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

$pageTitle = 'Draft Applications';
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
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {

    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    (string)
    $_SESSION['csrf_token'];

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


function draftOrganizationType(
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
| Current-step information
|--------------------------------------------------------------------------
*/

function draftStepInfo(
    int $currentStep
): array {

    /*
    |--------------------------------------------------------------------------
    | current_step represents the NEXT logical section.
    |--------------------------------------------------------------------------
    |
    | Organization save sets current_step = 2.
    |
    */

    return match (true) {

        $currentStep <= 1 => [
            'number' => 1,
            'label' => 'Organization',
            'url' => 'create.php',
        ],

        $currentStep === 2 => [
            'number' => 2,
            'label' => 'Objectives',
            'url' => 'objectives.php',
        ],

        $currentStep === 3 => [
            'number' => 3,
            'label' => 'Beneficiaries',
            'url' => 'beneficiaries.php',
        ],

        $currentStep === 4 => [
            'number' => 4,
            'label' => 'Financial Year',
            'url' => 'financial-year.php',
        ],

        $currentStep === 5 => [
            'number' => 5,
            'label' => 'Officials',
            'url' => 'officials.php',
        ],

        default => [
            'number' => 6,
            'label' => 'Review',
            'url' => 'review.php',
        ],
    };
}


/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

$search =
    trim(
        (string)
        ($_GET['q'] ?? '')
    );


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
| WHERE
|--------------------------------------------------------------------------
*/

$where = "
    WHERE
        a.status = 'draft'
        AND a.created_by = :user_id
";


$parameters = [
    ':user_id' => $userId,
];


if ($search !== '') {

    $where .= "
        AND
        (
            a.application_reference LIKE :search
            OR
            a.pbo_name LIKE :search
            OR
            a.pbo_registration_number LIKE :search
        )
    ";

    $parameters[':search'] =
        '%' .
        $search .
        '%';
}


/*
|--------------------------------------------------------------------------
| Count
|--------------------------------------------------------------------------
*/

$statement =
    $connection->prepare(
        "
        SELECT
            COUNT(*)
        FROM pbo_applications a
        $where
        "
    );


$statement->execute(
    $parameters
);


$totalDrafts =
    (int)
    $statement->fetchColumn();


$totalPages =
    max(
        1,
        (int)
        ceil(
            $totalDrafts /
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
| Load drafts
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        a.id,
        a.application_reference,
        a.pbo_name,
        a.pbo_registration_number,
        a.registration_type,
        a.status,
        a.current_step,
        a.created_at,
        a.updated_at,

        ad.county,
        ad.email

    FROM pbo_applications a

    LEFT JOIN application_addresses ad
        ON ad.application_id = a.id

    $where

    ORDER BY
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


$drafts =
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
   DRAFTS PAGE
   ============================================================== */

.drafts-summary {
    display: grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(190px, 1fr)
        );

    gap: 14px;

    margin-bottom: 20px;
}


.draft-summary-card {
    padding: 17px 19px;

    background: #ffffff;

    border: 1px solid var(--border);
    border-radius: var(--radius);

    box-shadow: var(--shadow-sm);
}


.draft-summary-card span {
    display: block;

    margin-bottom: 4px;

    color: var(--text-muted);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: .6px;
    text-transform: uppercase;
}


.draft-summary-card strong {
    color: var(--blue-dark);

    font-size: 22px;
}


.drafts-table-wrap {
    overflow-x: auto;
}


.drafts-table {
    width: 100%;
    min-width: 1050px;

    border-collapse: collapse;
}


.drafts-table th {
    padding: 12px;

    text-align: left;

    color: var(--text-muted);

    border-bottom: 2px solid var(--border);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: .5px;
    text-transform: uppercase;
}


.drafts-table td {
    padding: 14px 12px;

    vertical-align: middle;

    border-bottom: 1px solid var(--border);

    font-size: 12px;
}


.drafts-table tbody tr {
    transition:
        background .15s ease;
}


.drafts-table tbody tr:hover {
    background: var(--soft-bg);
}


.draft-reference {
    color: var(--blue-dark);

    font-weight: 800;
}


.draft-organization {
    color: var(--heading);

    font-weight: 700;
}


.draft-secondary {
    display: block;

    margin-top: 3px;

    color: var(--text-muted);

    font-size: 10px;
}


.draft-badge {
    display: inline-flex;
    align-items: center;

    padding: 5px 9px;

    background: var(--gold-light);

    border-radius: 20px;

    color: #755c12;

    font-size: 9px;
    font-weight: 800;

    text-transform: uppercase;
}


.draft-step {
    min-width: 145px;
}


.draft-step-top {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 8px;

    margin-bottom: 6px;
}


.draft-step-label {
    color: var(--heading);

    font-size: 10px;
    font-weight: 700;
}


.draft-step-number {
    color: var(--text-muted);

    font-size: 9px;
}


.draft-progress {
    height: 6px;

    overflow: hidden;

    background: #e8edf1;

    border-radius: 20px;
}


.draft-progress-bar {
    height: 100%;

    background:
        linear-gradient(
            90deg,
            var(--blue),
            var(--blue-medium)
        );

    border-radius: 20px;
}


.draft-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;

    gap: 7px;
}


@media (max-width: 700px) {

    .drafts-summary {
        grid-template-columns: 1fr;
    }

}

</style>


<div class="applications-page">


    <!-- ==========================================================
         HEADER
         ========================================================== -->

    <section class="page-header">

        <div>

            <span class="section-eyebrow">
                PBO REGISTRATION SYSTEM
            </span>

            <h1>
                Draft Applications
            </h1>

            <p class="subtitle">
                Continue applications that have been saved but not
                yet submitted.
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
         SUMMARY
         ========================================================== -->

    <div class="drafts-summary">

        <div class="draft-summary-card">

            <span>
                Saved Drafts
            </span>

            <strong>
                <?= number_format(
                    $totalDrafts
                ) ?>
            </strong>

        </div>

    </div>


    <!-- ==========================================================
         DRAFT LIST
         ========================================================== -->

    <section class="application-section">

        <div class="application-section-heading">

            <div class="application-section-number">
                D
            </div>

            <div>

                <h2>
                    Incomplete Applications
                </h2>

                <p>
                    Select Continue to resume an application from
                    its latest saved section.
                </p>

            </div>

        </div>


        <!-- ======================================================
             SEARCH
             ====================================================== -->

        <form
            method="get"
            action=""
            style="
                display: flex;
                align-items: flex-end;
                gap: 10px;
                margin-bottom: 22px;
            "
        >

            <div
                class="form-group"
                style="
                    flex: 1;
                    margin-bottom: 0;
                "
            >

                <label for="draft-search">
                    Search Drafts
                </label>

                <input
                    type="search"
                    id="draft-search"
                    name="q"
                    value="<?= e($search) ?>"
                    placeholder="Application reference, organization name or PBO number"
                >

            </div>


            <button
                type="submit"
                class="btn btn-primary"
            >
                Search
            </button>


            <?php if ($search !== ''): ?>

                <a
                    href="/pbo-portal/applications/drafts.php"
                    class="btn btn-secondary"
                >
                    Clear
                </a>

            <?php endif; ?>

        </form>


        <!-- ======================================================
             EMPTY
             ====================================================== -->

        <?php if ($drafts === []): ?>

            <div
                style="
                    padding: 45px 20px;
                    text-align: center;
                    background: var(--soft-bg);
                    border: 1px dashed var(--border);
                    border-radius: var(--radius);
                "
            >

                <strong
                    style="
                        display: block;
                        margin-bottom: 6px;
                        color: var(--heading);
                        font-size: 14px;
                    "
                >
                    No draft applications found
                </strong>


                <span
                    style="
                        color: var(--text-muted);
                        font-size: 11px;
                    "
                >

                    <?php if ($search !== ''): ?>

                        No draft applications match your search.

                    <?php else: ?>

                        Applications that have been started and saved
                        will appear here until they are submitted.

                    <?php endif; ?>

                </span>

            </div>


        <?php else: ?>


            <!-- ==================================================
                 TABLE
                 ================================================== -->

            <div class="drafts-table-wrap">

                <table class="drafts-table">

                    <thead>

                        <tr>

                            <th>
                                Application Reference
                            </th>

                            <th>
                                Organization
                            </th>

                            <th>
                                Type
                            </th>

                            <th>
                                County
                            </th>

                            <th>
                                Progress
                            </th>

                            <th>
                                Last Updated
                            </th>

                            <th>
                                Status
                            </th>

                            <th style="text-align: right;">
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach (
                            $drafts
                            as $draft
                        ): ?>

                            <?php

                            $currentStep =
                                (int)
                                (
                                    $draft[
                                        'current_step'
                                    ]
                                    ?? 1
                                );


                            $stepInfo =
                                draftStepInfo(
                                    $currentStep
                                );


                            $stepNumber =
                                (int)
                                $stepInfo['number'];


                            $progress =
                                min(
                                    100,
                                    max(
                                        0,
                                        (
                                            $stepNumber /
                                            6
                                        ) *
                                        100
                                    )
                                );


                            $continueUrl =
                                '/pbo-portal/applications/' .
                                $stepInfo['url'] .
                                '?id=' .
                                urlencode(
                                    (string)
                                    $draft['id']
                                );


                            $updatedTimestamp =
                                strtotime(
                                    (string)
                                    (
                                        $draft[
                                            'updated_at'
                                        ]
                                        ??
                                        $draft[
                                            'created_at'
                                        ]
                                    )
                                );


                            $updatedDisplay =
                                $updatedTimestamp !== false
                                    ? date(
                                        'd M Y, H:i',
                                        $updatedTimestamp
                                    )
                                    : '—';

                            ?>


                            <tr>


                                <!-- REFERENCE -->

                                <td>

                                    <span class="draft-reference">

                                        <?= e(
                                            (string)
                                            $draft[
                                                'application_reference'
                                            ]
                                        ) ?>

                                    </span>

                                </td>


                                <!-- ORGANIZATION -->

                                <td>

                                    <span class="draft-organization">

                                        <?= e(
                                            (string)
                                            $draft[
                                                'pbo_name'
                                            ]
                                        ) ?>

                                    </span>


                                    <?php if (
                                        !empty(
                                            $draft['email']
                                        )
                                    ): ?>

                                        <span class="draft-secondary">

                                            <?= e(
                                                (string)
                                                $draft['email']
                                            ) ?>

                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- TYPE -->

                                <td>

                                    <?= e(
                                        draftOrganizationType(
                                            (string)
                                            $draft[
                                                'registration_type'
                                            ]
                                        )
                                    ) ?>

                                </td>


                                <!-- COUNTY -->

                                <td>

                                    <?= !empty(
                                        $draft['county']
                                    )
                                        ? e(
                                            (string)
                                            $draft['county']
                                        )
                                        : '<span class="text-muted">—</span>' ?>

                                </td>


                                <!-- PROGRESS -->

                                <td>

                                    <div class="draft-step">

                                        <div class="draft-step-top">

                                            <span class="draft-step-label">

                                                <?= e(
                                                    (string)
                                                    $stepInfo[
                                                        'label'
                                                    ]
                                                ) ?>

                                            </span>


                                            <span class="draft-step-number">

                                                Step
                                                <?= $stepNumber ?>
                                                / 6

                                            </span>

                                        </div>


                                        <div class="draft-progress">

                                            <div
                                                class="draft-progress-bar"
                                                style="width: <?= e(
                                                    number_format(
                                                        $progress,
                                                        2,
                                                        '.',
                                                        ''
                                                    )
                                                ) ?>%;"
                                            ></div>

                                        </div>

                                    </div>

                                </td>


                                <!-- UPDATED -->

                                <td>

                                    <?= e(
                                        $updatedDisplay
                                    ) ?>

                                </td>


                                <!-- STATUS -->

                                <td>

                                    <span class="draft-badge">
                                        Draft
                                    </span>

                                </td>


                                <!-- ACTION -->

                                <td>

                                    <div class="draft-actions">

    <a
        href="<?= e(
            $continueUrl
        ) ?>"
        class="btn btn-primary btn-sm"
    >
        Continue &rarr;
    </a>


    <form
        method="post"
        action="/pbo-portal/applications/delete-draft.php"
        class="delete-draft-form"
        style="margin: 0;"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >

        <input
            type="hidden"
            name="application_id"
            value="<?= (int) $draft['id'] ?>"
        >

        <input
            type="hidden"
            name="application_reference"
            value="<?= e(
                (string)
                $draft['application_reference']
            ) ?>"
        >

        <button
            type="submit"
            class="btn btn-danger btn-sm"
        >
            Delete
        </button>

    </form>

</div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>


            <!-- ==================================================
                 PAGINATION
                 ================================================== -->

            <?php if ($totalPages > 1): ?>

                <div
                    style="
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        gap: 15px;
                        margin-top: 22px;
                    "
                >

                    <span
                        style="
                            color: var(--text-muted);
                            font-size: 11px;
                        "
                    >
                        Page
                        <?= $page ?>
                        of
                        <?= $totalPages ?>
                    </span>


                    <div                    <div
                        style="
                            display: flex;
                            gap: 8px;
                        "
                    >

                        <?php if ($page > 1): ?>

                            <a
                                href="/pbo-portal/applications/drafts.php?<?= http_build_query(
                                    [
                                        'q' => $search,
                                        'page' => $page - 1,
                                    ]
                                ) ?>"
                                class="btn btn-secondary btn-sm"
                            >
                                &larr; Previous
                            </a>

                        <?php endif; ?>


                        <?php if (
                            $page < $totalPages
                        ): ?>

                            <a
                                href="/pbo-portal/applications/drafts.php?<?= http_build_query(
                                    [
                                        'q' => $search,
                                        'page' => $page + 1,
                                    ]
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

</div>


<script>
(function () {

    'use strict';


    const deleteForms =
        document.querySelectorAll(
            '.delete-draft-form'
        );


    deleteForms.forEach(
        function (form) {

            form.addEventListener(
                'submit',
                function (event) {

                    const referenceInput =
                        form.querySelector(
                            'input[name="application_reference"]'
                        );


                    const reference =
                        referenceInput
                            ? referenceInput.value
                            : 'this application';


                    const confirmed =
                        window.confirm(
                            'Delete draft ' +
                            reference +
                            '?\n\n' +
                            'This will permanently remove the draft and its saved application information.\n\n' +
                            'This action cannot be undone.'
                        );


                    if (!confirmed) {

                        event.preventDefault();

                        return;
                    }


                    const button =
                        form.querySelector(
                            'button[type="submit"]'
                        );


                    if (button) {

                        button.disabled = true;

                        button.textContent =
                            'Deleting...';
                    }

                }
            );

        }
    );

})();
</script>

<?php
/*
|--------------------------------------------------------------------------
| Shared footer
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/footer.php';

?>