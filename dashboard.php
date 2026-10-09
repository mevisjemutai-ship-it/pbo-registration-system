<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();
requirePasswordChanged();


/*
|--------------------------------------------------------------------------
| Page configuration
|--------------------------------------------------------------------------
*/

$pageTitle = 'Home';
$activePage = 'home';


/*
|--------------------------------------------------------------------------
| Current user
|--------------------------------------------------------------------------
*/

$userId =
    (int) ($_SESSION['user_id'] ?? 0);

$userName =
    currentUserName();


if ($userId <= 0) {

    header(
        'Location: /pbo-portal/login.php'
    );

    exit;
}


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
| Helpers
|--------------------------------------------------------------------------
*/

function dashboardE(
    ?string $value
): string {

    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


function dashboardOrganizationType(
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
| Dashboard statistics
|--------------------------------------------------------------------------
*/

$statement =
    $connection->prepare(
        "
        SELECT

            COUNT(*) AS total_applications,

            SUM(
                CASE
                    WHEN status = 'draft'
                    THEN 1
                    ELSE 0
                END
            ) AS draft_applications,

            SUM(
                CASE
                    WHEN status = 'submitted'
                    THEN 1
                    ELSE 0
                END
            ) AS submitted_applications,

            SUM(
                CASE
                    WHEN status = 'submitted'
                     AND YEAR(submitted_at) = YEAR(CURRENT_DATE)
                    THEN 1
                    ELSE 0
                END
            ) AS submitted_this_year

        FROM pbo_applications

        WHERE
    status = 'submitted'
    OR
    (
        status = 'draft'
        AND created_by = :user_id
    )
        "
    );


$statement->execute([
    ':user_id'
        => $userId,
]);


$statistics =
    $statement->fetch(
        PDO::FETCH_ASSOC
    )
    ?: [];


$totalApplications =
    (int)
    ($statistics[
        'total_applications'
    ] ?? 0);


$draftApplications =
    (int)
    ($statistics[
        'draft_applications'
    ] ?? 0);


$submittedApplications =
    (int)
    ($statistics[
        'submitted_applications'
    ] ?? 0);


$submittedThisYear =
    (int)
    ($statistics[
        'submitted_this_year'
    ] ?? 0);


/*
|--------------------------------------------------------------------------
| Recent applications
|--------------------------------------------------------------------------
*/
$statement =
    $connection->prepare(
        "
        SELECT

            SUM(
                CASE
                    WHEN status = 'draft'
                     AND created_by = :user_id
                    THEN 1
                    ELSE 0
                END
            ) AS my_drafts,

            SUM(
                CASE
                    WHEN status = 'submitted'
                    THEN 1
                    ELSE 0
                END
            ) AS submitted_records,

            SUM(
                CASE
                    WHEN status = 'submitted'
                     AND YEAR(submitted_at) = YEAR(CURRENT_DATE)
                    THEN 1
                    ELSE 0
                END
            ) AS submitted_this_year

        FROM pbo_applications
        "
    );


$statement->execute([
    ':user_id'
        => $userId,
]);


$statistics =
    $statement->fetch(
        PDO::FETCH_ASSOC
    )
    ?: [];


$draftApplications =
    (int)
    ($statistics['my_drafts'] ?? 0);


$submittedApplications =
    (int)
    ($statistics[
        'submitted_records'
    ] ?? 0);


$submittedThisYear =
    (int)
    ($statistics[
        'submitted_this_year'
    ] ?? 0);


$totalApplications =
    $submittedApplications;
/*
|--------------------------------------------------------------------------
| Success flash
|--------------------------------------------------------------------------
*/

$successMessage =
    trim(
        (string)
        ($_SESSION['flash_success'] ?? '')
    );


unset(
    $_SESSION['flash_success']
);


/*
|--------------------------------------------------------------------------
| Load shared navigation/header
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| Recent Applications
|--------------------------------------------------------------------------
|
| Submitted applications are visible to all authenticated staff.
|
| Draft applications are private to the staff member who created them.
|
| The most recently submitted/updated records appear first.
|
*/

$statement =
    $connection->prepare(
        "
        SELECT
            id,
            application_reference,
            pbo_name,
            registration_type,
            status,
            current_step,
            created_by,
            created_at,
            updated_at,
            submitted_at

        FROM pbo_applications

        WHERE
            status = 'submitted'

            OR

            (
                status = 'draft'
                AND created_by = :user_id
            )

        ORDER BY
            CASE
                WHEN status = 'submitted'
                    THEN COALESCE(
                        submitted_at,
                        updated_at,
                        created_at
                    )

                ELSE COALESCE(
                    updated_at,
                    created_at
                )
            END DESC,
            id DESC

        LIMIT 6
        "
    );


$statement->execute([

    ':user_id'
        => $userId,
]);


$recentApplications =
    $statement->fetchAll(
        PDO::FETCH_ASSOC
    );
require_once __DIR__ . '/includes/header.php';

?>


<style>

/* ==============================================================
   DASHBOARD — OPERATIONAL SUMMARY
   ============================================================== */

.dashboard-overview-grid {
    display: grid;

    grid-template-columns:
        repeat(
            4,
            minmax(0, 1fr)
        );

    gap: 14px;

    margin-bottom: 25px;
}


.dashboard-stat-card {
    position: relative;

    overflow: hidden;

    min-height: 115px;

    padding: 19px 20px;

    background: #ffffff;

    border: 1px solid var(--border);
    border-radius: var(--radius);

    box-shadow: var(--shadow-sm);
}


.dashboard-stat-card::before {
    content: '';

    position: absolute;

    top: 0;
    left: 0;

    width: 4px;
    height: 100%;

    background: var(--blue);
}


.dashboard-stat-card-gold::before {
    background: var(--gold);
}


.dashboard-stat-card-green::before {
    background: var(--green);
}


.dashboard-stat-card-navy::before {
    background: var(--blue-dark);
}


.dashboard-stat-label {
    display: block;

    margin-bottom: 9px;

    color: var(--text-muted);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: .7px;

    text-transform: uppercase;
}


.dashboard-stat-value {
    display: block;

    margin-bottom: 5px;

    color: var(--blue-dark);

    font-size: 27px;
    font-weight: 800;

    line-height: 1;
}


.dashboard-stat-description {
    color: var(--text-secondary);

    font-size: 10px;
}


/* ==============================================================
   QUICK ACTIONS ENHANCEMENT
   ============================================================== */

.quick-card {
    position: relative;
}


.quick-card-count {
    position: absolute;

    top: 12px;
    right: 12px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-width: 25px;
    height: 25px;

    padding: 0 7px;

    background: rgba(255, 255, 255, .92);

    border-radius: 20px;

    color: var(--blue-dark);

    font-size: 10px;
    font-weight: 800;

    box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
}


/* ==============================================================
   RECENT APPLICATIONS
   ============================================================== */

.dashboard-recent {
    margin-top: 25px;
}


.dashboard-recent-list {
    overflow: hidden;

    background: #ffffff;

    border: 1px solid var(--border);
    border-radius: var(--radius);

    box-shadow: var(--shadow-sm);
}


.dashboard-recent-item {
    display: grid;

    grid-template-columns:
        minmax(150px, .8fr)
        minmax(240px, 1.8fr)
        minmax(130px, .8fr)
        minmax(120px, .7fr)
        auto;

    align-items: center;

    gap: 15px;

    padding: 14px 17px;

    border-bottom: 1px solid var(--border);
}


.dashboard-recent-item:last-child {
    border-bottom: 0;
}


.dashboard-recent-item:hover {
    background: var(--soft-bg);
}


.dashboard-recent-reference {
    color: var(--blue-dark);

    font-size: 11px;
    font-weight: 800;
}


.dashboard-recent-name {
    color: var(--heading);

    font-size: 12px;
    font-weight: 700;
}


.dashboard-recent-name span {
    display: block;

    margin-top: 2px;

    color: var(--text-muted);

    font-size: 9px;
    font-weight: 500;
}


.dashboard-status {
    display: inline-flex;
    align-items: center;

    width: fit-content;

    padding: 5px 9px;

    border-radius: 20px;

    font-size: 9px;
    font-weight: 800;

    text-transform: uppercase;
}


.dashboard-status-draft {
    background: var(--gold-light);

    color: #755c12;
}


.dashboard-status-submitted {
    background: var(--green-light);

    color: var(--green-dark);
}


.dashboard-recent-date {
    color: var(--text-muted);

    font-size: 10px;
}


.dashboard-empty {
    padding: 32px 20px;

    text-align: center;

    color: var(--text-muted);

    font-size: 11px;
}


/* ==============================================================
   RESPONSIVE
   ============================================================== */

@media (max-width: 1050px) {

    .dashboard-overview-grid {
        grid-template-columns:
            repeat(2, 1fr);
    }


    .dashboard-recent-item {
        grid-template-columns:
            1fr 1.5fr auto;
    }


    .dashboard-recent-type,
    .dashboard-recent-date {
        display: none;
    }

}


@media (max-width: 650px) {

    .dashboard-overview-grid {
        grid-template-columns: 1fr;
    }


    .dashboard-recent-item {
        grid-template-columns: 1fr;

        gap: 7px;
    }

}

</style>


<div class="dashboard-page">


    <!-- ==========================================================
         FLASH MESSAGE
         ========================================================== -->

    <?php if ($successMessage !== ''): ?>

        <div
            class="alert alert-success"
            id="flash-success-message"
            role="status"
            style="margin-bottom: 20px;"
        >

            <strong>
                Success.
            </strong>

            <?= dashboardE(
                $successMessage
            ) ?>

        </div>

    <?php endif; ?>


    <!-- ==========================================================
         WELCOME
         ========================================================== -->

    <section class="dashboard-welcome">

        <div>

            <span class="dashboard-eyebrow">
                PBO REGISTRATION SYSTEM
            </span>

            <h1>
                Welcome,
                <?= dashboardE(
                    $userName
                ) ?>
            </h1>

            <p>
                Registration and Records Management
            </p>

        </div>

    </section>


    <!-- ==========================================================
         OVERVIEW
         ========================================================== -->

    <section class="dashboard-section">

        <div class="dashboard-section-heading">

            <div>

                <span class="section-eyebrow">
                    Overview
                </span>

                <h2>
                    Application Summary
                </h2>

            </div>

        </div>


        <div class="dashboard-overview-grid">


            <!-- TOTAL -->

            <div class="dashboard-stat-card">

                <span class="dashboard-stat-label">
                    Total Applications
                </span>

                <strong class="dashboard-stat-value">
                    <?= number_format(
                        $totalApplications
                    ) ?>
                </strong>

                <span class="dashboard-stat-description">
                    Applications recorded by you
                </span>

            </div>


            <!-- DRAFTS -->

            <div
                class="
                    dashboard-stat-card
                    dashboard-stat-card-gold
                "
            >

                <span class="dashboard-stat-label">
                    Draft Applications
                </span>

                <strong class="dashboard-stat-value">
                    <?= number_format(
                        $draftApplications
                    ) ?>
                </strong>

                <span class="dashboard-stat-description">
                    Saved and awaiting completion
                </span>

            </div>


            <!-- SUBMITTED -->

            <div
                class="
                    dashboard-stat-card
                    dashboard-stat-card-green
                "
            >

                <span class="dashboard-stat-label">
                    Submitted
                </span>

                <strong class="dashboard-stat-value">
                    <?= number_format(
                        $submittedApplications
                    ) ?>
                </strong>

                <span class="dashboard-stat-description">
                    Completed application records
                </span>

            </div>


            <!-- THIS YEAR -->

            <div
                class="
                    dashboard-stat-card
                    dashboard-stat-card-navy
                "
            >

                <span class="dashboard-stat-label">
                    Submitted This Year
                </span>

                <strong class="dashboard-stat-value">
                    <?= number_format(
                        $submittedThisYear
                    ) ?>
                </strong>

                <span class="dashboard-stat-description">
                    <?= date('Y') ?>
                    submissions
                </span>

            </div>

        </div>

    </section>


    <!-- ==========================================================
         QUICK ACTIONS
         ========================================================== -->

    <section class="dashboard-section">

        <div class="dashboard-section-heading">

            <div>

                <span class="section-eyebrow">
                    Workspace
                </span>

                <h2>
                    Quick Actions
                </h2>

            </div>

        </div>


        <div class="quick-grid">


            <!-- NEW APPLICATION -->

            <a
                href="/pbo-portal/applications/create.php"
                class="quick-card quick-card-blue"
            >

                <div class="quick-card-icon">
                    +
                </div>


                <div>

                    <strong>
                        New Application
                    </strong>

                    <span>
                        Record a new PBO application
                    </span>

                </div>

            </a>


            <!-- DRAFTS -->

            <a
                href="/pbo-portal/applications/drafts.php"
                class="quick-card quick-card-gold"
            >

                <span class="quick-card-count">
                    <?= number_format(
                        $draftApplications
                    ) ?>
                </span>


                <div class="quick-card-icon">
                    D
                </div>


                <div>

                    <strong>
                        Draft Applications
                    </strong>

                    <span>
                        Continue incomplete applications
                    </span>

                </div>


            <!-- RECORDS -->

            <a
                href="/pbo-portal/applications/records.php"
                class="quick-card quick-card-navy"
            >

                <span class="quick-card-count">
                    <?= number_format(
                        $submittedApplications
                    ) ?>
                </span>


                <div class="quick-card-icon">
                    R
                </div>


                <div>

                    <strong>
                        Records
                    </strong>

                    <span>
                        View submitted applications
                    </span>

                </div>

            </a>

        </div>

    </section>


    <!-- ==========================================================
         RECENT APPLICATIONS
         ========================================================== -->

    <section
        class="
            dashboard-section
            dashboard-recent
        "
    >

        <div class="dashboard-section-heading">

            <div>

                <span class="section-eyebrow">
                    Recent Activity
                </span>

                <h2>
                    Recent Applications
                </h2>

            </div>



        <div class="dashboard-recent-list">


            <?php if (
                $recentApplications === []
            ): ?>

                <div class="dashboard-empty">

                    No applications have been recorded yet.

                </div>


            <?php else: ?>


                <?php foreach (
                    $recentApplications
                    as $recent
                ): ?>

                    <?php

                    $isSubmitted =
                        (string)
                        $recent['status'] ===
                        'submitted';


                    /*
                    |--------------------------------------------------------------------------
                    | Destination
                    |--------------------------------------------------------------------------
                    */

                    if ($isSubmitted) {

                        $recentUrl =
                            '/pbo-portal/applications/view.php?id=' .
                            urlencode(
                                (string)
                                $recent['id']
                            );


                        $recentAction =
                            'View';

                    } else {

                        $currentStep =
                            (int)
                            (
                                $recent[
                                    'current_step'
                                ]
                                ?? 1
                            );


                        $resumePage =
                            match (true) {

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


                        $recentUrl =
                            '/pbo-portal/applications/' .
                            $resumePage .
                            '?id=' .
                            urlencode(
                                (string)
                                $recent['id']
                            );


                        $recentAction =
                            'Continue';
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Date
                    |--------------------------------------------------------------------------
                    */

                    $recentDate =
                        $isSubmitted
                            ? (
                                $recent[
                                    'submitted_at'
                                ]
                                ?? ''
                            )
                            : (
                                $recent[
                                    'updated_at'
                                ]
                                ??
                                $recent[
                                    'created_at'
                                ]
                                ??
                                ''
                            );


                    $recentTimestamp =
                        $recentDate !== ''
                            ? strtotime(
                                (string)
                                $recentDate
                            )
                            : false;


                    $recentDateDisplay =
                        $recentTimestamp !== false
                            ? date(
                                'd M Y, H:i',
                                $recentTimestamp
                            )
                            : '—';

                    ?>


                    <div class="dashboard-recent-item">


                        <!-- REFERENCE -->

                        <div>

                            <span class="dashboard-recent-reference">

                                <?= dashboardE(
                                    (string)
                                    $recent[
                                        'application_reference'
                                    ]
                                ) ?>

                            </span>

                        </div>


                        <!-- ORGANIZATION -->

                        <div class="dashboard-recent-name">

                            <?= dashboardE(
                                (string)
                                $recent[
                                    'pbo_name'
                                ]
                            ) ?>


                            <span>
                                <?= dashboardE(
                                    dashboardOrganizationType(
                                        (string)
                                        $recent[
                                            'registration_type'
                                        ]
                                    )
                                ) ?>
                            </span>

                        </div>


                        <!-- STATUS -->

                        <div>

                            <?php if (
                                $isSubmitted
                            ): ?>

                                <span
                                    class="
                                        dashboard-status
                                        dashboard-status-submitted                                    "
                                >
                                    Submitted
                                </span>

                            <?php else: ?>

                                <span
                                    class="
                                        dashboard-status
                                        dashboard-status-draft
                                    "
                                >
                                    Draft
                                </span>

                            <?php endif; ?>

                        </div>


                        <!-- DATE -->

                        <div class="dashboard-recent-date">

                            <?= dashboardE(
                                $recentDateDisplay
                            ) ?>

                        </div>


                        <!-- ACTION -->

                        <div
                            style="
                                text-align: right;
                            "
                        >

                            <a
                                href="<?= dashboardE(
                                    $recentUrl
                                ) ?>"
                                class="<?= $isSubmitted
                                    ? 'btn btn-secondary btn-sm'
                                    : 'btn btn-primary btn-sm' ?>"
                            >
                                <?= dashboardE(
                                    $recentAction
                                ) ?>

                                <?php if (
                                    !$isSubmitted
                                ): ?>

                                    &rarr;

                                <?php endif; ?>

                            </a>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </section>


</div>


<!-- ==============================================================
     FLASH MESSAGE
     ============================================================== -->

<script>
(function () {

    'use strict';


    const message =
        document.getElementById(
            'flash-success-message'
        );


    if (!message) {
        return;
    }


    /*
    |--------------------------------------------------------------------------
    | Automatically hide successful operation message
    |--------------------------------------------------------------------------
    */

    window.setTimeout(
        function () {

            message.style.transition =
                'opacity 0.35s ease';

            message.style.opacity =
                '0';


            window.setTimeout(
                function () {

                    message.remove();

                },
                350
            );

        },
        6000
    );

})();
</script>


<?php

/*
|--------------------------------------------------------------------------
| Shared footer
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/includes/footer.php';

?>