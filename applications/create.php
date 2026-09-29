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

$pageTitle = 'PBO Application';
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

$userId =
    (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {

    header(
        'Location: /pbo-portal/login.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {

    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    (string) $_SESSION['csrf_token'];


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


function old(
    array $form,
    string $field
): string {

    return e(
        (string)
        ($form[$field] ?? '')
    );
}


/*
|--------------------------------------------------------------------------
| Generate annual PBO registration reference
|--------------------------------------------------------------------------
|
| Examples:
|
| PBO.REG.2026.0001
| PBO.REG.2026.0002
|
| When the calendar year becomes 2027:
|
| PBO.REG.2027.0001
|
| Must be called inside the application's database transaction.
|
*/

function generateApplicationReference(
    PDO $connection
): string {

    $year =
        (int) date('Y');


    /*
    | Create this year's counter if it does not exist.
    */

    $statement =
        $connection->prepare(
            "
            INSERT INTO application_reference_sequences
            (
                reference_year,
                last_number
            )
            VALUES
            (
                :reference_year,
                0
            )
            ON DUPLICATE KEY UPDATE
                reference_year =
                    VALUES(reference_year)
            "
        );


    $statement->execute([
        ':reference_year' => $year,
    ]);


    /*
    | Lock this year's sequence.
    */

    $statement =
        $connection->prepare(
            "
            SELECT
                last_number
            FROM application_reference_sequences
            WHERE reference_year =
                :reference_year
            FOR UPDATE
            "
        );


    $statement->execute([
        ':reference_year' => $year,
    ]);


    $lastNumber =
        (int) $statement->fetchColumn();


    $nextNumber =
        $lastNumber + 1;


    /*
    | Advance sequence.
    */

    $statement =
        $connection->prepare(
            "
            UPDATE application_reference_sequences
            SET
                last_number =
                    :last_number
            WHERE reference_year =
                :reference_year
            "
        );


    $statement->execute([

        ':last_number'
            => $nextNumber,

        ':reference_year'
            => $year,
    ]);


    return
        'PBO.REG.' .
        $year .
        '.' .
        str_pad(
            (string) $nextNumber,
            4,
            '0',
            STR_PAD_LEFT
        );
}


/*
|--------------------------------------------------------------------------
| Form model
|--------------------------------------------------------------------------
*/

$form = [

    /*
    | Core application
    */

    'pbo_name' => '',

    'pbo_registration_number' => '',

    'registration_type' => '',


    /*
    | Organization address
    */

    'county' => '',

    'sub_county' => '',

    'physical_address' => '',

    'postal_address' => '',

    'postal_code' => '',

    'phone_number' => '',

    'alternative_phone_number' => '',

    'email' => '',


    /*
    | International organization
    */

    'parent_organization_name' => '',

    'country_of_origin' => '',

    'registration_number' => '',

    'parent_physical_address' => '',

    'parent_postal_address' => '',

    'parent_postal_code' => '',

    'parent_phone_number' => '',

    'parent_email' => '',

    'contact_person_name' => '',

    'contact_person_phone' => '',

    'contact_person_email' => '',
];


$errors = [];


/*
|--------------------------------------------------------------------------
| Determine CREATE / EDIT mode
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


$isEditMode =
    $applicationId !== false &&
    $applicationId !== null &&
    (int) $applicationId > 0;


if ($isEditMode) {

    $applicationId =
        (int) $applicationId;
}


$application = null;


/*
|--------------------------------------------------------------------------
| Load existing application
|--------------------------------------------------------------------------
|
| This is what prevents saved Organization information from disappearing
| when staff return to this page.
|
*/

if ($isEditMode) {

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
                created_by,
                updated_by,
                created_at,
                updated_at
            FROM pbo_applications
            WHERE id = :application_id
              AND created_by = :user_id
            LIMIT 1
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

        http_response_code(404);

        exit(
            'Application not found.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Submitted records cannot be edited through the draft wizard
    |--------------------------------------------------------------------------
    */

    if (
        $application['status'] !==
        'draft'
    ) {

        header(
            'Location: /pbo-portal/'
        );

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Master values
    |--------------------------------------------------------------------------
    */

    $form['pbo_name'] =
        (string)
        ($application['pbo_name'] ?? '');


    $form['pbo_registration_number'] =
        (string)
        ($application[
            'pbo_registration_number'
        ] ?? '');


    $form['registration_type'] =
        (string)
        ($application[
            'registration_type'
        ] ?? '');


    /*
    |--------------------------------------------------------------------------
    | Address
    |--------------------------------------------------------------------------
    */

    $statement =
        $connection->prepare(
            "
            SELECT
                county,
                sub_county,
                physical_address,
                postal_address,
                postal_code,
                phone_number,
                alternative_phone_number,
                email
            FROM application_addresses
            WHERE application_id =
                :application_id
            LIMIT 1
            "
        );


    $statement->execute([
        ':application_id'
            => $applicationId,
    ]);


    $address =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );


    if ($address) {

        foreach (
            [
                'county',
                'sub_county',
                'physical_address',
                'postal_address',
                'postal_code',
                'phone_number',
                'alternative_phone_number',
                'email',
            ]
            as $field
        ) {

            $form[$field] =
                (string)
                ($address[$field] ?? '');
        }
    }


    /*
    |--------------------------------------------------------------------------
    | International details
    |--------------------------------------------------------------------------
    */

    $statement =
        $connection->prepare(
            "
            SELECT
                parent_organization_name,
                country_of_origin,
                registration_number,
                parent_physical_address,
                parent_postal_address,
                parent_postal_code,
                parent_phone_number,
                parent_email,
                contact_person_name,
                contact_person_phone,
                contact_person_email
            FROM application_international_details
            WHERE application_id =
                :application_id
            LIMIT 1
            "
        );


    $statement->execute([
        ':application_id'
            => $applicationId,
    ]);


    $internationalDetails =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );


    if ($internationalDetails) {

        foreach (
            [
                'parent_organization_name',
                'country_of_origin',
                'registration_number',
                'parent_physical_address',
                'parent_postal_address',
                'parent_postal_code',
                'parent_phone_number',
                'parent_email',
                'contact_person_name',
                'contact_person_phone',
                'contact_person_email',
            ]
            as $field
        ) {

            $form[$field] =
                (string)
                ($internationalDetails[$field] ?? '');
        }
    }
}


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] ===
    'POST'
) {


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
    | Populate submitted values
    |--------------------------------------------------------------------------
    |
    | POST values replace the database values in $form. If validation fails,
    | the user's current entries therefore remain on screen.
    |
    */

    foreach (
        array_keys($form)
        as $field
    ) {

        $form[$field] =
            trim(
                (string)
                ($_POST[$field] ?? '')
            );
    }



    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if (
        $form['pbo_name'] === ''
    ) {

        $errors[] =
            'Name of PBO is required.';
    }


    if (
        mb_strlen(
            $form['pbo_name']
        ) > 255
    ) {

        $errors[] =
            'Name of PBO cannot exceed 255 characters.';
    }


    $allowedOrganizationTypes = [

        'national',

        'international',

    ];


    if (
        !in_array(
            $form['registration_type'],
            $allowedOrganizationTypes,
            true
        )
    ) {

        $errors[] =
            'Please select a valid Type of Organization.';
    }


    /*
    |--------------------------------------------------------------------------
    | Address validation
    |--------------------------------------------------------------------------
    */

    $requiredAddressFields = [

        'county'
            => 'County',

        'sub_county'
            => 'Sub-County',

        'physical_address'
            => 'Physical Address',

        'postal_address'
            => 'Postal Address',

        'postal_code'
            => 'Postal Code',

        'phone_number'
            => 'Organization Phone Number',

        'email'
            => 'Email of the Organization',
    ];


    foreach (
        $requiredAddressFields
        as $field => $label
    ) {

        if (
            $form[$field] === ''
        ) {

            $errors[] =
                $label .
                ' is required.';
        }
    }


    if (
        $form['email'] !== '' &&
        !filter_var(
            $form['email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $errors[] =
            'Enter a valid email address for the organization.';
    }


    /*
    |--------------------------------------------------------------------------
    | International validation
    |--------------------------------------------------------------------------
    */

    if (
        $form['registration_type'] ===
        'international'
    ) {

        $requiredInternationalFields = [

            'parent_organization_name'
                => 'Name of Parent Organization',

            'country_of_origin'
                => 'Country where the Parent Organization is registered',

            'registration_number'
                => 'Public Benefit / Charity Registration Number',

            'parent_physical_address'
                => 'Parent Organization Physical Address',

            'parent_postal_address'
                => 'Parent Organization Postal Address',

            'parent_postal_code'
                => 'Parent Organization Postal Code',

            'parent_phone_number'
                => 'Parent Organization Phone Number',

            'parent_email'
                => 'Email of Parent Organization',

            'contact_person_name'
                => 'Contact Person Name',

            'contact_person_phone'
                => 'Contact Person Phone Number',

            'contact_person_email'
                => 'Contact Person Email Address',
        ];


        foreach (
            $requiredInternationalFields
            as $field => $label
        ) {

            if (
                $form[$field] === ''
            ) {

                $errors[] =
                    $label .
                    ' is required.';
            }
        }


        if (
            $form['parent_email'] !== '' &&
            !filter_var(
                $form['parent_email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $errors[] =
                'Enter a valid email address for the Parent Organization.';
        }


        if (
            $form['contact_person_email'] !== '' &&
            !filter_var(
                $form['contact_person_email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $errors[] =
                'Enter a valid email address for the Parent Organization contact person.';
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
            | CREATE MODE
            |--------------------------------------------------------------------------
            */

            if (!$isEditMode) {

                /*
                | Generate the public reference first.
                |
                | Because we're inside the same transaction, the annual
                | sequence allocation and application creation are atomic.
                */

                $applicationReference =
                    generateApplicationReference(
                        $connection
                    );


                $statement =
                    $connection->prepare(
                        "
                        INSERT INTO pbo_applications
                        (
                            application_reference,
                            pbo_name,
                            pbo_registration_number,
                            registration_type,
                            status,
                            current_step,
                            created_by,
                            updated_by
                        )
                        VALUES
                        (
                            :application_reference,
                            :pbo_name,
                            :pbo_registration_number,
                            :registration_type,
                            'draft',
                            2,
                            :created_by,
                            :updated_by
                        )
                        "
                    );


                $statement->execute([

                    ':application_reference'
                        => $applicationReference,

                    ':pbo_name'
                        => $form['pbo_name'],

                    ':pbo_registration_number'
                        => $form['pbo_registration_number'] !== ''
                            ? $form['pbo_registration_number']
                            : null,

                    ':registration_type'
                        => $form['registration_type'],

                    ':created_by'
                        => $userId,

                    ':updated_by'
                        => $userId,
                ]);


                $applicationId =
                    (int)
                    $connection->lastInsertId();


                $isEditMode =
                    true;


                /*
                |--------------------------------------------------------------------------
                | Address
                |--------------------------------------------------------------------------
                */

                $statement =
                    $connection->prepare(
                        "
                        INSERT INTO application_addresses
                        (
                            application_id,
                            county,
                            sub_county,
                            physical_address,
                            phone_number,
                            alternative_phone_number,
                            email,
                            postal_address,
                            postal_code
                        )
                        VALUES
                        (
                            :application_id,
                            :county,
                            :sub_county,
                            :physical_address,
                            :phone_number,
                            :alternative_phone_number,
                            :email,
                            :postal_address,
                            :postal_code
                        )
                        "
                    );


                $statement->execute([

                    ':application_id'
                    => $applicationId,

                    ':county'
                        => $form['county'],

                    ':sub_county'
                        => $form['sub_county'],

                    ':physical_address'
                        => $form['physical_address'],

                    ':phone_number'
                        => $form['phone_number'],

                    ':alternative_phone_number'
                        => $form['alternative_phone_number'] !== ''
                            ? $form['alternative_phone_number']
                            : null,

                    ':email'
                        => $form['email'],

                    ':postal_address'
                        => $form['postal_address'],

                    ':postal_code'
                        => $form['postal_code'],
                ]);


                /*
                |--------------------------------------------------------------------------
                | International details — CREATE
                |--------------------------------------------------------------------------
                */

                if (
                    $form['registration_type'] ===
                    'international'
                ) {

                    $statement =
                        $connection->prepare(
                            "
                            INSERT INTO application_international_details
                            (
                                application_id,
                                parent_organization_name,
                                country_of_origin,
                                registration_number,
                                parent_physical_address,
                                parent_postal_address,
                                parent_postal_code,
                                parent_phone_number,
                                parent_email,
                                contact_person_name,
                                contact_person_phone,
                                contact_person_email
                            )
                            VALUES
                            (
                                :application_id,
                                :parent_organization_name,
                                :country_of_origin,
                                :registration_number,
                                :parent_physical_address,
                                :parent_postal_address,
                                :parent_postal_code,
                                :parent_phone_number,
                                :parent_email,
                                :contact_person_name,
                                :contact_person_phone,
                                :contact_person_email
                            )
                            "
                        );


                    $statement->execute([

                        ':application_id'
                            => $applicationId,

                        ':parent_organization_name'
                            => $form[
                                'parent_organization_name'
                            ],

                        ':country_of_origin'
                            => $form[
                                'country_of_origin'
                            ],

                        ':registration_number'
                            => $form[
                                'registration_number'
                            ],

                        ':parent_physical_address'
                            => $form[
                                'parent_physical_address'
                            ],

                        ':parent_postal_address'
                            => $form[
                                'parent_postal_address'
                            ],

                        ':parent_postal_code'
                            => $form[
                                'parent_postal_code'
                            ],

                        ':parent_phone_number'
                            => $form[
                                'parent_phone_number'
                            ],

                        ':parent_email'
                            => $form[
                                'parent_email'
                            ],

                        ':contact_person_name'
                            => $form[
                                'contact_person_name'
                            ],

                        ':contact_person_phone'
                            => $form[
                                'contact_person_phone'
                            ],

                        ':contact_person_email'
                            => $form[
                                'contact_person_email'
                            ],
                    ]);
                }


                /*
                |--------------------------------------------------------------------------
                | Audit — application created
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
                            'APPLICATION_CREATED',
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
                        => 'Organization details completed and application draft created with reference ' .
                           $applicationReference .
                           '.',
                ]);


            /*
            |--------------------------------------------------------------------------
            | EDIT MODE
            |--------------------------------------------------------------------------
            */

            } else {


                /*
                |--------------------------------------------------------------------------
                | Lock existing application
                |--------------------------------------------------------------------------
                */

                $statement =
                    $connection->prepare(
                        "
                        SELECT
                            id,
                            application_reference,
                            status,
                            current_step
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
                        'Application could not be found or locked.'
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
                | Update master application
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                | application_reference is NOT regenerated here.
                |
                | PBO.REG.2026.0001 remains the same for the lifetime
                | of this application.
                |
                */

                $statement =
                    $connection->prepare(
                        "
                        UPDATE pbo_applications
                        SET
                            pbo_name =
                                :pbo_name,

                            pbo_registration_number =
                                :pbo_registration_number,

                            registration_type =
                                :registration_type,

                            current_step =
                                CASE
                                    WHEN current_step < 2
                                        THEN 2
                                    ELSE current_step
                                END,

                            updated_by =
                                :updated_by

                        WHERE id =
                            :application_id
                        "
                    );


                $statement->execute([

                    ':pbo_name'
                        => $form['pbo_name'],

                    ':pbo_registration_number'
                        => $form['pbo_registration_number'] !== ''
                            ? $form['pbo_registration_number']
                            : null,

                    ':registration_type'
                        => $form['registration_type'],

                    ':updated_by'
                        => $userId,

                    ':application_id'
                        => $applicationId,
                ]);


                /*
                |--------------------------------------------------------------------------
                | Address UPSERT
                |--------------------------------------------------------------------------
                |
                | application_id is UNIQUE in application_addresses.
                |
                | This means revisiting Organization updates the existing
                | address rather than creating another address row.
                |
                */

                $statement =
                    $connection->prepare(
                        "
                        INSERT INTO application_addresses
                        (
                            application_id,
                            county,
                            sub_county,
                            physical_address,
                            phone_number,
                            alternative_phone_number,
                            email,
                            postal_address,
                            postal_code
                        )
                        VALUES
                        (
                            :application_id,
                            :county,
                            :sub_county,
                            :physical_address,
                            :phone_number,
                            :alternative_phone_number,
                            :email,
                            :postal_address,
                            :postal_code
                        )
                        ON DUPLICATE KEY UPDATE
                            county =
                                VALUES(county),

                            sub_county =
                                VALUES(sub_county),

                            physical_address =
                                VALUES(physical_address),

                            phone_number =
                                VALUES(phone_number),

                            alternative_phone_number =
                                VALUES(alternative_phone_number),

                            email =
                                VALUES(email),

                            postal_address =
                                VALUES(postal_address),

                            postal_code =
                                VALUES(postal_code)
                        "
                    );


                $statement->execute([

                    ':application_id'
                        => $applicationId,

                    ':county'
                        => $form['county'],

                    ':sub_county'
                        => $form['sub_county'],

                    ':physical_address'
                        => $form['physical_address'],

                    ':phone_number'
                        => $form['phone_number'],

                    ':alternative_phone_number'
                        => $form['alternative_phone_number'] !== ''
                            ? $form['alternative_phone_number']
                            : null,

                    ':email'
                        => $form['email'],

                    ':postal_address'
                        => $form['postal_address'],

                    ':postal_code'
                        => $form['postal_code'],
                ]);


                /*
                |--------------------------------------------------------------------------
                | International details
                |--------------------------------------------------------------------------
                */

                if (
                    $form['registration_type'] ===
                    'international'
                ) {

                    /*
                    | Insert if missing.
                    | Update if it already exists.
                    */

                    $statement =
                        $connection->prepare(
                            "
                            INSERT INTO application_international_details
                            (
                                application_id,
                                parent_organization_name,
                                country_of_origin,
                                registration_number,
                                parent_physical_address,
                                parent_postal_address,
                                parent_postal_code,
                                parent_phone_number,
                                parent_email,
                                contact_person_name,
                                contact_person_phone,
                                contact_person_email
                            )
                            VALUES
                            (
                                :application_id,
                                :parent_organization_name,
                                :country_of_origin,
                                :registration_number,
                                :parent_physical_address,
                                :parent_postal_address,
                                :parent_postal_code,
                                :parent_phone_number,
                                :parent_email,
                                :contact_person_name,
                                :contact_person_phone,
                                :contact_person_email
                            )
                            ON DUPLICATE KEY UPDATE
                                parent_organization_name =
                                    VALUES(parent_organization_name),

                                country_of_origin =
                                    VALUES(country_of_origin),

                                registration_number =
                                    VALUES(registration_number),

                                parent_physical_address =
                                    VALUES(parent_physical_address),

                                parent_postal_address =
                                    VALUES(parent_postal_address),

                                parent_postal_code =
                                    VALUES(parent_postal_code),

                                parent_phone_number =
                                    VALUES(parent_phone_number),

                                parent_email =
                                    VALUES(parent_email),

                                contact_person_name =
                                    VALUES(contact_person_name),

                                contact_person_phone =
                                    VALUES(contact_person_phone),

                                contact_person_email =
                                    VALUES(contact_person_email)
                            "
                        );


                    $statement->execute([

                        ':application_id'
                            => $applicationId,

                        ':parent_organization_name'
                            => $form[
                                'parent_organization_name'
                            ],

                        ':country_of_origin'
                            => $form[
                                'country_of_origin'
                            ],

                        ':registration_number'
                            => $form[
                                'registration_number'
                            ],

                        ':parent_physical_address'
                            => $form[
                                'parent_physical_address'
                            ],

                        ':parent_postal_address'
                            => $form[
                                'parent_postal_address'
                            ],

                        ':parent_postal_code'
                            => $form[
                                'parent_postal_code'
                            ],

                        ':parent_phone_number'
                            => $form[
                                'parent_phone_number'
                            ],

                        ':parent_email'
                            => $form[
                                'parent_email'
                            ],

                        ':contact_person_name'
                            => $form[
                                'contact_person_name'
                            ],

                        ':contact_person_phone'
                            => $form[
                                'contact_person_phone'
                            ],

                        ':contact_person_email'
                            => $form[
                                'contact_person_email'
                            ],
                    ]);


                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Organization is no longer International
                    |--------------------------------------------------------------------------
                    |
                    | If staff previously selected International, saved the
                    | 11 parent-organization fields, and later changed the
                    | organization to National ,
                    | those obsolete international details must not remain.
                    |
                    */

                    $statement =
                        $connection->prepare(
                            "
                            DELETE FROM
                                application_international_details
                            WHERE application_id =
                                :application_id
                            "
                        );


                    $statement->execute([
                        ':application_id'
                            => $applicationId,
                    ]);
                }


                /*
                |--------------------------------------------------------------------------
                | Audit — Organization updated
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
                            'ORGANIZATION_DETAILS_UPDATED',
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
                        => 'Organization particulars, address and applicable international registration details updated.',
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            $connection->commit();


           
    

            /*
            |--------------------------------------------------------------------------
            | Save & Continue → Objectives
            |--------------------------------------------------------------------------
            */

            header(
                'Location: /pbo-portal/applications/objectives.php?id=' .
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


            error_log(
                'PBO organization save failed: ' .
                $exception->getMessage()
            );


            $errors[] =
                'The organization information could not be saved. No changes were committed. Please try again.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Reload application metadata after POST failure / existing draft
|--------------------------------------------------------------------------
*/

if ($isEditMode) {

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
                current_step
            FROM pbo_applications
            WHERE id = :application_id
              AND created_by = :user_id
            LIMIT 1
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
}


/*
|--------------------------------------------------------------------------
| Success flash
|--------------------------------------------------------------------------
*/

$successMessage = '';

if (
    !empty(
        $_SESSION['flash_success']
    )
) {

    $successMessage =
        (string)
        $_SESSION['flash_success'];


    unset(
        $_SESSION['flash_success']
    );
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
    <?= $isEditMode
        ? 'Organization Details'
        : 'New Application' ?>
</h1>

            <p class="subtitle">

    <?php if ($isEditMode): ?>

        Update the organization's particulars, address and
        applicable registration information.

    <?php else: ?>

        Step 1 of 6 — Organization particulars, address and
        registration status.

    <?php endif; ?>

</p>

        </div>

        <a
            href="/pbo-portal/"
            class="btn btn-secondary"
        >
            Back to Home
        </a>

    </section>
    <?php if (
    $isEditMode &&
    !empty(
        $application['application_reference']
    )
): ?>

    <div
        class="admin-info"
        style="margin-bottom: 18px;"
    >

        Application Reference:

        <strong>
            <?= e(
                (string)
                $application['application_reference']
            ) ?>
        </strong>

    </div>

<?php endif; ?>


<?php if ($successMessage !== ''): ?>

    <div
        class="alert alert-success"
        style="margin-bottom: 18px;"
    >

        <strong>
            Saved successfully.
        </strong>

        <?= e($successMessage) ?>

    </div>

<?php endif; ?>


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

            <span style="color: var(--blue);">
                1. Organization
            </span>

            <span>
                2. Objectives
            </span>

            <span>
                3. Beneficiaries
            </span>

            <span>
                4. Financial Year
            </span>

            <span>
                5. Officials
            </span>

            <span>
                6. Review
            </span>

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
                    width: 16.6667%;
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
         ERRORS
         ========================================================== -->

    <?php if ($errors !== []): ?>

        <div class="alert alert-error">

            <strong>
                Please correct the following information:
            </strong>

            <ul style="margin: 8px 0 0; padding-left: 20px;">

                <?php foreach ($errors as $error): ?>

                    <li>
                        <?= e($error) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>


    <!-- ==========================================================
         APPLICATION FORM
         ========================================================== -->

    <form
        method="post"
        action=""
        class="application-form"
        id="pbo-application-form"
        novalidate
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >
        <?php if ($isEditMode): ?>

    <input
        type="hidden"
        name="application_id"
        value="<?= (int) $applicationId ?>"
    >

<?php endif; ?>


        <!-- ======================================================
             SECTION 1 — PARTICULARS
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    1
                </div>

                <div>

                    <h2>
                        Particulars of the Proposed Organization
                    </h2>

                    <p>
                        Enter the organization's identity and select its
                        organization type.
                    </p>

                </div>

            </div>


            <div class="form-group">

                <label for="pbo_name">
                    Name of PBO
                    <span class="required">*</span>
                </label>

                <input
                    type="text"
                    id="pbo_name"
                    name="pbo_name"
                    maxlength="255"
                    value="<?= old($form, 'pbo_name') ?>"
                    autocomplete="organization"
                    required
                >

            </div>


            <div class="form-group">

                <label for="pbo_registration_number">
                    PBO Registration Number
                </label>

                <input
                    type="text"
                    id="pbo_registration_number"
                    name="pbo_registration_number"
                    maxlength="100"
                    value="<?= old($form, 'pbo_registration_number') ?>"
                >

                <span class="form-help">
                    Enter the registration number where one has already
                    been assigned.
                </span>

            </div>


            <div class="form-group">

                <label>
                    Type of Organization
                    <span class="required">*</span>
                </label>

                <div class="radio-group">

                    <label class="radio-option">
                        <input
                            type="radio"
                            name="registration_type"
                            value="national"
                            <?= $form['registration_type'] === 'national'
                                ? 'checked'
                                : '' ?>
                            required
                        >
                        <span>National</span>
                    </label>

                    <label class="radio-option">
                        <input
                            type="radio"
                            name="registration_type"
                            value="international"
                            <?= $form['registration_type'] === 'international'
                                ? 'checked'
                                : '' ?>
                            required
                        >
                        <span>International</span>
                    </label>

                   

                </div>

                <span class="form-help">
                    Select the category under which the organization is being
                    recorded.
                </span>

            </div>

        </section>


        <!-- ======================================================
             SECTION 2 — ADDRESS OF THE PROPOSED PBO
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    2
                </div>

                <div>

                    <h2>
                        Address of the Proposed Public Benefit Organization
                    </h2>

                    <p>
                        Enter the organization's physical, postal and contact
                        information.
                    </p>

                </div>

            </div>


            <!-- County / Sub-County -->

            <div class="form-row">

                <div class="form-group">

                    <label for="county">
                        County
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="county"
                        name="county"
                        maxlength="100"
                        value="<?= old($form, 'county') ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="sub_county">
                        Sub-County
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="sub_county"
                        name="sub_county"
                        maxlength="100"
                        value="<?= old($form, 'sub_county') ?>"
                        required
                    >

                </div>

            </div>


            <!-- Physical Address -->

            <div class="form-group">

                <label for="physical_address">
                    Physical Address
                    <span class="required">*</span>
                </label>

                <input
                    type="text"
                    id="physical_address"
                    name="physical_address"
                    maxlength="255"
                    value="<?= old($form, 'physical_address') ?>"
                    required
                >

            </div>


            <!-- Postal Address / Postal Code -->

            <div class="form-row">

                <div class="form-group">

                    <label for="postal_address">
                        Postal Address
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="postal_address"
                        name="postal_address"
                        maxlength="100"
                        value="<?= old($form, 'postal_address') ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="postal_code">
                        Postal Code
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="postal_code"
                        name="postal_code"
                        maxlength="30"
                        value="<?= old($form, 'postal_code') ?>"
                        required
                    >

                </div>

            </div>


            <!-- Phone Numbers -->

            <div class="form-row">

                <div class="form-group">

                    <label for="phone_number">
                        Organization Phone Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="tel"
                        id="phone_number"
                        name="phone_number"
                        maxlength="50"
                        value="<?= old($form, 'phone_number') ?>"
                        autocomplete="tel"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="alternative_phone_number">
                        Alternative Phone Number
                    </label>

                    <input
                        type="tel"
                        id="alternative_phone_number"
                        name="alternative_phone_number"
                        maxlength="50"
                        value="<?= old(
                            $form,
                            'alternative_phone_number'
                        ) ?>"
                    >

                    <span class="form-help">
                        Optional
                    </span>

                </div>

            </div>


            <!-- Email -->

            <div class="form-group">

                <label for="email">
                    Email of the Organization
                    <span class="required">*</span>
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="255"
                    value="<?= old($form, 'email') ?>"
                    autocomplete="email"
                    required
                >

            </div>

        </section>


        <!-- ======================================================
             SECTION 3 — INTERNATIONAL REGISTRATION STATUS
             ======================================================
             
             This section is displayed only when the applicant selects
             International as the Type of Organization.
             
             ====================================================== -->

        <section
            class="application-section"
            id="international-section"
            <?= $form['registration_type'] === 'international'
                ? ''
                : 'hidden' ?>
        >

            <div class="application-section-heading">

                <div class="application-section-number">
                    3
                </div>

                <div>

                    <h2>
                        Registration Status
                    </h2>

                    <p>
                        International Public Benefit Organizations only.
                        Enter the particulars of the parent organization.
                    </p>

                </div>

            </div>


            <div class="admin-info">
                The information in this section is required when
                <strong>International</strong> is selected as the Type of
                Organization.
            </div>


            <!-- ==================================================
                 Parent Organization Identity
                 ================================================== -->

            <div class="form-group">

                <label for="parent_organization_name">
                    Name of Parent Organization
                    <span class="required">*</span>
                </label>

                <input
                    type="text"
                    id="parent_organization_name"
                    name="parent_organization_name"
                    maxlength="255"
                    value="<?= old(
                        $form,
                        'parent_organization_name'
                    ) ?>"
                    data-international-required="true"
                >

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="country_of_origin">
                        Country in which Parent Organization is Registered
                        as a Public Benefit Organization / Charity
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="country_of_origin"
                        name="country_of_origin"
                        maxlength="100"
                        value="<?= old(
                            $form,
                            'country_of_origin'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>


                <div class="form-group">

                    <label for="registration_number">
                        Public Benefit / Charity Registration Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="registration_number"
                        name="registration_number"
                        maxlength="100"
                        value="<?= old(
                            $form,
                            'registration_number'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>

            </div>


            <!-- ==================================================
                 Parent Organization Contact Details
                 ================================================== -->

            <div
                style="
                    margin: 10px 0 22px;
                    padding-bottom: 10px;
                    border-bottom: 1px solid var(--border);
                "
            >

                <span class="section-eyebrow">
                    Parent Organization
                </span>

                <h3 style="margin: 0; color: var(--blue-dark);">
                    Contact Details
                </h3>

            </div>


            <div class="form-group">

                <label for="parent_physical_address">
                    Physical Address
                    <span class="required">*</span>
                </label>

                <input
                    type="text"
                    id="parent_physical_address"
                    name="parent_physical_address"
                    maxlength="255"
                    value="<?= old(
                        $form,
                        'parent_physical_address'
                    ) ?>"
                    data-international-required="true"
                >

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="parent_postal_address">
                        Postal Address
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="parent_postal_address"
                        name="parent_postal_address"
                        maxlength="255"
                        value="<?= old(
                            $form,
                            'parent_postal_address'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>


                <div class="form-group">

                    <label for="parent_postal_code">
                        Postal Code
                        <span class="required">*</span>
                    </label>

                    <input
                        type="text"
                        id="parent_postal_code"
                        name="parent_postal_code"
                        maxlength="30"
                        value="<?= old(
                            $form,
                            'parent_postal_code'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="parent_phone_number">
                        Phone Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="tel"
                        id="parent_phone_number"
                        name="parent_phone_number"
                        maxlength="50"
                        value="<?= old(
                            $form,
                            'parent_phone_number'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>


                <div class="form-group">

                    <label for="parent_email">
                        Email of Parent Organization
                        <span class="required">*</span>
                    </label>

                    <input
                        type="email"
                        id="parent_email"
                        name="parent_email"
                        maxlength="255"
                        value="<?= old(
                            $form,
                            'parent_email'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>

            </div>


            <!-- ==================================================
                 Contact Person
                 ================================================== -->

            <div
                style="
                    margin: 10px 0 22px;
                    padding-bottom: 10px;
                    border-bottom: 1px solid var(--border);
                "
            >

                <span class="section-eyebrow">
                    Parent Organization
                </span>

                <h3 style="margin: 0; color: var(--blue-dark);">
                    Contact Person
                </h3>

            </div>


            <div class="form-group">

                <label for="contact_person_name">
                    Name
                    <span class="required">*</span>
                </label>

                <input
                    type="text"
                    id="contact_person_name"
                    name="contact_person_name"
                    maxlength="255"
                    value="<?= old(
                        $form,
                        'contact_person_name'
                    ) ?>"
                    data-international-required="true"
                >

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="contact_person_phone">
                        Phone Number
                        <span class="required">*</span>
                    </label>

                    <input
                        type="tel"
                        id="contact_person_phone"
                        name="contact_person_phone"
                        maxlength="50"
                        value="<?= old(
                            $form,
                            'contact_person_phone'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>


                <div class="form-group">

                    <label for="contact_person_email">
                        Email Address
                        <span class="required">*</span>
                    </label>

                    <input
                        type="email"
                        id="contact_person_email"
                        name="contact_person_email"
                        maxlength="255"
                        value="<?= old(
                            $form,
                            'contact_person_email'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>

            </div>

        </section>


        <!-- ======================================================
             APPLICATION ACTIONS
             ====================================================== -->

        <div class="application-actions">

            <div class="application-actions-left">

                <a
                    href="/pbo-portal/"
                    class="btn btn-secondary"
                >
                    Cancel
                </a>

            </div>


            <<div class="application-actions-right">

    <span
        id="save-status"
        style="
            color: var(--text-muted);
            font-size: 11px;
        "
    >
        Step 1 of 6
    </span>

    <button
        type="submit"
        class="btn btn-primary"
    >
        Save &amp; Continue

        <span aria-hidden="true">
            &rarr;
        </span>
    </button>

</div>



    </form>

</div>


<!-- ==========================================================
     CONDITIONAL FORM LOGIC
     ========================================================== -->

<script>
(function () {

    'use strict';

    const form = document.getElementById('pbo-application-form');

    const internationalSection =
        document.getElementById('international-section');

    const organizationTypeInputs =
        document.querySelectorAll(
            'input[name="registration_type"]'
        );

    const internationalRequiredFields =
        document.querySelectorAll(
            '[data-international-required="true"]'
        );


    /*
    |--------------------------------------------------------------------------
    | Determine selected organization type
    |--------------------------------------------------------------------------
    */

    function getSelectedOrganizationType() {

        const selected =
            document.querySelector(
                'input[name="registration_type"]:checked'
            );

        return selected ? selected.value : '';
    }


    /*
    |--------------------------------------------------------------------------
    | Show / hide Registration Status
    |--------------------------------------------------------------------------
    */

    function updateOrganizationType() {

        const organizationType =
            getSelectedOrganizationType();

        const isInternational =
            organizationType === 'international';


        /*
        | Show only for International.
        |
        | National  use the standard
        | organization address/contact fields only.
        */

        internationalSection.hidden =
            !isInternational;


        /*
        | Browser-level required validation must also follow the
        | selected organization type.
        */

        internationalRequiredFields.forEach(
            function (field) {

                field.required = isInternational;

                if (!isInternational) {

                    /*
                    | Keep values in the DOM in case a user accidentally
                    | changes the organization type and then changes it
                    | back before submitting.
                    |
                    | The PHP backend ignores these values unless the
                    | final organization type is International.
                    */

                    field.setCustomValidity('');
                }
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Listen for organization type changes
    |--------------------------------------------------------------------------
    */

    organizationTypeInputs.forEach(
        function (input) {

            input.addEventListener(
                'change',
                updateOrganizationType
            );
        }
    );


    /*
    |--------------------------------------------------------------------------
    | Initial state
    |--------------------------------------------------------------------------
    |
    | Important after server-side validation errors because PHP will
    | repopulate the previously selected radio button.
    */

    updateOrganizationType();


    /*
    |--------------------------------------------------------------------------
    | Submit validation
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        function (event) {

            updateOrganizationType();

            if (!form.checkValidity()) {

                event.preventDefault();

                /*
                | Trigger native browser validation messages.
                */

                form.reportValidity();

                return;
            }


            /*
            | Prevent accidental double submission.
            */

            const submitButton =
                form.querySelector(
                    'button[type="submit"]'
                );

            if (submitButton) {

                submitButton.disabled = true;

                submitButton.innerHTML =
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