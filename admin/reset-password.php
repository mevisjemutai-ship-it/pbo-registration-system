<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdmin();
requirePasswordChanged();


$userId = filter_input(
    INPUT_GET,
    'id',
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
| Reset password
|--------------------------------------------------------------------------
*/


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
$temporaryPassword =
    bin2hex(
        random_bytes(6)
    );
$stmt->execute([
    'password_hash' => $passwordHash,
    'id' => $userId
]);


$_SESSION['flash_message'] =
    'Password for '
    . $user['full_name']
    . ' has been reset to field.123. '
    . 'The user must change it at the next login.';

$_SESSION['flash_type'] =
    'success';


header('Location: users.php');

exit;
