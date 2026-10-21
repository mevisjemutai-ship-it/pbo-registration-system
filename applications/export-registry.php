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
| The grid
|--------------------------------------------------------------------------
|
| Twelve columns divides cleanly into halves, thirds and quarters, which
| covers every layout below.
*/

const GRID_COLUMNS = 12;

/** How many organizations one export may carry. */
const MAX_DETAIL_RECORDS = 300;


function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/*
| Nothing recorded prints as nothing.
|
| This used to return an em dash. A dash in a cell reads as a value, and a
| column of them reads as a record somebody filled in badly - when usually
| the field simply does not apply. An empty cell says the same thing
| without the accusation.
*/
function value(mixed $raw): string
{
    return e(trim((string) $raw));
}

function typeLabel(string $type): string
{
    return pboRegistryTypes()[$type]
        ?? ucwords(strtolower(str_replace('_', ' ', $type)));
}

function prettyDate(?string $value): string
{
    $value = trim((string) $value);

    if ($value === '' || $value === '0000-00-00') {
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

/**
 * One label-and-value cell, or nothing at all when there is no value.
 *
 * A labelled box with nothing in it is the same problem as a dash: it
 * reads as a field somebody failed to complete, when usually it simply
 * does not apply. If there is no alternative phone number, there is no
 * Alternative Phone box.
 */
function field(string $label, mixed $raw, int $span = 4): string
{
    $raw = trim((string) $raw);

    if ($raw === '') {
        return '';
    }

    return '<td colspan="' . $span . '">'
        . '<span class="label">' . e($label) . '</span>'
        . '<span class="value">' . nl2br(e($raw)) . '</span>'
        . '</td>';
}

/**
 * Lay fields out in rows, skipping the empty ones and padding the last
 * row back out to the full grid - a row that stops short pulls every
 * section beneath it out of alignment in Excel.
 */
function fieldRows(array $fields, int $span = 4): string
{
    $cells = [];

    foreach ($fields as $label => $raw) {
        $cell = field((string) $label, $raw, $span);

        if ($cell !== '') {
            $cells[] = $cell;
        }
    }

    if ($cells === []) {
        return '';
    }

    $perRow = intdiv(GRID_COLUMNS, $span);
    $html = '';

    foreach (array_chunk($cells, $perRow) as $row) {
        $html .= '<tr>' . implode('', $row);

        $short = GRID_COLUMNS - (count($row) * $span);

        if ($short > 0) {
            $html .= '<td colspan="' . $short . '" class="pad"></td>';
        }

        $html .= '</tr>';
    }

    return $html;
}

/** The one colgroup every table repeats, so Excel builds a single grid. */
function grid(): string
{
    $html = '<colgroup>';

    for ($i = 0; $i < GRID_COLUMNS; $i++) {
        $html .= '<col style="width:' . round(100 / GRID_COLUMNS, 4) . '%">';
    }

    return $html . '</colgroup>';
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
            registration_date, registration_type,
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

/*
| A4 portrait with the same margins on every page, so a printed section
| lines up with the one before it.
*/
@page {
    size: A4 portrait;
    margin: 0.5in;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 10pt;
    color: #172b3a;
    margin: 0;
    padding: 0;
}

/*
| Every table is the full width of the page and uses the same fixed
| column grid. table-layout: fixed is what makes the browser honour the
| colgroup instead of resizing columns to fit their contents - which is
| what made sections print at different widths.
*/
table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    margin: 0;
}

td, th {
    border: 1px solid #d7e1e8;
    padding: 6px 8px;
    vertical-align: top;
    word-wrap: break-word;
    overflow-wrap: break-word;
    font-size: 9.5pt;
}

th {
    background: #eef3f7;
    font-size: 8.5pt;
    text-transform: uppercase;
    letter-spacing: .04em;
    text-align: left;
}

.masthead td {
    background: #123f67;
    color: #ffffff;
    border-color: #123f67;
    padding: 14px 12px;
}

.masthead-authority { font-size: 8.5pt; font-weight: bold; letter-spacing: .08em; }
.masthead-title { font-size: 17pt; font-weight: bold; }
.masthead-note { font-size: 9pt; }

.section-title td {
    background: #123f67;
    color: #ffffff;
    border-color: #123f67;
    font-size: 11pt;
    font-weight: bold;
    padding: 7px 10px;
}

.subsection td {
    background: #f6f9fb;
    border-top: 2px solid #d69a18;
    font-weight: bold;
    font-size: 9pt;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #4a5a68;
}

/*
| Labels were disappearing against the values - "Alternative Phone" and
| "Other Areas of Operation" in particular. Bolder and darker, not bigger:
| the value is still the thing being read, the label just has to hold its
| own beside it.
*/
/* Pads a short row out to the full grid; it is there for the grid, not
   for the reader, so it carries nothing. */
.pad {
    border-color: #ffffff;
    background: #ffffff;
}

.label {
    display: block;
    color: #41535f;
    font-size: 8pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: 2px;
}

.value {
    display: block;
    font-weight: bold;
    font-size: 10pt;
    color: #16242f;
}

.strong { font-weight: bold; }
.spacer td { border: 0; height: 10px; padding: 0; }
.muted { color: #8a949d; }

/*
| Short values sit centred in their column; addresses, descriptions and
| names stay left, because centred prose is hard to read down a page.
*/
td, th { text-align: center; }
.left, th.left { text-align: left; }

/* The name of the organization, with its label above it. */
.record-name td {
    background: #0f3c5f;
    border-color: #0f3c5f;
    color: #ffffff;
    padding: 12px 14px;
    text-align: left;
}

.record-name .label {
    color: #9dc4e0;
}

.record-name .name {
    display: block;
    font-size: 15pt;
    font-weight: bold;
    line-height: 1.2;
}

/* Each organization starts a new sheet of paper in details mode. */
.record { page-break-before: always; }
.record:first-of-type { page-break-before: auto; }

/* The print view only: a toolbar that never reaches the paper. */
.toolbar {
    padding: 10px 14px;
    background: #123f67;
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

@media print {
    .toolbar { display: none; }
}

</style>

</head>

<body>

<?php if ($format === 'print'): ?>
    <div class="toolbar">
        <?= e($description) ?>
        <button type="button" onclick="window.print()">Print</button>
        <button type="button" onclick="window.close()">Close</button>
    </div>
<?php endif; ?>


<table class="masthead">
    <?= grid() ?>
    <tr>
        <td colspan="<?= GRID_COLUMNS ?>">
            <div class="masthead-authority">
                PUBLIC BENEFIT ORGANIZATIONS REGULATORY AUTHORITY
            </div>
            <div class="masthead-title">
                List of Public Benefit Organizations
            </div>
            <div class="masthead-note">
                <?= e($description) ?>
                &nbsp;&middot;&nbsp;
                <?= count($records) ?>
                record<?= count($records) === 1 ? '' : 's' ?>
                &nbsp;&middot;&nbsp;
                Produced <?= e(date('j M Y')) ?>
            </div>
        </td>
    </tr>
</table>

<?php if ($truncated): ?>
    <table>
        <?= grid() ?>
        <tr>
            <td colspan="<?= GRID_COLUMNS ?>" class="strong">
                This export stops at <?= MAX_DETAIL_RECORDS ?> organizations.
                Narrow the filters and export the rest separately.
            </td>
        </tr>
    </table>
<?php endif; ?>

<?php if ($records === []): ?>

    <table>
        <?= grid() ?>
        <tr>
            <td colspan="<?= GRID_COLUMNS ?>" class="muted">
                No organizations match this selection.
            </td>
        </tr>
    </table>

<?php elseif ($mode === 'list'): ?>

    <table class="spacer"><?= grid() ?><tr><td colspan="<?= GRID_COLUMNS ?>"></td></tr></table>

    <table>
        <?= grid() ?>
        <tr>
            <th colspan="1">#</th>
            <th colspan="2">Registration No.</th>
            <th colspan="5" class="left">Name of Organization</th>
            <th colspan="2">Type</th>
            <th colspan="2">Registered</th>
        </tr>

        <?php foreach ($records as $index => $record): ?>
            <tr>
                <td colspan="1"><?= $index + 1 ?></td>
                <td colspan="2"><?= value($record['pbo_registration_number']) ?></td>
                <td colspan="5" class="strong left"><?= value($record['pbo_name']) ?></td>
                <td colspan="2"><?= value(typeLabel((string) $record['registration_type'])) ?></td>
                <td colspan="2"><?= value(prettyDate($record['registration_date'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

<?php else: ?>

    <?php foreach ($records as $record): ?>

        <?php $id = (int) $record['id']; ?>

        <div class="record">

            <table class="spacer"><?= grid() ?><tr><td colspan="<?= GRID_COLUMNS ?>"></td></tr></table>

            <table>
                <?= grid() ?>
                <tr class="record-name">
                    <td colspan="<?= GRID_COLUMNS ?>">
                        <span class="label">Name of Organization</span>
                        <span class="name"><?= value($record['pbo_name']) ?></span>
                    </td>
                </tr>
                <?php
                $start = monthName($record['financial_year_start_month']);
                $end = monthName($record['financial_year_end_month']);
                ?>

                <?= fieldRows([
                    'Registration Number' => $record['pbo_registration_number'],
                    'Date of Registration' => prettyDate($record['registration_date']),
                    'Type' => typeLabel((string) $record['registration_type']),
                    'Application Reference' => $record['application_reference'],
                    'Financial Year' => $start !== '' && $end !== '' ? $start . ' to ' . $end : '',
                    'Submitted' => prettyDate(substr((string) $record['submitted_at'], 0, 10)),
                ], 4) ?>
            </table>

            <?php $address = $addresses[$id] ?? null; ?>

            <table>
                <?= grid() ?>
                <tr class="subsection">
                    <td colspan="<?= GRID_COLUMNS ?>">Head Office</td>
                </tr>
                <?= fieldRows([
                    'County' => $address['county'] ?? '',
                    'Sub-County' => $address['sub_county'] ?? '',
                    'Postal Code' => $address['postal_code'] ?? '',
                    'Physical Address' => $address['physical_address'] ?? '',
                    'Postal Address' => $address['postal_address'] ?? '',
                    'Phone' => $address['phone_number'] ?? '',
                    'Alternative Phone' => $address['alternative_phone_number'] ?? '',
                    'Email' => $address['email'] ?? '',
                ], 4) ?>
            </table>

            <?php if (!empty($locations[$id])): ?>
                <table>
                    <?= grid() ?>
                    <tr class="subsection">
                        <td colspan="<?= GRID_COLUMNS ?>">
                            Other Areas of Operation
                            (<?= count($locations[$id]) ?>)
                        </td>
                    </tr>
                    <tr>
                        <th colspan="3" class="left">County</th>
                        <th colspan="3">Sub-County</th>
                        <th colspan="4" class="left">Physical Address</th>
                        <th colspan="2">Phone</th>
                    </tr>
                    <?php foreach ($locations[$id] as $location): ?>
                        <tr>
                            <td colspan="3" class="strong left"><?= value($location['county']) ?></td>
                            <td colspan="3"><?= value($location['sub_county']) ?></td>
                            <td colspan="4" class="left"><?= value($location['physical_address']) ?></td>
                            <td colspan="2"><?= value($location['phone_number']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if (!empty($objectives[$id])): ?>
                <table>
                    <?= grid() ?>
                    <tr class="subsection">
                        <td colspan="<?= GRID_COLUMNS ?>">Objectives</td>
                    </tr>
                    <?php foreach ($objectives[$id] as $objective): ?>
                        <tr>
                            <td colspan="4" class="strong">
                                <?= e(pboCodeLabel(
                                    (string) $objective['objective_code'],
                                    pboObjectiveLabels()
                                )) ?>
                            </td>
                            <td colspan="8" class="left"><?= value($objective['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if (!empty($beneficiaries[$id])): ?>
                <table>
                    <?= grid() ?>
                    <tr class="subsection">
                        <td colspan="<?= GRID_COLUMNS ?>">Beneficiaries</td>
                    </tr>
                    <?php foreach ($beneficiaries[$id] as $beneficiary): ?>
                        <tr>
                            <td colspan="4" class="strong">
                                <?= e(pboCodeLabel(
                                    (string) $beneficiary['beneficiary_code'],
                                    pboBeneficiaryLabels()
                                )) ?>
                            </td>
                            <td colspan="8" class="left"><?= value($beneficiary['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <?php if (!empty($officials[$id])): ?>
                <table>
                    <?= grid() ?>
                    <tr class="subsection">
                        <td colspan="<?= GRID_COLUMNS ?>">Officials and Directors</td>
                    </tr>
                    <tr>
                        <th colspan="2">Position</th>
                        <th colspan="3" class="left">Name</th>
                        <th colspan="2">Nationality</th>
                        <th colspan="2">ID / Passport</th>
                        <th colspan="3">Tax ID</th>
                    </tr>
                    <?php foreach ($officials[$id] as $official): ?>
                        <?php $tax = officialTaxIdDisplay($official); ?>
                        <tr>
                            <td colspan="2">
                                <?= value(ucwords(strtolower((string) $official['designation']))) ?>
                            </td>
                            <td colspan="3" class="strong left"><?= value($official['full_name']) ?></td>
                            <td colspan="2"><?= value($official['nationality']) ?></td>
                            <td colspan="2"><?= value($official['id_passport_number']) ?></td>
                            <td colspan="3">
                                <?php if ($tax !== null): ?>
                                    <span class="label"><?= e($tax['label']) ?></span>
                                    <span class="value"><?= e($tax['value']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

        </div>

    <?php endforeach; ?>

<?php endif; ?>

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
