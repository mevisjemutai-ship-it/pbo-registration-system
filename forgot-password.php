<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/password-reset.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verifyCsrfToken($_POST['csrf_token'] ?? null);

    $identifier = trim((string) ($_POST['identifier'] ?? ''));

    if ($identifier === '') {

        $error = 'Enter your username or email address.';

    } elseif (!pboSessionMayRequestCode()) {

        $error = 'Too many requests. Please wait 15 minutes and try again.';

    } else {

        pboRecordSessionCodeRequest();

        $admin = pboFindAdminForReset($pdo, $identifier);

        if (
            $admin
            && pboResetRequestsInWindow($pdo, (int) $admin['id']) < PBO_RESET_MAX_REQUESTS
        ) {
            try {
                $code = pboCreateResetCode(
                    $pdo,
                    (int) $admin['id'],
                    (string) ($_SERVER['REMOTE_ADDR'] ?? '')
                );

                pboSendResetCodeEmail($admin, $code);

            } catch (Throwable $exception) {

                error_log(
                    'PBO password reset email failed for user '
                    . $admin['id'] . ': ' . $exception->getMessage()
                );

                if (pboMailShowErrors()) {
                    $error = 'The email could not be sent: '
                        . $exception->getMessage();
                }
            }
        }

        /*
         * Same result whether or not the account exists, so this page
         * cannot be used to find out which usernames are administrators.
         */
        if ($error === '') {
            pboStartResetSession($admin ? (int) $admin['id'] : 0);

            header('Location: reset-password.php');
            exit;
        }
    }
}

$expired = isset($_GET['expired']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Forgot Password | PBO Registration System</title>

    <link rel="stylesheet"
          href="assets/css/style.css">
</head>

<body class="login-body">

<div class="login-card">

    <div class="login-header">
        <img class="login-logo" src="/pbo-portal/assets/images/pbora-logo.png" alt="Republic of Kenya | Public Benefit Organizations Regulatory Authority (PBORA)" width="539" height="180">

        <h1>Forgot Password</h1>

        <p>
            Administrators: enter your username or email and we will
            email you a 6-digit code.
        </p>
    </div>

    <div class="alert alert-warning">
        <strong>Staff members:</strong> you cannot reset your password here.
        Ask an administrator or the ICT department to reset it. You will then
        sign in with the temporary password and choose a new one.
    </div>

    <?php if ($expired): ?>

        <div class="alert alert-warning">
            Your reset session has ended. Request a new code.
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

        <div class="form-group">

            <label for="identifier">
                Username or email
            </label>

            <input
                id="identifier"
                type="text"
                name="identifier"
                value="<?= htmlspecialchars($identifier) ?>"
                required
                autofocus
                autocomplete="username"
            >

        </div>

        <button class="btn btn-primary btn-full"
                type="submit">
            Send Code
        </button>

    </form>

    <p class="login-links">
        <a href="login.php">Back to sign in</a>
    </p>

</div>

<?php require __DIR__ . '/includes/auth-page-extras.php'; ?>

</body>
</html>
