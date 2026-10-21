<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pbo-registry-filters.php';

requireLogin();
requirePasswordChanged();


/*
|--------------------------------------------------------------------------
| Page configuration
|--------------------------------------------------------------------------
*/

$pageTitle = 'List of PBOs';
$activePage = 'pbos';


/*
|--------------------------------------------------------------------------
| Database connection
|--------------------------------------------------------------------------
*/

$connection = null;

if (
    isset($pdo) &&
    $pdo instanceof PDO
) {

    $connection = $pdo;

} elseif (
    isset($db) &&
    $db instanceof PDO
) {

    $connection = $db;
}


if (!$connection instanceof PDO) {

    throw new RuntimeException(
        'Database connection was not initialized as a PDO instance.'
    );
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


function displayValue(
    mixed $value
): string {

    $value =
        trim(
            (string) $value
        );


    return $value !== ''
        ? e($value)
        : '—';
}


function formatRegistrationDate(
    mixed $value
): string {

    $value =
        trim(
            (string) $value
        );


    if ($value === '') {

        return '—';
    }


    try {

        /*
        | Short month keeps the date column compact in long lists.
        */

        return (
            new DateTimeImmutable(
                $value
            )
        )->format(
            'd M Y'
        );

    } catch (Throwable $exception) {

        return $value;
    }
}


/*
|--------------------------------------------------------------------------
| Build pagination/filter URL
|--------------------------------------------------------------------------
*/

/** Shorter alias used by the newer markup below. */
function registryUrl(array $overrides = []): string
{
    return buildPboListUrl($overrides);
}


function buildPboListUrl(
    array $overrides = []
): string {

    $parameters = [

        'q' =>
            trim(
                (string)
                ($_GET['q'] ?? '')
            ),

        'year' =>
            trim(
                (string)
                ($_GET['year'] ?? '')
            ),

        'type' =>
            trim(
                (string)
                ($_GET['type'] ?? '')
            ),

        /*
        | The alphabet and ordering travel with every other link on the
        | page, so paging through "names beginning with B, oldest first"
        | stays on B and stays oldest first.
        */
        'letter' =>
            trim(
                (string)
                ($_GET['letter'] ?? '')
            ),

        'sort' =>
            trim(
                (string)
                ($_GET['sort'] ?? '')
            ),

        'page' =>
            max(
                1,
                (int)
                ($_GET['page'] ?? 1)
            ),
    ];


    foreach (
        $overrides
        as $key => $value
    ) {

        $parameters[$key] =
            $value;
    }


    $parameters =
        array_filter(
            $parameters,
            static function (
                mixed $value
            ): bool {

                return !(
                    $value === '' ||
                    $value === null
                );
            }
        );


    if (
        isset(
            $parameters['page']
        ) &&
        (int)
        $parameters['page'] <= 1
    ) {

        unset(
            $parameters['page']
        );
    }


    $query =
        http_build_query(
            $parameters
        );


    return
        '/pbo-portal/applications/pbos.php' .
        (
            $query !== ''
                ? '?' . $query
                : ''
        );
}


/*
|--------------------------------------------------------------------------
| Read filters
|--------------------------------------------------------------------------
*/

$search =
    trim(
        (string)
        ($_GET['q'] ?? '')
    );


$year =
    trim(
        (string)
        ($_GET['year'] ?? '')
    );


$type =
    trim(
        (string)
        ($_GET['type'] ?? '')
    );


/*
|--------------------------------------------------------------------------
| Alphabet and ordering
|--------------------------------------------------------------------------
|
| Read by the shared module so the export produces exactly the rows this
| page is showing - see includes/pbo-registry-filters.php.
*/

$registryFilters = pboRegistryFilters($_GET);

$letter = $registryFilters['letter'];
$sort = $registryFilters['sort'];


/*
|--------------------------------------------------------------------------
| Validate year
|--------------------------------------------------------------------------
*/

if (
    $year !== '' &&
    !preg_match(
        '/^\d{4}$/',
        $year
    )
) {

    $year = '';
}


/*
|--------------------------------------------------------------------------
| Validate organization type
|--------------------------------------------------------------------------
*/

$allowedTypes = [

    'national',
    'international',
];


if (
    $type !== '' &&
    !in_array(
        $type,
        $allowedTypes,
        true
    )
) {

    $type = '';
}


/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
|
| Rows are now compact, so a page shows 50 organizations.
|
*/

$perPage = 50;


$page =
    max(
        1,
        (int)
        ($_GET['page'] ?? 1)
    );


/*
|--------------------------------------------------------------------------
| Registration years available for filtering
|--------------------------------------------------------------------------
*/

$yearStatement =
    $connection->query(
        "
        SELECT DISTINCT
            YEAR(registration_date)
                AS registration_year

        FROM pbo_applications

        WHERE status = 'submitted'
          AND registration_date IS NOT NULL

        ORDER BY
            registration_year DESC
        "
    );


$registrationYears =
    $yearStatement->fetchAll(
        PDO::FETCH_COLUMN
    );


/*
|--------------------------------------------------------------------------
| Build registry WHERE clause
|--------------------------------------------------------------------------
*/

/*
| Built by includes/pbo-registry-filters.php, which the export also uses.
| Two copies of this logic is how an export starts disagreeing with the
| screen it was printed from.
*/

$registryWhere = pboRegistryWhere($registryFilters);

$whereSql = $registryWhere['sql'];
$params = $registryWhere['params'];

/* Chosen from a fixed list, never built from what the user typed. */
$registryOrderBy = pboRegistryOrderBy($registryFilters);


/*
|--------------------------------------------------------------------------
| Count matching PBOs
|--------------------------------------------------------------------------
*/

$countStatement =
    $connection->prepare(
        "
        SELECT
            COUNT(*)

        FROM pbo_applications

        WHERE {$whereSql}
        "
    );


foreach (
    $params
    as $parameter => $value
) {

    if (
        $parameter ===
        ':registration_year'
    ) {

        $countStatement->bindValue(
            $parameter,
            $value,
            PDO::PARAM_INT
        );

    } else {

        $countStatement->bindValue(
            $parameter,
            $value,
            PDO::PARAM_STR
        );
    }
}


$countStatement->execute();


$totalRecords =
    (int)
    $countStatement->fetchColumn();


/*
|--------------------------------------------------------------------------
| Pagination calculations
|--------------------------------------------------------------------------
*/

$totalPages =
    max(
        1,
        (int)
        ceil(
            $totalRecords /
            $perPage
        )
    );


if (
    $page >
    $totalPages
) {

    $page =
        $totalPages;
}


$offset =
    ($page - 1) *
    $perPage;


/*
|--------------------------------------------------------------------------
| Retrieve registry records
|--------------------------------------------------------------------------
|
| Only retrieve the information needed for the List of PBOs.
|
*/

$listStatement =
    $connection->prepare(
        "
        SELECT
            id,
            pbo_registration_number,
            pbo_name,
            registration_date,
            registration_type

        FROM pbo_applications

        WHERE {$whereSql}

        ORDER BY {$registryOrderBy}

        LIMIT :limit
        OFFSET :offset
        "
    );


foreach (
    $params
    as $parameter => $value
) {

    if (
        $parameter ===
        ':registration_year'
    ) {

        $listStatement->bindValue(
            $parameter,
            $value,
            PDO::PARAM_INT
        );

    } else {

        $listStatement->bindValue(
            $parameter,
            $value,
            PDO::PARAM_STR
        );
    }
}


$listStatement->bindValue(
    ':limit',
    $perPage,
    PDO::PARAM_INT
);


$listStatement->bindValue(
    ':offset',
    $offset,
    PDO::PARAM_INT
);


$listStatement->execute();


$pbos =
    $listStatement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Result range
|--------------------------------------------------------------------------
*/

$firstResult =
    $totalRecords > 0
        ? $offset + 1
        : 0;


$lastResult =
    min(
        $offset +
        $perPage,
        $totalRecords
    );


/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/

require_once __DIR__ .
    '/../includes/header.php';

?>


<style>

/* ==============================================================
   PBO REGISTRY
   ============================================================== */

.pbo-registry {

    --registry-navy: #0b3557;
    --registry-navy-deep: #062945;

    --registry-blue: #176fa8;
    --registry-blue-soft: #edf6fc;

    --registry-gold: #d69a18;
    --registry-gold-dark: #8c5f00;
    --registry-gold-soft: #fff8e5;

    --registry-green: #197149;
    --registry-green-soft: #edf8f2;

    --registry-text: #1a2d3c;
    --registry-secondary: #4c6272;
    --registry-muted: #758692;

    --registry-border: #d5e0e7;
    --registry-soft: #f6f9fb;

    max-width: 1240px;

    margin:
        0 auto;
}


/* ==============================================================
   REGISTRY HERO
   ============================================================== */

.registry-hero {
    position: relative;

    margin-bottom: 22px;

    overflow: hidden;

    background:
        linear-gradient(
            112deg,
            var(--registry-navy-deep),
            var(--registry-navy) 58%,
            #176b9f
        );

    border-bottom:
        5px solid
        var(--registry-gold);

    border-radius: 14px;

    box-shadow:
        0 9px 28px
        rgba(
            8,
            45,
            75,
            .12
        );

    color: #ffffff;
}


.registry-hero::after {
    content: '';

    position: absolute;

    top: -105px;
    right: -55px;

    width: 235px;
    height: 235px;

    border:
        38px solid
        rgba(
            255,
            255,
            255,
            .04
        );

    border-radius: 50%;
}


.registry-hero-content {
    position: relative;

    z-index: 1;

    padding:
        27px 29px 26px;
}


.registry-hero-eyebrow {
    display: block;

    margin-bottom: 7px;

    color: #c9e2f1;

    font-size: 9px;
    font-weight: 900;

    letter-spacing: 1.2px;

    text-transform:
        uppercase;
}


.registry-hero h1 {
    margin:
        0 0 6px;

    color: #ffffff;

    font-size: 28px;
    font-weight: 800;
}


.registry-hero p {
    max-width: 650px;

    margin: 0;

    color:
        rgba(
            255,
            255,
            255,
            .77
        );

    font-size: 11px;

    line-height: 1.55;
}


/* ==============================================================
   SEARCH PANEL
   ============================================================== */

.registry-search {
    margin-bottom: 22px;

    overflow: hidden;

    background: #ffffff;

    border:
        1px solid
        var(--registry-border);

    border-radius: 14px;

    box-shadow:
        0 5px 18px
        rgba(
            15,
            47,
            70,
            .07
        );
}


.registry-search-header {
    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 20px;

    padding:
        15px 19px;

    background:
        linear-gradient(
            100deg,
            #eaf5fb 0%,
            #f8fbfd 67%,
            #fff9e8 100%
        );

    border-bottom:
        1px solid #cfdee7;
}


.registry-search-title {
    display: flex;

    align-items: center;

    gap: 11px;
}


.registry-search-symbol {
    display: flex;

    align-items: center;
    justify-content: center;

    flex:
        0 0 37px;

    width: 37px;
    height: 37px;

    background:
        linear-gradient(
            135deg,
            var(--registry-navy),
            var(--registry-blue)
        );

    border-radius: 9px;

    color: #ffffff;

    font-size: 17px;
    font-weight: 900;

    box-shadow:
        0 3px 8px
        rgba(
            8,
            45,
            75,
            .13
        );
}


.registry-search-title strong {
    display: block;

    margin-bottom: 2px;

    color:
        var(--registry-navy-deep);

    font-size: 13px;
    font-weight: 900;
}


.registry-search-title small {
    display: block;

    color:
        var(--registry-muted);

    font-size: 9px;
}


.registry-search-badge {
    padding:
        5px 9px;

    background:
        var(--registry-gold-soft);

    border:
        1px solid #ead79a;

    border-radius: 20px;

    color:
        var(--registry-gold-dark);

    font-size: 7px;
    font-weight: 900;

    letter-spacing: .55px;

    text-transform:
        uppercase;
}


.registry-search-body {
    padding: 20px;
}


.registry-search-grid {
    display: grid;

    grid-template-columns:
        minmax(320px, 2.1fr)
        minmax(165px, .78fr)
        minmax(185px, .9fr)
        auto;

    align-items: end;

    gap: 14px;
}


/* ==============================================================
   SEARCH FIELDS
   ============================================================== */

.registry-field label {
    display: block;

    margin-bottom: 7px;

    color:
        var(--registry-secondary);

    font-size: 8px;
    font-weight: 900;

    letter-spacing: .65px;

    text-transform:
        uppercase;
}


.registry-control {
    position: relative;
}


.registry-control-marker {
    position: absolute;

    left: 13px;
    top: 50%;

    transform:
        translateY(-50%);

    display: flex;

    align-items: center;
    justify-content: center;

    width: 18px;
    height: 18px;

    color:
        var(--registry-blue);

    font-size: 14px;
    font-weight: 900;

    pointer-events: none;
}


.registry-field input,
.registry-field select {
    display: block;

    width: 100%;
    height: 46px;

    margin: 0;

    background: #fbfcfd;

    border:
        1px solid #c8d6df;

    border-radius: 9px;

    color:
        var(--registry-text);

    font-family: inherit;
    font-size: 11px;

    outline: none;

    transition:
        border-color .15s ease,
        background .15s ease,
        box-shadow .15s ease;
}


.registry-field input {
    padding:
        0 14px 0 41px;
}


.registry-field select {
    padding:
        0 12px;
}


.registry-field input::placeholder {
    color: #8a99a4;
}


.registry-field input:hover,
.registry-field select:hover {
    border-color:
        #9db8c9;
}


.registry-field input:focus,
.registry-field select:focus {
    background: #ffffff;

    border-color:
        var(--registry-blue);

    box-shadow:
        0 0 0 3px
        rgba(
            23,
            111,
            168,
            .1
        );
}


/* ==============================================================
   SEARCH ACTIONS
   ============================================================== */

.registry-search-actions {
    display: flex;

    align-items: center;

    gap: 8px;
}


.registry-submit {
    display: inline-flex;

    align-items: center;
    justify-content: center;

    min-width: 106px;
    height: 46px;

    padding:
        0 18px;

    background:
        linear-gradient(
            135deg,
            var(--registry-navy),
            var(--registry-navy-deep)
        );

    border:
        1px solid
        var(--registry-navy-deep);

    border-radius: 9px;

    box-shadow:
        0 3px 9px
        rgba(
            8,
            45,
            75,
            .16
        );

    color: #ffffff;

    cursor: pointer;

    font-family: inherit;

    font-size: 10px;
    font-weight: 900;

    transition:
        transform .15s ease,
        box-shadow .15s ease;
}


.registry-submit:hover {
    transform:
        translateY(-1px);

    box-shadow:
        0 5px 12px
        rgba(
            8,
            45,
            75,
            .2
        );
}


.registry-reset {
    display: inline-flex;

    align-items: center;
    justify-content: center;

    height: 46px;

    padding:
        0 16px;

    background: #ffffff;

    border:
        1px solid #c9d6df;

    border-radius: 9px;

    color:
        var(--registry-secondary);

    font-size: 10px;
    font-weight: 800;

    text-decoration: none;

    transition:
        background .15s ease,
        border-color .15s ease,
        color .15s ease;
}


.registry-reset:hover {
    background:
        var(--registry-gold-soft);

    border-color:
        var(--registry-gold);

    color:
        var(--registry-gold-dark);
}


/* ==============================================================
   RESULT SUMMARY
   ============================================================== */

.registry-summary {
    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 16px;

    margin:
        0 2px 12px;
}


.registry-summary-count {
    color:
        var(--registry-secondary);

    font-size: 10px;
}


.registry-summary-count strong {
    color:
        var(--registry-navy-deep);

    font-size: 12px;
    font-weight: 900;
}


.registry-summary-filter {
    display: flex;

    align-items: center;

    flex-wrap: wrap;

    gap: 6px;

    color:
        var(--registry-muted);

    font-size: 9px;
}


.registry-filter-chip {
    display: inline-flex;

    align-items: center;

    padding:
        4px 8px;

    background:
        var(--registry-blue-soft);

    border:
        1px solid #c9deeb;

    border-radius: 20px;

    color:
        var(--registry-navy);

    font-size: 8px;
    font-weight: 900;
}


/* ==============================================================
   REGISTRY TABLE
   ==============================================================
   Compact rows for long lists (1,000+ organizations):
   - about 44px per row instead of about 90px
   - the column header stays visible while scrolling
   ============================================================== */

.registry-list {
    /*
    | "clip" keeps the rounded corners but, unlike "hidden",
    | still lets the column header stick while scrolling.
    */
    overflow: clip;

    background: #ffffff;

    border:
        1px solid #ccd9e2;

    border-radius: 14px;

    box-shadow:
        0 6px 20px
        rgba(
            15,
            47,
            70,
            .075
        );
}


.registry-table {
    width: 100%;

    border-collapse:
        collapse;

    table-layout:
        fixed;

    font-variant-numeric:
        tabular-nums;
}


/* ==============================================================
   TABLE HEADER (sticky)
   ============================================================== */

.registry-table th {
    position: sticky;

    /*
    | Sits just below the sticky blue navigation bar.
    */
    top: 55px;

    z-index: 2;

    padding:
        11px 18px;

    background:
        var(--registry-navy-deep);

    color:
        rgba(
            255,
            255,
            255,
            .92
        );

    font-size: 9px;
    font-weight: 900;

    letter-spacing: .7px;

    text-align: left;

    text-transform:
        uppercase;
}


/*
| Column widths, by position. These are tied to the number of columns in
| the table: adding the selection column at the front shifted every rule
| along one, which left the Organization Name column with no width at all
| and collapsed it to nothing. If a column is ever added or removed here,
| these four rules have to move with it.
|
|   1  selection tick   2  registration number
|   3  organization name (takes the slack)
|   4  date of registration
*/

.registry-table th:first-child {
    width: 44px;
}


.registry-table th:nth-child(2) {
    width: 22%;
}


.registry-table th:nth-child(3) {
    width: 56%;
}


.registry-table th:last-child {
    width: 20%;
}


/* ==============================================================
   TABLE ROWS
   ============================================================== */

.registry-row {
    cursor: pointer;

    background: #ffffff;

    transition:
        background .12s ease;
}


.registry-row:nth-child(even) {
    background:
        #f8fafc;
}


.registry-row + .registry-row {
    border-top:
        1px solid #e6edf1;
}


.registry-row:hover {
    background:
        #edf6fc;

    box-shadow:
        inset 4px 0 0
        var(--registry-gold);
}


.registry-row:focus {
    outline:
        2px solid
        var(--registry-blue);

    outline-offset:
        -2px;
}


.registry-table td {
    padding:
        10px 18px;

    vertical-align:
        middle;
}


/* ==============================================================
   REGISTRATION NUMBER
   ============================================================== */

.registry-number {
    display: inline-block;

    padding:
        2px 8px;

    background:
        var(--registry-blue-soft);

    border:
        1px solid #d3e5f0;

    border-radius: 6px;

    color:
        var(--registry-navy-deep);

    font-size: 12.5px;
    font-weight: 700;

    letter-spacing: .2px;

    line-height: 1.5;

    white-space: nowrap;
}


/* ==============================================================
   ORGANIZATION NAME
   ============================================================== */

.registry-name {
    display: block;

    overflow: hidden;

    color:
        var(--registry-navy-deep);

    font-size: 14px;
    font-weight: 650;

    line-height: 1.4;

    /*
    | Long names stay on one line; the full name shows on hover.
    */
    text-overflow: ellipsis;

    white-space: nowrap;
}


.registry-row:hover .registry-name {
    color:
        var(--registry-blue);

    text-decoration: underline;

    text-underline-offset: 3px;
}


/* ==============================================================
   REGISTRATION DATE
   ============================================================== */

.registry-date {
    display: block;

    color:
        var(--registry-secondary);

    font-size: 13px;
    font-weight: 600;

    line-height: 1.4;

    white-space: nowrap;
}


/* ==============================================================
   EMPTY STATE
   ============================================================== */

.registry-empty {
    padding:
        55px 25px;

    text-align: center;
}


.registry-empty-symbol {
    display: flex;

    align-items: center;
    justify-content: center;

    width: 52px;
    height: 52px;

    margin:
        0 auto 14px;

    background:
        var(--registry-blue-soft);

    border:
        1px solid #c8deeb;

    border-radius: 13px;

    color:
        var(--registry-blue);

    font-size: 20px;
    font-weight: 900;
}


.registry-empty h3 {
    margin:
        0 0 5px;

    color:
        var(--registry-navy-deep);

    font-size: 16px;
    font-weight: 900;
}


.registry-empty p {
    margin: 0;

    color:
        var(--registry-muted);

    font-size: 10px;
}


/* ==============================================================
   PAGINATION
   ============================================================== */

.registry-pagination {
    display: flex;

    align-items: center;
    justify-content: space-between;

    gap: 15px;

    margin-top: 17px;
}


.registry-pagination-info {
    color:
        var(--registry-muted);

    font-size: 9px;
}


.registry-pagination-info strong {
    color:
        var(--registry-navy-deep);

    font-size: 10px;
}


.registry-pages {
    display: flex;

    align-items: center;

    gap: 5px;
}


.registry-page {
    display: inline-flex;

    align-items: center;
    justify-content: center;

    min-width: 36px;
    height: 36px;

    padding:
        0 9px;

    background: #ffffff;

    border:
        1px solid
        var(--registry-border);

    border-radius: 7px;

    color:
        var(--registry-navy);

    font-size: 10px;
    font-weight: 900;

    text-decoration: none;

    transition:
        background .15s ease,
        border-color .15s ease,
        color .15s ease;
}


.registry-page:hover {
    background:
        var(--registry-blue-soft);

    border-color:
        #bad6e6;
}


.registry-page.active {
    background:
        var(--registry-navy);

    border-color:
        var(--registry-navy);

    color: #ffffff;
}


.registry-page.disabled {
    opacity: .4;

    pointer-events: none;
}


/* ==============================================================
   RESPONSIVE
   ============================================================== */

@media (max-width: 900px) {

    .registry-search-grid {
        grid-template-columns:
            1fr 1fr;
    }


    .registry-field.search {
        grid-column:
            1 / -1;
    }


    .registry-search-actions {
        align-self: end;
    }
}


@media (max-width: 650px) {

    .registry-hero-content {
        padding:
            23px 20px;
    }


    .registry-hero h1 {
        font-size: 23px;
    }


    .registry-search-header {
        align-items:
            flex-start;

        flex-direction:
            column;
    }


    .registry-search-badge {
        display: none;
    }


    .registry-search-grid {
        grid-template-columns:
            1fr;
    }


    .registry-field.search {
        grid-column: auto;
    }


    .registry-search-actions {
        width: 100%;
    }


    .registry-submit,
    .registry-reset {
        flex: 1;
    }


    .registry-summary,
    .registry-pagination {
        align-items:
            flex-start;

        flex-direction:
            column;
    }


    /*
    | On phones the table scrolls sideways, so the header
    | scrolls with it instead of sticking.
    */

    .registry-list {
        overflow-x: auto;
    }


    .registry-table {
        min-width: 600px;
    }


    .registry-table th {
        position: static;
    }
}

</style>


<div class="applications-page">

    <div class="pbo-registry">


        <!-- ======================================================
             REGISTRY HERO
             ====================================================== -->

        <section class="registry-hero">

            <div class="registry-hero-content">

                <span class="registry-hero-eyebrow">
                    PBO REGISTRY
                </span>


                <h1>
                    List of PBOs
                </h1>


                <p>
                    Search and access submitted Public Benefit Organization
                    registration records.
                </p>

            </div>

        </section>


        <!-- ======================================================
             SEARCH & FILTER
             ====================================================== -->

        <section class="registry-search">

            <div class="registry-search-header">

                <div class="registry-search-title">

                    <div class="registry-search-symbol">
                        &#9906;
                    </div>


                    <div>

                        <strong>
                            Search the PBO Registry
                        </strong>


                        <small>
                            Search by organization name or registration number,
                            then refine by registration year or organization type.
                        </small>

                    </div>

                </div>


                <span class="registry-search-badge">
                    Registry Search
                </span>

            </div>


            <div class="registry-search-body">

                <form
                    method="get"
                    action="/pbo-portal/applications/pbos.php"
                >

                    <!--
                        Searching must not silently drop the letter and
                        ordering the user already chose.
                    -->
                    <input type="hidden" name="letter" value="<?= e($letter) ?>">
                    <input type="hidden" name="sort" value="<?= e($sort) ?>">

                    <div class="registry-search-grid">


                        <!-- SEARCH -->

                        <div class="registry-field search">

                            <label for="q">
                                Organization or Registration Number
                            </label>


                            <div class="registry-control">

                                <span class="registry-control-marker">
                                    &#9906;
                                </span>


                                <input
                                    type="search"
                                    id="q"
                                    name="q"
                                    value="<?= e($search) ?>"
                                    placeholder="Search the PBO registry..."
                                    autocomplete="off"
                                >

                            </div>

                        </div>


                        <!-- YEAR -->

                        <div class="registry-field">

                            <label for="year">
                                Registration Year
                            </label>


                            <select
                                id="year"
                                name="year"
                            >

                                <option value="">
                                    All Years
                                </option>


                                <?php foreach (
                                    $registrationYears
                                    as $registrationYear
                                ): ?>

                                    <?php

                                    $registrationYear =
                                        (string)
                                        $registrationYear;

                                    ?>


                                    <option
                                        value="<?= e(
                                            $registrationYear
                                        ) ?>"
                                        <?= $year ===
                                            $registrationYear
                                                ? 'selected'
                                                : '' ?>
                                    >
                                        <?= e(
                                            $registrationYear
                                        ) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- TYPE -->

                        <div class="registry-field">

                            <label for="type">
                                Organization Type
                            </label>


                            <select
                                id="type"
                                name="type"
                            >

                                <option value="">
                                    All Types
                                </option>


                                <option
                                    value="national"
                                    <?= $type ===
                                        'national'
                                            ? 'selected'
                                            : '' ?>
                                >
                                    National
                                </option>


                                <option
                                    value="international"
                                    <?= $type ===
                                        'international'
                                            ? 'selected'
                                            : '' ?>
                                >
                                    International
                                </option>

                            </select>

                        </div>


                        <!-- ACTIONS -->

                        <div class="registry-search-actions">

                            <button
                                type="submit"
                                class="registry-submit"
                            >
                                Search
                            </button>


                            <a
                                href="/pbo-portal/applications/pbos.php"
                                class="registry-reset"
                            >
                                Reset
                            </a>

                        </div>

                    </div>

                </form>


                <!-- ==================================================
                     ALPHABET AND ORDERING

                     Both are plain links, so a filtered view can be
                     bookmarked, shared, and handed to the export with
                     the same query string.
                     ================================================== -->

                <div class="registry-alphabet">

                    <span class="registry-alphabet-label">Name begins with</span>

                    <a
                        href="<?= e(registryUrl(['letter' => null, 'page' => null])) ?>"
                        class="registry-letter<?= $letter === '' ? ' is-active' : '' ?>"
                    >All</a>

                    <?php foreach (range('A', 'Z') as $alphabetLetter): ?>
                        <a
                            href="<?= e(registryUrl([
                                'letter' => $alphabetLetter,
                                'page' => null,
                            ])) ?>"
                            class="registry-letter<?= $letter === $alphabetLetter ? ' is-active' : '' ?>"
                        ><?= e($alphabetLetter) ?></a>
                    <?php endforeach; ?>

                    <a
                        href="<?= e(registryUrl(['letter' => '#', 'page' => null])) ?>"
                        class="registry-letter<?= $letter === '#' ? ' is-active' : '' ?>"
                        title="Names beginning with a number or symbol"
                    >#</a>

                </div>


                <div class="registry-ordering">

                    <span class="registry-alphabet-label">Order</span>

                    <?php foreach (pboRegistrySortOptions() as $sortKey => $sortOption): ?>
                        <a
                            href="<?= e(registryUrl([
                                'sort' => $sortKey,
                                'page' => null,
                            ])) ?>"
                            class="registry-letter<?= $sort === $sortKey ? ' is-active' : '' ?>"
                        ><?= e($sortOption['label']) ?></a>
                    <?php endforeach; ?>

                </div>

            </div>

        </section>


        <!-- ======================================================
             RESULT SUMMARY
             ====================================================== -->

        <div class="registry-summary">

            <div class="registry-summary-count">

                <?php if (
                    $totalRecords > 0
                ): ?>

                    Showing

                    <strong>
                        <?= number_format(
                            $firstResult
                        ) ?>
                    </strong>

                    –

                    <strong>
                        <?= number_format(
                            $lastResult
                        ) ?>
                    </strong>

                    of

                    <strong>
                        <?= number_format(
                            $totalRecords
                        ) ?>
                    </strong>

                    registered
                    PBO<?= $totalRecords === 1
                        ? ''
                        : 's' ?>


                <?php else: ?>

                    <strong>
                        No PBOs found
                    </strong>

                <?php endif; ?>

            </div>


            <?php if (
                $search !== '' ||
                $year !== '' ||
                $type !== ''
            ): ?>

                <div class="registry-summary-filter">

                    <span>
                        Active filters:
                    </span>


                    <?php if (
                        $search !== ''
                    ): ?>

                        <span class="registry-filter-chip">
                            Search: <?= e($search) ?>
                        </span>

                    <?php endif; ?>


                    <?php if (
                        $year !== ''
                    ): ?>

                        <span class="registry-filter-chip">
                            Year: <?= e($year) ?>
                        </span>

                    <?php endif; ?>


                    <?php if (
                        $type !== ''
                    ): ?>

                        <span class="registry-filter-chip">

                            <?= $type ===
                                'international'
                                    ? 'International'
                                    : 'National' ?>

                        </span>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </div>


        <!-- ======================================================
             REGISTRY TABLE
             ====================================================== -->

        <section class="registry-list">

            <?php if (
                $pbos !== []
            ): ?>

                <!-- ==================================================
                     EXPORT AND PRINT

                     Acts on the ticked rows, or on everything matching
                     the current filters when nothing is ticked. The
                     export reads the same filters this page did, so
                     what comes out is what is on screen.
                     ================================================== -->

                <div
                    class="registry-actions"
                    data-registry-actions
                    data-export-url="/pbo-portal/applications/export-registry.php"
                    data-filter-query="<?= e(http_build_query(array_filter([
                        'q' => $search,
                        'year' => $year,
                        'type' => $type,
                        'letter' => $letter,
                        'sort' => $sort,
                    ], static fn ($v) => $v !== '' && $v !== null))) ?>"
                    data-total="<?= (int) $totalRecords ?>"
                >

                    <span class="registry-actions-scope" data-scope-label>
                        Nothing ticked &mdash; actions apply to all
                        <?= (int) $totalRecords ?> matching
                        organization<?= $totalRecords === 1 ? '' : 's' ?>
                    </span>

                    <span class="registry-actions-buttons">

                        <button type="button" class="registry-action" data-export="list-excel">
                            Export list to Excel
                        </button>

                        <button type="button" class="registry-action" data-export="list-print">
                            Print list
                        </button>

                        <button type="button" class="registry-action" data-export="details-excel">
                            Export full details
                        </button>

                        <button type="button" class="registry-action" data-export="details-print">
                            Print full details
                        </button>

                        <button
                            type="button"
                            class="registry-action registry-action-quiet"
                            data-clear-selection
                            hidden
                        >
                            Clear selection
                        </button>

                    </span>

                </div>

                <table class="registry-table">

                    <thead>

                        <tr>

                            <th class="registry-tick-cell">
                                <input
                                    type="checkbox"
                                    data-select-page
                                    title="Tick every organization on this page"
                                    aria-label="Select all on this page"
                                >
                            </th>

                            <th>
                                Registration Number
                            </th>

                            <th>
                                Organization Name
                            </th>

                            <th>
                                Date of Registration
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach (
                            $pbos
                            as $pbo
                        ): ?>

                            <?php

                            $recordUrl =
                                '/pbo-portal/applications/view.php?id=' .
                                urlencode(
                                    (string)
                                    $pbo[
                                        'id'
                                    ]
                                );

                            $pboName =
                                (string)
                                $pbo[
                                    'pbo_name'
                                ];

                            ?>


                            <tr
                                class="registry-row"
                                tabindex="0"
                                role="link"
                                data-record-url="<?= e(
                                    $recordUrl
                                ) ?>"
                                aria-label="Open registration record for <?= e(
                                    $pboName
                                ) ?>"
                            >


                                <!-- SELECTION -->

                                <td class="registry-tick-cell">
                                    <input
                                        type="checkbox"
                                        data-registry-tick
                                        value="<?= (int) $pbo['id'] ?>"
                                        aria-label="Select <?= e($pboName) ?>"
                                    >
                                </td>


                                <!-- REGISTRATION NUMBER -->

                                <td>

                                    <span class="registry-number">

                                        <?= displayValue(
                                            $pbo[
                                                'pbo_registration_number'
                                            ]
                                        ) ?>

                                    </span>

                                </td>


                                <!-- ORGANIZATION NAME -->

                                <td>

                                    <span
                                        class="registry-name"
                                        title="<?= e($pboName) ?>"
                                    >

                                        <?= displayValue(
                                            $pboName
                                        ) ?>

                                    </span>

                                </td>


                                <!-- REGISTRATION DATE -->

                                <td>

                                    <span class="registry-date">

                                        <?= e(
                                            formatRegistrationDate(
                                                $pbo[
                                                    'registration_date'
                                                ]
                                            )
                                        ) ?>

                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>


            <?php else: ?>

                <div class="registry-empty">

                    <div class="registry-empty-symbol">
                        &#9906;
                    </div>


                    <h3>
                        No PBOs found
                    </h3>


                    <p>

                        <?php if (
                            $search !== '' ||
                            $year !== '' ||
                            $type !== ''
                        ): ?>

                            No submitted organizations match the
                            current search and filters.

                        <?php else: ?>

                            There are currently no submitted PBO
                            registration records.

                        <?php endif; ?>

                    </p>

                </div>

            <?php endif; ?>

        </section>


        <!-- ======================================================
             PAGINATION
             ====================================================== -->

        <?php if (
            $totalRecords > 0
        ): ?>

            <div class="registry-pagination">

                <div class="registry-pagination-info">

                    Page

                    <strong>
                        <?= number_format(
                            $page
                        ) ?>
                    </strong>

                    of

                    <strong>
                        <?= number_format(
                            $totalPages
                        ) ?>
                    </strong>

                </div>


                <?php if (
                    $totalPages > 1
                ): ?>

                    <nav
                        class="registry-pages"
                        aria-label="PBO registry pages"
                    >


                        <!-- PREVIOUS -->

                        <a
                            href="<?= e(
                                buildPboListUrl([
                                    'page' =>
                                        max(
                                            1,
                                            $page - 1
                                        ),
                                ])
                            ) ?>"
                            class="registry-page <?= $page <= 1
                                ? 'disabled'
                                : '' ?>"
                            <?= $page <= 1
                                ? 'aria-disabled="true" tabindex="-1"'
                                : '' ?>
                        >
                            &lsaquo;
                        </a>


                        <?php

                        /*
                        |--------------------------------------------------------------------------
                        | Pagination window
                        |--------------------------------------------------------------------------
                        */

                        $windowStart =
                            max(
                                1,
                                $page - 2
                            );


                        $windowEnd =
                            min(
                                $totalPages,
                                $page + 2
                            );


                        /*
                        | Maintain approximately five visible page buttons.
                        */

                        if (
                            $windowEnd -
                            $windowStart <
                            4
                        ) {

                            if (
                                $windowStart === 1
                            ) {

                                $windowEnd =
                                    min(
                                        $totalPages,
                                        5
                                    );

                            } elseif (
                                $windowEnd ===
                                $totalPages
                            ) {

                                $windowStart =
                                    max(
                                        1,
                                        $totalPages - 4
                                    );
                            }
                        }

                        ?>


                        <!-- FIRST PAGE -->

                        <?php if (
                            $windowStart > 1
                        ): ?>

                            <a
                                href="<?= e(
                                    buildPboListUrl([
                                        'page' => 1,
                                    ])
                                ) ?>"
                                class="registry-page"
                            >
                                1
                            </a>


                            <?php if (
                                $windowStart > 2
                            ): ?>

                                <span
                                    style="
                                        color: var(--registry-muted);
                                        padding: 0 2px;
                                    "
                                >
                                    &hellip;
                                </span>

                            <?php endif; ?>

                        <?php endif; ?>


                        <!-- PAGE WINDOW -->

                        <?php for (
                            $pageNumber =
                                $windowStart;

                            $pageNumber <=
                                $windowEnd;

                            $pageNumber++
                        ): ?>

                            <a
                                href="<?= e(
                                    buildPboListUrl([
                                        'page' =>
                                            $pageNumber,
                                    ])
                                ) ?>"
                                class="registry-page <?= $pageNumber ===
                                    $page
                                        ? 'active'
                                        : '' ?>"
                                <?= $pageNumber ===
                                    $page
                                        ? 'aria-current="page"'
                                        : '' ?>
                            >
                                <?= $pageNumber ?>
                            </a>

                        <?php endfor; ?>


                        <!-- LAST PAGE -->

                        <?php if (
                            $windowEnd <
                            $totalPages
                        ): ?>

                            <?php if (
                                $windowEnd <
                                $totalPages - 1
                            ): ?>

                                <span
                                    style="
                                        color: var(--registry-muted);
                                        padding: 0 2px;
                                    "
                                >
                                    &hellip;
                                </span>

                            <?php endif; ?>


                            <a
                                href="<?= e(
                                    buildPboListUrl([
                                        'page' =>
                                            $totalPages,
                                    ])
                                ) ?>"
                                class="registry-page"
                            >
                                <?= $totalPages ?>
                            </a>

                        <?php endif; ?>


                        <!-- NEXT -->

                        <a
                            href="<?= e(
                                buildPboListUrl([
                                    'page' =>
                                        min(
                                            $totalPages,
                                            $page + 1
                                        ),
                                ])
                            ) ?>"
                            class="registry-page <?= $page >=
                                $totalPages
                                    ? 'disabled'
                                    : '' ?>"
                            <?= $page >=
                                $totalPages
                                    ? 'aria-disabled="true" tabindex="-1"'
                                    : '' ?>
                        >
                            &rsaquo;
                        </a>

                    </nav>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

</div>


<!-- ==============================================================
     REGISTRY INTERACTIONS
     ============================================================== -->

<script>
(function () {

    'use strict';


    /*
    |--------------------------------------------------------------------------
    | Clickable registry rows
    |--------------------------------------------------------------------------
    */

    const rows =
        document.querySelectorAll(
            '.registry-row[data-record-url]'
        );


    rows.forEach(
        function (row) {

            function openRecord() {

                const url =
                    row.getAttribute(
                        'data-record-url'
                    );


                if (url) {

                    window.location.href =
                        url;
                }
            }


            row.addEventListener(
                'click',
                function (event) {

                    /*
                    | Ticking a row for export is not a request to open
                    | it. Without this the checkbox is unusable: the
                    | click ticks the box and then navigates away.
                    */
                    if (event.target.closest('.registry-tick-cell')) {
                        return;
                    }

                    openRecord();
                }
            );


            row.addEventListener(
                'keydown',
                function (event) {

                    if (
                        event.key === 'Enter' ||
                        event.key === ' '
                    ) {

                        event.preventDefault();

                        openRecord();
                    }
                }
            );
        }
    );


    /*
    |--------------------------------------------------------------------------
    | Automatic filter submission
    |--------------------------------------------------------------------------
    |
    | Selecting a year or organization type immediately refreshes the
    | registry. The text search still waits for the Search button.
    |
    */

    const yearFilter =
        document.getElementById(
            'year'
        );


    const typeFilter =
        document.getElementById(
            'type'
        );


    if (yearFilter) {

        yearFilter.addEventListener(
            'change',
            function () {

                this.form.submit();
            }
        );
    }


    if (typeFilter) {

        typeFilter.addEventListener(
            'change',
            function () {

                this.form.submit();
            }
        );
    }

})();
</script>


<!-- Export and print the register: selection, scope and the buttons above the table. -->
<script src="/pbo-portal/assets/js/registry-export.js" defer></script>


<?php

/*
|--------------------------------------------------------------------------
| Shared footer
|--------------------------------------------------------------------------
*/

require_once __DIR__ .
    '/../includes/footer.php';

?>