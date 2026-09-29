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

$pageTitle = 'Review Application';
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


function displayValue(mixed $value): string
{
    $value = trim((string) $value);

    return $value !== ''
        ? e($value)
        : '<span class="text-muted">Not provided</span>';
}


function organizationTypeLabel(string $type): string
{
    return match ($type) {

        'national'
            => 'National',

        'international'
            => 'International',

    

        default
            => ucfirst(
                str_replace('_', ' ', $type)
            ),
    };
}


function designationLabel(string $designation): string
{
    return match (strtoupper($designation)) {

        'CHAIRPERSON'
            => 'Chairperson',

        'TREASURER'
            => 'Treasurer',

        'SECRETARY'
            => 'Secretary',

        'DIRECTOR'
            => 'Director',

        default
            => ucwords(
                strtolower(
                    str_replace(
                        '_',
                        ' ',
                        $designation
                    )
                )
            ),
    };
}


/*
|--------------------------------------------------------------------------
| Month names
|--------------------------------------------------------------------------
*/

$months = [
    1  => 'January',
    2  => 'February',
    3  => 'March',
    4  => 'April',
    5  => 'May',
    6  => 'June',
    7  => 'July',
    8  => 'August',
    9  => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December',
];


/*
|--------------------------------------------------------------------------
| Objective labels
|--------------------------------------------------------------------------
*/

$objectiveLabels = [

    'AGRICULTURE'
        => 'Agriculture',

    'CHILDREN'
        => 'Children',

    'CULTURE'
        => 'Culture',

    'DISABILITY'
        => 'Disability',

    'ENERGY'
        => 'Energy',

    'EDUCATION'
        => 'Education',

    'ENVIRONMENTAL_CONSERVATION'
        => 'Environmental and Conservation',

    'GENDER'
        => 'Gender',

    'GOVERNANCE'
        => 'Governance',

    'POVERTY_ERADICATION'
        => 'Poverty Eradication',

    'HEALTH'
        => 'Health',

    'HOUSING_SETTLEMENT'
        => 'Housing & Settlement',

    'HUMAN_RIGHTS'
        => 'Human Rights',

    'HIV_AIDS'
        => 'HIV/AIDS',

    'INFORMATION'
        => 'Information',

    'INFORMAL_SECTOR'
        => 'Informal Sector',

    'LEGAL_AID'
        => 'Legal Aid',

    'OLD_AGE'
        => 'Old Age',

    'PEACE_BUILDING'
        => 'Peace Building',

    'POPULATION_REPRODUCTIVE_HEALTH'
        => 'Population & Reproductive Health',

    'REFUGEES'
        => 'Refugees',

    'DISASTER_PREVENTION'
        => 'Disaster prevention, preparedness and mitigation',

    'RELIEF_PASTORALISM_MARGINALIZED'
        => 'Relief Pastoralism and marginalized communities',

    'SPORTS'
        => 'Sports',

    'WATER_SANITATION'
        => 'Water and sanitation',

    'ANIMAL_WELFARE'
        => 'Animal welfare',

    'YOUTH'
        => 'Youth',

    'OTHER'
        => 'Any Other',
];


/*
|--------------------------------------------------------------------------
| Beneficiary labels
|--------------------------------------------------------------------------
*/

$beneficiaryLabels = [

    'PUBLIC'
        => 'Public',

    'CHILDREN'
        => 'Children',

    'ELDERLY'
        => 'The Elderly',

    'YOUTH'
        => 'Youth',

    'COMMERCIAL_SEX_WORKERS'
        => 'Commercial sex workers',

    'DRUG_ALCOHOL_ADDICTS'
        => 'Drug and alcohol addicts',

    'HOMELESS_PEOPLE'
        => 'Homeless people',

    'ORPHANS_VULNERABLE_CHILDREN'
        => 'Orphans and Vulnerable Children',

    'PERSONS_WITH_DISABILITY'
        => 'Persons with Disability',

    'REFUGEES'
        => 'Refugees',

    'STREET_CHILDREN'
        => 'Street Children',

    'THE_POOR'
        => 'The Poor',

    'PRISONERS_EX_CONVICTS'
        => 'Prisoners and ex-convict',

    'PEOPLE_LIVING_WITH_HIV_AIDS'
        => 'People living with HIV/AIDS',

    'CAREGIVERS_VULNERABLE_GROUPS'
        => 'Caregivers for vulnerable groups (OVCs, Elderly, PLWAs)',

    'OTHER'
        => 'Others not listed here',
];


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
| Load master application
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
        financial_year_start_month,
        financial_year_end_month,
        created_by,
        updated_by,
        submitted_by,
        created_at,
        updated_at,
        submitted_at
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


/*
|--------------------------------------------------------------------------
| If already submitted
|--------------------------------------------------------------------------
*/

if ($application['status'] === 'submitted') {

    header(
        'Location: /pbo-portal/applications/records.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Load organization address
|--------------------------------------------------------------------------
*/

$statement = $connection->prepare(
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
    WHERE application_id = :application_id
    LIMIT 1
    "
);

$statement->execute([
    ':application_id' => $applicationId,
]);

$address =
    $statement->fetch(PDO::FETCH_ASSOC);

if (!$address) {

    $address = [
        'county' => '',
        'sub_county' => '',
        'physical_address' => '',
        'postal_address' => '',
        'postal_code' => '',
        'phone_number' => '',
        'alternative_phone_number' => '',
        'email' => '',
    ];
}


/*
|--------------------------------------------------------------------------
| Load International details
|--------------------------------------------------------------------------
*/

$internationalDetails = null;

if (
    $application['registration_type'] ===
    'international'
) {

    $statement = $connection->prepare(
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
        WHERE application_id = :application_id
        LIMIT 1
        "
    );

    $statement->execute([
        ':application_id' => $applicationId,
    ]);

    $internationalDetails =
        $statement->fetch(PDO::FETCH_ASSOC);

    if (!$internationalDetails) {

        $internationalDetails = [
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
    }
}


/*
|--------------------------------------------------------------------------
| Load objectives
|--------------------------------------------------------------------------
*/

$statement = $connection->prepare(
    "
    SELECT
        objective_code,
        description
    FROM application_objectives
    WHERE application_id = :application_id
    ORDER BY id ASC
    "
);

$statement->execute([
    ':application_id' => $applicationId,
]);

$objectives =
    $statement->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Load beneficiaries
|--------------------------------------------------------------------------
*/

$statement = $connection->prepare(
    "
    SELECT
        beneficiary_code,
        description
    FROM application_beneficiaries
    WHERE application_id = :application_id
    ORDER BY id ASC
    "
);

$statement->execute([
    ':application_id' => $applicationId,
]);

$beneficiaries =
    $statement->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Load officials
|--------------------------------------------------------------------------
*/

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

$officials =
    $statement->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Completeness validation
|--------------------------------------------------------------------------
*/

$completionErrors = [];


/*
|--------------------------------------------------------------------------
| Organization
|--------------------------------------------------------------------------
*/

if (trim((string) $application['pbo_name']) === '') {

    $completionErrors[] =
        'Name of PBO is missing.';
}

if (
    !in_array(
        $application['registration_type'],
        [
            'national',
            'international',
        
        ],
        true
    )
) {

    $completionErrors[] =
        'Type of Organization is missing or invalid.';
}


/*
|--------------------------------------------------------------------------
| Address
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
        => 'Organization Email',
];


foreach (
    $requiredAddressFields
    as $field => $label
) {

    if (
        trim(
            (string)
            ($address[$field] ?? '')
        ) === ''
    ) {

        $completionErrors[] =
            $label . ' is missing.';
    }
}


/*
|--------------------------------------------------------------------------
| International details
|--------------------------------------------------------------------------
*/

if (
    $application['registration_type'] ===
    'international'
) {

    $requiredInternationalFields = [

        'parent_organization_name'
            => 'Parent Organization Name',

        'country_of_origin'
            => 'Parent Organization Country',

        'registration_number'
            => 'Parent Organization Registration Number',

        'parent_physical_address'
            => 'Parent Organization Physical Address',

        'parent_postal_address'
            => 'Parent Organization Postal Address',

        'parent_postal_code'
            => 'Parent Organization Postal Code',

        'parent_phone_number'
            => 'Parent Organization Phone Number',

        'parent_email'
            => 'Parent Organization Email',

        'contact_person_name'
            => 'Parent Organization Contact Person',

        'contact_person_phone'
            => 'Contact Person Phone Number',

        'contact_person_email'
            => 'Contact Person Email',
    ];


    foreach (
        $requiredInternationalFields
        as $field => $label
    ) {

        if (
            trim(
                (string)
                ($internationalDetails[$field] ?? '')
            ) === ''
        ) {

            $completionErrors[] =
                $label . ' is missing.';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Objectives
|--------------------------------------------------------------------------
*/

if ($objectives === []) {

    $completionErrors[] =
        'At least one public benefit purpose must be selected.';
}


/*
|--------------------------------------------------------------------------
| Validate Objective OTHER
|--------------------------------------------------------------------------
*/

foreach ($objectives as $objective) {

    if (
        $objective['objective_code'] === 'OTHER' &&
        trim(
            (string)
            ($objective['description'] ?? '')
        ) === ''
    ) {

        $completionErrors[] =
            'The Any Other public benefit purpose has not been described.';
    }
}


/*
|--------------------------------------------------------------------------
| Beneficiaries
|--------------------------------------------------------------------------
*/

if ($beneficiaries === []) {

    $completionErrors[] =
        'Beneficiary information is missing.';
}


/*
|--------------------------------------------------------------------------
| Financial year
|--------------------------------------------------------------------------
*/

$startMonth =
    (int)
    ($application['financial_year_start_month'] ?? 0);

$endMonth =
    (int)
    ($application['financial_year_end_month'] ?? 0);


if (!isset($months[$startMonth])) {

    $completionErrors[] =
        'Financial year start month is missing.';
}

if (!isset($months[$endMonth])) {

    $completionErrors[] =
        'Financial year end month is missing.';
}


/*
|--------------------------------------------------------------------------
| Officials
|--------------------------------------------------------------------------
*/

$requiredRoles = [
    'CHAIRPERSON',
    'TREASURER',
    'SECRETARY',
];

$foundRoles = [];

foreach ($officials as $official) {

    $designation =
        strtoupper(
            trim(
                (string)
                $official['designation']
            )
        );

    $foundRoles[] =
        $designation;


    /*
    | Validate seven particulars.
    */

    $requiredOfficialFields = [

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

        'signature'
            => 'Signature',
    ];


    foreach (
        $requiredOfficialFields
        as $field => $label
    ) {

        if (
            trim(
                (string)
                ($official[$field] ?? '')
            ) === ''
        ) {

            $completionErrors[] =
                designationLabel(
                    $designation
                ) .
                ' — ' .
                $label .
                ' is missing.';
        }
    }
}


foreach ($requiredRoles as $role) {

    if (
        !in_array(
            $role,
            $foundRoles,
            true
        )
    ) {

        $completionErrors[] =
            designationLabel($role) .
            ' has not been entered.';
    }
}


/*
|--------------------------------------------------------------------------
| Minimum five persons
|--------------------------------------------------------------------------
*/

if (count($officials) < 5) {

    $completionErrors[] =
        'At least five officials/directors must be captured.';
}


/*
|--------------------------------------------------------------------------
| POST — Submit Application
|--------------------------------------------------------------------------
*/

$submitErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {


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

        $submitErrors[] =
            'Your session token is invalid or has expired.';
    }


    /*
    |--------------------------------------------------------------------------
    | Completeness
    |--------------------------------------------------------------------------
    */

    if ($completionErrors !== []) {

        $submitErrors[] =
            'The application contains incomplete information and cannot yet be submitted.';
    }


    /*
    |--------------------------------------------------------------------------
    | Final confirmation
    |--------------------------------------------------------------------------
    */

    $confirmed =
        isset($_POST['confirm_information']) &&
        $_POST['confirm_information'] === '1';


    if (!$confirmed) {

        $submitErrors[] =
            'Confirm that the information has been reviewed before submitting the application.';
    }


    /*
    |--------------------------------------------------------------------------
    | Submit
    |--------------------------------------------------------------------------
    */

    if ($submitErrors === []) {

        try {

            $connection->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Lock application
            |--------------------------------------------------------------------------
            |
            | Prevents two browser tabs from submitting the same draft
            | simultaneously.
            |
            */

            $statement =
                $connection->prepare(
                    "
                    SELECT
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
                    'Application could not be locked for submission.'
                );
            }


            if (                $lockedApplication['status'] !==
                'draft'
            ) {

                throw new RuntimeException(
                    'This application has already been submitted.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Mark application as submitted
            |--------------------------------------------------------------------------
            */

            $statement =
                $connection->prepare(
                    "
                    UPDATE pbo_applications
                    SET
                        status = 'submitted',
                        current_step = 6,
                        updated_by = :updated_by,
                        submitted_by = :submitted_by,
                        submitted_at = CURRENT_TIMESTAMP
                    WHERE id = :application_id
                      AND status = 'draft'
                    "
                );


            $statement->execute([

                ':updated_by'
                    => $userId,

                ':submitted_by'
                    => $userId,

                ':application_id'
                    => $applicationId,
            ]);


            /*
            |--------------------------------------------------------------------------
            | Audit log
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
                        'APPLICATION_SUBMITTED',
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
                    => 'PBO application reviewed and submitted.',
            ]);


            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

           /*
|--------------------------------------------------------------------------
| Commit
|--------------------------------------------------------------------------
*/

$connection->commit();


/*
|--------------------------------------------------------------------------
| Success flash message
|--------------------------------------------------------------------------
*/

$applicationReference =
    trim(
        (string)
        ($application['application_reference'] ?? '')
    );


if ($applicationReference !== '') {

    $_SESSION['flash_success'] =
        'PBO application ' .
        $applicationReference .
        ' was submitted successfully.';

} else {

    $_SESSION['flash_success'] =
        'PBO application was submitted successfully.';
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
| Redirect to Home
|--------------------------------------------------------------------------
*/

header(
    'Location: /pbo-portal/'
);

exit;

        } catch (Throwable $exception) {

            if ($connection->inTransaction()) {
                $connection->rollBack();
            }


            error_log(
                'Application submission failed: ' .
                $exception->getMessage()
            );


            $submitErrors[] =
                'The application could not be submitted. No submission changes were committed.';
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
                Review Application
            </h1>

            <p class="subtitle">
                Step 6 of 6 — Review all application information
                before final submission.
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
                    $application['application_reference']
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
            <span>5. Officials</span>

            <span style="color: var(--blue);">
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
                    width: 100%;
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
         COMPLETENESS STATUS
         ========================================================== -->

    <?php if ($completionErrors === []): ?>

        <div class="alert alert-success">

            <strong>
                Application complete.
            </strong>

            All required application sections contain the information
            needed for submission.

        </div>

    <?php else: ?>

        <div class="alert alert-warning">

            <strong>
                This application is not yet complete.
            </strong>

            <p style="margin: 6px 0 8px;">
                Correct the following items before submitting:
            </p>

            <ul style="margin: 0; padding-left: 20px;">

                <?php foreach (
                    $completionErrors
                    as $error
                ): ?>

                    <li>
                        <?= e($error) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>


    <?php if ($submitErrors !== []): ?>

        <div class="alert alert-error">

            <strong>
                Application not submitted.
            </strong>

            <ul style="margin: 8px 0 0; padding-left: 20px;">

                <?php foreach (
                    $submitErrors
                    as $error
                ): ?>

                    <li>
                        <?= e($error) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>


    <!-- ==========================================================
         REVIEW CONTENT
         ========================================================== -->

    <div class="application-form">


        <!-- ======================================================
             1. ORGANIZATION DETAILS
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    1
                </div>

                <div style="flex: 1;">

                    <h2>
                        Organization Details
                    </h2>

                    <p>
                        Particulars and address of the proposed
                        Public Benefit Organization.
                    </p>

                </div>


                <a
                    href="/pbo-portal/applications/create.php?id=<?= urlencode(
                        (string) $applicationId
                    ) ?>"
                    class="btn btn-secondary btn-sm"
                >
                    Edit
                </a>

            </div>


            <div class="record-grid">

                <div class="record-field">

                    <span>
                        Name of PBO
                    </span>

                    <strong>
                        <?= displayValue(
                            $application['pbo_name']
                        ) ?>
                    </strong>

                </div>


                <div class="record-field">

                    <span>
                        PBO Registration Number
                    </span>

                    <strong>
                        <?= displayValue(
                            $application['pbo_registration_number']
                        ) ?>
                    </strong>

                </div>


                <div class="record-field">

                    <span>
                        Type of Organization
                    </span>

                    <strong>
                        <?= e(
                            organizationTypeLabel(
                                (string)
                                $application['registration_type']
                            )
                        ) ?>
                    </strong>

                </div>


                <div class="record-field">

                    <span>
                        County
                    </span>

                    <strong>
                        <?= displayValue(
                            $address['county']
                        ) ?>
                    </strong>

                </div>


                <div class="record-field">

                    <span>
                        Sub-County
                    </span>

                    <strong>
                        <?= displayValue(
                            $address['sub_county']
                        ) ?>
                    </strong>

                </div>


                <div class="record-field">

                    <span>
                        Physical Address
                    </span>

                    <strong>
                        <?= displayValue(
                            $address['physical_address']
                        ) ?>
                    </strong>

                </div>


                <div class="record-field">

                    <span>
                        Postal Address
                    </span>

                    <strong>
                        <?= displayValue(
                            $address['postal_address']
                        ) ?>
                    </strong>

                </div>


                <div class="record-field">

                    <span>
                        Postal Code
                    </span>

                    <strong>
                        <?= displayValue(
                            $address['postal_code']
                        ) ?>
                    </strong>

                </div>


                <div class="record-field">

                    <span>
                        Phone Number
                    </span>

                    <strong>
                        <?= displayValue(
                            $address['phone_number']
                        ) ?>
                    </strong>

                </div>


                <div class="record-field">

                    <span>
                        Alternative Phone Number
                    </span>

                    <strong>
                        <?= displayValue(
                            $address['alternative_phone_number']
                        ) ?>
                    </strong>

                </div>


                <div class="record-field">

                    <span>
                        Email
                    </span>

                    <strong>
                        <?= displayValue(
                            $address['email']
                        ) ?>
                    </strong>

                </div>

            </div>

        </section>


        <!-- ======================================================
             INTERNATIONAL DETAILS
             ====================================================== -->

        <?php if (
            $application['registration_type'] ===
            'international'
        ): ?>

            <section class="application-section">

                <div class="application-section-heading">

                    <div class="application-section-number">
                        1A
                    </div>

                    <div style="flex: 1;">

                        <h2>
                            Registration Status
                        </h2>

                        <p>
                            Parent organization and contact-person
                            particulars.
                        </p>

                    </div>


                    <a
                        href="/pbo-portal/applications/create.php?id=<?= urlencode(
                            (string) $applicationId
                        ) ?>"
                        class="btn btn-secondary btn-sm"
                    >
                        Edit
                    </a>

                </div>


                <div class="record-grid">


                    <div class="record-field">

                        <span>
                            Parent Organization
                        </span>

                        <strong>
                            <?= displayValue(
                                $internationalDetails[
                                    'parent_organization_name'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="record-field">

                        <span>
                            Country of Registration
                        </span>

                        <strong>
                            <?= displayValue(
                                $internationalDetails[
                                    'country_of_origin'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="record-field">

                        <span>
                            PBO / Charity Registration Number
                        </span>

                        <strong>
                            <?= displayValue(
                                $internationalDetails[
                                    'registration_number'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="record-field">

                        <span>
                            Physical Address
                        </span>

                        <strong>
                            <?= displayValue(
                                $internationalDetails[
                                    'parent_physical_address'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="record-field">

                        <span>
                            Postal Address
                        </span>

                        <strong>
                            <?= displayValue(
                                $internationalDetails[
                                    'parent_postal_address'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="record-field">

                        <span>
                            Postal Code
                        </span>

                        <strong>
                            <?= displayValue(
                                $internationalDetails[
                                    'parent_postal_code'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="record-field">

                        <span>
                            Parent Organization Phone
                        </span>

                        <strong>
                            <?= displayValue(
                                $internationalDetails[
                                    'parent_phone_number'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="record-field">

                        <span>
                            Parent Organization Email
                        </span>

                        <strong>
                            <?= displayValue(
                                $internationalDetails[
                                    'parent_email'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="record-field">

                        <span>
                            Contact Person
                        </span>

                        <strong>
                            <?= displayValue(
                                $internationalDetails[
                                    'contact_person_name'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="record-field">

                        <span>
                            Contact Person Phone
                        </span>

                        <strong>
                            <?= displayValue(
                                $internationalDetails[
                                    'contact_person_phone'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="record-field">

                        <span>
                            Contact Person Email
                        </span>

                        <strong>
                            <?= displayValue(
                                $internationalDetails[
                                    'contact_person_email'
                                ]
                            ) ?>
                        </strong>

                    </div>

                </div>

            </section>

        <?php endif; ?>


        <!-- ======================================================
             2. OBJECTIVES
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    2
                </div>

                <div style="flex: 1;">

                    <h2>
                        Public Benefit Purposes
                    </h2>

                    <p>
                        Selected activities relating to the
                        organization's public benefit purposes.
                    </p>

                </div>


                <a
                    href="/pbo-portal/applications/objectives.php?id=<?= urlencode(
                        (string) $applicationId
                    ) ?>"
                    class="btn btn-secondary btn-sm"
                >
                    Edit
                </a>

            </div>


            <?php if ($objectives !== []): ?>

                <div
                    class="checkbox-group"
                    style="
                        display: grid;
                        grid-template-columns:
                            repeat(
                                auto-fit,
                                minmax(250px, 1fr)
                            );
                        gap: 10px;
                    "
                >

                    <?php foreach (
                        $objectives
                        as $objective
                    ): ?>

                        <?php

                        $objectiveCode =
                            (string)
                            $objective['objective_code'];

                        $objectiveLabel =
                            $objectiveLabels[$objectiveCode]
                            ??
                            ucwords(
                                strtolower(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $objectiveCode
                                    )
                                )
                            );

                        ?>

                        <div
                            class="checkbox-option"
                            style="cursor: default;"
                        >

                            <span
                                style="
                                    color: var(--green);
                                    font-weight: 800;
                                "
                            >
                                &#10003;
                            </span>

                            <span>
                                <?= e($objectiveLabel) ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>


                <?php foreach (
                    $objectives
                    as $objective
                ): ?>

                    <?php if (
                        $objective['objective_code'] ===
                        'OTHER'
                    ): ?>

                        <div
                            style="
                                margin-top: 18px;
                                padding: 15px 17px;
                                background: var(--soft-bg);
                                border: 1px solid var(--border);
                                border-left: 4px solid var(--gold);
                                border-radius: var(--radius);
                            "
                        >

                            <span class="section-eyebrow">
                                Any Other
                            </span>

                            <strong
                                style="
                                    display: block;
                                    color: var(--heading);
                                "
                            >
                                If so, Describe
                            </strong>

                            <p
                                style="
                                    margin: 5px 0 0;
                                    white-space: pre-wrap;
                                "
                            >
                                <?= e(
                                    (string)
                                    $objective['description']
                                ) ?>
                            </p>

                        </div>

                    <?php endif; ?>

                <?php endforeach; ?>


            <?php else: ?>

                <p class="text-muted">
                    No public benefit purposes have been selected.
                </p>

            <?php endif; ?>

        </section>


        <!-- ======================================================
             3. BENEFICIARIES
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    3
                </div>

                <div style="flex: 1;">

                    <h2>
                        Beneficiaries
                    </h2>

                    <p>
                        Persons or groups benefiting from the
                        organization's activities.
                    </p>

                </div>


                <a
                    href="/pbo-portal/applications/beneficiaries.php?id=<?= urlencode(
                        (string) $applicationId
                    ) ?>"
                    class="btn btn-secondary btn-sm"
                >
                    Edit
                </a>

            </div>


            <?php

            $isPublic = false;

            foreach ($beneficiaries as $beneficiary) {

                if (
                    $beneficiary['beneficiary_code'] ===
                    'PUBLIC'
                ) {

                    $isPublic = true;
                    break;
                }
            }

            ?>


            <?php if ($isPublic): ?>

                <div class="admin-info">

                    Beneficiary Category:

                    <strong>
                        Public
                    </strong>

                </div>

            <?php elseif ($beneficiaries !== []): ?>

                <div class="admin-info">

                    Beneficiary Category:

                    <strong>
                        Section of the Public
                    </strong>

                </div>


                <div
                    class="checkbox-group"
                    style="
                        display: grid;
                        grid-template-columns:
                            repeat(
                                auto-fit,
                                minmax(250px, 1fr)
                            );
                        gap: 10px;
                    "
                >

                    <?php foreach (
                        $beneficiaries
                        as $beneficiary
                    ): ?>

                        <?php

                        $beneficiaryCode =
                            (string)
                            $beneficiary[
                                'beneficiary_code'
                            ];

                        if (
                            $beneficiaryCode ===
                            'PUBLIC'
                        ) {
                            continue;
                        }

                        $beneficiaryLabel =
                            $beneficiaryLabels[
                                $beneficiaryCode
                            ]
                            ??
                            ucwords(
                                strtolower(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $beneficiaryCode
                                    )
                                )
                            );

                        ?>

                                               <div
                            class="checkbox-option"
                            style="cursor: default;"
                        >

                            <span
                                style="
                                    color: var(--green);
                                    font-weight: 800;
                                "
                            >
                                &#10003;
                            </span>

                            <span>
                                <?= e($beneficiaryLabel) ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>


                <!-- ==============================================
                     OTHER BENEFICIARY DETAILS
                     ============================================== -->

                <?php foreach ($beneficiaries as $beneficiary): ?>

                    <?php if (
                        (string) $beneficiary['beneficiary_code'] === 'OTHER'
                    ): ?>

                        <?php

                        $otherSpecify = '';
                        $otherDescription = '';

                        $storedOther = trim(
                            (string) ($beneficiary['description'] ?? '')
                        );

                        /*
                        |--------------------------------------------------------------------------
                        | beneficiaries.php stores OTHER as JSON:
                        |
                        | {
                        |     "specify": "...",
                        |     "description": "..."
                        | }
                        |--------------------------------------------------------------------------
                        */

                        if ($storedOther !== '') {

                            $decodedOther = json_decode(
                                $storedOther,
                                true
                            );

                            if (is_array($decodedOther)) {

                                $otherSpecify = trim(
                                    (string) ($decodedOther['specify'] ?? '')
                                );

                                $otherDescription = trim(
                                    (string) ($decodedOther['description'] ?? '')
                                );

                            } else {

                                /*
                                | Backward compatibility with older
                                | plain-text records.
                                */

                                $otherDescription = $storedOther;
                            }
                        }

                        ?>


                        <div
                            style="
                                margin-top: 18px;
                                padding: 17px;
                                background: var(--soft-bg);
                                border: 1px solid var(--border);
                                border-left: 4px solid var(--gold);
                                border-radius: var(--radius);
                            "
                        >

                            <span class="section-eyebrow">
                                Others Not Listed Here
                            </span>


                            <div
                                class="record-grid"
                                style="margin-top: 10px;"
                            >

                                <div class="record-field">

                                    <span>
                                        Specify
                                    </span>

                                    <strong>
                                        <?= displayValue(
                                            $otherSpecify
                                        ) ?>
                                    </strong>

                                </div>


                                <div class="record-field">

                                    <span>
                                        If so, Describe
                                    </span>

                                    <strong
                                        style="
                                            white-space: pre-wrap;
                                        "
                                    >
                                        <?= displayValue(
                                            $otherDescription
                                        ) ?>
                                    </strong>

                                </div>

                            </div>

                        </div>

                    <?php endif; ?>

                <?php endforeach; ?>


            <?php else: ?>

                <p class="text-muted">
                    Beneficiary information has not been entered.
                </p>

            <?php endif; ?>

        </section>


        <!-- ======================================================
             4. FINANCIAL YEAR
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    4
                </div>

                <div style="flex: 1;">

                    <h2>
                        Financial Year
                    </h2>

                    <p>
                        Start and end of the organization's financial year.
                    </p>

                </div>


                <a
                    href="/pbo-portal/applications/financial-year.php?id=<?= urlencode(
                        (string) $applicationId
                    ) ?>"
                    class="btn btn-secondary btn-sm"
                >
                    Edit
                </a>

            </div>


            <?php if (
                isset($months[$startMonth]) &&
                isset($months[$endMonth])
            ): ?>

                <div
                    style="
                        padding: 20px;
                        background:
                            linear-gradient(
                                100deg,
                                var(--blue-light),
                                #ffffff
                            );
                        border: 1px solid #d2e0ec;
                        border-left: 4px solid var(--blue);
                        border-radius: var(--radius);
                    "
                >

                    <span
                        style="
                            display: block;
                            margin-bottom: 5px;
                            color: var(--text-muted);
                            font-size: 10px;
                            font-weight: 800;
                            letter-spacing: .6px;
                            text-transform: uppercase;
                        "
                    >
                        Selected Financial Year
                    </span>

                    <strong
                        style="
                            color: var(--blue-dark);
                            font-size: 20px;
                        "
                    >
                        <?= e($months[$startMonth]) ?>
                        &nbsp;&mdash;&nbsp;
                        <?= e($months[$endMonth]) ?>
                    </strong>

                </div>

            <?php else: ?>

                <div class="alert alert-warning">

                    Financial year information has not been completed.

                </div>

            <?php endif; ?>

        </section>


        <!-- ======================================================
             5. OFFICIALS AND DIRECTORS
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    5
                </div>

                <div style="flex: 1;">

                    <h2>
                        Officials and Directors
                    </h2>

                    <p>
                        Personal particulars of the Chairperson,
                        Treasurer, Secretary and additional Directors.
                    </p>

                </div>


                <a
                    href="/pbo-portal/applications/officials.php?id=<?= urlencode(
                        (string) $applicationId
                    ) ?>"
                    class="btn btn-secondary btn-sm"
                >
                    Edit
                </a>

            </div>


            <?php if ($officials !== []): ?>

                <div class="admin-info">

                    Total officials/directors captured:

                    <strong>
                        <?= count($officials) ?>
                    </strong>

                </div>


                <div
                    style="
                        display: grid;
                        grid-template-columns:
                            repeat(
                                auto-fit,
                                minmax(330px, 1fr)
                            );
                        gap: 17px;
                    "
                >

                    <?php foreach ($officials as $official): ?>

                        <?php

                        $designation = designationLabel(
                            (string) $official['designation']
                        );

                        ?>


                        <article
                            style="
                                overflow: hidden;
                                background: var(--white);
                                border: 1px solid var(--border);
                                border-radius: var(--radius);
                                box-shadow: var(--shadow-sm);
                            "
                        >


                            <!-- OFFICIAL HEADER -->

                            <div
                                style="
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

                                <span class="section-eyebrow">
                                    Official / Director
                                </span>

                                <h3
                                    style="
                                        margin: 0;
                                        color: var(--blue-dark);
                                    "
                                >
                                    <?= e($designation) ?>
                                </h3>

                            </div>


                            <!-- OFFICIAL DETAILS -->

                            <div style="padding: 17px;">

                                <div class="record-grid">


                                    <div class="record-field">

                                        <span>
                                            Name
                                        </span>

                                        <strong>
                                            <?= displayValue(
                                                $official['full_name']
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="record-field">

                                        <span>
                                            Nationality
                                        </span>

                                        <strong>
                                            <?= displayValue(
                                                $official['nationality']
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="record-field">

                                        <span>
                                            ID / Passport Number
                                        </span>

                                        <strong>
                                            <?= displayValue(
                                                $official['id_passport_number']
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="record-field">

                                        <span>
                                            KRA PIN
                                        </span>

                                        <strong>
                                            <?= displayValue(
                                                $official['kra_pin']
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="record-field">

                                        <span>
                                            Phone Number
                                        </span>

                                        <strong>
                                            <?= displayValue(
                                                $official['phone_number']
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="record-field">

                                        <span>
                                            Email
                                        </span>

                                        <strong>
                                            <?= displayValue(
                                                $official['email']
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="record-field">

                                        <span>
                                            Signature
                                        </span>

                                        <?php if (
                                            trim(
                                                (string)
                                                ($official['signature'] ?? '')
                                            ) !== ''
                                        ): ?>

                                            <strong
                                                style="
                                                    color: var(--green);
                                                "
                                            >
                                                &#10003; Provided
                                            </strong>

                                        <?php else: ?>

                                            <strong
                                                style="
                                                    color: var(--red);
                                                "
                                            >
                                                Missing
                                            </strong>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="alert alert-warning">

                    Officials and Directors have not been entered.

                </div>

            <?php endif; ?>

        </section>


    </div>
    <!-- END .application-form -->


    <!-- ==========================================================
         FINAL SUBMISSION
         ========================================================== -->

    <form
        method="post"
        action=""
        id="submit-application-form"
        style="margin-top: 22px;"
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


        <section
            style="
                overflow: hidden;
                background: var(--white);
                border: 1px solid var(--border);
                border-radius: var(--radius-lg);
                box-shadow: var(--shadow);
            "
        >


            <!-- SUBMISSION HEADER -->

            <div
                style="
                    padding: 21px 24px;
                    background:
                        linear-gradient(
                            100deg,
                            var(--gold-light),
                            #ffffff
                        );
                    border-top: 4px solid var(--gold);
                    border-bottom: 1px solid var(--border);
                "
            >

                <span class="section-eyebrow">
                    Final Step
                </span>

                <h2
                    style="
                        margin: 0 0 5px;
                        color: var(--blue-dark);
                    "
                >
                    Submit Application
                </h2>

                <p
                    style="
                        margin: 0;
                        color: var(--text-secondary);
                        font-size: 13px;
                    "
                >
                    Review the information above carefully before
                    completing the application.
                </p>

            </div>


            <!-- SUBMISSION BODY -->

            <div style="padding: 24px;">


                <?php if ($completionErrors === []): ?>

                    <div
                        style="
                            margin-bottom: 22px;
                            padding: 17px;
                            background: var(--green-light);
                            border: 1px solid #c3dfce;
                            border-left: 4px solid var(--green);
                            border-radius: var(--radius);
                        "
                    >

                        <strong
                            style="
                                display: block;
                                margin-bottom: 4px;
                                color: var(--green-dark);
                            "
                        >
                            Ready for submission
                        </strong>

                        <span
                            style="
                                color: #356348;
                                font-size: 12px;
                            "
                        >
                            All required sections have passed the
                            application completeness check.
                        </span>

                    </div>


                    <label
                        class="checkbox-option"
                        for="confirm_information"
                        style="
                            align-items: flex-start;
                            padding: 16px;
                        "
                    >

                        <input
                            type="checkbox"
                            id="confirm_information"
                            name="confirm_information"
                            value="1"
                            required
                        >

                        <span>

                            <strong
                                style="
                                    display: block;
                                    margin-bottom: 4px;
                                    color: var(--heading);
                                "
                            >
                                Final Confirmation
                            </strong>

                            I confirm that I have reviewed the information
                            recorded in this application and that it is
                            ready for submission.

                        </span>

                    </label>


                <?php else: ?>

                    <div class="alert alert-warning">

                        <strong>
                            Submission unavailable.
                        </strong>

                        <p style="margin: 6px 0 0;">
                            Complete or correct the outstanding items
                            identified above before submitting this
                            application.
                        </p>

                    </div>

                <?php endif; ?>

            </div>


            <!-- SUBMISSION ACTIONS -->

            <div class="application-actions">

                <div class="application-actions-left">

                    <a
                        href="/pbo-portal/applications/officials.php?id=<?= urlencode(
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
                        Step 6 of 6
                    </span>


                    <button
                        type="submit"
                        id="submit-application-button"
                        class="btn btn-success"
                        <?= $completionErrors !== []
                            ? 'disabled'
                            : '' ?>
                    >
                        Submit Application
                    </button>

                </div>

            </div>

        </section>

    </form>

</div>


<!-- ==============================================================
     FINAL SUBMISSION JAVASCRIPT
     ============================================================== -->

<script>
(function () {

    'use strict';


    const form =
        document.getElementById(
            'submit-application-form'
        );


    const confirmation =
        document.getElementById(
            'confirm_information'
        );


    const submitButton =
        document.getElementById(
            'submit-application-button'
        );


    /*
    |--------------------------------------------------------------------------
    | Application may be incomplete
    |--------------------------------------------------------------------------
    |
    | In that case the confirmation checkbox is not rendered and
    | the submit button is disabled.
    |
    */

    if (
        !form ||
        !confirmation ||
        !submitButton ||
        submitButton.disabled
    ) {

        return;

    }


    /*
    |--------------------------------------------------------------------------
    | Submit
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        function (event) {


            /*
            | Require confirmation.
            */

            if (!confirmation.checked) {

                event.preventDefault();

                alert(
                    'Please confirm that you have reviewed the application before submitting.'
                );

                confirmation.focus();

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
            |--------------------------------------------------------------------------
            | Final confirmation
            |--------------------------------------------------------------------------
            */

            const proceed =
                window.confirm(
                    'Submit this PBO application now?\n\n' +
                    'After submission, the application will no longer ' +
                    'be editable through the draft workflow.'
                );


            if (!proceed) {

                event.preventDefault();

                return;

            }


            /*
            | Prevent double submission.
            */

            submitButton.disabled = true;

            submitButton.textContent =
                'Submitting...';

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