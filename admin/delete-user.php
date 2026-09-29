<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdmin();
requirePasswordChanged();


/*
|--------------------------------------------------------------------------
| POST only
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header(
        'Location: /pbo-portal/admin/users.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Current administrator
|--------------------------------------------------------------------------
*/

$currentUserId =
    currentUserId();


if ($currentUserId <= 0) {

    header(
        'Location: /pbo-portal/login.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Target user
|--------------------------------------------------------------------------
*/

$userId =
    filter_input(
        INPUT_POST,
        'user_id',
        FILTER_VALIDATE_INT
    );


if (!$userId) {

    $_SESSION['flash_message'] =
        'The user account could not be identified.';

    $_SESSION['flash_type'] =
        'error';


    header(
        'Location: /pbo-portal/admin/users.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Never delete yourself
|--------------------------------------------------------------------------
*/

if (
    (int) $userId ===
    (int) $currentUserId
) {

    $_SESSION['flash_message'] =
        'You cannot delete the account you are currently signed in with.';

    $_SESSION['flash_type'] =
        'error';


    header(
        'Location: /pbo-portal/admin/users.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Delete
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | Lock target user
    |--------------------------------------------------------------------------
    */

    $statement =
        $pdo->prepare(
            "
            SELECT
                id,
                username,
                full_name,
                role,
                is_active
            FROM users
            WHERE id = :user_id
            FOR UPDATE
            "
        );


    $statement->execute([
        ':user_id'
            => $userId,
    ]);


    $user =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$user) {

        throw new RuntimeException(
            'User account was not found.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Account must already be disabled
    |--------------------------------------------------------------------------
    */

    if (
        (bool) $user['is_active']
    ) {

        throw new RuntimeException(
            'Active accounts cannot be deleted. Disable the account first.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Application history
    |--------------------------------------------------------------------------
    |
    | Do not remove a user if their ID is part of an application record.
    |
    */

    $statement =
        $pdo->prepare(
            "
            SELECT
                COUNT(*)
            FROM pbo_applications
            WHERE
                created_by = :created_by
                OR updated_by = :updated_by
                OR submitted_by = :submitted_by
            "
        );


    $statement->execute([

        ':created_by'
            => $userId,

        ':updated_by'
            => $userId,

        ':submitted_by'
            => $userId,
    ]);


    $applicationReferences =
        (int)
        $statement->fetchColumn();


    if ($applicationReferences > 0) {

        throw new RuntimeException(
            'This account has application history and must be retained for record attribution.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Audit history
    |--------------------------------------------------------------------------
    */

    $statement =
        $pdo->prepare(
            "
            SELECT
                COUNT(*)
            FROM application_audit_log
            WHERE user_id = :user_id
            "
        );


    $statement->execute([
        ':user_id'
            => $userId,
    ]);


    $auditReferences =
        (int)
        $statement->fetchColumn();


    if ($auditReferences > 0) {

        throw new RuntimeException(
            'This account has audit history and must be retained for accountability.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Delete account
    |--------------------------------------------------------------------------
    */

    $statement =
        $pdo->prepare(
            "
            DELETE FROM users
            WHERE id = :user_id
              AND is_active = FALSE
            "
        );


    $statement->execute([
        ':user_id'
            => $userId,
    ]);


    if (
        $statement->rowCount() !==
        1
    ) {

        throw new RuntimeException(
            'The disabled account could not be deleted.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Commit
    |--------------------------------------------------------------------------
    */

    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */

    $_SESSION['flash_message'] =
        'Disabled user "' .
        (string) $user['username'] .
        '" was deleted successfully.';


    $_SESSION['flash_type'] =
        'success';


    header(
        'Location: /pbo-portal/admin/users.php'
    );

    exit;


} catch (Throwable $exception) {


    if ($pdo->inTransaction()) {

        $pdo->rollBack();
    }


    error_log(
        'User deletion failed for user ID ' .
        (string) $userId .
        ': ' .
        $exception->getMessage()
    );


    $_SESSION['flash_message'] =
        $exception->getMessage();


    $_SESSION['flash_type'] =
        'error';


    header(
        'Location: /pbo-portal/admin/users.php'
    );

    exit;
}