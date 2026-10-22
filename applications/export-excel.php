<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/official-rules.php';
require_once __DIR__ . '/../includes/excel-layout.php';
require_once __DIR__ . '/../includes/organization-reach.php';
require_once __DIR__ . '/../includes/application-labels.php';

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
        " . (pboHeadquartersColumnAvailable($pdo) ? 'headquarters_in_kenya,' : '') . "
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
        " . implode(",\n        ", array_keys(pboOverseasFields())) . "
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
| What will actually be printed
|--------------------------------------------------------------------------
|
| Worked out before any markup, so a section with nothing in it can be
| left out altogether rather than printed as an empty shell.
*/

$isInternational = strtolower((string) $application['registration_type']) === 'international';

/*
| One head office, one section. An organization whose head office is in
| Kenya prints the Kenyan address and nothing overseas, however it is
| registered - printing both is what made a Kenyan organization look
| like its own foreign parent on an exported record.
*/
$headOfficeAbroad = pboUsesOverseasHeadOffice($application);

$overseasFields = [];

if ($headOfficeAbroad && is_array($internationalDetails)) {

    /*
    | Labels from pboOverseasFields(), so a rename reaches this export,
    | the other export, the form and the record together. Postal address
    | and postal code are the one departure: they are printed as one
    | field here because two half-empty cells side by side read worse
    | than one.
    */
    $labels = pboOverseasFields();

    foreach ($labels as $column => $label) {

        if ($column === 'parent_postal_code') {
            continue;
        }

        $value = $internationalDetails[$column] ?? '';

        if ($column === 'parent_postal_address') {
            $value = implode(' - ', array_filter([
                trim((string) ($internationalDetails['parent_postal_address'] ?? '')),
                trim((string) ($internationalDetails['parent_postal_code'] ?? '')),
            ], 'xlFilled'));
        }

        $overseasFields[$label] = $value;
    }

    $overseasFields = array_filter($overseasFields, 'xlFilled');
}

/* Areas of operation outside Kenya, for either type of organization. */
$foreignLocations = array_values(array_filter(
    pboForeignLocations($pdo, $applicationId),
    static fn ($row) => xlFilled($row['country'] ?? '')
));

/* A location with nothing but a county name is still worth a row. */
$operatingLocations = array_values(array_filter(
    $operatingLocations,
    static fn ($row) => xlFilled($row['county'] ?? '')
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
        $objectiveRows[] = [
            'label' => pboCodeLabel($code, pboObjectiveLabels()),
            'note' => trim((string) ($objective['description'] ?? '')),
        ];
    }
}

$beneficiaryRows = [];

foreach ($beneficiaries as $beneficiary) {
    $code = (string) ($beneficiary['beneficiary_code'] ?? '');

    if ($code === 'OTHER' || $code === '') {
        continue;
    }

    $beneficiaryRows[] = [
        'label' => pboCodeLabel($code, pboBeneficiaryLabels()),
        'note' => trim((string) ($beneficiary['description'] ?? '')),
    ];
}

$sectionNumber = 0;

/** Sections are numbered as they print, so a skipped one leaves no hole. */
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
<?= xlStyles() ?>
</style>

</head>

<body>


<!-- ==========================================================
     MASTHEAD

     Three rows, not three spans in one cell. Excel ignores
     display:block, so stacked spans arrive as one run-together
     string - which is how the authority, the title and the
     organization name all ended up crushed into cell A1.
     ========================================================== -->

<table class="cover">
<?= xlGrid() ?>
<tr><td colspan="<?= XL_GRID ?>" class="cover-authority">PUBLIC BENEFIT ORGANIZATIONS REGULATORY AUTHORITY</td></tr>
<tr><td colspan="<?= XL_GRID ?>" class="cover-title">Registration Record</td></tr>
<tr><td colspan="<?= XL_GRID ?>" class="cover-name"><?= xl((string) $application['pbo_name']) ?></td></tr>
</table>

<table>
<?= xlGrid() ?>
<?= xlFields([
    'Reference' => $application['application_reference'],
    'Status' => ucwords((string) $application['status']),
    'Registration Number' => $application['pbo_registration_number'],
    'Date of Registration' => formatDateValue($application['registration_date']),
    'Type of Organization' => organizationTypeLabel((string) $application['registration_type']),
    'Produced' => date('j M Y'),
], 2) ?>
</table>


<!-- ==========================================================
     ORGANIZATION
     ========================================================== -->

<?= xlGap() ?>
<?= xlBand(nextSection(), 'Organization Details', 'Headquarters and contact particulars') ?>

<table>
<?= xlGrid() ?>

<?php
$headOffice = xlFields([
    'County' => $address['county'],
    'Sub-County' => $address['sub_county'],
    'Town / City' => $address['town'],
    'Postal Code' => $address['postal_code'],
    'Physical Address' => $address['physical_address'],
    'Postal Address' => $address['postal_address'],
    'Telephone' => $address['phone_number'],
    'Alternative Telephone' => $address['alternative_phone_number'],
    'Email' => $address['email'],
], 2);
?>

<?php if (!$headOfficeAbroad): ?>

    <?= xlSub('Head Office') ?>

    <?php if ($headOffice !== ''): ?>
        <?= $headOffice ?>
    <?php else: ?>
        <tr><td colspan="<?= XL_GRID ?>" class="v plain">No head office address recorded.</td></tr>
    <?php endif; ?>

<?php endif; ?>

<?php if ($operatingLocations !== []): ?>

    <?= xlSub(
        'Other Areas of Operation',
        count($operatingLocations) . (count($operatingLocations) === 1 ? ' county' : ' counties')
    ) ?>

    <tr>
        <?= xlHeading('County', 2, 'left') ?>
        <?= xlHeading('Sub-County', 2, 'left') ?>
        <?= xlHeading('Physical Address', 4, 'left') ?>
        <?= xlHeading('Telephone', 2) ?>
        <?= xlHeading('Email', 2, 'left') ?>
    </tr>

    <?php foreach ($operatingLocations as $location): ?>
        <tr>
            <?= xlCell($location['county'], 2) ?>
            <?= xlCell($location['sub_county'], 2, 'v plain') ?>
            <?= xlCell($location['physical_address'], 4, 'v plain') ?>
            <?= xlCell($location['phone_number'], 2, 'v plain c') ?>
            <?= xlCell($location['email'], 2, 'v plain') ?>
        </tr>
    <?php endforeach; ?>

<?php endif; ?>

<?php
/*
| Areas of operation outside Kenya. Same table shape as the counties
| above, with country in place of county and city in place of
| sub-county, so one reading explains both.
*/
?>
<?php if ($foreignLocations !== []): ?>

    <?= xlSub(
        'Areas of Operation Outside Kenya',
        count($foreignLocations) . (count($foreignLocations) === 1 ? ' country' : ' countries')
    ) ?>

    <tr>
        <?= xlHeading('Country', 2, 'left') ?>
        <?= xlHeading('City / Town', 2, 'left') ?>
        <?= xlHeading('Physical Address', 4, 'left') ?>
        <?= xlHeading('Telephone', 2) ?>
        <?= xlHeading('Email', 2, 'left') ?>
    </tr>

    <?php foreach ($foreignLocations as $location): ?>
        <tr>
            <?= xlCell($location['country'], 2) ?>
            <?= xlCell($location['city'], 2, 'v plain') ?>
            <?= xlCell($location['physical_address'], 4, 'v plain') ?>
            <?= xlCell($location['phone_number'], 2, 'v plain c') ?>
            <?= xlCell($location['email'], 2, 'v plain') ?>
        </tr>
    <?php endforeach; ?>

<?php endif; ?>

</table>

<?php if ($overseasFields !== []): ?>

    <?= xlGap() ?>
    <?= xlBand(
        nextSection(),
        'Head Office Outside Kenya',
        "The organization's own head office in the country it is based in"
    ) ?>

    <table>
    <?= xlGrid() ?>
    <?= xlFields($overseasFields, 2) ?>
    </table>

<?php endif; ?>


<!-- ==========================================================
     PURPOSES AND BENEFICIARIES

     One per row. They used to be printed side by side in a
     single cell, which arrived in Excel as one long unreadable
     string: "Education Poverty Eradication Hiv Aids Water,
     Sanitation and Hygiene".
     ========================================================== -->

<?php if ($objectiveRows !== [] || $objectiveOther !== ''): ?>

    <?= xlGap() ?>
    <?= xlBand(nextSection(), 'Public Benefit Purposes', 'What the organization is registered to pursue') ?>

    <table>
    <?= xlGrid() ?>

    <?php
    /*
    | Most purposes carry no note. Printing an empty column beside every
    | one of them is the same empty-box problem as a dash, so the column
    | only appears when at least one row has something to put in it.
    */
    $anyObjectiveNote = array_filter(
        array_column($objectiveRows, 'note'),
        'xlFilled'
    ) !== [];
    ?>

    <?php foreach ($objectiveRows as $index => $objectiveRow): ?>
        <tr>
            <?= xlCell((string) ($index + 1), 1, 'v plain c') ?>
            <?= xlCell($objectiveRow['label'], $anyObjectiveNote ? 5 : 11) ?>
            <?= $anyObjectiveNote ? xlCell($objectiveRow['note'], 6, 'v plain') : '' ?>
        </tr>
    <?php endforeach; ?>

    <?php if ($objectiveOther !== ''): ?>
        <?= xlSub('Any Other Purpose') ?>
        <tr><?= xlCell($objectiveOther, XL_GRID, 'v plain') ?></tr>
    <?php endif; ?>

    </table>

<?php endif; ?>

<?php if ($beneficiaryRows !== [] || $beneficiaryOtherDescription !== '' || $beneficiaryOtherSpecify !== ''): ?>

    <?= xlGap() ?>
    <?= xlBand(nextSection(), 'Beneficiaries', 'Who the organization serves') ?>

    <table>
    <?= xlGrid() ?>

    <?php
    $anyBeneficiaryNote = array_filter(
        array_column($beneficiaryRows, 'note'),
        'xlFilled'
    ) !== [];
    ?>

    <?php foreach ($beneficiaryRows as $index => $beneficiaryRow): ?>
        <tr>
            <?= xlCell((string) ($index + 1), 1, 'v plain c') ?>
            <?= xlCell($beneficiaryRow['label'], $anyBeneficiaryNote ? 5 : 11) ?>
            <?= $anyBeneficiaryNote ? xlCell($beneficiaryRow['note'], 6, 'v plain') : '' ?>
        </tr>
    <?php endforeach; ?>

    <?php if ($beneficiaryOtherSpecify !== '' || $beneficiaryOtherDescription !== ''): ?>
        <?= xlSub('Any Other Beneficiary') ?>
        <?= xlFields([
            'Specify' => $beneficiaryOtherSpecify,
            'Description' => $beneficiaryOtherDescription,
        ], 1) ?>
    <?php endif; ?>

    </table>

<?php endif; ?>


<!-- ==========================================================
     FINANCIAL YEAR
     ========================================================== -->

<?php if ($financialYearLabel !== ''): ?>

    <?= xlGap() ?>
    <?= xlBand(nextSection(), 'Financial Year') ?>

    <table>
    <?= xlGrid() ?>
    <?= xlFields(['Financial Year Runs' => $financialYearLabel], 1) ?>
    </table>

<?php endif; ?>


<!-- ==========================================================
     OFFICIALS
     ========================================================== -->

<?php if ($officialsToPrint !== [] || $directors !== []): ?>

    <?= xlGap() ?>
    <?= xlBand(nextSection(), 'Officials and Directors', 'As recorded on the application') ?>

    <table>
    <?= xlGrid() ?>

    <?php foreach ($officialsToPrint as $official): ?>

        <?php
        $tax = officialTaxIdDisplay($official);
        $signature = trim((string) ($official['signature'] ?? ''));
        ?>

        <?= xlSub(
            designationLabel((string) $official['designation']),
            $signature !== '' ? 'Signature on file' : ''
        ) ?>

        <?= xlFields([
            'Full Name' => $official['full_name'],
            'Nationality' => $official['nationality'],
            'ID / Passport Number' => $official['id_passport_number'],
            ($tax['label'] ?? 'KRA PIN') => $tax['value'] ?? '',
            'Telephone' => $official['phone_number'],
            'Email' => $official['email'],
        ], 2) ?>

    <?php endforeach; ?>

    <?php if ($directors !== []): ?>

        <?= xlSub(
            'Directors',
            count($directors) . (count($directors) === 1 ? ' director' : ' directors')
        ) ?>

        <tr>
            <?= xlHeading('Name', 3, 'left') ?>
            <?= xlHeading('Nationality', 2) ?>
            <?= xlHeading('ID / Passport', 2) ?>
            <?= xlHeading('Tax Identification', 3, 'left') ?>
            <?= xlHeading('Telephone', 2) ?>
        </tr>

        <?php foreach ($directors as $director): ?>
            <?php $directorTax = officialTaxIdDisplay($director); ?>
            <tr>
                <?= xlCell($director['full_name'], 3) ?>
                <?= xlCell($director['nationality'], 2, 'v plain c') ?>
                <?= xlCell($director['id_passport_number'], 2, 'v plain c') ?>
                <?= xlCell(
                    $directorTax === null
                        ? ''
                        : $directorTax['label'] . ': ' . $directorTax['value'],
                    3,
                    'v plain'
                ) ?>
                <?= xlCell($director['phone_number'], 2, 'v plain c') ?>
            </tr>
        <?php endforeach; ?>

    <?php endif; ?>

    </table>

<?php endif; ?>


<table class="footnote">
<?= xlGrid() ?>
<tr>
    <td colspan="<?= XL_GRID ?>">
        Produced from the PBO Registration and Records Management System on
        <?= xl(date('j F Y \a\t H:i')) ?>.
        Fields the organization did not provide are not shown.
    </td>
</tr>
</table>

</body>
</html>
