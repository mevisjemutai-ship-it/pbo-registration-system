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
| Database
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
        'TREASURER' => 'Treasurer',
        'SECRETARY' => 'Secretary',
        'DIRECTOR' => 'Director',
        default => ucwords(
            strtolower(
                str_replace('_', ' ', $designation)
            )
        ),
    };
}


function directorHasData(array $record): bool
{
    foreach (
        [
            'full_name',
            'nationality',
            'id_passport_number',
            'kra_pin',
            'phone_number',
            'email',
            'existing_signature',
            'signature',
        ]
        as $field
    ) {
        if (
            trim(
                (string) ($record[$field] ?? '')
            ) !== ''
        ) {
            return true;
        }
    }

    return false;
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

$applicationId =
    filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );

if (!$applicationId) {
    $applicationId =
        filter_input(
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

$applicationId = (int) $applicationId;


/*
|--------------------------------------------------------------------------
| Load application
|--------------------------------------------------------------------------
*/

$statement =
    $connection->prepare(
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
| Signatures are OPTIONAL.
|
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
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];

$directoryError = null;


/*
|--------------------------------------------------------------------------
| Prepare signature directory
|--------------------------------------------------------------------------
|
| A directory problem does NOT stop officials being saved because signatures
| are optional. It matters only when a user actually uploads a signature.
|
*/

if (!is_dir($uploadDirectory)) {

    $created =
        @mkdir(
            $uploadDirectory,
            0775,
            true
        );

    if (
        !$created &&
        !is_dir($uploadDirectory)
    ) {
        $directoryError =
            'Signature storage is currently unavailable.';
    }
}

if (
    $directoryError === null &&
    is_dir($uploadDirectory) &&
    !is_writable($uploadDirectory)
) {
    $directoryError =
        'Signature storage is currently unavailable.';
}


/*
|--------------------------------------------------------------------------
| Initial officials
|--------------------------------------------------------------------------
*/

$chairperson =
    emptyOfficial('CHAIRPERSON');

$treasurer =
    emptyOfficial('TREASURER');

$secretary =
    emptyOfficial('SECRETARY');

$directors = [];


/*
|--------------------------------------------------------------------------
| Load saved officials
|--------------------------------------------------------------------------
*/

$statement =
    $connection->prepare(
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
        'designation' => $designation,
        'full_name' =>
            (string) ($saved['full_name'] ?? ''),
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
| Errors
|--------------------------------------------------------------------------
*/

$errors = [];


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | Submission action
    |--------------------------------------------------------------------------
    */

    $formAction =
        (string)
        ($_POST['form_action'] ?? 'save_continue');

    $isWizardNavigation =
        $formAction === 'wizard_navigation';

    $wizardDestination =
        filter_input(
            INPUT_POST,
            'wizard_destination',
            FILTER_VALIDATE_INT
        );

    if (
        $wizardDestination === false ||
        $wizardDestination === null ||
        $wizardDestination < 1 ||
        $wizardDestination > 6
    ) {
        $wizardDestination = 5;
    }


    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $submittedToken =
        (string)
        ($_POST['csrf_token'] ?? '');

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
    | Principal officials
    |--------------------------------------------------------------------------
    */

    $postedOfficials =
        $_POST['officials'] ?? [];

    if (!is_array($postedOfficials)) {
        $postedOfficials = [];
    }

    $definitions = [
        'chairperson' => 'CHAIRPERSON',
        'treasurer' => 'TREASURER',
        'secretary' => 'SECRETARY',
    ];

    $namedOfficials = [];

    foreach (
        $definitions
        as $formKey => $designation
    ) {

        $posted =
            isset($postedOfficials[$formKey]) &&
            is_array($postedOfficials[$formKey])
                ? $postedOfficials[$formKey]
                : [];

        $namedOfficials[$formKey] = [

            'form_key' => $formKey,

            'designation' => $designation,

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
    }


    /*
    |--------------------------------------------------------------------------
    | Optional Directors
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
        | Ignore completely blank Director cards.
        */

        if (directorHasData($record)) {
            $submittedDirectors[] =
                $record;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Repopulate immediately
    |--------------------------------------------------------------------------
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


    /*
    |--------------------------------------------------------------------------
    | Full validation for Save & Continue
    |--------------------------------------------------------------------------
    |
    | Only Chairperson, Treasurer and Secretary are mandatory.
    |
    */

    if (!$isWizardNavigation) {

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


        /*
        |--------------------------------------------------------------------------
        | Principal officials
        |--------------------------------------------------------------------------
        */

        foreach (
            [
                $chairperson,
                $treasurer,
                $secretary,
            ]
            as $record
        ) {

            $label =
                officialLabel(
                    $record['designation']
                );

            foreach (
                $requiredFields
                as $field => $fieldLabel
            ) {

                if (
                    trim(
                        (string)
                        ($record[$field] ?? '')
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
        | Optional Directors
        |--------------------------------------------------------------------------
        |
        | A Director is not required.
        |
        | But once somebody enters a Director, complete that Director's textual
        | particulars so a malformed partial Director is not stored.
        |
        */

        foreach (
            $directors
            as $index => $director
        ) {

            $label =
                'Director ' .
                ($index + 1);

            foreach (
                $requiredFields
                as $field => $fieldLabel
            ) {

                if (
                    trim(
                        (string)
                        ($director[$field] ?? '')
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
                $director['email'] !== '' &&
                !filter_var(
                    $director['email'],
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                $errors[] =
                    $label .
                    ': enter a valid email address.';
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Upload helpers
    |--------------------------------------------------------------------------
    */

    $newlyUploadedFiles = [];


    $getNamedSignatureFile =
        static function (
            string $formKey
        ): ?array {

            if (
                !isset($_FILES['signatures']) ||
                !is_array($_FILES['signatures']) ||
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
                !isset($_FILES['director_signatures']) ||
                !is_array(
                    $_FILES['director_signatures']
                ) ||
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
    | Process optional signature
    |--------------------------------------------------------------------------
    */

    $processSignature =
        function (
            ?array $file,
            string $existingSignature,
            string $personLabel
        ) use (
            $uploadDirectory,
            $uploadWebPath,
            $maximumUploadSize,
            $allowedMimeTypes,
            $directoryError,
            &$newlyUploadedFiles,
            &$errors
        ): string {

            /*
            | No file selected = perfectly valid.
            */

            if (
                $file === null ||
                !isset($file['error']) ||
                (int) $file['error'] ===
                    UPLOAD_ERR_NO_FILE
            ) {
                return $existingSignature;
            }


            /*
            | Storage only matters when a file was actually selected.
            */

            if (
                $directoryError !== null ||
                !is_dir($uploadDirectory) ||
                !is_writable($uploadDirectory)
            ) {
                $errors[] =
                    $personLabel .
                    ': signature storage is currently unavailable.';

                return $existingSignature;
            }


            $errorCode =
                (int) $file['error'];

            if ($errorCode !== UPLOAD_ERR_OK) {

                $errors[] =
                    $personLabel .
                    ': the signature upload failed.';

                return $existingSignature;
            }


            $temporaryFile =
                (string)
                ($file['tmp_name'] ?? '');

            $fileSize =
                (int)
                ($file['size'] ?? 0);


            if (
                $temporaryFile === '' ||
                !                !is_uploaded_file(
                    $temporaryFile
                )
            ) {
                $errors[] =
                    $personLabel .
                    ': the uploaded signature could not be verified.';

                return $existingSignature;
            }


            /*
            |--------------------------------------------------------------------------
            | File size
            |--------------------------------------------------------------------------
            */

            if ($fileSize <= 0) {

                $errors[] =
                    $personLabel .
                    ': the selected signature file is empty.';

                return $existingSignature;
            }


            if (
                $fileSize >
                $maximumUploadSize
            ) {

                $errors[] =
                    $personLabel .
                    ': signature must not exceed 5 MB.';

                return $existingSignature;
            }


            /*
            |--------------------------------------------------------------------------
            | MIME validation
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
                    ': the signature file type could not be verified.';

                return $existingSignature;
            }


            if (
                !is_string($mimeType) ||
                !isset(
                    $allowedMimeTypes[
                        $mimeType
                    ]
                )
            ) {

                $errors[] =
                    $personLabel .
                    ': signature must be a JPG, PNG, WEBP or PDF file.';

                return $existingSignature;
            }


            $extension =
                $allowedMimeTypes[
                    $mimeType
                ];


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
                $safeLabel =
                    'signature';
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
            | Store upload
            |--------------------------------------------------------------------------
            */

            if (
                !move_uploaded_file(
                    $temporaryFile,
                    $destination
                )
            ) {

                $errors[] =
                    $personLabel .
                    ': the signature could not be saved.';

                return $existingSignature;
            }


            @chmod(
                $destination,
                0644
            );


            $newlyUploadedFiles[] =
                $destination;


            return
                $uploadWebPath .
                '/' .
                $fileName;
        };


    /*
    |--------------------------------------------------------------------------
    | Process principal-official signatures
    |--------------------------------------------------------------------------
    */

    foreach (
        $namedOfficials
        as $formKey => &$record
    ) {

        $file =
            $getNamedSignatureFile(
                $formKey
            );


        $record['signature'] =
            $processSignature(
                $file,
                $record[
                    'existing_signature'
                ],
                officialLabel(
                    $record[
                        'designation'
                    ]
                )
            );


        $record[
            'existing_signature'
        ] =
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

        $file =
            $getDirectorSignatureFile(
                (int)
                $record[
                    'director_index'
                ]
            );


        $record['signature'] =
            $processSignature(
                $file,
                $record[
                    'existing_signature'
                ],
                'Director ' .
                ($index + 1)
            );


        $record[
            'existing_signature'
        ] =
            $record['signature'];
    }

    unset($record);


    /*
    |--------------------------------------------------------------------------
    | Rebuild values after uploads
    |--------------------------------------------------------------------------
    */

    $chairperson =
        $namedOfficials[
            'chairperson'
        ];

    $treasurer =
        $namedOfficials[
            'treasurer'
        ];

    $secretary =
        $namedOfficials[
            'secretary'
        ];

    $directors =
        array_values(
            $submittedDirectors
        );


    /*
    |--------------------------------------------------------------------------
    | Officials to save
    |--------------------------------------------------------------------------
    |
    | The three principal officials are always retained.
    |
    | Directors are included only when a Director has actually been entered.
    |
    */

    $officialsToSave = [
        $chairperson,
        $treasurer,
        $secretary,
    ];


    foreach (
        $directors
        as $director
    ) {

        if (
            directorHasData(
                $director
            )
        ) {

            $officialsToSave[] =
                $director;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Save
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
                $lockedApplication[
                    'status'
                ] !== 'draft'
            ) {

                throw new RuntimeException(
                    'This application is no longer editable.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Existing signature paths
            |--------------------------------------------------------------------------
            */

            $statement =
                $connection->prepare(
                    "
                    SELECT signature
                    FROM application_officials
                    WHERE application_id =
                        :application_id
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
            | Replace officials
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

                /*
                |--------------------------------------------------------------------------
                | During wizard navigation we don't want completely empty
                | principal-official placeholder rows in the database.
                |--------------------------------------------------------------------------
                */

                $hasTextualData =
                    $record['full_name'] !== '' ||
                    $record['nationality'] !== '' ||
                    $record['id_passport_number'] !== '' ||
                    $record['kra_pin'] !== '' ||
                    $record['phone_number'] !== '' ||
                    $record['email'] !== '' ||
                    $record['signature'] !== '';


                if (
                    $isWizardNavigation &&
                    !$hasTextualData
                ) {

                    continue;
                }


                $insert->execute([

                    ':application_id'
                        => $applicationId,

                    ':full_name'
                        => $record[
                            'full_name'
                        ],

                    ':designation'
                        => $record[
                            'designation'
                        ],

                    ':nationality'
                        => $record[
                            'nationality'
                        ],

                    ':id_passport_number'
                        => $record[
                            'id_passport_number'
                        ],

                    ':kra_pin'
                        => $record[
                            'kra_pin'
                        ],

                    ':phone_number'
                        => $record[
                            'phone_number'
                        ],

                    ':email'
                        => $record[
                            'email'
                        ],

                    ':signature'
                        => $record[
                            'signature'
                        ] !== ''
                            ? $record[
                                'signature'
                            ]
                            : null,
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


            $directorCount =
                count($directors);


            $auditDescription =
                'Principal officials saved.' .
                (
                    $directorCount > 0
                        ? ' ' .
                          $directorCount .
                          ' optional director(s) saved.'
                        : ' No additional directors recorded.'
                );


            $statement->execute([

                ':application_id'
                    => $applicationId,

                ':user_id'
                    => $userId,

                ':description'
                    => $auditDescription,
            ]);


            $connection->commit();


            /*
            |--------------------------------------------------------------------------
            | Remove obsolete signature files
            |--------------------------------------------------------------------------
            */

            $currentSignaturePaths =
                array_values(
                    array_filter(
                        array_map(
                            static function (
                                array $record
                            ): string {

                                return trim(
                                    (string)
                                    (
                                        $record[
                                            'signature'
                                        ] ?? ''
                                    )
                                );
                            },
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
                        (string)
                        $oldWebPath
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


                $oldPhysicalPath =
                    $uploadDirectory .
                    DIRECTORY_SEPARATOR .
                    basename(
                        $oldWebPath
                    );


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
            | Wizard navigation
            |--------------------------------------------------------------------------
            */

            if ($isWizardNavigation) {

                $wizardPages = [

                    1 => 'create.php',

                    2 => 'objectives.php',

                    3 => 'beneficiaries.php',

                    4 => 'financial-year.php',

                    5 => 'officials.php',

                    6 => 'review.php',
                ];


                $destinationPage =
                    $wizardPages[
                        $wizardDestination
                    ] ??
                    'officials.php';


                header(
                    'Location: /pbo-portal/applications/' .
                    $destinationPage .
                    '?id=' .
                    urlencode(
                        (string)
                        $applicationId
                    )
                );

                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | Normal Save & Continue → Review
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
            | Remove newly uploaded files after DB failure
            |--------------------------------------------------------------------------
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
                'The officials could not be saved. Your entered information has been retained on this page.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Shared header
|--------------------------------------------------------------------------
*/

require_once __DIR__ .
    '/../includes/header.php';

?>


<div class="applications-page">

    <section class="page-header">

        <div>

            <span class="section-eyebrow">
                PBO REGISTRATION
            </span>

            <h1>
                Officials
            </h1>

            <p class="subtitle">
                Step 5 of 6 — Enter the organization's principal officials.
                Additional directors may be added where applicable.
            </p>

        </div>

    </section>


    <div class="admin-info">

        <strong>
            <?= e(
                (string)
                $application['pbo_name']
            ) ?>
        </strong>


        <?php if (
            !empty(
                $application[
                    'application_reference'
                ]
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


    <?php

    $wizardCurrentStep = 5;

    require __DIR__ .
        '/../includes/application-wizard.php';

    ?>


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

                <?php foreach (
                    $errors as $error
                ): ?>

                    <li>
                        <?= e($error) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>


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

        <input
            type="hidden"
            name="form_action"
            id="form_action"
            value="save_continue"
        >

        <input
            type="hidden"
            name="wizard_destination"
            id="wizard_destination"
            value=""
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
                        Principal Officials
                    </h2>

                    <p>
                        Chairperson, Treasurer and Secretary are required.
                    </p>

                </div>

            </div>


            <div class="admin-info">

                Complete the required particulars for the
                <strong>Chairperson</strong>,
                <strong>Treasurer</strong> and
                <strong>Secretary</strong>.

                Signatures are
                <strong>optional</strong> and may be uploaded
                where available.

            </div>

        </section>


        <?php

        $principalOfficials = [

            'chairperson' => [
                'number' => '1',
                'title' => 'Chairperson',
                'record' => $chairperson,
            ],

            'treasurer' => [
                'number' => '2',
                'title' => 'Treasurer',
                'record' => $treasurer,
            ],

            'secretary' => [
                'number' => '3',
                'title' => 'Secretary',
                'record' => $secretary,
            ],
        ];

        ?>


        <?php foreach (
            $principalOfficials
            as $formKey => $definition
        ): ?>

            <?php

            $record =
                $definition['record'];

            $existingSignature =
                (string)
                (
                    $record[
                        'existing_signature'
                    ]
                    ??
                    $record[
                        'signature'
                    ]
                    ??
                    ''
                );

            ?>


            <section class="application-section">

                <div class="application-section-heading">

                    <div class="application-section-number">
                        <?= e(
                            $definition[
                                'number'
                            ]
                        ) ?>
                    </div>

                    <div>

                        <h2>
                            <?= e(
                                $definition[
                                    'title'
                                ]
                            ) ?>
                        </h2>

                        <p>
                            Required principal official.
                        </p>

                    </div>

                </div>


                <div class="form-group">

                    <label>
                        Name
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        name="officials[<?= e($formKey) ?>][full_name]"
                        maxlength="255"
                        value="<?= e(
                            $record[
                                'full_name'
                            ]
                        ) ?>"
                        required
                    >

                </div>


                <div class="form-row">

                    <div class="form-group">

                        <label>
                            Nationality
                            <span class="required">*</span>                        </label>

                        <input
                            type="text"
                            name="officials[<?= e($formKey) ?>][nationality]"
                            maxlength="100"
                            value="<?= e(
                                $record[
                                    'nationality'
                                ]
                            ) ?>"
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
                            name="officials[<?= e($formKey) ?>][id_passport_number]"
                            maxlength="100"
                            value="<?= e(
                                $record[
                                    'id_passport_number'
                                ]
                            ) ?>"
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
                            name="officials[<?= e($formKey) ?>][kra_pin]"
                            maxlength="50"
                            value="<?= e(
                                $record[
                                    'kra_pin'
                                ]
                            ) ?>"
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
                            name="officials[<?= e($formKey) ?>][phone_number]"
                            maxlength="50"
                            value="<?= e(
                                $record[
                                    'phone_number'
                                ]
                            ) ?>"
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
                            name="officials[<?= e($formKey) ?>][email]"
                            maxlength="255"
                            value="<?= e(
                                $record[
                                    'email'
                                ]
                            ) ?>"
                            required
                        >

                    </div>


                    <!-- OPTIONAL SIGNATURE -->

                    <div class="form-group">

                        <label>
                            Signature
                            <span
                                style="
                                    margin-left: 5px;
                                    color: var(--text-muted);
                                    font-size: 10px;
                                    font-weight: 600;
                                "
                            >
                                Optional
                            </span>
                        </label>


                        <input
                            type="hidden"
                            name="officials[<?= e($formKey) ?>][existing_signature]"
                            value="<?= e(
                                $existingSignature
                            ) ?>"
                        >


                        <?php if (
                            $existingSignature !== ''
                        ): ?>

                            <div
                                style="
                                    margin-bottom: 10px;
                                    padding: 10px 12px;
                                    background: var(--green-light);
                                    border: 1px solid #c3dfce;
                                    border-radius: var(--radius);
                                    color: var(--green-dark);
                                    font-size: 11px;
                                    font-weight: 700;
                                "
                            >
                                &#10003; Signature on file
                            </div>

                        <?php endif; ?>


                        <input
                            type="file"
                            name="signatures[<?= e($formKey) ?>]"
                            accept=".jpg,.jpeg,.png,.webp,.pdf"
                        >


                        <span class="form-help">

                            <?php if (
                                $existingSignature !== ''
                            ): ?>

                                A signature is already on file.
                                Select another file only to replace it.

                            <?php else: ?>

                                Optional. Upload JPG, PNG, WEBP or PDF
                                where a signature is available.

                            <?php endif; ?>

                        </span>

                    </div>

                </div>

            </section>

        <?php endforeach; ?>


        <!-- ======================================================
             OPTIONAL DIRECTORS
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    +
                </div>

                <div style="flex: 1;">

                    <h2>
                        Additional Directors
                    </h2>

                    <p>
                        Optional — add Director particulars only where applicable.
                    </p>

                </div>


                <span
                    style="
                        padding: 5px 9px;
                        background: var(--soft-bg);
                        border: 1px solid var(--border);
                        border-radius: 20px;
                        color: var(--text-muted);
                        font-size: 10px;
                        font-weight: 800;
                        letter-spacing: .4px;
                        text-transform: uppercase;
                    "
                >
                    Optional
                </span>

            </div>


            <div
                style="
                    padding: 17px 18px;
                    background:
                        linear-gradient(
                            100deg,
                            var(--blue-light),
                            #ffffff
                        );
                    border: 1px solid #d2e0ec;
                    border-radius: var(--radius);
                "
            >

                <div
                    style="
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        gap: 15px;
                        flex-wrap: wrap;
                    "
                >

                    <div>

                        <strong
                            style="
                                display: block;
                                margin-bottom: 3px;
                                color: var(--blue-dark);
                            "
                        >
                            Director Information
                        </strong>

                        <span
                            id="director-summary"
                            style="
                                color: var(--text-secondary);
                                font-size: 11px;
                            "
                        >
                            No additional directors recorded.
                        </span>

                    </div>


                    <button
                        type="button"
                        class="btn btn-secondary"
                        id="toggle-directors"
                        aria-expanded="<?= $directors !== []
                            ? 'true'
                            : 'false' ?>"
                    >
                        <?= $directors !== []
                            ? 'Hide Directors'
                            : 'Add / View Directors' ?>
                    </button>

                </div>

            </div>


            <!-- Collapsible Director area -->

            <div
                id="directors-panel"
                style="
                    margin-top: 18px;
                    <?= $directors === []
                        ? 'display: none;'
                        : '' ?>
                "
            >

                <div
                    class="admin-info"
                    style="margin-bottom: 15px;"
                >
                    Directors are optional. Add only those applicable to
                    this organization. Director signatures are also optional.
                </div>


                <div id="directors-container">

                    <?php foreach (
                        $directors
                        as $index => $director
                    ): ?>

                        <?php

                        $directorSignature =
                            (string)
                            (
                                $director[
                                    'existing_signature'
                                ]
                                ??
                                $director[
                                    'signature'
                                ]
                                ??
                                ''
                            );

                        ?>

                        <article
                            data-director-card
                            class="director-card"
                            style="
                                margin-bottom: 18px;
                                overflow: hidden;
                                background: var(--white);
                                border: 1px solid var(--border);
                                border-radius: var(--radius);
                                box-shadow: var(--shadow-sm);
                            "
                        >

                            <div
                                style="
                                    display: flex;
                                    align-items: center;
                                    justify-content: space-between;
                                    gap: 15px;
                                    padding: 14px 17px;
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
                                        Optional Director
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
                                >
                                    Remove
                                </button>

                            </div>


                            <div style="padding: 20px;">

                                <div class="form-group">

                                    <label>
                                        Name
                                    </label>

                                    <input
                                        type="text"
                                        name="directors[<?= $index ?>][full_name]"
                                        maxlength="255"
                                        value="<?= e(
                                            $director[
                                                'full_name'
                                            ]
                                        ) ?>"
                                        data-director-field
                                        data-field-name="full_name"
                                    >

                                </div>


                                <div class="form-row">

                                    <div class="form-group">

                                        <label>
                                            Nationality
                                        </label>

                                        <input
                                            type="text"
                                            name="directors[<?= $index ?>][nationality]"
                                            maxlength="100"
                                            value="<?= e(
                                                $director[
                                                    'nationality'
                                                ]
                                            ) ?>"
                                            data-director-field
                                            data-field-name="nationality"
                                        >

                                    </div>


                                    <div class="form-group">

                                        <label>
                                            ID / Passport Number
                                        </label>

                                        <input
                                            type="text"
                                            name="directors[<?= $index ?>][id_passport_number]"
                                            maxlength="100"
                                            value="<?= e(
                                                $director[
                                                    'id_passport_number'
                                                ]
                                            ) ?>"
                                            data-director-field
                                            data-field-name="id_passport_number"
                                        >

                                    </div>

                                </div>


                                <div class="form-row">

                                    <div class="form-group">

                                        <label>
                                            KRA PIN
                                        </label>

                                        <input
                                            type="text"
                                            name="directors[<?= $index ?>][kra_pin]"
                                            maxlength="50"
                                            value="<?= e(
                                                $director[
                                                    'kra_pin'
                                                ]
                                            ) ?>"
                                            data-director-field
                                            data-field-name="kra_pin"
                                        >

                                    </div>


                                    <div class="form-group">

                                        <label>
                                            Phone Number
                                        </label>

                                        <input
                                            type="tel"
                                            name="directors[<?= $index ?>][phone_number]"
                                            maxlength="50"
                                            value="<?= e(
                                                $director[
                                                    'phone_number'
                                                ]
                                            ) ?>"
                                            data-director-field
                                            data-field-name="phone_number"
                                        >

                                    </div>

                                </div>


                                <div class="form-row">

                                    <div class="form-group">

                                        <label>
                                            Email
                                        </label>

                                        <input
                                            type="email"
                                            name="directors[<?= $index ?>][email]"
                                            maxlength="255"
                                            value="<?= e(
                                                $director[
                                                    'email'
                                                ]
                                            ) ?>"
                                            data-director-field
                                            data-field-name="email"
                                        >

                                    </div>


                                    <div class="form-group">

                                        <label>
                                            Signature
                                            <span
                                                style="
                                                    margin-left: 5px;
                                                    color: var(--text-muted);
                                                    font-size: 10px;
                                                "
                                            >
                                                Optional
                                            </span>
                                        </label>


                                        <input
                                            type="hidden"
                                            name="directors[<?= $index ?>][existing_signature]"
                                            value="<?= e(
                                                $directorSignature
                                            ) ?>"
                                            data-director-field
                                            data-field-name="existing_signature"
                                        >


                                        <?php if (
                                            $directorSignature !== ''
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
                                                &#10003; Signature on file
                                            </div>

                                        <?php endif; ?>


                                        <input
                                            type="file"
                                            name="director_signatures[<?= $index ?>]"
                                            accept=".jpg,.jpeg,.png,.webp,.pdf"
                                            data-director-signature
                                        >

                                        <span class="form-help">
                                            Optional. JPG, PNG, WEBP or PDF,
                                            maximum 5 MB.
                                        </span>

                                    </div>

                                </div>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>


                <button
                    type="button"
                    class="btn btn-secondary"
                    id="add-director"
                >
                    + Add Director
                </button>


                <span
                    id="director-count"
                    style="
                        margin-left: 10px;
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
                        (string)
                        $applicationId
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
     OPTIONAL DIRECTOR TEMPLATE
     ============================================================== -->

<template id="director-template">

    <article
        data-director-card
        class="director-card"
        style="
            margin-bottom: 18px;
            overflow: hidden;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
        "
    >

        <div
            style="
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 15px;
                padding: 14px 17px;
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
                    Optional Director
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


        <div style="padding: 20px;">

            <div class="form-group">

                <label>
                    Name
                </label>

                <input
                    type="text"
                    data-director-field
                    data-field-name="full_name"
                    maxlength="255"
                >

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label>
                        Nationality
                    </label>

                    <input
                        type="text"
                        data-director-field
                        data-field-name="nationality"
                        maxlength="100"
                    >

                </div>


                <div class="form-group">

                    <label>
                        ID / Passport Number
                    </label>

                    <input
                        type="text"
                        data-director-field
                        data-field-name="id_passport_number"
                        maxlength="100"
                    >

                </div>

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label>
                        KRA PIN
                    </label>

                    <input
                        type="text"
                        data-director-field
                        data-field-name="kra_pin"
                        maxlength="50"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Phone Number
                    </label>

                    <input
                        type="tel"
                        data-director-field
                        data-field-name="phone_number"
                        maxlength="50"
                    >

                </div>

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label>
                        Email
                    </label>

                    <input
                        type="email"
                        data-director-field
                        data-field-name="email"
                        maxlength="255"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Signature
                        <span
                            style="
                                margin-left: 5px;
                                color: var(--text-muted);
                                font-size: 10px;
                            "
                        >
                            Optional
                        </span>
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
                    >

                    <span class="form-help">
                        Optional. JPG, PNG, WEBP or PDF,
                        maximum 5 MB.
                    </span>

                </div>

            </div>

        </div>

    </article>

</template>


<script>
(function () {

    'use strict';


    const form =
        document.getElementById(
            'officials-form'
        );

    const directorsPanel =
        document.getElementById(
            'directors-panel'
        );

    const directorsContainer =
        document.getElementById(
            'directors-container'
        );

    const directorTemplate =
        document.getElementById(
            'director-template'
        );

    const toggleDirectorsButton =
        document.getElementById(
            'toggle-directors'
        );

    const addDirectorButton =
        document.getElementById(
            'add-director'
        );

    const directorCount =
        document.getElementById(
            'director-count'
        );

    const directorSummary =
        document.getElementById(
            'director-summary'
        );

    const saveButton =
        document.getElementById(
            'save-officials-button'
        );

    const MAX_DIRECTORS = 30;


    /*
    |--------------------------------------------------------------------------
    | Reindex optional Directors
    |--------------------------------------------------------------------------
    */

    function reindexDirectors() {

        const cards =
            directorsContainer.querySelectorAll(
                '[data-director-card]'
            );
                    cards.forEach(
            function (card, index) {

                /*
                |--------------------------------------------------------------------------
                | Director title
                |--------------------------------------------------------------------------
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
                |--------------------------------------------------------------------------
                | Reindex text/hidden fields
                |--------------------------------------------------------------------------
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
                |--------------------------------------------------------------------------
                | Reindex optional signature
                |--------------------------------------------------------------------------
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
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Director count
        |--------------------------------------------------------------------------
        */

        const count =
            cards.length;


        directorCount.textContent =
            count === 0
                ? 'No directors added'
                : count +
                  ' director' +
                  (
                      count === 1
                          ? ''
                          : 's'
                  ) +
                  ' added';


        directorSummary.textContent =
            count === 0
                ? 'No additional directors recorded.'
                : count +
                  ' optional director' +
                  (
                      count === 1
                          ? ''
                          : 's'
                  ) +
                  ' currently recorded.';


        /*
        |--------------------------------------------------------------------------
        | Add limit
        |--------------------------------------------------------------------------
        */

        addDirectorButton.disabled =
            count >=
            MAX_DIRECTORS;
    }


    /*
    |--------------------------------------------------------------------------
    | Expand / collapse Directors
    |--------------------------------------------------------------------------
    */

    function setDirectorsExpanded(
        expanded
    ) {

        directorsPanel.style.display =
            expanded
                ? ''
                : 'none';


        toggleDirectorsButton.setAttribute(
            'aria-expanded',
            expanded
                ? 'true'
                : 'false'
        );


        toggleDirectorsButton.textContent =
            expanded
                ? 'Hide Directors'
                : 'Add / View Directors';
    }


    toggleDirectorsButton.addEventListener(
        'click',
        function () {

            const expanded =
                this.getAttribute(
                    'aria-expanded'
                ) === 'true';


            setDirectorsExpanded(
                !expanded
            );
        }
    );


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
                MAX_DIRECTORS
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
            |--------------------------------------------------------------------------
            | Ensure panel remains open
            |--------------------------------------------------------------------------
            */

            setDirectorsExpanded(
                true
            );


            /*
            |--------------------------------------------------------------------------
            | Focus new Director
            |--------------------------------------------------------------------------
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
    |
    | Unlike the old implementation, there is NO minimum Director count.
    |
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


            card.remove();

            reindexDirectors();


            /*
            |--------------------------------------------------------------------------
            | Collapse automatically if none remain
            |--------------------------------------------------------------------------
            */

            const remaining =
                directorsContainer.querySelectorAll(
                    '[data-director-card]'
                ).length;


            if (remaining === 0) {

                setDirectorsExpanded(
                    false
                );
            }
        }
    );


    /*
    |--------------------------------------------------------------------------
    | Client-side signature validation
    |--------------------------------------------------------------------------
    |
    | Signature itself is optional.
    |
    | If a file IS selected, validate its size/type for user feedback.
    | PHP remains authoritative.
    |
    */

    form.addEventListener(
        'change',
        function (event) {

            const input =
                event.target;


            if (
                !(
                    input instanceof
                    HTMLInputElement
                ) ||
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
            |--------------------------------------------------------------------------
            | Some browsers provide an empty MIME type.
            | Server-side finfo performs the final check.
            |--------------------------------------------------------------------------
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
    | Normal Save & Continue
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        function (event) {

            reindexDirectors();


            /*
            |--------------------------------------------------------------------------
            | Wizard navigation deliberately bypasses normal browser validation.
            |--------------------------------------------------------------------------
            */

            const formAction =
                document.getElementById(
                    'form_action'
                );


            if (
                formAction &&
                formAction.value ===
                    'wizard_navigation'
            ) {

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Only principal officials have HTML required fields.
            |
            | Directors have no HTML required attributes because Directors
            | themselves are optional. PHP validates a Director only when
            | one has actually been entered.
            |--------------------------------------------------------------------------
            */

            if (!form.checkValidity()) {

                event.preventDefault();

                form.reportValidity();

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate submission
            |--------------------------------------------------------------------------
            */

            if (saveButton) {

                saveButton.disabled =
                    true;

                saveButton.innerHTML =
                    'Saving...';
            }
        }
    );


    /*
    |--------------------------------------------------------------------------
    | Wizard navigation
    |--------------------------------------------------------------------------
    |
    | Save partial Officials data before moving to another wizard page.
    |
    */

    const wizardLinks =
        document.querySelectorAll(
            '[data-wizard-link="true"]'
        );


    wizardLinks.forEach(
        function (link) {

            link.addEventListener(
                'click',
                function (event) {

                    const destination =
                        this.getAttribute(
                            'data-wizard-step'
                        );


                    /*
                    |--------------------------------------------------------------------------
                    | Already on Officials
                    |--------------------------------------------------------------------------
                    */

                    if (destination === '5') {

                        return;
                    }


                    event.preventDefault();


                    const formAction =
                        document.getElementById(
                            'form_action'
                        );


                    const wizardDestination =
                        document.getElementById(
                            'wizard_destination'
                        );


                    if (
                        !formAction ||
                        !wizardDestination
                    ) {

                        return;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Ensure dynamically-added Director fields have correct names.
                    |--------------------------------------------------------------------------
                    */

                    reindexDirectors();


                    formAction.value =
                        'wizard_navigation';


                    wizardDestination.value =
                        destination;


                    /*
                    |--------------------------------------------------------------------------
                    | Partial draft navigation bypasses browser required fields.
                    |--------------------------------------------------------------------------
                    */

                    form.noValidate =
                        true;


                    form.submit();
                }
            );
        }
    );


    /*
    |--------------------------------------------------------------------------
    | Initial state
    |--------------------------------------------------------------------------
    */

    reindexDirectors();


    const initialDirectorCount =
        directorsContainer.querySelectorAll(
            '[data-director-card]'
        ).length;


    setDirectorsExpanded(
        initialDirectorCount > 0
    );

})();
</script>


<?php

/*
|--------------------------------------------------------------------------
| Shared footer
|--------------------------------------------------------------------------
*/

require_once __DIR__ .
    '/../includes/footer.php';

?>