<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/password-reset.php';
require_once __DIR__ . '/includes/password-policy.php';
require_once __DIR__ . '/includes/two-factor.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$reset = pboResetSession();

if ($reset === null) {
    header('Location: forgot-password.php?expired=1');
    exit;
}

$userId = (int) $reset['user_id'];
$error = '';
$needsNewCode = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verifyCsrfToken($_POST['csrf_token'] ?? null);

    $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ((int) $reset['attempts'] >= PBO_RESET_MAX_ATTEMPTS) {

        $status = 'locked';

    } elseif ($code === '') {

        $status = 'empty';

    } elseif ($userId > 0) {

        $check = pboCheckResetCode($pdo, $userId, $code);
        $status = $check['status'];

    } else {

        // No matching administrator: behave exactly like a wrong code.
        $status = 'invalid';
    }

    if ($status === 'invalid') {
        $_SESSION['password_reset']['attempts'] = (int) $reset['attempts'] + 1;

        if ($_SESSION['password_reset']['attempts'] >= PBO_RESET_MAX_ATTEMPTS) {
            $status = 'locked';
        }
    }

    switch ($status) {

        case 'ok':
            $policyError = pboPasswordPolicyError($newPassword, $confirmPassword);

            if ($policyError !== null) {
                // Code was right; keep it valid so they can fix the password.
                $error = $policyError;
                break;
            }

            if (!pboCompletePasswordReset($pdo, $userId, (int) $check['code_id'], $newPassword)) {
                $error = 'This code has already been used. Request a new one.';
                $needsNewCode = true;
                break;
            }

            /*
             * New password: forget every browser trusted before, and
             * trust this one (the admin just proved they own the email).
             */
            pboForgetTrustedDevices($pdo, $userId);
            pboTrustThisDevice($pdo, $userId);

            pboClearResetSession();
            session_regenerate_id(true);

            header('Location: login.php?reset=1');
            exit;

        case 'empty':
            $error = 'Enter the 6-digit code from the email.';
            break;

        case 'invalid':
            $error = 'That code is not correct. Check the email and try again.';
            break;

        case 'locked':
            $error = 'Too many wrong codes. Request a new code.';
            $needsNewCode = true;
            break;

        default: // 'expired' or 'none'
            $error = 'This code has expired. Request a new code.';
            $needsNewCode = true;
            break;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Reset Password | PBO Registration System</title>

    <link rel="stylesheet"
          href="assets/css/style.css">
</head>

<body class="login-body">

<div class="login-card">

    <div class="login-header">
        <img class="login-logo" src="/pbo-portal/assets/images/pbora-logo.png" alt="Republic of Kenya | Public Benefit Organizations Regulatory Authority (PBORA)" width="539" height="180">

        <h1>Reset Password</h1>

        <p>
            Enter the code we emailed you and choose a new password.
        </p>
    </div>

    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>

        <div class="alert alert-info">
            If that username or email belongs to an administrator account,
            a 6-digit code has been sent to the email address saved on it.
            The code expires in <?= PBO_RESET_CODE_MINUTES ?> minutes.
        </div>

    <?php endif; ?>

    <?php if ($error !== ''): ?>

        <div class="alert alert-error">
            <?= htmlspecialchars($error) ?>

            <?php if ($needsNewCode): ?>
                <a href="forgot-password.php">Request a new code</a>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    <form method="POST" autocomplete="off">

        <input type="hidden"
               name="csrf_token"
               value="<?= htmlspecialchars(csrfToken()) ?>">

        <div class="form-group">

            <label for="code">
                6-digit code
            </label>

            <input
                id="code"
                class="code-input"
                type="text"
                name="code"
                inputmode="numeric"
                pattern="[0-9 ]*"
                maxlength="7"
                required
                autofocus
                autocomplete="one-time-code"
            >

        </div>

        <div class="form-group">

            <label for="new_password">
                New Password
            </label>

            <input
                id="new_password"
                type="password"
                name="new_password"
                minlength="6"
                maxlength="10"
                required
                autocomplete="new-password"
            >

        </div>

        <div class="password-requirements">

            <strong>Password requirements</strong>

            <ul>
                <li>6 to 10 characters</li>
                <li>Must contain letters</li>
                <li>Must contain a number or symbol</li>
                <li>Cannot be the temporary password</li>
            </ul>

        </div>

        <div class="form-group">

            <label for="confirm_password">
                Confirm New Password
            </label>

            <input
                id="confirm_password"
                type="password"
                name="confirm_password"
                minlength="6"
                maxlength="10"
                required
                autocomplete="new-password"
            >

        </div>

        <button class="btn btn-primary btn-full"
                type="submit">
            Reset Password
        </button>

    </form>

    <p class="login-links">
        Didn't get a code?
        <a href="forgot-password.php">Send a new one</a>
        &middot;
        <a href="login.php">Back to sign in</a>
    </p>

</div>

<?php require __DIR__ . '/includes/auth-page-extras.php'; ?>

</body>
</html>
