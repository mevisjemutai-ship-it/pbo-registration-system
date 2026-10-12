<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/password-policy.php';

requireAdmin();
requirePasswordChanged();


/*
|--------------------------------------------------------------------------
| Only accept the Reset Password button (POST + security token)
|--------------------------------------------------------------------------
|
| Resetting from a plain link would let another website trick a
| signed-in administrator into resetting someone's password.
|
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    $_SESSION['flash_message'] =
        'Use the Reset Password button on the User Management page.';

    $_SESSION['flash_type'] =
        'error';

    header('Location: users.php');

    exit;
}

verifyCsrfToken($_POST['csrf_token'] ?? null);


$userId = filter_var(
    $_POST['user_id'] ?? null,
    FILTER_VALIDATE_INT
);


if (!$userId) {

    $_SESSION['flash_message'] =
        'Invalid user account.';

    $_SESSION['flash_type'] =
        'error';

    header('Location: users.php');

    exit;
}


/*
|--------------------------------------------------------------------------
| Prevent administrator resetting their own account here
|--------------------------------------------------------------------------
*/

if ($userId === currentUserId()) {

    $_SESSION['flash_message'] =
        'Use Change Password to modify your own password.';

    $_SESSION['flash_type'] =
        'error';

    header('Location: users.php');

    exit;
}


/*
|--------------------------------------------------------------------------
| Verify target account
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT id, full_name
     FROM users
     WHERE id = :id
     LIMIT 1'
);

$stmt->execute([
    'id' => $userId
]);

$user = $stmt->fetch();


if (!$user) {

    $_SESSION['flash_message'] =
        'User account not found.';

    $_SESSION['flash_type'] =
        'error';

    header('Location: users.php');

    exit;
}


/*
|--------------------------------------------------------------------------
| Reset password to the standard temporary password
|--------------------------------------------------------------------------
|
| The temporary password (field.123) is defined once in
| includes/password-policy.php. The user must replace it at next login.
|
*/

$temporaryPassword = PBO_TEMPORARY_PASSWORD;

$passwordHash = password_hash(
    $temporaryPassword,
    PASSWORD_DEFAULT
);


$stmt = $pdo->prepare(
    'UPDATE users
     SET
        password_hash = :password_hash,
        must_change_password = TRUE
     WHERE id = :id'
);

$stmt->execute([
    'password_hash' => $passwordHash,
    'id' => $userId
]);


$_SESSION['flash_message'] =
    'Password for '
    . $user['full_name']
    . ' has been reset to '
    . $temporaryPassword
    . '. The user must change it at the next login.';

$_SESSION['flash_type'] =
    'success';


header('Location: users.php');

exit;
