<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
requirePasswordChanged();


/*
|--------------------------------------------------------------------------
| Page configuration
|--------------------------------------------------------------------------
*/

$pageTitle = 'Officials and Directors';
$activePage = 'applications';


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

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: /pbo-portal/login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


function cleanString(mixed $value): string
{
    return trim((string) $value);
}


function emptyOfficial(string $designation): array
{
    return [
        'id' => null,
        'designation' => $designation,
        'full_name' => '',
        'nationality' => '',
        'id_passport_number' => '',
        'kra_pin' => '',
        'phone_number' => '',
        'email' => '',
        'signature' => '',
        'existing_signature' => '',
    ];
}


function officialLabel(string $designation): string
{
    return match (strtoupper($designation)) {
        'CHAIRPERSON' => 'Chairperson',
        'TREASURER'   => 'Treasurer',
        'SECRETARY'   => 'Secretary',
        'DIRECTOR'    => 'Director',
        default       => ucwords(
            strtolower(
                str_replace('_', ' ', $designation)
            )
        ),
    };
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    (string) $_SESSION['csrf_token'];


/*
|--------------------------------------------------------------------------
| Application ID
|--------------------------------------------------------------------------
*/

$applicationId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$applicationId) {
    $applicationId = filter_input(
        INPUT_POST,
        'application_id',
        FILTER_VALIDATE_INT
    );
}

if (!$applicationId) {
    header(
        'Location: /pbo-portal/applications/drafts.php'
    );
    exit;
}


/*
|--------------------------------------------------------------------------
| Load application
|--------------------------------------------------------------------------
*/

$statement = $connection->prepare(
    "
    SELECT
        id,
        application_reference,
        pbo_name,
        pbo_registration_number,
        registration_type,
        status,
        current_step,
        created_by
    FROM pbo_applications
    WHERE id = :application_id
      AND created_by = :user_id
    LIMIT 1
    "
);

$statement->execute([
    ':application_id' => $applicationId,
    ':user_id' => $userId,
]);

$application =
    $statement->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    http_response_code(404);
    exit('Application not found.');
}

if ($application['status'] !== 'draft') {
    header(
        'Location: /pbo-portal/applications/records.php'
    );
    exit;
}


/*
|--------------------------------------------------------------------------
| Signature storage
|--------------------------------------------------------------------------
|
| Filesystem:
|
| /var/www/html/pbo-portal/
|     uploads/
|         applications/
|             {application_id}/
|                 signatures/
|
| Database stores a web-relative path:
|
| /pbo-portal/uploads/applications/{id}/signatures/file.png
|
|--------------------------------------------------------------------------
*/

$uploadDirectory =
    dirname(__DIR__) .
    '/uploads/applications/' .
    $applicationId .
    '/signatures';

$uploadWebPath =
    '/pbo-portal/uploads/applications/' .
    $applicationId .
    '/signatures';

$maximumUploadSize =
    5 * 1024 * 1024;

$allowedMimeTypes = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];


/*
|--------------------------------------------------------------------------
| Create signature directory immediately
|--------------------------------------------------------------------------
|
| Do this when the page loads rather than waiting until POST.
|
*/

$directoryError = null;

if (!is_dir($uploadDirectory)) {

    $created = @mkdir(
        $uploadDirectory,
        0775,
        true
    );

    if (!$created && !is_dir($uploadDirectory)) {

        $lastError = error_get_last();

        $directoryError =
            'The signature storage directory could not be created.';

        error_log(
            'Signature directory creation failed: ' .
            $uploadDirectory .
            ' | ' .
            ($lastError['message'] ?? 'Unknown filesystem error')
        );
    }
}


/*
|--------------------------------------------------------------------------
| Ensure directory permissions are usable
|--------------------------------------------------------------------------
*/

if (
    $directoryError === null &&
    is_dir($uploadDirectory) &&
    !is_writable($uploadDirectory)
) {

    $directoryError =
        'The signature storage directory is not writable.';

    error_log(
        'Signature directory is not writable: ' .
        $uploadDirectory
    );
}


/*
|--------------------------------------------------------------------------
| Load existing officials
|--------------------------------------------------------------------------
*/

$chairperson =
    emptyOfficial('CHAIRPERSON');

$treasurer =
    emptyOfficial('TREASURER');

$secretary =
    emptyOfficial('SECRETARY');

$directors = [];


$statement = $connection->prepare(
    "
    SELECT
        id,
        full_name,
        designation,
        nationality,
        id_passport_number,
        kra_pin,
        phone_number,
        email,
        signature
    FROM application_officials
    WHERE application_id = :application_id
    ORDER BY
        CASE designation
            WHEN 'CHAIRPERSON' THEN 1
            WHEN 'TREASURER' THEN 2
            WHEN 'SECRETARY' THEN 3
            WHEN 'DIRECTOR' THEN 4
            ELSE 5
        END,
        id ASC
    "
);

$statement->execute([
    ':application_id' => $applicationId,
]);

$savedOfficials =
    $statement->fetchAll(PDO::FETCH_ASSOC);


foreach ($savedOfficials as $saved) {

    $designation =
        strtoupper(
            trim(
                (string) $saved['designation']
            )
        );

    $record = [
        'id' =>
            (int) $saved['id'],

        'designation' =>
            $designation,

        'full_name' =>
            (string) $saved['full_name'],

        'nationality' =>
            (string) ($saved['nationality'] ?? ''),

        'id_passport_number' =>
            (string) ($saved['id_passport_number'] ?? ''),

        'kra_pin' =>
            (string) ($saved['kra_pin'] ?? ''),

        'phone_number' =>
            (string) ($saved['phone_number'] ?? ''),

        'email' =>
            (string) ($saved['email'] ?? ''),

        'signature' =>
            (string) ($saved['signature'] ?? ''),

        'existing_signature' =>
            (string) ($saved['signature'] ?? ''),
    ];


    switch ($designation) {

        case 'CHAIRPERSON':
            $chairperson = $record;
            break;

        case 'TREASURER':
            $treasurer = $record;
            break;

        case 'SECRETARY':
            $secretary = $record;
            break;

        case 'DIRECTOR':
            $directors[] = $record;
            break;
    }
}


/*
|--------------------------------------------------------------------------
| Minimum two Director cards
|--------------------------------------------------------------------------
*/

while (count($directors) < 2) {
    $directors[] =
        emptyOfficial('DIRECTOR');
}


/*
|--------------------------------------------------------------------------
| Errors
|--------------------------------------------------------------------------
*/

$errors = [];

if ($directoryError !== null) {
    $errors[] = $directoryError;
}


/*
|--------------------------------------------------------------------------
| Process POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $submittedToken =
        (string) ($_POST['csrf_token'] ?? '');

    if (
        $submittedToken === '' ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {
        $errors[] =
            'Your session token is invalid or has expired. Please reload the page and try again.';
    }


    /*
    |--------------------------------------------------------------------------
    | Named officials
    |--------------------------------------------------------------------------
    */

    $postedOfficials =
        $_POST['officials'] ?? [];

    if (!is_array($postedOfficials)) {
        $postedOfficials = [];
    }


    $officialDefinitions = [
        'chairperson' => 'CHAIRPERSON',
        'treasurer'   => 'TREASURER',
        'secretary'   => 'SECRETARY',
    ];


    $namedOfficials = [];


    foreach (
        $officialDefinitions
        as $formKey => $designation
    ) {

        $posted =
            isset($postedOfficials[$formKey]) &&
            is_array($postedOfficials[$formKey])
                ? $postedOfficials[$formKey]
                : [];


        $record = [

            'form_key' =>
                $formKey,

            'designation' =>
                $designation,

            'full_name' =>
                cleanString(
                    $posted['full_name'] ?? ''
                ),

            'nationality' =>
                cleanString(
                    $posted['nationality'] ?? ''
                ),

            'id_passport_number' =>
                cleanString(
                    $posted['id_passport_number'] ?? ''
                ),

            'kra_pin' =>
                cleanString(
                    $posted['kra_pin'] ?? ''
                ),

            'phone_number' =>
                cleanString(
                    $posted['phone_number'] ?? ''
                ),

            'email' =>
                cleanString(
                    $posted['email'] ?? ''
                ),

            'existing_signature' =>
                cleanString(
                    $posted['existing_signature'] ?? ''
                ),

            'signature' =>
                cleanString(
                    $posted['existing_signature'] ?? ''
                ),
        ];


        $namedOfficials[$formKey] =
            $record;
    }


    /*
    |--------------------------------------------------------------------------
    | Directors
    |--------------------------------------------------------------------------
    */

    $postedDirectors =
        $_POST['directors'] ?? [];

    if (!is_array($postedDirectors)) {
        $postedDirectors = [];
    }


    $submittedDirectors = [];


    foreach (
        $postedDirectors
        as $index => $posted
    ) {

        if (!is_array($posted)) {
            continue;
        }


        $record = [

            'form_key' =>
                'director_' . $index,

            'director_index' =>
                (int) $index,

            'designation' =>
                'DIRECTOR',

            'full_name' =>
                cleanString(
                    $posted['full_name'] ?? ''
                ),

            'nationality' =>
                cleanString(
                    $posted['nationality'] ?? ''
                ),

            'id_passport_number' =>
                cleanString(
                    $posted['id_passport_number'] ?? ''
                ),

            'kra_pin' =>
                cleanString(
                    $posted['kra_pin'] ?? ''
                ),

            'phone_number' =>
                cleanString(
                    $posted['phone_number'] ?? ''
                ),

            'email' =>
                cleanString(
                    $posted['email'] ?? ''
                ),

            'existing_signature' =>
                cleanString(
                    $posted['existing_signature'] ?? ''
                ),

            'signature' =>
                cleanString(
                    $posted['existing_signature'] ?? ''
                ),
        ];


        /*
        | Do not discard partially completed Director forms.
        |
        | If ANY value is entered, preserve the whole card so
        | validation errors do not clear the user's input.
        */

        $submittedDirectors[] =
            $record;
    }


    /*
    |--------------------------------------------------------------------------
    | Repopulate page immediately
    |--------------------------------------------------------------------------
    |
    | This is important:
    |
    | if validation fails later, the page uses POSTed values instead
    | of reloading old database values.
    |
    */

    $chairperson =
        $namedOfficials['chairperson'];

    $treasurer =
        $namedOfficials['treasurer'];

    $secretary =
        $namedOfficials['secretary'];

    $directors =
        $submittedDirectors;


    while (count($directors) < 2) {
        $directors[] =
            emptyOfficial('DIRECTOR');
    }


    /*
    |--------------------------------------------------------------------------
    | Validate textual particulars
    |--------------------------------------------------------------------------
    */

    $allOfficials = [
        $chairperson,
        $treasurer,
        $secretary,
    ];

    foreach ($directors as $director) {
        $allOfficials[] = $director;
    }


    foreach (
        $allOfficials
        as $position => $record
    ) {

        $label =
            $record['designation'] === 'DIRECTOR'
                ? 'Director ' .
                    max(
                        1,
                        $position - 2
                    )
                : officialLabel(
                    $record['designation']
                );


        $requiredFields = [

            'full_name'
                => 'Name',

            'nationality'
                => 'Nationality',

            'id_passport_number'
                => 'ID / Passport Number',

            'kra_pin'
                => 'KRA PIN',

            'phone_number'
                => 'Phone Number',

            'email'
                => 'Email',
        ];


        foreach (
            $requiredFields
            as $field => $fieldLabel
        ) {

            if (
                trim(
                    (string) $record[$field]
                ) === ''
            ) {

                $errors[] =
                    $label .
                    ': ' .
                    $fieldLabel .
                    ' is required.';
            }
        }


        if (
            $record['email'] !== '' &&
            !filter_var(
                $record['email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $errors[] =
                $label .
                ': enter a valid email address.';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | At least two additional Directors
    |--------------------------------------------------------------------------
    */

    if (count($directors) < 2) {

        $errors[] =
            'At least two additional Directors are required.';
    }


    /*
    |--------------------------------------------------------------------------
    | Do not process uploads if directory is unavailable
    |--------------------------------------------------------------------------
    */

    if (
        $directoryError === null &&
        !is_dir($uploadDirectory)
    ) {

        $errors[] =
            'Signature storage is currently unavailable.';
    }


    /*
    |--------------------------------------------------------------------------
    | File upload utility
    |--------------------------------------------------------------------------
    */

    $newlyUploadedFiles = [];


    $processSignature = function (
        ?array $file,
        string $existingSignature,
        string $personLabel
    ) use (
        $uploadDirectory,
        $uploadWebPath,
        $maximumUploadSize,
        $allowedMimeTypes,
        &$newlyUploadedFiles,
        &$errors
    ): string {


        /*
        | No new file selected.
        */

        if (
            $file === null ||
            !isset($file['error']) ||
            (int) $file['error'] ===
                UPLOAD_ERR_NO_FILE
        ) {

            if ($existingSignature !== '') {
                return $existingSignature;
            }


            $errors[] =
                $personLabel .
                ': Signature is required.';

            return '';
        }


        /*
        |--------------------------------------------------------------------------
        | PHP upload errors
        |--------------------------------------------------------------------------
        */

        $errorCode =
            (int) $file['error'];


        if ($errorCode !== UPLOAD_ERR_OK) {

            $messages = [

                UPLOAD_ERR_INI_SIZE =>
                    'The signature exceeds the server upload size limit.',

                UPLOAD_ERR_FORM_SIZE =>
                    'The signature exceeds the permitted form size.',

                UPLOAD_ERR_PARTIAL =>
                    'The signature was only partially uploaded.',

                UPLOAD_ERR_NO_FILE =>
                    'No signature file was uploaded.',

                UPLOAD_ERR_NO_TMP_DIR =>
                    'The server temporary upload directory is missing.',

                UPLOAD_ERR_CANT_WRITE =>
                    'The server could not write the signature to disk.',

                UPLOAD_ERR_EXTENSION =>
                    'A PHP extension stopped the signature upload.',
            ];


            $errors[] =
                $personLabel .
                ': ' .
                (
                    $messages[$errorCode]
                    ??
                    'Signature upload failed with error code ' .
                    $errorCode .
                    '.'
                );


            error_log(
                'Signature upload error [' .
                $personLabel .
                '] code=' .
                $errorCode
            );


            return $existingSignature;
        }


        /*
        |--------------------------------------------------------------------------
        | File metadata
        |--------------------------------------------------------------------------
        */

        $temporaryFile =
            (string) ($file['tmp_name'] ?? '');

        $fileSize =
            (int) ($file['size'] ?? 0);


        if (
            $temporaryFile === '' ||
            !is_uploaded_file($temporaryFile)
        ) {

            $errors[] =
                $personLabel .
                ': The uploaded signature could not be verified.';

            return $existingSignature;
        }


        /*
        |--------------------------------------------------------------------------
        | Size
        |--------------------------------------------------------------------------
        */

        if ($fileSize <= 0) {

            $errors[] =
                $personLabel .
                ': The signature file is empty.';

            return $existingSignature;
        }


        if (
            $fileSize >
            $maximumUploadSize
        ) {

            $errors[] =
                $personLabel .
                ': Signature must not exceed 5 MB.';

            return $existingSignature;
        }


        /*
        |--------------------------------------------------------------------------
        | MIME type
        |--------------------------------------------------------------------------
        */

        try {

            $finfo =
                new finfo(
                    FILEINFO_MIME_TYPE
                );

            $mimeType =
                $finfo->file(
                    $temporaryFile
                );

        } catch (Throwable $exception) {

            error_log(
                'Unable to inspect signature MIME type [' .
                $personLabel .
                ']: ' .
                $exception->getMessage()
            );

            $errors[] =
                $personLabel .
                ': The signature file type could not be verified.';

            return $existingSignature;
        }


        if (
            !is_string($mimeType) ||
            !isset(
                $allowedMimeTypes[$mimeType]
            )
        ) {

            $errors[] =
                $personLabel .
                ': Signature must be a JPG, PNG, WEBP or PDF file.';

            return $existingSignature;
        }


        $extension =
            $allowedMimeTypes[$mimeType];


        /*
        |--------------------------------------------------------------------------
        | Safe filename
        |--------------------------------------------------------------------------
        */

        $safeLabel =
            strtolower(
                preg_replace(
                    '/[^a-zA-Z0-9]+/',
                    '-',
                    $personLabel
                ) ?? 'signature'
            );


        $safeLabel =
            trim(
                $safeLabel,
                '-'
            );


        if ($safeLabel === '') {
            $safeLabel = 'signature';
        }


        try {

            $randomPart =
                bin2hex(
                    random_bytes(12)
                );

        } catch (Throwable $exception) {

            $randomPart =
                str_replace(
                    '.',
                    '',
                    uniqid('', true)
                );
        }


        $fileName =
            $safeLabel .
            '-' .
            $randomPart .
            '.' .
            $extension;


        $destination =
            $uploadDirectory .
            DIRECTORY_SEPARATOR .
            $fileName;


        /*
        |--------------------------------------------------------------------------
        | Move uploaded file
        |--------------------------------------------------------------------------
        */

        if (
            !move_uploaded_file(
                $temporaryFile,
                $destination
            )
        ) {

            $lastError =
                error_get_last();


            error_log(
                'move_uploaded_file failed [' .
                $personLabel .
                '] destination=' .
                $destination .
                ' | ' .
                ($lastError['message'] ?? 'Unknown error')
            );


            $errors[] =
                $personLabel .
                ': The signature was received but could not be saved.';

            return $existingSignature;
        }


        /*
        |--------------------------------------------------------------------------
        | File permissions
        |--------------------------------------------------------------------------
        */

        @chmod(
            $destination,
            0644
        );


        /*
        |--------------------------------------------------------------------------
        | Track newly uploaded file
        |--------------------------------------------------------------------------
        |
        | If later validation/database operations fail, these files
        | can be removed without touching existing signatures.
        |
        */

        $newlyUploadedFiles[] =
            $destination;


        /*
        |--------------------------------------------------------------------------
        | Return web path for database
        |--------------------------------------------------------------------------
        */

        return
            $uploadWebPath .
            '/' .
            $fileName;
    };


    /*
    |--------------------------------------------------------------------------
    | Helper for extracting PHP upload array
    |--------------------------------------------------------------------------
    */

    $getNamedSignatureFile =
        static function (
            string $formKey
        ): ?array {

            if (
                !isset(
                    $_FILES['signatures']
                ) ||
                !is_array(
                    $_FILES['signatures']
                )
            ) {
                return null;
            }


            if (
                !isset(
                    $_FILES['signatures']['error'][$formKey]
                )
            ) {
                return null;
            }


            return [

                'name' =>
                    $_FILES['signatures']['name'][$formKey]
                    ?? '',

                'type' =>
                    $_FILES['signatures']['type'][$formKey]
                    ?? '',

                'tmp_name' =>
                    $_FILES['signatures']['tmp_name'][$formKey]
                    ?? '',

                'error' =>
                    $_FILES['signatures']['error'][$formKey]
                    ?? UPLOAD_ERR_NO_FILE,

                'size' =>
                    $_FILES['signatures']['size'][$formKey]
                    ?? 0,
            ];
        };


    $getDirectorSignatureFile =
        static function (
            int $index
        ): ?array {

            if (
                !isset(
                    $_FILES['director_signatures']
                ) ||
                !is_array(
                    $_FILES['director_signatures']
                )
            ) {
                return null;
            }


            if (
                !isset(
                    $_FILES['director_signatures']['error'][$index]
                )
            ) {
                return null;
            }


            return [

                'name' =>
                    $_FILES['director_signatures']['name'][$index]
                    ?? '',

                'type' =>
                    $_FILES['director_signatures']['type'][$index]
                    ?? '',

                'tmp_name' =>
                    $_FILES['director_signatures']['tmp_name'][$index]
                    ?? '',

                'error' =>
                    $_FILES['director_signatures']['error'][$index]
                    ?? UPLOAD_ERR_NO_FILE,

                'size' =>
                    $_FILES['director_signatures']['size'][$index]
                    ?? 0,
            ];
        };


    /*
    |--------------------------------------------------------------------------
    | Process named-official signatures
    |--------------------------------------------------------------------------
    |
    | We process the signatures even if another textual validation error
    | exists. Their paths are then preserved in hidden fields when the page
    | reloads, so a successfully uploaded signature does not have to be
    | uploaded again.
    |
    */

    if ($directoryError === null) {

        foreach (
            $namedOfficials
            as $formKey => &$record
        ) {

            $personLabel =
                officialLabel(
                    $record['designation']
                );


            $file =
                $getNamedSignatureFile(
                    $formKey
                );


            $record['signature'] =
                $processSignature(
                    $file,
                    $record['existing_signature'],
                    $personLabel
                );


            $record['existing_signature'] =
                $record['signature'];
        }

        unset($record);


        /*
        |--------------------------------------------------------------------------
        | Process Director signatures
        |--------------------------------------------------------------------------
        */

        foreach (
            $submittedDirectors
            as $index => &$record
        ) {

            $personLabel =
                'Director ' .
                ($index + 1);


            $file =
                $getDirectorSignatureFile(
                    (int) $record['director_index']
                );


            $record['signature'] =
                $processSignature(
                    $file,
                    $record['existing_signature'],
                    $personLabel
                );


            $record['existing_signature'] =
                $record['signature'];
        }

        unset($record);
    }


    /*
    |--------------------------------------------------------------------------
    | Rebuild display values AFTER uploads
    |--------------------------------------------------------------------------
    |
    | This is what prevents the form from clearing when validation fails.
    |
    */

    $chairperson =
        $namedOfficials['chairperson'];

    $treasurer =
        $namedOfficials['treasurer'];

    $secretary =
        $namedOfficials['secretary'];

    $directors =
        array_values(
            $submittedDirectors
        );


    while (count($directors) < 2) {
        $directors[] =
            emptyOfficial('DIRECTOR');
    }


    /*
    |--------------------------------------------------------------------------
    | Final signature validation
    |--------------------------------------------------------------------------
    */

    $officialsToSave = [
        $chairperson,
        $treasurer,
        $secretary,
    ];


    foreach ($directors as $director) {
        $officialsToSave[] =
            $director;
    }


    foreach (
        $officialsToSave
        as $index => $record
    ) {

        $label =
            $record['designation'] === 'DIRECTOR'
                ? 'Director ' .
                    max(
                        1,
                        $index - 2
                    )
                : officialLabel(
                    $record['designation']
                );


        if (
            trim(
                (string)
                ($record['signature'] ?? '')
            ) === ''
        ) {

            /*
            | Avoid duplicate "Signature required" messages if
            | processSignature already added one.
            */

            $expectedMessage =
                $label .
                ': Signature is required.';


            if (
                !in_array(
                    $expectedMessage,
                    $errors,
                    true
                )
            ) {

                $errors[] =
                    $expectedMessage;
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Save only when everything is valid
    |--------------------------------------------------------------------------
    */

    if ($errors === []) {

        try {

            $connection->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Lock application
            |--------------------------------------------------------------------------
            */

            $statement =
                $connection->prepare(
                    "
                    SELECT
                        id,
                        status
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


            $lockedApplication =
                $statement->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$lockedApplication) {

                throw new RuntimeException(
                    'Application could not be locked.'
                );
            }


            if (
                $lockedApplication['status'] !==
                'draft'
            ) {

                throw new RuntimeException(
                    'This application is no longer editable.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Remember old signature paths
            |--------------------------------------------------------------------------
            |
            | We do not delete old physical files until the new database
            | transaction has committed successfully.
            |
            */

            $statement =
                $connection->prepare(
                    "
                    SELECT
                        signature
                    FROM application_officials
                    WHERE application_id = :application_id
                    "
                );


            $statement->execute([
                ':application_id'
                    => $applicationId,
            ]);


            $oldSignaturePaths =
                $statement->fetchAll(
                    PDO::FETCH_COLUMN
                );


            /*
            |--------------------------------------------------------------------------
            | Replace official records
            |--------------------------------------------------------------------------
            */

            $statement =
                $connection->prepare(
                    "
                    DELETE FROM application_officials
                    WHERE application_id = :application_id
                    "
                );


            $statement->execute([
                ':application_id'
                    => $applicationId,
            ]);


            /*
            |--------------------------------------------------------------------------
            | Insert current officials
            |--------------------------------------------------------------------------
            */

            $insert =
                $connection->prepare(
                    "
                    INSERT INTO application_officials
                    (
                        application_id,
                        full_name,
                        designation,
                        nationality,
                        id_passport_number,
                        kra_pin,
                        phone_number,
                        email,
                        signature
                    )
                    VALUES
                    (
                        :application_id,
                        :full_name,
                        :designation,
                        :nationality,
                        :id_passport_number,
                        :kra_pin,
                        :phone_number,
                        :email,
                        :signature
                    )
                    "
                );


            foreach (
                $officialsToSave
                as $record
            ) {

                $insert->execute([

                    ':application_id'
                        => $applicationId,

                    ':full_name'
                        => $record['full_name'],

                    ':designation'
                        => $record['designation'],

                    ':nationality'
                        => $record['nationality'],

                    ':id_passport_number'
                        => $record['id_passport_number'],

                    ':kra_pin'
                        => $record['kra_pin'],

                    ':phone_number'
                        => $record['phone_number'],

                    ':email'
                        => $record['email'],

                    ':signature'
                        => $record['signature'],
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | Advance wizard
            |--------------------------------------------------------------------------
            */

            $statement =
                $connection->prepare(
                    "
                    UPDATE pbo_applications
                    SET
                        current_step =
                            CASE
                                WHEN current_step < 6
                                    THEN 6
                                ELSE current_step
                            END,
                        updated_by =
                            :updated_by
                    WHERE id =
                        :application_id
                    "
                );


            $statement->execute([

                ':updated_by'
                    => $userId,

                ':application_id'
                    => $applicationId,
            ]);


            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            $statement =
                $connection->prepare(
                    "
                    INSERT INTO application_audit_log
                    (
                        application_id,
                        user_id,
                        action,
                        description
                    )
                    VALUES
                    (
                        :application_id,
                        :user_id,
                        'OFFICIALS_SAVED',
                        :description
                    )
                    "
                );


            $statement->execute([

                ':application_id'
                    => $applicationId,

                ':user_id'
                    => $userId,

                ':description'
                    => count(
                        $officialsToSave
                    ) .
                    ' official/director record(s) saved.',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            $connection->commit();


            /*
            |--------------------------------------------------------------------------
            | Remove replaced/orphaned OLD signature files
            |--------------------------------------------------------------------------
            |
            | Only delete files that:
            |
            | 1. were previously stored,
            | 2. are no longer referenced,
            | 3. belong to this application's signature directory.
            |
            */

            $currentSignaturePaths =
                array_values(
                    array_filter(
                        array_map(
                            static fn (array $record): string =>
                                trim(
                                    (string)
                                    ($record['signature'] ?? '')
                                ),
                            $officialsToSave
                        )
                    )
                );


            foreach (
                $oldSignaturePaths
                as $oldWebPath
            ) {

                $oldWebPath =
                    trim(
                        (string) $oldWebPath
                    );


                if ($oldWebPath === '') {
                    continue;
                }


                if (
                    in_array(
                        $oldWebPath,
                        $currentSignaturePaths,
                        true
                    )
                ) {
                    continue;
                }


                /*
                | Only operate on our expected application
                | signature directory.
                */

                $expectedPrefix =
                    $uploadWebPath .
                    '/';


                if (
                    !str_starts_with(
                        $oldWebPath,
                        $expectedPrefix
                    )
                ) {
                    continue;
                }


                $oldFileName =
                    basename(
                        $oldWebPath
                    );


                $oldPhysicalPath =
                    $uploadDirectory .
                    DIRECTORY_SEPARATOR .
                    $oldFileName;


                if (
                    is_file(
                        $oldPhysicalPath
                    )
                ) {

                    @unlink(
                        $oldPhysicalPath
                    );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Continue to Review
            |--------------------------------------------------------------------------
            */

            header(
                'Location: /pbo-portal/applications/review.php?id=' .
                urlencode(
                    (string)
                    $applicationId
                )
            );

            exit;

        } catch (Throwable $exception) {

            if (
                $connection->inTransaction()
            ) {

                $connection->rollBack();
            }


            /*
            |--------------------------------------------------------------------------
            | Database failed — remove only NEW uploads
            |--------------------------------------------------------------------------
            |
            | Existing signatures remain untouched.
            |
            */

            foreach (
                $newlyUploadedFiles
                as $uploadedFile
            ) {

                if (
                    is_file(
                        $uploadedFile
                    )
                ) {

                    @unlink(
                        $uploadedFile
                    );
                }
            }


            error_log(
                'Saving officials failed: ' .
                $exception->getMessage()
            );


            $errors[] =
                'The officials and directors could not be saved. Your entered information has been retained on this page.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Shared header
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/header.php';

?>


<div class="applications-page">


    <!-- ==========================================================
         PAGE HEADER
         ========================================================== -->

    <section class="page-header">

        <div>

            <span class="section-eyebrow">
                PBO REGISTRATION
            </span>

            <h1>
                Officials and Directors
            </h1>

            <p class="subtitle">
                Step 5 of 6 — Enter the personal particulars of the
                organization's officials and additional directors.
            </p>

        </div>

    </section>


    <!-- ==========================================================
         APPLICATION SUMMARY
         ========================================================== -->

    <div class="admin-info">

        <strong>
            <?= e(
                (string)
                $application['pbo_name']
            ) ?>
        </strong>


        <?php if (
            !empty(
                $application['application_reference']
            )
        ): ?>

            &nbsp;&mdash;&nbsp;

            Application Reference:

            <strong>
                <?= e(
                    (string)
                    $application[
                        'application_reference'
                    ]
                ) ?>
            </strong>

        <?php endif; ?>

    </div>


    <!-- ==========================================================
         PROGRESS
         ========================================================== -->

    <div
        style="
            margin-bottom: 22px;
            padding: 16px 18px;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
        "
    >

        <div
            style="
                display: flex;
                justify-content: space-between;
                gap: 10px;
                margin-bottom: 10px;
                color: var(--text-secondary);
                font-size: 11px;
                font-weight: 700;
            "
        >

            <span>1. Organization</span>
            <span>2. Objectives</span>
            <span>3. Beneficiaries</span>
            <span>4. Financial Year</span>

            <span style="color: var(--blue);">
                5. Officials
            </span>

            <span>6. Review</span>

        </div>


        <div
            style="
                width: 100%;
                height: 7px;
                overflow: hidden;
                background: #e9edf1;
                border-radius: 20px;
            "
        >

            <div
                style="
                    width: 83.3333%;
                    height: 100%;
                    background:
                        linear-gradient(
                            90deg,
                            var(--blue),
                            var(--blue-medium)
                        );
                "
            ></div>

        </div>

    </div>


    <!-- ==========================================================
         STORAGE ERROR
         ========================================================== -->

    <?php if (
        $directoryError !== null
    ): ?>

        <div class="alert alert-error">

            <strong>
                Signature storage is unavailable.
            </strong>

            <p style="margin: 6px 0 0;">
                <?= e($directoryError) ?>
            </p>

        </div>

    <?php endif; ?>


    <!-- ==========================================================
         FORM ERRORS
         ========================================================== -->

    <?php if ($errors !== []): ?>

    <div class="alert alert-error">

        <strong>
            Please correct the following:
        </strong>

        <ul
            style="
                margin: 8px 0 0;
                padding-left: 20px;
            "
        >

            <?php foreach ($errors as $error): ?>

                <li>
                    <?= e($error) ?>
                </li>

            <?php endforeach; ?>

        </ul>

    </div>

<?php endif; ?>

    <!-- ==========================================================
         OFFICIALS FORM
         ========================================================== -->

    <form
        method="post"
        action=""
        enctype="multipart/form-data"
        class="application-form"
        id="officials-form"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >

        <input
            type="hidden"
            name="application_id"
            value="<?= (int) $applicationId ?>"
        >


        <!-- ======================================================
             INTRODUCTION
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    5
                </div>

                <div>

                    <h2>
                        Personal Particulars
                    </h2>

                    <p>
                        Enter the particulars of the Chairperson,
                        Treasurer, Secretary and additional Directors.
                    </p>

                </div>

            </div>


            <div class="admin-info">

                Complete the seven particulars for each person:

                <strong>Name</strong>,
                <strong>Nationality</strong>,
                <strong>ID / Passport Number</strong>,
                <strong>KRA PIN</strong>,
                <strong>Phone Number</strong>,
                <strong>Email</strong> and
                <strong>Signature</strong>.

                At least two additional Directors should be entered.

            </div>

        </section>


        <!-- ======================================================
             CHAIRPERSON
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    1
                </div>

                <div>

                    <h2>
                        Chairperson
                    </h2>

                    <p>
                        Personal particulars of the Chairperson.
                    </p>

                </div>

            </div>


            <div class="form-group">

                <label for="chairperson_name">
                    Name
                    <span class="required">*</span>
                </label>

                <input
                    type="text"
                    id="chairperson_name"
                    name="officials[chairperson][full_name]"
                    maxlength="255"
                    value="<?= e($chairperson['full_name']) ?>"
                    required
                >

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="chairperson_nationality">
                        Nationality
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="chairperson_nationality"
                        name="officials[chairperson][nationality]"
                        maxlength="100"
                        value="<?= e($chairperson['nationality']) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="chairperson_id">
                        ID / Passport Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="chairperson_id"
                        name="officials[chairperson][id_passport_number]"
                        maxlength="100"
                        value="<?= e(
                            $chairperson['id_passport_number']
                        ) ?>"
                        required
                    >

                </div>

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="chairperson_kra">
                        KRA PIN
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="chairperson_kra"
                        name="officials[chairperson][kra_pin]"
                        maxlength="50"
                        value="<?= e($chairperson['kra_pin']) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="chairperson_phone">
                        Phone Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="tel"
                        id="chairperson_phone"
                        name="officials[chairperson][phone_number]"
                        maxlength="50"
                        value="<?= e(
                            $chairperson['phone_number']
                        ) ?>"
                        required
                    >

                </div>

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="chairperson_email">
                        Email
                        <span class="required">*</span>
                    </label>

                    <input
                        type="email"
                        id="chairperson_email"
                        name="officials[chairperson][email]"
                        maxlength="255"
                        value="<?= e($chairperson['email']) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="chairperson_signature">
                        Signature
                        <span class="required">*</span>
                    </label>


                    <!-- Preserve an already uploaded signature -->

                    <input
                        type="hidden"
                        name="officials[chairperson][existing_signature]"
                        value="<?= e(
                            $chairperson['existing_signature']
                            ??
                            $chairperson['signature']
                            ??
                            ''
                        ) ?>"
                    >


                    <?php if (
                        !empty(
                            $chairperson['existing_signature']
                            ??
                            $chairperson['signature']
                            ??
                            ''
                        )
                    ): ?>

                        <div
                            style="
                                margin-bottom: 8px;
                                padding: 9px 11px;
                                background: var(--green-light);
                                border: 1px solid #c3dfce;
                                border-radius: var(--radius);
                                color: var(--green-dark);
                                font-size: 11px;
                                font-weight: 700;
                            "
                        >
                            &#10003; Signature already uploaded
                        </div>

                    <?php endif; ?>


                    <input
                        type="file"
                        id="chairperson_signature"
                        name="signatures[chairperson]"
                        accept=".jpg,.jpeg,.png,.webp,.pdf"
                        <?= empty(
                            $chairperson['existing_signature']
                            ??
                            $chairperson['signature']
                            ??
                            ''
                        )
                            ? 'required'
                            : '' ?>
                    >

                    <span class="form-help">

                        <?php if (
                            !empty(
                                $chairperson['existing_signature']
                                ??
                                $chairperson['signature']
                                ??
                                ''
                            )
                        ): ?>

                            Select another file only if the existing
                            signature should be replaced.

                        <?php else: ?>

                            Upload JPG, PNG, WEBP or PDF.
                            Maximum file size: 5 MB.

                        <?php endif; ?>

                    </span>

                </div>

            </div>

        </section>


        <!-- ======================================================
             TREASURER
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    2
                </div>

                <div>

                    <h2>
                        Treasurer
                    </h2>

                    <p>
                        Personal particulars of the Treasurer.
                    </p>

                </div>

            </div>


            <div class="form-group">

                <label for="treasurer_name">
                    Name
                    <span class="required">*</span>
                </label>

                <input
                    type="text"
                    id="treasurer_name"
                    name="officials[treasurer][full_name]"
                    maxlength="255"
                    value="<?= e($treasurer['full_name']) ?>"
                    required
                >

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="treasurer_nationality">
                        Nationality
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="treasurer_nationality"
                        name="officials[treasurer][nationality]"
                        maxlength="100"
                        value="<?= e($treasurer['nationality']) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="treasurer_id">
                        ID / Passport Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="treasurer_id"
                        name="officials[treasurer][id_passport_number]"
                        maxlength="100"
                        value="<?= e(
                            $treasurer['id_passport_number']
                        ) ?>"
                        required
                    >

                </div>

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="treasurer_kra">
                        KRA PIN
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="treasurer_kra"
                        name="officials[treasurer][kra_pin]"
                        maxlength="50"
                        value="<?= e($treasurer['kra_pin']) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="treasurer_phone">
                        Phone Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="tel"
                        id="treasurer_phone"
                        name="officials[treasurer][phone_number]"
                        maxlength="50"
                        value="<?= e(
                            $treasurer['phone_number']
                        ) ?>"
                        required
                    >

                </div>

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="treasurer_email">
                        Email
                        <span class="required">*</span>
                    </label>

                    <input
                        type="email"
                        id="treasurer_email"
                        name="officials[treasurer][email]"
                        maxlength="255"
                        value="<?= e($treasurer['email']) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="treasurer_signature">
                        Signature
                        <span class="required">*</span>
                    </label>

                    <input
                        type="hidden"
                        name="officials[treasurer][existing_signature]"
                        value="<?= e(
                            $treasurer['existing_signature']
                            ??
                            $treasurer['signature']
                            ??
                            ''
                        ) ?>"
                    >


                    <?php if (
                        !empty(
                            $treasurer['existing_signature']
                            ??
                            $treasurer['signature']
                            ??
                            ''
                        )
                    ): ?>

                        <div
                            style="
                                margin-bottom: 8px;
                                padding: 9px 11px;
                                background: var(--green-light);
                                border: 1px solid #c3dfce;
                                border-radius: var(--radius);
                                color: var(--green-dark);
                                font-size: 11px;
                                font-weight: 700;
                            "
                        >
                            &#10003; Signature already uploaded
                        </div>

                    <?php endif; ?>


                    <input
                        type="file"
                        id="treasurer_signature"
                        name="signatures[treasurer]"
                        accept=".jpg,.jpeg,.png,.webp,.pdf"
                        <?= empty(
                            $treasurer['existing_signature']
                            ??
                            $treasurer['signature']
                            ??
                            ''
                        )
                            ? 'required'
                            : '' ?>
                    >

                    <span class="form-help">

                        <?php if (
                            !empty(
                                $treasurer['existing_signature']
                                ??
                                $treasurer['signature']
                                ??
                                ''
                            )
                        ): ?>

                            Select another file only if the existing
                            signature should be replaced.

                        <?php else: ?>

                            Upload JPG, PNG, WEBP or PDF.
                            Maximum file size: 5 MB.

                        <?php endif; ?>

                    </span>

                </div>

            </div>

        </section>


        <!-- ======================================================
             SECRETARY
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    3
                </div>

                <div>

                    <h2>
                        Secretary
                    </h2>

                    <p>
                        Personal particulars of the Secretary.
                    </p>

                </div>

            </div>


            <div class="form-group">

                <label for="secretary_name">
                    Name
                    <span class="required">*</span>
                </label>

                <input
                    type="text"
                    id="secretary_name"
                    name="officials[secretary][full_name]"
                    maxlength="255"
                    value="<?= e($secretary['full_name']) ?>"
                    required
                >

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="secretary_nationality">
                        Nationality
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="secretary_nationality"
                        name="officials[secretary][nationality]"
                        maxlength="100"
                        value="<?= e($secretary['nationality']) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="secretary_id">
                        ID / Passport Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="secretary_id"
                        name="officials[secretary][id_passport_number]"
                        maxlength="100"
                        value="<?= e(
                            $secretary['id_passport_number']
                        ) ?>"
                        required
                    >

                </div>

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="secretary_kra">
                        KRA PIN
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="secretary_kra"
                        name="officials[secretary][kra_pin]"
                        maxlength="50"
                        value="<?= e($secretary['kra_pin']) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="secretary_phone">
                        Phone Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="tel"
                        id="secretary_phone"
                        name="officials[secretary][phone_number]"
                        maxlength="50"
                        value="<?= e(
                            $secretary['phone_number']
                        ) ?>"
                        required
                    >

                </div>

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="secretary_email">
                        Email
                        <span class="required">*</span>
                    </label>

                    <input
                        type="email"
                        id="secretary_email"
                        name="officials[secretary][email]"
                        maxlength="255"
                        value="<?= e($secretary['email']) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="secretary_signature">
                        Signature
                        <span class="required">*</span>
                    </label>

                    <input
                        type="hidden"
                        name="officials[secretary][existing_signature]"
                        value="<?= e(
                            $secretary['existing_signature']
                            ??
                            $secretary['signature']
                            ??
                            ''
                        ) ?>"
                    >


                    <?php if (
                        !empty(
                            $secretary['existing_signature']
                            ??
                            $secretary['signature']
                            ??
                            ''
                        )
                    ): ?>

                        <div
                            style="
                                margin-bottom: 8px;
                                padding: 9px 11px;
                                background: var(--green-light);
                                border: 1px solid #c3dfce;
                                border-radius: var(--radius);
                                color: var(--green-dark);
                                font-size: 11px;
                                font-weight: 700;
                            "
                        >
                            &#10003; Signature already uploaded
                        </div>

                    <?php endif; ?>


                    <input
                        type="file"
                        id="secretary_signature"
                        name="signatures[secretary]"
                        accept=".jpg,.jpeg,.png,.webp,.pdf"
                        <?= empty(
                            $secretary['existing_signature']
                            ??
                            $secretary['signature']
                            ??
                            ''
                        )
                            ? 'required'
                            : '' ?>
                    >

                    <span class="form-help">

                        <?php if (
                            !empty(
                                $secretary['existing_signature']
                                ??
                                $secretary['signature']
                                ??
                                ''
                            )
                        ): ?>

                            Select another file only if the existing
                            signature should be replaced.

                        <?php else: ?>

                            Upload JPG, PNG, WEBP or PDF.
                            Maximum file size: 5 MB.

                        <?php endif; ?>

                    </span>

                </div>

            </div>

        </section>


        <!-- ======================================================
             ADDITIONAL DIRECTORS
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    4+
                </div>

                <div>

                    <h2>
                        Additional Directors
                    </h2>

                    <p>
                        Enter the personal particulars of the
                        organization's additional Directors.
                    </p>

                </div>

            </div>


            <div class="admin-info">

                At least <strong>two additional Directors</strong>
                should be captured.

                Use <strong>Add Another Director</strong> where the
                organization has more Directors.

            </div>


            <div id="directors-container">

                <?php foreach (
                    $directors
                    as $index => $director
                ): ?>

                    <?php

                    $storedDirectorSignature =
                        (string) (
                            $director['existing_signature']
                            ??
                            $director['signature']
                            ??
                            ''
                        );

                    ?>


                    <article
                        data-director-card
                        class="director-card"
                        style="
                            margin-bottom: 22px;
                            overflow: hidden;
                            background: var(--white);
                            border: 1px solid var(--border);
                            border-radius: var(--radius);
                            box-shadow: var(--shadow-sm);
                        "
                    >


                        <!-- DIRECTOR HEADER -->

                        <div
                            style="
                                display: flex;
                                align-items: center;
                                justify-content: space-between;
                                gap: 15px;
                                padding: 15px 18px;
                                background:
                                    linear-gradient(
                                        100deg,
                                        var(--blue-light),                                        #ffffff
                                    );
                                border-bottom: 1px solid var(--border);
                            "
                        >

                            <div>

                                <span class="section-eyebrow">
                                    Additional Director
                                </span>

                                <h3
                                    data-director-title
                                    style="
                                        margin: 0;
                                        color: var(--blue-dark);
                                    "
                                >
                                    Director <?= $index + 1 ?>
                                </h3>

                            </div>


                            <button
                                type="button"
                                class="btn btn-danger btn-sm"
                                data-remove-director
                                <?= $index < 2
                                    ? 'style="display:none;"'
                                    : '' ?>
                            >
                                Remove
                            </button>

                        </div>


                        <!-- DIRECTOR BODY -->

                        <div style="padding: 20px;">


                            <!-- NAME -->

                            <div class="form-group">

                                <label>
                                    Name
                                    <span class="required">*</span>
                                </label>

                                <input
                                    type="text"
                                    name="directors[<?= $index ?>][full_name]"
                                    maxlength="255"
                                    value="<?= e(
                                        $director['full_name']
                                    ) ?>"
                                    data-director-field
                                    data-field-name="full_name"
                                    required
                                >

                            </div>


                            <!-- NATIONALITY / ID -->

                            <div class="form-row">

                                <div class="form-group">

                                    <label>
                                        Nationality
                                        <span class="required">*</span>
                                    </label>

                                    <input
                                        type="text"
                                        name="directors[<?= $index ?>][nationality]"
                                        maxlength="100"
                                        value="<?= e(
                                            $director['nationality']
                                        ) ?>"
                                        data-director-field
                                        data-field-name="nationality"
                                        required
                                    >

                                </div>


                                <div class="form-group">

                                    <label>
                                        ID / Passport Number
                                        <span class="required">*</span>
                                    </label>

                                    <input
                                        type="text"
                                        name="directors[<?= $index ?>][id_passport_number]"
                                        maxlength="100"
                                        value="<?= e(
                                            $director['id_passport_number']
                                        ) ?>"
                                        data-director-field
                                        data-field-name="id_passport_number"
                                        required
                                    >

                                </div>

                            </div>


                            <!-- KRA PIN / PHONE -->

                            <div class="form-row">

                                <div class="form-group">

                                    <label>
                                        KRA PIN
                                        <span class="required">*</span>
                                    </label>

                                    <input
                                        type="text"
                                        name="directors[<?= $index ?>][kra_pin]"
                                        maxlength="50"
                                        value="<?= e(
                                            $director['kra_pin']
                                        ) ?>"
                                        data-director-field
                                        data-field-name="kra_pin"
                                        required
                                    >

                                </div>


                                <div class="form-group">

                                    <label>
                                        Phone Number
                                        <span class="required">*</span>
                                    </label>

                                    <input
                                        type="tel"
                                        name="directors[<?= $index ?>][phone_number]"
                                        maxlength="50"
                                        value="<?= e(
                                            $director['phone_number']
                                        ) ?>"
                                        data-director-field
                                        data-field-name="phone_number"
                                        required
                                    >

                                </div>

                            </div>


                            <!-- EMAIL / SIGNATURE -->

                            <div class="form-row">

                                <div class="form-group">

                                    <label>
                                        Email
                                        <span class="required">*</span>
                                    </label>

                                    <input
                                        type="email"
                                        name="directors[<?= $index ?>][email]"
                                        maxlength="255"
                                        value="<?= e(
                                            $director['email']
                                        ) ?>"
                                        data-director-field
                                        data-field-name="email"
                                        required
                                    >

                                </div>


                                <div class="form-group">

                                    <label>
                                        Signature
                                        <span class="required">*</span>
                                    </label>


                                    <!--
                                        Preserve an already uploaded signature.

                                        This is particularly important when
                                        another field fails validation.
                                    -->

                                    <input
                                        type="hidden"
                                        name="directors[<?= $index ?>][existing_signature]"
                                        value="<?= e(
                                            $storedDirectorSignature
                                        ) ?>"
                                        data-director-field
                                        data-field-name="existing_signature"
                                    >


                                    <?php if (
                                        $storedDirectorSignature !== ''
                                    ): ?>

                                        <div
                                            style="
                                                margin-bottom: 8px;
                                                padding: 9px 11px;
                                                background: var(--green-light);
                                                border: 1px solid #c3dfce;
                                                border-radius: var(--radius);
                                                color: var(--green-dark);
                                                font-size: 11px;
                                                font-weight: 700;
                                            "
                                        >
                                            &#10003; Signature already uploaded
                                        </div>

                                    <?php endif; ?>


                                    <input
                                        type="file"
                                        name="director_signatures[<?= $index ?>]"
                                        accept=".jpg,.jpeg,.png,.webp,.pdf"
                                        data-director-signature
                                        <?= $storedDirectorSignature === ''
                                            ? 'required'
                                            : '' ?>
                                    >


                                    <span class="form-help">

                                        <?php if (
                                            $storedDirectorSignature !== ''
                                        ): ?>

                                            Select another file only if
                                            the existing signature should
                                            be replaced.

                                        <?php else: ?>

                                            Upload JPG, PNG, WEBP or PDF.
                                            Maximum file size: 5 MB.

                                        <?php endif; ?>

                                    </span>

                                </div>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>


            <!-- ==================================================
                 ADD DIRECTOR
                 ================================================== -->

            <div
                style="
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    flex-wrap: wrap;
                    margin-top: 5px;
                "
            >

                <button
                    type="button"
                    class="btn btn-secondary"
                    id="add-director"
                >
                    + Add Another Director
                </button>


                <span
                    id="director-count"
                    style="
                        color: var(--text-muted);
                        font-size: 11px;
                    "
                ></span>

            </div>

        </section>


        <!-- ======================================================
             ACTIONS
             ====================================================== -->

        <div class="application-actions">

            <div class="application-actions-left">

                <a
                    href="/pbo-portal/applications/financial-year.php?id=<?= urlencode(
                        (string) $applicationId
                    ) ?>"
                    class="btn btn-secondary"
                >
                    &larr; Back
                </a>

            </div>


            <div class="application-actions-right">

                <span
                    style="
                        color: var(--text-muted);
                        font-size: 11px;
                    "
                >
                    Step 5 of 6
                </span>


                <button
                    type="submit"
                    class="btn btn-primary"
                    id="save-officials-button"
                    <?= $directoryError !== null
                        ? 'disabled'
                        : '' ?>
                >
                    Save &amp; Continue

                    <span aria-hidden="true">
                        &rarr;
                    </span>
                </button>

            </div>

        </div>

    </form>

</div>


<!-- ==============================================================
     NEW DIRECTOR TEMPLATE
     ============================================================== -->

<template id="director-template">

    <article
        data-director-card
        class="director-card"
        style="
            margin-bottom: 22px;
            overflow: hidden;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
        "
    >

        <!-- HEADER -->

        <div
            style="
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 15px;
                padding: 15px 18px;
                background:
                    linear-gradient(
                        100deg,
                        var(--blue-light),
                        #ffffff
                    );
                border-bottom: 1px solid var(--border);
            "
        >

            <div>

                <span class="section-eyebrow">
                    Additional Director
                </span>

                <h3
                    data-director-title
                    style="
                        margin: 0;
                        color: var(--blue-dark);
                    "
                >
                    Director
                </h3>

            </div>


            <button
                type="button"
                class="btn btn-danger btn-sm"
                data-remove-director
            >
                Remove
            </button>

        </div>


        <!-- BODY -->

        <div style="padding: 20px;">


            <div class="form-group">

                <label>
                    Name
                    <span class="required">*</span>
                </label>

                <input
                    type="text"
                    data-director-field
                    data-field-name="full_name"
                    maxlength="255"
                    required
                >

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label>
                        Nationality
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        data-director-field
                        data-field-name="nationality"
                        maxlength="100"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        ID / Passport Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        data-director-field
                        data-field-name="id_passport_number"
                        maxlength="100"
                        required
                    >

                </div>

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label>
                        KRA PIN
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        data-director-field
                        data-field-name="kra_pin"
                        maxlength="50"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Phone Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="tel"
                        data-director-field
                        data-field-name="phone_number"
                        maxlength="50"
                        required
                    >

                </div>

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label>
                        Email
                        <span class="required">*</span>
                    </label>

                    <input
                        type="email"
                        data-director-field
                        data-field-name="email"
                        maxlength="255"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Signature
                        <span class="required">*</span>
                    </label>


                    <input
                        type="hidden"
                        data-director-field
                        data-field-name="existing_signature"
                        value=""
                    >


                    <input
                        type="file"
                        data-director-signature
                        accept=".jpg,.jpeg,.png,.webp,.pdf"
                        required
                    >

                    <span class="form-help">
                        Upload JPG, PNG, WEBP or PDF.
                        Maximum file size: 5 MB.
                    </span>

                </div>

            </div>

        </div>

    </article>

</template>


<!-- ==============================================================
     DIRECTOR + UPLOAD JAVASCRIPT
     ============================================================== -->

<script>
(function () {

    'use strict';


    /*
    |--------------------------------------------------------------------------
    | Elements
    |--------------------------------------------------------------------------
    */

    const form =
        document.getElementById(
            'officials-form'
        );


    const directorsContainer =
        document.getElementById(
            'directors-container'
        );


    const directorTemplate =
        document.getElementById(
            'director-template'
        );


    const addDirectorButton =
        document.getElementById(
            'add-director'
        );


    const directorCount =
        document.getElementById(
            'director-count'
        );


    const saveButton =
        document.getElementById(
            'save-officials-button'
        );


    /*
    |--------------------------------------------------------------------------
    | Safety limit
    |--------------------------------------------------------------------------
    */

    const MAX_ADDITIONAL_DIRECTORS = 30;


    /*
    |--------------------------------------------------------------------------
    | Reindex Director fields
    |--------------------------------------------------------------------------
    |
    | PHP needs contiguous arrays:
    |
    | directors[0][full_name]
    | directors[1][full_name]
    |
    | director_signatures[0]
    | director_signatures[1]
    |
    */

    function reindexDirectors() {

        const cards =
            directorsContainer.querySelectorAll(
                '[data-director-card]'
            );


        cards.forEach(
            function (card, index) {


                /*
                | Heading
                */

                const title =
                    card.querySelector(
                        '[data-director-title]'
                    );


                if (title) {

                    title.textContent =
                        'Director ' +
                        (index + 1);

                }


                /*
                | Text + hidden fields
                */

                const fields =
                    card.querySelectorAll(
                        '[data-director-field]'
                    );


                fields.forEach(
                    function (field) {

                        const fieldName =
                            field.getAttribute(
                                'data-field-name'
                            );


                        if (!fieldName) {
                            return;
                        }


                        field.setAttribute(
                            'name',
                            'directors[' +
                            index +
                            '][' +
                            fieldName +
                            ']'
                        );

                    }
                );


                /*
                | Signature upload
                */

                const signature =
                    card.querySelector(
                        '[data-director-signature]'
                    );


                if (signature) {

                    signature.setAttribute(
                        'name',
                        'director_signatures[' +
                        index +
                        ']'
                    );

                }


                /*
                | First two Director cards cannot be removed.
                */

                const removeButton =
                    card.querySelector(
                        '[data-remove-director]'
                    );


                if (removeButton) {

                    removeButton.style.display =
                        index < 2
                            ? 'none'
                            : '';

                }

            }
        );


        /*
        | Display total.
        */

        const additionalCount =
            cards.length;


        const totalPeople =
            3 +
            additionalCount;


        directorCount.textContent =
            additionalCount +
            ' additional director' +
            (
                additionalCount === 1
                    ? ''
                    : 's'
            ) +
            ' — ' +
            totalPeople +
            ' officials/directors in total';


        /*
        | Add limit.
        */

        addDirectorButton.disabled =
            additionalCount >=
            MAX_ADDITIONAL_DIRECTORS;

    }


    /*
    |--------------------------------------------------------------------------
    | Add Director
    |--------------------------------------------------------------------------
    */

    addDirectorButton.addEventListener(
        'click',
        function () {

            const existingCount =
                directorsContainer.querySelectorAll(
                    '[data-director-card]'
                ).length;


            if (
                existingCount >=
                MAX_ADDITIONAL_DIRECTORS
            ) {
                return;
            }


            const fragment =
                directorTemplate.content.cloneNode(
                    true
                );


            directorsContainer.appendChild(
                fragment
            );


            reindexDirectors();


            /*
            | Focus new Director name.
            */

            const cards =
                directorsContainer.querySelectorAll(
                    '[data-director-card]'
                );


            const lastCard =
                cards[
                    cards.length - 1
                ];


            if (lastCard) {

                const nameInput =
                    lastCard.querySelector(
                        '[data-field-name="full_name"]'
                    );


                if (nameInput) {
                    nameInput.focus();
                }
            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | Remove Director
    |--------------------------------------------------------------------------
    */

    directorsContainer.addEventListener(
        'click',
        function (event) {

            const button =
                event.target.closest(
                    '[data-remove-director]'
                );


            if (!button) {
                return;
            }


            const card =
                button.closest(
                    '[data-director-card]'
                );


            if (!card) {
                return;
            }


            const cards =
                directorsContainer.querySelectorAll(
                    '[data-director-card]'
                );


            /*
            | Never go below two.
            */

            if (cards.length <= 2) {

                alert(
                    'At least two additional Directors must remain.'
                );

                return;
            }


            card.remove();

            reindexDirectors();

        }
    );


    /*
    |--------------------------------------------------------------------------
    | Client-side file validation
    |--------------------------------------------------------------------------
    |
    | PHP performs the authoritative validation.
    |
    */

    form.addEventListener(
        'change',
        function (event) {

            const input =
                event.target;


            if (
                !(input instanceof HTMLInputElement) ||
                input.type !== 'file'
            ) {
                return;
            }


            if (
                !input.files ||
                input.files.length === 0
            ) {
                return;
            }


            const file =
                input.files[0];


            const maximumSize =
                5 * 1024 * 1024;


            if (
                file.size >
                maximumSize
            ) {

                alert(
                    'Signature file must not exceed 5 MB.'
                );

                input.value = '';

                return;
            }


            const allowedMimeTypes = [
                'image/jpeg',
                'image/png',
                'image/webp',
                'application/pdf'
            ];


            /*
            | Some browsers may provide an empty MIME type.
            | Server-side finfo still performs the final check.
            */

            if (
                file.type &&
                !allowedMimeTypes.includes(
                    file.type
                )
            ) {

                alert(
                    'Signature must be a JPG, PNG, WEBP or PDF file.'
                );

                input.value = '';
            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | Initial state
    |--------------------------------------------------------------------------
    */

    reindexDirectors();


    /*
    |--------------------------------------------------------------------------
    | Submit
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        function (event) {


            /*
            | Reindex one final time.
            */

            reindexDirectors();


            /*
            | Minimum two Directors.
            */

            const cards =
                directorsContainer.querySelectorAll(
                    '[data-director-card]'
                );


            if (cards.length < 2) {

                event.preventDefault();

                alert(
                    'Please enter at least two additional Directors.'
                );

                return;
            }


            /*
            | Native validation.
            */

            if (!form.checkValidity()) {

                event.preventDefault();

                form.reportValidity();

                return;
            }


            /*
            | Do NOT clear any fields here.
            |
            | The browser posts the complete form and PHP repopulates
            | all entered values if server-side validation fails.
            */


            /*
            | Prevent duplicate submission.
            */

            if (saveButton) {

                saveButton.disabled = true;

                saveButton.innerHTML =
                    'Saving...';

            }

        }
    );

})();
</script>


<?php

/*
|--------------------------------------------------------------------------
| Shared footer
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/footer.php';

?>