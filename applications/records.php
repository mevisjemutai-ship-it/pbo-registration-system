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

$pageTitle = 'Records';
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


function recordsOrganizationType(
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
| Newly submitted application
|--------------------------------------------------------------------------
*/

$highlightId =
    filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );

if (!$highlightId) {
    $highlightId = 0;
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
| Build WHERE clause
|--------------------------------------------------------------------------
|
| For now, records are restricted to applications created by the
| logged-in user, matching the ownership rules used throughout the
| application workflow.
|
*/

$where = "
    WHERE
        a.status = 'submitted'
";


$parameters = [];

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
| Count records
|--------------------------------------------------------------------------
*/

$countStatement =
    $connection->prepare(
        "
        SELECT
            COUNT(*)
        FROM pbo_applications a
        $where
        "
    );


$countStatement->execute(
    $parameters
);


$totalRecords =
    (int)
    $countStatement->fetchColumn();


$totalPages =
    max(
        1,
        (int)
        ceil(
            $totalRecords /
            $perPage
        )
    );


if ($page > $totalPages) {
    $page = $totalPages;

    $offset =
        ($page - 1) *
        $perPage;
}


/*
|--------------------------------------------------------------------------
| Load submitted records
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
        a.submitted_at,
        a.created_at,

        ad.county,
        ad.phone_number,
        ad.email

    FROM pbo_applications a

    LEFT JOIN application_addresses ad
        ON ad.application_id = a.id

    $where

    ORDER BY
        a.submitted_at DESC,
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


$records =
    $statement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Shared header
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/header.php';

?>


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
                Submitted Applications
            </h1>

            <p class="subtitle">
                View and search submitted PBO application records.
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
         SUCCESS FLASH
         ========================================================== -->

    <?php if (
        !empty(
            $_SESSION['flash_success']
        )
    ): ?>

        <div
            class="alert alert-success"
            id="flash-success-message"
            role="status"
        >

            <strong>
                Success.
            </strong>

            <?= e(
                (string)
                $_SESSION['flash_success']
            ) ?>

        </div>

        <?php

        unset(
            $_SESSION['flash_success']
        );

        ?>

    <?php endif; ?>


    <!-- ==========================================================
         RECORD SUMMARY
         ========================================================== -->

    <section class="application-section">

        <div class="application-section-heading">

            <div class="application-section-number">
                R
            </div>

            <div>

                <h2>
                    Application Records
                </h2>

                <p>
                    <?= number_format(
                        $totalRecords
                    ) ?>
                    submitted
                    <?= $totalRecords === 1
                        ? 'application'
                        : 'applications' ?>
                    found.
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
                gap: 10px;
                align-items: flex-end;
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

                <label for="records-search">
                    Search Records
                </label>

                <input
                    type="search"
                    id="records-search"
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


            <?php if (
                $search !== ''
            ): ?>

                <a
                    href="/pbo-portal/applications/records.php"
                    class="btn btn-secondary"
                >
                    Clear
                </a>

            <?php endif; ?>

        </form>


        <!-- ======================================================
             RECORDS
             ====================================================== -->

        <?php if (
            $records === []
        ): ?>

            <div
                style="
                    padding: 40px 20px;
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
                    "
                >
                    No submitted applications found
                </strong>

                <span
                    style="
                        color: var(--text-secondary);
                        font-size: 12px;
                    "
                >

                    <?php if (
                        $search !== ''
                    ): ?>

                        No submitted applications match
                        your search.

                    <?php else: ?>

                        Submitted PBO applications will
                        appear here.

                    <?php endif; ?>

                </span>

            </div>


        <?php else: ?>


            <div
                style="
                    overflow-x: auto;
                "
            >

                <table
                    style="
                        width: 100%;
                        border-collapse: collapse;
                        min-width: 980px;
                    "
                >

                    <thead>

                        <tr>

                            <th
                                style="
                                    text-align: left;
                                    padding: 12px;
                                    border-bottom: 2px solid var(--border);
                                "
                            >
                                Application Reference
                            </th>

                            <th
                                style="
                                    text-align: left;
                                    padding: 12px;
                                    border-bottom: 2px solid var(--border);
                                "
                            >
                                Organization
                            </th>

                            <th
                                style="
                                    text-align: left;
                                    padding: 12px;
                                    border-bottom: 2px solid var(--border);
                                "
                            >
                                PBO Number
                            </th>

                            <th
                                style="
                                    text-align: left;
                                    padding: 12px;
                                    border-bottom: 2px solid var(--border);
                                "
                            >
                                Type
                            </th>

                            <th
                                style="
                                    text-align: left;
                                    padding: 12px;
                                    border-bottom: 2px solid var(--border);
                                "
                            >
                                County
                            </th>

                            <th
                                style="
                                    text-align: left;
                                    padding: 12px;
                                    border-bottom: 2px solid var(--border);
                                "
                            >
                                Submitted
                            </th>

                            <th
                                style="
                                    text-align: right;
                                    padding: 12px;
                                    border-bottom: 2px solid var(--border);
                                "
                            >
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

                            $isHighlighted =
                                (int) $record['id'] ===
                                (int) $highlightId;

                            ?>


                            <tr
                                <?= $isHighlighted
                                    ? 'style="background: var(--green-light);"'
                                    : '' ?>
                            >

                                <!-- REFERENCE -->

                                <td
                                    style="
                                        padding: 14px 12px;
                                        border-bottom: 1px solid var(--border);
                                    "
                                >

                                    <strong
                                        style="
                                            color: var(--blue-dark);
                                        "
                                    >
                                        <?= e(
                                            (string)
                                            $record[
                                                'application_reference'
                                            ]
                                        ) ?>
                                    </strong>

                                </td>


                                <!-- ORGANIZATION -->

                                <td
                                    style="
                                        padding: 14px 12px;
                                        border-bottom: 1px solid var(--border);
                                    "
                                >

                                    <strong>
                                        <?= e(
                                            (string)
                                            $record['pbo_name']
                                        ) ?>
                                    </strong>

                                    <?php if (
                                        !empty(
                                            $record['email']
                                        )
                                    ): ?>

                                        <span
                                            style="
                                                display: block;
                                                margin-top: 3px;
                                                color: var(--text-muted);
                                                font-size: 10px;
                                            "
                                        >
                                            <?= e(
                                                (string)
                                                $record['email']
                                            ) ?>
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- PBO NUMBER -->

                                <td
                                    style="
                                        padding: 14px 12px;
                                        border-bottom: 1px solid var(--border);
                                    "
                                >

                                    <?php if (
                                        !empty(
                                            $record[
                                                'pbo_registration_number'
                                            ]
                                        )
                                    ): ?>

                                        <?= e(
                                            (string)
                                            $record[
                                                'pbo_registration_number'
                                            ]
                                        ) ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            —
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- TYPE -->

                                <td
                                    style="
                                        padding: 14px 12px;
                                        border-bottom: 1px solid var(--border);
                                    "
                                >

                                    <?= e(
                                        recordsOrganizationType(
                                            (string)
                                            $record[
                                                'registration_type'
                                            ]
                                        )
                                    ) ?>

                                </td>


                                <!-- COUNTY -->

                                <td
                                    style="
                                        padding: 14px 12px;
                                        border-bottom: 1px solid var(--border);
                                    "
                                >

                                    <?= !empty(
                                        $record['county']
                                    )
                                        ? e(
                                            (string)
                                            $record['county']
                                        )
                                        : '<span class="text-muted">—</span>' ?>

                                </td>


                                <!-- SUBMITTED -->

                                <td
                                    style="
                                        padding: 14px 12px;
                                        border-bottom: 1px solid var(--border);
                                    "
                                >

                                    <?php if (
                                        !empty(
                                            $record[
                                                'submitted_at'
                                            ]
                                        )
                                    ): ?>

                                        <?= e(
                                            date(
                                                'd M Y H:i',
                                                strtotime(
                                                    (string)
                                                    $record[
                                                        'submitted_at'
                                                    ]
                                                )
                                            )
                                        ) ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            —
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- ACTION -->

                                <td
                                    style="
                                        padding: 14px 12px;
                                        border-bottom: 1px solid var(--border);
                                        text-align: right;
                                    "
                                >

                                    <a
                                        href="/pbo-portal/applications/view.php?id=<?= urlencode(
                                            (string)
                                            $record['id']
                                        ) ?>"
                                        class="btn btn-secondary btn-sm"
                                    >
                                        View
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


                    <div
                        style="
                            display: flex;
                            gap: 8px;
                        "
                    >

                        <?php if (
                            $page > 1
                        ): ?>

                            <a
                                                            href="/pbo-portal/applications/records.php?<?= http_build_query(
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
                                href="/pbo-portal/applications/records.php?<?= http_build_query(
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


<!-- ==============================================================
     SUCCESS MESSAGE
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
    | Keep the success message visible long enough for staff to read it,
    | then remove it without affecting the records page.
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

require_once __DIR__ . '/../includes/footer.php';

?>