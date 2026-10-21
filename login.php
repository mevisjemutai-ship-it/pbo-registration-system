<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/two-factor.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

/*
 * Shown after an administrator resets a forgotten password.
 */
$passwordWasReset = (($_GET['reset'] ?? '') === '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } else {

        $stmt = $pdo->prepare(
            'SELECT
                id,
                username,
                full_name,
                email,
                password_hash,
                role,
                is_active,
                must_change_password
             FROM users
             WHERE username = :username
             LIMIT 1'
        );

        $stmt->execute([
            'username' => $username
        ]);

        $user = $stmt->fetch();

        if (
            !$user ||
            !$user['is_active'] ||
            !password_verify($password, $user['password_hash'])
        ) {
            $error = 'Invalid username or password.';
        } elseif (pboLoginNeedsCode($pdo, $user)) {

            /*
             * Administrator on a browser that is not trusted (or not
             * used for a while): email a sign-in code first.
             */
            try {
                $sendResult = pboSendLoginCode($pdo, $user);

                pboStartPendingLogin((int) $user['id']);
                $_SESSION['pending_login']['notice'] = $sendResult;

                header('Location: verify-login.php');
                exit;

            } catch (Throwable $exception) {

                error_log(
                    'PBO sign-in code email failed for user '
                    . $user['id'] . ': ' . $exception->getMessage()
                );

                $error = pboMailShowErrors()
                    ? 'The sign-in code could not be sent: ' . $exception->getMessage()
                    : 'We could not send your sign-in code. Please try again in a few minutes.';
            }

        } else {

            pboFinishLogin($pdo, $user);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Login | PBO Registration System</title>

    <link rel="stylesheet"
          href="assets/css/style.css">
</head>

<body class="login-body">

<div class="login-card">

    <div class="login-header">
        <img class="login-logo" src="/pbo-portal/assets/images/pbora-logo.png" alt="Republic of Kenya | Public Benefit Organizations Regulatory Authority (PBORA)" width="539" height="180">

        <h1>PBO Registration System</h1>

        <p>
            Authorized Staff Access
        </p>
    </div>

    <?php if ($passwordWasReset && $error === ''): ?>

        <div class="alert alert-success">
            Your password has been changed. Sign in with your new password.
        </div>

    <?php endif; ?>

    <?php if ($error !== ''): ?>

        <div class="alert alert-error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>

    <form method="POST" autocomplete="off">

        <div class="form-group">

            <label for="username">
                Username
            </label>

            <input
                id="username"
                type="text"
                name="username"
                required
                autofocus
                autocomplete="username"
            >

        </div>

        <div class="form-group">

            <label for="password">
                Password
            </label>

            <input
                id="password"
                type="password"
                name="password"
                required
                autocomplete="current-password"
            >

        </div>

        <button class="btn btn-primary btn-full"
                type="submit">
            Sign In
        </button>

    </form>

    <p class="login-links">
        <a href="forgot-password.php">Forgot password?</a>
    </p>

    <p class="login-notice">
        Access is restricted to authorized personnel.
    </p>

</div>

<?php require __DIR__ . '/includes/auth-page-extras.php'; ?>

</body>
</html>
