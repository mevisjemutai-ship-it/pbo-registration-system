<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/application-validation.php';
require_once __DIR__ . '/../includes/organization-reach.php';
require_once __DIR__ . '/../includes/official-rules.php';
require_once __DIR__ . '/../includes/text-case.php';
require_once __DIR__ . '/../includes/kenya-locations.php';

requireLogin();
requirePasswordChanged();

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$connection = $pdo ?? $db ?? null;

if (!$connection instanceof PDO) {
    throw new RuntimeException(
        'Database connection was not initialized as a PDO instance.'
    );
}

/*
| The two tax-identification columns only exist once
| database/add_official_tax_id.sql has been run. Until then this page
| behaves exactly as it did before, rather than failing with
| "Unknown column" in front of a member of staff.
*/
$hasTaxColumns = officialTaxColumnsExist($connection);

/*
|--------------------------------------------------------------------------
| Application ID
|--------------------------------------------------------------------------
*/

/*
| filter_var on $_GET / $_POST rather than filter_input: identical for a
| real request, and it lets this page be rendered by a test without a web
| server, which is how the head office sections are checked. The same
| change was made to export-excel.php, view.php and review.php.
*/
$applicationId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$applicationId) {
    $applicationId = filter_var(
        $_POST['application_id'] ?? null,
        FILTER_VALIDATE_INT
    );
}

if (!$applicationId || $applicationId < 1) {
    http_response_code(400);
    exit('Invalid application ID.');
}

$applicationId = (int) $applicationId;

/*
|--------------------------------------------------------------------------
| Controlled options
|--------------------------------------------------------------------------
*/

$objectiveOptions = [
    'AGRICULTURE' => 'Agriculture',
    'CHILDREN' => 'Children',
    'CULTURE' => 'Culture',
    'DISABILITY' => 'Disability',
    'ENERGY' => 'Energy',
    'EDUCATION' => 'Education',
    'ENVIRONMENTAL_CONSERVATION' => 'Environmental and Conservation',
    'GENDER' => 'Gender',
    'GOVERNANCE' => 'Governance',
    'POVERTY_ERADICATION' => 'Poverty Eradication',
    'HEALTH' => 'Health',
    'HOUSING_SETTLEMENT' => 'Housing & Settlement',
    'HUMAN_RIGHTS' => 'Human Rights',
    'HIV_AIDS' => 'HIV/AIDS',
    'INFORMATION' => 'Information',
    'INFORMAL_SECTOR' => 'Informal Sector',
    'LEGAL_AID' => 'Legal Aid',
    'OLD_AGE' => 'Old Age',
    'PEACE_BUILDING' => 'Peace Building',
    'POPULATION_REPRODUCTIVE_HEALTH' => 'Population & Reproductive Health',
    'REFUGEES' => 'Refugees',
    'DISASTER_PREVENTION' => 'Disaster prevention, preparedness and mitigation',
    'RELIEF_PASTORALISM_MARGINALIZED' => 'Relief Pastoralism and marginalized communities',
    'SPORTS' => 'Sports',
    'WATER_SANITATION' => 'Water and sanitation',
    'ANIMAL_WELFARE' => 'Animal welfare',
    'YOUTH' => 'Youth',
    'OTHER' => 'Any Other',
];

$beneficiaryOptions = [
    'CHILDREN' => 'Children',
    'ELDERLY' => 'The Elderly',
    'YOUTH' => 'Youth',
    'COMMERCIAL_SEX_WORKERS' => 'Commercial sex workers',
    'DRUG_ALCOHOL_ADDICTS' => 'Drug and alcohol addicts',
    'HOMELESS_PEOPLE' => 'Homeless people',
    'ORPHANS_VULNERABLE_CHILDREN' => 'Orphans and Vulnerable Children',
    'PERSONS_WITH_DISABILITY' => 'Persons with Disability',
    'REFUGEES' => 'Refugees',
    'STREET_CHILDREN' => 'Street Children',
    'THE_POOR' => 'The Poor',
    'PRISONERS_EX_CONVICTS' => 'Prisoners and ex-convict',
    'PEOPLE_LIVING_WITH_HIV_AIDS' => 'People living with HIV/AIDS',
    'CAREGIVERS_VULNERABLE_GROUPS' => 'Caregivers for vulnerable groups (OVCs, Elderly, PLWAs)',
    'OTHER' => 'Others not listed here',
];

$months = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December',
];

/*
|--------------------------------------------------------------------------
| Load submitted application
|--------------------------------------------------------------------------
*/

$stmt = $connection->prepare(
    "SELECT *
     FROM pbo_applications
     WHERE id = :id
       AND status = 'submitted'
     LIMIT 1"
);

$stmt->execute([
    'id' => $applicationId
]);

$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    http_response_code(404);
    exit('Submitted PBO record was not found.');
}

/*
| Is the headquarters question column there yet? Checked rather than
| assumed, so this page keeps working on a server where
| database/add_operations_outside_kenya.sql has not been applied - it
| simply cannot record the answer until it has.
*/
$hasHeadquartersColumn = pboHeadquartersColumnAvailable($connection);

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
|
| A submitted record may only be edited after administrator verification.
| This is checked on both GET and POST.
|
*/

if (!hasValidAdminEditAuthorization($connection, $applicationId)) {

    http_response_code(403);

    $pageTitle = 'Edit Authorization Required';
    $activePage = 'applications';

    require_once __DIR__ . '/../includes/header.php';
    ?>

    <style>
        .editor-access-page {
            max-width: 980px;
            margin: 0 auto;
            padding: 42px 18px 70px;
        }

        .editor-access-card {
            overflow: hidden;
            border: 1px solid #e2c8cc;
            border-top: 5px solid #c32430;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 10px 28px rgba(31,45,74,.08);
        }

        .editor-access-head {
            padding: 27px 30px;
            background: linear-gradient(110deg, #fff7f7, #fff);
            border-bottom: 1px solid #f0dde0;
        }

        .editor-access-kicker {
            color: #c32430;
            font-size: .75rem;
            font-weight: 900;
            letter-spacing: .09em;
            text-transform: uppercase;
        }

        .editor-access-head h1 {
            margin: 7px 0 8px;
            color: #263452;
            font-weight: 850;
        }

        .editor-access-head p {
            max-width: 760px;
            margin: 0;
            color: #697386;
            line-height: 1.65;
        }

        .editor-access-body {
            padding: 22px 30px 30px;
        }

        .editor-access-meta {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) repeat(3, minmax(120px, .7fr));
            gap: 10px;
            margin-bottom: 18px;
        }

        .editor-access-meta div {
            padding: 12px 14px;
            border: 1px solid #e0e5ed;
            border-radius: 11px;
            background: #fafbfd;
        }

        .editor-access-meta span {
            display: block;
            margin-bottom: 3px;
            color: #7b8494;
            font-size: .7rem;
            font-weight: 800;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .editor-access-meta strong {
            display: block;
            color: #2b3b5e;
            overflow-wrap: anywhere;
        }

        @media (max-width: 760px) {
            .editor-access-page {
                padding: 24px 12px 55px;
            }

            .editor-access-head,
            .editor-access-body {
                padding-left: 18px;
                padding-right: 18px;
            }

            .editor-access-meta {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <main class="editor-access-page">

        <section class="editor-access-card">

            <div class="editor-access-head">

                <span class="editor-access-kicker">
                    Submitted Record • Access Control
                </span>

                <h1>
                    Editing Authorization Required
                </h1>

                <p>
                    The temporary administrator authorization for this
                    submitted PBO record is missing or has expired.
                    Verification is required before any changes can be saved.
                </p>

            </div>

            <div class="editor-access-body">

                <div class="editor-access-meta">

                    <div>
                        <span>Organization</span>
                        <strong><?= e((string) $application['pbo_name']) ?></strong>
                    </div>

                    <div>
                        <span>Registration No.</span>
                        <strong>
                            <?= e(
                                (string) (
                                    $application['pbo_registration_number']
                                    ?: '—'
                                )
                            ) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Status</span>
                        <strong>Submitted</strong>
                    </div>

                    <div>
                        <span>Application ID</span>
                        <strong>#<?= $applicationId ?></strong>
                    </div>

                </div>

                <div class="alert alert-warning mb-3">

                    <strong>
                        No edit has been made.
                    </strong>

                    Verify your administrator identity to start a new
                    controlled edit session.

                </div>

                <div class="d-flex gap-2 flex-wrap">

                    <a
                        href="/pbo-portal/admin/verify-edit.php?id=<?= $applicationId ?>"
                        class="btn btn-primary"
                    >
                        Verify Administrator
                    </a>

                    <a
                        href="/pbo-portal/applications/view.php?id=<?= $applicationId ?>"
                        class="btn btn-outline-secondary"
                    >
                        Return to Record
                    </a>

                </div>

            </div>

        </section>

    </main>

    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/*
|--------------------------------------------------------------------------
| Load headquarters address
|--------------------------------------------------------------------------
*/

$stmt = $connection->prepare(
    "SELECT
        county,
        COALESCE(NULLIF(city_town, ''), town) AS town,
        sub_county,
        physical_address,
        postal_address,
        postal_code,
        phone_number,
        alternative_phone_number,
        email
     FROM application_addresses
     WHERE application_id = :id
     ORDER BY id DESC
     LIMIT 1"
);

$stmt->execute([
    'id' => $applicationId
]);

$address = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

/*
|--------------------------------------------------------------------------
| Load other areas of operation
|--------------------------------------------------------------------------
|
| IMPORTANT:
| application_operating_locations does NOT have a town column.
|
*/

$stmt = $connection->prepare(
    'SELECT
        id,
        county,
        sub_county,
        physical_address,
        phone_number,
        alternative_phone_number,
        email,
        postal_address,
        postal_code
     FROM application_operating_locations
     WHERE application_id = :id
     ORDER BY id ASC'
);

$stmt->execute([
    'id' => $applicationId
]);

$operatingLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$operatingLocationsForForm = $operatingLocations;

/*
|--------------------------------------------------------------------------
| Load international details
|--------------------------------------------------------------------------
*/

$stmt = $connection->prepare(
    'SELECT
        ' . implode(',
        ', array_keys(pboOverseasFields())) . '
     FROM application_international_details
     WHERE application_id = :id
     ORDER BY id DESC
     LIMIT 1'
);

$stmt->execute([
    'id' => $applicationId
]);

$international = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

/*
|--------------------------------------------------------------------------
| Load areas of operation outside Kenya
|--------------------------------------------------------------------------
|
| Offered to national organizations as much as international ones: a
| Kenyan organization running a programme in Uganda is an ordinary thing,
| and until this existed the only place to record where an organization
| works was a list of the 47 counties.
|
*/

$foreignLocations = pboForeignLocations($connection, $applicationId);

$foreignLocationsForForm = $foreignLocations;

/*
|--------------------------------------------------------------------------
| Load objectives
|--------------------------------------------------------------------------
*/

$stmt = $connection->prepare(
    'SELECT objective_code, description
     FROM application_objectives
     WHERE application_id = :id
     ORDER BY id'
);

$stmt->execute([
    'id' => $applicationId
]);

$objectiveRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selectedObjectives = [];
$objectiveOther = '';

foreach ($objectiveRows as $row) {

    $code = (string) $row['objective_code'];

    if (isset($objectiveOptions[$code])) {
        $selectedObjectives[] = $code;
    }

    if ($code === 'OTHER') {
        $objectiveOther = trim(
            (string) ($row['description'] ?? '')
        );
    }
}

/*
|--------------------------------------------------------------------------
| Load beneficiaries
|--------------------------------------------------------------------------
*/

$stmt = $connection->prepare(
    'SELECT beneficiary_code, description
     FROM application_beneficiaries
     WHERE application_id = :id
     ORDER BY id'
);

$stmt->execute([
    'id' => $applicationId
]);

$beneficiaryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$beneficiaryScope = '';
$selectedBeneficiaries = [];
$beneficiaryOtherSpecify = '';
$beneficiaryOtherDescription = '';

foreach ($beneficiaryRows as $row) {

    $code = (string) $row['beneficiary_code'];

    if ($code === 'PUBLIC') {

        $beneficiaryScope = 'public';

    } elseif (isset($beneficiaryOptions[$code])) {

        $beneficiaryScope = 'section_of_public';
        $selectedBeneficiaries[] = $code;
    }

    if ($code === 'OTHER') {

        $decoded = json_decode(
            (string) ($row['description'] ?? ''),
            true
        );

        if (is_array($decoded)) {

            $beneficiaryOtherSpecify = trim(
                (string) ($decoded['specify'] ?? '')
            );

            $beneficiaryOtherDescription = trim(
                (string) ($decoded['description'] ?? '')
            );

        } else {

            $beneficiaryOtherDescription = trim(
                (string) ($row['description'] ?? '')
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load officials
|--------------------------------------------------------------------------
*/

$stmt = $connection->prepare(
    "SELECT
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
     WHERE application_id = :id
     ORDER BY
        CASE designation
            WHEN 'CHAIRPERSON' THEN 1
            WHEN 'SECRETARY' THEN 2
            WHEN 'TREASURER' THEN 3
            WHEN 'DIRECTOR' THEN 4
            ELSE 5
        END,
        id"
);

$stmt->execute([
    'id' => $applicationId
]);

$officialRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$officials = [
    'CHAIRPERSON' => null,
    'TREASURER' => null,
    'SECRETARY' => null,
    'DIRECTOR' => [],
];

foreach ($officialRows as $row) {

    $designation = strtoupper(
        trim((string) $row['designation'])
    );

    if (
        $designation === 'CHAIRPERSON' ||
        $designation === 'TREASURER' ||
        $designation === 'SECRETARY'
    ) {

        $officials[$designation] = $row;

    } elseif ($designation === 'DIRECTOR') {

        $officials['DIRECTOR'][] = $row;
    }
}

/*
|--------------------------------------------------------------------------
| Initial form state
|--------------------------------------------------------------------------
*/

$errors = [];

$form = [
    'pbo_name' =>
        (string) $application['pbo_name'],

    'pbo_registration_number' =>
        (string) ($application['pbo_registration_number'] ?? ''),

    'registration_date' =>
        (string) ($application['registration_date'] ?? ''),

    'registration_type' =>
        (string) $application['registration_type'],

    /*
    | Where the head office is.
    |
    | Never left blank here, unlike the entry form: this page edits a
    | record that already exists, and every page in the portal already
    | reads an unanswered international record as based abroad. Showing
    | neither card would hide overseas details the record actually holds
    | from the person editing it. So it starts on the reading in force
    | and saving writes that answer down explicitly.
    */
    'headquarters_in_kenya' =>
        pboHeadquartersInKenya($application) ? '1' : '0',
];

foreach (
    [
        'county',
        'town',
        'sub_county',
        'physical_address',
        'postal_address',
        'postal_code',
        'phone_number',
        'alternative_phone_number',
        'email',
    ] as $field
) {

    $form[$field] =
        (string) ($address[$field] ?? '');
}

/*
| The overseas fields, from the shared map rather than a list repeated
| here - a column added or renamed there reaches this page on its own.
*/
foreach (array_keys(pboOverseasFields()) as $field) {

    $form[$field] =
        (string) ($international[$field] ?? '');
}

$startMonth = (int) (
    $application['financial_year_start_month'] ?? 0
);

$endMonth = (int) (
    $application['financial_year_end_month'] ?? 0
);

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
     * CSRF validation.
     */
    verifyCsrfToken(
        $_POST['csrf_token'] ?? null
    );

    /*
     * Re-check administrator authorization.
     */
    if (!hasValidAdminEditAuthorization(
        $connection,
        $applicationId
    )) {

        http_response_code(403);
        exit('Editing authorization has expired.');
    }

    /*
     * Read ordinary form fields.
     */
    foreach (array_keys($form) as $field) {

        /* Block capitals, except email - see includes/text-case.php. */
        $form[$field] = pboStoredCase(
            $field,
            $_POST[$field] ?? ''
        );
    }

    $startMonth = (int) (
        $_POST['financial_year_start_month'] ?? 0
    );

    $endMonth = (int) (
        $_POST['financial_year_end_month'] ?? 0
    );

    /*
     |--------------------------------------------------------------------------
     | Other Areas of Operation
     |--------------------------------------------------------------------------
     */

    /*
    | Where the head office is. A national organization is in Kenya by
    | definition, so the answer is set rather than left to whatever the
    | hidden radios happened to hold.
    */
    $form['headquarters_in_kenya'] =
        in_array($_POST['headquarters_in_kenya'] ?? '', ['0', '1'], true)
            ? (string) $_POST['headquarters_in_kenya']
            : '';

    if ($form['registration_type'] === 'national') {
        $form['headquarters_in_kenya'] = '1';
    }

    /*
    | Areas of operation outside Kenya. A card with no country named is
    | an empty one somebody added and thought better of, so it is dropped
    | rather than reported as a mistake.
    */
    $operatesAbroad =
        ($_POST['operates_abroad'] ?? 'no') === 'yes' ? 'yes' : 'no';

    $normalizedForeignLocations = $operatesAbroad === 'yes'
        ? pboNormaliseForeignLocations($_POST['foreign_locations'] ?? [])
        : [];

    if ($normalizedForeignLocations === []) {
        $operatesAbroad = 'no';
    }

    $foreignLocationsForForm = $normalizedForeignLocations;

    $postedOperatingLocations =
        $_POST['operating_locations'] ?? [];

    if (!is_array($postedOperatingLocations)) {
        $postedOperatingLocations = [];
    }

    $normalizedOperatingLocations = [];

    foreach (
        $postedOperatingLocations as $index => $location
    ) {

        if (!is_array($location)) {
            continue;
        }

        $normalized = [
            'county' =>
                trim((string) ($location['county'] ?? '')),

            'sub_county' =>
                trim((string) ($location['sub_county'] ?? '')),

            'physical_address' =>
                trim((string) ($location['physical_address'] ?? '')),

            'phone_number' =>
                trim((string) ($location['phone_number'] ?? '')),

            'alternative_phone_number' =>
                trim((string) ($location['alternative_phone_number'] ?? '')),

            'email' =>
                trim((string) ($location['email'] ?? '')),

            'postal_address' =>
                trim((string) ($location['postal_address'] ?? '')),

            'postal_code' =>
                trim((string) ($location['postal_code'] ?? '')),
        ];

        /*
         * A completely blank operating-location card is valid.
         * It is simply ignored.
         */
        $hasAnyValue = false;

        foreach ($normalized as $value) {

            if ($value !== '') {
                $hasAnyValue = true;
                break;
            }
        }

        if (!$hasAnyValue) {
            continue;
        }

        $locationNumber =
            is_numeric($index)
                ? ((int) $index + 1)
                : count($normalizedOperatingLocations) + 1;

        /*
         * A location needs its county. Office details are optional:
         * many PBOs work in a county without having an office there.
         */
        if ($normalized['county'] === '') {

            $errors[] =
                "Operating location {$locationNumber}: County is required.";
        }

        if (
            $normalized['email'] !== '' &&
            !filter_var(
                $normalized['email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $errors[] =
                "Operating location {$locationNumber}: Email address is invalid.";
        }

        $normalizedOperatingLocations[] =
            $normalized;
    }

    /*
     * Use the submitted locations when the form has validation
     * errors so entered data is not lost.
     */
    $operatingLocationsForForm =
        $normalizedOperatingLocations;

    /*
     |--------------------------------------------------------------------------
     | Objectives
     |--------------------------------------------------------------------------
     */

    $postedObjectives =
        $_POST['objectives'] ?? [];

    $selectedObjectives =
        is_array($postedObjectives)
            ? array_values(
                array_unique(
                    array_map(
                        'strval',
                        $postedObjectives
                    )
                )
            )
            : [];

    $selectedObjectives =
        array_values(
            array_filter(
                $selectedObjectives,
                static fn($value): bool =>
                    array_key_exists(
                        $value,
                        $objectiveOptions
                    )
            )
        );

    $objectiveOther = trim(
        (string) (
            $_POST['objective_other'] ?? ''
        )
    );

    /*
     |--------------------------------------------------------------------------
     | Beneficiaries
     |--------------------------------------------------------------------------
     */

    $beneficiaryScope = trim(
        (string) (
            $_POST['beneficiary_scope'] ?? ''
        )
    );

    $postedBeneficiaries =
        $_POST['beneficiaries'] ?? [];

    $selectedBeneficiaries =
        is_array($postedBeneficiaries)
            ? array_values(
                array_unique(
                    array_map(
                        'strval',
                        $postedBeneficiaries
                    )
                )
            )
            : [];

    $selectedBeneficiaries =
        array_values(
            array_filter(
                $selectedBeneficiaries,
                static fn($value): bool =>
                    array_key_exists(
                        $value,
                        $beneficiaryOptions
                    )
            )
        );

    $beneficiaryOtherSpecify =
        trim(
            (string) (
                $_POST['beneficiary_other_specify'] ?? ''
            )
        );

    $beneficiaryOtherDescription =
        trim(
            (string) (
                $_POST['beneficiary_other_description'] ?? ''
            )
        );

    /*
     |--------------------------------------------------------------------------
     | Officials
     |--------------------------------------------------------------------------
     */

    $postedOfficials =
        $_POST['officials'] ?? [];

    $officials = [
        'CHAIRPERSON' => null,
        'TREASURER' => null,
        'SECRETARY' => null,
        'DIRECTOR' => [],
    ];

    /*
     * Required principal officials.
     */
    foreach (
        [
            'CHAIRPERSON',
            'SECRETARY',
            'TREASURER',
        ] as $designation
    ) {

        $posted =
            is_array(
                $postedOfficials[$designation] ?? null
            )
                ? $postedOfficials[$designation]
                : [];

        $officials[$designation] = [

            'full_name' =>
                pboStoredCase(
                    'full_name',
                    $posted['full_name'] ?? ''
                ),

            'nationality' =>
                pboStoredCase(
                    'nationality',
                    $posted['nationality'] ?? ''
                ),

            'id_passport_number' =>
                pboStoredCase(
                    'id_passport_number',
                    $posted['id_passport_number'] ?? ''
                ),

            'kra_pin' =>
                pboStoredCase(
                    'kra_pin',
                    $posted['kra_pin'] ?? ''
                ),

            'tax_id_type' =>
                cleanTaxIdType(
                    $posted['tax_id_type'] ?? ''
                ),

            'tax_id_number' =>
                pboStoredCase(
                    'tax_id_number',
                    $posted['tax_id_number'] ?? ''
                ),

            'phone_number' =>
                pboStoredCase(
                    'phone_number',
                    $posted['phone_number'] ?? ''
                ),

            'email' =>
                pboStoredCase(
                    'email',
                    $posted['email'] ?? ''
                ),
        ];
    }

    /*
     * Directors.
     *
     * Zero Directors is valid.
     */
    $postedDirectors =
        is_array(
            $postedOfficials['DIRECTOR'] ?? null
        )
            ? $postedOfficials['DIRECTOR']
            : [];

    foreach (
        $postedDirectors as $postedDirector
    ) {

        if (!is_array($postedDirector)) {
            continue;
        }

        $officials['DIRECTOR'][] = [

            'full_name' =>
                pboStoredCase(
                    'full_name',
                    $postedDirector['full_name'] ?? ''
                ),

            'nationality' =>
                pboStoredCase(
                    'nationality',
                    $postedDirector['nationality'] ?? ''
                ),

            'id_passport_number' =>
                pboStoredCase(
                    'id_passport_number',
                    $postedDirector['id_passport_number'] ?? ''
                ),

            'kra_pin' =>
                pboStoredCase(
                    'kra_pin',
                    $postedDirector['kra_pin'] ?? ''
                ),

            'tax_id_type' =>
                cleanTaxIdType(
                    $postedDirector['tax_id_type'] ?? ''
                ),

            'tax_id_number' =>
                pboStoredCase(
                    'tax_id_number',
                    $postedDirector['tax_id_number'] ?? ''
                ),

            'phone_number' =>
                pboStoredCase(
                    'phone_number',
                    $postedDirector['phone_number'] ?? ''
                ),

            'email' =>
                pboStoredCase(
                    'email',
                    $postedDirector['email'] ?? ''
                ),
        ];
    }

    /*
     |--------------------------------------------------------------------------
     | Validation
     |--------------------------------------------------------------------------
     */

    /*
     * Organization.
     */
    if ($form['pbo_name'] === '') {

        $errors[] =
            'Name of PBO is required.';
    }

    if (mb_strlen($form['pbo_name']) > 255) {

        $errors[] =
            'Name of PBO cannot exceed 255 characters.';
    }

    /*
     * Registration date.
     */
    if ($form['registration_date'] === '') {

        $errors[] =
            'Date of Registration is required.';

    } else {

        /*
         | One shared rule - see includes/application-validation.php. It
         | rejects a day that does not exist AND a date in the future:
         | records were found carrying 2027 and 2028.
         */
        $dateError = pboRegistrationDateError(
            $form['registration_date'],
            'Date of Registration'
        );

        if ($dateError !== null) {
            $errors[] = $dateError;
        }
    }

    /*
     * Organization type.
     */
    if (
        !in_array(
            $form['registration_type'],
            [
                'national',
                'international',
            ],
            true
        )
    ) {

        $errors[] =
            'Please select a valid Type of Organization.';
    }

    /*
     * Headquarters address.
     *
     * Town / City is deliberately not made server-required here
     * so older submitted records with no town value can still be
     * edited and saved safely.
     */
    /*
     * An international organization has to say where its head office is,
     * because the answer decides which card has to be complete.
     */
    if (
        $form['registration_type'] === 'international'
        && !in_array($form['headquarters_in_kenya'], ['0', '1'], true)
    ) {
        $errors[] = 'Say whether the head office is in Kenya.';
    }

    $headOfficeAbroad = pboUsesOverseasHeadOffice([
        'registration_type' => $form['registration_type'],
        'headquarters_in_kenya' => $form['headquarters_in_kenya'],
    ]);

    if (!$headOfficeAbroad) {

        foreach (
            [
                'county' =>
                    'County',

                'physical_address' =>
                    'Physical Address',

                'postal_address' =>
                    'Postal Address',

                'phone_number' =>
                    'Organization Phone Number',

                'email' =>
                    'Organization Email',
            ] as $field => $label
        ) {

            if ($form[$field] === '') {

                $errors[] =
                    $label . ' is required.';
            }
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
     * The head office abroad, asked for only when that is where it is.
     *
     * This used to be asked of every international organization, so one
     * whose head office is in Kenya - and many are - could not be saved
     * until somebody copied its Kenyan address into these eleven
     * fields. The register then said a Kenyan organization was its own
     * foreign parent.
     *
     * Labels from pboOverseasFields(), the same place the form takes
     * them from, so a message can never name a field by a label the
     * person cannot see on screen.
     */
    if ($headOfficeAbroad) {

        $overseasLabels = pboOverseasFields();

        foreach ($overseasLabels as $field => $label) {

            if ($form[$field] === '') {

                $errors[] =
                    $label . ' is required.';
            }
        }

        foreach (['parent_email', 'contact_person_email'] as $field) {

            if (
                $form[$field] !== '' &&
                !filter_var(
                    $form[$field],
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                $errors[] =
                    'Enter a valid ' . lcfirst($overseasLabels[$field]) . '.';
            }
        }
    }

    foreach (pboForeignLocationErrors($normalizedForeignLocations) as $foreignError) {
        $errors[] = $foreignError;
    }

    /*
     * Objectives.
     */
    if ($selectedObjectives === []) {

        $errors[] =
            'Select at least one public benefit purpose.';
    }

    if (
        in_array(
            'OTHER',
            $selectedObjectives,
            true
        ) &&
        $objectiveOther === ''
    ) {

        $errors[] =
            'Describe the public benefit purpose selected under Any Other.';
    }

    /*
     * Beneficiaries.
     */
    if (
        !in_array(
            $beneficiaryScope,
            [
                'public',
                'section_of_public',
            ],
            true
        )
    ) {

        $errors[] =
            'Select Public or Section of the Public for beneficiaries.';
    }

    if (
        $beneficiaryScope === 'section_of_public'
    ) {

        if ($selectedBeneficiaries === []) {

            $errors[] =
                'Select at least one beneficiary group.';
        }

        if (
            in_array(
                'OTHER',
                $selectedBeneficiaries,
                true
            ) &&
            (
                $beneficiaryOtherSpecify === '' ||
                $beneficiaryOtherDescription === ''
            )
        ) {

            $errors[] =
                'Specify and describe the beneficiary group under Others not listed here.';
        }

    } else {

        $selectedBeneficiaries = [];
        $beneficiaryOtherSpecify = '';
        $beneficiaryOtherDescription = '';
    }

    /*
     * Financial year.
     */
    if (
        !in_array(
            $startMonth,
            range(1, 12),
            true
        ) ||
        !in_array(
            $endMonth,
            range(1, 12),
            true
        )
    ) {

        $errors[] =
            'Select both financial year months.';
    }

    /*
     |--------------------------------------------------------------------------
     | Required principal officials
     |--------------------------------------------------------------------------
     */

    /*
     | Mirrors officials.php: KRA PIN is never required, and email is
     | required for the Chairperson only.
     */
    foreach (
        [
            'CHAIRPERSON' => 'Chairperson',
            'SECRETARY' => 'Secretary',
            'TREASURER' => 'Treasurer',
        ] as $designation => $label
    ) {

        $official =
            $officials[$designation];

        $officialFields = [
            'full_name' =>
                'Name',

            'nationality' =>
                'Nationality',

            'id_passport_number' =>
                'ID / Passport Number',

            'phone_number' =>
                'Phone Number',
        ];

        foreach (
            $officialFields as $field => $fieldLabel
        ) {

            if (
                ($official[$field] ?? '') === ''
            ) {

                $errors[] =
                    $label .
                    ' — ' .
                    $fieldLabel .
                    ' is required.';
            }
        }

        if (
            ($official['email'] ?? '') !== '' &&
            !filter_var(
                $official['email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $errors[] =
                $label .
                ' — enter a valid email address.';
        }

        /*
         | KRA PIN follows nationality - see includes/official-rules.php.
         */
        foreach (
            officialTaxIdErrors($official, $label) as $taxError
        ) {
            $errors[] = $taxError;
        }
    }

    /*
     |--------------------------------------------------------------------------
     | Optional Directors
     |--------------------------------------------------------------------------
     */

    $completeDirectors = [];

    foreach (
        $officials['DIRECTOR'] as $index => $director
    ) {

        $hasAnyValue = false;

        foreach ($director as $value) {

            if (
                trim((string) $value) !== ''
            ) {

                $hasAnyValue = true;
                break;
            }
        }

        /*
         * Completely blank Director cards are ignored.
         */
        if (!$hasAnyValue) {
            continue;
        }

        /*
         * Once started, a Director's particulars are mandatory - except the
         * KRA PIN, which many officials genuinely do not have.
         */
        foreach (
            [
                'full_name' =>
                    'Name',

                'nationality' =>
                    'Nationality',

                'id_passport_number' =>
                    'ID / Passport Number',

                'phone_number' =>
                    'Phone Number',
            ] as $field => $label
        ) {

            if (
                ($director[$field] ?? '') === ''
            ) {

                $errors[] =
                    'Director ' .
                    ($index + 1) .
                    ' — ' .
                    $label .
                    ' is required.';
            }
        }

        if (
            ($director['email'] ?? '') !== '' &&
            !filter_var(
                $director['email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {

            $errors[] =
                'Director ' .
                ($index + 1) .
                ' — enter a valid email address.';
        }

        /*
         | KRA PIN follows nationality - see includes/official-rules.php.
         */
        foreach (
            officialTaxIdErrors($director, 'Director ' . ($index + 1))
            as $taxError
        ) {
            $errors[] = $taxError;
        }

        $completeDirectors[] =
            $director;
    }

    /*
     |--------------------------------------------------------------------------
     | Save
     |--------------------------------------------------------------------------
     */

    if ($errors === []) {

        try {

            /*
             * Capture existing signatures before deleting/recreating
             * official records.
             */
            $stmt = $connection->prepare(
                'SELECT
                    designation,
                    signature
                 FROM application_officials
                 WHERE application_id = :id
                 ORDER BY id'
            );

            $stmt->execute([
                'id' => $applicationId
            ]);

            $oldSignatures = [
                'CHAIRPERSON' => null,
                'TREASURER' => null,
                'SECRETARY' => null,
                'DIRECTOR' => [],
            ];

            foreach (
                $stmt->fetchAll(PDO::FETCH_ASSOC)
                as $oldOfficial
            ) {

                $designation =
                    strtoupper(
                        trim(
                            (string)
                            $oldOfficial['designation']
                        )
                    );

                if ($designation === 'DIRECTOR') {

                    $oldSignatures['DIRECTOR'][] =
                        $oldOfficial['signature'];

                } elseif (
                    array_key_exists(
                        $designation,
                        $oldSignatures
                    )
                ) {

                    $oldSignatures[$designation] =
                        $oldOfficial['signature'];
                }
            }

            /*
             * Start transaction.
             */
            $connection->beginTransaction();

            /*
             * Update master application.
             */
            $stmt = $connection->prepare(
                "UPDATE pbo_applications
                 SET
                    pbo_name = :pbo_name,
                    pbo_registration_number = :pbo_registration_number,
                    registration_date = :registration_date,
                    registration_type = :registration_type,
                    " . ($hasHeadquartersColumn
                        ? 'headquarters_in_kenya = :headquarters_in_kenya,'
                        : '') . "
                    financial_year_start_month = :start_month,
                    financial_year_end_month = :end_month,
                    updated_by = :updated_by
                 WHERE id = :id
                   AND status = 'submitted'"
            );

            $parameters = [
                'pbo_name' =>
                    $form['pbo_name'],

                'pbo_registration_number' =>
                    $form['pbo_registration_number'] !== ''
                        ? $form['pbo_registration_number']
                        : null,

                'registration_date' =>
                    $form['registration_date'],

                'registration_type' =>
                    $form['registration_type'],

                'start_month' =>
                    $startMonth,

                'end_month' =>
                    $endMonth,

                'updated_by' =>
                    currentUserId(),

                'id' =>
                    $applicationId,
            ];

            if ($hasHeadquartersColumn) {
                $parameters['headquarters_in_kenya'] =
                    $form['headquarters_in_kenya'] !== ''
                        ? (int) $form['headquarters_in_kenya']
                        : null;
            }

            $stmt->execute($parameters);

            /*
             |--------------------------------------------------------------------------
             | Headquarters / Head Office
             |--------------------------------------------------------------------------
             */

            $stmt = $connection->prepare(
                'DELETE FROM application_addresses
                 WHERE application_id = :id'
            );

            $stmt->execute([
                'id' => $applicationId
            ]);

            $stmt = $connection->prepare(
                'INSERT INTO application_addresses
                (
                    application_id,
                    county,
                    town,
                    city_town,
                    sub_county,
                    physical_address,
                    postal_address,
                    postal_code,
                    phone_number,
                    alternative_phone_number,
                    email
                )
                VALUES
                (
                    :id,
                    :county,
                    :town,
                    :city_town,
                    :sub_county,
                    :physical_address,
                    :postal_address,
                    :postal_code,
                    :phone_number,
                    :alternative_phone_number,
                    :email
                )'
            );

            $town = $form['town'] !== '' ? $form['town'] : null;

            $stmt->execute([
                'id' => $applicationId,
                'county' => $form['county'],
                'town' => $town,
                'city_town' => $town,
                'sub_county' => $form['sub_county'] !== '' ? $form['sub_county'] : null,
                'physical_address' => $form['physical_address'],
                'postal_address' => $form['postal_address'],
                'postal_code' => $form['postal_code'] !== '' ? $form['postal_code'] : null,
                'phone_number' => $form['phone_number'],
                'alternative_phone_number' =>
                    $form['alternative_phone_number'] !== ''
                        ? $form['alternative_phone_number']
                        : null,
                'email' => $form['email'] !== '' ? $form['email'] : null,
            ]);

            /*
             |--------------------------------------------------------------------------
             | Other Areas of Operation
             |--------------------------------------------------------------------------
             |
             | Existing operating locations are replaced inside the same
             | transaction. A completely blank list means the PBO has no
             | additional operating locations.
             |
             */

            $stmt = $connection->prepare(
                'DELETE FROM application_operating_locations
                 WHERE application_id = :id'
            );

            $stmt->execute([
                'id' => $applicationId
            ]);

            if ($normalizedOperatingLocations !== []) {

                $insertOperatingLocation =
                    $connection->prepare(
                        'INSERT INTO application_operating_locations
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
                        )'
                    );

                foreach (
                    $normalizedOperatingLocations
                    as $location
                ) {

                    $insertOperatingLocation->execute([
                        'application_id' =>
                            $applicationId,

                        'county' =>
                            $location['county'],

                        'sub_county' =>
                            $location['sub_county'],

                        'physical_address' =>
                            $location['physical_address'],

                        'phone_number' =>
                            $location['phone_number'],

                        'alternative_phone_number' =>
                            $location['alternative_phone_number'] !== ''
                                ? $location['alternative_phone_number']
                                : null,

                        /*
                         * Blank office details are stored as empty text,
                         * the same way the application wizard saves them.
                         */
                        'email' =>
                            $location['email'],

                        'postal_address' =>
                            $location['postal_address'],

                        'postal_code' =>
                            $location['postal_code'],
                    ]);
                }
            }

            /*
             |--------------------------------------------------------------------------
             | International details
             |--------------------------------------------------------------------------
             */

            /*
             * Stored only when the head office IS abroad. An
             * international organization based in Kenya has nothing
             * overseas to record, and the row is removed if it once had
             * one - otherwise an old copy of its Kenyan address would
             * sit in the register as its foreign head office forever.
             */
            if ($headOfficeAbroad) {

                $stmt = $connection->prepare(
                    'DELETE FROM application_international_details
                     WHERE application_id = :id'
                );

                $stmt->execute([
                    'id' => $applicationId
                ]);

                $stmt = $connection->prepare(
                    'INSERT INTO application_international_details
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
                        :id,
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
                    )'
                );

                $stmt->execute([
                    'id' =>
                        $applicationId,

                    'parent_organization_name' =>
                        $form['parent_organization_name'],

                    'country_of_origin' =>
                        $form['country_of_origin'],

                    'registration_number' =>
                        $form['registration_number'],

                    'parent_physical_address' =>
                        $form['parent_physical_address'],

                    'parent_postal_address' =>
                        $form['parent_postal_address'],

                    'parent_postal_code' =>
                        $form['parent_postal_code'],

                    'parent_phone_number' =>
                        $form['parent_phone_number'],

                    'parent_email' =>
                        $form['parent_email'],

                    'contact_person_name' =>
                        $form['contact_person_name'],

                    'contact_person_phone' =>
                        $form['contact_person_phone'],

                    'contact_person_email' =>
                        $form['contact_person_email'],
                ]);

            } else {

                $stmt = $connection->prepare(
                    'DELETE FROM application_international_details
                     WHERE application_id = :id'
                );

                $stmt->execute([
                    'id' => $applicationId
                ]);
            }

            /*
             |--------------------------------------------------------------------------
             | Areas of operation outside Kenya
             |--------------------------------------------------------------------------
             |
             | Replaced inside the same transaction, as the other child
             | tables are. Does nothing on a server where the migration
             | has not been applied.
             |
             */

            pboSaveForeignLocations(
                $connection,
                $applicationId,
                $normalizedForeignLocations
            );

            /*
             |--------------------------------------------------------------------------
             | Objectives
             |--------------------------------------------------------------------------
             */

            $stmt = $connection->prepare(
                'DELETE FROM application_objectives
                 WHERE application_id = :id'
            );

            $stmt->execute([
                'id' => $applicationId
            ]);

            $stmt = $connection->prepare(
                'INSERT INTO application_objectives
                (
                    application_id,
                    objective_code,
                    description
                )
                VALUES
                (
                    :id,
                    :code,
                    :description
                )'
            );

            foreach (
                $selectedObjectives as $code
            ) {

                $stmt->execute([
                    'id' =>
                        $applicationId,

                    'code' =>
                        $code,

                    'description' =>
                        $code === 'OTHER'
                            ? $objectiveOther
                            : null,
                ]);
            }

            /*
             |--------------------------------------------------------------------------
             | Beneficiaries
             |--------------------------------------------------------------------------
             */

            $stmt = $connection->prepare(
                'DELETE FROM application_beneficiaries
                 WHERE application_id = :id'
            );

            $stmt->execute([
                'id' => $applicationId
            ]);

            if (
                $beneficiaryScope === 'public'
            ) {

                $stmt = $connection->prepare(
                    "INSERT INTO application_beneficiaries
                    (
                        application_id,
                        beneficiary_code,
                        description
                    )
                    VALUES
                    (
                        :id,
                        'PUBLIC',
                        NULL
                    )"
                );

                $stmt->execute([
                    'id' =>
                        $applicationId
                ]);

            } else {

                $stmt = $connection->prepare(
                    'INSERT INTO application_beneficiaries
                    (
                        application_id,
                        beneficiary_code,
                        description
                    )
                    VALUES
                    (
                        :id,
                        :code,
                        :description
                    )'
                );

                foreach (
                    $selectedBeneficiaries as $code
                ) {

                    $description = null;

                    if ($code === 'OTHER') {

                        $description = json_encode(
                            [
                                'specify' =>
                                    $beneficiaryOtherSpecify,

                                'description' =>
                                    $beneficiaryOtherDescription,
                            ],
                            JSON_UNESCAPED_UNICODE |
                            JSON_UNESCAPED_SLASHES
                        );
                    }

                    $stmt->execute([
                        'id' =>
                            $applicationId,

                        'code' =>
                            $code,

                        'description' =>
                            $description,
                    ]);
                }
            }

            /*
             |--------------------------------------------------------------------------
             | Officials
             |--------------------------------------------------------------------------
             */

            $stmt = $connection->prepare(
                'DELETE FROM application_officials
                 WHERE application_id = :id'
            );

            $stmt->execute([
                'id' => $applicationId
            ]);

            $insertOfficial = $connection->prepare(
                'INSERT INTO application_officials
                (
                    application_id,
                    full_name,
                    designation,
                    nationality,
                    id_passport_number,
                    kra_pin,
                    ' . ($hasTaxColumns ? 'tax_id_type, tax_id_number,' : '') . '
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
                    ' . ($hasTaxColumns ? ':tax_id_type, :tax_id_number,' : '') . '
                    :phone_number,
                    :email,
                    :signature
                )'
            );

            /*
             * Principal officials.
             */
            foreach (
                [
                    'CHAIRPERSON',
                    'SECRETARY',
                    'TREASURER',
                ] as $designation
            ) {

                $official =
                    $officials[$designation];

                $parameters = [
                    'application_id' =>
                        $applicationId,

                    'full_name' =>
                        $official['full_name'],

                    'designation' =>
                        $designation,

                    'nationality' =>
                        $official['nationality'],

                    'id_passport_number' =>
                        $official['id_passport_number'],

                    'kra_pin' =>
                        $official['kra_pin'],

                    'phone_number' =>
                        $official['phone_number'],

                    'email' =>
                        $official['email'],

                    'signature' =>
                        $oldSignatures[$designation] ?? null,
                ];

                if ($hasTaxColumns) {

                    $parameters['tax_id_type'] =
                        ($official['tax_id_type'] ?? '') !== ''
                            ? $official['tax_id_type']
                            : null;

                    $parameters['tax_id_number'] =
                        ($official['tax_id_number'] ?? '') !== ''
                            ? $official['tax_id_number']
                            : null;
                }

                $insertOfficial->execute($parameters);
            }

            /*
             * Optional Directors.
             */
            foreach (
                $completeDirectors as $index => $director
            ) {

                $parameters = [
                    'application_id' =>
                        $applicationId,

                    'full_name' =>
                        $director['full_name'],

                    'designation' =>
                        'DIRECTOR',

                    'nationality' =>
                        $director['nationality'],

                    'id_passport_number' =>
                        $director['id_passport_number'],

                    'kra_pin' =>
                        $director['kra_pin'],

                    'phone_number' =>
                        $director['phone_number'],

                    'email' =>
                        $director['email'],

                    'signature' =>
                        $oldSignatures['DIRECTOR'][$index] ?? null,
                ];

                if ($hasTaxColumns) {

                    $parameters['tax_id_type'] =
                        ($director['tax_id_type'] ?? '') !== ''
                            ? $director['tax_id_type']
                            : null;

                    $parameters['tax_id_number'] =
                        ($director['tax_id_number'] ?? '') !== ''
                            ? $director['tax_id_number']
                            : null;
                }

                $insertOfficial->execute($parameters);
            }

            /*
             |--------------------------------------------------------------------------
             | Audit
             |--------------------------------------------------------------------------
             */

            $stmt = $connection->prepare(
                'INSERT INTO application_audit_log
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
                    :action,
                    :description
                )'
            );

            $stmt->execute([
                'application_id' =>
                    $applicationId,

                'user_id' =>
                    currentUserId(),

                'action' =>
                    'submitted_record_edited',

                'description' =>
                    'Administrator edited the submitted PBO record. Organization, headquarters address, other areas of operation, international details where applicable, objectives, beneficiaries, financial year and officials were processed in one transaction. Directors are optional. Existing official signatures were preserved.',
            ]);

            /*
             |--------------------------------------------------------------------------
             | Commit
             |--------------------------------------------------------------------------
             */

            $connection->commit();

            /*
             * Authorization is single-use after successful save.
             */
            clearAdminEditAuthorization();

            header(
                'Location: /pbo-portal/applications/view.php?id=' .
                $applicationId .
                '&updated=1'
            );

            exit;

        } catch (Throwable $exception) {

            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            error_log(
                'Submitted PBO edit failed: ' .
                $exception->getMessage()
            );

            $errors[] =
                'The submitted record could not be saved. No changes were committed. Check the server error log for technical details.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Page
|--------------------------------------------------------------------------
*/

$pageTitle = 'Edit Submitted PBO';
$activePage = 'applications';

$editAuthorization =
    $_SESSION['admin_edit_authorization'] ?? [];

$editAuthorizationExpiresAt =
    (int) (
        $editAuthorization['expires_at'] ?? 0
    );

$editAuthorizationSecondsRemaining =
    max(
        0,
        $editAuthorizationExpiresAt > 0
            ? $editAuthorizationExpiresAt - time()
            : 0
    );

$editAuthorizationMinutesRemaining =
    (int) ceil(
        $editAuthorizationSecondsRemaining / 60
    );

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/* ==========================================================
   SUBMITTED RECORD EDITOR
   Matches the dashboard and records pages and uses the shared
   colour variables from assets/css/style.css.
   ========================================================== */

.submitted-editor {
    --ed-ink: var(--heading, #15283b);
    --ed-muted: #5f6b78;
    --ed-line: #e2e6eb;
    --ed-field: #c9d1da;
    --ed-radius: 12px;

    width: 100%;
    max-width: 1180px;
    margin: 0 auto;
    padding: 0 0 96px;
    color: var(--text, #26313d);
}

.submitted-editor *,
.submitted-editor *::before,
.submitted-editor *::after {
    box-sizing: border-box;
}

.submitted-editor h1,
.submitted-editor h2,
.submitted-editor p {
    margin: 0;
}


/* ---------- Layout helpers the page markup uses ---------- */

.submitted-editor .row {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 18px 20px;
}

.submitted-editor .row > * {
    grid-column: span 12;
    min-width: 0;
}

.submitted-editor .row > .col-md-4 { grid-column: span 4; }
.submitted-editor .row > .col-md-6 { grid-column: span 6; }
.submitted-editor .row > .col-md-8 { grid-column: span 8; }

.submitted-editor .d-flex { display: flex; }
.submitted-editor .flex-wrap { flex-wrap: wrap; }
.submitted-editor .gap-2 { gap: 8px; }
.submitted-editor .mb-0 { margin-bottom: 0 !important; }
.submitted-editor .mb-2 { margin-bottom: 8px !important; }
.submitted-editor .mb-3 { margin-bottom: 14px !important; }
.submitted-editor .mt-1 { margin-top: 4px !important; }
.submitted-editor .mt-3 { margin-top: 14px !important; }
.submitted-editor .mt-4 { margin-top: 22px !important; }
.submitted-editor .fw-bold { font-weight: 700; }
.submitted-editor .fw-semibold { font-weight: 600; }
.submitted-editor .fw-normal { font-weight: 400; }
.submitted-editor .small { font-size: 13px; }
.submitted-editor .text-danger { color: var(--red, #b5222a); }
.submitted-editor .text-muted { color: var(--ed-muted); }


/* ---------- Form controls ---------- */

.submitted-editor .form-control,
.submitted-editor .form-select {
    display: block;
    width: 100%;
    min-height: 44px;
    padding: 10px 12px;
    border: 1px solid var(--ed-field);
    border-radius: 8px;
    background-color: #ffffff;
    color: var(--ed-ink);
    font-size: 14.5px;
    box-shadow: none;
}

.submitted-editor .form-select {
    padding-right: 36px;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%235f6b78' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' d='m4 6 4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 12px center;
    background-repeat: no-repeat;
    background-size: 14px;
}

.submitted-editor textarea.form-control {
    min-height: 96px;
    resize: vertical;
}

.submitted-editor .form-control:hover,
.submitted-editor .form-select:hover {
    border-color: #aab6c3;
}

.submitted-editor .form-control:focus,
.submitted-editor .form-select:focus {
    border-color: var(--blue, #123b69);
    box-shadow: 0 0 0 3px rgba(18, 59, 105, 0.12);
    outline: none;
}

.submitted-editor .form-check-input {
    width: 17px;
    height: 17px;
    margin: 1px 0 0;
    accent-color: var(--blue, #123b69);
}

.edit-label {
    display: block;
    margin-bottom: 6px;
    color: var(--ed-ink);
    font-size: 13.5px;
    font-weight: 600;
}

.edit-help {
    display: block;
    margin-top: 5px;
    color: var(--ed-muted);
    font-size: 12.5px;
    line-height: 1.45;
}


/* ---------- Buttons ---------- */

.submitted-editor .btn {
    border-radius: 8px;
}

.submitted-editor .btn-outline-secondary {
    background: #ffffff;
    border: 1px solid var(--ed-field);
    color: var(--ed-ink);
}

.submitted-editor .btn-outline-secondary:hover {
    background: var(--blue-soft, #f6f9fc);
    border-color: #aab6c3;
    color: var(--ed-ink);
}

.submitted-editor .btn-lg {
    min-height: 46px;
    padding: 0 22px;
    font-size: 15px;
}

.submitted-editor .btn:focus-visible,
.submitted-editor a:focus-visible,
.submitted-editor .form-check-input:focus-visible {
    outline: 3px solid rgba(201, 154, 46, 0.55);
    outline-offset: 2px;
}


/* ---------- Alerts ---------- */

.submitted-editor .alert-danger {
    background: var(--red-light, #fbeaec);
    border: 1px solid #ecc5c8;
    color: #7b1c24;
}

.editor-error {
    margin-bottom: 18px;
    padding: 16px 18px;
    border-left: 4px solid var(--red, #b5222a) !important;
    border-radius: 10px;
}

.editor-error ul {
    padding-left: 20px;
}


/* ---------- Top bar ---------- */

.editor-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}

.editor-topbar-left,
.editor-topbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.editor-topbar-right {
    color: var(--ed-muted);
}

.editor-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 5px 11px;
    border-radius: 999px;
    background: var(--red-light, #fbeaec);
    color: var(--red-dark, #8f1720);
    font-size: 13px;
    font-weight: 600;
}

.editor-badge-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--red, #b5222a);
}


/* ---------- Header + record summary (one panel) ---------- */

.editor-page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 24px;
    padding: 28px 32px 24px;
    background: #ffffff;
    border: 1px solid var(--ed-line);
    border-bottom: 0;
    border-radius: var(--ed-radius) var(--ed-radius) 0 0;
}

.editor-eyebrow {
    display: block;
    margin-bottom: 6px;
    color: var(--gold-dark, #9d741d);
    font-size: 13.5px;
    font-weight: 600;
}

.editor-page-header h1 {
    color: var(--ed-ink);
    font-size: 30px;
    font-weight: 700;
    letter-spacing: -0.015em;
    line-height: 1.15;
}

.editor-page-header p {
    max-width: 68ch;
    margin-top: 8px;
    color: var(--ed-muted);
    font-size: 14.5px;
    line-height: 1.6;
}

.editor-page-header p strong {
    color: var(--ed-ink);
}

.editor-header-mark {
    flex-shrink: 0;
    min-width: 180px;
    padding: 12px 16px;
    border: 1px solid var(--ed-line);
    border-radius: 10px;
    background: var(--blue-soft, #f6f9fc);
}

.editor-header-mark span {
    display: block;
    color: var(--ed-muted);
    font-size: 12.5px;
}

.editor-header-mark strong {
    display: block;
    margin: 2px 0 4px;
    color: var(--blue, #123b69);
    font-size: 16px;
}

.editor-record-strip {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) repeat(4, minmax(0, 1fr));
    margin-bottom: 18px;
    background: #fbfcfd;
    border: 1px solid var(--ed-line);
    border-radius: 0 0 var(--ed-radius) var(--ed-radius);
}

.editor-record-item {
    min-width: 0;
    padding: 14px 20px 16px;
}

.editor-record-item + .editor-record-item {
    border-left: 1px solid var(--ed-line);
}

.editor-record-item span {
    display: block;
    margin-bottom: 3px;
    color: var(--ed-muted);
    font-size: 12.5px;
}

.editor-record-item strong {
    display: block;
    overflow-wrap: anywhere;
    color: var(--ed-ink);
    font-size: 14.5px;
    font-weight: 650;
}

.editor-record-item.status strong {
    color: var(--green-dark, #1c6039);
}


/* ---------- Edit session notice ---------- */

.editor-security {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 14px;
    margin-bottom: 22px;
    padding: 14px 18px;
    background: var(--gold-soft, #fffdf6);
    border: 1px solid #ecdcae;
    border-left: 4px solid var(--gold, #c99a2e);
    border-radius: 10px;
}

.editor-security-icon {
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--gold-light, #fff8e5);
    border: 1px solid #ecd9a4;
    color: var(--gold-dark, #9d741d);
    font-weight: 800;
}

.editor-security strong {
    display: block;
    color: var(--ed-ink);
    font-size: 14.5px;
}

.editor-security p {
    margin-top: 2px;
    color: #5b5440;
    font-size: 13.5px;
    line-height: 1.5;
}

.editor-security-timer {
    padding: 6px 12px;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid #ecd9a4;
    color: var(--gold-dark, #9d741d);
    font-size: 13px;
    font-weight: 650;
    white-space: nowrap;
}


/* ---------- Form sections ---------- */

.edit-section {
    margin-bottom: 18px;
    background: #ffffff;
    border: 1px solid var(--ed-line);
    border-radius: var(--ed-radius);
}

.edit-section-header {
    padding: 20px 24px 16px;
    border-bottom: 1px solid #eef1f4;
}

.edit-section-header h2 {
    color: var(--ed-ink);
    font-size: 18px;
    font-weight: 700;
    line-height: 1.3;
}

.edit-section-header p {
    max-width: 75ch;
    margin-top: 4px;
    color: var(--ed-muted);
    font-size: 13.5px;
    line-height: 1.5;
}

.edit-section-body {
    padding: 22px 24px 24px;
}


/* ---------- Checkbox grids ---------- */

.check-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 8px;
}

.check-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    min-height: 44px;
    padding: 11px 12px;
    border: 1px solid var(--ed-line);
    border-radius: 8px;
    background: #ffffff;
    color: var(--ed-ink);
    font-size: 14px;
    line-height: 1.35;
    cursor: pointer;
}

.check-item:hover {
    border-color: #aab6c3;
}

.check-item:has(input:checked) {
    border-color: var(--blue, #123b69);
    background: var(--blue-light, #edf4fb);
}


/* ---------- Other areas of operation ---------- */

.operating-location-notice,
.director-notice {
    margin-bottom: 16px;
    padding: 12px 14px;
    background: var(--blue-soft, #f6f9fc);
    border: 1px solid #dbe5ef;
    border-radius: 8px;
    color: #3e4a57;
    font-size: 13.5px;
    line-height: 1.55;
}

.operating-location-notice strong,
.director-notice .fw-semibold {
    color: var(--ed-ink);
}

.operating-location-card,
.official-card {
    margin-bottom: 12px;
    padding: 18px 20px 20px;
    background: #fbfcfd;
    border: 1px solid var(--ed-line);
    border-radius: 10px;
}

.operating-location-card:last-child,
.official-card:last-child {
    margin-bottom: 0;
}

.operating-location-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}

.operating-location-title,
.official-card > .fw-bold {
    color: var(--ed-ink);
    font-size: 15px;
    font-weight: 700;
}

.operating-location-remove {
    min-height: 34px;
    padding: 0 12px;
    font-size: 13px;
}

.operating-location-note {
    margin-top: 10px;
    color: var(--ed-muted);
    font-size: 12.5px;
    line-height: 1.45;
}

.add-operating-location {
    margin-top: 14px;
}

#beneficiary-groups {
    transition: opacity 0.15s ease;
}


/* ---------- Sticky save bar ---------- */

.editor-action-bar {
    position: sticky;
    bottom: 14px;
    z-index: 30;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-top: 22px;
    padding: 14px 18px;
    background: #ffffff;
    border: 1px solid #d5dce3;
    border-radius: var(--ed-radius);
    box-shadow: 0 8px 24px rgba(21, 40, 59, 0.12);
}

.editor-action-copy strong {
    display: block;
    color: var(--ed-ink);
    font-size: 14.5px;
}

.editor-action-copy span {
    display: block;
    margin-top: 2px;
    color: var(--ed-muted);
    font-size: 13px;
}

.editor-action-buttons {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.editor-save-btn {
    min-width: 150px;
}

.editor-unsaved {
    display: none;
    margin-top: 4px;
    color: var(--red, #b5222a);
    font-size: 13px;
    font-weight: 600;
}

.editor-action-bar.is-dirty .editor-unsaved {
    display: block;
}


/* ---------- Responsive ---------- */

@media (max-width: 1050px) {

    .editor-record-strip {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .editor-record-item:first-child {
        grid-column: 1 / -1;
    }

    .editor-record-item:nth-child(n + 2) {
        border-top: 1px solid var(--ed-line);
    }

    .editor-record-item:nth-child(2n) {
        border-left: 0;
    }
}

@media (max-width: 760px) {

    .submitted-editor .row > .col-md-4,
    .submitted-editor .row > .col-md-6,
    .submitted-editor .row > .col-md-8 {
        grid-column: span 12;
    }

    .editor-page-header {
        display: block;
        padding: 22px 20px 18px;
    }

    .editor-page-header h1 {
        font-size: 25px;
    }

    .editor-header-mark {
        margin-top: 16px;
    }

    .editor-record-strip {
        grid-template-columns: 1fr;
    }

    .editor-record-item + .editor-record-item {
        border-left: 0;
        border-top: 1px solid var(--ed-line);
    }

    .editor-security {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .editor-security-timer {
        grid-column: 1 / -1;
        justify-self: start;
    }

    .edit-section-header,
    .edit-section-body {
        padding-left: 18px;
        padding-right: 18px;
    }

    .editor-action-bar {
        flex-direction: column;
        align-items: stretch;
    }

    .editor-action-buttons > * {
        flex: 1 1 0;
        justify-content: center;
        text-align: center;
    }
}
</style>

<main class="submitted-editor">

    <div class="editor-topbar">

        <div class="editor-topbar-left">

            <a
                href="/pbo-portal/applications/view.php?id=<?= $applicationId ?>"
                class="btn btn-outline-secondary btn-sm"
            >
                &larr; Back to Record
            </a>

            <span class="editor-badge">
                <span class="editor-badge-dot"></span>
                Controlled Administrator Edit
            </span>

        </div>

        <div class="editor-topbar-right">

            <span class="small text-muted">
                Application ID:
                <strong>#<?= $applicationId ?></strong>
            </span>

        </div>

    </div>

    <section class="editor-page-header">

        <div>

            <span class="editor-eyebrow">
                Submitted PBO Record
            </span>

            <h1>
                Edit Submitted Record
            </h1>

            <p>
                Make a controlled correction to the submitted PBO record.
                The record remains <strong>Submitted</strong>; a successful
                save updates the existing record and creates an audit entry.
            </p>

        </div>

        <div class="editor-header-mark">

            <span>Editing authority</span>

            <strong>Administrator</strong>

            <span style="margin-top:8px;">
                Step-up verified
            </span>

        </div>

    </section>

    <section class="editor-record-strip">

        <div class="editor-record-item">

            <span>Organization</span>

            <strong>
                <?= e((string) $application['pbo_name']) ?>
            </strong>

        </div>

        <div class="editor-record-item">

            <span>Registration No.</span>

            <strong>
                <?= e(
                    (string) (
                        $application['pbo_registration_number']
                        ?: '—'
                    )
                ) ?>
            </strong>

        </div>

        <div class="editor-record-item">

            <span>Date of Registration</span>

            <strong>
                <?= e(
                    (string) (
                        $application['registration_date']
                        ?: '—'
                    )
                ) ?>
            </strong>

        </div>

        <div class="editor-record-item">

            <span>Organization Type</span>

            <strong>
                <?= e(
                    ucfirst(
                        (string)
                        $application['registration_type']
                    )
                ) ?>
            </strong>

        </div>

        <div class="editor-record-item status">

            <span>Record Status</span>

            <strong>
                Submitted
            </strong>

        </div>

    </section>

    <section class="editor-security">

        <div class="editor-security-icon">
            !
        </div>

        <div>

            <strong>
                Controlled edit session
            </strong>

            <p>
                Administrator verification is temporary and tied to this
                application. Save only the corrections that have been
                authorized. Passwords are never stored in this form.
            </p>

        </div>

        <div class="editor-security-timer">

            <?php if ($editAuthorizationMinutesRemaining > 0): ?>

                <?= $editAuthorizationMinutesRemaining ?>
                min authorization remaining

            <?php else: ?>

                Authorization active

            <?php endif; ?>

        </div>

    </section>

    <?php if ($errors !== []): ?>

        <div class="alert alert-danger editor-error">

            <div class="fw-bold mb-2">
                The record was not saved.
            </div>

            <ul class="mb-0">

                <?php foreach ($errors as $error): ?>

                    <li>
                        <?= e($error) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>

    <form
        method="post"
        id="submittedEditForm"
        novalidate
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(csrfToken()) ?>"
        >

        <input
            type="hidden"
            name="application_id"
            value="<?= $applicationId ?>"
        >

        <!-- =========================================================
             1. ORGANIZATION
             ========================================================= -->

        <section class="edit-section">

            <div class="edit-section-header">

                <div>

                    <h2>
                        1. Organization & Registration
                    </h2>

                    <p>
                        Core identity, registration number, registration date
                        and organization classification.
                    </p>

                </div>

            </div>

            <div class="edit-section-body">

                <div class="row g-3">

                    <div class="col-md-8">

                        <label class="edit-label">
                            Name of PBO
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            class="form-control"
                            name="pbo_name"
                            value="<?= e($form['pbo_name']) ?>"
                            required
                        >

                        <span class="edit-help">
                            Use the organization name as it should appear
                            on the official PBO record.
                        </span>

                    </div>

                    <div class="col-md-4">

                        <label class="edit-label">
                            Registration Number
                        </label>

                        <input
                            class="form-control"
                            name="pbo_registration_number"
                            value="<?= e(
                                $form['pbo_registration_number']
                            ) ?>"
                        >

                    </div>

                    <div class="col-md-4">

                        <label class="edit-label">
                            Date of Registration
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="date"
                            class="form-control"
                            name="registration_date"
                            value="<?= e(
                                $form['registration_date']
                            ) ?>"
                            min="<?= e(PBO_EARLIEST_REGISTRATION_DATE) ?>"
                            max="<?= e(pboTodayForInput()) ?>"
                            required
                        >

                    </div>

                    <div class="col-md-4">

                        <label class="edit-label">
                            Type of Organization
                            <span class="text-danger">*</span>
                        </label>

                        <select
                            class="form-select"
                            name="registration_type"
                            id="registration_type"
                            required
                        >

                            <option value="">
                                Select
                            </option>

                            <option
                                value="national"
                                <?= $form['registration_type'] === 'national'
                                    ? 'selected'
                                    : '' ?>
                            >
                                National
                            </option>

                            <option
                                value="international"
                                <?= $form['registration_type'] === 'international'
                                    ? 'selected'
                                    : '' ?>
                            >
                                International
                            </option>

                        </select>

                    </div>

                </div>

            </div>

        </section>

        <!-- =========================================================
             WHERE IS THE HEAD OFFICE?

             Asked only of an international organization, and the answer
             decides which of the two cards below it fills in. Before
             this existed, every international record was assumed to have
             a body abroad, and the ones that did not had the overseas
             fields filled with their own Kenyan address to get past the
             page.

             No step number: it is the question that opens step 2, not a
             step of its own.
             ========================================================= -->

        <section
            class="edit-section"
            data-headquarters-question
            style="display: <?= $form['registration_type'] === 'international'
                ? 'block'
                : 'none' ?>;"
        >

            <div class="edit-section-header">

                <div>

                    <h2>
                        <span>Head Office Location</span>
                    </h2>

                    <p>
                        Is the organization's head office in Kenya?
                    </p>

                </div>

            </div>

            <div class="edit-section-body">

                <div
                    class="row g-3"
                    role="radiogroup"
                    aria-label="Is the head office in Kenya?"
                >

                    <div class="col-md-6">

                        <label class="edit-label">

                            <input
                                type="radio"
                                name="headquarters_in_kenya"
                                value="1"
                                <?= $form['headquarters_in_kenya'] === '1'
                                    ? 'checked'
                                    : '' ?>
                            >

                            Yes, in Kenya

                        </label>

                    </div>

                    <div class="col-md-6">

                        <label class="edit-label">

                            <input
                                type="radio"
                                name="headquarters_in_kenya"
                                value="0"
                                <?= $form['headquarters_in_kenya'] === '0'
                                    ? 'checked'
                                    : '' ?>
                            >

                            No, outside Kenya

                        </label>

                    </div>

                    <div class="col-12">

                        <p class="text-muted" style="margin: 0; font-size: 12px;">
                            Many organizations registered as international
                            have their head office here and no body abroad.
                            If that is the case, answer Yes and fill in the
                            Kenyan address as usual.
                        </p>

                    </div>

                </div>

            </div>

        </section>

        <!-- =========================================================
             2. HEADQUARTERS / HEAD OFFICE
             ========================================================= -->

        <section
            class="edit-section"
            data-kenyan-head-office
            style="display: <?= pboUsesOverseasHeadOffice([
                'registration_type' => $form['registration_type'],
                'headquarters_in_kenya' => $form['headquarters_in_kenya'],
            ]) ? 'none' : 'block' ?>;"
        >

            <div class="edit-section-header">

                <div>

                    <h2>
                        2. Headquarters / Head Office
                    </h2>

                    <p>
                        Principal physical location, postal details and
                        organization contact information.
                    </p>

                </div>

            </div>

            <div class="edit-section-body">

                <div class="row g-3">

                    <?php
                    $addressFields = [
                        ['county', 'County'],
                        ['town', 'Town / City'],
                        ['sub_county', 'Sub-County'],
                        ['physical_address', 'Physical Address'],
                        ['postal_address', 'Postal Address'],
                        ['postal_code', 'Postal Code'],
                        ['phone_number', 'Organization Phone Number'],
                        ['alternative_phone_number', 'Alternative Phone Number'],
                        ['email', 'Organization Email'],
                    ];
                    ?>

                    <?php foreach (
                        $addressFields
                        as [$field, $label]
                    ): ?>

                        <div class="col-md-6">

                            <label class="edit-label">

                                <?= e($label) ?>

                                <?php if (
                                    in_array(
                                        $field,
                                        [
                                            'county',
                                            'physical_address',
                                            'postal_address',
                                            'phone_number',
                                            'email',
                                        ],
                                        true
                                    )
                                ): ?>

                                    <span class="text-danger">*</span>

                                <?php endif; ?>

                            </label>

                            <!--
                                The id is what location-suggest.js looks for
                                when attaching the county / sub-county /
                                postal-code suggestion lists.
                            -->
                            <input
                                class="form-control"
                                id="<?= e($field) ?>"
                                name="<?= e($field) ?>"
                                value="<?= e(
                                    $form[$field]
                                ) ?>"
                                <?php
                                /*
                                 * data-kenyan-required lets the script
                                 * drop "required" when the head office is
                                 * abroad and this card is hidden. A hidden
                                 * required field blocks the form with a
                                 * message nobody can see.
                                 */
                                ?>
                                <?= in_array(
                                    $field,
                                    [
                                        'county',
                                        'physical_address',
                                        'postal_address',
                                        'phone_number',
                                        'email',
                                    ],
                                    true
                                )
                                    ? 'required data-kenyan-required="true"'
                                    : '' ?>
                            >

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        </section>

        <!-- =========================================================
             2. HEAD OFFICE OUTSIDE KENYA

             Stands in PLACE of the Kenyan head office card above,
             never alongside it, which is why it carries the same
             step number and sits here rather than at the foot of
             the page.
             ========================================================= -->

        <section
            class="edit-section"
            id="international-section"
            style="display: <?= pboUsesOverseasHeadOffice([
                'registration_type' => $form['registration_type'],
                'headquarters_in_kenya' => $form['headquarters_in_kenya'],
            ]) ? 'block' : 'none' ?>;"
        >

            <div class="edit-section-header">

                <div>

                    <h2>
                        2. <?= e(pboOverseasHeading()) ?>
                    </h2>

                    <p>
                        The organization's own head office in the country it
                        is based in, and the person to contact there. This
                        stands in place of the Kenyan head office above, not
                        alongside it.
                    </p>

                </div>

            </div>

            <div class="edit-section-body">

                <div class="row g-3">

                    <?php
                    /*
                     * Labels from pboOverseasFields(). They used to be
                     * written out here as well as in create.php, review.php
                     * and both exports, which is how "Country of Origin"
                     * survived in some of them after being renamed in
                     * others.
                     */
                    ?>

                    <?php foreach (
                        pboOverseasFields()
                        as $field => $label
                    ): ?>

                        <div class="col-md-6">

                            <label class="edit-label">
                                <?= e($label) ?>
                            </label>

                            <input
                                class="form-control"
                                name="<?= e($field) ?>"
                                value="<?= e(
                                    $form[$field]
                                ) ?>"
                            >

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        </section>

        <!-- =========================================================
             3. OTHER AREAS OF OPERATION
             ========================================================= -->

        <section class="edit-section">

            <div class="edit-section-header">

                <div>

                    <h2>
                        3. Other Areas of Operation
                    </h2>

                    <p>
                        Maintain additional counties, sub-counties and
                        physical locations where the organization operates
                        outside its headquarters.
                    </p>

                </div>

            </div>

            <div class="edit-section-body">

                <div class="operating-location-notice">

                    <strong>
                        Only the county is needed for each area of operation.
                    </strong>

                    Fill in the office details only if the organization has an
                    office in that county. Leave the cards blank if the
                    organization works only from its headquarters.

                </div>

                <div id="operating-location-list">

                    <?php
                    $locationRows =
                        $operatingLocationsForForm;

                    if (
                        count($locationRows) === 0
                    ) {

                        $locationRows[] = [
                            'county' => '',
                            'sub_county' => '',
                            'physical_address' => '',
                            'phone_number' => '',
                            'alternative_phone_number' => '',
                            'email' => '',
                            'postal_address' => '',
                            'postal_code' => '',
                        ];
                    }
                    ?>

                    <?php foreach (
                        $locationRows as $index => $location
                    ): ?>

                        <div class="operating-location-card">

                            <div class="operating-location-heading">

                                <div class="operating-location-title">

                                    <span class="operating-location-number">
                                        Operating Location
                                        <?= $index + 1 ?>
                                    </span>

                                </div>

                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary operating-location-remove"
                                >
                                    Remove
                                </button>

                            </div>

                            <div class="row g-3">

                                <div class="col-md-6">

                                    <label class="edit-label">
                                        County
                                    </label>

                                    <input
                                        class="form-control"
                                        data-location-field="county"
                                        name="operating_locations[<?= $index ?>][county]"
                                        value="<?= e(
                                            (string) (
                                                $location['county'] ?? ''
                                            )
                                        ) ?>"
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label class="edit-label">
                                        Sub-County
                                    </label>

                                    <input
                                        class="form-control"
                                        data-location-field="sub_county"
                                        name="operating_locations[<?= $index ?>][sub_county]"
                                        value="<?= e(
                                            (string) (
                                                $location['sub_county'] ?? ''
                                            )
                                        ) ?>"
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label class="edit-label">
                                        Physical Address
                                    </label>

                                    <input
                                        class="form-control"
                                        data-location-field="physical_address"
                                        name="operating_locations[<?= $index ?>][physical_address]"
                                        value="<?= e(
                                            (string) (
                                                $location['physical_address'] ?? ''
                                            )
                                        ) ?>"
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label class="edit-label">
                                        Postal Address
                                    </label>

                                    <input
                                        class="form-control"
                                        data-location-field="postal_address"
                                        name="operating_locations[<?= $index ?>][postal_address]"
                                        value="<?= e(
                                            (string) (
                                                $location['postal_address'] ?? ''
                                            )
                                        ) ?>"
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label class="edit-label">
                                        Postal Code
                                    </label>

                                    <input
                                        class="form-control"
                                        data-location-field="postal_code"
                                        name="operating_locations[<?= $index ?>][postal_code]"
                                        value="<?= e(
                                            (string) (
                                                $location['postal_code'] ?? ''
                                            )
                                        ) ?>"
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label class="edit-label">
                                        Organization Phone Number
                                    </label>

                                    <input
                                        class="form-control"
                                        data-location-field="phone_number"
                                        name="operating_locations[<?= $index ?>][phone_number]"
                                        value="<?= e(
                                            (string) (
                                                $location['phone_number'] ?? ''
                                            )
                                        ) ?>"
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label class="edit-label">
                                        Alternative Phone Number
                                    </label>

                                    <input
                                        class="form-control"
                                        data-location-field="alternative_phone_number"
                                        name="operating_locations[<?= $index ?>][alternative_phone_number]"
                                        value="<?= e(
                                            (string) (
                                                $location['alternative_phone_number'] ?? ''
                                            )
                                        ) ?>"
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label class="edit-label">
                                        Organization Email
                                    </label>

                                    <input
                                        type="email"
                                        class="form-control"
                                        data-location-field="email"
                                        name="operating_locations[<?= $index ?>][email]"
                                        value="<?= e(
                                            (string) (
                                                $location['email'] ?? ''
                                            )
                                        ) ?>"
                                    >

                                </div>

                            </div>

                            <div class="operating-location-note">
                                County is required. Office details are optional.
                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

                <button
                    type="button"
                    class="btn btn-outline-secondary add-operating-location"
                    id="add-operating-location"
                >
                    + Add Operating Location
                </button>


                <!--
                     Areas of operation OUTSIDE Kenya.

                     Organizations work in Canada, the United States,
                     France. The only place to record where an
                     organization operates was the list of counties above,
                     so none of that was recorded. Inside this section
                     rather than a numbered one of its own, because
                     "Other Areas of Operation" covers both.
                -->

                <hr style="margin: 28px 0 20px;">

                <h3 style="font-size: 15px; margin: 0 0 6px;">
                    Areas of Operation Outside Kenya
                </h3>

                <p class="text-muted" style="font-size: 12px; margin: 0 0 14px;">
                    Does the organization operate in any country outside
                    Kenya? Only the country is needed; office details are
                    optional.
                </p>

                <div
                    class="row g-3"
                    role="radiogroup"
                    aria-label="Does the organization operate outside Kenya?"
                    style="margin-bottom: 14px;"
                >

                    <div class="col-md-6">

                        <label class="edit-label">

                            <input
                                type="radio"
                                name="operates_abroad"
                                value="no"
                                <?= $foreignLocationsForForm === []
                                    ? 'checked'
                                    : '' ?>
                            >

                            No

                        </label>

                    </div>

                    <div class="col-md-6">

                        <label class="edit-label">

                            <input
                                type="radio"
                                name="operates_abroad"
                                value="yes"
                                <?= $foreignLocationsForForm !== []
                                    ? 'checked'
                                    : '' ?>
                            >

                            Yes

                        </label>

                    </div>

                </div>

                <div
                    id="foreign-locations-body"
                    style="display: <?= $foreignLocationsForForm !== []
                        ? 'block'
                        : 'none' ?>;"
                >

                    <div id="foreign-location-cards">

                        <?php foreach (
                            $foreignLocationsForForm as $index => $location
                        ): ?>

                            <div
                                class="operating-location-card"
                                data-foreign-card
                            >

                                <div class="operating-location-head">

                                    <strong>Country <?= (int) $index + 1 ?></strong>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        data-remove-foreign
                                    >
                                        Remove
                                    </button>

                                </div>

                                <div class="row g-3">

                                    <?php foreach (
                                        pboForeignLocationFields()
                                        as $field => $label
                                    ): ?>

                                        <div class="col-md-6">

                                            <label class="edit-label">

                                                <?= e($label) ?>

                                                <?php if ($field === 'country'): ?>
                                                    <span class="text-danger">*</span>
                                                <?php endif; ?>

                                            </label>

                                            <input
                                                class="form-control"
                                                type="<?= $field === 'email'
                                                    ? 'email'
                                                    : 'text' ?>"
                                                name="foreign_locations[<?= (int) $index ?>][<?= e($field) ?>]"
                                                data-foreign-field="<?= e($field) ?>"
                                                value="<?= e((string) ($location[$field] ?? '')) ?>"
                                                <?= $field === 'country'
                                                    ? 'list="country-list"'
                                                    : '' ?>
                                            >

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <button
                        type="button"
                        class="btn btn-outline-secondary"
                        id="add-foreign-location"
                    >
                        + Add a Country
                    </button>

                </div>

            </div>

        </section>


        <!-- =========================================================
             4. OBJECTIVES
             ========================================================= -->

        <section class="edit-section">

            <div class="edit-section-header">

                <div>

                    <h2>
                        4. Public Benefit Purposes
                    </h2>

                    <p>
                        Select the public benefit purposes that form part of
                        the organization's submitted registration record.
                    </p>

                </div>

            </div>

            <div class="edit-section-body">

                <div class="check-grid">

                    <?php foreach (
                        $objectiveOptions
                        as $code => $label
                    ): ?>

                        <label class="check-item">

                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="objectives[]"
                                value="<?= e($code) ?>"
                                <?= in_array(
                                    $code,
                                    $selectedObjectives,
                                    true
                                )
                                    ? 'checked'
                                    : '' ?>
                            >

                            <span>
                                <?= e($label) ?>
                            </span>

                        </label>

                    <?php endforeach; ?>

                </div>

                <div class="mt-3">

                    <label class="edit-label">
                        Any Other — Description
                    </label>

                    <textarea
                        class="form-control"
                        name="objective_other"
                        rows="3"
                    ><?= e($objectiveOther) ?></textarea>

                    <span class="edit-help">
                        Complete this field when “Any Other” is selected.
                    </span>

                </div>

            </div>

        </section>

        <!-- =========================================================
             5. BENEFICIARIES
             ========================================================= -->

        <section class="edit-section">

            <div class="edit-section-header">

                <div>

                    <h2>
                        5. Beneficiaries
                    </h2>

                    <p>
                        Define whether the beneficiaries are the Public or
                        a Section of the Public, then maintain the applicable
                        beneficiary groups.
                    </p>

                </div>

            </div>

            <div class="edit-section-body">

                <div class="row g-3 mb-3">

                    <div class="col-md-6">

                        <label class="edit-label">
                            Beneficiary Category
                            <span class="text-danger">*</span>
                        </label>

                        <select
                            class="form-select"
                            name="beneficiary_scope"
                            id="beneficiary_scope"
                            required
                        >

                            <option value="">
                                Select
                            </option>

                            <option
                                value="public"
                                <?= $beneficiaryScope === 'public'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Public
                            </option>

                            <option
                                value="section_of_public"
                                <?= $beneficiaryScope === 'section_of_public'
                                    ? 'selected'
                                    : '' ?>
                            >
                                Section of the Public
                            </option>

                        </select>

                    </div>

                </div>

                <div id="beneficiary-groups">

                    <div class="check-grid">

                        <?php foreach (
                            $beneficiaryOptions
                            as $code => $label
                        ): ?>

                            <label class="check-item">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="beneficiaries[]"
                                    value="<?= e($code) ?>"
                                    <?= in_array(
                                        $code,
                                        $selectedBeneficiaries,
                                        true
                                    )
                                        ? 'checked'
                                        : '' ?>
                                >

                                <span>
                                    <?= e($label) ?>
                                </span>

                            </label>

                        <?php endforeach; ?>

                    </div>

                    <div class="row g-3 mt-1">

                        <div class="col-md-6">

                            <label class="edit-label">
                                Others — Specify
                            </label>

                            <input
                                class="form-control"
                                name="beneficiary_other_specify"
                                value="<?= e(
                                    $beneficiaryOtherSpecify
                                ) ?>"
                            >

                        </div>

                        <div class="col-md-6">

                            <label class="edit-label">
                                Others — Describe
                            </label>

                            <textarea
                                class="form-control"
                                name="beneficiary_other_description"
                                rows="2"
                            ><?= e(
                                $beneficiaryOtherDescription
                            ) ?></textarea>

                        </div>

                    </div>

                </div>

            </div>

        </section>

        <!-- =========================================================
             6. FINANCIAL YEAR
             ========================================================= -->

        <section class="edit-section">

            <div class="edit-section-header">

                <div>

                    <h2>
                        6. Financial Year
                    </h2>

                    <p>
                        Maintain the recorded financial year start and end
                        months. Both values remain under administrator control.
                    </p>

                </div>

            </div>

            <div class="edit-section-body">

                <div class="row g-3">

                    <div class="col-md-6">

                        <label class="edit-label">
                            Financial Year Start Month
                            <span class="text-danger">*</span>
                        </label>

                        <select
                            class="form-select"
                            name="financial_year_start_month"
                            required
                        >

                            <option value="">
                                Select month
                            </option>

                            <?php foreach (
                                $months as $number => $label
                            ): ?>

                                <option
                                    value="<?= $number ?>"
                                    <?= $startMonth === $number
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($label) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-6">

                        <label class="edit-label">
                            Financial Year End Month
                            <span class="text-danger">*</span>
                        </label>

                        <select
                            class="form-select"
                            name="financial_year_end_month"
                            required
                        >

                            <option value="">
                                Select month
                            </option>

                            <?php foreach (
                                $months as $number => $label
                            ): ?>

                                <option
                                    value="<?= $number ?>"
                                    <?= $endMonth === $number
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e($label) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

            </div>

        </section>

        <!-- =========================================================
             7. OFFICIALS
             ========================================================= -->

        <section class="edit-section">

            <div class="edit-section-header">

                <div>

                    <h2>
                        7. Officials & Directors
                    </h2>

                    <p>
                        Chairperson, Secretary and Treasurer are required.
                        Directors are optional; an entered Director must be
                        complete.
                    </p>

                </div>

            </div>

            <div class="edit-section-body">

                <?php
                $principalOfficials = [
                    'CHAIRPERSON' => 'Chairperson',
                    'SECRETARY' => 'Secretary',
                    'TREASURER' => 'Treasurer',
                ];
                ?>

                <?php foreach (
                    $principalOfficials
                    as $designation => $label
                ): ?>

                    <?php
                    $official =
                        $officials[$designation]
                        ?: [
                            'full_name' => '',
                            'nationality' => '',
                            'id_passport_number' => '',
                            'kra_pin' => '',
                            'phone_number' => '',
                            'email' => '',
                        ];
                    ?>

                    <div class="official-card">

                        <div class="fw-bold mb-3">

                            <?= e($label) ?>

                            <span class="text-danger">
                                *
                            </span>

                        </div>

                        <div class="row g-3">

                            <?php
                            /*
                            | Third element: whether the browser enforces it.
                            | KRA PIN is never required; email is required for
                            | the Chairperson only.
                            */
                            $officialFields = [
                                ['full_name', 'Name', true],
                                ['nationality', 'Nationality', true],
                                ['id_passport_number', 'ID / Passport Number', true],
                                ['kra_pin', 'KRA PIN', officialIsKenyan($official['nationality'] ?? '')],
                                ['tax_id_type', 'Other Tax Identification Type', false],
                                ['tax_id_number', 'Other Tax Identification Number', false],
                                ['phone_number', 'Phone Number', true],
                                ['email', 'Email', false],
                            ];
                            ?>

                            <?php foreach (
                                $officialFields
                                as [$field, $fieldLabel, $fieldRequired]
                            ): ?>

                                <div class="col-md-6">

                                    <label class="edit-label">
                                        <?= e($fieldLabel) ?>
                                        <?php if (!$fieldRequired): ?>
                                            <span class="text-muted fw-normal">
                                                (optional)
                                            </span>
                                        <?php endif; ?>
                                    </label>

                                    <input
                                        class="form-control"
                                        name="officials[<?= e($designation) ?>][<?= e($field) ?>]"
                                        value="<?= e(
                                            (string) (
                                                $official[$field] ?? ''
                                            )
                                        ) ?>"
                                        <?= $fieldRequired ? 'required' : '' ?>
                                    >

                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

                <div
                    class="mt-4 mb-2 fw-bold"
                    style="color:#2f4168;"
                >

                    Additional Directors

                    <span class="text-muted fw-normal">
                        (Optional)
                    </span>

                </div>

                <div class="director-notice">

                    <div class="fw-semibold">
                        Directors are optional.
                    </div>

                    <div class="small text-muted">
                        Zero Directors is valid. If you enter a Director,
                        all Director particulars must be completed before
                        the record can be saved.
                    </div>

                </div>

                <div id="director-list">

                    <?php

                    $directorRows =
                        $officials['DIRECTOR'];

                    if (
                        count($directorRows) === 0
                    ) {

                        $directorRows[] = [
                            'full_name' => '',
                            'nationality' => '',
                            'id_passport_number' => '',
                            'kra_pin' => '',
                            'phone_number' => '',
                            'email' => '',
                        ];
                    }

                    ?>

                    <?php foreach (
                        $directorRows as $index => $director
                    ): ?>

                        <div class="official-card director-card">

                            <div class="fw-bold mb-3">

                                Director
                                <?= $index + 1 ?>

                                <span class="text-muted fw-normal">
                                    (Optional)
                                </span>

                            </div>

                            <div class="row g-3">

                                <?php foreach (
                                    $officialFields
                                    as [$field, $fieldLabel]
                                ): ?>

                                    <div class="col-md-6">

                                        <label class="edit-label">
                                            <?= e($fieldLabel) ?>
                                        </label>

                                        <input
                                            class="form-control"
                                            name="officials[DIRECTOR][<?= $index ?>][<?= e($field) ?>]"
                                            value="<?= e(
                                                (string) (
                                                    $director[$field] ?? ''
                                                )
                                            ) ?>"
                                        >

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    id="add-director"
                >
                    + Add Director
                </button>

            </div>

        </section>

        <div
            class="editor-action-bar"
            id="editor-action-bar"
        >

            <div class="editor-action-copy">

                <strong>
                    Save submitted-record changes
                </strong>

                <span>
                    The record remains Submitted and the successful edit
                    is written to the audit trail.
                </span>

                <div class="editor-unsaved">
                    Unsaved changes are present on this page.
                </div>

            </div>

            <div class="editor-action-buttons">

                <a
                    href="/pbo-portal/applications/view.php?id=<?= $applicationId ?>"
                    class="btn btn-outline-secondary"
                >
                    Cancel
                </a>

                <button
                    type="submit"
                    class="btn btn-primary btn-lg editor-save-btn"
                >
                    Save Changes
                </button>

            </div>

        </div>

    </form>

</main>

<!-- Suggestions for the Country fields. Free text underneath: the list
     will never cover everywhere, and a country it misses must still be
     enterable. -->
<datalist id="country-list">
    <?php foreach (pboCountrySuggestions() as $countryName): ?>
        <option value="<?= e($countryName) ?>"></option>
    <?php endforeach; ?>
</datalist>


<script>
(function () {

    const type =
        document.getElementById('registration_type');

    const internationalSection =
        document.getElementById('international-section');

    document
        .querySelectorAll('input[name="headquarters_in_kenya"]')
        .forEach(function (radio) {
            radio.addEventListener('change', toggleInternational);
        });

    const beneficiaryScope =
        document.getElementById('beneficiary_scope');

    const beneficiaryGroups =
        document.getElementById('beneficiary-groups');

    const directorList =
        document.getElementById('director-list');

    const addDirector =
        document.getElementById('add-director');

    const operatingLocationList =
        document.getElementById('operating-location-list');

    const addOperatingLocation =
        document.getElementById('add-operating-location');

    const submittedEditForm =
        document.getElementById('submittedEditForm');

    const actionBar =
        document.getElementById('editor-action-bar');

    /*
     |--------------------------------------------------------------------------
     | International section
     |--------------------------------------------------------------------------
     */

    /*
     | Which head office card is shown. The server works this out too, in
     | includes/organization-reach.php; this is so the page follows along
     | as the radio is clicked.
     |
     |   national                      the Kenyan card, no question asked
     |   international, in Kenya       the Kenyan card
     |   international, abroad         the overseas card in its place
     |   international, not answered   neither, so the question is the
     |                                 obvious next thing to deal with
     */

    /*
     |--------------------------------------------------------------------------
     | Areas of operation outside Kenya
     |--------------------------------------------------------------------------
     */

    const foreignBody =
        document.getElementById('foreign-locations-body');

    const foreignCards =
        document.getElementById('foreign-location-cards');

    const addForeign =
        document.getElementById('add-foreign-location');

    const foreignFields = <?= json_encode(
        pboForeignLocationFields(),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    ) ?>;

    function renumberForeignCards() {

        if (!foreignCards) {
            return;
        }

        Array.prototype.forEach.call(
            foreignCards.children,
            function (card, index) {

                const heading = card.querySelector('strong');

                if (heading) {
                    heading.textContent = 'Country ' + (index + 1);
                }

                card.querySelectorAll('input').forEach(function (field) {

                    const key = field.getAttribute('data-foreign-field');

                    if (key) {
                        field.name =
                            'foreign_locations[' + index + '][' + key + ']';
                    }
                });
            }
        );
    }

    function addForeignCard() {

        if (!foreignCards) {
            return;
        }

        const card = document.createElement('div');
        card.className = 'operating-location-card';
        card.setAttribute('data-foreign-card', '');

        let html =
            '<div class="operating-location-head">'
            + '<strong>Country</strong>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" '
            + 'data-remove-foreign>Remove</button>'
            + '</div><div class="row g-3">';

        Object.keys(foreignFields).forEach(function (key) {
            html +=
                '<div class="col-md-6"><label class="edit-label">'
                + foreignFields[key]
                + (key === 'country'
                    ? ' <span class="text-danger">*</span>'
                    : '')
                + '</label><input class="form-control" type="'
                + (key === 'email' ? 'email' : 'text')
                + '" data-foreign-field="' + key + '"'
                + (key === 'country' ? ' list="country-list"' : '')
                + '></div>';
        });

        card.innerHTML = html + '</div>';

        foreignCards.appendChild(card);
        renumberForeignCards();
    }

    function updateForeignVisibility() {

        const chosen = document.querySelector(
            'input[name="operates_abroad"]:checked'
        );

        const yes = chosen && chosen.value === 'yes';

        if (foreignBody) {
            foreignBody.style.display = yes ? 'block' : 'none';
        }

        /* Offer an empty card straight away rather than a bare button. */
        if (yes && foreignCards && foreignCards.children.length === 0) {
            addForeignCard();
        }
    }

    if (addForeign) {
        addForeign.addEventListener('click', addForeignCard);
    }

    document.addEventListener('click', function (event) {

        if (!event.target.closest('[data-remove-foreign]')) {
            return;
        }

        const card = event.target.closest('[data-foreign-card]');

        if (card) {
            card.remove();
            renumberForeignCards();
        }
    });

    document
        .querySelectorAll('input[name="operates_abroad"]')
        .forEach(function (radio) {
            radio.addEventListener('change', updateForeignVisibility);
        });

    updateForeignVisibility();


    function headquartersAnswer() {

        const chosen = document.querySelector(
            'input[name="headquarters_in_kenya"]:checked'
        );

        return chosen ? chosen.value : '';
    }

    function toggleInternational() {

        if (!type || !internationalSection) {
            return;
        }

        const international =
            type.value === 'international';

        const answer = headquartersAnswer();

        const abroad = international && answer === '0';
        const showKenyan = !international || answer === '1';

        const question =
            document.querySelector('[data-headquarters-question]');

        if (question) {
            question.style.display = international ? 'block' : 'none';
        }

        const kenyanSection =
            document.querySelector('[data-kenyan-head-office]');

        if (kenyanSection) {
            kenyanSection.style.display = showKenyan ? 'block' : 'none';
        }

        /*
        | A hidden required field blocks the form with a message nobody
        | can see, so required follows visibility.
        */
        document
            .querySelectorAll('[data-kenyan-required="true"]')
            .forEach(function (field) {
                field.required = showKenyan;
            });

        const visible = abroad;

        internationalSection.style.display =
            visible ? 'block' : 'none';
    }

    /*
     |--------------------------------------------------------------------------
     | Beneficiary section
     |--------------------------------------------------------------------------
     */

    function toggleBeneficiaries() {

        if (!beneficiaryScope || !beneficiaryGroups) {
            return;
        }

        const visible =
            beneficiaryScope.value === 'section_of_public';

        beneficiaryGroups.style.display =
            visible ? 'block' : 'none';

        beneficiaryGroups.style.opacity =
            visible ? '1' : '0.72';
    }

    if (type) {

        type.addEventListener(
            'change',
            toggleInternational
        );
    }

    if (beneficiaryScope) {

        beneficiaryScope.addEventListener(
            'change',
            toggleBeneficiaries
        );
    }

    toggleInternational();
    toggleBeneficiaries();

    /*
     |--------------------------------------------------------------------------
     | Other Areas of Operation
     |--------------------------------------------------------------------------
     */

    function refreshOperatingLocationCards() {

        if (!operatingLocationList) {
            return;
        }

        const cards =
            operatingLocationList.querySelectorAll(
                '.operating-location-card'
            );

        cards.forEach(
            function (card, index) {

                const number =
                    card.querySelector(
                        '.operating-location-number'
                    );

                if (number) {

                    number.textContent =
                        'Operating Location ' +
                        (index + 1);
                }

                const fields =
                    card.querySelectorAll(
                        '[data-location-field]'
                    );

                fields.forEach(
                    function (field) {

                        const fieldName =
                            field.getAttribute(
                                'data-location-field'
                            );

                        field.name =
                            'operating_locations[' +
                            index +
                            '][' +
                            fieldName +
                            ']';
                    }
                );
            }
        );
    }

    function createOperatingLocationCard() {

        const card =
            document.createElement('div');

        card.className =
            'operating-location-card';

        card.innerHTML = `
            <div class="operating-location-heading">

                <div class="operating-location-title">

                    <span class="operating-location-number">
                        Operating Location
                    </span>

                </div>

                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary operating-location-remove"
                >
                    Remove
                </button>

            </div>

            <div class="row g-3">

                <div class="col-md-6">

                    <label class="edit-label">
                        County
                    </label>

                    <input
                        class="form-control"
                        data-location-field="county"
                        value=""
                    >

                </div>

                <div class="col-md-6">

                    <label class="edit-label">
                        Sub-County
                    </label>

                    <input
                        class="form-control"
                        data-location-field="sub_county"
                        value=""
                    >

                </div>

                <div class="col-md-6">

                    <label class="edit-label">
                        Physical Address
                    </label>

                    <input
                        class="form-control"
                        data-location-field="physical_address"
                        value=""
                    >

                </div>

                <div class="col-md-6">

                    <label class="edit-label">
                        Postal Address
                    </label>

                    <input
                        class="form-control"
                        data-location-field="postal_address"
                        value=""
                    >

                </div>

                <div class="col-md-6">

                    <label class="edit-label">
                        Postal Code
                    </label>

                    <input
                        class="form-control"
                        data-location-field="postal_code"
                        value=""
                    >

                </div>

                <div class="col-md-6">

                    <label class="edit-label">
                        Organization Phone Number
                    </label>

                    <input
                        class="form-control"
                        data-location-field="phone_number"
                        value=""
                    >

                </div>

                <div class="col-md-6">

                    <label class="edit-label">
                        Alternative Phone Number
                    </label>

                    <input
                        class="form-control"
                        data-location-field="alternative_phone_number"
                        value=""
                    >

                </div>

                <div class="col-md-6">

                    <label class="edit-label">
                        Organization Email
                    </label>

                    <input
                        type="email"
                        class="form-control"
                        data-location-field="email"
                        value=""
                    >

                </div>

            </div>

            <div class="operating-location-note">
                County is required. Office details are optional.
            </div>
        `;

        return card;
    }

    if (
        addOperatingLocation &&
        operatingLocationList
    ) {

        refreshOperatingLocationCards();

        addOperatingLocation.addEventListener(
            'click',
            function () {

                const card =
                    createOperatingLocationCard();

                operatingLocationList.appendChild(
                    card
                );

                refreshOperatingLocationCards();

                if (actionBar) {
                    actionBar.classList.add('is-dirty');
                }
            }
        );

        operatingLocationList.addEventListener(
            'click',
            function (event) {

                const removeButton =
                    event.target.closest(
                        '.operating-location-remove'
                    );

                if (!removeButton) {
                    return;
                }

                const cards =
                    operatingLocationList.querySelectorAll(
                        '.operating-location-card'
                    );

                const card =
                    removeButton.closest(
                        '.operating-location-card'
                    );

                /*
                 * Keep one blank card visible when there is only
                 * one card remaining.
                 */
                if (cards.length === 1) {

                    card.querySelectorAll(
                        'input'
                    ).forEach(
                        function (input) {

                            input.value = '';
                        }
                    );

                } else if (card) {

                    card.remove();
                }

                refreshOperatingLocationCards();

                if (actionBar) {
                    actionBar.classList.add('is-dirty');
                }
            }
        );
    }

    /*
     |--------------------------------------------------------------------------
     | Directors
     |--------------------------------------------------------------------------
     */

    if (
        addDirector &&
        directorList
    ) {

        addDirector.addEventListener(
            'click',
            function () {

                const index =
                    directorList.querySelectorAll(
                        '.director-card'
                    ).length;

                const card =
                    document.createElement('div');

                card.className =
                    'official-card director-card';

                const fields = [
                    ['full_name', 'Name'],
                    ['nationality', 'Nationality'],
                    ['id_passport_number', 'ID / Passport Number'],
                    ['kra_pin', 'KRA PIN'],
                    ['tax_id_type', 'Other Tax Identification Type'],
                    ['tax_id_number', 'Other Tax Identification Number'],
                    ['phone_number', 'Phone Number'],
                    ['email', 'Email']
                ];

                let html = '';

                html +=
                    '<div class="fw-bold mb-3">' +
                    'Director ' +
                    (index + 1) +
                    ' <span class="text-muted fw-normal">' +
                    '(Optional)' +
                    '</span>' +
                    '</div>';

                html +=
                    '<div class="row g-3">';

                fields.forEach(
                    function (field) {

                        html +=
                            '<div class="col-md-6">' +
                            '<label class="edit-label">' +
                            field[1] +
                            '</label>' +
                            '<input ' +
                            'class="form-control" ' +
                            'name="officials[DIRECTOR][' +
                            index +
                            '][' +
                            field[0] +
                            ']" ' +
                            'value="">' +
                            '</div>';
                    }
                );

                html +=
                    '</div>';

                card.innerHTML =
                    html;

                directorList.appendChild(
                    card
                );

                if (actionBar) {
                    actionBar.classList.add('is-dirty');
                }
            }
        );
    }

    /*
     |--------------------------------------------------------------------------
     | Dirty state / submit confirmation
     |--------------------------------------------------------------------------
     */

    if (submittedEditForm) {

        let dirty = false;

        submittedEditForm.addEventListener(
            'input',
            function () {

                dirty = true;

                if (actionBar) {
                    actionBar.classList.add(
                        'is-dirty'
                    );
                }
            }
        );

        submittedEditForm.addEventListener(
            'change',
            function () {

                dirty = true;

                if (actionBar) {
                    actionBar.classList.add(
                        'is-dirty'
                    );
                }
            }
        );

        submittedEditForm.addEventListener(
            'submit',
            function (event) {

                const confirmed =
                    window.confirm(
                        'Save these changes to the submitted PBO record? ' +
                        'The changes will be recorded in the audit trail.'
                    );

                if (!confirmed) {

                    event.preventDefault();
                    return;
                }

                dirty = false;
            }
        );

        window.addEventListener(
            'beforeunload',
            function (event) {

                if (!dirty) {
                    return;
                }

                event.preventDefault();
                event.returnValue = '';
            }
        );
    }

})();
</script>


<!-- ==========================================================
     COUNTY / SUB-COUNTY / POSTAL CODE SUGGESTIONS

     Same data and same script as the create wizard, so a record
     edited here gets the same spellings it was captured with.
     The fields stay free text; this only proposes values.
     ========================================================== -->

<script>
window.KENYA_LOCATIONS = <?= json_encode(
    [
        'counties'    => kenyaCounties($connection),
        'subCounties' => kenyaSubCountyMap($connection),
        'postal'      => kenyaPostalCodes($connection),
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
) ?>;
</script>

<script src="/pbo-portal/assets/js/location-suggest.js"></script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>