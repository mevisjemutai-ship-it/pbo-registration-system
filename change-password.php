<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    /*
     * PASSWORD POLICY
     *
     * 1. Password must be 6–10 characters.
     * 2. Password must contain at least one letter.
     * 3. Password must contain at least one number OR symbol.
     * 4. Password cannot be the standard temporary password.
     * 5. Password and confirmation must match.
     */

    if (
        strlen($newPassword) < 6 ||
        strlen($newPassword) > 10
    ) {

        $error = 'Password must be between 6 and 10 characters.';

    } elseif (!preg_match('/[A-Za-z]/', $newPassword)) {

        $error = 'Password must contain at least one letter.';

    } elseif (
        !preg_match('/[0-9]/', $newPassword) &&
        !preg_match('/[^A-Za-z0-9]/', $newPassword)
    ) {

        $error = 'Password must contain at least one number or symbol.';

    } elseif ($newPassword === 'field.123') {

        $error = 'You cannot continue using the temporary password.';

    } elseif ($newPassword !== $confirmPassword) {

        $error = 'The passwords do not match.';

    } else {

        $passwordHash = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        $stmt = $pdo->prepare(
            'UPDATE users
             SET
                password_hash = :password_hash,
                must_change_password = FALSE
             WHERE id = :id'
        );

        $stmt->execute([
            'password_hash' => $passwordHash,
            'id' => $_SESSION['user_id']
        ]);

        /*
         * Update the current session so the user
         * is no longer redirected back here.
         */
        $_SESSION['must_change_password'] = false;

        /*
         * Regenerate the session ID after this
         * security-sensitive account change.
         */
        session_regenerate_id(true);

        header('Location: dashboard.php');
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Change Password | PBO Registration System
    </title>

    <link
        rel="stylesheet"
        href="assets/css/style.css"
    >

</head>

<body class="login-body">

<div class="login-card">

    <div class="login-header">

        <div class="system-mark">
            PBO
        </div>

        <h1>Change Password</h1>

        <?php if (!empty($_SESSION['must_change_password'])): ?>

            <p>
                This is your first login. Please create
                your own password before continuing.
            </p>

        <?php else: ?>

            <p>
                Create a new password for your account.
            </p>

        <?php endif; ?>

    </div>


    <?php if ($error !== ''): ?>

        <div class="alert alert-error">

            <?= htmlspecialchars($error) ?>

        </div>

    <?php endif; ?>


    <form method="POST" autocomplete="off">

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

            <p class="password-example">
    Example format:
    <strong>Nuru#482</strong>
</p>

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


        <button
            type="submit"
            class="btn btn-primary btn-full"
        >
            Change Password
        </button>

    </form>

</div>

</body>

</html>