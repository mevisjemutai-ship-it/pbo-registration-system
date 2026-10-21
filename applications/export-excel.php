<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
requirePasswordChanged();

/*
|--------------------------------------------------------------------------
| Database connection
|--------------------------------------------------------------------------
*/

$pdo = $pdo ?? $db ?? null;

if (!$pdo instanceof PDO) {
    http_response_code(500);
    exit('Database connection is not available.');
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

function excelValue(mixed $value): string
{
    $value = trim((string) $value);

    return $value !== ''
        ? $value
        : '—';
}

function organizationTypeLabel(string $type): string
{
    return match ($type) {
        'national' => 'National',
        'international' => 'International',
        'international_exempt' => 'International Exempt',
        default => ucwords(
            strtolower(
                str_replace('_', ' ', $type)
            )
        ),
    };
}

function designationLabel(string $designation): string
{
    $designation = strtoupper(trim($designation));

    return match ($designation) {
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

function formatDateValue(
    mixed $value,
    string $format = 'd F Y'
): string {
    if ($value === null || trim((string) $value) === '') {
        return '';
    }

    try {
        return (new DateTime((string) $value))
            ->format($format);
    } catch (Throwable) {
        return (string) $value;
    }
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

if (!$applicationId || $applicationId < 1) {
    http_response_code(400);
    exit('Invalid application ID.');
}


/*
|--------------------------------------------------------------------------
| Application
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
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
        submitted_at
    FROM pbo_applications
    WHERE id = :application_id
      AND status = 'submitted'
    LIMIT 1
    "
);

$stmt->execute([
    'application_id' => $applicationId,
]);

$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    http_response_code(404);
    exit('Submitted application not found.');
}


/*
|--------------------------------------------------------------------------
| Headquarters address
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "
    SELECT
        county,
        town,
        sub_county,
        physical_address,
        phone_number,
        alternative_phone_number,
        email,
        postal_address,
        postal_code
    FROM application_addresses
    WHERE application_id = :application_id
    LIMIT 1
    "
);

$stmt->execute([
    'application_id' => $applicationId,
]);

$address = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$address) {
    $address = [
        'county' => '',
        'town' => '',
        'sub_county' => '',
        'physical_address' => '',
        'phone_number' => '',
        'alternative_phone_number' => '',
        'email' => '',
        'postal_address' => '',
        'postal_code' => '',
    ];
}


/*
|--------------------------------------------------------------------------
| Additional operating locations
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
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
    'application_id' => $applicationId,
]);

$operatingLocations = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| International details
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
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
    'application_id' => $applicationId,
]);

$internationalDetails = $stmt->fetch(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Objectives
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
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
    'application_id' => $applicationId,
]);

$objectives = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Beneficiaries
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
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
    'application_id' => $applicationId,
]);

$beneficiaries = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Officials
|--------------------------------------------------------------------------
|
| The officials table stores the telephone field as `phone`.
|
| We alias it to `phone_number` so the rest of this export can use
| a consistent name.
|
| We also normalize designation values in PHP. This means values such as:
|
|   CHAIRPERSON
|   Chairperson
|   chairperson
|
| are all treated as the same designation.
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
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
        CASE UPPER(TRIM(designation))
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
    'application_id' => $applicationId,
]);

$officials = $stmt->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Organize officials by designation
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

    /*
     * Normalize the record before storing it.
     */
    $official['designation'] = $designation;

    if (
        $designation === 'CHAIRPERSON'
        || $designation === 'TREASURER'
        || $designation === 'SECRETARY'
    ) {
        $principalOfficials[$designation] = $official;

    } elseif ($designation === 'DIRECTOR') {

        $directors[] = $official;
    }
}


/*
|--------------------------------------------------------------------------
| Labels
|--------------------------------------------------------------------------
*/

$objectiveLabels = [
    'HEALTH' => 'Health',
    'EDUCATION' => 'Education',
    'AGRICULTURE' => 'Agriculture',
    'FOOD_SECURITY' => 'Food Security',
    'WATER_SANITATION' => 'Water, Sanitation and Hygiene',
    'ENVIRONMENT' => 'Environment',
    'CLIMATE_CHANGE' => 'Climate Change',
    'GOVERNANCE' => 'Governance',
    'HUMAN_RIGHTS' => 'Human Rights',
    'GENDER' => 'Gender Equality',
    'CHILDREN' => 'Children',
    'YOUTH' => 'Youth',
    'PERSONS_WITH_DISABILITIES' => 'Persons with Disabilities',
    'ELDERLY' => 'Older Persons',
    'POVERTY_ERADICATION' => 'Poverty Eradication',
    'LIVELIHOODS' => 'Livelihoods',
    'HOUSING' => 'Housing',
    'SOCIAL_PROTECTION' => 'Social Protection',
    'PEACE_AND_SECURITY' => 'Peace and Security',
    'DEMOCRACY' => 'Democracy',
    'LEGAL_AID' => 'Legal Aid',
    'CULTURE' => 'Culture',
    'SPORTS' => 'Sports',
    'SCIENCE_AND_TECHNOLOGY' => 'Science and Technology',
    'RESEARCH' => 'Research',
    'DISASTER_MANAGEMENT' => 'Disaster Management',
    'COMMUNITY_DEVELOPMENT' => 'Community Development',
    'OTHER' => 'Other',
];

$beneficiaryLabels = [
    'CHILDREN' => 'Children',
    'YOUTH' => 'Youth',
    'WOMEN' => 'Women',
    'MEN' => 'Men',
    'PERSONS_WITH_DISABILITIES' => 'Persons with Disabilities',
    'OLDER_PERSONS' => 'Older Persons',
    'REFUGEES' => 'Refugees',
    'INTERNALLY_DISPLACED_PERSONS' => 'Internally Displaced Persons',
    'LOW_INCOME_GROUPS' => 'Low-Income Groups',
    'FARMERS' => 'Farmers',
    'COMMUNITIES' => 'Communities',
    'STUDENTS' => 'Students',
    'PATIENTS' => 'Patients',
    'MINORITY_GROUPS' => 'Minority Groups',
    'OTHER' => 'Other',
];


/*
|--------------------------------------------------------------------------
| Financial year
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

$financialYearLabel = '';

$startMonth = (int) (
    $application['financial_year_start_month'] ?? 0
);

$endMonth = (int) (
    $application['financial_year_end_month'] ?? 0
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
| Beneficiary "Other"
|--------------------------------------------------------------------------
*/

$beneficiaryOtherSpecify = '';
$beneficiaryOtherDescription = '';

foreach ($beneficiaries as $beneficiary) {

    if (
        (string) $beneficiary['beneficiary_code']
        !== 'OTHER'
    ) {
        continue;
    }

    $storedOther = trim(
        (string) (
            $beneficiary['description'] ?? ''
        )
    );

    if ($storedOther === '') {
        continue;
    }

    $decodedOther = json_decode(
        $storedOther,
        true
    );

    if (is_array($decodedOther)) {

        $beneficiaryOtherSpecify = trim(
            (string) (
                $decodedOther['specify'] ?? ''
            )
        );

        $beneficiaryOtherDescription = trim(
            (string) (
                $decodedOther['description'] ?? ''
            )
        );

    } else {

        $beneficiaryOtherDescription =
            $storedOther;
    }
}


/*
|--------------------------------------------------------------------------
| Excel-compatible HTML workbook
|--------------------------------------------------------------------------
*/

$filename =
    'PBO_Registration_Record_'
    . preg_replace(
        '/[^A-Za-z0-9_-]+/',
        '_',
        (string) (
            $application['application_reference']
            ?? $applicationId
        )
    )
    . '.xls';

header(
    'Content-Type: application/vnd.ms-excel; charset=UTF-8'
);

header(
    'Content-Disposition: attachment; filename="' .
    $filename .
    '"'
);

header('Cache-Control: max-age=0');

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>
PBO Registration Record
</title>

<style>

@page {
    size: A4 portrait;
    margin: 0.45in;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 10pt;
    color: #172b3a;
    margin: 0;
    padding: 0;
}

table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

td,
th {
    border: 1px solid #d7e1e8;
    padding: 7px 8px;
    vertical-align: top;
    word-wrap: break-word;
}

.cover {
    background: #123f67;
    color: #ffffff;
    border: 0;
}

.cover td {
    border: 0;
    padding: 18px 20px;
}

.cover-authority {
    font-size: 9pt;
    font-weight: bold;
}

.cover-title {
    font-size: 20pt;
    font-weight: bold;
    margin-top: 5px;
}

.cover-subtitle {
    font-size: 9pt;
    margin-top: 5px;
}

.reference {
    background: #082d4b;
    color: #ffffff;
    font-weight: bold;
    padding: 7px 10px;
    display: inline-block;
    margin-top: 10px;
}

.identity {
    margin-top: 0;
}

.identity td {
    border-top: 3px solid #d69a18;
    background: #f6f9fb;
}

.identity-label {
    display: block;
    font-size: 8pt;
    color: #71818e;
    font-weight: bold;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.identity-value {
    font-size: 11pt;
    font-weight: bold;
}

.section {
    margin-top: 16px;
}

.section-title {
    background: #123f67;
    color: #ffffff;
    font-size: 12pt;
    font-weight: bold;
    padding: 8px 10px;
}

.section-subtitle {
    display: block;
    font-size: 8pt;
    font-weight: normal;
    margin-top: 3px;
}

.card-title {
    background: #eaf5fb;
    color: #082d4b;
    font-weight: bold;
    text-transform: uppercase;
    font-size: 8pt;
}

.card-title-gold {
    background: #fff7df;
    color: #8d6000;
}

.label {
    color: #71818e;
    font-size: 7pt;
    font-weight: bold;
    text-transform: uppercase;
}

.value {
    font-size: 9pt;
    font-weight: bold;
}

.section-gap {
    height: 10px;
    border: 0;
}

.selected {
    background: #eaf7ef;
    border: 1px solid #b7ddc5;
}

.check {
    font-weight: bold;
    color: #197149;
}

.footer {
    margin-top: 18px;
}

.footer-accent {
    background: #d69a18;
    height: 4px;
}

.footer-note {
    font-size: 8pt;
    color: #71818e;
}

.no-border {
    border: 0 !important;
}

.small {
    font-size: 8pt;
}

.signature-note {
    color: #71818e;
    font-style: italic;
}

.official-present {
    background: #f8fbfd;
}

.signature-present {
    color: #197149;
    font-weight: bold;
}

@media print {

    .section {
        break-inside: auto;
    }

    tr,
    .card {
        break-inside: avoid;
    }

}

</style>

</head>

<body>

<!-- ==========================================================
     COVER
     ========================================================== -->

<table class="cover">

<tr>

<td>

<div class="cover-authority">
Public Benefit Organizations Regulatory Authority
</div>

<div class="cover-title">
PBO Registration Record
</div>

<div class="cover-subtitle">
Complete submitted application record
</div>

<div class="reference">
Application Reference:
<?= e((string) $application['application_reference']) ?>
</div>

</td>

</tr>

</table>


<!-- ==========================================================
     IDENTITY
     ========================================================== -->

<table class="identity">

<tr>

<td width="45%">

<span class="identity-label">
Organization
</span>

<span class="identity-value">
<?= e((string) $application['pbo_name']) ?>
</span>

</td>

<td width="25%">

<span class="identity-label">
Registration Number
</span>

<span class="identity-value">
<?= excelValue($application['pbo_registration_number']) ?>
</span>

</td>

<td width="30%">

<span class="identity-label">
Date of Registration
</span>

<span class="identity-value">
<?= excelValue(
    formatDateValue(
        $application['registration_date']
    )
) ?>
</span>

</td>

</tr>

</table>


<!-- ==========================================================
     1. ORGANIZATION DETAILS
     ========================================================== -->

<table class="section">

<tr>

<td class="section-title">
1. Organization Details
<span class="section-subtitle">
Classification, headquarters address and contact particulars
</span>
</td>

</tr>

</table>


<table>

<tr>
<td colspan="3" class="card-title">
Organization Classification
</td>
</tr>

<tr>

<td colspan="3">

<div class="label">
Type of Organization
</div>

<div class="value">
<?= e(
    organizationTypeLabel(
        (string) $application['registration_type']
    )
) ?>
</div>

</td>

</tr>

</table>


<table class="section-gap">
<tr><td class="no-border"></td></tr>
</table>


<table>

<tr>
<td colspan="3" class="card-title">
Headquarters Address &amp; Contact
</td>
</tr>

<tr>

<td>

<div class="label">
County
</div>

<div class="value">
<?= excelValue($address['county']) ?>
</div>

</td>

<td>

<div class="label">
Town / City
</div>

<div class="value">
<?= excelValue($address['town']) ?>
</div>

</td>

<td>

<div class="label">
Sub-County
</div>

<div class="value">
<?= excelValue($address['sub_county']) ?>
</div>

</td>

</tr>

<tr>

<td colspan="3">

<div class="label">
Physical Address
</div>

<div class="value">
<?= excelValue($address['physical_address']) ?>
</div>

</td>

</tr>

<tr>

<td>

<div class="label">
Postal Address
</div>

<div class="value">
<?= excelValue($address['postal_address']) ?>
</div>

</td>

<td>

<div class="label">
Postal Code
</div>

<div class="value">
<?= excelValue($address['postal_code']) ?>
</div>

</td>

<td>

<div class="label">
Organization Phone
</div>

<div class="value">
<?= excelValue($address['phone_number']) ?>
</div>

</td>

</tr>

<tr>

<td>

<div class="label">
Alternative Phone
</div>

<div class="value">
<?= excelValue($address['alternative_phone_number']) ?>
</div>

</td>

<td colspan="2">

<div class="label">
Organization Email
</div>

<div class="value">
<?= excelValue($address['email']) ?>
</div>

</td>

</tr>

</table>


<!-- ==========================================================
     OTHER AREAS OF OPERATION
     ========================================================== -->

<table class="section-gap">
<tr><td class="no-border"></td></tr>
</table>

<table>

<tr>

<td colspan="3" class="card-title">

Other Areas of Operation

</td>

</tr>

<tr>

<td colspan="3">

<div class="label">
Additional Operating Locations
</div>

<div class="value">

<?= $operatingLocations !== []
    ? 'Yes — '
        . count($operatingLocations)
        . ' additional operating location(s)'
    : 'No — No additional operating locations recorded.' ?>

</div>

</td>

</tr>

<?php foreach (
    $operatingLocations
    as $index => $location
): ?>

<tr>

<td colspan="3" class="card-title">

Operating Location <?= $index + 1 ?>

</td>

</tr>

<tr>

<td>

<div class="label">
County
</div>

<div class="value">
<?= excelValue($location['county']) ?>
</div>

</td>

<td>

<div class="label">
Sub-County
</div>

<div class="value">
<?= excelValue($location['sub_county']) ?>
</div>

</td>

<td>

<div class="label">
Postal Code
</div>

<div class="value">
<?= excelValue($location['postal_code']) ?>
</div>

</td>

</tr>

<tr>

<td colspan="3">

<div class="label">
Physical Address
</div>

<div class="value">
<?= excelValue($location['physical_address']) ?>
</div>

</td>

</tr>

<tr>

<td>

<div class="label">
Postal Address
</div>

<div class="value">
<?= excelValue($location['postal_address']) ?>
</div>

</td>

<td>

<div class="label">
Phone
</div>

<div class="value">
<?= excelValue($location['phone_number']) ?>
</div>

</td>

<td>

<div class="label">
Alternative Phone
</div>

<div class="value">
<?= excelValue($location['alternative_phone_number']) ?>
</div>

</td>

</tr>

<tr>

<td colspan="3">

<div class="label">
Email
</div>

<div class="value">
<?= excelValue($location['email']) ?>
</div>

</td>

</tr>

<?php endforeach; ?>

</table>


<?php if (
    (string) $application['registration_type']
    === 'international'
    && $internationalDetails !== false
    && $internationalDetails !== null
): ?>

<!-- ==========================================================
     INTERNATIONAL
     ========================================================== -->

<table class="section-gap">
<tr><td class="no-border"></td></tr>
</table>

<table>

<tr>

<td colspan="3"
    class="card-title card-title-gold">

International Registration Details

</td>

</tr>

<tr>

<td>

<div class="label">
Parent Organization
</div>

<div class="value">
<?= excelValue(
    $internationalDetails[
        'parent_organization_name'
    ]
) ?>
</div>

</td>

<td>

<div class="label">
Country of Registration
</div>

<div class="value">
<?= excelValue(
    $internationalDetails[
        'country_of_origin'
    ]
) ?>
</div>

</td>

<td>

<div class="label">
Parent Registration Number
</div>

<div class="value">
<?= excelValue(
    $internationalDetails[
        'registration_number'
    ]
) ?>
</div>

</td>

</tr>

<tr>

<td>

<div class="label">
Parent Physical Address
</div>

<div class="value">
<?= excelValue(
    $internationalDetails[
        'parent_physical_address'
    ]
) ?>
</div>

</td>

<td>

<div class="label">
Parent Postal Address
</div>

<div class="value">
<?= excelValue(
    $internationalDetails[
        'parent_postal_address'
    ]
) ?>
</div>

</td>

<td>

<div class="label">
Parent Postal Code
</div>

<div class="value">
<?= excelValue(
    $internationalDetails[
        'parent_postal_code'
    ]
) ?>
</div>

</td>

</tr>

<tr>

<td>

<div class="label">
Parent Phone
</div>

<div class="value">
<?= excelValue(
    $internationalDetails[
        'parent_phone_number'
    ]
) ?>
</div>

</td>

<td colspan="2">

<div class="label">
Parent Email
</div>

<div class="value">
<?= excelValue(
    $internationalDetails[
        'parent_email'
    ]
) ?>
</div>

</td>

</tr>

</table>


<table class="section-gap">
<tr><td class="no-border"></td></tr>
</table>

<table>

<tr>

<td colspan="3"
    class="card-title card-title-gold">

Parent Organization Contact Person

</td>

</tr>

<tr>

<td>

<div class="label">
Contact Person
</div>

<div class="value">
<?= excelValue(
    $internationalDetails[
        'contact_person_name'
    ]
) ?>
</div>

</td>

<td>

<div class="label">
Phone
</div>

<div class="value">
<?= excelValue(
    $internationalDetails[
        'contact_person_phone'
    ]
) ?>
</div>

</td>

<td>

<div class="label">
Email
</div>

<div class="value">
<?= excelValue(
    $internationalDetails[
        'contact_person_email'
    ]
) ?>
</div>

</td>

</tr>

</table>

<?php endif; ?>


<!-- ==========================================================
     2. OBJECTIVES
     ========================================================== -->

<table class="section">

<tr>

<td class="section-title">

2. Public Benefit Purposes

<span class="section-subtitle">
Registered areas of public benefit activity
</span>

</td>

</tr>

</table>


<table>

<tr>
<td class="card-title">
Selected Public Benefit Purposes
</td>
</tr>

<?php if ($objectives !== []): ?>

<?php foreach ($objectives as $objective): ?>

<?php

$code = (string) (
    $objective['objective_code'] ?? ''
);

$label =
    $objectiveLabels[$code]
    ?? ucwords(
        strtolower(
            str_replace(
                '_',
                ' ',
                $code
            )
        )
    );

?>

<tr>

<td class="selected">

<span class="check">
✓
</span>

<?= e($label) ?>

</td>

</tr>

<?php endforeach; ?>

<?php else: ?>

<tr>

<td>
No public benefit purposes recorded.
</td>

</tr>

<?php endif; ?>

</table>


<?php foreach ($objectives as $objective): ?>

<?php if (
    (string) $objective['objective_code']
    === 'OTHER'
    && trim(
        (string) (
            $objective['description'] ?? ''
        )
    ) !== ''
): ?>

<table class="section-gap">
<tr><td class="no-border"></td></tr>
</table>

<table>

<tr>

<td class="card-title">
Any Other — Description
</td>

</tr>

<tr>

<td>
<?= e(
    (string) $objective['description']
) ?>
</td>

</tr>

</table>

<?php endif; ?>

<?php endforeach; ?>


<!-- ==========================================================
     3. BENEFICIARIES
     ========================================================== -->

<table class="section">

<tr>

<td class="section-title">

3. Beneficiaries

<span class="section-subtitle">
Persons or groups served by the organization
</span>

</td>

</tr>

</table>


<?php

$isPublic = false;

foreach ($beneficiaries as $beneficiary) {

    if (
        (string) $beneficiary['beneficiary_code']
        === 'PUBLIC'
    ) {
        $isPublic = true;
        break;
    }
}

?>


<table>

<tr>

<td class="card-title">
Beneficiary Category
</td>

</tr>

<tr>

<td>

<div class="label">
Scope
</div>

<div class="value">

<?= $isPublic
    ? 'Public'
    : (
        $beneficiaries !== []
            ? 'Section of the Public'
            : 'Not Recorded'
    ) ?>

</div>

</td>

</tr>

</table>


<?php if (
    !$isPublic
    && $beneficiaries !== []
): ?>

<table class="section-gap">
<tr><td class="no-border"></td></tr>
</table>

<table>

<tr>

<td class="card-title">
Selected Beneficiary Categories
</td>

</tr>

<?php foreach ($beneficiaries as $beneficiary): ?>

<?php

$code = (string) (
    $beneficiary['beneficiary_code']
    ?? ''
);

if ($code === 'PUBLIC') {
    continue;
}

$label =
    $beneficiaryLabels[$code]
    ?? ucwords(
        strtolower(
            str_replace(
                '_',
                ' ',
                $code
            )
        )
    );

?>

<tr>

<td class="selected">

<span class="check">
✓
</span>

<?= e($label) ?>

</td>

</tr>

<?php endforeach; ?>

</table>

<?php endif; ?>


<?php if (
    $beneficiaryOtherSpecify !== ''
    || $beneficiaryOtherDescription !== ''
): ?>

<table class="section-gap">
<tr><td class="no-border"></td></tr>
</table>

<table>

<tr>

<td class="card-title">
Others Not Listed Here
</td>

</tr>

<?php if ($beneficiaryOtherSpecify !== ''): ?>

<tr>

<td>

<div class="label">
Specify
</div>

<div class="value">
<?= e($beneficiaryOtherSpecify) ?>
</div>

</td>

</tr>

<?php endif; ?>

<?php if (
    $beneficiaryOtherDescription !== ''
): ?>

<tr>

<td>

<div class="label">
Description
</div>

<div class="value">
<?= e($beneficiaryOtherDescription) ?>
</div>

</td>

</tr>

<?php endif; ?>

</table>

<?php endif; ?>


<!-- ==========================================================
     4. FINANCIAL YEAR
     ========================================================== -->

<table class="section">

<tr>

<td class="section-title">

4. Financial Year

<span class="section-subtitle">
Approved twelve-month reporting period
</span>

</td>

</tr>

</table>


<table>

<tr>

<td class="card-title">
Selected Financial Year
</td>

</tr>

<tr>

<td>

<div class="value">

<?= $financialYearLabel !== ''
    ? e($financialYearLabel)
    : 'Financial year information is not available.' ?>

</div>

<?php if ($financialYearLabel !== ''): ?>

<div class="small">
12 Month Period
</div>

<?php endif; ?>

</td>

</tr>

</table>


<!-- ==========================================================
     5. PRINCIPAL OFFICIALS
     ========================================================== -->

<table class="section">

<tr>

<td class="section-title">

5. Principal Officials

<span class="section-subtitle">
Chairperson, Secretary and Treasurer
</span>

</td>

</tr>

</table>


<?php foreach (
    [
        'CHAIRPERSON',
        'SECRETARY',
        'TREASURER',
    ] as $designation
): ?>

<?php

$official =
    $principalOfficials[$designation];

?>

<table>

<tr>

<td colspan="3"
    class="card-title">

<?= e(
    designationLabel($designation)
) ?>

</td>

</tr>

<?php if ($official === null): ?>

<tr>

<td colspan="3">

<div class="label">
Status
</div>

<div class="value">
No <?= e(
    strtolower(
        designationLabel($designation)
    )
) ?> record found.
</div>

</td>

</tr>

<?php else: ?>

<tr class="official-present">

<td>

<div class="label">
Name
</div>

<div class="value">
<?= excelValue(
    $official['full_name'] ?? ''
) ?>
</div>

</td>

<td>

<div class="label">
Nationality
</div>

<div class="value">
<?= excelValue(
    $official['nationality'] ?? ''
) ?>
</div>

</td>

<td>

<div class="label">
ID / Passport Number
</div>

<div class="value">
<?= excelValue(
    $official['id_passport_number'] ?? ''
) ?>
</div>

</td>

</tr>

<tr>

<td>

<div class="label">
KRA PIN
</div>

<div class="value">
<?= excelValue(
    $official['kra_pin'] ?? ''
) ?>
</div>

</td>

<td>

<div class="label">
Phone Number
</div>

<div class="value">
<?= excelValue(
    $official['phone_number'] ?? ''
) ?>
</div>

</td>

<td>

<div class="label">
Email
</div>

<div class="value">
<?= excelValue(
    $official['email'] ?? ''
) ?>
</div>

</td>

</tr>

<tr>

<td colspan="3">

<div class="label">
Signature
</div>

<?php

$signature = trim(
    (string) (
        $official['signature'] ?? ''
    )
);

?>

<?php if ($signature !== ''): ?>

<div class="value signature-present">
Signature captured
</div>

<div class="signature-note">
<?= e($signature) ?>
</div>

<?php else: ?>

<div class="signature-note">
No signature file recorded.
</div>

<?php endif; ?>

</td>

</tr>

<?php endif; ?>

</table>

<table class="section-gap">
<tr><td class="no-border"></td></tr>
</table>

<?php endforeach; ?>


<!-- ==========================================================
     5B. DIRECTORS
     ========================================================== -->

<table>

<tr>

<td colspan="3"
    class="card-title">

Additional Directors

</td>

</tr>

<?php if ($directors === []): ?>

<tr>

<td colspan="3">

<div class="value">
No additional directors recorded.
</div>

</td>

</tr>

<?php else: ?>

<?php foreach (
    $directors
    as $index => $director
): ?>

<tr>

<td colspan="3"
    class="card-title">

Director <?= $index + 1 ?>

</td>

</tr>

<tr>

<td>

<div class="label">
Name
</div>

<div class="value">
<?= excelValue(
    $director['full_name'] ?? ''
) ?>
</div>

</td>

<td>

<div class="label">
Nationality
</div>

<div class="value">
<?= excelValue(
    $director['nationality'] ?? ''
) ?>
</div>

</td>

<td>

<div class="label">
ID / Passport Number
</div>

<div class="value">
<?= excelValue(
    $director['id_passport_number'] ?? ''
) ?>
</div>

</td>

</tr>

<tr>

<td>

<div class="label">
KRA PIN
</div>

<div class="value">
<?= excelValue(
    $director['kra_pin'] ?? ''
) ?>
</div>

</td>

<td>

<div class="label">
Phone Number
</div>

<div class="value">
<?= excelValue(
    $director['phone_number'] ?? ''
) ?>
</div>

</td>

<td>

<div class="label">
Email
</div>

<div class="value">
<?= excelValue(
    $director['email'] ?? ''
) ?>
</div>

</td>

</tr>

<tr>

<td colspan="3">

<div class="label">
Signature
</div>

<?php

$signature = trim(
    (string) (
        $director['signature'] ?? ''
    )
);

?>

<?php if ($signature !== ''): ?>

<div class="value signature-present">
Signature captured
</div>

<div class="signature-note">
<?= e($signature) ?>
</div>

<?php else: ?>

<div class="signature-note">
No signature file recorded.
</div>

<?php endif; ?>

</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</table>


<!-- ==========================================================
     RECORD INFORMATION
     ========================================================== -->

<table class="footer">

<tr>

<td colspan="4"
    class="footer-accent no-border">
</td>

</tr>

<tr>

<td>

<div class="label">
Application Reference
</div>

<div class="value">
<?= excelValue(
    $application['application_reference']
) ?>
</div>

</td>

<td>

<div class="label">
Record Status
</div>

<div class="value">
Submitted
</div>

</td>

<td>

<div class="label">
Submitted Date
</div>

<div class="value">
<?= excelValue(
    formatDateValue(
        $application['submitted_at'],
        'd F Y, H:i'
    )
) ?>
</div>

</td>

<td>

<div class="label">
Type of Organization
</div>

<div class="value">
<?= e(
    organizationTypeLabel(
        (string) $application['registration_type']
    )
) ?>
</div>

</td>

</tr>

<tr>

<td colspan="4">

<div class="footer-note">

This record presents the information captured in the
PBO Registration System for this submitted application.
Optional information that was not supplied is intentionally
omitted from the record.

</div>

</td>

</tr>

</table>

</body>

</html>