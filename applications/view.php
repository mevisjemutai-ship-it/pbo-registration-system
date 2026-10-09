<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
requirePasswordChanged();

$pageTitle  = 'PBO Registration Record';
$activePage = 'applications';

/*
|--------------------------------------------------------------------------
| Database connection
|--------------------------------------------------------------------------
*/

if (isset($pdo) && $pdo instanceof PDO) {
    $db = $pdo;
} elseif (isset($db) && $db instanceof PDO) {
    // Existing connection supplied by database.php.
} else {
    throw new RuntimeException('Database connection is not available.');
}


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function hasValue(mixed $value): bool
{
    return trim((string) $value) !== '';
}

function displayValue(mixed $value): string
{
    return hasValue($value)
        ? e((string) $value)
        : '—';
}

function organizationTypeLabel(string $type): string
{
    return match (strtolower(trim($type))) {
        'national' => 'National Organization',
        'international' => 'International Organization',
        'international_exempt' => 'International Organization — Exempt',
        default => ucwords(
            strtolower(
                str_replace('_', ' ', trim($type))
            )
        ),
    };
}

function designationLabel(string $designation): string
{
    return match (strtoupper(trim($designation))) {
        'CHAIRPERSON' => 'Chairperson',
        'TREASURER'   => 'Treasurer',
        'SECRETARY'   => 'Secretary',
        'DIRECTOR'    => 'Director',
        default       => ucwords(
            strtolower(
                str_replace('_', ' ', trim($designation))
            )
        ),
    };
}

function formatDateValue(
    mixed $value,
    string $format = 'd F Y'
): string {
    if (!hasValue($value)) {
        return '';
    }

    try {
        return (new DateTime((string) $value))->format($format);
    } catch (Throwable) {
        return (string) $value;
    }
}

function signatureExtension(string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    $pathWithoutQuery = explode('?', $path, 2)[0];

    return strtolower(
        pathinfo(
            $pathWithoutQuery,
            PATHINFO_EXTENSION
        )
    );
}


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

if (
    $applicationId === false ||
    $applicationId === null ||
    $applicationId < 1
) {
    http_response_code(400);
    exit('Invalid application ID.');
}


/*
|--------------------------------------------------------------------------
| Labels
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

$objectiveLabels = [
    'EDUCATION' => 'Education',
    'HEALTH' => 'Health',
    'AGRICULTURE' => 'Agriculture',
    'ENVIRONMENT' => 'Environment',
    'GOVERNANCE' => 'Governance',
    'HUMAN_RIGHTS' => 'Human Rights',
    'GENDER' => 'Gender',
    'CHILDREN' => 'Children',
    'YOUTH' => 'Youth',
    'DISABILITY' => 'Persons with Disabilities',
    'ELDERLY' => 'Older Persons',
    'POVERTY_ERADICATION' => 'Poverty Eradication',
    'FOOD_SECURITY' => 'Food Security',
    'WATER_AND_SANITATION' => 'Water and Sanitation',
    'HOUSING' => 'Housing',
    'LIVELIHOODS' => 'Livelihoods',
    'ECONOMIC_EMPOWERMENT' => 'Economic Empowerment',
    'COMMUNITY_DEVELOPMENT' => 'Community Development',
    'SOCIAL_PROTECTION' => 'Social Protection',
    'PEACE_AND_SECURITY' => 'Peace and Security',
    'DISASTER_MANAGEMENT' => 'Disaster Management',
    'RESEARCH' => 'Research',
    'SCIENCE_AND_TECHNOLOGY' => 'Science and Technology',
    'CULTURE_AND_ARTS' => 'Culture and Arts',
    'SPORTS' => 'Sports',
    'RELIGION' => 'Religion',
    'ADVOCACY' => 'Advocacy',
    'OTHER' => 'Other',
];

$beneficiaryLabels = [
    'WOMEN' => 'Women',
    'MEN' => 'Men',
    'CHILDREN' => 'Children',
    'YOUTH' => 'Youth',
    'ELDERLY' => 'Older Persons',
    'PERSONS_WITH_DISABILITIES' => 'Persons with Disabilities',
    'LOW_INCOME_GROUPS' => 'Low-Income Groups',
    'RURAL_COMMUNITIES' => 'Rural Communities',
    'URBAN_COMMUNITIES' => 'Urban Communities',
    'REFUGEES' => 'Refugees',
    'INTERNALLY_DISPLACED_PERSONS' => 'Internally Displaced Persons',
    'FARMERS' => 'Farmers',
    'WORKERS' => 'Workers',
    'STUDENTS' => 'Students',
    'OTHER' => 'Other',
    'PUBLIC' => 'Public',
];


/*
|--------------------------------------------------------------------------
| Application
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare(
    "
    SELECT
        id,
        application_reference,
        pbo_name,
        pbo_registration_number,
        registration_date,
        registration_type,
        status,
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
      AND status = 'submitted'
    LIMIT 1
    "
);

$stmt->execute([
    ':application_id' => $applicationId,
]);

$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    http_response_code(404);
    exit('Submitted application record not found.');
}


/*
|--------------------------------------------------------------------------
| Headquarters address
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare(
    "
    SELECT
        county,
        town,
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

$stmt->execute([
    ':application_id' => $applicationId,
]);

$address = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$address) {
    $address = [
        'county' => '',
        'town' => '',
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
| Other areas of operation
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare(
    "
    SELECT
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
    WHERE application_id = :application_id
    ORDER BY id ASC
    "
);

$stmt->execute([
    ':application_id' => $applicationId,
]);

$operatingLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| International details
|--------------------------------------------------------------------------
*/

$internationalDetails = null;

if (
    (string) $application['registration_type'] ===
    'international'
) {
    $stmt = $db->prepare(
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

    $stmt->execute([
        ':application_id' => $applicationId,
    ]);

    $internationalDetails =
        $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}


/*
|--------------------------------------------------------------------------
| Objectives
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare(
    "
    SELECT
        objective_code,
        description
    FROM application_objectives
    WHERE application_id = :application_id
    ORDER BY id ASC
    "
);

$stmt->execute([
    ':application_id' => $applicationId,
]);

$objectives = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Beneficiaries
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare(
    "
    SELECT
        beneficiary_code,
        description
    FROM application_beneficiaries
    WHERE application_id = :application_id
    ORDER BY id ASC
    "
);

$stmt->execute([
    ':application_id' => $applicationId,
]);

$beneficiaries = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Officials
|--------------------------------------------------------------------------
*/

$stmt = $db->prepare(
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

$stmt->execute([
    ':application_id' => $applicationId,
]);

$officials = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Split officials
|--------------------------------------------------------------------------
*/

$principalOfficials = [
    'CHAIRPERSON' => null,
    'TREASURER'   => null,
    'SECRETARY'   => null,
];

$directors = [];

foreach ($officials as $official) {
    $designation = strtoupper(
        trim(
            (string) (
                $official['designation'] ?? ''
            )
        )
    );

    if (
        array_key_exists(
            $designation,
            $principalOfficials
        )
    ) {
        $principalOfficials[$designation] =
            $official;
    } elseif (
        $designation === 'DIRECTOR'
    ) {
        $directors[] = $official;
    }
}


/*
|--------------------------------------------------------------------------
| Financial year
|--------------------------------------------------------------------------
*/

$financialYearLabel = '';

$startMonth = (int) (
    $application[
        'financial_year_start_month'
    ] ?? 0
);

$endMonth = (int) (
    $application[
        'financial_year_end_month'
    ] ?? 0
);

if (
    isset($months[$startMonth]) &&
    isset($months[$endMonth])
) {
    $financialYearLabel =
        $months[$startMonth]
        . ' to '
        . $months[$endMonth];
}


/*
|--------------------------------------------------------------------------
| Dates
|--------------------------------------------------------------------------
*/

$registrationDate = formatDateValue(
    $application['registration_date'] ?? null
);

$submittedDate = formatDateValue(
    $application['submitted_at'] ?? null,
    'd F Y, H:i'
);


/*
|--------------------------------------------------------------------------
| Shared header
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/header.php';

?>

<style>

/*
|--------------------------------------------------------------------------
| PBO RECORD
|--------------------------------------------------------------------------
*/

.pbo-record {
    --record-navy: #123f67;
    --record-navy-dark: #082d4b;
    --record-blue: #176fa8;
    --record-blue-soft: #edf6fc;

    --record-gold: #d69a18;
    --record-gold-dark: #8d6000;
    --record-gold-soft: #fff7df;

    --record-green: #197149;
    --record-green-dark: #0d5133;
    --record-green-soft: #eaf7ef;

    --record-ink: #172b3a;
    --record-text: #344b5c;
    --record-muted: #71818e;

    --record-border: #d7e1e8;
    --record-soft: #f6f9fb;

    max-width: 1200px;
    margin: 0 auto;
}


/*
|--------------------------------------------------------------------------
| Toolbar
|--------------------------------------------------------------------------
*/

.record-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
}

.record-toolbar-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.record-toolbar .btn {
    white-space: nowrap;
}


/*
|--------------------------------------------------------------------------
| Cover
|--------------------------------------------------------------------------
*/

.record-cover {
    overflow: hidden;
    margin-bottom: 22px;
    border: 1px solid #cbdbe6;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 5px 18px rgba(18,63,103,.08);
}

.record-cover-main {
    position: relative;
    overflow: hidden;
    padding: 34px 38px;
    color: #fff;
    background:
        linear-gradient(
            135deg,
            #082d4b 0%,
            #123f67 48%,
            #176fa8 100%
        );
}

.record-cover-main::after {
    content: '';
    position: absolute;
    width: 250px;
    height: 250px;
    right: -80px;
    top: -130px;
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 50%;
    box-shadow:
        0 0 0 35px rgba(255,255,255,.025),
        0 0 0 70px rgba(255,255,255,.018);
}

.record-authority {
    display: block;
    margin-bottom: 8px;
    color: #e8c76e;
    font-size: 10px;
    font-weight: 900;
    letter-spacing: 1.15px;
    text-transform: uppercase;
}

.record-cover-main h1 {
    position: relative;
    z-index: 1;
    margin: 0 0 7px;
    font-size: 29px;
    font-weight: 900;
    line-height: 1.15;
}

.record-cover-main p {
    position: relative;
    z-index: 1;
    margin: 0;
    color: rgba(255,255,255,.82);
    font-size: 12px;
}

.record-reference {
    position: relative;
    z-index: 1;
    display: inline-block;
    margin-top: 18px;
    padding: 7px 12px;
    border: 1px solid rgba(255,255,255,.22);
    border-radius: 5px;
    background: rgba(255,255,255,.09);
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .3px;
}


/*
|--------------------------------------------------------------------------
| Identity
|--------------------------------------------------------------------------
*/

.record-identity {
    display: grid;
    grid-template-columns: 1.45fr 1fr 1fr;
    border-top: 4px solid var(--record-gold);
}

.record-identity-item {
    position: relative;
    min-width: 0;
    padding: 19px 23px;
    border-right: 1px solid #c7d7e2;
    background: #fff;
}

.record-identity-item:last-child {
    border-right: 0;
}

.record-identity-item span {
    display: block;
    min-height: 17px;
    margin-bottom: 6px;
    color: var(--record-muted);
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .65px;
    text-transform: uppercase;
}

.record-identity-item strong {
    display: block;
    overflow-wrap: anywhere;
    color: var(--record-ink);
    font-size: 13px;
    line-height: 1.45;
}

.record-identity-item:nth-child(1)::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--record-blue);
}

.record-identity-item:nth-child(2) strong {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 5px;
    color: var(--record-navy-dark);
    background: var(--record-blue-soft);
    box-shadow: inset 0 0 0 1px #d5e9f4;
}

.record-identity-item:nth-child(3)::after {
    content: '';
    position: absolute;
    right: 0;
    top: 20px;
    bottom: 20px;
    width: 1px;
    background: #e4ebef;
}


/*
|--------------------------------------------------------------------------
| Sections
|--------------------------------------------------------------------------
*/

.record-section {
    overflow: hidden;
    margin-bottom: 22px;
    border: 1px solid var(--record-border);
    border-radius: 13px;
    background: #fff;
    box-shadow: 0 4px 15px rgba(18,63,103,.055);
}

.record-section-header {
    position: relative;
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 17px 21px;
    border-bottom: 3px solid var(--record-blue);
    background: linear-gradient(
        90deg,
        #f0f7fb,
        #ffffff
    );
}

.record-section-header::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: -3px;
    width: 27%;
    height: 3px;
    background: var(--record-gold);
}

.record-section-number {
    flex: 0 0 34px;
    width: 34px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #d5e7f1;
    border-radius: 50%;
    background: #fff;
    color: var(--record-navy);
    box-shadow: 0 2px 7px rgba(18,63,103,.08);
    font-size: 12px;
    font-weight: 900;
}

.record-section-header h2 {
    margin: 0 0 3px;
    color: var(--record-navy-dark);
    font-size: 16px;
    font-weight: 900;
}

.record-section-header p {
    margin: 0;
    color: var(--record-muted);
    font-size: 10px;
}

.record-section-body {
    padding: 22px;
}


/*
|--------------------------------------------------------------------------
| Detail cards
|--------------------------------------------------------------------------
*/

.detail-card {
    position: relative;
    overflow: hidden;
    border: 1px solid #d5e0e7;
    border-radius: 12px;
    background: linear-gradient(
        180deg,
        #ffffff,
        #fbfcfd
    );
    box-shadow: 0 3px 12px rgba(18,63,103,.055);
}

.detail-card + .detail-card {
    margin-top: 18px;
}

.detail-card-header {
    position: relative;
    padding: 12px 17px 12px 20px;
    border-bottom: 1px solid #cadfea;
    background: linear-gradient(
        90deg,
        #eaf5fb,
        #f7fbfd
    );
    color: var(--record-navy-dark);
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .7px;
    text-transform: uppercase;
}

.detail-card-header::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 5px;
    background: var(--record-blue);
}

.detail-card-header.gold {
    border-bottom-color: #ead79a;
    background: linear-gradient(
        90deg,
        var(--record-gold-soft),
        #fffdf7
    );
    color: var(--record-gold-dark);
}

.detail-card-header.gold::before {
    background: var(--record-gold);
}

.detail-card-body {
    padding: 21px 20px;
}


/*
|--------------------------------------------------------------------------
| Fields
|--------------------------------------------------------------------------
*/

.detail-grid {
    display: grid;
    grid-template-columns: repeat(
        3,
        minmax(0, 1fr)
    );
    gap: 21px 26px;
}

.detail-field {
    min-width: 0;
}

.detail-field.span-2 {
    grid-column: span 2;
}

.detail-field.span-3 {
    grid-column: 1 / -1;
}

.detail-field span {
    display: block;
    margin-bottom: 5px;
    color: var(--record-muted);
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .55px;
    text-transform: uppercase;
}

.detail-field strong {
    display: block;
    overflow-wrap: anywhere;
    color: var(--record-ink);
    font-size: 12px;
    line-height: 1.5;
}

.detail-field.important strong {
    color: var(--record-navy-dark);
    font-size: 13px;
}


/*
|--------------------------------------------------------------------------
| Operating locations
|--------------------------------------------------------------------------
*/

.operation-status {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 17px;
    padding: 12px 15px;
    border: 1px solid #d7e1e8;
    border-radius: 9px;
    font-size: 11px;
    font-weight: 800;
}

.operation-status.yes {
    border-color: #c6e4d3;
    background: var(--record-green-soft);
    color: var(--record-green-dark);
}

.operation-status.no {
    border-color: #d9e1e7;
    background: #f7f9fa;
    color: var(--record-muted);
}

.operation-status-mark {
    width: 24px;
    height: 24px;
    flex: 0 0 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #fff;
    font-size: 12px;
    font-weight: 900;
}

.operation-status.yes .operation-status-mark {
    color: var(--record-green);
    box-shadow: inset 0 0 0 1px #b9dac8;
}

.operation-status.no .operation-status-mark {
    color: var(--record-muted);
    box-shadow: inset 0 0 0 1px #d6dfe5;
}

.operation-location {
    overflow: hidden;
    margin-top: 15px;
    border: 1px solid #d5e0e7;
    border-radius: 10px;
    background: #fff;
}

.operation-location:first-of-type {
    margin-top: 0;
}

.operation-location-header {
    padding: 10px 15px;
    border-bottom: 1px solid #d7e7ef;
    background: linear-gradient(
        90deg,
        #f1f8fc,
        #fbfdfe
    );
    color: var(--record-navy-dark);
    font-size: 9px;
    font-weight: 900;
    letter-spacing: .6px;
    text-transform: uppercase;
}

.operation-location-body {
    padding: 18px;
}


/*
|--------------------------------------------------------------------------
| Selection panels
|--------------------------------------------------------------------------
*/

.selection-panel {
    padding: 9px;
    border: 1px solid #d8e3e9;
    border-radius: 10px;
    background: #f8fbfc;
}

.selection-grid {
    display: grid;
    grid-template-columns: repeat(
        3,
        minmax(0, 1fr)
    );
    gap: 8px;
}

.selection-item {
    min-width: 0;
    min-height: 42px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 11px;
    border: 1px solid #dce7ec;
    border-radius: 7px;
    background: #fff;
}

.selection-mark {
    flex: 0 0 20px;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: var(--record-green);
    color: #fff;
    font-size: 10px;
    font-weight: 900;
}

.selection-item span:last-child {
    min-width: 0;
    color: var(--record-text);
    font-size: 10px;
    font-weight: 700;
    line-height: 1.35;
}

.description-box {
    margin-top: 15px;
    padding: 13px 16px;
    border-left: 4px solid var(--record-gold);
    border-radius: 7px;
    background: var(--record-gold-soft);
}

.description-box span {
    display: block;
    margin-bottom: 6px;
    color: var(--record-gold-dark);
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .6px;
    text-transform: uppercase;
}

.description-box p {
    margin: 0;
    color: var(--record-text);
    font-size: 10px;
    line-height: 1.5;
}

.description-box p + p {
    margin-top: 8px;
}


/*
|--------------------------------------------------------------------------
| Financial year
|--------------------------------------------------------------------------
*/

.financial-year-card {
    padding: 22px;
    border: 1px solid #d5e0e7;
    border-radius: 11px;
    background:
        linear-gradient(
            135deg,
            #f8fbfd,
            #ffffff
        );
    box-shadow: 0 3px 12px rgba(18,63,103,.05);
}

.financial-year-card span {
    display: block;
    margin-bottom: 6px;
    color: var(--record-muted);
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .6px;
    text-transform: uppercase;
}

.financial-year-card strong {
    display: block;
    color: var(--record-navy-dark);
    font-size: 21px;
    line-height: 1.2;
}

.financial-year-badge {
    display: inline-block;
    margin-top: 10px;
    padding: 5px 10px;
    border-radius: 4px;
    background: var(--record-green);
    color: #fff;
    font-size: 8px;
    font-weight: 900;
    letter-spacing: .5px;
    text-transform: uppercase;
}


/*
|--------------------------------------------------------------------------
| Officials
|--------------------------------------------------------------------------
*/

.official-grid {
    display: grid;
    grid-template-columns: repeat(
        3,
        minmax(0, 1fr)
    );
    gap: 15px;
}

.official-card {
    overflow: hidden;
    min-width: 0;
    border: 1px solid #d5e0e7;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 3px 12px rgba(18,63,103,.045);
}

.official-card-header {
    padding: 13px 15px;
    border-left: 4px solid var(--record-blue);
    border-bottom: 1px solid #d9e5eb;
    background: linear-gradient(
        90deg,
        #eef7fb,
        #ffffff
    );
}

.official-card-header.director {
    border-left-color: var(--record-gold);
    background: linear-gradient(
        90deg,
        #fff9e8,
        #ffffff
    );
}

.official-card-header span {
    display: block;
    margin-bottom: 3px;
    color: var(--record-muted);
    font-size: 7px;
    font-weight: 900;
    letter-spacing: .55px;
    text-transform: uppercase;
}

.official-card-header h3 {
    margin: 0;
    color: var(--record-navy-dark);
    font-size: 13px;
    font-weight: 900;
}

.official-card-body {
    padding: 17px;
}

.official-card .detail-grid {
    grid-template-columns: repeat(
        2,
        minmax(0, 1fr)
    );
    gap: 14px 18px;
}


/*
|--------------------------------------------------------------------------
| Directors
|--------------------------------------------------------------------------
*/

.directors-section {
    margin-top: 22px;
    padding-top: 18px;
    border-top: 1px solid #dce6eb;
}

.directors-heading {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 13px;
}

.directors-heading h3 {
    margin: 0;
    color: var(--record-navy-dark);
    font-size: 13px;
    font-weight: 900;
}

.optional-badge {
    display: inline-block;
    padding: 4px 8px;
    border: 1px solid #ead79a;
    border-radius: 4px;
    background: var(--record-gold-soft);
    color: var(--record-gold-dark);
    font-size: 7px;
    font-weight: 900;
    letter-spacing: .45px;
    text-transform: uppercase;
}


/*
|--------------------------------------------------------------------------
| Signatures
|--------------------------------------------------------------------------
*/

.signature-panel {
    margin-top: 15px;
    padding: 9px;
    border: 1px solid #d8e2e8;
    border-radius: 7px;
    background: #f8fafb;
}

.signature-panel-label {
    display: block;
    margin-bottom: 6px;
    color: var(--record-muted);
    font-size: 7px;
    font-weight: 900;
    letter-spacing: .55px;
    text-transform: uppercase;
}

.signature-preview {
    min-height: 65px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 7px;
    border: 1px dashed #c8d5dd;
    border-radius: 5px;
    background: #fff;
}

.signature-preview img {
    display: block;
    max-width: 170px;
    max-height: 60px;
    object-fit: contain;
}

.signature-document {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.signature-document strong {
    display: block;
    color: var(--record-ink);
    font-size: 9px;
}

.signature-document span {
    display: block;
    margin-top: 2px;
    color: var(--record-muted);
    font-size: 7px;
}


/*
|--------------------------------------------------------------------------
| Record footer
|--------------------------------------------------------------------------
*/

.record-footer {
    overflow: hidden;
    margin-top: 24px;
    border: 1px solid #d5e0e7;
    border-radius: 11px;
    background: #fff;
    box-shadow: 0 3px 12px rgba(18,63,103,.045);
}

.record-footer-accent {
    height: 4px;
    background:
        linear-gradient(
            90deg,
            var(--record-navy) 0 45%,
            var(--record-blue) 45% 75%,
            var(--record-gold) 75% 100%
        );
}

.record-footer-body {
    padding: 17px 20px;
}

.record-footer-grid {
    display: grid;
    grid-template-columns: repeat(
        4,
        minmax(0, 1fr)
    );
    gap: 20px;
}

.record-footer-item {
    min-width: 0;
}

.record-footer-item span {
    display: block;
    margin-bottom: 5px;
    color: var(--record-muted);
    font-size: 7px;
    font-weight: 900;
    letter-spacing: .55px;
    text-transform: uppercase;
}

.record-footer-item strong {
    display: block;
    overflow-wrap: anywhere;
    color: var(--record-ink);
    font-size: 10px;
}

.record-footer-item strong.submitted {
    color: var(--record-green);
}

.record-footer-note {
    margin-top: 15px;
    padding-top: 11px;
    border-top: 1px solid #e0e7eb;
    color: var(--record-muted);
    font-size: 8px;
    line-height: 1.45;
}


/*
|--------------------------------------------------------------------------
| Responsive
|--------------------------------------------------------------------------
*/

@media (max-width: 900px) {
    .official-grid {
        grid-template-columns: repeat(
            2,
            minmax(0, 1fr)
        );
    }

    .detail-grid {
        grid-template-columns: repeat(
            2,
            minmax(0, 1fr)
        );
    }

    .selection-grid {
        grid-template-columns: repeat(
            2,
            minmax(0, 1fr)
        );
    }

    .record-footer-grid {
        grid-template-columns: repeat(
            2,
            minmax(0, 1fr)
        );
    }
}


@media (max-width: 760px) {

    .record-identity {
        grid-template-columns: 1fr;
    }

    .record-identity-item {
        padding: 18px 20px;
        border-right: 0;
        border-bottom: 1px solid #c7d7e2;
    }

    .record-identity-item:last-child {
        border-bottom: 0;
    }

    .record-identity-item:nth-child(3)::after {
        display: none;
    }

    .detail-grid {
        grid-template-columns: 1fr;
    }

    .detail-field.span-2,
    .detail-field.span-3 {
        grid-column: auto;
    }

    .official-grid {
        grid-template-columns: 1fr;
    }

    .official-card .detail-grid {
        grid-template-columns: 1fr;
    }

    .selection-grid {
        grid-template-columns: 1fr;
    }

    .record-footer-grid {
        grid-template-columns: repeat(
            2,
            minmax(0, 1fr)
        );
    }

    .record-toolbar {
        align-items: stretch;
        flex-direction: column;
    }

    .record-toolbar-actions {
        width: 100%;
    }

    .record-toolbar-actions .btn {
        flex: 1;
    }

    .record-cover-main {
        padding: 25px 21px;
    }

    .record-section-body {
        padding: 15px;
    }

    .detail-card-body {
        padding: 16px;
    }
}


/*
|--------------------------------------------------------------------------
| Print / PDF
|--------------------------------------------------------------------------
*/

@media print {

    @page {
        size: A4 portrait;
        margin: 7mm 8mm;
    }

    html,
    body {
        width: 100%;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        font-size: 8.5pt !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    header,
    footer,
    nav,
    .sidebar,
    .topbar,
    .record-toolbar,
    .no-print {
        display: none !important;
    }

    .applications-page,
    .pbo-record {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .record-cover {
        margin: 0 0 4mm !important;
        border-radius: 4px !important;
        box-shadow: none !important;
        break-inside: avoid;
    }

    .record-cover-main {
        padding: 5mm 6mm !important;
    }

    .record-cover-main::after {
        display: none !important;
    }

    .record-authority {
        margin-bottom: 1mm !important;
        font-size: 6.5pt !important;
    }

    .record-cover-main h1 {
        margin: 0 0 1mm !important;
        font-size: 16pt !important;
        line-height: 1.1 !important;
    }

    .record-cover-main p {
        font-size: 7pt !important;
    }

    .record-reference {
        margin-top: 2.5mm !important;
        padding: 1.3mm 2.3mm !important;
        font-size: 6.5pt !important;
    }

    .record-identity {
        grid-template-columns: 1.45fr 1fr 1fr !important;
        border-top-width: 3px !important;
    }

    .record-identity-item {
        padding: 3mm 3.5mm !important;
    }

    .record-identity-item span {
        min-height: 0 !important;
        margin-bottom: 1mm !important;
        font-size: 5.5pt !important;
        letter-spacing: .4px !important;
    }

    .record-identity-item strong {
        font-size: 8.5pt !important;
        line-height: 1.2 !important;
    }

    .record-identity-item:nth-child(1)::before {
        width: 2px !important;
    }

    .record-identity-item:nth-child(2) strong {
        padding: 1mm 1.5mm !important;
        font-size: 7.5pt !important;
        border-radius: 2px !important;
        box-shadow: none !important;
    }

    .record-identity-item:nth-child(3)::after {
        display: none !important;
    }

    .record-section {
        margin-bottom: 3mm !important;
        border-radius: 3px !important;
        box-shadow: none !important;
        break-inside: auto !important;
    }

    .record-section-header {
        gap: 2mm !important;
        padding: 2mm 3mm !important;
        border-bottom-width: 2px !important;
        break-after: avoid;
    }

    .record-section-header::after {
        display: none !important;
    }

    .record-section-number {
        flex-basis: 6mm !important;
        width: 6mm !important;
        height: 6mm !important;
        border: 0 !important;
        border-radius: 1.5mm !important;
        box-shadow: none !important;
        font-size: 6pt !important;
    }

    .record-section-header h2 {
        margin: 0 !important;
        font-size: 8.5pt !important;
        line-height: 1.1 !important;
    }

    .record-section-header p {
        display: none !important;
    }

    .record-section-body {
        padding: 2.5mm !important;
        break-inside: auto !important;
    }


    /*
    |--------------------------------------------------------------------------
    | Grouped cards
    |--------------------------------------------------------------------------
    */

    .detail-card {
        border-radius: 2px !important;
        box-shadow: none !important;
        break-inside: avoid;
    }

    .detail-card + .detail-card {
        margin-top: 2mm !important;
    }

    .detail-card-header {
        padding: 1.5mm 2.5mm !important;
        font-size: 5.5pt !important;
        letter-spacing: .35px !important;
        break-after: avoid;
    }

    .detail-card-header::before {
        width: 2px !important;
    }

    .detail-card-body {
        padding: 2.3mm 2.7mm !important;
    }


    /*
    |--------------------------------------------------------------------------
    | Fields
    |--------------------------------------------------------------------------
    */

    .detail-grid {
        grid-template-columns: repeat(
            3,
            minmax(0, 1fr)
        ) !important;
        gap: 2mm 3.5mm !important;
    }

    .detail-field.span-2 {
        grid-column: span 2 !important;
    }

    .detail-field.span-3 {
        grid-column: 1 / -1 !important;
    }

    .detail-field span {
        margin-bottom: .6mm !important;
        font-size: 5pt !important;
        letter-spacing: .25px !important;
    }

    .detail-field strong {
        font-size: 7pt !important;
        line-height: 1.2 !important;
    }

    .detail-field.important strong {
        font-size: 7.5pt !important;
    }


    /*
    |--------------------------------------------------------------------------
    | Other areas of operation
    |--------------------------------------------------------------------------
    */

    .operation-status {
        margin-bottom: 2mm !important;
        padding: 1.5mm 2mm !important;
        border-radius: 2px !important;
        font-size: 6pt !important;
    }

    .operation-status-mark {
        width: 4mm !important;
        height: 4mm !important;
        flex-basis: 4mm !important;
        font-size: 5pt !important;
    }

    .operation-location {
        margin-top: 2mm !important;
        border-radius: 2px !important;
        break-inside: avoid;
    }

    .operation-location-header {
        padding: 1.5mm 2.5mm !important;
        font-size: 5.5pt !important;
    }

    .operation-location-body {
        padding: 2.3mm 2.7mm !important;
    }


    /*
    |--------------------------------------------------------------------------
    | Objectives and beneficiaries
    |--------------------------------------------------------------------------
    */

    .selection-panel {
        padding: .8mm !important;
        border-radius: 2px !important;
    }

    .selection-grid {
        grid-template-columns: repeat(
            3,
            minmax(0, 1fr)
        ) !important;
        gap: .8mm !important;
    }

    .selection-item {
        min-height: 0 !important;
        padding: 1.3mm 1.7mm !important;
        border-radius: 2px !important;
    }

    .selection-mark {
        flex-basis: 3mm !important;
        width: 3mm !important;
        height: 3mm !important;
        font-size: 4.5pt !important;
    }

    .selection-item span:last-child {
        font-size: 6pt !important;
        line-height: 1.2 !important;
    }

    .description-box {
        margin-top: 1.5mm !important;
        padding: 1.7mm 2mm !important;
        border-left-width: 2px !important;
        border-radius: 2px !important;
        break-inside: avoid;
    }

    .description-box span {
        margin-bottom: .5mm !important;
        font-size: 5pt !important;
    }

    .description-box p {
        font-size: 6pt !important;
        line-height: 1.25 !important;
    }


    /*
    |--------------------------------------------------------------------------
    | Financial Year
    |--------------------------------------------------------------------------
    */

    .financial-year-card {
        padding: 2.7mm !important;
        border-radius: 2px !important;
        box-shadow: none !important;
        break-inside: avoid;
    }

    .financial-year-card span {
        margin-bottom: .7mm !important;
        font-size: 5pt !important;
    }

    .financial-year-card strong {
        font-size: 11pt !important;
    }

    .financial-year-badge {
        margin-top: 1.2mm !important;
        padding: .7mm 1.5mm !important;
        font-size: 4.5pt !important;
    }


    /*
    |--------------------------------------------------------------------------
    | Principal officials
    |--------------------------------------------------------------------------
    */

    .official-grid {
        grid-template-columns: repeat(
            3,
            minmax(0, 1fr)
        ) !important;
        gap: 2mm !important;
    }

    .official-card {
        border-radius: 2px !important;
        box-shadow: none !important;
        break-inside: avoid;
    }

    .official-card-header {
        padding: 1.6mm 2mm !important;
        border-left-width: 2px !important;
        break-after: avoid;
    }

    .official-card-header span {
        margin-bottom: .3mm !important;
        font-size: 4.5pt !important;
    }

    .official-card-header h3 {
        font-size: 7.5pt !important;
    }

    .official-card-body {
        padding: 2mm !important;
    }

    .official-card .detail-grid {
        grid-template-columns: 1fr !important;
        gap: 1.2mm !important;
    }

    .official-card .detail-field span {
        margin-bottom: .3mm !important;
        font-size: 4.5pt !important;
    }

    .official-card .detail-field strong {
        font-size: 6pt !important;
        line-height: 1.15 !important;
    }

    .official-card .detail-field.important strong {
        font-size: 6.5pt !important;
    }


    /*
    |--------------------------------------------------------------------------
    | Directors
    |--------------------------------------------------------------------------
    */

    .directors-section {
        margin-top: 2.5mm !important;
        padding-top: 2mm !important;
    }

    .directors-heading {
        margin-bottom: 1.5mm !important;
    }

    .directors-heading h3 {
        font-size: 7.5pt !important;
    }

    .optional-badge {
        padding: .6mm 1.2mm !important;
        font-size: 4.5pt !important;
    }


    /*
    |--------------------------------------------------------------------------
    | Signatures
    |--------------------------------------------------------------------------
    */

    .signature-panel {
        margin-top: 1.5mm !important;
        padding: 1mm !important;
        border-radius: 2px !important;
    }

    .signature-panel-label {
        margin-bottom: .7mm !important;
        font-size: 4.5pt !important;
    }

    .signature-preview {
        min-height: 11mm !important;
        padding: .8mm !important;
        border-radius: 1px !important;
    }

    .signature-preview img {
        max-width: 35mm !important;
        max-height: 11mm !important;
    }

    .signature-document strong {
        font-size: 6pt !important;
    }

    .signature-document span {
        font-size: 5pt !important;
    }


    /*
    |--------------------------------------------------------------------------
    | Record footer
    |--------------------------------------------------------------------------
    */

    .record-footer {
        margin-top: 2.5mm !important;
        border-radius: 2px !important;
        box-shadow: none !important;
        break-inside: avoid;
    }

    .record-footer-accent {
        height: 2px !important;
    }

    .record-footer-body {
        padding: 2mm 2.5mm !important;
    }

    .record-footer-grid {
        grid-template-columns: repeat(
            4,
            minmax(0, 1fr)
        ) !important;
        gap: 2.5mm !important;
    }

    .record-footer-item span {
        margin-bottom: .3mm !important;
        font-size: 4.5pt !important;
    }

    .record-footer-item strong {
        font-size: 6pt !important;
    }

    .record-footer-note {
        margin-top: 1.5mm !important;
        padding-top: 1mm !important;
        font-size: 5pt !important;
        line-height: 1.25 !important;
    }


    /*
    |--------------------------------------------------------------------------
    | Print color
    |--------------------------------------------------------------------------
    */

    .record-cover-main,
    .record-section-header,
    .record-section-number,
    .selection-mark,
    .financial-year-badge,
    .record-footer-accent,
    .record-identity-item:nth-child(2) strong,
    .operation-status.yes {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }


    /*
    |--------------------------------------------------------------------------
    | Page-break strategy
    |--------------------------------------------------------------------------
    */

    .record-section-header,
    .detail-card-header,
    .official-card-header,
    .operation-location-header {
        break-after: avoid;
    }

    .record-section,
    .record-section-body {
        break-inside: auto !important;
    }

    .detail-card,
    .official-card,
    .financial-year-card,
    .description-box,
    .operation-location {
        page-break-inside: avoid;
        break-inside: avoid;
    }

    a {
        color: inherit !important;
        text-decoration: none !important;
    }
}

</style>


<div class="applications-page">

    <div class="pbo-record">


        <!-- ======================================================
             TOOLBAR
             ====================================================== -->

        <div class="record-toolbar no-print">

            <a
                href="/pbo-portal/applications/records.php"
                class="btn btn-secondary"
            >
                &larr; Back to Records
            </a>


            <div class="record-toolbar-actions">

                <a
                    href="/pbo-portal/admin/verify-edit.php?id=<?= (int) $applicationId ?>"
                    class="btn btn-warning"
                >
                    Edit Submitted Record
                </a>

                <a
                    href="/pbo-portal/applications/export-excel.php?id=<?= (int) $applicationId ?>"
                    class="btn btn-success"
                >
                    Export Excel
                </a>

                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="window.print();"
                >
                    Print / Save PDF
                </button>

            </div>

        </div>


        <!-- ======================================================
             PBO RECORD COVER
             ====================================================== -->

        <section class="record-cover">

            <div class="record-cover-main">

                <span class="record-authority">
                    Public Benefit Organizations Regulatory Authority
                </span>

                <h1>
                    PBO Registration Record
                </h1>

                <p>
                    Complete submitted application record
                </p>

                <?php if (
                    hasValue(
                        $application[
                            'application_reference'
                        ] ?? ''
                    )
                ): ?>

                    <div class="record-reference">

                        Application Reference:
                        &nbsp;

                        <?= e(
                            (string)
                            $application[
                                'application_reference'
                            ]
                        ) ?>

                    </div>

                <?php endif; ?>

            </div>


            <div class="record-identity">

                <div class="record-identity-item">

                    <span>
                        Organization
                    </span>

                    <strong>
                        <?= displayValue(
                            $application[
                                'pbo_name'
                            ]
                        ) ?>
                    </strong>

                </div>


                <div class="record-identity-item">

                    <span>
                        Registration Number
                    </span>

                    <strong>
                        <?= displayValue(
                            $application[
                                'pbo_registration_number'
                            ]
                        ) ?>
                    </strong>

                </div>


                <div class="record-identity-item">

                    <span>
                        Date of Registration
                    </span>

                    <strong>
                        <?= $registrationDate !== ''
                            ? e($registrationDate)
                            : '—' ?>
                    </strong>

                </div>

            </div>

        </section>


        <!-- ======================================================
             1. ORGANIZATION DETAILS
             ====================================================== -->

        <section class="record-section">

            <div class="record-section-header">

                <div class="record-section-number">
                    1
                </div>

                <div>

                    <h2>
                        Organization Details
                    </h2>

                    <p>
                        Classification, address and contact particulars
                    </p>

                </div>

            </div>


            <div class="record-section-body">


                <!-- ORGANIZATION CLASSIFICATION -->

                <div class="detail-card">

                    <div class="detail-card-header">
                        Organization Classification
                    </div>

                    <div class="detail-card-body">

                        <div class="detail-grid">

                            <div class="detail-field important">

                                <span>
                                    Type of Organization
                                </span>

                                <strong>
                                    <?= e(
                                        organizationTypeLabel(
                                            (string)
                                            $application[
                                                'registration_type'
                                            ]
                                        )
                                    ) ?>
                                </strong>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- ADDRESS & CONTACT -->

                <div class="detail-card">

                    <div class="detail-card-header">
                        Headquarters Address &amp; Contact
                    </div>

                    <div class="detail-card-body">

                        <div class="detail-grid">

                            <div class="detail-field">

                                <span>
                                    County
                                </span>

                                <strong>
                                    <?= displayValue(
                                        $address['county']
                                    ) ?>
                                </strong>

                            </div>


                            <?php if (
                                hasValue(
                                    $address['town'] ?? ''
                                )
                            ): ?>

                                <div class="detail-field">

                                    <span>
                                        Town / City
                                    </span>

                                    <strong>
                                        <?= e(
                                            (string)
                                            $address['town']
                                        ) ?>
                                    </strong>

                                </div>

                            <?php endif; ?>


                            <?php if (
                                hasValue(
                                    $address['sub_county'] ?? ''
                                )
                            ): ?>

                                <div class="detail-field">

                                    <span>
                                        Sub-County
                                    </span>

                                    <strong>
                                        <?= e(
                                            (string)
                                            $address['sub_county']
                                        ) ?>
                                    </strong>

                                </div>

                            <?php endif; ?>


                            <div class="detail-field span-2">

                                <span>
                                    Physical Address
                                </span>

                                <strong>
                                    <?= displayValue(
                                        $address[
                                            'physical_address'
                                        ]
                                    ) ?>
                                </strong>

                            </div>


                            <div class="detail-field">

                                <span>
                                    Postal Address
                                </span>

                                <strong>
                                    <?= displayValue(
                                        $address[
                                            'postal_address'
                                        ]
                                    ) ?>
                                </strong>

                            </div>


                            <?php if (
                                hasValue(
                                    $address[
                                        'postal_code'
                                    ] ?? ''
                                )
                            ): ?>

                                <div class="detail-field">

                                    <span>
                                        Postal Code
                                    </span>

                                    <strong>
                                        <?= e(
                                            (string)
                                            $address[
                                                'postal_code'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>

                            <?php endif; ?>


                            <div class="detail-field">

                                <span>
                                    Organization Phone
                                </span>

                                <strong>
                                    <?= displayValue(
                                        $address[
                                            'phone_number'
                                        ]
                                    ) ?>
                                </strong>

                            </div>


                            <?php if (
                                hasValue(
                                    $address[
                                        'alternative_phone_number'
                                    ] ?? ''
                                )
                            ): ?>

                                <div class="detail-field">

                                    <span>
                                        Alternative Phone
                                    </span>

                                    <strong>
                                        <?= e(
                                            (string)
                                            $address[
                                                'alternative_phone_number'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>

                            <?php endif; ?>


                            <div class="detail-field span-2">

                                <span>
                                    Organization Email
                                </span>

                                <strong>
                                    <?= displayValue(
                                        $address['email']
                                    ) ?>
                                </strong>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- ==================================================
                     OTHER AREAS OF OPERATION
                     ================================================== -->

                <div class="detail-card">

                    <div class="detail-card-header">
                        Other Areas of Operation
                    </div>

                    <div class="detail-card-body">

                        <?php if (
                            $operatingLocations !== []
                        ): ?>

                            <div class="operation-status yes">

                                <span class="operation-status-mark">
                                    &#10003;
                                </span>

                                <span>
                                    Yes — <?= count(
                                        $operatingLocations
                                    ) ?> additional
                                    operating
                                    <?= count(
                                        $operatingLocations
                                    ) === 1
                                        ? 'location'
                                        : 'locations' ?>
                                    recorded.
                                </span>

                            </div>


                            <?php foreach (
                                $operatingLocations
                                as $index => $location
                            ): ?>

                                <div class="operation-location">

                                    <div class="operation-location-header">
                                        Additional Operating Location
                                        <?= $index + 1 ?>
                                    </div>

                                    <div class="operation-location-body">

                                        <div class="detail-grid">


                                            <div class="detail-field">

                                                <span>
                                                    County
                                                </span>

                                                <strong>
                                                    <?= displayValue(
                                                        $location[
                                                            'county'
                                                        ] ?? ''
                                                    ) ?>
                                                </strong>

                                            </div>


                                            <?php if (
                                                hasValue(
                                                    $location[
                                                        'sub_county'
                                                    ] ?? ''
                                                )
                                            ): ?>

                                                <div class="detail-field">

                                                    <span>
                                                        Sub-County
                                                    </span>

                                                    <strong>
                                                        <?= e(
                                                            (string)
                                                            $location[
                                                                'sub_county'
                                                            ]
                                                        ) ?>
                                                    </strong>

                                                </div>

                                            <?php endif; ?>


                                            <div class="detail-field span-2">

                                                <span>
                                                    Physical Address
                                                </span>

                                                <strong>
                                                    <?= displayValue(
                                                        $location[
                                                            'physical_address'
                                                        ] ?? ''
                                                    ) ?>
                                                </strong>

                                            </div>


                                            <div class="detail-field">

                                                <span>
                                                    Postal Address
                                                </span>

                                                <strong>
                                                    <?= displayValue(
                                                        $location[
                                                            'postal_address'
                                                        ] ?? ''
                                                    ) ?>
                                                </strong>

                                            </div>


                                            <?php if (
                                                hasValue(
                                                    $location[
                                                        'postal_code'
                                                    ] ?? ''
                                                )
                                            ): ?>

                                                <div class="detail-field">

                                                    <span>
                                                        Postal Code
                                                    </span>

                                                    <strong>
                                                        <?= e(
                                                            (string)
                                                            $location[
                                                                'postal_code'
                                                            ]
                                                        ) ?>
                                                    </strong>

                                                </div>

                                            <?php endif; ?>


                                            <div class="detail-field">

                                                <span>
                                                    Phone
                                                </span>

                                                <strong>
                                                    <?= displayValue(
                                                        $location[
                                                            'phone_number'
                                                        ] ?? ''
                                                    ) ?>
                                                </strong>

                                            </div>


                                            <?php if (
                                                hasValue(
                                                    $location[
                                                        'alternative_phone_number'
                                                    ] ?? ''
                                                )
                                            ): ?>

                                                <div class="detail-field">

                                                    <span>
                                                        Alternative Phone
                                                    </span>

                                                    <strong>
                                                        <?= e(
                                                            (string)
                                                            $location[
                                                                'alternative_phone_number'
                                                            ]
                                                        ) ?>
                                                    </strong>

                                                </div>

                                            <?php endif; ?>


                                            <div class="detail-field span-2">

                                                <span>
                                                    Email
                                                </span>

                                                <strong>
                                                    <?= displayValue(
                                                        $location[
                                                            'email'
                                                        ] ?? ''
                                                    ) ?>
                                                </strong>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                            <?php endforeach; ?>


                        <?php else: ?>

                            <div class="operation-status no">

                                <span class="operation-status-mark">
                                    &mdash;
                                </span>

                                <span>
                                    No — no additional operating
                                    locations have been recorded.
                                </span>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- ==================================================
                     INTERNATIONAL ORGANIZATION
                     ================================================== -->

                <?php if (
                    $application[
                        'registration_type'
                    ] === 'international' &&
                    $internationalDetails !== null
                ): ?>

                    <div class="detail-card">

                        <div class="detail-card-header gold">
                            International Registration Details
                        </div>

                        <div class="detail-card-body">

                            <div class="detail-grid">


                                <div class="detail-field important">

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


                                <div class="detail-field">

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


                                <div class="detail-field">

                                    <span>
                                        Parent Registration Number
                                    </span>

                                    <strong>
                                        <?= displayValue(
                                            $internationalDetails[
                                                'registration_number'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>


                                <div class="detail-field">

                                    <span>
                                        Parent Physical Address
                                    </span>

                                    <strong>
                                        <?= displayValue(
                                            $internationalDetails[
                                                'parent_physical_address'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>


                                <div class="detail-field">

                                    <span>
                                        Parent Postal Address
                                    </span>

                                    <strong>
                                        <?= displayValue(
                                            $internationalDetails[
                                                'parent_postal_address'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>


                                <div class="detail-field">

                                    <span>
                                        Parent Postal Code
                                    </span>

                                    <strong>
                                        <?= displayValue(
                                            $internationalDetails[
                                                'parent_postal_code'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>


                                <div class="detail-field">

                                    <span>
                                        Parent Phone
                                    </span>

                                    <strong>
                                        <?= displayValue(
                                            $internationalDetails[
                                                'parent_phone_number'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>


                                <div class="detail-field span-2">

                                    <span>
                                        Parent Email
                                    </span>

                                    <strong>
                                        <?= displayValue(
                                            $internationalDetails[
                                                'parent_email'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>

                            </div>

                        </div>

                    </div>


                    <div class="detail-card">

                        <div class="detail-card-header gold">
                            Parent Organization Contact Person
                        </div>

                        <div class="detail-card-body">

                            <div class="detail-grid">


                                <div class="detail-field">

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


                                <div class="detail-field">

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


                                <div class="detail-field">

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

                        </div>

                    </div>

                <?php endif; ?>

            </div>

        </section>


        <!-- ======================================================
             2. PUBLIC BENEFIT PURPOSES
             ====================================================== -->

        <section class="record-section">

            <div class="record-section-header">

                <div class="record-section-number">
                    2
                </div>

                <div>

                    <h2>
                        Public Benefit Purposes
                    </h2>

                    <p>
                        Registered areas of public benefit activity
                    </p>

                </div>

            </div>


            <div class="record-section-body">

                <?php if (
                    $objectives !== []
                ): ?>

                    <div class="selection-panel">

                        <div class="selection-grid">

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

                                <div class="selection-item">

                                    <span class="selection-mark">
                                        &#10003;
                                    </span>

                                    <span>
                                        <?= e(
                                            $objectiveLabel
                                        ) ?>
                                    </span>

                                </div>

                            <?php endforeach; ?>

                        </div>

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

                            <div class="description-box">

                                <span>
                                    Any Other — Description
                                </span>

                                <p>
                                    <?= e(
                                        (string)
                                        $objective[
                                            'description'
                                        ]
                                    ) ?>
                                </p>

                            </div>

                        <?php endif; ?>

                    <?php endforeach; ?>

                <?php else: ?>

                    <p class="text-muted">
                        No public benefit purposes recorded.
                    </p>

                <?php endif; ?>

            </div>

        </section>


        <!-- ======================================================
             3. BENEFICIARIES
             ====================================================== -->

        <section class="record-section">

            <div class="record-section-header">

                <div class="record-section-number">
                    3
                </div>

                <div>

                    <h2>
                        Beneficiaries
                    </h2>

                    <p>
                        Persons or groups served by the organization
                    </p>

                </div>

            </div>


            <div class="record-section-body">

                <?php

                $isPublic = false;

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
                        $isPublic = true;
                        break;
                    }
                }

                ?>


                <?php if ($isPublic): ?>

                    <div class="detail-card">

                        <div class="detail-card-header">
                            Beneficiary Category
                        </div>

                        <div class="detail-card-body">

                            <div class="detail-field important">

                                <span>
                                    Scope
                                </span>

                                <strong>
                                    Public
                                </strong>

                            </div>

                        </div>

                    </div>


                <?php elseif (
                    $beneficiaries !== []
                ): ?>

                    <div class="detail-card">

                        <div class="detail-card-header">
                            Beneficiary Category
                        </div>

                        <div class="detail-card-body">

                            <div class="detail-field important">

                                <span>
                                    Scope
                                </span>

                                <strong>
                                    Section of the Public
                                </strong>

                            </div>

                        </div>

                    </div>


                    <div
                        class="selection-panel"
                        style="margin-top: 15px;"
                    >

                        <div class="selection-grid">

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

                                <div class="selection-item">

                                    <span class="selection-mark">
                                        &#10003;
                                    </span>

                                    <span>
                                        <?= e(
                                            $beneficiaryLabel
                                        ) ?>
                                    </span>

                                </div>

                            <?php endforeach; ?>

                        </div>

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

                                <div class="description-box">

                                    <span>
                                        Others Not Listed Here
                                    </span>

                                    <?php if (
                                        $otherSpecify !== ''
                                    ): ?>

                                        <p>

                                            <strong>
                                                Specify:
                                            </strong>

                                            <?= e(
                                                $otherSpecify
                                            ) ?>

                                        </p>

                                    <?php endif; ?>


                                    <?php if (
                                        $otherDescription !== ''
                                    ): ?>

                                        <p>

                                            <strong>
                                                Description:
                                            </strong>

                                            <?= e(
                                                $otherDescription
                                            ) ?>

                                        </p>

                                    <?php endif; ?>

                                </div>

                            <?php endif; ?>

                        <?php endif; ?>

                    <?php endforeach; ?>


                <?php else: ?>

                    <p class="text-muted">
                        No beneficiary information recorded.
                    </p>

                <?php endif; ?>

            </div>

        </section>


        <!-- ======================================================
             4. FINANCIAL YEAR
             ====================================================== -->

        <section class="record-section">

            <div class="record-section-header">

                <div class="record-section-number">
                    4
                </div>

                <div>

                    <h2>
                        Financial Year
                    </h2>

                    <p>
                        Approved twelve-month reporting period
                    </p>

                </div>

            </div>


            <div class="record-section-body">

                <?php if (
                    $financialYearLabel !== ''
                ): ?>

                    <div class="financial-year-card">

                        <span>
                            Selected Financial Year
                        </span>

                        <strong>
                            <?= e(
                                $financialYearLabel
                            ) ?>
                        </strong>

                        <div class="financial-year-badge">
                            12 Month Period
                        </div>

                    </div>

                <?php else: ?>

                    <p class="text-muted">
                        Financial year information is not available.
                    </p>

                <?php endif; ?>

            </div>

        </section>


        <!-- ======================================================
             5. PRINCIPAL OFFICIALS
             ====================================================== -->

        <section class="record-section">

            <div class="record-section-header">

                <div class="record-section-number">
                    5
                </div>

                <div>

                    <h2>
                        Principal Officials
                    </h2>

                    <p>
                        Chairperson, Treasurer and Secretary
                    </p>

                </div>

            </div>


            <div class="record-section-body">

                <div class="official-grid">

                    <?php foreach (
                        [
                            'CHAIRPERSON',
                            'TREASURER',
                            'SECRETARY',
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

                        $signature =
                            trim(
                                (string)
                                (
                                    $official[
                                        'signature'
                                    ] ?? ''
                                )
                            );

                        $signatureExt =
                            $signature !== ''
                                ? signatureExtension(
                                    $signature
                                )
                                : '';

                        $imageSignature =
                            in_array(
                                $signatureExt,
                                [
                                    'jpg',
                                    'jpeg',
                                    'png',
                                    'webp',
                                ],
                                true
                            );

                        $pdfSignature =
                            $signatureExt === 'pdf';

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

                                <div class="detail-grid">


                                    <div class="detail-field important">

                                        <span>
                                            Name
                                        </span>

                                        <strong>
                                            <?= displayValue(
                                                $official[
                                                    'full_name'
                                                ]
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="detail-field">

                                        <span>
                                            Nationality
                                        </span>

                                        <strong>
                                            <?= displayValue(
                                                $official[
                                                    'nationality'
                                                ]
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="detail-field">

                                        <span>
                                            ID / Passport Number
                                        </span>

                                        <strong>
                                            <?= displayValue(
                                                $official[
                                                    'id_passport_number'
                                                ]
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="detail-field">

                                        <span>
                                            KRA PIN
                                        </span>

                                        <strong>
                                            <?= displayValue(
                                                $official[
                                                    'kra_pin'
                                                ]
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="detail-field">

                                        <span>
                                            Phone Number
                                        </span>

                                        <strong>
                                            <?= displayValue(
                                                $official[
                                                    'phone_number'
                                                ]
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="detail-field">

                                        <span>
                                            Email
                                        </span>

                                        <strong>
                                            <?= displayValue(
                                                $official[
                                                    'email'
                                                ]
                                            ) ?>
                                        </strong>

                                    </div>

                                </div>


                                <?php if (
                                    $signature !== ''
                                ): ?>

                                    <div class="signature-panel">

                                        <span class="signature-panel-label">
                                            Signature
                                        </span>


                                        <?php if (
                                            $imageSignature
                                        ): ?>

                                            <div class="signature-preview">

                                                <img
                                                    src="<?= e(
                                                        $signature
                                                    ) ?>"
                                                    alt="<?= e(
                                                        (string)
                                                        $official[
                                                            'full_name'
                                                        ]
                                                    ) ?> signature"
                                                    loading="lazy"
                                                >

                                            </div>


                                        <?php elseif (
                                            $pdfSignature
                                        ): ?>

                                            <div class="signature-preview">

                                                <div class="signature-document">

                                                    <div>

                                                        <strong>
                                                            Signature Document
                                                        </strong>

                                                        <span>
                                                            PDF signature file
                                                        </span>

                                                    </div>

                                                    <a
                                                        href="<?= e(
                                                            $signature
                                                        ) ?>"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="btn btn-secondary btn-sm no-print"
                                                    >
                                                        View PDF
                                                    </a>

                                                </div>

                                            </div>


                                        <?php else: ?>

                                            <div class="signature-preview">

                                                <div class="signature-document">

                                                    <div>

                                                        <strong>
                                                            Signature Document
                                                        </strong>

                                                        <span>
                                                            Attached signature file
                                                        </span>

                                                    </div>

                                                    <a
                                                        href="<?= e(
                                                            $signature
                                                        ) ?>"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="btn btn-secondary btn-sm no-print"
                                                    >
                                                        Open File
                                                    </a>

                                                </div>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                <?php endif; ?>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>


                <!-- ==================================================
                     OPTIONAL ADDITIONAL DIRECTORS
                     ================================================== -->

                <?php if (
                    $directors !== []
                ): ?>

                    <div class="directors-section">

                        <div class="directors-heading">

                            <h3>
                                Additional Directors
                            </h3>

                            <span class="optional-badge">
                                Optional
                            </span>

                        </div>


                        <div class="official-grid">

                            <?php foreach (
                                $directors
                                as $index => $director
                            ): ?>

                                <?php

                                $signature =
                                    trim(
                                        (string)
                                        (
                                            $director[
                                                'signature'
                                            ] ?? ''
                                        )
                                    );

                                $signatureExt =
                                    $signature !== ''
                                        ? signatureExtension(
                                            $signature
                                        )
                                        : '';

                                $imageSignature =
                                    in_array(
                                        $signatureExt,
                                        [
                                            'jpg',
                                            'jpeg',
                                            'png',
                                            'webp',
                                        ],
                                        true
                                    );

                                $pdfSignature =
                                    $signatureExt === 'pdf';

                                ?>

                                <article class="official-card">

                                    <div class="official-card-header director">

                                        <span>
                                            Additional Director
                                        </span>

                                        <h3>
                                            Director
                                            <?= $index + 1 ?>
                                        </h3>

                                    </div>


                                    <div class="official-card-body">

                                        <div class="detail-grid">


                                            <div class="detail-field important">

                                                <span>
                                                    Name
                                                </span>

                                                <strong>
                                                    <?= displayValue(
                                                        $director[
                                                            'full_name'
                                                        ]
                                                    ) ?>
                                                </strong>

                                            </div>


                                            <div class="detail-field">

                                                <span>
                                                    Nationality
                                                </span>

                                                <strong>
                                                    <?= displayValue(
                                                        $director[
                                                            'nationality'
                                                        ]
                                                    ) ?>
                                                </strong>

                                            </div>


                                            <div class="detail-field">

                                                <span>
                                                    ID / Passport Number
                                                </span>

                                                <strong>
                                                    <?= displayValue(
                                                        $director[
                                                            'id_passport_number'
                                                        ]
                                                    ) ?>
                                                </strong>

                                            </div>


                                            <div class="detail-field">

                                                <span>
                                                    KRA PIN
                                                </span>

                                                <strong>
                                                    <?= displayValue(
                                                        $director[
                                                            'kra_pin'
                                                        ]
                                                    ) ?>
                                                </strong>

                                            </div>


                                            <div class="detail-field">

                                                <span>
                                                    Phone Number
                                                </span>

                                                <strong>
                                                    <?= displayValue(
                                                        $director[
                                                            'phone_number'
                                                        ]
                                                    ) ?>
                                                </strong>

                                            </div>


                                            <div class="detail-field">

                                                <span>
                                                    Email
                                                </span>

                                                <strong>
                                                    <?= displayValue(
                                                        $director[
                                                            'email'
                                                        ]
                                                    ) ?>
                                                </strong>

                                            </div>

                                        </div>


                                        <?php if (
                                            $signature !== ''
                                        ): ?>

                                            <div class="signature-panel">

                                                <span class="signature-panel-label">
                                                    Signature
                                                </span>


                                                <?php if (
                                                    $imageSignature
                                                ): ?>

                                                    <div class="signature-preview">

                                                        <img
                                                            src="<?= e(
                                                                $signature
                                                            ) ?>"
                                                            alt="<?= e(
                                                                (string)
                                                                $director[
                                                                    'full_name'
                                                                ]
                                                            ) ?> signature"
                                                            loading="lazy"
                                                        >

                                                    </div>


                                                <?php elseif (
                                                    $pdfSignature
                                                ): ?>

                                                    <div class="signature-preview">

                                                        <div class="signature-document">

                                                            <div>

                                                                <strong>
                                                                    Signature Document
                                                                </strong>

                                                                <span>
                                                                    PDF signature file
                                                                </span>

                                                            </div>

                                                            <a
                                                                href="<?= e(
                                                                    $signature
                                                                ) ?>"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                class="btn btn-secondary btn-sm no-print"
                                                            >
                                                                View PDF
                                                            </a>

                                                        </div>

                                                    </div>


                                                <?php else: ?>

                                                    <div class="signature-preview">

                                                        <div class="signature-document">

                                                            <div>

                                                                <strong>
                                                                    Signature Document
                                                                </strong>

                                                                <span>
                                                                    Attached signature file
                                                                </span>

                                                            </div>

                                                            <a
                                                                href="<?= e(
                                                                    $signature
                                                                ) ?>"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                class="btn btn-secondary btn-sm no-print"
                                                            >
                                                                Open File
                                                            </a>

                                                        </div>

                                                    </div>

                                                <?php endif; ?>

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


        <!-- ======================================================
             RECORD INFORMATION
             ====================================================== -->

        <section class="record-footer">

            <div class="record-footer-accent"></div>

            <div class="record-footer-body">

                <div class="record-footer-grid">


                    <div class="record-footer-item">

                        <span>
                            Application Reference
                        </span>

                        <strong>
                            <?= displayValue(
                                $application[
                                    'application_reference'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="record-footer-item">

                        <span>
                            Record Status
                        </span>

                        <strong class="submitted">
                            Submitted
                        </strong>

                    </div>


                    <div class="record-footer-item">

                        <span>
                            Submitted Date
                        </span>

                        <strong>
                            <?= $submittedDate !== ''
                                ? e($submittedDate)
                                : '—' ?>
                        </strong>

                    </div>


                    <div class="record-footer-item">

                        <span>
                            Type of Organization
                        </span>

                        <strong>
                            <?= e(
                                organizationTypeLabel(
                                    (string)
                                    $application[
                                        'registration_type'
                                    ]
                                )
                            ) ?>
                        </strong>

                    </div>

                </div>


                <div class="record-footer-note">

                    This record presents the information captured in the
                    PBO Registration System for this submitted application.

                    Optional information that was not supplied is intentionally
                    omitted from the record.

                </div>

            </div>

        </section>


        <!-- ======================================================
             BOTTOM ACTIONS
             ====================================================== -->

        <div
            class="record-toolbar no-print"
            style="
                margin-top: 20px;
                margin-bottom: 0;
            "
        >

            <a
                href="/pbo-portal/applications/records.php"
                class="btn btn-secondary"
            >
                &larr; Back to Records
            </a>


            <div class="record-toolbar-actions">

                <a
                    href="/pbo-portal/admin/verify-edit.php?id=<?= (int) $applicationId ?>"
                    class="btn btn-warning"
                >
                    Edit Submitted Record
                </a>

                <a
                    href="/pbo-portal/applications/export-excel.php?id=<?= (int) $applicationId ?>"
                    class="btn btn-success"
                >
                    Export Excel
                </a>

                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="window.print();"
                >
                    Print / Save PDF
                </button>

            </div>

        </div>

    </div>
    <!-- END .pbo-record -->

</div>
<!-- END .applications-page -->


<?php

/*
|--------------------------------------------------------------------------
| Shared footer
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/footer.php';

?>