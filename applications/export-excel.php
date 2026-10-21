<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/official-rules.php';

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

/*
| filter_var on $_GET rather than filter_input(INPUT_GET): identical for a
| real request, and it lets the export be rendered by a test without a web
| server, which is how the layout below is checked.
*/
$applicationId = filter_var(
    $_GET['id'] ?? null,
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
            WHEN 'SECRETARY' THEN 2
            WHEN 'TREASURER' THEN 3
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
| Tax identification for officials
|--------------------------------------------------------------------------
|
| Fetched separately so this export keeps working on a database where
| database/add_official_tax_id.sql has not been run yet.
*/

if (officialTaxColumnsExist($pdo)) {

    $taxStatement = $pdo->prepare(
        'SELECT id, tax_id_type, tax_id_number
           FROM application_officials
          WHERE application_id = :application_id'
    );

    $taxStatement->execute(['application_id' => $applicationId]);

    $taxRows = [];

    foreach ($taxStatement->fetchAll(PDO::FETCH_ASSOC) as $taxRow) {
        $taxRows[(int) $taxRow['id']] = $taxRow;
    }

    foreach ($principalOfficials as $designation => $official) {
        if ($official !== null && isset($taxRows[(int) $official['id']])) {
            $principalOfficials[$designation] += $taxRows[(int) $official['id']];
        }
    }

    foreach ($directors as $index => $director) {
        if (isset($taxRows[(int) $director['id']])) {
            $directors[$index] += $taxRows[(int) $director['id']];
        }
    }
}


/*
|--------------------------------------------------------------------------
| Layout
|--------------------------------------------------------------------------
|
| Two rules decide everything below.
|
| ONE GRID. Excel ignores almost all CSS, but it does read each table's
| column structure and build a worksheet grid from it. When tables declare
| different column counts, Excel merges and splits columns to fit them all
| and the sheet opens ragged - every section a different width. So every
| table here declares the SAME twelve columns and spans them with colspan.
| Twelve divides into halves, thirds, quarters and sixths, which covers
| every layout in this document.
|
| NOTHING EMPTY. A dash in a cell reads as a value. A page of dashes reads
| as a record that was filled in badly, when usually the field simply does
| not apply - most organizations do not operate in a second county, and an
| official with no email is now perfectly normal. So a field with nothing
| in it is not printed, and a section with nothing in it is not printed
| either. What comes out is what the organization actually told us.
|
*/

const GRID = 12;

/** The column grid every table repeats. */
function grid(): string
{
    $width = round(100 / GRID, 4);

    return '<colgroup>' . str_repeat('<col style="width:' . $width . '%">', GRID) . '</colgroup>';
}

/** Is there anything here worth printing? */
function filled(mixed $value): bool
{
    return trim((string) $value) !== '';
}

/**
 * One label-and-value cell, or nothing at all when there is no value.
 *
 * $span is in grid columns: 4 gives three across a row, 6 gives two, 12
 * gives one full-width.
 */
function field(string $label, mixed $value, int $span = 4): string
{
    if (!filled($value)) {
        return '';
    }

    return '<td colspan="' . $span . '">'
        . '<span class="label">' . e($label) . '</span>'
        . '<span class="value">' . nl2br(e(trim((string) $value))) . '</span>'
        . '</td>';
}

/**
 * Lay fields out in rows of the given span, skipping the empty ones and
 * padding the last row so the grid stays square.
 *
 * Without the padding, a row holding one field out of three leaves the
 * grid short by eight columns and Excel pulls the sections out of line
 * underneath it.
 */
function fieldRows(array $fields, int $span = 4): string
{
    $cells = [];

    foreach ($fields as $label => $value) {
        $cell = field((string) $label, $value, $span);

        if ($cell !== '') {
            $cells[] = $cell;
        }
    }

    if ($cells === []) {
        return '';
    }

    $perRow = intdiv(GRID, $span);
    $html = '';

    foreach (array_chunk($cells, $perRow) as $row) {
        $html .= '<tr>' . implode('', $row);

        $short = GRID - (count($row) * $span);

        if ($short > 0) {
            $html .= '<td colspan="' . $short . '" class="pad"></td>';
        }

        $html .= '</tr>';
    }

    return $html;
}

/** A numbered band across the page. */
function sectionTitle(string $number, string $title, string $subtitle = ''): string
{
    return '<table class="band">' . grid() . '<tr class="section-title"><td colspan="' . GRID . '">'
        . '<span class="band-number">' . e($number) . '</span>'
        . '<span class="band-title">' . e($title) . '</span>'
        . ($subtitle !== '' ? '<span class="band-subtitle">' . e($subtitle) . '</span>' : '')
        . '</td></tr></table>';
}

/** A quieter heading inside a section. */
function subTitle(string $title, string $note = ''): string
{
    return '<tr class="subsection"><td colspan="' . GRID . '">'
        . e($title)
        . ($note !== '' ? '<span class="subsection-note">' . e($note) . '</span>' : '')
        . '</td></tr>';
}

/** Vertical space that survives the trip into Excel. */
function gap(): string
{
    return '<table class="gap">' . grid() . '<tr><td colspan="' . GRID . '"></td></tr></table>';
}

/** One heading cell of a table, measured in grid columns. */
function th(string $label, int $span): string
{
    return '<th colspan="' . $span . '">' . e($label) . '</th>';
}

/** One body cell, measured in grid columns. Blank rather than dashed. */
function td(mixed $value, int $span, string $class = ''): string
{
    return '<td colspan="' . $span . '"'
        . ($class !== '' ? ' class="' . $class . '"' : '')
        . '>' . nl2br(e(trim((string) $value))) . '</td>';
}

/** Everything in this row that is worth printing, as one block. */
function compact_(array $values, string $separator = ', '): string
{
    return implode($separator, array_filter(
        array_map(static fn ($v) => trim((string) $v), $values),
        static fn ($v) => $v !== ''
    ));
}


/*
|--------------------------------------------------------------------------
| Gather what will actually be printed
|--------------------------------------------------------------------------
|
| Worked out before any markup, so a section can be left out entirely
| rather than printed as an empty shell.
*/

$isInternational = strtolower((string) $application['registration_type']) === 'international';

/* Only the international details that were actually given. */
$internationalFields = [];

if ($isInternational && is_array($internationalDetails)) {
    $internationalFields = [
        'Parent Organization' => $internationalDetails['parent_organization_name'] ?? '',
        'Country of Origin' => $internationalDetails['country_of_origin'] ?? '',
        'Registration Number' => $internationalDetails['registration_number'] ?? '',
        'Physical Address' => $internationalDetails['parent_physical_address'] ?? '',
        'Postal Address' => compact_([
            $internationalDetails['parent_postal_address'] ?? '',
            $internationalDetails['parent_postal_code'] ?? '',
        ], ' - '),
        'Telephone' => $internationalDetails['parent_phone_number'] ?? '',
        'Email' => $internationalDetails['parent_email'] ?? '',
        'Contact Person' => $internationalDetails['contact_person_name'] ?? '',
        'Contact Telephone' => $internationalDetails['contact_person_phone'] ?? '',
        'Contact Email' => $internationalDetails['contact_person_email'] ?? '',
    ];

    $internationalFields = array_filter($internationalFields, 'filled');
}

/* An operating location with nothing but a county name is still worth a row. */
$operatingLocations = array_values(array_filter(
    $operatingLocations,
    static fn ($row) => filled($row['county'] ?? '')
));

$officialsToPrint = array_values(array_filter([
    $principalOfficials['CHAIRPERSON'] ?? null,
    $principalOfficials['SECRETARY'] ?? null,
    $principalOfficials['TREASURER'] ?? null,
]));

/* Objectives and beneficiaries, with the free-text "Other" pulled out. */
$objectiveRows = [];
$objectiveOther = '';

foreach ($objectives as $objective) {
    $code = (string) ($objective['objective_code'] ?? '');

    if ($code === 'OTHER') {
        $objectiveOther = trim((string) ($objective['description'] ?? ''));
        continue;
    }

    if ($code !== '') {
        $objectiveRows[] = $objectiveLabels[$code] ?? ucwords(strtolower(str_replace('_', ' ', $code)));
    }
}

$beneficiaryRows = [];

foreach ($beneficiaries as $beneficiary) {
    $code = (string) ($beneficiary['beneficiary_code'] ?? '');

    if ($code === 'OTHER' || $code === '') {
        continue;
    }

    $beneficiaryRows[] = $beneficiaryLabels[$code] ?? ucwords(strtolower(str_replace('_', ' ', $code)));
}

$sectionNumber = 0;

/** Sections are numbered as they are printed, so a skipped one leaves no hole. */
function nextSection(): string
{
    global $sectionNumber;

    return (string) ++$sectionNumber;
}


/*
|--------------------------------------------------------------------------
| Send it
|--------------------------------------------------------------------------
*/

$filename =
    'PBO_Registration_Record_'
    . preg_replace(
        '/[^A-Za-z0-9_-]+/',
        '_',
        (string) ($application['application_reference'] ?? $applicationId)
    )
    . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>PBO Registration Record</title>

<style>

@page {
    size: A4 portrait;
    margin: 0.5in;
}

body {
    font-family: Calibri, Arial, Helvetica, sans-serif;
    font-size: 10pt;
    color: #1b2b38;
    margin: 0;
    padding: 0;
    background: #ffffff;
}

/*
| Fixed layout is what makes the colgroup above bind. Without it the
| browser - and Excel - resize columns to fit their contents, and every
| section ends up a different width.
*/
table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    margin: 0;
}

td, th {
    border: 1px solid #dfe7ed;
    padding: 7px 9px;
    vertical-align: top;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

th {
    background: #eef3f7;
    color: #41535f;
    font-size: 8pt;
    font-weight: bold;
    text-align: left;
    text-transform: uppercase;
    letter-spacing: .06em;
}

/*
| The cell that pads a short row out to twelve columns. It exists for the
| grid, not for the reader, so it carries no border and no background -
| an empty bordered box reads as a field somebody failed to fill in,
| which is the very thing this rewrite removes.
*/
.pad {
    border-color: #ffffff;
    background: #ffffff;
}

/* ---- cover --------------------------------------------------------- */

.cover td {
    background: #0f3c5f;
    color: #ffffff;
    border-color: #0f3c5f;
    padding: 22px 20px 20px;
}

.cover-authority {
    font-size: 8.5pt;
    font-weight: bold;
    letter-spacing: .14em;
    color: #9dc4e0;
}

.cover-title {
    display: block;
    margin-top: 6px;
    font-size: 22pt;
    font-weight: bold;
    line-height: 1.15;
}

.cover-name {
    display: block;
    margin-top: 10px;
    font-size: 13pt;
    color: #d9e8f3;
}

.cover-reference td {
    background: #0b2e49;
    color: #ffffff;
    border-color: #0b2e49;
    font-size: 9pt;
    letter-spacing: .05em;
    padding: 9px 20px;
}

.cover-reference .label {
    color: #8fb6d2;
}

.cover-reference .value {
    color: #ffffff;
    font-weight: bold;
}

/* ---- the identity strip under the cover ---------------------------- */

.identity td {
    background: #f7fafc;
    border-top: 3px solid #c79418;
}

/* ---- section bands -------------------------------------------------- */

.band { margin-top: 0; }

.section-title td {
    background: #0f3c5f;
    color: #ffffff;
    border-color: #0f3c5f;
    padding: 9px 12px;
}

.band-number {
    font-size: 9pt;
    font-weight: bold;
    color: #9dc4e0;
    letter-spacing: .1em;
}

.band-title {
    display: block;
    font-size: 12.5pt;
    font-weight: bold;
    margin-top: 1px;
}

.band-subtitle {
    display: block;
    margin-top: 2px;
    font-size: 8.5pt;
    color: #b8d4e8;
}

.subsection td {
    background: #f1f6fa;
    border-top: 2px solid #c79418;
    color: #41535f;
    font-size: 8.5pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: .06em;
}

.subsection-note {
    float: right;
    font-weight: normal;
    text-transform: none;
    letter-spacing: 0;
    color: #7b8b97;
}

/* ---- fields --------------------------------------------------------- */

.label {
    display: block;
    font-size: 7.5pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #7b8b97;
    margin-bottom: 3px;
}

.value {
    display: block;
    font-size: 10pt;
    color: #1b2b38;
}

.headline .value {
    font-size: 11.5pt;
    font-weight: bold;
}

.strong { font-weight: bold; }
.small { font-size: 8.5pt; color: #5f6f7b; }

.gap td {
    border: 0;
    height: 12px;
    padding: 0;
    background: #ffffff;
}

.chip {
    display: inline-block;
    margin: 0 4px 4px 0;
    padding: 3px 9px;
    background: #eaf2f8;
    border: 1px solid #c9ddec;
    border-radius: 3px;
    font-size: 9pt;
}

.footnote td {
    border: 0;
    padding: 12px 2px 0;
    color: #8a97a2;
    font-size: 7.5pt;
}

</style>

</head>

<body>


<!-- ==========================================================
     COVER
     ========================================================== -->

<table class="cover">
<?= grid() ?>
<tr>
    <td colspan="<?= GRID ?>">
        <span class="cover-authority">PUBLIC BENEFIT ORGANIZATIONS REGULATORY AUTHORITY</span>
        <span class="cover-title">Registration Record</span>
        <span class="cover-name"><?= e((string) $application['pbo_name']) ?></span>
    </td>
</tr>
</table>

<table class="cover-reference">
<?= grid() ?>
<tr>
    <td colspan="4">
        <span class="label">Reference</span>
        <span class="value"><?= e((string) $application['application_reference']) ?></span>
    </td>
    <td colspan="4">
        <span class="label">Status</span>
        <span class="value"><?= e(ucwords((string) $application['status'])) ?></span>
    </td>
    <td colspan="4">
        <span class="label">Produced</span>
        <span class="value"><?= e(date('j M Y')) ?></span>
    </td>
</tr>
</table>

<table class="identity">
<?= grid() ?>
<?= fieldRows([
    'Registration Number' => $application['pbo_registration_number'],
    'Date of Registration' => formatDateValue($application['registration_date']),
    'Type of Organization' => organizationTypeLabel((string) $application['registration_type']),
], 4) ?>
</table>


<!-- ==========================================================
     ORGANIZATION
     ========================================================== -->

<?= gap() ?>
<?= sectionTitle(nextSection(), 'Organization Details', 'Headquarters address and contact particulars') ?>

<table>
<?= grid() ?>

<?php
$headOffice = fieldRows([
    'County' => $address['county'],
    'Sub-County' => $address['sub_county'],
    'Town / City' => $address['town'],
    'Physical Address' => $address['physical_address'],
    'Postal Address' => compact_([$address['postal_address'], $address['postal_code']], ' - '),
    'Telephone' => $address['phone_number'],
    'Alternative Telephone' => $address['alternative_phone_number'],
    'Email' => $address['email'],
], 4);
?>

<?php if ($headOffice !== ''): ?>
    <?= subTitle('Head Office') ?>
    <?= $headOffice ?>
<?php else: ?>
    <?= subTitle('Head Office') ?>
    <tr><td colspan="<?= GRID ?>" class="small">No head office address recorded.</td></tr>
<?php endif; ?>

<?php if ($operatingLocations !== []): ?>

    <?= subTitle(
        'Other Areas of Operation',
        count($operatingLocations) . (count($operatingLocations) === 1 ? ' county' : ' counties')
    ) ?>

    <tr>
        <?= th('County', 2) ?>
        <?= th('Sub-County', 2) ?>
        <?= th('Physical Address', 3) ?>
        <?= th('Postal Address', 2) ?>
        <?= th('Telephone', 2) ?>
        <?= th('Email', 1) ?>
    </tr>

    <?php foreach ($operatingLocations as $location): ?>
        <tr>
            <?= td($location['county'], 2, 'strong') ?>
            <?= td($location['sub_county'], 2) ?>
            <?= td($location['physical_address'], 3) ?>
            <?= td(compact_([$location['postal_address'], $location['postal_code']], ' - '), 2) ?>
            <?= td($location['phone_number'], 2) ?>
            <?= td($location['email'], 1) ?>
        </tr>
    <?php endforeach; ?>

<?php endif; ?>

</table>

<?php if ($internationalFields !== []): ?>

    <?= gap() ?>
    <?= sectionTitle(nextSection(), 'Parent Organization', 'Recorded for an international organization') ?>

    <table>
    <?= grid() ?>
    <?= fieldRows($internationalFields, 4) ?>
    </table>

<?php endif; ?>


<!-- ==========================================================
     PURPOSES AND BENEFICIARIES
     ========================================================== -->

<?php if ($objectiveRows !== [] || $objectiveOther !== ''): ?>

    <?= gap() ?>
    <?= sectionTitle(nextSection(), 'Public Benefit Purposes', 'The purposes the organization is registered to pursue') ?>

    <table>
    <?= grid() ?>

    <?php if ($objectiveRows !== []): ?>
        <tr>
            <td colspan="<?= GRID ?>">
                <?php foreach ($objectiveRows as $objectiveRow): ?>
                    <span class="chip"><?= e($objectiveRow) ?></span>
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endif; ?>

    <?php if ($objectiveOther !== ''): ?>
        <?= subTitle('Any Other Purpose') ?>
        <tr><?= td($objectiveOther, GRID) ?></tr>
    <?php endif; ?>

    </table>

<?php endif; ?>

<?php if ($beneficiaryRows !== [] || $beneficiaryOtherDescription !== '' || $beneficiaryOtherSpecify !== ''): ?>

    <?= gap() ?>
    <?= sectionTitle(nextSection(), 'Beneficiaries', 'Who the organization serves') ?>

    <table>
    <?= grid() ?>

    <?php if ($beneficiaryRows !== []): ?>
        <tr>
            <td colspan="<?= GRID ?>">
                <?php foreach ($beneficiaryRows as $beneficiaryRow): ?>
                    <span class="chip"><?= e($beneficiaryRow) ?></span>
                <?php endforeach; ?>
            </td>
        </tr>
    <?php endif; ?>

    <?php if ($beneficiaryOtherSpecify !== '' || $beneficiaryOtherDescription !== ''): ?>
        <?= subTitle('Any Other Beneficiary') ?>
        <?= fieldRows([
            'Specify' => $beneficiaryOtherSpecify,
            'Description' => $beneficiaryOtherDescription,
        ], 6) ?>
    <?php endif; ?>

    </table>

<?php endif; ?>


<!-- ==========================================================
     FINANCIAL YEAR
     ========================================================== -->

<?php if ($financialYearLabel !== ''): ?>

    <?= gap() ?>
    <?= sectionTitle(nextSection(), 'Financial Year') ?>

    <table>
    <?= grid() ?>
    <?= fieldRows(['Financial Year Runs' => $financialYearLabel], 12) ?>
    </table>

<?php endif; ?>


<!-- ==========================================================
     OFFICIALS
     ========================================================== -->

<?php if ($officialsToPrint !== [] || $directors !== []): ?>

    <?= gap() ?>
    <?= sectionTitle(nextSection(), 'Officials and Directors', 'Particulars as recorded on the application') ?>

    <table>
    <?= grid() ?>

    <?php foreach ($officialsToPrint as $official): ?>

        <?php
        $tax = officialTaxIdDisplay($official);
        $signature = trim((string) ($official['signature'] ?? ''));
        ?>

        <?= subTitle(
            designationLabel((string) $official['designation']),
            $signature !== '' ? 'Signature on file' : ''
        ) ?>

        <?= fieldRows([
            'Full Name' => $official['full_name'],
            'Nationality' => $official['nationality'],
            'ID / Passport Number' => $official['id_passport_number'],
            ($tax['label'] ?? 'KRA PIN') => $tax['value'] ?? '',
            'Telephone' => $official['phone_number'],
            'Email' => $official['email'],
        ], 4) ?>

    <?php endforeach; ?>

    <?php if ($directors !== []): ?>

        <?= subTitle(
            'Directors',
            count($directors) . (count($directors) === 1 ? ' director' : ' directors')
        ) ?>

        <tr>
            <?= th('Name', 3) ?>
            <?= th('Nationality', 2) ?>
            <?= th('ID / Passport', 2) ?>
            <?= th('Tax Identification', 3) ?>
            <?= th('Telephone', 2) ?>
        </tr>

        <?php foreach ($directors as $director): ?>
            <?php $directorTax = officialTaxIdDisplay($director); ?>
            <tr>
                <?= td($director['full_name'], 3, 'strong') ?>
                <?= td($director['nationality'], 2) ?>
                <?= td($director['id_passport_number'], 2) ?>
                <?= td(
                    $directorTax === null
                        ? ''
                        : $directorTax['label'] . ': ' . $directorTax['value'],
                    3
                ) ?>
                <?= td($director['phone_number'], 2) ?>
            </tr>
        <?php endforeach; ?>

    <?php endif; ?>

    </table>

<?php endif; ?>


<table class="footnote">
<?= grid() ?>
<tr>
    <td colspan="<?= GRID ?>">
        Produced from the PBO Registration and Records Management System on
        <?= e(date('j F Y \a\t H:i')) ?>.
        Fields the organization did not provide are not shown.
    </td>
</tr>
</table>

</body>
</html>
