<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
requirePasswordChanged();


/*
|--------------------------------------------------------------------------
| POST only
|--------------------------------------------------------------------------
|
| Draft deletion must never be triggered through a normal GET request.
|
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header(
        'Location: /pbo-portal/applications/drafts.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Database connection
|--------------------------------------------------------------------------
*/

$connection = null;

if (isset($pdo) && $pdo instanceof PDO) {

    $connection = $pdo;

} elseif (isset($db) && $db instanceof PDO) {

    $connection = $db;
}


if (!$connection instanceof PDO) {

    throw new RuntimeException(
        'Database connection was not initialized as a PDO instance.'
    );
}


/*
|--------------------------------------------------------------------------
| Current user
|--------------------------------------------------------------------------
*/

$userId =
    (int)
    ($_SESSION['user_id'] ?? 0);


if ($userId <= 0) {

    header(
        'Location: /pbo-portal/login.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Application ID
|--------------------------------------------------------------------------
*/

$applicationId =
    filter_input(
        INPUT_POST,
        'application_id',
        FILTER_VALIDATE_INT
    );


if (!$applicationId) {

    $_SESSION['flash_error'] =
        'The draft application could not be identified.';

    header(
        'Location: /pbo-portal/applications/drafts.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| CSRF validation
|--------------------------------------------------------------------------
*/

$sessionToken =
    (string)
    ($_SESSION['csrf_token'] ?? '');


$submittedToken =
    (string)
    ($_POST['csrf_token'] ?? '');


if (
    $sessionToken === '' ||
    $submittedToken === '' ||
    !hash_equals(
        $sessionToken,
        $submittedToken
    )
) {

    $_SESSION['flash_error'] =
        'The delete request could not be verified. Please try again.';

    header(
        'Location: /pbo-portal/applications/drafts.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Delete draft
|--------------------------------------------------------------------------
*/

try {

    $connection->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | Lock draft and verify ownership
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | A user can delete only:
    |
    | 1. An application they created.
    | 2. An application whose status is still "draft".
    |
    */

    $statement =
        $connection->prepare(
            "
            SELECT
                id,
                application_reference,
                pbo_name,
                status,
                created_by
            FROM pbo_applications
            WHERE id = :application_id
              AND created_by = :user_id
            FOR UPDATE
            "
        );


    $statement->execute([

        ':application_id'
            => $applicationId,

        ':user_id'
            => $userId,
    ]);


    $application =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$application) {

        throw new RuntimeException(
            'Draft application was not found or access was denied.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Never delete submitted records
    |--------------------------------------------------------------------------
    */

    if (
        (string)
        $application['status'] !==
        'draft'
    ) {

        throw new RuntimeException(
            'Only draft applications can be deleted.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Save signature paths before deleting officials
    |--------------------------------------------------------------------------
    |
    | These paths are needed later so that uploaded signature files can
    | also be removed from the server.
    |
    */

    $statement =
        $connection->prepare(
            "
            SELECT
                signature
            FROM application_officials
            WHERE application_id =
                :application_id
            "
        );


    $statement->execute([
        ':application_id'
            => $applicationId,
    ]);


    $signaturePaths =
        $statement->fetchAll(
            PDO::FETCH_COLUMN
        );


    /*
    |--------------------------------------------------------------------------
    | Delete objectives
    |--------------------------------------------------------------------------
    */

    $statement =
        $connection->prepare(
            "
            DELETE FROM application_objectives
            WHERE application_id =
                :application_id
            "
        );


    $statement->execute([
        ':application_id'
            => $applicationId,
    ]);


    /*
    |--------------------------------------------------------------------------
    | Delete beneficiaries
    |--------------------------------------------------------------------------
    */

    $statement =
        $connection->prepare(
            "
            DELETE FROM application_beneficiaries
            WHERE application_id =
                :application_id
            "
        );


    $statement->execute([
        ':application_id'
            => $applicationId,
    ]);


    /*
    |--------------------------------------------------------------------------
    | Delete officials/directors
    |--------------------------------------------------------------------------
    */

    $statement =
        $connection->prepare(
            "
            DELETE FROM application_officials
            WHERE application_id =
                :application_id
            "
        );


    $statement->execute([
        ':application_id'
            => $applicationId,
    ]);


    /*
    |--------------------------------------------------------------------------
    | Delete international details
    |--------------------------------------------------------------------------
    */

    $statement =
        $connection->prepare(
            "
            DELETE FROM application_international_details
            WHERE application_id =
                :application_id
            "
        );


    $statement->execute([
        ':application_id'
            => $applicationId,
    ]);


    /*
    |--------------------------------------------------------------------------
    | Delete organization address
    |--------------------------------------------------------------------------
    */

    $statement =
        $connection->prepare(
            "
            DELETE FROM application_addresses
            WHERE application_id =
                :application_id
            "
        );


    $statement->execute([
        ':application_id'
            => $applicationId,
    ]);


    /*
    |--------------------------------------------------------------------------
    | Delete application audit history
    |--------------------------------------------------------------------------
    |
    | Since the entire draft is being permanently removed, its draft audit
    | history is also removed.
    |
    */

    $statement =
        $connection->prepare(
            "
            DELETE FROM application_audit_log
            WHERE application_id =
                :application_id
            "
        );


    $statement->execute([
        ':application_id'
            => $applicationId,
    ]);


    /*
    |--------------------------------------------------------------------------
    | Delete master application
    |--------------------------------------------------------------------------
    |
    | Ownership and status are checked again here as defence in depth.
    |
    */

    $statement =
        $connection->prepare(
            "
            DELETE FROM pbo_applications
            WHERE id = :application_id
              AND created_by = :user_id
              AND status = 'draft'
            "
        );


    $statement->execute([

        ':application_id'
            => $applicationId,

        ':user_id'
            => $userId,
    ]);


    if (
        $statement->rowCount() !==
        1
    ) {

        throw new RuntimeException(
            'The draft application could not be deleted.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Commit database deletion
    |--------------------------------------------------------------------------
    */

    $connection->commit();


    /*
    |--------------------------------------------------------------------------
    | Uploaded application directory
    |--------------------------------------------------------------------------
    |
    | officials.php stores signatures under the application's upload
    | directory. Physical files are removed only AFTER the database
    | transaction succeeds.
    |
    */

    $applicationUploadDirectory =
        dirname(__DIR__) .
        '/uploads/applications/' .
        $applicationId;


    $realApplicationUploadDirectory =
        realpath(
            $applicationUploadDirectory
        );


    /*
    |--------------------------------------------------------------------------
    | Remove individual signature files
    |--------------------------------------------------------------------------
    */

    foreach (
        $signaturePaths
        as $signaturePath
    ) {

        $signaturePath =
            trim(
                (string)
                $signaturePath
            );


        if ($signaturePath === '') {
            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | Convert stored path to filesystem path
        |--------------------------------------------------------------------------
        |
        | Supported public-path example:
        |
        | /pbo-portal/uploads/applications/17/signatures/file.png
        |
        */

        $publicPrefix =
            '/pbo-portal/';


        if (
            str_starts_with(
                $signaturePath,
                $publicPrefix
            )
        ) {

            $relativePath =
                substr(
                    $signaturePath,
                    strlen(
                        $publicPrefix
                    )
                );


            $absolutePath =
                dirname(__DIR__) .
                '/' .
                ltrim(
                    $relativePath,
                    '/'
                );

        } else {

            /*
            |--------------------------------------------------------------------------
            | The stored value may already be a filesystem path
            |--------------------------------------------------------------------------
            |
            | It will still have to pass the security check below.
            |
            */

            $absolutePath =
                $signaturePath;
        }


        $realFile =
            realpath(
                $absolutePath
            );


        /*
        |--------------------------------------------------------------------------
        | Security check
        |--------------------------------------------------------------------------
        |
        | Never delete a file unless it physically exists inside this
        | application's upload directory.
        |
        */

        if (
            $realApplicationUploadDirectory !== false &&
            $realFile !== false &&
            str_starts_with(
                $realFile,
                $realApplicationUploadDirectory .
                DIRECTORY_SEPARATOR
            ) &&
            is_file(
                $realFile
            )
        ) {

            @unlink(
                $realFile
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Remove empty signatures directory
    |--------------------------------------------------------------------------
    */

    $signatureDirectory =
        $applicationUploadDirectory .
        '/signatures';


    if (
        is_dir(
            $signatureDirectory
        )
    ) {

        $contents =
            array_diff(
                scandir(
                    $signatureDirectory
                ) ?: [],
                [
                    '.',
                    '..',
                ]
            );


        if ($contents === []) {

            @rmdir(
                $signatureDirectory
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Remove empty application upload directory
    |--------------------------------------------------------------------------
    */

    if (
        is_dir(
            $applicationUploadDirectory
        )
    ) {

        $contents =
            array_diff(
                scandir(
                    $applicationUploadDirectory
                ) ?: [],
                [
                    '.',
                    '..',
                ]
            );


        if ($contents === []) {

            @rmdir(
                $applicationUploadDirectory
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Success message
    |--------------------------------------------------------------------------
    */

    $reference =
        trim(
            (string)
            (
                $application[
                    'application_reference'
                ]
                ?? ''
            )
        );


    if ($reference !== '') {

        $_SESSION['flash_success'] =
            'Draft ' .
            $reference .
            ' was deleted successfully.';

    } else {

        $_SESSION['flash_success'] =
            'Draft application was deleted successfully.';
    }


    /*
    |--------------------------------------------------------------------------
    | Rotate CSRF token
    |--------------------------------------------------------------------------
    */

    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );


    /*
    |--------------------------------------------------------------------------
    | Return to Draft Applications
    |--------------------------------------------------------------------------
    */

    header(
        'Location: /pbo-portal/applications/drafts.php'
    );

    exit;


} catch (Throwable $exception) {


    /*
    |--------------------------------------------------------------------------
    | Roll back
    |--------------------------------------------------------------------------
    */

    if (
        $connection->inTransaction()
    ) {

        $connection->rollBack();
    }


    /*
    |--------------------------------------------------------------------------
    | Server-side logging
    |--------------------------------------------------------------------------
    */

    error_log(
        'Draft deletion failed for application ID ' .
        $applicationId .
        ': ' .
        $exception->getMessage()
    );


    /*
    |--------------------------------------------------------------------------
    | User-facing error
    |--------------------------------------------------------------------------
    */

    $_SESSION['flash_error'] =
        'The draft could not be deleted. No application data was removed.';


    /*
    |--------------------------------------------------------------------------
    | Return to Draft Applications
    |--------------------------------------------------------------------------
    */

    header(
        'Location: /pbo-portal/applications/drafts.php'
    );

    exit;
}