<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

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
        } else {

            session_regenerate_id(true);

            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['must_change_password']
                = (bool) $user['must_change_password'];

            $pdo->prepare(
                'UPDATE users
                 SET last_login_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            )->execute([
                'id' => $user['id']
            ]);

            if ($user['must_change_password']) {
                header('Location: change-password.php');
            } else {
                header('Location: dashboard.php');
            }

            exit;
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
        <div class="system-mark">PBO</div>

        <h1>PBO Registration System</h1>

        <p>
            Authorized Staff Access
        </p>
    </div>

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

    <p class="login-notice">
        Access is restricted to authorized personnel.
    </p>

</div>

</body>
</html>