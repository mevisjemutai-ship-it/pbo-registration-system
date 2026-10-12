<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/two-factor.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (isset($_GET['cancel'])) {
    unset($_SESSION['pending_login']);
    header('Location: login.php');
    exit;
}

$pending = pboPendingLogin();

if ($pending === null) {
    header('Location: login.php');
    exit;
}

$userId = (int) $pending['user_id'];
$user = pboLoadUserForLogin($pdo, $userId);

if (!$user || !(int) $user['is_active'] || $user['role'] !== 'admin') {
    unset($_SESSION['pending_login']);
    header('Location: login.php');
    exit;
}

$error = '';
$info = '';
$trustDevice = true;

/*
 * Message from login.php (first code) or from "Send a new code".
 */
$notice = (string) ($_SESSION['pending_login']['notice'] ?? '');
unset($_SESSION['pending_login']['notice']);

if ($notice === 'sent') {
    $info = 'We emailed a 6-digit sign-in code to your email address. '
        . 'It expires in ' . PBO_RESET_CODE_MINUTES . ' minutes.';
} elseif ($notice === 'limited') {
    $info = 'A code was sent to your email in the last few minutes. '
        . 'Use the most recent code.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verifyCsrfToken($_POST['csrf_token'] ?? null);

    $action = (string) ($_POST['action'] ?? 'verify');

    if ($action === 'resend') {

        try {
            $_SESSION['pending_login']['notice'] = pboSendLoginCode($pdo, $user);
        } catch (Throwable $exception) {
            error_log(
                'PBO sign-in code email failed for user '
                . $userId . ': ' . $exception->getMessage()
            );

            $_SESSION['pending_login']['notice'] = 'failed';
        }

        header('Location: verify-login.php');
        exit;
    }

    $code = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));
    $trustDevice = !empty($_POST['trust_device']);

    if ((int) $pending['attempts'] >= PBO_RESET_MAX_ATTEMPTS) {
        $status = 'locked';
    } elseif ($code === '') {
        $status = 'empty';
    } else {
        $check = pboCheckResetCode($pdo, $userId, $code, 'login');
        $status = $check['status'];
    }

    if ($status === 'invalid') {
        $_SESSION['pending_login']['attempts'] = (int) $pending['attempts'] + 1;

        if ($_SESSION['pending_login']['attempts'] >= PBO_RESET_MAX_ATTEMPTS) {
            $status = 'locked';
        }
    }

    switch ($status) {

        case 'ok':
            if (!pboUseLoginCode($pdo, (int) $check['code_id'])) {
                $error = 'This code has already been used. Send a new code.';
                break;
            }

            if ($trustDevice) {
                pboTrustThisDevice($pdo, $userId);
            }

            pboFinishLogin($pdo, $user);
            // pboFinishLogin() redirects and exits.

        case 'empty':
            $error = 'Enter the 6-digit code from the email.';
            break;

        case 'invalid':
            $error = 'That code is not correct. Check the email and try again.';
            break;

        case 'locked':
            $error = 'Too many wrong codes. Send a new code, or go back and sign in again.';
            break;

        default: // 'expired' or 'none'
            $error = 'This code has expired. Send a new code.';
            break;
    }
}

if ($notice === 'failed') {
    $error = 'We could not send a new code. Please try again in a few minutes.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Sign-in Code | PBO Registration System</title>

    <link rel="stylesheet"
          href="assets/css/style.css">
</head>

<body class="login-body">

<div class="login-card">

    <div class="login-header">
        <img class="login-logo" src="/pbo-portal/assets/images/pbora-logo.png" alt="Republic of Kenya | Public Benefit Organizations Regulatory Authority (PBORA)" width="539" height="180">

        <h1>Check Your Email</h1>

        <p>
            For security, administrators confirm new or inactive
            browsers with a code.
        </p>
    </div>

    <?php if ($info !== '' && $error === ''): ?>

        <div class="alert alert-info">
            <?= htmlspecialchars($info) ?>
        </div>

    <?php endif; ?>

    <?php if ($error !== ''): ?>

        <div class="alert alert-error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>

    <form method="POST" autocomplete="off">

        <input type="hidden"
               name="csrf_token"
               value="<?= htmlspecialchars(csrfToken()) ?>">

        <input type="hidden" name="action" value="verify">

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

        <label class="trust-device">
            <input type="checkbox"
                   name="trust_device"
                   value="1"
                   <?= $trustDevice ? 'checked' : '' ?>>
            <span>
                Trust this browser for <?= PBO_TRUST_IDLE_DAYS ?> days.
                <small>Untick on shared or public computers.</small>
            </span>
        </label>

        <button class="btn btn-primary btn-full"
                type="submit">
            Verify and Sign In
        </button>

    </form>

    <form method="POST" class="login-links">

        <input type="hidden"
               name="csrf_token"
               value="<?= htmlspecialchars(csrfToken()) ?>">

        <input type="hidden" name="action" value="resend">

        Didn't get it?
        <button type="submit" class="link-button">Send a new code</button>
        &middot;
        <a href="verify-login.php?cancel=1">Back to sign in</a>

    </form>

</div>

<?php require __DIR__ . '/includes/auth-page-extras.php'; ?>

</body>
</html>
