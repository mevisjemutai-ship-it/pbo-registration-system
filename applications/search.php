<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
requirePasswordChanged();


/*
|--------------------------------------------------------------------------
| Page configuration
|--------------------------------------------------------------------------
*/

$pageTitle = 'Search Organizations';
$activePage = 'applications';


/*
|--------------------------------------------------------------------------
| Database connection
|--------------------------------------------------------------------------
*/

$connection = null;

if (isset($pdo) && $pdo instanceof PDO) {

    $connection = $pdo;

} elseif (isset($db) && $db instanceof PDO) {

    $connection = $db;
}


if (!$connection instanceof PDO) {

    throw new RuntimeException(
        'Database connection was not initialized as a PDO instance.'
    );
}


/*
|--------------------------------------------------------------------------
| Current user
|--------------------------------------------------------------------------
*/

$userId =
    (int) ($_SESSION['user_id'] ?? 0);


if ($userId <= 0) {

    header(
        'Location: /pbo-portal/login.php'
    );

    exit;
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


function searchOrganizationType(
    string $type
): string {

    return match ($type) {

        'national'
            => 'National',

        'international'
            => 'International',

    
        default
            => ucwords(
                str_replace(
                    '_',
                    ' ',
                    $type
                )
            ),
    };
}


/*
|--------------------------------------------------------------------------
| Draft continuation URL
|--------------------------------------------------------------------------
*/

function draftContinueUrl(
    int $applicationId,
    int $currentStep
): string {

    $page = match (true) {

        $currentStep <= 1
            => 'create.php',

        $currentStep === 2
            => 'objectives.php',

        $currentStep === 3
            => 'beneficiaries.php',

        $currentStep === 4
            => 'financial-year.php',

        $currentStep === 5
            => 'officials.php',

        default
            => 'review.php',
    };


    return
        '/pbo-portal/applications/' .
        $page .
        '?id=' .
        urlencode(
            (string) $applicationId
        );
}


/*
|--------------------------------------------------------------------------
| Search parameters
|--------------------------------------------------------------------------
*/

$search =
    trim(
        (string)
        ($_GET['q'] ?? '')
    );


$type =
    trim(
        (string)
        ($_GET['type'] ?? '')
    );


$status =
    trim(
        (string)
        ($_GET['status'] ?? '')
    );


$allowedTypes = [

    '',

    'national',

    'international',

    'international_exempt',
];


if (
    !in_array(
        $type,
        $allowedTypes,
        true
    )
) {

    $type = '';
}


$allowedStatuses = [

    '',

    'draft',

    'submitted',
];


if (
    !in_array(
        $status,
        $allowedStatuses,
        true
    )
) {

    $status = '';
}


/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$page =
    filter_input(
        INPUT_GET,
        'page',
        FILTER_VALIDATE_INT
    );


if (!$page || $page < 1) {

    $page = 1;
}


$perPage = 20;


$offset =
    ($page - 1) *
    $perPage;


/*
|--------------------------------------------------------------------------
| Build query
|--------------------------------------------------------------------------
|
| Keep the ownership model already used throughout the portal:
| officers search only applications they created.
|
*/

$where = "
    WHERE
        (
            a.status = 'submitted'
            OR
            (
                a.status = 'draft'
                AND a.created_by = :user_id
            )
        )
";


$parameters = [
    ':user_id'
        => $userId,
];


/*
|--------------------------------------------------------------------------
| Text search
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $where .= "
        AND
        (
            a.application_reference
                LIKE :search

            OR a.pbo_name
                LIKE :search

            OR a.pbo_registration_number
                LIKE :search

            OR ad.county
                LIKE :search

            OR ad.email
                LIKE :search

            OR ad.phone_number
                LIKE :search
        )
    ";


    $parameters[':search'] =
        '%' .
        $search .
        '%';
}


/*
|--------------------------------------------------------------------------
| Organization type
|--------------------------------------------------------------------------
*/

if ($type !== '') {

    $where .= "
        AND a.registration_type =
            :registration_type
    ";


    $parameters[
        ':registration_type'
    ] = $type;
}


/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

if ($status !== '') {

    $where .= "
        AND a.status =
            :status
    ";


    $parameters[
        ':status'
    ] = $status;
}


/*
|--------------------------------------------------------------------------
| Count results
|--------------------------------------------------------------------------
*/

$statement =
    $connection->prepare(
        "
        SELECT
            COUNT(DISTINCT a.id)

        FROM pbo_applications a

        LEFT JOIN application_addresses ad
            ON ad.application_id = a.id

        $where
        "
    );


$statement->execute(
    $parameters
);


$totalResults =
    (int)
    $statement->fetchColumn();


$totalPages =
    max(
        1,
        (int)
        ceil(
            $totalResults /
            $perPage
        )
    );


if ($page > $totalPages) {

    $page =
        $totalPages;


    $offset =
        ($page - 1) *
        $perPage;
}


/*
|--------------------------------------------------------------------------
| Load results
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT DISTINCT
        a.id,
        a.application_reference,
        a.pbo_name,
        a.pbo_registration_number,
        a.registration_type,
        a.status,
        a.current_step,
        a.created_at,
        a.updated_at,
        a.submitted_at,

        ad.county,
        ad.sub_county,
        ad.phone_number,
        ad.email

    FROM pbo_applications a

    LEFT JOIN application_addresses ad
        ON ad.application_id = a.id

    $where

    ORDER BY
        CASE
            WHEN a.status = 'draft'
                THEN 1
            WHEN a.status = 'submitted'
                THEN 2
            ELSE 3
        END,

        COALESCE(
            a.updated_at,
            a.created_at
        ) DESC,

        a.id DESC

    LIMIT :limit
    OFFSET :offset
";


$statement =
    $connection->prepare(
        $sql
    );


foreach (
    $parameters
    as $key => $value
) {

    $statement->bindValue(
        $key,
        $value,
        PDO::PARAM_STR
    );
}


$statement->bindValue(
    ':limit',
    $perPage,
    PDO::PARAM_INT
);


$statement->bindValue(
    ':offset',
    $offset,
    PDO::PARAM_INT
);


$statement->execute();


$results =
    $statement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/header.php';

?>


<style>

/* ==============================================================
   ORGANIZATION SEARCH
   ============================================================== */

.organization-search-panel {
    margin-bottom: 20px;
    padding: 20px;

    background: #ffffff;

    border: 1px solid var(--border);
    border-radius: var(--radius);

    box-shadow: var(--shadow-sm);
}


.organization-search-grid {
    display: grid;

    grid-template-columns:
        minmax(260px, 2fr)
        minmax(180px, 1fr)
        minmax(160px, 1fr);

    gap: 12px;

    align-items: end;
}


.organization-search-grid
.form-group {

    margin-bottom: 0;
}


.organization-search-actions {
    display: flex;

    gap: 8px;

    margin-top: 14px;
}


.search-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;

    margin-bottom: 15px;
}


.search-summary strong {
    color: var(--blue-dark);
}


.search-results-wrap {
    overflow-x: auto;
}


.search-results-table {
    width: 100%;
    min-width: 1100px;

    border-collapse: collapse;
}


.search-results-table th {
    padding: 12px;

    text-align: left;

    color: var(--text-muted);

    border-bottom:
        2px solid var(--border);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: .5px;

    text-transform: uppercase;
}


.search-results-table td {
    padding: 14px 12px;

    vertical-align: middle;

    border-bottom:
        1px solid var(--border);

    font-size: 12px;
}


.search-results-table
tbody tr {

    transition:
        background .15s ease;
}


.search-results-table
tbody tr:hover {

    background:
        var(--soft-bg);
}


.search-reference {
    color: var(--blue-dark);

    font-weight: 800;

    white-space: nowrap;
}


.search-organization-name {
    display: block;

    color: var(--heading);

    font-weight: 700;
}


.search-secondary {
    display: block;

    margin-top: 3px;

    color: var(--text-muted);

    font-size: 10px;
}


.search-status {
    display: inline-flex;
    align-items: center;

    padding: 5px 9px;

    border-radius: 20px;

    font-size: 9px;
    font-weight: 800;

    letter-spacing: .3px;

    text-transform: uppercase;
}


.search-status-draft {
    background: var(--gold-light);

    border: 1px solid #eadfbf;

    color: #755c12;
}


.search-status-submitted {
    background: var(--green-light);

    border: 1px solid #c3dfce;

    color: var(--green-dark);
}


.search-empty {
    padding: 48px 20px;

    text-align: center;

    background: var(--soft-bg);

    border: 1px dashed var(--border);
    border-radius: var(--radius);
}


.search-empty strong {
    display: block;

    margin-bottom: 6px;

    color: var(--heading);

    font-size: 14px;
}


.search-empty span {
    color: var(--text-muted);

    font-size: 11px;
}


.search-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;

    margin-top: 22px;
}


.search-pagination-actions {
    display: flex;

    gap: 8px;
}


@media (max-width: 850px) {

    .organization-search-grid {
        grid-template-columns: 1fr;
    }


    .organization-search-actions {
        flex-direction: column;
    }


    .organization-search-actions .btn {
        width: 100%;
    }

}

</style>


<div class="applications-page">


    <!-- ==========================================================
         PAGE HEADER
         ========================================================== -->

    <section class="page-header">

        <div>

            <span class="section-eyebrow">
                PBO REGISTRATION SYSTEM
            </span>

            <h1>
                Search Organizations
            </h1>

            <p class="subtitle">
                Search saved and submitted organization records.
            </p>

        </div>


        <a
            href="/pbo-portal/applications/create.php"
            class="btn btn-primary"
        >
            + New Application
        </a>

    </section>


    <!-- ==========================================================
         SEARCH PANEL
         ========================================================== -->

    <section class="organization-search-panel">

        <form
            method="get"
            action=""
        >

            <div class="organization-search-grid">


                <!-- SEARCH -->

                <div class="form-group">

                    <label for="organization-search">
                        Search
                    </label>

                    <input
                        type="search"
                        id="organization-search"
                        name="q"
                        value="<?= e($search) ?>"
                        placeholder="Name, reference, PBO number, county, email or phone"
                    >

                </div>


                <!-- TYPE -->

                <div class="form-group">

                    <label for="organization-type">
                        Type of Organization
                    </label>

                    <select
                        id="organization-type"
                        name="type"
                    >

                        <option value="">
                            All Types
                        </option>

                        <option
                            value="national"
                            <?= $type === 'national'
                                ? 'selected'
                                : '' ?>
                        >
                            National
                        </option>

                        <option
                            value="international"
                            <?= $type === 'international'
                                ? 'selected'
                                : '' ?>
                        >
                            International
                        </option>

    
                    </select>

                </div>


                <!-- STATUS -->

                <div class="form-group">

                    <label for="organization-status">
                        Status
                    </label>

                    <select
                        id="organization-status"
                        name="status"
                    >

                        <option value="">
                            All Statuses
                        </option>

                        <option
                            value="draft"
                            <?= $status === 'draft'
                                ? 'selected'
                                : '' ?>
                        >
                            Draft
                        </option>

                        <option
                            value="submitted"
                            <?= $status === 'submitted'
                                ? 'selected'
                                : '' ?>
                        >
                            Submitted
                        </option>

                    </select>

                </div>

            </div>


            <div class="organization-search-actions">

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Search Organizations
                </button>


                <?php if (
                    $search !== '' ||
                    $type !== '' ||
                    $status !== ''
                ): ?>

                    <a
                        href="/pbo-portal/applications/search.php"
                        class="btn btn-secondary"
                    >
                        Clear Search
                    </a>

                <?php endif; ?>

            </div>

        </form>

    </section>


    <!-- ==========================================================
         RESULTS
         ========================================================== -->

    <section class="application-section">

        <div class="application-section-heading">

            <div class="application-section-number">
                S
            </div>


            <div>

                <h2>
                    Organization Results
                </h2>

                <p>
                    Search and open organization application records.
                </p>

            </div>

        </div>


        <div class="search-summary">

            <span>

                <strong>
                    <?= number_format(
                        $totalResults
                    ) ?>
                </strong>

                <?= $totalResults === 1
                    ? 'organization found'
                    : 'organizations found' ?>

            </span>

        </div>


        <?php if ($results === []): ?>

            <div class="search-empty">

                <strong>
                    No organizations found
                </strong>

                <span>

                    <?php if (
                        $search !== '' ||
                        $type !== '' ||
                        $status !== ''
                    ): ?>

                        No organization records match the
                        selected search criteria.

                    <?php else: ?>

                        Your saved organization records will
                        appear here.

                    <?php endif; ?>

                </span>

            </div>


        <?php else: ?>


            <div class="search-results-wrap">

                <table class="search-results-table">

                    <thead>

                        <tr>

                            <th>
                                Reference
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
                                Location
                            </th>

                            <th>
                                Contact
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Updated / Submitted
                            </th>

                            <th style="text-align: right;">
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach (
                            $results
                            as $result
                        ): ?>

                            <?php

                            $isSubmitted =
                                (string)
                                $result['status'] ===
                                'submitted';


                            if ($isSubmitted) {

                                $actionUrl =
                                    '/pbo-portal/applications/view.php?id=' .
                                    urlencode(
                                        (string)
                                        $result['id']
                                    );


                                $actionLabel =
                                    'View';

                            } else {

                                $actionUrl =
                                    draftContinueUrl(
                                        (int)
                                        $result['id'],
                                        (int)
                                        (
                                            $result[
                                                'current_step'
                                            ]
                                            ?? 1
                                        )
                                    );


                                $actionLabel =
                                    'Continue';
                            }


                            $dateValue =
                                $isSubmitted
                                    ? (
                                        $result[
                                            'submitted_at'
                                        ]
                                        ?? ''
                                    )
                                    : (
                                        $result[
                                            'updated_at'
                                        ]
                                        ??
                                        $result[
                                            'created_at'
                                        ]
                                        ??
                                        ''
                                    );


                            $dateTimestamp =
                                $dateValue !== ''
                                    ? strtotime(
                                        (string)
                                        $dateValue
                                    )
                                    : false;


                            $dateDisplay =
                                $dateTimestamp !== false
                                    ? date(
                                        'd M Y, H:i',
                                        $dateTimestamp
                                    )
                                    : '—';

                            ?>


                            <tr>


                                <!-- REFERENCE -->

                                <td>

                                    <span class="search-reference">

                                        <?= e(
                                            (string)
                                            $result[
                                                'application_reference'
                                            ]
                                        ) ?>

                                    </span>

                                </td>


                                <!-- ORGANIZATION -->

                                <td>

                                    <span class="search-organization-name">

                                        <?= e(
                                            (string)
                                            $result[
                                                'pbo_name'
                                            ]
                                        ) ?>

                                    </span>


                                    <?php if (
                                        !empty(
                                            $result['email']
                                        )
                                    ): ?>

                                        <span class="search-secondary">

                                            <?= e(
                                                (string)
                                                $result['email']
                                            ) ?>

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- PBO NUMBER -->

                                <td>

                                    <?php if (
                                        !empty(
                                            $result[
                                                'pbo_registration_number'
                                            ]
                                        )
                                    ): ?>

                                        <?= e(
                                            (string)
                                            $result[
                                                'pbo_registration_number'
                                            ]
                                        ) ?>

                                    <?php else: ?>                                        <span class="text-muted">
                                            —
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- TYPE -->

                                <td>

                                    <?= e(
                                        searchOrganizationType(
                                            (string)
                                            $result[
                                                'registration_type'
                                            ]
                                        )
                                    ) ?>

                                </td>


                                <!-- LOCATION -->

                                <td>

                                    <?php if (
                                        !empty(
                                            $result['county']
                                        )
                                    ): ?>

                                        <strong>
                                            <?= e(
                                                (string)
                                                $result['county']
                                            ) ?>
                                        </strong>


                                        <?php if (
                                            !empty(
                                                $result[
                                                    'sub_county'
                                                ]
                                            )
                                        ): ?>

                                            <span class="search-secondary">

                                                <?= e(
                                                    (string)
                                                    $result[
                                                        'sub_county'
                                                    ]
                                                ) ?>

                                            </span>

                                        <?php endif; ?>


                                    <?php else: ?>

                                        <span class="text-muted">
                                            —
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- CONTACT -->

                                <td>

                                    <?php if (
                                        !empty(
                                            $result[
                                                'phone_number'
                                            ]
                                        )
                                    ): ?>

                                        <?= e(
                                            (string)
                                            $result[
                                                'phone_number'
                                            ]
                                        ) ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            —
                                        </span>

                                    <?php endif; ?>


                                    <?php if (
                                        !empty(
                                            $result['email']
                                        )
                                    ): ?>

                                        <span class="search-secondary">

                                            <?= e(
                                                (string)
                                                $result['email']
                                            ) ?>

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- STATUS -->

                                <td>

                                    <?php if (
                                        $isSubmitted
                                    ): ?>

                                        <span
                                            class="
                                                search-status
                                                search-status-submitted
                                            "
                                        >
                                            Submitted
                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="
                                                search-status
                                                search-status-draft
                                            "
                                        >
                                            Draft
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- DATE -->

                                <td>

                                    <?= e(
                                        $dateDisplay
                                    ) ?>

                                    <span class="search-secondary">

                                        <?= $isSubmitted
                                            ? 'Submitted'
                                            : 'Last updated' ?>

                                    </span>

                                </td>


                                <!-- ACTION -->

                                <td
                                    style="
                                        text-align: right;
                                    "
                                >

                                    <a
                                        href="<?= e(
                                            $actionUrl
                                        ) ?>"
                                        class="<?= $isSubmitted
                                            ? 'btn btn-secondary btn-sm'
                                            : 'btn btn-primary btn-sm' ?>"
                                    >
                                        <?= e(
                                            $actionLabel
                                        ) ?>

                                        <?php if (
                                            !$isSubmitted
                                        ): ?>

                                            &rarr;

                                        <?php endif; ?>

                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>


            <!-- ==================================================
                 PAGINATION
                 ================================================== -->

            <?php if (
                $totalPages > 1
            ): ?>

                <div class="search-pagination">

                    <span
                        style="
                            color: var(--text-muted);
                            font-size: 11px;
                        "
                    >
                        Page
                        <?= (int) $page ?>
                        of
                        <?= (int) $totalPages ?>
                    </span>


                    <div class="search-pagination-actions">


                        <!-- PREVIOUS -->

                        <?php if ($page > 1): ?>
                            <a
                                href="/pbo-portal/applications/search.php?<?= e(
                                    http_build_query(
                                        [
                                            'q' => $search,
                                            'type' => $type,
                                            'status' => $status,
                                            'page' => $page - 1,
                                        ]
                                    )
                                ) ?>"
                                class="btn btn-secondary btn-sm"
                            >
                                &larr; Previous
                            </a>

                        <?php endif; ?>


                        <!-- NEXT -->

                        <?php if (
                            $page < $totalPages
                        ): ?>

                            <a
                                href="/pbo-portal/applications/search.php?<?= e(
                                    http_build_query(
                                        [
                                            'q'
                                                => $search,

                                            'type'
                                                => $type,

                                            'status'
                                                => $status,

                                            'page'
                                                => $page + 1,
                                        ]
                                    )
                                ) ?>"
                                class="btn btn-secondary btn-sm"
                            >
                                Next &rarr;
                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endif; ?>

        <?php endif; ?>

    </section>


    <!-- ==========================================================
         SEARCH INFORMATION
         ========================================================== -->

    <section
        style="
            margin-top: 18px;
            padding: 16px 18px;
            background: var(--soft-bg);
            border: 1px solid var(--border);
            border-left: 4px solid var(--blue);
            border-radius: var(--radius);
        "
    >

        <span class="section-eyebrow">
            Search Information
        </span>

        <p
            style="
                margin: 5px 0 0;
                color: var(--text-secondary);
                font-size: 11px;
                line-height: 1.6;
            "
        >
            Search covers application references, organization names,
            PBO registration numbers, counties, organization email
            addresses and telephone numbers. Draft applications can be
            continued, while submitted applications open as read-only
            registration records.
        </p>

        </section>

</div>


<?php

/*
|--------------------------------------------------------------------------
| Shared footer
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/footer.php';

?>