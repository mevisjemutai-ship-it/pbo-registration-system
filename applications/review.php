<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/official-rules.php';
require_once __DIR__ . '/../includes/application-validation.php';

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
| The two tax-identification columns only exist once
| database/add_official_tax_id.sql has been run.
*/
$hasTaxColumns = officialTaxColumnsExist($connection);


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


function hasValue(mixed $value): bool
{
    return trim(
        (string) $value
    ) !== '';
}


function organizationTypeLabel(
    string $type
): string {

    return match ($type) {

        'national'
            => 'National',

        'international'
            => 'International',

        default
            => ucfirst(
                str_replace(
                    '_',
                    ' ',
                    $type
                )
            ),
    };
}


function designationLabel(
    string $designation
): string {

    return match (
        strtoupper($designation)
    ) {

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


function formatRegistrationDate(
    mixed $value
): string {

    $value =
        trim((string) $value);

    if ($value === '') {
        return '';
    }

    try {

        return (
            new DateTimeImmutable($value)
        )->format('d F Y');

    } catch (Throwable $exception) {

        return $value;
    }
}


/*
|--------------------------------------------------------------------------
| Months
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
        bin2hex(
            random_bytes(32)
        );
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

$applicationId =
    (int) $applicationId;


/*
|--------------------------------------------------------------------------
| Master application
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
            registration_date,
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
| Submitted application
|--------------------------------------------------------------------------
*/

if (
    $application['status'] ===
    'submitted'
) {

    header(
        'Location: /pbo-portal/applications/records.php'
    );

    exit;
}


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
            city_town,
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
        ORDER BY id DESC
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

if (!$address) {

    $address = [

        'county' => '',
        'city_town' => '',
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
| International details
|--------------------------------------------------------------------------
*/

$internationalDetails = null;

if (
    $application[
        'registration_type'
    ] === 'international'
) {

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
            ORDER BY id DESC
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
| Other areas of operation (national and international)
|--------------------------------------------------------------------------
|
| One row per selected county. Office details are optional.
|
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
        FROM application_operating_locations
        WHERE application_id =
            :application_id
        ORDER BY county ASC
        "
    );

$statement->execute([
    ':application_id'
        => $applicationId,
]);

$operatingLocations = [];

foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $location) {
    // One entry per county (newest wins if an old duplicate exists).
    $operatingLocations[(string) $location['county']] = $location;
}

$operatingLocationDetailFields = [
    'sub_county' => 'Sub-County',
    'physical_address' => 'Physical Address',
    'postal_address' => 'Postal Address',
    'postal_code' => 'Postal Code',
    'phone_number' => 'Phone Number',
    'alternative_phone_number' => 'Alternative Phone Number',
    'email' => 'Email',
];

$locationsWithOffices = array_filter(
    $operatingLocations,
    static function (array $location) use ($operatingLocationDetailFields): bool {
        foreach (array_keys($operatingLocationDetailFields) as $field) {
            if (hasValue($location[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }
);


/*
|--------------------------------------------------------------------------
| Objectives
|--------------------------------------------------------------------------
*/

$statement =
    $connection->prepare(
        "
        SELECT
            objective_code,
            description
        FROM application_objectives
        WHERE application_id =
            :application_id
        ORDER BY id ASC
        "
    );

$statement->execute([
    ':application_id'
        => $applicationId,
]);

$objectives =
    $statement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Beneficiaries
|--------------------------------------------------------------------------
*/

$statement =
    $connection->prepare(
        "
        SELECT
            beneficiary_code,
            description
        FROM application_beneficiaries
        WHERE application_id =
            :application_id
        ORDER BY id ASC
        "
    );

$statement->execute([
    ':application_id'
        => $applicationId,
]);

$beneficiaries =
    $statement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Officials
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
            " . ($hasTaxColumns ? 'tax_id_type, tax_id_number,' : '') . "
            phone_number,
            email,
            signature
        FROM application_officials
        WHERE application_id =
            :application_id
        ORDER BY
            CASE designation
                WHEN 'CHAIRPERSON'
                    THEN 1
                WHEN 'SECRETARY'
                    THEN 2
                WHEN 'TREASURER'
                    THEN 3
                WHEN 'DIRECTOR'
                    THEN 4
                ELSE 5
            END,
            id ASC
        "
    );

$statement->execute([
    ':application_id'
        => $applicationId,
]);

$officials =
    $statement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Separate principal officials and Directors
|--------------------------------------------------------------------------
*/

$principalOfficials = [

    'CHAIRPERSON' => null,

    'TREASURER' => null,

    'SECRETARY' => null,
];

$directors = [];


foreach ($officials as $official) {

    $designation =
        strtoupper(
            trim(
                (string)
                $official[
                    'designation'
                ]
            )
        );


    if (
        array_key_exists(
            $designation,
            $principalOfficials
        )
    ) {

        $principalOfficials[
            $designation
        ] =
            $official;

        continue;
    }


    if (
        $designation ===
        'DIRECTOR'
    ) {

        $directors[] =
            $official;
    }
}


/*
|--------------------------------------------------------------------------
| Completeness validation
|--------------------------------------------------------------------------
*/

$completionErrors = [];


/*
|--------------------------------------------------------------------------
| Organization (headquarters, international, other areas of operation)
|--------------------------------------------------------------------------
|
| Shared with the Organization page so both always apply the same rules.
| Office details for other areas of operation are optional.
|
*/

foreach (
    pboOrganizationSectionErrors($connection, $applicationId)
    as $organizationError
) {
    $completionErrors[] = $organizationError;
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


foreach (
    $objectives
    as $objective
) {

    if (
        $objective[
            'objective_code'
        ] === 'OTHER' &&
        !hasValue(
            $objective[
                'description'
            ] ?? ''
        )
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
| Financial Year
|--------------------------------------------------------------------------
|
| Valid period:
|
| January -> December
| February -> January
| March -> February
| ...
| December -> November
|
*/

$startMonth =
    (int)
    (
        $application[
            'financial_year_start_month'
        ] ?? 0
    );

$endMonth =
    (int)
    (
        $application[
            'financial_year_end_month'
        ] ?? 0
    );


if (
    !isset($months[$startMonth]) ||
    !isset($months[$endMonth])
) {

    $completionErrors[] =
        'Financial Year has not been selected.';

} else {

    $expectedEndMonth =
        $startMonth === 1
            ? 12
            : $startMonth - 1;


    if (
        $endMonth !==
        $expectedEndMonth
    ) {

        $completionErrors[] =
            'The selected Financial Year is not a valid twelve-month period.';
    }
}


/*
|--------------------------------------------------------------------------
| Principal Officials
|--------------------------------------------------------------------------
|
| Only Chairperson, Secretary and Treasurer are required.
| Signatures are NOT required.
|
*/

/*
| This list is what BLOCKS submission, and it was the last place still
| demanding a KRA PIN and an email from every official. The wizard stopped
| requiring them, so the form said "optional" and then the Review page
| refused to submit - the worst of both.
|
| KRA PIN is handled separately below, by nationality, because for a
| Kenyan official it IS required. Email is not required of anybody.
*/
$requiredOfficialFields = [

    'full_name'
        => 'Name',

    'nationality'
        => 'Nationality',

    'id_passport_number'
        => 'ID / Passport Number',

    'phone_number'
        => 'Phone Number',
];


foreach (
    $principalOfficials
    as $designation => $official
) {

    $roleLabel =
        designationLabel(
            $designation
        );


    if ($official === null) {

        $completionErrors[] =
            $roleLabel .
            ' has not been entered.';

        continue;
    }


    foreach (
        $requiredOfficialFields
        as $field => $label
    ) {

        if (
            !hasValue(
                $official[
                    $field
                ] ?? ''
            )
        ) {

            $completionErrors[] =
                $roleLabel .
                ' — ' .
                $label .
                ' is missing.';        }
    }


    /*
    |--------------------------------------------------------------------------
    | Validate email where present
    |--------------------------------------------------------------------------
    */

    if (
        hasValue(
            $official['email'] ?? ''
        ) &&
        !filter_var(
            $official['email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $completionErrors[] =
            $roleLabel .
            ' — Email is invalid.';
    }


    /*
    |--------------------------------------------------------------------------
    | Tax identification
    |--------------------------------------------------------------------------
    |
    | One shared rule - includes/official-rules.php - so the Review page
    | cannot disagree with the page the details were entered on. A Kenyan
    | official needs a KRA PIN; anyone else may record a foreign tax number
    | instead, or none at all.
    |
    */

    foreach (
        officialTaxIdErrors($official, $roleLabel)
        as $taxError
    ) {
        $completionErrors[] = $taxError;
    }
}


/*
|--------------------------------------------------------------------------
| Directors
|--------------------------------------------------------------------------
|
| Directors are OPTIONAL.
|
| No minimum Director count is enforced here.
| Signatures are also optional.
|
| If a Director exists in the database, validate the six textual particulars
| so an incomplete Director record is not submitted.
|
*/

foreach (
    $directors
    as $index => $director
) {

    $directorLabel =
        'Director ' .
        ($index + 1);


    foreach (
        $requiredOfficialFields
        as $field => $label
    ) {

        if (
            !hasValue(
                $director[$field] ?? ''
            )
        ) {

            $completionErrors[] =
                $directorLabel .
                ' — ' .
                $label .
                ' is missing.';
        }
    }


    if (
        hasValue(
            $director['email'] ?? ''
        ) &&
        !filter_var(
            $director['email'],
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $completionErrors[] =
            $directorLabel .
            ' — Email is invalid.';
    }


    foreach (
        officialTaxIdErrors($director, $directorLabel)
        as $taxError
    ) {
        $completionErrors[] = $taxError;
    }
}


/*
|--------------------------------------------------------------------------
| POST — Final submission
|--------------------------------------------------------------------------
*/

$submitErrors = [];


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
        isset(
            $_POST[
                'confirm_information'
            ]
        ) &&
        $_POST[
            'confirm_information'
        ] === '1';


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
            | Lock draft
            |--------------------------------------------------------------------------
            */

            $statement =
                $connection->prepare(
                    "
                    SELECT
                        status
                    FROM pbo_applications
                    WHERE id =
                        :application_id
                      AND created_by =
                        :user_id
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


            if (
                $lockedApplication[
                    'status'
                ] !== 'draft'
            ) {

                throw new RuntimeException(
                    'This application has already been submitted.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Submit application
            |--------------------------------------------------------------------------
            */

            $statement =
                $connection->prepare(
                    "
                    UPDATE pbo_applications
                    SET
                        status =
                            'submitted',

                        current_step =
                            6,

                        updated_by =
                            :updated_by,

                        submitted_by =
                            :submitted_by,

                        submitted_at =
                            CURRENT_TIMESTAMP

                    WHERE id =
                        :application_id

                      AND status =
                            'draft'
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
            | Ensure one row was actually submitted
            |--------------------------------------------------------------------------
            */

            if (
                $statement->rowCount() !== 1
            ) {

                throw new RuntimeException(
                    'The application could not be marked as submitted.'
                );
            }


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


            $connection->commit();


            /*
            |--------------------------------------------------------------------------
            | Success message
            |--------------------------------------------------------------------------
            */

            $applicationReference =
                trim(
                    (string)
                    (
                        $application[
                            'application_reference'
                        ] ?? ''
                    )
                );


            if (
                $applicationReference !== ''
            ) {

                $_SESSION[
                    'flash_success'
                ] =
                    'PBO application ' .
                    $applicationReference .
                    ' was submitted successfully.';

            } else {

                $_SESSION[
                    'flash_success'
                ] =
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
            | Home
            |--------------------------------------------------------------------------
            */

            header(
                'Location: /pbo-portal/'
            );

            exit;


        } catch (Throwable $exception) {

            if (
                $connection->inTransaction()
            ) {

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

require_once __DIR__ .
    '/../includes/header.php';

?>


<style>

.review-shell {
    display: grid;
    gap: 20px;
}


.review-section {
    overflow: hidden;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
}


.review-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 18px 22px;
    background:
        linear-gradient(
            100deg,
            var(--blue-light),
            #ffffff
        );
    border-bottom: 1px solid var(--border);
}


.review-section-heading {
    display: flex;
    align-items: center;
    gap: 13px;
}


.review-number {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 34px;
    width: 34px;
    height: 34px;
    border: 1px solid #c8dced;
    border-radius: 50%;
    background: #eef6fc;
    color: var(--blue-dark);
    font-size: 12px;
    font-weight: 800;
}


.review-section-header h2 {
    margin: 0 0 3px;
    color: var(--blue-dark);
    font-size: 17px;
}


.review-section-header p {
    margin: 0;
    color: var(--text-secondary);
    font-size: 11px;
}


.review-section-body {
    padding: 21px 24px;
}


.review-grid {
    display: grid;
    grid-template-columns:
        repeat(
            auto-fit,
            minmax(210px, 1fr)
        );
    gap: 18px 24px;
}


.review-field {
    min-width: 0;
}


.review-field-label {
    display: block;
    margin-bottom: 5px;
    color: var(--text-muted);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .55px;
    text-transform: uppercase;
}


.review-field-value {
    display: block;
    overflow-wrap: anywhere;
    color: var(--heading);
    font-size: 13px;
    font-weight: 700;
}


.review-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}


.review-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 10px;
    background: var(--soft-bg);
    border: 1px solid var(--border);
    border-radius: 20px;
    color: var(--heading);
    font-size: 11px;
    font-weight: 700;
}


.review-tag-check {
    color: var(--green);
    font-weight: 900;
}


.official-grid {
    display: grid;
    grid-template-columns:
        repeat(
            auto-fit,
            minmax(290px, 1fr)
        );
    gap: 16px;
}


.official-card {
    overflow: hidden;
    background: #ffffff;
    border: 1px solid var(--border);
    border-radius: var(--radius);
}


.official-card-header {
    padding: 13px 16px;
    background: var(--soft-bg);
    border-bottom: 1px solid var(--border);
}


.official-card-header span {
    display: block;
    margin-bottom: 2px;
    color: var(--text-muted);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .5px;
    text-transform: uppercase;
}


.official-card-header h3 {
    margin: 0;
    color: var(--blue-dark);
    font-size: 15px;
}


.official-card-body {
    padding: 16px;
}


.official-card .review-grid {
    grid-template-columns:
        repeat(
            2,
            minmax(0, 1fr)
        );
    gap: 13px 16px;
}


.signature-on-file {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 15px;
    padding: 7px 10px;
    background: var(--green-light);
    border: 1px solid #c3dfce;
    border-radius: 6px;
    color: var(--green-dark);
    font-size: 10px;
    font-weight: 800;
}


.optional-block {
    margin-top: 19px;
    padding-top: 19px;
    border-top: 1px solid var(--border);
}


.optional-block-title {
    margin: 0 0 13px;
    color: var(--blue-dark);
    font-size: 14px;
}


.review-complete {
    padding: 16px 18px;
    background: var(--green-light);
    border: 1px solid #c3dfce;
    border-left: 4px solid var(--green);
    border-radius: var(--radius);
}


.review-complete strong {
    display: block;
    margin-bottom: 3px;
    color: var(--green-dark);
}


@media (max-width: 650px) {

    .review-section-header {
        align-items: flex-start;
        flex-direction: column;
    }


    .official-card .review-grid {
        grid-template-columns: 1fr;
    }
}

</style>


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
                Step 6 of 6 — Verify the application before final submission.
            </p>

        </div>

    </section>


    <!-- Application identity -->

    <div class="admin-info">

        <strong>
            <?= e(
                (string)
                $application[
                    'pbo_name'
                ]
            ) ?>
        </strong>


        <?php if (
            hasValue(
                $application[
                    'application_reference'
                ] ?? ''
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

    $wizardCurrentStep = 6;

    require __DIR__ .
        '/../includes/application-wizard.php';

    ?>


    <!-- ==========================================================
         COMPLETENESS
         ========================================================== -->

    <?php if (
        $completionErrors === []
    ): ?>

        <div class="review-complete">

            <strong>
                Application complete
            </strong>

            All required information is present.
        </div>

    <?php else: ?>

        <div class="alert alert-warning">

            <strong>
                This application is not yet complete.
            </strong>

            <p
                style="
                    margin: 6px 0 8px;
                "
            >
                Correct the following items before submitting:
            </p>

            <ul
                style="
                    margin: 0;
                    padding-left: 20px;
                "
            >

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


    <?php if (
        $submitErrors !== []
    ): ?>

        <div class="alert alert-error">

            <strong>
                Application not submitted.
            </strong>

            <ul
                style="
                    margin: 8px 0 0;
                    padding-left: 20px;
                "
            >

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


    <div class="review-shell">


        <!-- ======================================================
             1. ORGANIZATION
             ====================================================== -->

        <section class="review-section">

            <div class="review-section-header">

                <div class="review-section-heading">

                    <div class="review-number">
                        1
                    </div>

                    <div>

                        <h2>
                            Organization Details
                        </h2>

                        <p>
                            Registration and contact particulars.
                        </p>

                    </div>

                </div>


                <a
                    href="/pbo-portal/applications/create.php?id=<?= urlencode(
                        (string)
                        $applicationId
                    ) ?>"
                    class="btn btn-secondary btn-sm"
                >
                    Edit
                </a>

            </div>


            <div class="review-section-body">

                <div class="review-grid">


                    <div class="review-field">

                        <span class="review-field-label">
                            Name of PBO
                        </span>

                        <span class="review-field-value">
                            <?= e(
                                (string)
                                $application[
                                    'pbo_name'
                                ]
                            ) ?>
                        </span>

                    </div>


                    <div class="review-field">

                        <span class="review-field-label">
                            PBO Registration Number
                        </span>

                        <span class="review-field-value">
                            <?= e(
                                (string)
                                $application[
                                    'pbo_registration_number'
                                ]
                            ) ?>
                        </span>

                    </div>


                    <div class="review-field">

                        <span class="review-field-label">
                            Date of Registration
                        </span>

                        <span class="review-field-value">
                            <?= e(
                                formatRegistrationDate(
                                    $application[
                                        'registration_date'
                                    ]
                                )
                            ) ?>
                        </span>

                    </div>


                    <div class="review-field">

                        <span class="review-field-label">
                            Type of Organization
                        </span>

                        <span class="review-field-value">
                            <?= e(
                                organizationTypeLabel(
                                    (string)
                                    $application[
                                        'registration_type'
                                    ]
                                )
                            ) ?>
                        </span>

                    </div>


                    <div class="review-field">

                        <span class="review-field-label">
                            County
                        </span>

                        <span class="review-field-value">
                            <?= e(
                                (string)
                                $address['county']
                            ) ?>
                        </span>

                    </div>


                    <?php if (
                        hasValue(
                            $address[
                                'city_town'
                            ] ?? ''
                        )
                    ): ?>

                        <div class="review-field">

                            <span class="review-field-label">
                                City / Town
                            </span>

                            <span class="review-field-value">
                                <?= e(
                                    (string)
                                    $address[
                                        'city_town'
                                    ]
                                ) ?>
                            </span>

                        </div>

                    <?php endif; ?>


                    <?php if (
                        hasValue(
                            $address[
                                'sub_county'
                            ] ?? ''
                        )
                    ): ?>

                        <div class="review-field">

                            <span class="review-field-label">
                                Sub-County
                            </span>

                            <span class="review-field-value">
                                <?= e(
                                    (string)
                                    $address[
                                        'sub_county'
                                    ]
                                ) ?>
                            </span>

                        </div>

                    <?php endif; ?>


                    <div class="review-field">

                        <span class="review-field-label">
                            Physical Address
                        </span>

                        <span class="review-field-value">
                            <?= e(
                                (string)
                                $address[
                                    'physical_address'
                                ]
                            ) ?>
                        </span>

                    </div>


                    <div class="review-field">

                        <span class="review-field-label">
                            Postal Address
                        </span>

                        <span class="review-field-value">
                            <?= e(
                                (string)
                                $address[
                                    'postal_address'
                                ]
                            ) ?>
                        </span>

                    </div>


                    <?php if (
                        hasValue(
                            $address[
                                'postal_code'
                            ] ?? ''
                        )
                    ): ?>

                        <div class="review-field">

                            <span class="review-field-label">
                                Postal Code
                            </span>

                            <span class="review-field-value">
                                <?= e(
                                    (string)
                                    $address[
                                        'postal_code'
                                    ]
                                ) ?>
                            </span>

                        </div>

                    <?php endif; ?>


                    <div class="review-field">

                        <span class="review-field-label">
                            Phone Number
                        </span>

                        <span class="review-field-value">
                            <?= e(
                                (string)
                                $address[
                                    'phone_number'
                                ]
                            ) ?>
                        </span>

                    </div>


                    <?php if (
                        hasValue(
                            $address[
                                'alternative_phone_number'
                            ] ?? ''
                        )
                    ): ?>

                        <div class="review-field">

                            <span class="review-field-label">
                                Alternative Phone Number
                            </span>

                            <span class="review-field-value">
                                <?= e(
                                    (string)
                                    $address[
                                        'alternative_phone_number'
                                    ]
                                ) ?>
                            </span>

                        </div>

                    <?php endif; ?>


                    <div class="review-field">

                        <span class="review-field-label">
                            Email
                        </span>

                        <span class="review-field-value">
                            <?= e(
                                (string)
                                $address['email']
                            ) ?>
                        </span>

                    </div>

                </div>


                <!-- Other areas of operation -->

                <div class="optional-block">

                    <h3 class="optional-block-title">
                        Other Areas of Operation
                    </h3>

                    <?php if ($operatingLocations === []): ?>

                        <span class="text-muted">
                            No other areas of operation.
                        </span>

                    <?php else: ?>

                        <p
                            style="
                                margin: 0 0 10px;
                                color: var(--text-secondary);
                                font-size: 11px;
                            "
                        >
                            <?= count($operatingLocations) ?>
                            <?= count($operatingLocations) === 1 ? 'county' : 'counties' ?>
                            selected<?= $locationsWithOffices !== []
                                ? ', ' . count($locationsWithOffices) . ' with office details'
                                : '' ?>.
                        </p>

                        <div class="review-tags">

                            <?php foreach (
                                $operatingLocations
                                as $countyName => $location
                            ): ?>

                                <span class="review-tag">

                                    <span class="review-tag-check">
                                        &#10003;
                                    </span>

                                    <?= e((string) $countyName) ?>

                                </span>

                            <?php endforeach; ?>

                        </div>


                        <?php if ($locationsWithOffices !== []): ?>

                            <div
                                class="official-grid"
                                style="margin-top: 16px;"
                            >

                                <?php foreach (
                                    $locationsWithOffices
                                    as $countyName => $location
                                ): ?>

                                    <article class="official-card">

                                        <div class="official-card-header">

                                            <span>
                                                Office details
                                            </span>

                                            <h3>
                                                <?= e((string) $countyName) ?>
                                            </h3>

                                        </div>


                                        <div class="official-card-body">

                                            <div class="review-grid">

                                                <?php foreach (
                                                    $operatingLocationDetailFields
                                                    as $field => $label
                                                ): ?>

                                                    <?php if (
                                                        hasValue(
                                                            $location[$field] ?? ''
                                                        )
                                                    ): ?>

                                                        <div class="review-field">

                                                            <span class="review-field-label">
                                                                <?= e($label) ?>
                                                            </span>

                                                            <span class="review-field-value">
                                                                <?= e(
                                                                    (string)
                                                                    $location[$field]
                                                                ) ?>
                                                            </span>

                                                        </div>

                                                    <?php endif; ?>

                                                <?php endforeach; ?>

                                            </div>

                                        </div>

                                    </article>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>

                    <?php endif; ?>

                </div>

            </div>

        </section>


        <!-- ======================================================
             1A. INTERNATIONAL DETAILS
             ====================================================== -->

        <?php if (
            $application[
                'registration_type'
            ] === 'international'
        ): ?>

            <section class="review-section">

                <div class="review-section-header">

                    <div class="review-section-heading">

                        <div class="review-number">
                            1A
                        </div>

                        <div>

                            <h2>
                                International Registration Status
                            </h2>

                            <p>
                                Parent organization and contact-person particulars.
                            </p>

                        </div>

                    </div>


                    <a
                        href="/pbo-portal/applications/create.php?id=<?= urlencode(
                            (string)
                            $applicationId
                        ) ?>"
                        class="btn btn-secondary btn-sm"
                    >
                        Edit
                    </a>

                </div>


                <div class="review-section-body">

                    <div class="review-grid">

                        <?php

                        $internationalReviewFields = [

                            'parent_organization_name'
                                => 'Parent Organization',

                            'country_of_origin'
                                => 'Country of Registration',

                            'registration_number'
                                => 'PBO / Charity Registration Number',

                            'parent_physical_address'
                                => 'Physical Address',

                            'parent_postal_address'
                                => 'Postal Address',

                            'parent_postal_code'
                                => 'Postal Code',

                            'parent_phone_number'
                                => 'Parent Organization Phone',

                            'parent_email'
                                => 'Parent Organization Email',

                            'contact_person_name'
                                => 'Contact Person',

                            'contact_person_phone'
                                => 'Contact Person Phone',

                            'contact_person_email'
                                => 'Contact Person Email',
                        ];

                        ?>


                        <?php foreach (
                            $internationalReviewFields
                            as $field => $label
                        ): ?>

                            <div class="review-field">

                                <span class="review-field-label">
                                    <?= e($label) ?>
                                </span>

                                <span class="review-field-value">
                                    <?= e(
                                        (string)
                                        (
                                            $internationalDetails[
                                                $field
                                            ] ?? ''
                                        )
                                    ) ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            </section>

        <?php endif; ?>


        <!-- ======================================================
             2. OBJECTIVES
             ====================================================== -->

        <section class="review-section">

            <div class="review-section-header">

                <div class="review-section-heading">

                    <div class="review-number">
                        2
                    </div>

                    <div>

                        <h2>
                            Public Benefit Purposes
                        </h2>

                        <p>
                            Selected public benefit purposes of the organization.
                        </p>

                    </div>

                </div>


                <a
                    href="/pbo-portal/applications/objectives.php?id=<?= urlencode(
                        (string)
                        $applicationId
                    ) ?>"
                    class="btn btn-secondary btn-sm"
                >
                    Edit
                </a>

            </div>


            <div class="review-section-body">

                <?php if (
                    $objectives !== []
                ): ?>

                    <div class="review-tags">

                        <?php foreach (
                            $objectives
                            as $objective
                        ): ?>

                            <?php

                            $objectiveCode =
                                (string)
                                $objective[
                                    'objective_code'
                                ];

                            $objectiveLabel =
                                $objectiveLabels[
                                    $objectiveCode
                                ]
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

                            <span class="review-tag">

                                <span class="review-tag-check">
                                    &#10003;
                                </span>

                                <?= e(
                                    $objectiveLabel
                                ) ?>

                            </span>

                        <?php endforeach; ?>

                    </div>


                    <?php foreach (
                        $objectives
                        as $objective
                    ): ?>

                        <?php if (
                            (string)
                            $objective[
                                'objective_code'
                            ] === 'OTHER' &&
                            hasValue(
                                $objective[
                                    'description'
                                ] ?? ''
                            )
                        ): ?>

                            <div
                                style="
                                    margin-top: 17px;
                                    padding: 14px 16px;
                                    background: var(--soft-bg);
                                    border: 1px solid var(--border);
                                    border-left: 4px solid var(--gold);
                                    border-radius: var(--radius);
                                "
                            >

                                <span class="review-field-label">
                                    Any Other — Description
                                </span>

                                <span
                                    class="review-field-value"
                                    style="
                                        white-space: pre-wrap;
                                    "
                                >
                                    <?= e(
                                        (string)
                                        $objective[
                                            'description'
                                        ]
                                    ) ?>
                                </span>

                            </div>

                        <?php endif; ?>

                    <?php endforeach; ?>


                <?php else: ?>

                    <span class="text-muted">
                        No public benefit purposes selected.
                    </span>

                <?php endif; ?>

            </div>

        </section>


        <!-- ======================================================
             3. BENEFICIARIES
             ====================================================== -->

        <section class="review-section">

            <div class="review-section-header">

                <div class="review-section-heading">

                    <div class="review-number">
                        3
                    </div>

                    <div>

                        <h2>
                            Beneficiaries
                        </h2>

                        <p>
                            Persons or groups benefiting from the organization's activities.
                        </p>

                    </div>

                </div>


                <a
                    href="/pbo-portal/applications/beneficiaries.php?id=<?= urlencode(
                        (string)
                        $applicationId
                    ) ?>"
                    class="btn btn-secondary btn-sm"
                >
                    Edit
                </a>

            </div>


            <div class="review-section-body">

                <?php

                $isPublic =
                    false;

                foreach (
                    $beneficiaries
                    as $beneficiary
                ) {

                    if (
                        (string)
                        $beneficiary[
                            'beneficiary_code'
                        ] === 'PUBLIC'
                    ) {

                        $isPublic =
                            true;

                        break;
                    }
                }

                ?>


                <?php if ($isPublic): ?>

                    <div
                        style="
                            padding: 15px 17px;
                            background: var(--soft-bg);
                            border: 1px solid var(--border);
                            border-radius: var(--radius);
                        "
                    >

                        <span class="review-field-label">
                            Beneficiary Category
                        </span>

                        <span class="review-field-value">
                            Public
                        </span>

                    </div>


                <?php elseif (
                    $beneficiaries !== []
                ): ?>

                    <div
                        style="
                            margin-bottom: 16px;
                        "
                    >

                        <span class="review-field-label">
                            Beneficiary Category
                        </span>

                        <span class="review-field-value">
                            Section of the Public
                        </span>

                    </div>


                    <div class="review-tags">

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

                            <span class="review-tag">

                                <span class="review-tag-check">
                                    &#10003;
                                </span>

                                <?= e(
                                    $beneficiaryLabel
                                ) ?>

                            </span>

                        <?php endforeach; ?>

                    </div>


                    <?php foreach (
                        $beneficiaries
                        as $beneficiary
                    ): ?>

                        <?php if (
                            (string)
                            $beneficiary[
                                'beneficiary_code'
                            ] === 'OTHER'
                        ): ?>

                            <?php

                            $otherSpecify = '';
                            $otherDescription = '';

                            $storedOther =
                                trim(
                                    (string)
                                    (
                                        $beneficiary[
                                            'description'
                                        ] ?? ''
                                    )
                                );


                            if (
                                $storedOther !== ''
                            ) {

                                $decodedOther =
                                    json_decode(
                                        $storedOther,
                                        true
                                    );


                                if (
                                    is_array(
                                        $decodedOther
                                    )
                                ) {

                                    $otherSpecify =
                                        trim(
                                            (string)
                                            (
                                                $decodedOther[
                                                    'specify'
                                                ] ?? ''
                                            )
                                        );

                                    $otherDescription =
                                        trim(
                                            (string)
                                            (
                                                $decodedOther[
                                                    'description'
                                                ] ?? ''
                                            )
                                        );

                                } else {

                                    $otherDescription =
                                        $storedOther;
                                }
                            }

                            ?>


                            <?php if (
                                $otherSpecify !== '' ||
                                $otherDescription !== ''
                            ): ?>

                                <div
                                    style="
                                        margin-top: 17px;
                                        padding: 15px 17px;
                                        background: var(--soft-bg);
                                        border: 1px solid var(--border);
                                        border-left: 4px solid var(--gold);
                                        border-radius: var(--radius);
                                    "
                                >

                                    <?php if (
                                        $otherSpecify !== ''
                                    ): ?>

                                        <div class="review-field">

                                            <span class="review-field-label">
                                                Others — Specify
                                            </span>

                                            <span class="review-field-value">
                                                <?= e(
                                                    $otherSpecify
                                                ) ?>
                                            </span>

                                        </div>

                                    <?php endif; ?>


                                    <?php if (
                                        $otherDescription !== ''
                                    ): ?>

                                        <div
                                            class="review-field"
                                            style="
                                                margin-top:
                                                <?= $otherSpecify !== ''
                                                    ? '13px'
                                                    : '0' ?>;
                                            "
                                        >

                                            <span class="review-field-label">
                                                Description
                                            </span>

                                            <span
                                                class="review-field-value"
                                                style="
                                                    white-space: pre-wrap;
                                                "
                                            >
                                                <?= e(
                                                    $otherDescription
                                                ) ?>
                                            </span>

                                        </div>

                                    <?php endif; ?>

                                </div>

                            <?php endif; ?>

                        <?php endif; ?>

                    <?php endforeach; ?>


                <?php else: ?>

                    <span class="text-muted">
                        Beneficiary information has not been entered.
                    </span>

                <?php endif; ?>

            </div>

        </section>


        <!-- ======================================================
             4. FINANCIAL YEAR
             ====================================================== -->

        <section class="review-section">

            <div class="review-section-header">

                <div class="review-section-heading">

                    <div class="review-number">
                        4
                    </div>

                    <div>

                        <h2>
                            Financial Year
                        </h2>

                        <p>
                            Approved twelve-month financial period.
                        </p>

                    </div>

                </div>


                <a
                    href="/pbo-portal/applications/financial-year.php?id=<?= urlencode(
                        (string)
                        $applicationId
                    ) ?>"
                    class="btn btn-secondary btn-sm"
                >
                    Edit
                </a>

            </div>


            <div class="review-section-body">

                <?php if (
                    isset(
                        $months[
                            $startMonth
                        ]
                    ) &&
                    isset(
                        $months[
                            $endMonth
                        ]
                    )
                ): ?>

                    <div
                        style="
                            padding: 18px 20px;
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

                        <span class="review-field-label">
                            Selected Financial Year
                        </span>

                        <strong
                            style="
                                color: var(--blue-dark);
                                font-size: 20px;
                            "
                        >
                            <?= e(
                                $months[
                                    $startMonth
                                ]
                            ) ?>

                            &nbsp;&mdash;&nbsp;

                            <?= e(
                                $months[
                                    $endMonth
                                ]
                            ) ?>
                        </strong>

                    </div>

                <?php endif; ?>

            </div>

        </section>


        <!-- ======================================================
             5. PRINCIPAL OFFICIALS
             ====================================================== -->

        <section class="review-section">

            <div class="review-section-header">

                <div class="review-section-heading">

                    <div class="review-number">
                        5
                    </div>

                    <div>

                        <h2>
                            Principal Officials
                        </h2>

                        <p>
                            Chairperson, Secretary and Treasurer.
                        </p>

                    </div>

                </div>


                <a
                    href="/pbo-portal/applications/officials.php?id=<?= urlencode(
                        (string)
                        $applicationId
                    ) ?>"
                    class="btn btn-secondary btn-sm"
                >
                    Edit
                </a>

            </div>


            <div class="review-section-body">

                <div class="official-grid">

                    <?php foreach (
                        [
                            'CHAIRPERSON',
                            'SECRETARY',
                            'TREASURER',
                        ]
                        as $designation
                    ): ?>

                        <?php

                        $official =
                            $principalOfficials[
                                $designation
                            ];

                        if (
                            $official === null
                        ) {
                            continue;
                        }

                        ?>

                        <article class="official-card">

                            <div class="official-card-header">

                                <span>
                                    Principal Official
                                </span>

                                <h3>
                                    <?= e(
                                        designationLabel(
                                            $designation
                                        )
                                    ) ?>
                                </h3>

                            </div>


                            <div class="official-card-body">

                                <div class="review-grid">

                                    <div class="review-field">

                                        <span class="review-field-label">
                                            Name
                                        </span>

                                        <span class="review-field-value">
                                            <?= e(
                                                (string)
                                                $official[
                                                    'full_name'
                                                ]
                                            ) ?>
                                        </span>

                                    </div>


                                    <div class="review-field">

                                        <span class="review-field-label">
                                            Nationality
                                        </span>

                                        <span class="review-field-value">
                                            <?= e(
                                                (string)
                                                $official[
                                                    'nationality'
                                                ]
                                            ) ?>
                                        </span>

                                    </div>


                                    <div class="review-field">

                                        <span class="review-field-label">
                                            ID / Passport
                                        </span>

                                        <span class="review-field-value">
                                            <?= e(
                                                (string)
                                                $official[
                                                    'id_passport_number'
                                                ]
                                            ) ?>
                                        </span>

                                    </div>


                                    <div class="review-field">

                                        <span class="review-field-label">
                                            KRA PIN
                                        </span>

                                        <span class="review-field-value">
                                            <?= e(
                                                (string)
                                                $official[
                                                    'kra_pin'
                                                ]
                                            ) ?>
                                        </span>

                                    </div>


                                    <?php
                                    /*
                                     | A tax number from another country is
                                     | shown under its own name, never under
                                     | the heading "KRA PIN".
                                     */
                                    $foreignTax = trim(
                                        (string) ($official['tax_id_number'] ?? '')
                                    );

                                    $foreignTaxType = trim(
                                        (string) ($official['tax_id_type'] ?? '')
                                    );
                                    ?>

                                    <?php if ($foreignTax !== ''): ?>

                                        <div class="review-field">

                                            <span class="review-field-label">
                                                <?= e(
                                                    $foreignTaxType !== ''
                                                        ? 'Tax ID (' . $foreignTaxType . ')'
                                                        : 'Tax ID'
                                                ) ?>
                                            </span>

                                            <span class="review-field-value">
                                                <?= e($foreignTax) ?>
                                            </span>

                                        </div>

                                    <?php endif; ?>


                                    <div class="review-field">

                                        <span class="review-field-label">
                                            Phone
                                        </span>

                                        <span class="review-field-value">
                                            <?= e(
                                                (string)
                                                $official[
                                                    'phone_number'
                                                ]
                                            ) ?>
                                        </span>

                                    </div>


                                    <div class="review-field">

                                        <span class="review-field-label">
                                            Email
                                        </span>

                                        <span class="review-field-value">
                                            <?= e(
                                                (string)
                                                $official[
                                                    'email'
                                                ]
                                            ) ?>
                                        </span>

                                    </div>

                                </div>


                                <?php if (
                                    hasValue(
                                        $official[
                                            'signature'
                                        ] ?? ''
                                    )
                                ): ?>

                                    <div class="signature-on-file">
                                        &#10003; Signature on file
                                    </div>

                                <?php endif; ?>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>


                <!-- Optional Directors -->

                <?php if (
                    $directors !== []
                ): ?>

                    <div class="optional-block">

                        <h3 class="optional-block-title">
                            Additional Directors
                            <span
                                style="
                                    margin-left: 6px;
                                    color: var(--text-muted);
                                    font-size: 10px;
                                    font-weight: 700;
                                "
                            >
                                Optional
                            </span>
                        </h3>


                        <div class="official-grid">

                            <?php foreach (
                                $directors
                                as $index => $director
                            ): ?>

                                <article class="official-card">

                                    <div class="official-card-header">

                                        <span>
                                            Additional Director
                                        </span>

                                        <h3>
                                            Director <?= $index + 1 ?>
                                        </h3>

                                    </div>


                                    <div class="official-card-body">

                                        <div class="review-grid">

                                            <div class="review-field">

                                                <span class="review-field-label">
                                                    Name
                                                </span>

                                                <span class="review-field-value">
                                                    <?= e(
                                                        (string)
                                                        $director[
                                                            'full_name'
                                                        ]
                                                    ) ?>
                                                </span>

                                            </div>


                                            <div class="review-field">

                                                <span class="review-field-label">
                                                    Nationality
                                                </span>

                                                <span class="review-field-value">
                                                    <?= e(
                                                        (string)
                                                        $director[
                                                            'nationality'
                                                        ]
                                                    ) ?>
                                                </span>

                                            </div>


                                            <div class="review-field">

                                                <span class="review-field-label">
                                                    ID / Passport
                                                </span>

                                                <span class="review-field-value">
                                                    <?= e(
                                                        (string)
                                                        $director[
                                                            'id_passport_number'
                                                        ]
                                                    ) ?>
                                                </span>

                                            </div>


                                            <div class="review-field">

                                                <span class="review-field-label">
                                                    KRA PIN
                                                </span>

                                                <span class="review-field-value">
                                                    <?= e(
                                                        (string)
                                                        $director[
                                                            'kra_pin'
                                                        ]
                                                    ) ?>
                                                </span>

                                            </div>


                                            <div class="review-field">

                                                <span class="review-field-label">
                                                    Phone
                                                </span>

                                                <span class="review-field-value">
                                                    <?= e(
                                                        (string)
                                                        $director[
                                                            'phone_number'
                                                        ]
                                                    ) ?>
                                                </span>

                                            </div>


                                            <div class="review-field">

                                                <span class="review-field-label">
                                                    Email
                                                </span>

                                                <span class="review-field-value">
                                                    <?= e(
                                                        (string)
                                                        $director[
                                                            'email'
                                                        ]
                                                    ) ?>
                                                </span>

                                            </div>

                                        </div>


                                        <?php if (
                                            hasValue(
                                                $director[
                                                    'signature'
                                                ] ?? ''
                                            )
                                        ): ?>

                                            <div class="signature-on-file">
                                                &#10003; Signature on file
                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </article>

                            <?php endforeach; ?>

                        </div>

                    </div>

                <?php endif; ?>

            </div>

        </section>


    </div>
    <!-- END .review-shell -->


    <!-- ==========================================================
         FINAL SUBMISSION
         ========================================================== -->

    <form
        method="post"
        action=""
        id="submit-application-form"
        style="
            margin-top: 22px;
        "
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(
                $csrfToken
            ) ?>"
        >

        <input
            type="hidden"
            name="application_id"
            value="<?= (int)
                $applicationId ?>"
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

            <!-- FINAL SUBMISSION HEADER -->

            <div
                style="
                    padding: 21px 24px;
                    background:
                        linear-gradient(
                            100deg,
                            var(--gold-light),
                            #ffffff
                        );
                    border-top:
                        4px solid var(--gold);
                    border-bottom:
                        1px solid var(--border);
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


            <!-- FINAL SUBMISSION BODY -->

            <div
                style="
                    padding: 24px;
                "
            >

                <?php if (
                    $completionErrors === []
                ): ?>

                    <div
                        style="
                            margin-bottom: 22px;
                            padding: 17px;
                            background: var(--green-light);
                            border: 1px solid #c3dfce;
                            border-left:
                                4px solid var(--green);
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
                            All required application information
                            has passed the completeness check.
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

                            I confirm that I have reviewed the
                            information recorded in this application
                            and that it is ready for submission.

                        </span>

                    </label>


                <?php else: ?>

                    <div class="alert alert-warning">

                        <strong>
                            Submission unavailable.
                        </strong>

                        <p
                            style="
                                margin: 6px 0 0;
                            "
                        >
                            Complete or correct the outstanding
                            required information identified above
                            before submitting this application.
                        </p>

                    </div>

                <?php endif; ?>

            </div>


            <!-- FINAL ACTIONS -->

            <div class="application-actions">

                <div class="application-actions-left">

                    <a
                        href="/pbo-portal/applications/officials.php?id=<?= urlencode(
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
    | Incomplete application
    |--------------------------------------------------------------------------
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
            |--------------------------------------------------------------------------
            | Confirmation required
            |--------------------------------------------------------------------------
            */

            if (
                !confirmation.checked
            ) {

                event.preventDefault();


                alert(
                    'Please confirm that you have reviewed the application before submitting.'
                );


                confirmation.focus();

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Native validation
            |--------------------------------------------------------------------------
            */

            if (!form.checkValidity()) {

                event.preventDefault();

                form.reportValidity();

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Final irreversible-action confirmation
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
            |--------------------------------------------------------------------------
            | Prevent duplicate submission
            |--------------------------------------------------------------------------
            */

            submitButton.disabled =
                true;


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

require_once __DIR__ .
    '/../includes/footer.php';

?>