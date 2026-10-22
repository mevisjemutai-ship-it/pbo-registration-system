<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Export or print the register
|--------------------------------------------------------------------------
|
| Staff were printing organizations one at a time. This takes whatever the
| List of PBOs page is showing - or a set of rows ticked on it - and
| produces one document.
|
| Query string:
|   mode    list     a table, one row per organization  (default)
|           details  every particular, one organization per page
|   format  excel    downloaded and opened in Excel     (default)
|           print    a page that opens the print dialog
|   ids     1,2,3    only these organizations. Without it, everything
|                    matching the filters below
|   q, year, type, letter, sort   the same filters as the list page,
|                    read by the same code (includes/pbo-registry-filters.php)
|
| Layout note
| -----------
| Excel ignores most CSS, but it does honour a table's column structure.
| Every table here therefore shares ONE column grid, declared in the same
| colgroup, with wide cells spanning columns instead of tables having
| different widths. That is what stops the sections opening ragged, each
| section setting its own column widths and fighting the ones above it.
|
*/

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/excel-layout.php';
require_once __DIR__ . '/../includes/organization-reach.php';
require_once __DIR__ . '/../includes/official-rules.php';
require_once __DIR__ . '/../includes/pbo-registry-filters.php';
require_once __DIR__ . '/../includes/application-labels.php';

requireLogin();
requirePasswordChanged();

$connection = $pdo ?? $db ?? null;

if (!$connection instanceof PDO) {
    http_response_code(500);
    exit('Database connection is not available.');
}


/*
|--------------------------------------------------------------------------
| Layout
|--------------------------------------------------------------------------
|
| The grid, the cells, the labels and the stylesheet come from
| includes/excel-layout.php, which this export and the per-application
| record now share. They had been drifting apart: a fix made to one and
| forgotten in the other is how the register went on printing dashes
| after they had been taken out of the record.
|
| What matters there, and does not show until the file is opened in
| Excel: a label and its value go in SEPARATE cells (Excel ignores
| display:block, so stacked spans arrive as "Registration Number20397"),
| column widths are in pixels, and every value is marked as text so a
| telephone number keeps its leading zero.
|
*/

/** How many organizations one details export may carry. */
const MAX_DETAIL_RECORDS = 300;


function typeLabel(string $type): string
{
    return pboRegistryTypes()[$type]
        ?? ucwords(strtolower(str_replace('_', ' ', $type)));
}


function prettyDate(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return '';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));

    return $date === false ? $value : $date->format('j M Y');
}


function monthName(mixed $month): string
{
    $month = (int) $month;

    if ($month < 1 || $month > 12) {
        return '';
    }

    return DateTimeImmutable::createFromFormat('!m', (string) $month)->format('F');
}


/** Join the parts that have something in them. */
function joined(array $values, string $separator = ' '): string
{
    return implode($separator, array_filter(
        array_map(static fn ($v) => trim((string) $v), $values),
        static fn ($v) => $v !== ''
    ));
}


/*
|--------------------------------------------------------------------------
| What to export
|--------------------------------------------------------------------------
*/

$mode = ($_GET['mode'] ?? 'list') === 'details' ? 'details' : 'list';
$format = ($_GET['format'] ?? 'excel') === 'print' ? 'print' : 'excel';

$filters = pboRegistryFilters($_GET);

/*
| Selected rows arrive as a comma-separated list. Anything that is not a
| positive integer is dropped rather than refused: a stray value should
| not cost the user the whole export.
*/
$ids = [];

if (isset($_GET['ids']) && trim((string) $_GET['ids']) !== '') {
    foreach (explode(',', (string) $_GET['ids']) as $candidate) {
        $id = filter_var(trim($candidate), FILTER_VALIDATE_INT);

        if ($id !== false && $id > 0) {
            $ids[$id] = $id;
        }
    }

    $ids = array_values($ids);
}

$where = pboRegistryWhere($filters);
$whereSql = $where['sql'];
$params = $where['params'];

if ($ids !== []) {
    /*
    | Ids are already integers, so inlining them is safe and avoids
    | several hundred placeholders. The filters still apply, so a row
    | that no longer matches cannot be exported by passing its id.
    */
    $whereSql .= ' AND id IN (' . implode(',', $ids) . ')';
}

$statement = $connection->prepare(
    'SELECT id, application_reference, pbo_name, pbo_registration_number,
            registration_date, registration_type,'
            . (pboHeadquartersColumnAvailable($connection) ? ' headquarters_in_kenya,' : '') . '
            financial_year_start_month, financial_year_end_month,
            submitted_at
       FROM pbo_applications
      WHERE ' . $whereSql . '
   ORDER BY ' . pboRegistryOrderBy($filters) . '
      LIMIT ' . ($mode === 'details' ? MAX_DETAIL_RECORDS : 10000)
);

pboRegistryBind($statement, $params);
$statement->execute();

$records = $statement->fetchAll(PDO::FETCH_ASSOC);

$description = pboRegistryDescription($filters, $ids !== [] ? count($records) : null);

$truncated = $mode === 'details' && count($records) === MAX_DETAIL_RECORDS;


/*
|--------------------------------------------------------------------------
| Details: gather the rest, in one query per section
|--------------------------------------------------------------------------
|
| One query per section for the whole set, rather than per organization:
| printing 50 records otherwise means 300 round trips.
*/

$addresses = [];
$locations = [];
$foreignLocations = [];
$overseasOffices = [];
$objectives = [];
$beneficiaries = [];
$officials = [];

if ($mode === 'details' && $records !== []) {

    $idList = implode(',', array_map(static fn ($r) => (int) $r['id'], $records));

    foreach (
        $connection->query(
            'SELECT * FROM application_addresses
              WHERE application_id IN (' . $idList . ')
           ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_ASSOC) as $row
    ) {
        /* Latest row per application wins; earlier ones are superseded. */
        $addresses[$row['application_id']] ??= $row;
    }

    foreach (
        $connection->query(
            'SELECT * FROM application_operating_locations
              WHERE application_id IN (' . $idList . ')
           ORDER BY county'
        )->fetchAll(PDO::FETCH_ASSOC) as $row
    ) {
        $locations[$row['application_id']][] = $row;
    }

    /*
    | Areas of operation outside Kenya, and the overseas head office for
    | the records that have one. Both guarded, so an export still runs on
    | a server where database/add_operations_outside_kenya.sql has not
    | been applied yet - it simply prints nothing for them.
    */
    if (pboForeignLocationsAvailable($connection)) {
        foreach (
            $connection->query(
                'SELECT * FROM application_foreign_locations
                  WHERE application_id IN (' . $idList . ')
               ORDER BY country'
            )->fetchAll(PDO::FETCH_ASSOC) as $row
        ) {
            $foreignLocations[$row['application_id']][] = $row;
        }
    }

    foreach (
        $connection->query(
            'SELECT * FROM application_international_details
              WHERE application_id IN (' . $idList . ')
           ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_ASSOC) as $row
    ) {
        /* Latest row per application wins, as with the addresses. */
        $overseasOffices[$row['application_id']] ??= $row;
    }

    foreach (
        $connection->query(
            'SELECT application_id, objective_code, description
               FROM application_objectives
              WHERE application_id IN (' . $idList . ')
           ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC) as $row
    ) {
        $objectives[$row['application_id']][] = $row;
    }

    foreach (
        $connection->query(
            'SELECT application_id, beneficiary_code, description
               FROM application_beneficiaries
              WHERE application_id IN (' . $idList . ')
           ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC) as $row
    ) {
        $beneficiaries[$row['application_id']][] = $row;
    }

    $taxColumns = officialTaxColumnsExist($connection)
        ? ', tax_id_type, tax_id_number'
        : '';

    foreach (
        $connection->query(
            'SELECT application_id, full_name, designation, nationality,
                    id_passport_number, kra_pin, phone_number, email' . $taxColumns . '
               FROM application_officials
              WHERE application_id IN (' . $idList . ')
           ORDER BY CASE designation
                        WHEN \'CHAIRPERSON\' THEN 1
                        WHEN \'SECRETARY\' THEN 2
                        WHEN \'TREASURER\' THEN 3
                        WHEN \'DIRECTOR\' THEN 4
                        ELSE 5
                    END, id'
        )->fetchAll(PDO::FETCH_ASSOC) as $row
    ) {
        $officials[$row['application_id']][] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| Send it
|--------------------------------------------------------------------------
*/

$stamp = date('Y-m-d');

$filename = 'PBO-Register-'
    . ($mode === 'details' ? 'Details-' : 'List-')
    . $stamp
    . '.xls';

if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
} else {
    header('Content-Type: text/html; charset=UTF-8');
}

?><!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<title>List of Public Benefit Organizations</title>

<style>
<?= xlStyles() ?>

/* The print view only: a toolbar that never reaches the paper. */
.toolbar {
    width: 696px;
    padding: 10px 12px;
    background: #0f3c5f;
    color: #fff;
    font-size: 11pt;
}

.toolbar button {
    font: inherit;
    font-size: 10pt;
    padding: 6px 14px;
    margin-left: 8px;
    border: 0;
    border-radius: 4px;
    background: #d69a18;
    color: #fff;
    cursor: pointer;
}

/* Each organization starts a new sheet of paper in details mode. */
.record { page-break-before: always; }
.record:first-of-type { page-break-before: auto; }

@media print {
    .toolbar { display: none; }
}
</style>

</head>

<body>

<?php if ($format === 'print'): ?>
    <div class="toolbar">
        <?= xl($description) ?>
        <button type="button" onclick="window.print()">Print</button>
        <button type="button" onclick="window.close()">Close</button>
    </div>
<?php endif; ?>


<!-- ==========================================================
     MASTHEAD

     One row each. Stacked spans in a single cell arrive in Excel
     as one run-together string.
     ========================================================== -->

<table class="cover">
<?= xlGrid() ?>
<tr><td colspan="<?= XL_GRID ?>" class="cover-authority">PUBLIC BENEFIT ORGANIZATIONS REGULATORY AUTHORITY</td></tr>
<tr><td colspan="<?= XL_GRID ?>" class="cover-title">List of Public Benefit Organizations</td></tr>
<tr><td colspan="<?= XL_GRID ?>" class="cover-name">
    <?= xl($description) ?>
    &nbsp;&nbsp;&middot;&nbsp;&nbsp;
    <?= count($records) ?> record<?= count($records) === 1 ? '' : 's' ?>
    &nbsp;&nbsp;&middot;&nbsp;&nbsp;
    Produced <?= xl(date('j M Y')) ?>
</td></tr>
</table>

<?php if ($truncated): ?>
    <table>
    <?= xlGrid() ?>
    <tr><td colspan="<?= XL_GRID ?>" class="v">
        This export stops at <?= MAX_DETAIL_RECORDS ?> organizations.
        Narrow the filters and export the rest separately.
    </td></tr>
    </table>
<?php endif; ?>

<?php if ($records === []): ?>

    <table>
    <?= xlGrid() ?>
    <tr><td colspan="<?= XL_GRID ?>" class="v plain">
        No organizations match this selection.
    </td></tr>
    </table>

<?php elseif ($mode === 'list'): ?>

    <?= xlGap() ?>

    <table>
    <?= xlGrid() ?>
    <tr>
        <?= xlHeading('#', 1) ?>
        <?= xlHeading('Registration Number', 3, 'left') ?>
        <?= xlHeading('Name of Organization', 4, 'left') ?>
        <?= xlHeading('Type', 2) ?>
        <?= xlHeading('Registered', 2) ?>
    </tr>

    <?php foreach ($records as $index => $record): ?>
        <tr>
            <?= xlCell((string) ($index + 1), 1, 'v plain c') ?>
            <?= xlCell($record['pbo_registration_number'], 3, 'v plain') ?>
            <?= xlCell($record['pbo_name'], 4) ?>
            <?= xlCell(typeLabel((string) $record['registration_type']), 2, 'v plain c') ?>
            <?= xlCell(prettyDate($record['registration_date']), 2, 'v plain c') ?>
        </tr>
    <?php endforeach; ?>
    </table>

<?php else: ?>

    <?php foreach ($records as $record): ?>

        <?php
        $id = (int) $record['id'];
        $address = $addresses[$id] ?? [];

        $start = monthName($record['financial_year_start_month']);
        $end = monthName($record['financial_year_end_month']);
        ?>

        <div class="record">

            <?= xlGap() ?>

            <!-- The name, introduced rather than left bare. -->
            <table class="cover">
            <?= xlGrid() ?>
            <tr><td colspan="<?= XL_GRID ?>" class="cover-authority">NAME OF ORGANIZATION</td></tr>
            <tr><td colspan="<?= XL_GRID ?>" class="cover-title" style="font-size:15pt"><?= xl($record['pbo_name']) ?></td></tr>
            </table>

            <table>
            <?= xlGrid() ?>
            <?= xlFields([
                'Registration Number' => $record['pbo_registration_number'],
                'Date of Registration' => prettyDate($record['registration_date']),
                'Type' => typeLabel((string) $record['registration_type']),
                'Application Reference' => $record['application_reference'],
                'Financial Year' => $start !== '' && $end !== '' ? $start . ' to ' . $end : '',
                'Submitted' => prettyDate(substr((string) $record['submitted_at'], 0, 10)),
            ], 2) ?>
            </table>

            <?php
            $headOffice = xlFields([
                'County' => $address['county'] ?? '',
                'Sub-County' => $address['sub_county'] ?? '',
                'Postal Code' => $address['postal_code'] ?? '',
                'Physical Address' => $address['physical_address'] ?? '',
                'Postal Address' => $address['postal_address'] ?? '',
                'Telephone' => $address['phone_number'] ?? '',
                'Alternative Telephone' => $address['alternative_phone_number'] ?? '',
                'Email' => $address['email'] ?? '',
            ], 2);
            ?>

            <?php
            /*
            | One head office, one table. Where it is decides which one
            | is printed: an organization registered as international but
            | based in Kenya gets the Kenyan table and nothing overseas,
            | and one based abroad gets the overseas table in its place.
            | Printing both is what made a Kenyan organization read as
            | its own foreign parent.
            */
            $abroad = pboUsesOverseasHeadOffice($record);
            $overseas = $overseasOffices[$id] ?? null;

            $overseasOffice = '';

            if ($abroad && is_array($overseas)) {
                $overseasValues = [];

                foreach (pboOverseasFields() as $column => $label) {
                    if ($column === 'parent_postal_code') {
                        continue;
                    }

                    $overseasValues[$label] = $column === 'parent_postal_address'
                        ? implode(' - ', array_filter([
                            trim((string) ($overseas['parent_postal_address'] ?? '')),
                            trim((string) ($overseas['parent_postal_code'] ?? '')),
                        ], 'xlFilled'))
                        : ($overseas[$column] ?? '');
                }

                $overseasOffice = xlFields($overseasValues, 2);
            }
            ?>

            <?php if (!$abroad && $headOffice !== ''): ?>
                <table>
                <?= xlGrid() ?>
                <?= xlSub('Head Office') ?>
                <?= $headOffice ?>
                </table>
            <?php endif; ?>

            <?php if ($overseasOffice !== ''): ?>
                <table>
                <?= xlGrid() ?>
                <?= xlSub('Head Office Outside Kenya') ?>
                <?= $overseasOffice ?>
                </table>
            <?php endif; ?>

            <?php if (!empty($locations[$id])): ?>
                <table>
                <?= xlGrid() ?>
                <?= xlSub(
                    'Other Areas of Operation',
                    count($locations[$id]) . (count($locations[$id]) === 1 ? ' county' : ' counties')
                ) ?>
                <tr>
                    <?= xlHeading('County', 3, 'left') ?>
                    <?= xlHeading('Sub-County', 2, 'left') ?>
                    <?= xlHeading('Physical Address', 4, 'left') ?>
                    <?= xlHeading('Telephone', 3) ?>
                </tr>
                <?php foreach ($locations[$id] as $location): ?>
                    <tr>
                        <?= xlCell($location['county'], 3) ?>
                        <?= xlCell($location['sub_county'], 2, 'v plain') ?>
                        <?= xlCell($location['physical_address'], 4, 'v plain') ?>
                        <?= xlCell($location['phone_number'], 3, 'v plain c') ?>
                    </tr>
                <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if (!empty($foreignLocations[$id])): ?>
                <table>
                <?= xlGrid() ?>
                <?= xlSub(
                    'Areas of Operation Outside Kenya',
                    count($foreignLocations[$id])
                    . (count($foreignLocations[$id]) === 1 ? ' country' : ' countries')
                ) ?>
                <tr>
                    <?= xlHeading('Country', 3, 'left') ?>
                    <?= xlHeading('City / Town', 2, 'left') ?>
                    <?= xlHeading('Physical Address', 4, 'left') ?>
                    <?= xlHeading('Telephone', 3) ?>
                </tr>
                <?php foreach ($foreignLocations[$id] as $location): ?>
                    <tr>
                        <?= xlCell($location['country'], 3) ?>
                        <?= xlCell($location['city'], 2, 'v plain') ?>
                        <?= xlCell($location['physical_address'], 4, 'v plain') ?>
                        <?= xlCell($location['phone_number'], 3, 'v plain c') ?>
                    </tr>
                <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if (!empty($objectives[$id])): ?>
                <table>
                <?= xlGrid() ?>
                <?= xlSub('Objectives') ?>
                <?php
                /*
                | Most purposes carry no note. A column of empty cells
                | beside them is the same problem as a dash, so it only
                | appears when at least one row has something in it.
                */
                $anyNote = array_filter(
                    array_column($objectives[$id], 'description'),
                    'xlFilled'
                ) !== [];
                ?>
                <?php foreach ($objectives[$id] as $index => $objective): ?>
                    <tr>
                        <?= xlCell((string) ($index + 1), 1, 'v plain c') ?>
                        <?= xlCell(
                            pboCodeLabel((string) $objective['objective_code'], pboObjectiveLabels()),
                            $anyNote ? 5 : 11
                        ) ?>
                        <?= $anyNote ? xlCell($objective['description'], 6, 'v plain') : '' ?>
                    </tr>
                <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if (!empty($beneficiaries[$id])): ?>
                <table>
                <?= xlGrid() ?>
                <?= xlSub('Beneficiaries') ?>
                <?php
                $anyNote = array_filter(
                    array_column($beneficiaries[$id], 'description'),
                    'xlFilled'
                ) !== [];
                ?>
                <?php foreach ($beneficiaries[$id] as $index => $beneficiary): ?>
                    <tr>
                        <?= xlCell((string) ($index + 1), 1, 'v plain c') ?>
                        <?= xlCell(
                            pboCodeLabel((string) $beneficiary['beneficiary_code'], pboBeneficiaryLabels()),
                            $anyNote ? 5 : 11
                        ) ?>
                        <?= $anyNote ? xlCell($beneficiary['description'], 6, 'v plain') : '' ?>
                    </tr>
                <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if (!empty($officials[$id])): ?>
                <table>
                <?= xlGrid() ?>
                <?= xlSub('Officials and Directors') ?>
                <tr>
                    <?= xlHeading('Position', 2, 'left') ?>
                    <?= xlHeading('Name', 3, 'left') ?>
                    <?= xlHeading('Nationality', 2) ?>
                    <?= xlHeading('ID / Passport', 2) ?>
                    <?= xlHeading('Tax Identification', 3, 'left') ?>
                </tr>
                <?php foreach ($officials[$id] as $official): ?>
                    <?php $tax = officialTaxIdDisplay($official); ?>
                    <tr>
                        <?= xlCell(
                            ucwords(strtolower((string) $official['designation'])),
                            2,
                            'v plain'
                        ) ?>
                        <?= xlCell($official['full_name'], 3) ?>
                        <?= xlCell($official['nationality'], 2, 'v plain c') ?>
                        <?= xlCell($official['id_passport_number'], 2, 'v plain c') ?>
                        <?= xlCell(
                            $tax === null ? '' : $tax['label'] . ': ' . $tax['value'],
                            3,
                            'v plain'
                        ) ?>
                    </tr>
                <?php endforeach; ?>
                </table>
            <?php endif; ?>

        </div>

    <?php endforeach; ?>

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

<?php if ($format === 'print'): ?>
    <script>
        /* Offer the dialog straight away; the toolbar stays for a second go. */
        window.addEventListener('load', function () {
            window.setTimeout(function () { window.print(); }, 250);
        });
    </script>
<?php endif; ?>

</body>
</html>
