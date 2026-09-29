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

$pageTitle = 'Financial Year';
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

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: /pbo-portal/login.php');
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


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['csrf_token'];


/*
|--------------------------------------------------------------------------
| Application ID
|--------------------------------------------------------------------------
*/

$applicationId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$applicationId) {

    $applicationId = filter_input(
        INPUT_POST,
        'application_id',
        FILTER_VALIDATE_INT
    );
}

if (!$applicationId) {

    header(
        'Location: /pbo-portal/applications/drafts.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Load application
|--------------------------------------------------------------------------
*/

$statement = $connection->prepare(
    "
    SELECT
        id,
        application_reference,
        pbo_name,
        pbo_registration_number,
        registration_type,
        status,
        current_step,
        financial_year_start_month,
        financial_year_end_month,
        created_by
    FROM pbo_applications
    WHERE id = :application_id
      AND created_by = :user_id
    LIMIT 1
    "
);

$statement->execute([
    ':application_id' => $applicationId,
    ':user_id' => $userId,
]);

$application = $statement->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    http_response_code(404);
    exit('Application not found.');
}


/*
|--------------------------------------------------------------------------
| Prevent editing submitted applications
|--------------------------------------------------------------------------
*/

if ($application['status'] !== 'draft') {

    header(
        'Location: /pbo-portal/applications/records.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Months
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
| Existing values
|--------------------------------------------------------------------------
*/

$startMonth =
    $application['financial_year_start_month'] !== null
        ? (int) $application['financial_year_start_month']
        : 0;

$endMonth =
    $application['financial_year_end_month'] !== null
        ? (int) $application['financial_year_end_month']
        : 0;


/*
|--------------------------------------------------------------------------
| Errors
|--------------------------------------------------------------------------
*/

$errors = [];


/*
|--------------------------------------------------------------------------
| Process submission
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $submittedToken =
        (string) ($_POST['csrf_token'] ?? '');

    if (
        $submittedToken === '' ||
        !hash_equals($csrfToken, $submittedToken)
    ) {
        $errors[] =
            'Your session token is invalid or has expired. Please reload the page and try again.';
    }


    /*
    |--------------------------------------------------------------------------
    | Staff-entered months
    |--------------------------------------------------------------------------
    */

    $startMonth = isset($_POST['financial_year_start_month'])
        ? (int) $_POST['financial_year_start_month']
        : 0;

    $endMonth = isset($_POST['financial_year_end_month'])
        ? (int) $_POST['financial_year_end_month']
        : 0;


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | Staff retain control over both values.
    |
    | We therefore verify only that valid months have been selected.
    | We do NOT replace or reject the end month based on an automatic
    | calculation.
    |
    */

    if (!isset($months[$startMonth])) {
        $errors[] =
            'Please select the start month of the financial year.';
    }

    if (!isset($months[$endMonth])) {
        $errors[] =
            'Please select the end month of the financial year.';
    }


    /*
    |--------------------------------------------------------------------------
    | Save
    |--------------------------------------------------------------------------
    */

    if ($errors === []) {

        try {

            $connection->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Update application
            |--------------------------------------------------------------------------
            */

            $statement = $connection->prepare(
                "
                UPDATE pbo_applications
                SET
                    financial_year_start_month = :start_month,
                    financial_year_end_month = :end_month,

                    current_step =
                        CASE
                            WHEN current_step < 5 THEN 5
                            ELSE current_step
                        END,

                    updated_by = :updated_by

                WHERE id = :application_id
                "
            );

            $statement->execute([
                ':start_month' => $startMonth,
                ':end_month' => $endMonth,
                ':updated_by' => $userId,
                ':application_id' => $applicationId,
            ]);


            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            $statement = $connection->prepare(
                "
                INSERT INTO application_audit_log
                (
                    application_id,
                    user_id,
                    action,
                    description
                )
                VALUES
                (
                    :application_id,
                    :user_id,
                    'FINANCIAL_YEAR_SAVED',
                    :description
                )
                "
            );

            $statement->execute([
                ':application_id' => $applicationId,
                ':user_id' => $userId,
                ':description' =>
                    'Financial year recorded as ' .
                    $months[$startMonth] .
                    ' to ' .
                    $months[$endMonth] .
                    '.',
            ]);


            $connection->commit();


            /*
            |--------------------------------------------------------------------------
            | Continue to officials
            |--------------------------------------------------------------------------
            */

            header(
                'Location: /pbo-portal/applications/officials.php?id=' .
                urlencode((string) $applicationId)
            );

            exit;

        } catch (Throwable $exception) {

            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            error_log(
                'Saving financial year failed: ' .
                $exception->getMessage()
            );

            $errors[] =
                'The financial year could not be saved. No changes were committed.';
        }
    }
}


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
                PBO REGISTRATION
            </span>

            <h1>
                Financial Year
            </h1>

            <p class="subtitle">
                Step 4 of 6 — Enter the start and end of the
                organization's financial year.
            </p>

        </div>

    </section>


    <!-- ==========================================================
         APPLICATION SUMMARY
         ========================================================== -->

    <div class="admin-info">

        <strong>
            <?= e((string) $application['pbo_name']) ?>
        </strong>

        <?php if (!empty($application['application_reference'])): ?>

            &nbsp;&mdash;&nbsp;

            Application Reference:

            <strong>
                <?= e(
                    (string)
                    $application['application_reference']
                ) ?>
            </strong>

        <?php endif; ?>

    </div>


    <!-- ==========================================================
         PROGRESS
         ========================================================== -->

    <div
        style="
            margin-bottom: 22px;
            padding: 16px 18px;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
        "
    >

        <div
            style="
                display: flex;
                justify-content: space-between;
                gap: 10px;
                margin-bottom: 10px;
                color: var(--text-secondary);
                font-size: 11px;
                font-weight: 700;
            "
        >

            <span>1. Organization</span>
            <span>2. Objectives</span>
            <span>3. Beneficiaries</span>

            <span style="color: var(--blue);">
                4. Financial Year
            </span>

            <span>5. Officials</span>
            <span>6. Review</span>

        </div>


        <div
            style="
                width: 100%;
                height: 7px;
                overflow: hidden;
                background: #e9edf1;
                border-radius: 20px;
            "
        >

            <div
                style="
                    width: 66.6667%;
                    height: 100%;
                    background:
                        linear-gradient(
                            90deg,
                            var(--blue),
                            var(--blue-medium)
                        );
                "
            ></div>

        </div>

    </div>


    <!-- ==========================================================
         ERRORS
         ========================================================== -->

    <?php if ($errors !== []): ?>

        <div class="alert alert-error">

            <strong>
                Please correct the following:
            </strong>

            <ul style="margin: 8px 0 0; padding-left: 20px;">

                <?php foreach ($errors as $error): ?>

                    <li>
                        <?= e($error) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>


    <!-- ==========================================================
         FINANCIAL YEAR FORM
         ========================================================== -->

    <form
        method="post"
        action=""
        class="application-form"
        id="financial-year-form"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >

        <input
            type="hidden"
            name="application_id"
            value="<?= (int) $applicationId ?>"
        >


        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    4
                </div>

                <div>

                    <h2>
                        Financial Year
                    </h2>

                    <p>
                        Indicate the start and end of the organization's
                        financial year.
                    </p>

                </div>

            </div>


            <div class="admin-info">

                Select the appropriate start and end months.

                Typical financial years may run from
                <strong>January to December</strong>,
                <strong>February to January</strong>, or
                <strong>March to February</strong>.

        

            </div>


            <!-- ==================================================
                 CALENDAR-LIKE MONTH SELECTION
                 ================================================== -->

            <div
                style="
                    display: grid;
                    grid-template-columns:
                        repeat(auto-fit, minmax(310px, 1fr));
                    gap: 22px;
                "
            >


                <!-- ==============================================
                     START MONTH
                     ============================================== -->

                <div
                    style="
                        padding: 20px;
                        background: var(--soft-bg);
                        border: 1px solid var(--border);
                        border-radius: var(--radius);
                    "
                >

                    <span class="section-eyebrow">
                        Financial Year
                    </span>

                    <h3
                        style="
                            margin: 0 0 5px;
                            color: var(--blue-dark);
                        "
                    >
                        Start Month
                    </h3>

                    <p
                        style="
                            margin: 0 0 17px;
                            color: var(--text-secondary);
                            font-size: 12px;
                        "
                    >
                        Select the month in which the financial year begins.
                    </p>


                    <div
                        id="start-month-calendar"
                        style="
                            display: grid;
                            grid-template-columns:
                                repeat(3, minmax(0, 1fr));
                            gap: 8px;
                        "
                    >

                        <?php foreach ($months as $number => $name): ?>

                            <label
                                class="radio-option"
                                style="
                                    justify-content: center;
                                    text-align: center;
                                "
                            >

                                <input
                                    type="radio"
                                    name="financial_year_start_month"
                                    value="<?= $number ?>"
                                    <?= $startMonth === $number
                                        ? 'checked'
                                        : '' ?>
                                    required
                                >

                                <span>
                                    <?= e(
                                        substr($name, 0, 3)
                                    ) ?>
                                </span>

                            </label>

                        <?php endforeach; ?>

                    </div>

                </div>


                <!-- ==============================================
                     END MONTH
                     ============================================== -->

                <div
                    style="
                        padding: 20px;
                        background: var(--soft-bg);
                        border: 1px solid var(--border);
                        border-radius: var(--radius);
                    "
                >

                    <span class="section-eyebrow">
                        Financial Year
                    </span>

                    <h3
                        style="
                            margin: 0 0 5px;
                            color: var(--blue-dark);
                        "
                    >
                        End Month
                    </h3>

                    <p
                        style="
                            margin: 0 0 17px;
                            color: var(--text-secondary);
                            font-size: 12px;
                        "
                    >
                        Select the month in which the financial year ends.
                    </p>


                    <div
                        id="end-month-calendar"
                        style="
                            display: grid;
                            grid-template-columns:
                                repeat(3, minmax(0, 1fr));
                            gap: 8px;
                        "
                    >

                        <?php foreach ($months as $number => $name): ?>

                            <label
                                class="radio-option"
                                style="
                                    justify-content: center;
                                    text-align: center;
                                "
                            >

                                <input
                                    type="radio"
                                    name="financial_year_end_month"
                                    value="<?= $number ?>"
                                    <?= $endMonth === $number
                                        ? 'checked'
                                        : '' ?>
                                    required
                                >

                                <span>
                                    <?= e(
                                        substr($name, 0, 3)
                                    ) ?>
                                </span>

                            </label>

                        <?php endforeach; ?>

                    </div>

                </div>

            </div>


            <!-- ==================================================
                 SUGGESTION
                 ================================================== -->

            <div
                id="financial-year-suggestion"
                style="
                    display: none;
                    margin-top: 20px;
                    padding: 15px 17px;
                    background: var(--gold-light);
                    border: 1px solid #ead9a8;
                    border-left: 4px solid var(--gold);
                    border-radius: var(--radius);
                "
            >

                <span
                    style="
                        display: block;
                        margin-bottom: 4px;
                        color: var(--gold-dark);
                        font-size: 10px;
                        font-weight: 800;
                        letter-spacing: .6px;
                        text-transform: uppercase;
                    "
                >
                    Suggested End Month
                </span>

                <div
                    style="
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        flex-wrap: wrap;
                        gap: 12px;
                    "
                >

                    <div>

                        <strong
                            id="suggestion-text"
                            style="
                                color: var(--heading);
                            "
                        ></strong>

                        <div
                            style="
                                margin-top: 3px;
                                color: var(--text-secondary);
                                font-size: 11px;
                            "
                        >
                            This suggestion is optional. 
                        </div>

                    </div>


                    <button
                        type="button"
                        class="btn btn-gold btn-sm"
                        id="use-suggested-end"
                    >
                        Use Suggested Month
                    </button>

                </div>

            </div>


            <!-- ==================================================
                 SELECTED PERIOD
                 ================================================== -->

            <div
                id="financial-year-preview"
                style="
                    display: none;
                    margin-top: 20px;
                    padding: 18px 20px;
                    background:
                        linear-gradient(
                            100deg,
                            var(--blue-light),
                            #ffffff
                        );
                    border: 1px solid #d2e0ec;
                    border-left: 4px solid var(--blue);
                    border-radius: var(--radius);
                "
            >

                <span
                    style="
                        display: block;
                        margin-bottom: 5px;
                        color: var(--text-muted);
                        font-size: 10px;
                        font-weight: 800;
                        letter-spacing: .6px;
                        text-transform: uppercase;
                    "
                >
                    Selected Financial Year
                </span>

                <strong
                    id="financial-year-preview-text"
                    style="
                        color: var(--blue-dark);
                        font-size: 18px;
                    "
                ></strong>

            </div>

        </section>


        <!-- ======================================================
             ACTIONS
             ====================================================== -->

        <div class="application-actions">

            <div class="application-actions-left">

                <a
                    href="/pbo-portal/applications/beneficiaries.php?id=<?= urlencode(
                        (string) $applicationId
                    ) ?>"
                    class="btn btn-secondary"
                >
                    &larr; Back
                </a>

            </div>


            <div class="application-actions-right">

                <span
                    style="
                        color: var(--text-muted);
                        font-size: 11px;
                    "
                >
                    Step 4 of 6
                </span>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Save &amp; Continue

                    <span aria-hidden="true">
                        &rarr;
                    </span>
                </button>

            </div>

        </div>

    </form>

</div>


<!-- ==============================================================
     FINANCIAL YEAR INTERACTION
     ============================================================== -->

<script>
(function () {

    'use strict';


    /*
    |--------------------------------------------------------------------------
    | Elements
    |--------------------------------------------------------------------------
    */

    const form =
        document.getElementById(
            'financial-year-form'
        );


    const startInputs =
        document.querySelectorAll(
            'input[name="financial_year_start_month"]'
        );


    const endInputs =
        document.querySelectorAll(
            'input[name="financial_year_end_month"]'
        );


    const suggestionBox =
        document.getElementById(
            'financial-year-suggestion'
        );


    const suggestionText =
        document.getElementById(
            'suggestion-text'
        );


    const useSuggestedButton =
        document.getElementById(
            'use-suggested-end'
        );


    const preview =
        document.getElementById(
            'financial-year-preview'
        );


    const previewText =
        document.getElementById(
            'financial-year-preview-text'
        );


    /*
    |--------------------------------------------------------------------------
    | Month names
    |--------------------------------------------------------------------------
    */

    const months = {

        1: 'January',
        2: 'February',
        3: 'March',
        4: 'April',
        5: 'May',
        6: 'June',
        7: 'July',
        8: 'August',
        9: 'September',
        10: 'October',
        11: 'November',
        12: 'December'

    };


    /*
    |--------------------------------------------------------------------------
    | Suggested end month
    |--------------------------------------------------------------------------
    |
    | This is only a suggestion.
    |
    | Staff remain free to select ANY end month.
    |
    */

    let suggestedEndMonth = 0;


    /*
    |--------------------------------------------------------------------------
    | Get selected month
    |--------------------------------------------------------------------------
    */

    function getSelectedMonth(inputs) {

        for (const input of inputs) {

            if (input.checked) {

                return parseInt(
                    input.value,
                    10
                );

            }

        }

        return 0;

    }


    /*
    |--------------------------------------------------------------------------
    | Select end month programmatically
    |--------------------------------------------------------------------------
    */

    function selectEndMonth(monthNumber) {

        endInputs.forEach(
            function (input) {

                input.checked =
                    parseInt(
                        input.value,
                        10
                    ) === monthNumber;

            }
        );


        updatePreview();

    }


    /*
    |--------------------------------------------------------------------------
    | Update suggested month
    |--------------------------------------------------------------------------
    */

    function updateSuggestion() {

        const startMonth =
            getSelectedMonth(startInputs);


        if (
            !startMonth ||
            !months[startMonth]
        ) {

            suggestedEndMonth = 0;

            suggestionBox.style.display =
                'none';

            suggestionText.textContent =
                '';

            return;

        }


        /*
        | Conventional 12-month suggestion:
        |
        | January  -> December
        | February -> January
        | March    -> February
        | ...
        | December -> November
        */

        suggestedEndMonth =
            startMonth === 1
                ? 12
                : startMonth - 1;


        suggestionText.textContent =
            months[startMonth] +
            ' normally corresponds to an end month of ' +
            months[suggestedEndMonth] +
            '.';


        /*
        | Do not show the suggestion when staff have
        | already selected the suggested end month.
        */

        const selectedEnd =
            getSelectedMonth(endInputs);


        if (
            selectedEnd === suggestedEndMonth
        ) {

            suggestionBox.style.display =
                'none';

        } else {

            suggestionBox.style.display =
                '';

        }

    }


    /*
    |--------------------------------------------------------------------------
    | Update selected financial year preview
    |--------------------------------------------------------------------------
    */

    function updatePreview() {

        const startMonth =
            getSelectedMonth(startInputs);


        const endMonth =
            getSelectedMonth(endInputs);


        if (
            startMonth &&
            endMonth &&
            months[startMonth] &&
            months[endMonth]
        ) {

            previewText.textContent =
                months[startMonth] +
                ' to ' +
                months[endMonth];


            preview.style.display =
                '';

        } else {

            previewText.textContent =
                '';

            preview.style.display =
                'none';

        }


        /*
        | Refresh suggestion because staff may have
        | manually selected the suggested end month.
        */

        updateSuggestion();

    }


    /*
    |--------------------------------------------------------------------------
    | Start month listeners
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | Selecting the start month DOES NOT automatically
    | change the end month.
    |
    */

    startInputs.forEach(
        function (input) {

            input.addEventListener(
                'change',
                function () {

                    updateSuggestion();

                    updatePreview();

                }
            );

        }
    );


    /*
    |--------------------------------------------------------------------------
    | End month listeners
    |--------------------------------------------------------------------------
    |
    | Staff have full control over the end month.
    |
    */

    endInputs.forEach(
        function (input) {

            input.addEventListener(
                'change',
                function () {

                    updatePreview();

                }
            );

        }
    );


    /*
    |--------------------------------------------------------------------------
    | Use suggested end month
    |--------------------------------------------------------------------------
    |
    | The suggestion is applied ONLY when staff explicitly
    | press this button.
    |
    */

    useSuggestedButton.addEventListener(
        'click',
        function () {

            if (
                suggestedEndMonth &&
                months[suggestedEndMonth]
            ) {

                selectEndMonth(
                    suggestedEndMonth
                );

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | Initial state
    |--------------------------------------------------------------------------
    */

    updateSuggestion();

    updatePreview();


    /*
    |--------------------------------------------------------------------------
    | Submit
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        function (event) {


            /*
            | Both month selections are required.
            */

            const startMonth =
                getSelectedMonth(startInputs);


            const endMonth =
                getSelectedMonth(endInputs);


            if (
                !startMonth ||
                !endMonth
            ) {

                event.preventDefault();

                alert(
                    'Please select both the start and end of the financial year.'
                );

                return;

            }


            /*
            | Browser validation.
            */

            if (!form.checkValidity()) {

                event.preventDefault();

                form.reportValidity();

                return;

            }


            /*
            |--------------------------------------------------------------------------
            | IMPORTANT
            |--------------------------------------------------------------------------
            |
            | There is deliberately NO JavaScript rule requiring
            | the selected end month to equal the suggested month.
            |
            | Staff-entered values are authoritative.
            |
            */


            /*
            | Prevent accidental double submission.
            */

            const submitButton =
                form.querySelector(
                    'button[type="submit"]'
                );


            if (submitButton) {

                submitButton.disabled = true;

                submitButton.innerHTML =
                    'Saving...';

            }

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