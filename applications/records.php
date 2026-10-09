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
| Active staff accounts
|--------------------------------------------------------------------------
|
| Only administrators need this list.
|
*/

$staffUsers = [];

if ($isAdmin) {

    $staffStatement = $connection->query(
        "
        SELECT
            id,
            username,
            full_name

        FROM users

        WHERE role = 'staff'
          AND is_active = 1

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

/*
|--------------------------------------------------------------------------
| Submitted Records
|--------------------------------------------------------------------------
|
| This stylesheet is intentionally scoped to this page.
| The shared header, navigation and footer remain controlled by
| the site's existing assets/css/style.css and include files.
|--------------------------------------------------------------------------
*/


.records-page {
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
}


/*
|--------------------------------------------------------------------------
| Page heading
|--------------------------------------------------------------------------
*/

.records-heading {
    position: relative;
    overflow: hidden;

    margin-bottom: 24px;
    padding: 27px 30px;

    background:
        linear-gradient(
            110deg,
            #eaf0f6 0%,
            #f7f9fb 54%,
            #f8f4e9 100%
        );

    border: 1px solid #d5e0e9;

    border-left: 5px solid #587492;

    border-bottom: 2px solid #d5b96a;

    border-radius: 10px;

    box-shadow:
        0 3px 12px rgba(31, 52, 73, 0.06);
}


.records-heading::after {
    content: "";

    position: absolute;

    right: -55px;
    top: -120px;

    width: 270px;
    height: 270px;

    border: 34px solid
        rgba(47, 73, 110, 0.035);

    border-radius: 50%;
}


.records-heading-content {
    position: relative;
    z-index: 2;
}


.records-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    margin-bottom: 7px;

    color: #71839a;

    font-size: 10px;
    font-weight: 800;

    letter-spacing: 1.25px;
    text-transform: uppercase;
}


.records-eyebrow::before {
    content: "";

    width: 21px;
    height: 2px;

    background: #bf962d;

    border-radius: 10px;
}


.records-heading h1 {
    margin: 0;

    color: #30465e;

    font-size: 27px;
    line-height: 1.2;

    font-weight: 750;

    text-align: left;
    text-transform: none;
}


.records-heading p {
    max-width: 800px;

    margin: 7px 0 0;

    color: #68788a;

    font-size: 12px;
    line-height: 1.55;
}


/*
|--------------------------------------------------------------------------
| Dashboard cards
|--------------------------------------------------------------------------
*/

.records-stat-grid {
    display: grid;

    grid-template-columns:
        repeat(3, minmax(0, 1fr));

    gap: 17px;

    margin-bottom: 22px;
}


.records-stat {
    position: relative;

    min-height: 126px;

    overflow: hidden;

    padding: 20px 21px;

    border-radius: 11px;

    box-shadow:
        0 3px 11px rgba(31, 52, 73, 0.07);
}


.records-stat::after {
    content: "";

    position: absolute;

    width: 115px;
    height: 115px;

    right: -47px;
    bottom: -61px;

    border-radius: 50%;

    background: rgba(255,255,255,0.10);
}


.records-stat-blue {
    background:
        linear-gradient(
            135deg,
            #526d8c,
            #67809b
        );

    color: #fff;
}


.records-stat-red {
    background:
        linear-gradient(
            135deg,
            #a9545d,
            #bd6970
        );

    color: #fff;
}


.records-stat-gold {
    background:
        linear-gradient(
            135deg,
            #a68b46,
            #b9a05c
        );

    color: #fff;
}


.records-stat-top {
    position: relative;
    z-index: 2;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 10px;
}


.records-stat-label {
    font-size: 10px;
    font-weight: 800;

    letter-spacing: 1px;
    text-transform: uppercase;

    opacity: .86;
}


.records-stat-icon {
    width: 34px;
    height: 34px;

    display: grid;
    place-items: center;

    border-radius: 9px;

    background: rgba(255,255,255,.13);

    font-size: 13px;
    font-weight: 800;
}


.records-stat-number {
    position: relative;
    z-index: 2;

    display: block;

    margin-top: 13px;

    font-size: 30px;
    line-height: 1;

    font-weight: 800;
}


.records-stat-description {
    position: relative;
    z-index: 2;

    margin-top: 7px;

    color: rgba(255,255,255,.76);

    font-size: 10px;
}


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

.records-filter-card {
    margin-bottom: 22px;

    background: #fff;

    border: 1px solid #dfe5eb;

    border-radius: 10px;

    box-shadow:
        0 2px 9px rgba(31,52,73,.05);

    overflow: hidden;
}


.records-filter-header {
    display: flex;

    align-items: center;
    justify-content: space-between;

    padding: 14px 18px;

    background:
        linear-gradient(
            100deg,
            #f3f6f9,
            #fafbfc
        );

    border-bottom: 1px solid #e3e8ee;
}


.records-filter-title {
    display: flex;

    align-items: center;

    gap: 9px;

    margin: 0;

    color: #43586e;

    font-size: 13px;
    font-weight: 750;
}


.records-filter-mark {
    width: 7px;
    height: 21px;

    border-radius: 5px;

    background:
        linear-gradient(
            to bottom,
            #587492 0 55%,
            #c07077 55% 77%,
            #bf962d 77% 100%
        );
}


.records-filter-body {
    padding: 18px;
}


.records-filter-grid {
    display: grid;

    grid-template-columns:
        minmax(270px, 1.8fr)
        minmax(145px, .75fr)
        minmax(145px, .75fr)
        minmax(190px, 1fr)
        auto;

    gap: 12px;

    align-items: end;
}


.records-field {
    min-width: 0;
}


.records-label {
    display: block;

    margin-bottom: 6px;

    color: #687686;

    font-size: 10px;
    font-weight: 800;

    letter-spacing: .7px;
    text-transform: uppercase;
}


.records-input-wrap {
    position: relative;
}


.records-search-icon {
    position: absolute;

    left: 12px;
    top: 50%;

    transform: translateY(-50%);

    color: #94a0ad;

    font-size: 14px;

    pointer-events: none;
}


.records-control {
    width: 100%;
    height: 42px;

    padding: 0 12px;

    border: 1px solid #d8dfe7;

    border-radius: 8px;

    background: #fbfcfd;

    color: #344252;

    outline: none;

    transition:
        border-color .16s ease,
        box-shadow .16s ease,
        background .16s ease;
}


.records-input-wrap
.records-control {
    padding-left: 35px;
}


.records-control:hover {
    background: #fff;

    border-color: #c7d0db;
}


.records-control:focus {
    background: #fff;

    border-color: #7189a2;

    box-shadow:
        0 0 0 3px rgba(88,116,146,.10);
}


.records-filter-actions {
    display: flex;

    gap: 8px;
}


.records-button {
    height: 42px;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    gap: 7px;

    padding: 0 15px;

    border-radius: 8px;

    font-size: 11px;
    font-weight: 750;

    text-decoration: none;

    cursor: pointer;

    white-space: nowrap;

    transition:
        transform .15s ease,
        box-shadow .15s ease,
        background .15s ease;
}


.records-button:hover {
    transform: translateY(-1px);
}


.records-button-primary {
    border: 1px solid #526d8c;

    background: #526d8c;

    color: #fff;

    box-shadow:
        0 4px 10px rgba(82,109,140,.16);
}


.records-button-primary:hover {
    background: #465f7b;
}


.records-button-reset {
    border: 1px solid #d8dee6;

    background: #f5f7f9;

    color: #657180;
}


.records-button-reset:hover {
    background: #eceff3;
}


.records-active-filters {
    display: flex;

    flex-wrap: wrap;

    align-items: center;

    gap: 7px;

    margin-top: 14px;

    padding-top: 13px;

    border-top: 1px dashed #dfe4ea;
}


.records-active-label {
    color: #87919e;

    font-size: 10px;
    font-weight: 650;
}


.records-filter-chip {
    display: inline-flex;

    align-items: center;

    padding: 5px 9px;

    border-radius: 999px;

    background: #edf2f7;

    border: 1px solid #dce4ec;

    color: #53687d;

    font-size: 10px;
    font-weight: 700;
}


.records-filter-chip-red {
    background: #fbefef;

    border-color: #efd5d7;

    color: #9a4e56;
}


.records-filter-chip-gold {
    background: #faf5e7;

    border-color: #ecdfba;

    color: #92752e;
}


/*
|--------------------------------------------------------------------------
| Results
|--------------------------------------------------------------------------
*/

.records-results {
    background: #fff;

    border: 1px solid #dfe5eb;

    border-radius: 10px;

    box-shadow:
        0 2px 9px rgba(31,52,73,.05);

    overflow: hidden;
}


.records-results-header {
    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 15px;

    padding: 16px 18px;

    border-bottom: 1px solid #e2e7ed;

    background:
        linear-gradient(
            100deg,
            #fbfcfd,
            #f8fafb
        );
}


.records-results-title {
    margin: 0;

    color: #3b4e62;

    font-size: 15px;
    font-weight: 800;
}


.records-results-subtitle {
    margin: 4px 0 0;

    color: #7c8794;

    font-size: 10px;
}


.records-results-count {
    flex-shrink: 0;

    padding: 6px 10px;

    border-radius: 999px;

    background: #f7f2e3;

    border: 1px solid #eadfbf;

    color: #8c702d;

    font-size: 10px;
    font-weight: 800;
}


/*
|--------------------------------------------------------------------------
| Table
|--------------------------------------------------------------------------
*/

.records-table-wrap {
    width: 100%;

    overflow-x: auto;
}


.records-table {
    width: 100%;

    min-width: 1030px;

    border-collapse: separate;
    border-spacing: 0;
}


.records-table thead th {
    padding: 12px 15px;

    background: #f5f7f9;

    border-bottom: 1px solid #dfe5eb;

    color: #64707d;

    font-size: 9px;
    font-weight: 850;

    letter-spacing: .8px;

    text-align: left;
    text-transform: uppercase;

    white-space: nowrap;
}


.records-table tbody td {
    padding: 14px 15px;

    background: #fff;

    border-bottom: 1px solid #edf0f3;

    vertical-align: middle;
}


.records-table tbody tr {
    transition:
        background .15s ease;
}


.records-table tbody tr:hover td {
    background: #fafbfd;
}


.records-table tbody tr:last-child td {
    border-bottom: 0;
}


/*
|--------------------------------------------------------------------------
| Application reference
|--------------------------------------------------------------------------
*/

.records-reference {
    display: flex;

    flex-direction: column;

    gap: 5px;

    min-width: 140px;
}


.records-reference-code {
    display: inline-flex;

    align-items: center;

    width: fit-content;

    padding: 5px 8px;

    border-radius: 6px;

    background: #edf2f7;

    border: 1px solid #dce4ec;

    color: #536b83;

    font-size: 10px;
    font-weight: 850;
}


.records-reference-id {
    color: #9aa4af;

    font-size: 9px;
}


/*
|--------------------------------------------------------------------------
| Organization
|--------------------------------------------------------------------------
*/

.records-organization {
    min-width: 245px;

    max-width: 350px;
}


.records-organization-name {
    color: #2f3d4d;

    font-size: 12px;
    font-weight: 800;

    line-height: 1.4;

    word-break: break-word;
}


.records-organization-meta {
    display: flex;

    align-items: center;

    gap: 6px;

    margin-top: 5px;

    color: #919aa5;

    font-size: 9px;
}


/*
|--------------------------------------------------------------------------
| PBO number
|--------------------------------------------------------------------------
*/

.records-registration-number {
    color: #4b5662;

    font-size: 11px;
    font-weight: 650;

    white-space: nowrap;
}


.records-registration-number.empty {
    color: #a7afb9;

    font-style: italic;

    font-weight: 500;
}


/*
|--------------------------------------------------------------------------
| Registration type
|--------------------------------------------------------------------------
*/

.records-type {
    display: inline-flex;

    align-items: center;

    padding: 5px 9px;

    border-radius: 999px;

    font-size: 9px;
    font-weight: 800;
}


.records-type-national {
    background: #edf2f7;

    border: 1px solid #dce5ed;

    color: #526a82;
}


.records-type-international {
    background: #fbefef;

    border: 1px solid #efd5d7;

    color: #9d5159;
}


/*
|--------------------------------------------------------------------------
| Submitted By
|--------------------------------------------------------------------------
*/

.records-submitted-by {
    min-width: 190px;

    max-width: 235px;
}


.records-submitter {
    display: flex;

    align-items: center;

    gap: 9px;

    min-width: 0;
}


.records-submitter-avatar {
    flex: 0 0 35px;

    width: 35px;
    height: 35px;

    display: grid;

    place-items: center;

    border-radius: 9px;

    background:
        linear-gradient(
            135deg,
            #607b98,
            #49627d
        );

    color: #fff;

    font-size: 10px;
    font-weight: 850;

    box-shadow:
        0 3px 8px rgba(73,98,125,.16);
}


.records-submitter-details {
    min-width: 0;

    display: flex;

    flex-direction: column;
}


.records-submitter-name {
    color: #354251;

    font-size: 10.5px;
    font-weight: 800;

    line-height: 1.35;

    overflow-wrap: anywhere;
}


.records-submitter-username {
    margin-top: 3px;

    color: #929ba6;

    font-size: 9px;

    line-height: 1.2;
}


/*
|--------------------------------------------------------------------------
| Submission date
|--------------------------------------------------------------------------
*/

.records-submitted-date {
    min-width: 125px;
}


.records-date-main {
    color: #3d4956;

    font-size: 10.5px;
    font-weight: 750;
}


.records-date-time {
    display: inline-block;

    margin-top: 4px;

    color: #929ba6;

    font-size: 9px;
}


/*
|--------------------------------------------------------------------------
| View button
|--------------------------------------------------------------------------
*/

.records-action {
    min-width: 82px;

    text-align: right !important;
}


.records-view {
    display: inline-flex;

    align-items: center;
    justify-content: center;

    gap: 6px;

    padding: 8px 12px;

    border-radius: 7px;

    background: #536e8c;

    color: #fff;

    font-size: 10px;
    font-weight: 800;

    text-decoration: none;

    box-shadow:
        0 4px 9px rgba(83,110,140,.16);

    transition:
        background .15s ease,
        transform .15s ease,
        box-shadow .15s ease;
}


.records-view:hover {
    background: #465f79;

    transform: translateY(-1px);

    box-shadow:
        0 5px 12px rgba(83,110,140,.22);
}


.records-view-icon {
    font-size: 10px;
}


/*
|--------------------------------------------------------------------------
| Empty state
|--------------------------------------------------------------------------
*/

.records-empty {
    padding: 58px 25px;

    text-align: center;
}


.records-empty-icon {
    width: 58px;
    height: 58px;

    display: grid;

    place-items: center;

    margin: 0 auto 13px;

    border-radius: 16px;

    background:
        linear-gradient(
            135deg,
            #edf2f7,
            #f8f3e6
        );

    border: 1px solid #dfe5eb;

    color: #637b94;

    font-size: 22px;
    font-weight: 800;
}


.records-empty h3 {
    margin: 0;

    color: #3d4c5b;

    font-size: 15px;

    font-weight: 800;

    text-transform: none;
}


.records-empty p {
    max-width: 470px;

    margin: 7px auto 0;

    color: #858f9b;

    font-size: 10.5px;

    line-height: 1.6;
}


/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

.records-pagination {
    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 15px;

    padding: 13px 18px;

    background: #fafbfc;

    border-top: 1px solid #e3e8ed;
}


.records-pagination-info {
    color: #89939e;

    font-size: 9.5px;
}


.records-pagination-info strong {
    color: #566372;
}


.records-pagination-links {
    display: flex;

    align-items: center;

    gap: 4px;
}


.records-page-link {
    min-width: 31px;
    height: 31px;

    display: inline-flex;

    align-items: center;
    justify-content: center;

    padding: 0 8px;

    border-radius: 7px;

    border: 1px solid #dce2e8;

    background: #fff;

    color: #667381;

    font-size: 9.5px;
    font-weight: 750;

    text-decoration: none;
}


.records-page-link:hover {
    background: #edf2f7;

    border-color: #cfd9e3;

    color: #526b84;
}


.records-page-link.active {
    background: #587492;

    border-color: #587492;

    color: #fff;
}


.records-page-link.disabled {
    opacity: .4;

    pointer-events: none;
}


.records-page-dots {
    padding: 0 3px;

    color: #9da6b0;

    font-size: 11px;
}


/*
|--------------------------------------------------------------------------
| Responsive
|--------------------------------------------------------------------------
*/

@media (max-width: 1180px) {

    .records-filter-grid {
        grid-template-columns:
            minmax(230px, 1fr)
            minmax(145px, 1fr)
            minmax(145px, 1fr);
    }

    .records-field-search {
        grid-column: 1 / -1;
    }

    .records-filter-actions {
        grid-column: 1 / -1;
    }

    .records-filter-actions .records-button {
        flex: 1;
    }
}


@media (max-width: 820px) {

    .records-stat-grid {
        grid-template-columns: 1fr;
    }

    .records-filter-grid {
        grid-template-columns: 1fr;
    }

    .records-field-search {
        grid-column: auto;
    }

    .records-filter-actions {
        grid-column: auto;
    }

    .records-heading {
        padding: 23px;
    }

    .records-heading h1 {
        font-size: 23px;
    }

    .records-pagination {
        align-items: flex-start;

        flex-direction: column;
    }

    .records-pagination-links {
        width: 100%;

        overflow-x: auto;

        padding-bottom: 2px;
    }
}


@media (max-width: 600px) {

    .records-heading {
        padding: 20px;
    }

    .records-filter-body,
    .records-results-header {
        padding: 14px;
    }

    .records-stat {
        min-height: 115px;
    }
}


/*
|--------------------------------------------------------------------------
| Print
|--------------------------------------------------------------------------
*/

@media print {

    .records-heading,
    .records-filter-card,
    .records-stat-grid,
    .records-results-header,
    .records-pagination {
        display: none !important;
    }

    .records-page {
        max-width: none;
    }

    .records-results {
        border: 0;

        box-shadow: none;
    }

    .records-table {
        min-width: 0;
    }

    .records-table-wrap {
        overflow: visible;
    }

    .records-action {
        display: none;
    }

}

</style>


<!-- ================================================================
     SUBMITTED RECORDS PAGE
     ================================================================ -->

<div class="records-page">


    <!-- ============================================================
         PAGE HEADING
         ============================================================ -->

    <section class="records-heading">

        <div class="records-heading-content">

            <div class="records-eyebrow">
                PBO Registration &amp; Records
            </div>

            <h1>
                Submitted Records
            </h1>

            <p>
                Review completed registration applications and track
                submission activity within your authorized access.
            </p>

        </div>

    </section>


    <!-- ============================================================
         STATISTICS
         ============================================================ -->

    <section class="records-stat-grid">


        <!-- TOTAL -->

        <div class="records-stat records-stat-blue">

            <div class="records-stat-top">

                <span class="records-stat-label">
                    Total Submitted
                </span>

                <span class="records-stat-icon">
                    ✓
                </span>

            </div>

            <span class="records-stat-number">
                <?= number_format($totalSubmitted) ?>
            </span>

            <div class="records-stat-description">
                All submitted records within your access scope.
            </div>

        </div>


        <!-- YEAR -->

        <div class="records-stat records-stat-red">

            <div class="records-stat-top">

                <span class="records-stat-label">
                    This Year
                </span>

                <span class="records-stat-icon">
                    Y
                </span>

            </div>

            <span class="records-stat-number">
                <?= number_format($thisYear) ?>
            </span>

            <div class="records-stat-description">
                Submitted during <?= date('Y') ?>.
            </div>

        </div>


        <!-- MONTH -->

        <div class="records-stat records-stat-gold">

            <div class="records-stat-top">

                <span class="records-stat-label">
                    This Month
                </span>

                <span class="records-stat-icon">
                    M
                </span>

            </div>

            <span class="records-stat-number">
                <?= number_format($thisMonth) ?>
            </span>

            <div class="records-stat-description">
                Submitted during <?= date('F Y') ?>.
            </div>

        </div>


    </section>


    <!-- ============================================================
         SEARCH AND FILTER
         ============================================================ -->

    <section class="records-filter-card">


        <div class="records-filter-header">

            <h2 class="records-filter-title">

                <span class="records-filter-mark"></span>

                Search &amp; Filter Records

            </h2>

        </div>


        <div class="records-filter-body">

            <form method="get" action="">


                <div class="records-filter-grid">


                    <!-- SEARCH -->

                    <div class="records-field records-field-search">

                        <label
                            class="records-label"
                            for="records-search"
                        >
                            Search
                        </label>

                        <div class="records-input-wrap">

                            <span class="records-search-icon">
                                ⌕
                            </span>

                            <input
                                type="search"
                                id="records-search"
                                name="q"
                                class="records-control"
                                value="<?= recordsEscape($search) ?>"
                                placeholder="Organization name, application reference or PBO number..."
                            >

                        </div>

                    </div>


                    <!-- STAFF -->

                    <?php if ($isAdmin): ?>

                        <div class="records-field">

                            <label
                                class="records-label"
                                for="submitted_by"
                            >
                                Submitted By
                            </label>

                            <select
                                id="submitted_by"
                                name="submitted_by"
                                class="records-control"
                            >

                                <option value="">
                                    All Users
                                </option>

                                <?php foreach (
                                    $staffUsers
                                    as $staff
                                ): ?>

                                    <option
                                        value="<?= (int) $staff['id'] ?>"
                                        <?= (
                                            $selectedUserId
                                            === (int) $staff['id']
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= recordsEscape(
                                            $staff['full_name']
                                        ) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    <?php endif; ?>


                    <!-- YEAR -->

                    <div class="records-field">

                        <label
                            class="records-label"
                            for="records-year"
                        >
                            Year
                        </label>

                        <select
                            id="records-year"
                            name="year"
                            class="records-control"
                        >

                            <option value="">
                                All Years
                            </option>

                            <?php foreach (
                                $availableYears
                                as $year
                            ): ?>

                                <option
                                    value="<?= (int) $year ?>"
                                    <?= (
                                        $filterYear
                                        === (int) $year
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    <?= (int) $year ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- MONTH -->

                    <div class="records-field">

                        <label
                            class="records-label"
                            for="records-month"
                        >
                            Month
                        </label>

                        <select
                            id="records-month"
                            name="month"
                            class="records-control"
                        >

                            <option value="">
                                All Months
                            </option>

                            <?php foreach (
                                $months
                                as $monthNumber => $monthName
                            ): ?>

                                <option
                                    value="<?= $monthNumber ?>"
                                    <?= (
                                        $filterMonth
                                        === $monthNumber
                                    )
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    <?= recordsEscape(
                                        $monthName
                                    ) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- BUTTONS -->

                    <div class="records-filter-actions">

                        <button
                            type="submit"
                            class="records-button records-button-primary"
                        >
                            <span>⌕</span>
                            Search
                        </button>

                        <a
                            href="records.php"
                            class="records-button records-button-reset"
                        >
                            Reset
                        </a>

                    </div>


                </div>


                <!-- ACTIVE FILTERS -->

                <?php if ($hasFilters): ?>

                    <div class="records-active-filters">

                        <span class="records-active-label">
                            Active filters:
                        </span>


                        <?php if ($search !== ''): ?>

                            <span class="records-filter-chip">

                                Search:
                                <?= recordsEscape($search) ?>

                            </span>

                        <?php endif; ?>


                        <?php if (
                            $selectedStaffName !== ''
                        ): ?>

                            <span
                                class="
                                    records-filter-chip
                                    records-filter-chip-red
                                "
                            >

                                Staff:
                                <?= recordsEscape(
                                    $selectedStaffName
                                ) ?>

                            </span>

                        <?php endif; ?>


                        <?php if ($filterYear > 0): ?>

                            <span class="records-filter-chip">

                                Year:
                                <?= (int) $filterYear ?>

                            </span>

                        <?php endif; ?>


                        <?php if ($filterMonth > 0): ?>

                            <span
                                class="
                                    records-filter-chip
                                    records-filter-chip-gold
                                "
                            >

                                Month:
                                <?= recordsEscape(
                                    $months[$filterMonth]
                                ) ?>

                            </span>

                        <?php endif; ?>


                    </div>

                <?php endif; ?>


            </form>

        </div>

    </section>


    <!-- ============================================================
         RESULTS
         ============================================================ -->

    <section class="records-results">


        <div class="records-results-header">

            <div>

                <h2 class="records-results-title">
                    Registration Records
                </h2>

                <p class="records-results-subtitle">

                    <?php if (
                        $selectedStaffName !== ''
                    ): ?>

                        Showing submissions by
                        <strong>
                            <?= recordsEscape(
                                $selectedStaffName
                            ) ?>
                        </strong>.

                    <?php elseif (!$isAdmin): ?>

                        Showing records submitted by you.

                    <?php else: ?>

                        Showing all submitted records.

                    <?php endif; ?>

                </p>

            </div>


            <div class="records-results-count">

                <?= number_format($totalFiltered) ?>

                <?= $totalFiltered === 1
                    ? 'record'
                    : 'records'
                ?>

            </div>

        </div>


        <?php if (!empty($records)): ?>


            <!-- ====================================================
                 TABLE
                 ==================================================== -->

            <div class="records-table-wrap">

                <table class="records-table">

                    <thead>

                        <tr>

                            <th>
                                Application
                            </th>

                            <th>
                                Organization
                            </th>

                            <th>
                                PBO Number
                            </th>

                            <th>
                                Type
                            </th>

                            <th>
                                Submitted By
                            </th>

                            <th>
                                Submitted
                            </th>

                            <th class="records-action">
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php foreach (
                        $records
                        as $record
                    ): ?>


                        <?php

                        $submitterName =
                            trim(
                                (string) (
                                    $record[
                                        'submitted_by_name'
                                    ] ?? ''
                                )
                            );

                        if (
                            $submitterName === ''
                        ) {
                            $submitterName =
                                'Unknown User';
                        }

                        $submitterUsername =
                            trim(
                                (string) (
                                    $record[
                                        'submitted_by_username'
                                    ] ?? ''
                                )
                            );

                        $submitterInitials =
                            recordsInitials(
                                $submitterName
                            );

                        $registrationType =
                            strtolower(
                                (string) (
                                    $record[
                                        'registration_type'
                                    ] ?? ''
                                )
                            );

                        ?>


                        <tr>


                            <!-- APPLICATION -->

                            <td>

                                <div class="records-reference">

                                    <span
                                        class="
                                            records-reference-code
                                        "
                                    >
                                        <?= recordsEscape(
                                            $record[
                                                'application_reference'
                                            ]
                                        ) ?>
                                    </span>

                                    <span
                                        class="
                                            records-reference-id
                                        "
                                    >
                                        Record #
                                        <?= (int) $record['id'] ?>
                                    </span>

                                </div>

                            </td>


                            <!-- ORGANIZATION -->

                            <td>

                                <div
                                    class="
                                        records-organization
                                    "
                                >

                                    <div
                                        class="
                                            records-organization-name
                                        "
                                    >
                                        <?= recordsEscape(
                                            $record['pbo_name']
                                        ) ?>
                                    </div>


                                    <div
                                        class="
                                            records-organization-meta
                                        "
                                    >

                                        <span>
                                            Registration application
                                        </span>


                                        <?php if (
                                            !empty(
                                                $record[
                                                    'registration_date'
                                                ]
                                            )
                                        ): ?>

                                            <span>
                                                •
                                            </span>

                                            <span>

                                                <?= recordsEscape(
                                                    date(
                                                        'd M Y',
                                                        strtotime(
                                                            $record[
                                                                'registration_date'
                                                            ]
                                                        )
                                                    )
                                                ) ?>

                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </td>


                            <!-- PBO NUMBER -->

                            <td>

                                <?php if (
                                    !empty(
                                        $record[
                                            'pbo_registration_number'
                                        ]
                                    )
                                ): ?>

                                    <span
                                        class="
                                            records-registration-number
                                        "
                                    >
                                        <?= recordsEscape(
                                            $record[
                                                'pbo_registration_number'
                                            ]
                                        ) ?>
                                    </span>

                                <?php else: ?>

                                    <span
                                        class="
                                            records-registration-number
                                            empty
                                        "
                                    >
                                        Not assigned
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- TYPE -->

                            <td>

                                <?php if (
                                    $registrationType
                                    === 'international'
                                ): ?>

                                    <span
                                        class="
                                            records-type
                                            records-type-international
                                        "
                                    >
                                        International
                                    </span>

                                <?php else: ?>

                                    <span
                                        class="
                                            records-type
                                            records-type-national
                                        "
                                    >
                                        National
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- SUBMITTED BY -->

                            <td class="records-submitted-by">

                                <div class="records-submitter">


                                    <div
                                        class="
                                            records-submitter-avatar
                                        "
                                    >
                                        <?= recordsEscape(
                                            $submitterInitials
                                        ) ?>
                                    </div>


                                    <div
                                        class="
                                            records-submitter-details
                                        "
                                    >

                                        <span
                                            class="
                                                records-submitter-name
                                            "
                                        >
                                            <?= recordsEscape(
                                                $submitterName
                                            ) ?>
                                        </span>


                                        <?php if (
                                            $submitterUsername
                                            !== ''
                                        ): ?>

                                            <span
                                                class="
                                                    records-submitter-username
                                                "
                                            >
                                                @<?= recordsEscape(
                                                    $submitterUsername
                                                ) ?>
                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="
                                                    records-submitter-username
                                                "
                                            >
                                                Submitted user
                                            </span>

                                        <?php endif; ?>

                                    </div>


                                </div>

                            </td>


                            <!-- SUBMITTED DATE -->

                            <td
                                class="
                                    records-submitted-date
                                "
                            >

                                <div
                                    class="
                                        records-date-main
                                    "
                                >
                                    <?= recordsEscape(
                                        recordsDate(
                                            $record[
                                                'submitted_at'
                                            ]
                                        )
                                    ) ?>
                                </div>


                                <span
                                    class="
                                        records-date-time
                                    "
                                >
                                    <?= recordsEscape(
                                        recordsTime(
                                            $record[
                                                'submitted_at'
                                            ]
                                        )
                                    ) ?>
                                </span>

                            </td>


                            <!-- VIEW -->

                            <td class="records-action">

                                <a
                                    href="view.php?id=<?= (int) $record['id'] ?>"
                                    class="records-view"
                                >

                                    <span
                                        class="
                                            records-view-icon
                                        "
                                    >
                                        →
                                    </span>

                                    View

                                </a>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                    </tbody>

                </table>

            </div>


            <!-- ====================================================
                 PAGINATION
                 ==================================================== -->

            <?php if (
                $totalPages > 1
            ): ?>

                <div class="records-pagination">


                    <div class="records-pagination-info">

                        Showing

                        <strong>
                            <?= number_format(
                                $offset + 1
                            ) ?>
                        </strong>

                        to

                        <strong>
                            <?= number_format(
                                min(
                                    $offset + $perPage,
                                    $totalFiltered
                                )
                            ) ?>
                        </strong>

                        of

                        <strong>
                            <?= number_format(
                                $totalFiltered
                            ) ?>
                        </strong>

                        records

                    </div>


                    <nav
                        class="
                            records-pagination-links
                        "
                        aria-label="Pagination"
                    >


                        <!-- PREVIOUS -->

                        <?php if ($page > 1): ?>

                            <a
                                href="<?= recordsEscape(
                                    recordsPageUrl(
                                        $page - 1,
                                        $paginationParameters
                                    )
                                ) ?>"
                                class="records-page-link"
                                aria-label="Previous page"
                            >
                                ‹
                            </a>

                        <?php else: ?>

                            <span
                                class="
                                    records-page-link
                                    disabled
                                "
                            >
                                ‹
                            </span>

                        <?php endif; ?>


                        <?php

                        $startPage =
                            max(
                                1,
                                $page - 2
                            );

                        $endPage =
                            min(
                                $totalPages,
                                $page + 2
                            );

                        ?>


                        <!-- FIRST PAGE -->

                        <?php if (
                            $startPage > 1
                        ): ?>

                            <a
                                href="<?= recordsEscape(
                                    recordsPageUrl(
                                        1,
                                        $paginationParameters
                                    )
                                ) ?>"
                                class="records-page-link"
                            >
                                1
                            </a>


                            <?php if (
                                $startPage > 2
                            ): ?>

                                <span
                                    class="
                                        records-page-dots
                                    "
                                >
                                    …
                                </span>

                            <?php endif; ?>

                        <?php endif; ?>


                        <!-- PAGE NUMBERS -->

                        <?php for (
                            $p = $startPage;
                            $p <= $endPage;
                            $p++
                        ): ?>


                            <?php if (
                                $p === $page
                            ): ?>

                                <span
                                    class="
                                        records-page-link
                                        active
                                    "
                                >
                                    <?= $p ?>
                                </span>

                            <?php else: ?>

                                <a
                                    href="<?= recordsEscape(
                                        recordsPageUrl(
                                            $p,
                                            $paginationParameters
                                        )
                                    ) ?>"
                                    class="records-page-link"
                                >
                                    <?= $p ?>
                                </a>

                            <?php endif; ?>


                        <?php endfor; ?>


                        <!-- LAST PAGE -->

                        <?php if (
                            $endPage < $totalPages
                        ): ?>


                            <?php if (
                                $endPage
                                < $totalPages - 1
                            ): ?>

                                <span
                                    class="
                                        records-page-dots
                                    "
                                >
                                    …
                                </span>

                            <?php endif; ?>


                            <a
                                href="<?= recordsEscape(
                                    recordsPageUrl(
                                        $totalPages,
                                        $paginationParameters
                                    )
                                ) ?>"
                                class="records-page-link"
                            >
                                <?= $totalPages ?>
                            </a>


                        <?php endif; ?>


                        <!-- NEXT -->

                        <?php if (
                            $page < $totalPages
                        ): ?>

                            <a
                                href="<?= recordsEscape(
                                    recordsPageUrl(
                                        $page + 1,
                                        $paginationParameters
                                    )
                                ) ?>"
                                class="records-page-link"
                                aria-label="Next page"
                            >
                                ›
                            </a>

                        <?php else: ?>

                            <span
                                class="
                                    records-page-link
                                    disabled
                                "
                            >
                                ›
                            </span>

                        <?php endif; ?>


                    </nav>

                </div>

            <?php endif; ?>


        <?php else: ?>


            <!-- ====================================================
                 EMPTY STATE
                 ==================================================== -->

            <div class="records-empty">

                <div class="records-empty-icon">
                    ⌕
                </div>

                <h3>
                    No submitted records found
                </h3>

                <p>

                    <?php if ($hasFilters): ?>

                        No submitted applications match the
                        current search and filter criteria.
                        Try changing the filters or reset them
                        to view all available records.

                    <?php else: ?>

                        There are currently no submitted
                        applications within your authorized
                        access scope.

                    <?php endif; ?>

                </p>

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