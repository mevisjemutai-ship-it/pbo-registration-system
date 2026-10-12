<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
requirePasswordChanged();

/*
|--------------------------------------------------------------------------
| Database connection
|--------------------------------------------------------------------------
*/

$connection = $pdo ?? $db ?? null;

if (!$connection instanceof PDO) {
    http_response_code(500);
    exit('Database connection was not initialized.');
}


/*
|--------------------------------------------------------------------------
| Page configuration
|--------------------------------------------------------------------------
*/

$pageTitle = 'Submitted Records';
$activePage = 'records';

$isAdmin = currentUserRole() === 'admin';
$currentUserId = currentUserId();


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function recordsEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


function recordsInitials(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name);

    if (count($parts) === 1) {
        return strtoupper(
            substr($parts[0], 0, 1)
        );
    }

    return strtoupper(
        substr($parts[0], 0, 1) .
        substr($parts[count($parts) - 1], 0, 1)
    );
}


function recordsDate(?string $date): string
{
    if (!$date) {
        return '—';
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return '—';
    }

    return date('d M Y', $timestamp);
}


function recordsTime(?string $date): string
{
    if (!$date) {
        return '';
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return '';
    }

    return date('h:i A', $timestamp);
}


/*
|--------------------------------------------------------------------------
| Request filters
|--------------------------------------------------------------------------
*/

$search = trim(
    (string) ($_GET['q'] ?? '')
);

$selectedUserId = 0;

if (
    $isAdmin &&
    isset($_GET['submitted_by']) &&
    $_GET['submitted_by'] !== ''
) {
    $selectedUserId = max(
        0,
        (int) $_GET['submitted_by']
    );
}

$filterYear = 0;

if (
    isset($_GET['year']) &&
    $_GET['year'] !== ''
) {
    $filterYear = (int) $_GET['year'];
}

$filterMonth = 0;

if (
    isset($_GET['month']) &&
    $_GET['month'] !== ''
) {
    $filterMonth = (int) $_GET['month'];
}

if ($filterMonth < 1 || $filterMonth > 12) {
    $filterMonth = 0;
}

$page = isset($_GET['page'])
    ? max(1, (int) $_GET['page'])
    : 1;

$perPage = 10;


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
| Available submission years
|--------------------------------------------------------------------------
*/

$yearsStatement = $connection->query(
    "
    SELECT DISTINCT
        YEAR(submitted_at) AS submission_year

    FROM pbo_applications

    WHERE status = 'submitted'
      AND submitted_at IS NOT NULL

    ORDER BY submission_year DESC
    "
);

$availableYears = $yearsStatement->fetchAll(
    PDO::FETCH_COLUMN
);


/*
|--------------------------------------------------------------------------
| Accounts for the "Submitted by" filter
|--------------------------------------------------------------------------
|
| Only administrators need this list. It includes administrators and
| staff: every active account, plus any account (even a disabled one)
| that has submitted records, so older records can still be found.
|
*/

$staffUsers = [];

if ($isAdmin) {

    $staffStatement = $connection->query(
        "
        SELECT
            id,
            username,
            full_name,
            role

        FROM users

        WHERE is_active = 1
           OR id IN (
                SELECT DISTINCT submitted_by
                FROM pbo_applications
                WHERE status = 'submitted'
                  AND submitted_by IS NOT NULL
           )

        ORDER BY full_name ASC
        "
    );

    $staffUsers = $staffStatement->fetchAll(
        PDO::FETCH_ASSOC
    );
}


/*
|--------------------------------------------------------------------------
| Build record filters
|--------------------------------------------------------------------------
*/

$where = [];

$params = [];


/*
| Submitted records only
*/

$where[] = "a.status = 'submitted'";
$where[] = "a.submitted_at IS NOT NULL";


/*
| Staff visibility
|
| Staff members only see records they submitted.
*/

if (!$isAdmin) {

    $where[] = "a.submitted_by = :current_user_id";

    $params[':current_user_id'] = $currentUserId;
}


/*
| Administrator staff filter
*/

if (
    $isAdmin &&
    $selectedUserId > 0
) {

    $where[] = "a.submitted_by = :selected_user_id";

    $params[':selected_user_id'] = $selectedUserId;
}


/*
| Search
|
| Important:
| Use separate named placeholders.
|
| Reusing :search multiple times can produce
| HY093 with native MySQL PDO.
*/

if ($search !== '') {

    $where[] = "
        (
            a.application_reference LIKE :search_reference
            OR a.pbo_name LIKE :search_name
            OR a.pbo_registration_number LIKE :search_registration
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[':search_reference'] = $searchValue;
    $params[':search_name'] = $searchValue;
    $params[':search_registration'] = $searchValue;
}


/*
| Year
*/

if ($filterYear > 0) {

    $where[] =
        "YEAR(a.submitted_at) = :filter_year";

    $params[':filter_year'] = $filterYear;
}


/*
| Month
*/

if ($filterMonth > 0) {

    $where[] =
        "MONTH(a.submitted_at) = :filter_month";

    $params[':filter_month'] = $filterMonth;
}


$whereSql = implode(
    "\n AND ",
    $where
);


/*
|--------------------------------------------------------------------------
| Dashboard scope
|--------------------------------------------------------------------------
*/

$countScope = '';
$countParams = [];

if (!$isAdmin) {

    $countScope =
        "AND a.submitted_by = :count_user_id";

    $countParams[':count_user_id'] =
        $currentUserId;

} elseif ($selectedUserId > 0) {

    $countScope =
        "AND a.submitted_by = :count_selected_user_id";

    $countParams[':count_selected_user_id'] =
        $selectedUserId;
}


/*
|--------------------------------------------------------------------------
| Total submitted
|--------------------------------------------------------------------------
*/

$totalStatement = $connection->prepare(
    "
    SELECT COUNT(*)

    FROM pbo_applications a

    WHERE a.status = 'submitted'
      AND a.submitted_at IS NOT NULL

      $countScope
    "
);

$totalStatement->execute(
    $countParams
);

$totalSubmitted = (int)
    $totalStatement->fetchColumn();


/*
|--------------------------------------------------------------------------
| Submitted this year
|--------------------------------------------------------------------------
*/

$thisYearStatement = $connection->prepare(
    "
    SELECT COUNT(*)

    FROM pbo_applications a

    WHERE a.status = 'submitted'
      AND a.submitted_at IS NOT NULL

      AND YEAR(a.submitted_at) = YEAR(CURDATE())

      $countScope
    "
);

$thisYearStatement->execute(
    $countParams
);

$thisYear = (int)
    $thisYearStatement->fetchColumn();


/*
|--------------------------------------------------------------------------
| Submitted this month
|--------------------------------------------------------------------------
*/

$thisMonthStatement = $connection->prepare(
    "
    SELECT COUNT(*)

    FROM pbo_applications a

    WHERE a.status = 'submitted'
      AND a.submitted_at IS NOT NULL

      AND YEAR(a.submitted_at) = YEAR(CURDATE())
      AND MONTH(a.submitted_at) = MONTH(CURDATE())

      $countScope
    "
);

$thisMonthStatement->execute(
    $countParams
);

$thisMonth = (int)
    $thisMonthStatement->fetchColumn();


/*
|--------------------------------------------------------------------------
| Filtered record count
|--------------------------------------------------------------------------
*/

$countFilteredStatement = $connection->prepare(
    "
    SELECT COUNT(*)

    FROM pbo_applications a

    WHERE $whereSql
    "
);

$countFilteredStatement->execute(
    $params
);

$totalFiltered = (int)
    $countFilteredStatement->fetchColumn();


/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$totalPages = max(
    1,
    (int) ceil(
        $totalFiltered / $perPage
    )
);

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;


/*
|--------------------------------------------------------------------------
| Main records query
|--------------------------------------------------------------------------
*/

$dataStatement = $connection->prepare(
    "
    SELECT

        a.id,

        a.application_reference,

        a.pbo_name,

        a.pbo_registration_number,

        a.registration_date,

        a.registration_type,

        a.status,

        a.submitted_by,

        a.submitted_at,

        u.full_name
            AS submitted_by_name,

        u.username
            AS submitted_by_username,

        u.role
            AS submitted_by_role

    FROM pbo_applications a

    LEFT JOIN users u
        ON u.id = a.submitted_by

    WHERE $whereSql

    ORDER BY
        a.submitted_at DESC,
        a.id DESC

    LIMIT :limit_value
    OFFSET :offset_value
    "
);


/*
| Bind filter parameters.
*/

foreach (
    $params
    as $parameterName => $parameterValue
) {

    if (is_int($parameterValue)) {

        $dataStatement->bindValue(
            $parameterName,
            $parameterValue,
            PDO::PARAM_INT
        );

    } else {

        $dataStatement->bindValue(
            $parameterName,
            $parameterValue,
            PDO::PARAM_STR
        );
    }
}


$dataStatement->bindValue(
    ':limit_value',
    $perPage,
    PDO::PARAM_INT
);

$dataStatement->bindValue(
    ':offset_value',
    $offset,
    PDO::PARAM_INT
);


$dataStatement->execute();


$records = $dataStatement->fetchAll(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| Selected staff name
|--------------------------------------------------------------------------
*/

$selectedStaffName = '';

if (
    $isAdmin &&
    $selectedUserId > 0
) {

    foreach (
        $staffUsers
        as $staff
    ) {

        if (
            (int) $staff['id']
            === $selectedUserId
        ) {

            $selectedStaffName =
                (string) $staff['full_name'];

            break;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

$paginationParameters = [];

if ($search !== '') {
    $paginationParameters['q'] =
        $search;
}

if (
    $isAdmin &&
    $selectedUserId > 0
) {
    $paginationParameters['submitted_by'] =
        $selectedUserId;
}

if ($filterYear > 0) {
    $paginationParameters['year'] =
        $filterYear;
}

if ($filterMonth > 0) {
    $paginationParameters['month'] =
        $filterMonth;
}


function recordsPageUrl(
    int $pageNumber,
    array $parameters
): string {

    $parameters['page'] =
        $pageNumber;

    return '?' .
        http_build_query(
            $parameters
        );
}


/*
|--------------------------------------------------------------------------
| Active filter state
|--------------------------------------------------------------------------
*/

$hasFilters =
    $search !== '' ||
    $selectedUserId > 0 ||
    $filterYear > 0 ||
    $filterMonth > 0;


/*
|--------------------------------------------------------------------------
| Shared site header
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/header.php';

?>

<style>

/* ==============================================================
   SUBMITTED RECORDS
   Uses the shared colour variables from assets/css/style.css.
   ============================================================== */

.rec {
    --rec-ink: var(--heading, #15283b);
    --rec-muted: #5f6b78;
    --rec-line: #e2e6eb;
    --rec-radius: 12px;

    width: 100%;
    max-width: 1320px;
    margin: 0 auto;
    color: var(--text, #26313d);
    font-variant-numeric: tabular-nums;
}

.rec h1,
.rec h2,
.rec h3,
.rec p {
    margin: 0;
}

.rec-panel {
    background: #ffffff;
    border: 1px solid var(--rec-line);
    border-radius: var(--rec-radius);
}

.rec a:focus-visible,
.rec button:focus-visible,
.rec input:focus-visible,
.rec select:focus-visible {
    outline: 3px solid rgba(201, 154, 46, 0.55);
    outline-offset: 2px;
}


/* ---------- Title + figures (same family as List of PBOs) ---------- */

.rec-head {
    position: relative;
    margin-bottom: 18px;
    overflow: hidden;
    border: 0;
    border-radius: 14px;
    box-shadow: 0 9px 28px rgba(8, 45, 75, .12);
}

.rec-title {
    position: relative;
    padding: 28px 32px 24px;
    background: linear-gradient(112deg, #062945, #0b3557 58%, #176b9f);
    color: #ffffff;
}

.rec-title::after {
    content: "";
    position: absolute;
    top: -105px;
    right: -55px;
    width: 235px;
    height: 235px;
    border: 38px solid rgba(255, 255, 255, .04);
    border-radius: 50%;
    pointer-events: none;
}

.rec-title h1 {
    position: relative;
    z-index: 1;
    color: #ffffff;
    font-size: 30px;
    font-weight: 800;
    letter-spacing: -0.015em;
    line-height: 1.15;
}

.rec-title p {
    position: relative;
    z-index: 1;
    max-width: 70ch;
    margin-top: 8px;
    color: rgba(255, 255, 255, .8);
    font-size: 14px;
    line-height: 1.55;
}

.rec-ledger {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    border-top: 5px solid #d69a18;
    background: #ffffff;
}

.rec-figure {
    padding: 18px 32px 20px;
}

.rec-figure + .rec-figure {
    border-left: 1px solid var(--rec-line);
}

.rec-figure strong {
    display: block;
    color: var(--rec-ink);
    font-size: 28px;
    font-weight: 750;
    line-height: 1.1;
}

.rec-figure span {
    display: block;
    margin-top: 6px;
    color: var(--rec-ink);
    font-size: 14px;
    font-weight: 600;
}

.rec-figure span::before {
    content: "";
    display: inline-block;
    width: 9px;
    height: 9px;
    margin-right: 8px;
    border-radius: 50%;
    background: var(--figure-accent, var(--blue));
    vertical-align: 1px;
}

.rec-figure small {
    display: block;
    margin-top: 2px;
    color: var(--rec-muted);
    font-size: 12.5px;
}


/* ---------- Filters ---------- */

.rec-filters {
    margin-bottom: 18px;
    padding: 18px 20px;
}

.rec-filter-grid {
    display: grid;
    grid-template-columns: minmax(260px, 2fr) repeat(var(--rec-selects, 2), minmax(140px, 1fr)) auto;
    gap: 12px;
    align-items: end;
}

.rec-field label {
    display: block;
    margin-bottom: 6px;
    color: var(--rec-ink);
    font-size: 13px;
    font-weight: 600;
}

.rec-control {
    width: 100%;
    height: 44px;
    padding: 0 12px;
    border: 1px solid #c9d1da;
    border-radius: 8px;
    background: #ffffff;
    color: var(--rec-ink);
    font-size: 14px;
}

.rec-control:hover {
    border-color: #aab6c3;
}

.rec-control:focus {
    border-color: var(--blue, #123b69);
    box-shadow: 0 0 0 3px rgba(18, 59, 105, 0.12);
    outline: none;
}

.rec-search {
    position: relative;
}

.rec-search svg {
    position: absolute;
    top: 50%;
    left: 12px;
    width: 17px;
    height: 17px;
    transform: translateY(-50%);
    color: #7d8895;
    pointer-events: none;
}

.rec-search .rec-control {
    padding-left: 38px;
}

.rec-filter-actions {
    display: flex;
    gap: 8px;
}

.rec-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 44px;
    padding: 0 18px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 650;
    white-space: nowrap;
    cursor: pointer;
    text-decoration: none;
}

.rec-button-primary {
    background: var(--blue, #123b69);
    border: 1px solid var(--blue, #123b69);
    color: #ffffff;
}

.rec-button-primary:hover {
    background: var(--blue-dark, #092b50);
    color: #ffffff;
}

.rec-button-quiet {
    background: #ffffff;
    border: 1px solid #c9d1da;
    color: var(--rec-ink);
}

.rec-button-quiet:hover {
    background: var(--blue-soft, #f6f9fc);
    color: var(--rec-ink);
}

.rec-active {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #eef1f4;
    color: var(--rec-muted);
    font-size: 13px;
}

.rec-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 999px;
    background: var(--blue-light, #edf4fb);
    color: var(--blue-dark, #092b50);
    font-size: 13px;
    font-weight: 600;
}

.rec-clear {
    margin-left: 4px;
    color: var(--red, #b5222a);
    font-weight: 600;
}

.rec-clear:hover {
    color: var(--red-dark, #8f1720);
    text-decoration: underline;
}


/* ---------- Results ---------- */

.rec-results {
    overflow: hidden;
}

.rec-results-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 16px;
    padding: 20px 24px 14px;
}

.rec-results-head h2 {
    color: var(--rec-ink);
    font-size: 17px;
    font-weight: 700;
}

.rec-results-head p {
    margin-top: 3px;
    color: var(--rec-muted);
    font-size: 13px;
}

.rec-total {
    flex-shrink: 0;
    color: var(--rec-muted);
    font-size: 13px;
}

.rec-total strong {
    color: var(--rec-ink);
}

.rec-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.rec-table {
    width: 100%;
    min-width: 980px;
    border-collapse: collapse;
}

.rec-table th {
    padding: 11px 16px;
    background: #f6f8fa;
    border-top: 1px solid var(--rec-line);
    border-bottom: 1px solid var(--rec-line);
    color: var(--rec-muted);
    font-size: 12.5px;
    font-weight: 600;
    text-align: left;
    white-space: nowrap;
}

.rec-table td {
    padding: 15px 16px;
    border-bottom: 1px solid #eef1f4;
    font-size: 14px;
    vertical-align: middle;
}

.rec-table tbody tr:last-child td {
    border-bottom: 0;
}

.rec-table tbody tr:hover td {
    background: var(--blue-soft, #f6f9fc);
}

.rec-table th:last-child {
    position: relative;
}

.rec-sr {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

.rec-table th:first-child,
.rec-table td:first-child {
    padding-left: 24px;
}

.rec-table th:last-child,
.rec-table td:last-child {
    padding-right: 24px;
    text-align: right;
}

.rec-org {
    min-width: 230px;
    max-width: 340px;
}

.rec-org a {
    color: var(--rec-ink);
    font-size: 14.5px;
    font-weight: 650;
    line-height: 1.4;
}

.rec-org a:hover {
    color: var(--blue, #123b69);
    text-decoration: underline;
}

.rec-sub {
    display: block;
    margin-top: 2px;
    color: var(--rec-muted);
    font-size: 12.5px;
}

.rec-ref {
    color: var(--blue-dark, #092b50);
    font-weight: 600;
    white-space: nowrap;
}

.rec-number {
    white-space: nowrap;
}

.rec-number.is-empty {
    color: #8a949f;
    font-style: italic;
}

.rec-type {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
}

.rec-type-national {
    background: var(--blue-light, #edf4fb);
    color: var(--blue, #123b69);
}

.rec-type-international {
    background: var(--red-light, #fbeaec);
    color: var(--red-dark, #8f1720);
}

.rec-person {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 170px;
}

.rec-avatar {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--gold-light, #fff8e5);
    border: 1px solid #ecd9a4;
    color: var(--gold-dark, #9d741d);
    font-size: 11.5px;
    font-weight: 700;
}

.rec-person strong {
    display: block;
    color: var(--rec-ink);
    font-size: 13.5px;
    font-weight: 600;
}

.rec-date {
    white-space: nowrap;
}

.rec-view {
    display: inline-flex;
    align-items: center;
    height: 34px;
    padding: 0 14px;
    border: 1px solid #c9d1da;
    border-radius: 8px;
    background: #ffffff;
    color: var(--blue, #123b69);
    font-size: 13px;
    font-weight: 650;
    white-space: nowrap;
}

.rec-view:hover {
    background: var(--blue, #123b69);
    border-color: var(--blue, #123b69);
    color: #ffffff;
}


/* ---------- Empty ---------- */

.rec-empty {
    margin: 4px 24px 24px;
    padding: 34px 24px;
    border: 1px dashed #cfd6de;
    border-radius: 10px;
    text-align: center;
}

.rec-empty h3 {
    color: var(--rec-ink);
    font-size: 16px;
    font-weight: 700;
}

.rec-empty p {
    max-width: 52ch;
    margin: 6px auto 0;
    color: var(--rec-muted);
    font-size: 14px;
    line-height: 1.55;
}

.rec-empty .rec-button {
    margin-top: 14px;
}


/* ---------- Pagination ---------- */

.rec-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 14px 24px;
    border-top: 1px solid var(--rec-line);
    color: var(--rec-muted);
    font-size: 13px;
}

.rec-pagination strong {
    color: var(--rec-ink);
}

.rec-pages {
    display: flex;
    align-items: center;
    gap: 4px;
}

.rec-page {
    display: inline-grid;
    place-items: center;
    min-width: 36px;
    height: 36px;
    padding: 0 8px;
    border: 1px solid #d5dce3;
    border-radius: 8px;
    background: #ffffff;
    color: var(--rec-ink);
    font-size: 13px;
    font-weight: 600;
}

.rec-page:hover {
    background: var(--blue-soft, #f6f9fc);
    color: var(--rec-ink);
}

.rec-page.is-current {
    background: var(--blue, #123b69);
    border-color: var(--blue, #123b69);
    color: #ffffff;
}

.rec-page.is-disabled {
    opacity: 0.4;
    pointer-events: none;
}

.rec-dots {
    padding: 0 4px;
}


/* ---------- Responsive ---------- */

@media (max-width: 1100px) {

    .rec-filter-grid {
        grid-template-columns: repeat(var(--rec-selects, 2), minmax(0, 1fr));
    }

    .rec-filter-grid .rec-field-search,
    .rec-filter-actions {
        grid-column: 1 / -1;
    }
}

@media (max-width: 720px) {

    .rec-title,
    .rec-figure {
        padding-left: 20px;
        padding-right: 20px;
    }

    .rec-title h1 {
        font-size: 25px;
    }

    .rec-ledger,
    .rec-filter-grid {
        grid-template-columns: 1fr;
    }

    .rec-figure + .rec-figure {
        border-left: 0;
        border-top: 1px solid var(--rec-line);
    }

    .rec-filter-actions .rec-button {
        flex: 1;
    }

    .rec-results-head,
    .rec-pagination {
        flex-direction: column;
        align-items: flex-start;
        padding-left: 18px;
        padding-right: 18px;
    }

    .rec-pages {
        flex-wrap: wrap;
    }
}

@media print {

    .rec-filters,
    .rec-ledger,
    .rec-pagination,
    .rec-table th:last-child,
    .rec-table td:last-child {
        display: none !important;
    }

    .rec-panel {
        border: 0;
    }

    .rec-table {
        min-width: 0;
    }
}

</style>


<div class="rec">

    <!-- ============================================================
         TITLE + FIGURES
         ============================================================ -->

    <section class="rec-panel rec-head">

        <div class="rec-title">

            <h1>
                Submitted records
            </h1>

            <p>
                <?php if ($isAdmin): ?>
                    Every registration application submitted to the registry.
                    Search by organization, reference or PBO number, or narrow
                    by who submitted it and when.
                <?php else: ?>
                    Registration applications you have submitted. Search by
                    organization, reference or PBO number, or narrow by
                    submission date.
                <?php endif; ?>
            </p>

        </div>

        <div class="rec-ledger">

            <div class="rec-figure" style="--figure-accent: var(--blue);">
                <strong><?= number_format($totalSubmitted) ?></strong>
                <span>Total submitted</span>
                <small><?= $isAdmin && $selectedUserId === 0 ? 'Across all users' : ($isAdmin ? 'By the selected user' : 'Submitted by you') ?></small>
            </div>

            <div class="rec-figure" style="--figure-accent: var(--gold);">
                <strong><?= number_format($thisYear) ?></strong>
                <span>Submitted in <?= date('Y') ?></span>
                <small>Year to date</small>
            </div>

            <div class="rec-figure" style="--figure-accent: var(--red);">
                <strong><?= number_format($thisMonth) ?></strong>
                <span>Submitted in <?= date('F') ?></span>
                <small>This month so far</small>
            </div>

        </div>

    </section>


    <!-- ============================================================
         SEARCH AND FILTER
         ============================================================ -->

    <section class="rec-panel rec-filters" aria-label="Search and filter records">

        <form method="get" action="">

            <div
                class="rec-filter-grid"
                style="--rec-selects: <?= $isAdmin ? 3 : 2 ?>;"
            >

                <div class="rec-field rec-field-search">

                    <label for="records-search">
                        Search
                    </label>

                    <div class="rec-search">

                        <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <circle cx="8.5" cy="8.5" r="5.75" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M13 13l4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                        </svg>

                        <input
                            type="search"
                            id="records-search"
                            name="q"
                            class="rec-control"
                            value="<?= recordsEscape($search) ?>"
                            placeholder="Organization, reference or PBO number"
                        >

                    </div>

                </div>


                <?php if ($isAdmin): ?>

                    <div class="rec-field">

                        <label for="submitted_by">
                            Submitted by
                        </label>

                        <select id="submitted_by" name="submitted_by" class="rec-control">

                            <option value="">All users</option>

                            <?php foreach (['admin' => 'Administrators', 'staff' => 'Staff'] as $roleKey => $roleGroup): ?>

                                <optgroup label="<?= $roleGroup ?>">

                                    <?php foreach ($staffUsers as $staff): ?>

                                        <?php if (($staff['role'] ?? 'staff') !== $roleKey) { continue; } ?>

                                        <option
                                            value="<?= (int) $staff['id'] ?>"
                                            <?= $selectedUserId === (int) $staff['id'] ? 'selected' : '' ?>
                                        >
                                            <?= recordsEscape($staff['full_name']) ?><?= (int) $staff['id'] === $currentUserId ? ' (you)' : '' ?>
                                        </option>

                                    <?php endforeach; ?>

                                </optgroup>

                            <?php endforeach; ?>

                        </select>

                    </div>

                <?php endif; ?>


                <div class="rec-field">

                    <label for="records-year">
                        Year
                    </label>

                    <select id="records-year" name="year" class="rec-control">

                        <option value="">All years</option>

                        <?php foreach ($availableYears as $year): ?>

                            <option
                                value="<?= (int) $year ?>"
                                <?= $filterYear === (int) $year ? 'selected' : '' ?>
                            >
                                <?= (int) $year ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="rec-field">

                    <label for="records-month">
                        Month
                    </label>

                    <select id="records-month" name="month" class="rec-control">

                        <option value="">All months</option>

                        <?php foreach ($months as $monthNumber => $monthName): ?>

                            <option
                                value="<?= $monthNumber ?>"
                                <?= $filterMonth === $monthNumber ? 'selected' : '' ?>
                            >
                                <?= recordsEscape($monthName) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="rec-filter-actions">

                    <button type="submit" class="rec-button rec-button-primary">
                        Search
                    </button>

                    <?php if ($hasFilters): ?>
                        <a href="records.php" class="rec-button rec-button-quiet">
                            Reset
                        </a>
                    <?php endif; ?>

                </div>

            </div>


            <?php if ($hasFilters): ?>

                <div class="rec-active">

                    <span>Showing results for</span>

                    <?php if ($search !== ''): ?>
                        <span class="rec-chip">&ldquo;<?= recordsEscape($search) ?>&rdquo;</span>
                    <?php endif; ?>

                    <?php if ($selectedStaffName !== ''): ?>
                        <span class="rec-chip"><?= recordsEscape($selectedStaffName) ?></span>
                    <?php endif; ?>

                    <?php if ($filterMonth > 0): ?>
                        <span class="rec-chip"><?= recordsEscape($months[$filterMonth]) ?></span>
                    <?php endif; ?>

                    <?php if ($filterYear > 0): ?>
                        <span class="rec-chip"><?= (int) $filterYear ?></span>
                    <?php endif; ?>

                    <a href="records.php" class="rec-clear">Clear filters</a>

                </div>

            <?php endif; ?>

        </form>

    </section>


    <!-- ============================================================
         RESULTS
         ============================================================ -->

    <section class="rec-panel rec-results" aria-labelledby="rec-results-title">

        <div class="rec-results-head">

            <div>

                <h2 id="rec-results-title">
                    Registration records
                </h2>

                <p>
                    <?php if ($selectedStaffName !== ''): ?>
                        Submitted by <?= recordsEscape($selectedStaffName) ?>, newest first.
                    <?php elseif (!$isAdmin): ?>
                        Submitted by you, newest first.
                    <?php else: ?>
                        All users, newest first.
                    <?php endif; ?>
                </p>

            </div>

            <span class="rec-total">
                <strong><?= number_format($totalFiltered) ?></strong>
                <?= $totalFiltered === 1 ? 'record' : 'records' ?>
            </span>

        </div>


        <?php if (!empty($records)): ?>

            <div class="rec-table-wrap">

                <table class="rec-table">

                    <thead>
                        <tr>
                            <th scope="col">Organization</th>
                            <th scope="col">Reference</th>
                            <th scope="col">PBO number</th>
                            <th scope="col">Type</th>
                            <th scope="col">Submitted by</th>
                            <th scope="col">Submitted on</th>
                            <th scope="col"><span class="rec-sr">Actions</span></th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($records as $record): ?>

                            <?php

                            $submitterName = trim((string) ($record['submitted_by_name'] ?? ''));

                            if ($submitterName === '') {
                                $submitterName = 'Unknown user';
                            }

                            $submitterUsername = trim((string) ($record['submitted_by_username'] ?? ''));

                            $registrationType = strtolower((string) ($record['registration_type'] ?? ''));

                            $viewUrl = 'view.php?id=' . (int) $record['id'];

                            ?>

                            <tr>

                                <td class="rec-org">

                                    <a href="<?= recordsEscape($viewUrl) ?>">
                                        <?= recordsEscape($record['pbo_name']) ?>
                                    </a>

                                    <?php if (!empty($record['registration_date'])): ?>
                                        <span class="rec-sub">
                                            Registered <?= recordsEscape(recordsDate($record['registration_date'])) ?>
                                        </span>
                                    <?php endif; ?>

                                </td>

                                <td>
                                    <span class="rec-ref">
                                        <?= recordsEscape($record['application_reference']) ?>
                                    </span>
                                </td>

                                <td>

                                    <?php if (!empty($record['pbo_registration_number'])): ?>

                                        <span class="rec-number">
                                            <?= recordsEscape($record['pbo_registration_number']) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="rec-number is-empty">
                                            Not assigned
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>
                                    <span class="rec-type rec-type-<?= $registrationType === 'international' ? 'international' : 'national' ?>">
                                        <?= $registrationType === 'international' ? 'International' : 'National' ?>
                                    </span>
                                </td>

                                <td>

                                    <div class="rec-person">

                                        <span class="rec-avatar" aria-hidden="true">
                                            <?= recordsEscape(recordsInitials($submitterName)) ?>
                                        </span>

                                        <span>
                                            <strong><?= recordsEscape($submitterName) ?></strong>

                                            <?php if ($submitterUsername !== ''): ?>
                                                <span class="rec-sub">
                                                    @<?= recordsEscape($submitterUsername) ?><?= ($record['submitted_by_role'] ?? '') === 'admin' ? ' &middot; Administrator' : '' ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>

                                    </div>

                                </td>

                                <td class="rec-date">

                                    <?= recordsEscape(recordsDate($record['submitted_at'])) ?>

                                    <span class="rec-sub">
                                        <?= recordsEscape(recordsTime($record['submitted_at'])) ?>
                                    </span>

                                </td>

                                <td>
                                    <a
                                        href="<?= recordsEscape($viewUrl) ?>"
                                        class="rec-view"
                                        aria-label="View <?= recordsEscape($record['pbo_name']) ?>"
                                    >
                                        View
                                    </a>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>


            <?php if ($totalPages > 1): ?>

                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                ?>

                <div class="rec-pagination">

                    <span>
                        Showing
                        <strong><?= number_format($offset + 1) ?></strong>
                        to
                        <strong><?= number_format(min($offset + $perPage, $totalFiltered)) ?></strong>
                        of
                        <strong><?= number_format($totalFiltered) ?></strong>
                    </span>

                    <nav class="rec-pages" aria-label="Pagination">

                        <?php if ($page > 1): ?>
                            <a class="rec-page" href="<?= recordsEscape(recordsPageUrl($page - 1, $paginationParameters)) ?>" aria-label="Previous page">&lsaquo;</a>
                        <?php else: ?>
                            <span class="rec-page is-disabled" aria-hidden="true">&lsaquo;</span>
                        <?php endif; ?>

                        <?php if ($startPage > 1): ?>
                            <a class="rec-page" href="<?= recordsEscape(recordsPageUrl(1, $paginationParameters)) ?>">1</a>
                            <?php if ($startPage > 2): ?>
                                <span class="rec-dots">&hellip;</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $startPage; $p <= $endPage; $p++): ?>

                            <?php if ($p === $page): ?>
                                <span class="rec-page is-current" aria-current="page"><?= $p ?></span>
                            <?php else: ?>
                                <a class="rec-page" href="<?= recordsEscape(recordsPageUrl($p, $paginationParameters)) ?>"><?= $p ?></a>
                            <?php endif; ?>

                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="rec-dots">&hellip;</span>
                            <?php endif; ?>
                            <a class="rec-page" href="<?= recordsEscape(recordsPageUrl($totalPages, $paginationParameters)) ?>"><?= $totalPages ?></a>
                        <?php endif; ?>

                        <?php if ($page < $totalPages): ?>
                            <a class="rec-page" href="<?= recordsEscape(recordsPageUrl($page + 1, $paginationParameters)) ?>" aria-label="Next page">&rsaquo;</a>
                        <?php else: ?>
                            <span class="rec-page is-disabled" aria-hidden="true">&rsaquo;</span>
                        <?php endif; ?>

                    </nav>

                </div>

            <?php endif; ?>

        <?php else: ?>

            <div class="rec-empty">

                <?php if ($hasFilters): ?>

                    <h3>No records match these filters</h3>

                    <p>
                        Check the spelling of the organization name or reference,
                        or widen the date range.
                    </p>

                    <a href="records.php" class="rec-button rec-button-quiet">
                        Clear filters
                    </a>

                <?php else: ?>

                    <h3>No submitted records yet</h3>

                    <p>
                        Applications appear here once they are submitted from the
                        review step.
                    </p>

                    <a href="/pbo-portal/applications/create.php" class="rec-button rec-button-primary">
                        Start a new application
                    </a>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </section>

</div>


<?php

/*
|--------------------------------------------------------------------------
| Shared site footer
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/footer.php';

?>