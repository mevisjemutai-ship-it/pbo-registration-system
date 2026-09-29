<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdmin();
requirePasswordChanged();

/*
|--------------------------------------------------------------------------
| Read Request
|--------------------------------------------------------------------------
*/

$userId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

$action = $_GET['action'] ?? '';


/*
|--------------------------------------------------------------------------
| Validate User ID
|--------------------------------------------------------------------------
*/

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
| Validate Action
|--------------------------------------------------------------------------
*/

if (!in_array(
    $action,
    ['enable', 'disable'],
    true
)) {

    $_SESSION['flash_message'] =
        'Invalid account action.';

    $_SESSION['flash_type'] =
        'error';

    header('Location: users.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Prevent Administrator From Disabling Their Own Account
|--------------------------------------------------------------------------
*/

if (
    $userId === currentUserId()
    && $action === 'disable'
) {

    $_SESSION['flash_message'] =
        'You cannot disable your own administrator account.';

    $_SESSION['flash_type'] =
        'error';

    header('Location: users.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Find Target User
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'SELECT
        id,
        username,
        full_name,
        role,
        is_active
     FROM users
     WHERE id = :id
     LIMIT 1'
);

$stmt->execute([
    'id' => $userId
]);

$user = $stmt->fetch();


/*
|--------------------------------------------------------------------------
| User Must Exist
|--------------------------------------------------------------------------
*/

if (!$user) {

    $_SESSION['flash_message'] =
        'The selected user account does not exist.';

    $_SESSION['flash_type'] =
        'error';

    header('Location: users.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Determine New Account Status
|--------------------------------------------------------------------------
*/

$newStatus = $action === 'enable'
    ? 1
    : 0;


/*
|--------------------------------------------------------------------------
| Check Whether Account Is Already In Requested State
|--------------------------------------------------------------------------
*/

if (
    (int) $user['is_active']
    === $newStatus
) {

    $_SESSION['flash_message'] =
        $action === 'enable'
            ? $user['full_name']
                . ' is already enabled.'
            : $user['full_name']
                . ' is already disabled.';

    $_SESSION['flash_type'] =
        'error';

    header('Location: users.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Update Account
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    'UPDATE users
     SET is_active = :is_active
     WHERE id = :id'
);

$stmt->execute([
    'is_active' => $newStatus,
    'id' => $userId
]);


/*
|--------------------------------------------------------------------------
| Success Message
|--------------------------------------------------------------------------
*/

if ($action === 'enable') {

    $_SESSION['flash_message'] =
        $user['full_name']
        . ' has been enabled successfully.';

} else {

    $_SESSION['flash_message'] =
        $user['full_name']
        . ' has been disabled successfully.';
}

$_SESSION['flash_type'] =
    'success';


/*
|--------------------------------------------------------------------------
| Return To User Management
|--------------------------------------------------------------------------
*/

header('Location: users.php');
exit;