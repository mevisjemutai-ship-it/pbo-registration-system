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
| Helper
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

    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    (string) $_SESSION['csrf_token'];


/*
|--------------------------------------------------------------------------
| Application ID
|--------------------------------------------------------------------------
*/

$applicationId =
    filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );

if (!$applicationId) {

    $applicationId =
        filter_input(
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

$applicationId =
    (int) $applicationId;


/*
|--------------------------------------------------------------------------
| Load application
|--------------------------------------------------------------------------
*/

$statement =
    $connection->prepare(
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

    ':application_id'
        => $applicationId,

    ':user_id'
        => $userId,
]);

$application =
    $statement->fetch(
        PDO::FETCH_ASSOC
    );

if (!$application) {

    http_response_code(404);

    exit(
        'Application not found.'
    );
}


/*
|--------------------------------------------------------------------------
| Submitted applications cannot be edited
|--------------------------------------------------------------------------
*/

if (
    $application['status'] !==
    'draft'
) {

    header(
        'Location: /pbo-portal/applications/records.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Financial-year cycles
|--------------------------------------------------------------------------
|
| Every option below represents exactly twelve consecutive months.
|
| Start month => end month
|
*/

$financialYearCycles = [

    1 => [
        'start' => 1,
        'end' => 12,
        'label' => 'January – December',
    ],

    2 => [
        'start' => 2,
        'end' => 1,
        'label' => 'February – January',
    ],

    3 => [
        'start' => 3,
        'end' => 2,
        'label' => 'March – February',
    ],

    4 => [
        'start' => 4,
        'end' => 3,
        'label' => 'April – March',
    ],

    5 => [
        'start' => 5,
        'end' => 4,
        'label' => 'May – April',
    ],

    6 => [
        'start' => 6,
        'end' => 5,
        'label' => 'June – May',
    ],

    7 => [
        'start' => 7,
        'end' => 6,
        'label' => 'July – June',
    ],

    8 => [
        'start' => 8,
        'end' => 7,
        'label' => 'August – July',
    ],

    9 => [
        'start' => 9,
        'end' => 8,
        'label' => 'September – August',
    ],

    10 => [
        'start' => 10,
        'end' => 9,
        'label' => 'October – September',
    ],

    11 => [
        'start' => 11,
        'end' => 10,
        'label' => 'November – October',
    ],

    12 => [
        'start' => 12,
        'end' => 11,
        'label' => 'December – November',
    ],
];


/*
|--------------------------------------------------------------------------
| Determine existing selection
|--------------------------------------------------------------------------
*/

$selectedCycle = 0;

$currentStartMonth =
    $application[
        'financial_year_start_month'
    ] !== null
        ? (int)
          $application[
              'financial_year_start_month'
          ]
        : 0;

$currentEndMonth =
    $application[
        'financial_year_end_month'
    ] !== null
        ? (int)
          $application[
              'financial_year_end_month'
          ]
        : 0;


foreach (
    $financialYearCycles
    as $cycleNumber => $cycle
) {

    if (
        $cycle['start'] ===
            $currentStartMonth &&
        $cycle['end'] ===
            $currentEndMonth
    ) {

        $selectedCycle =
            (int) $cycleNumber;

        break;
    }
}


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

if (
    $_SERVER['REQUEST_METHOD'] ===
    'POST'
) {

    /*
    |--------------------------------------------------------------------------
    | Submission action
    |--------------------------------------------------------------------------
    */

    $formAction =
        (string)
        ($_POST['form_action'] ??
            'save_continue');


    $isWizardNavigation =
        $formAction ===
        'wizard_navigation';


    $wizardDestination =
        filter_input(
            INPUT_POST,
            'wizard_destination',
            FILTER_VALIDATE_INT
        );


    if (
        $wizardDestination === false ||
        $wizardDestination === null ||
        $wizardDestination < 1 ||
        $wizardDestination > 6
    ) {

        $wizardDestination = 4;
    }


    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $submittedToken =
        (string)
        ($_POST['csrf_token'] ?? '');


    if (
        $submittedToken === '' ||
        !hash_equals(
            $csrfToken,
            $submittedToken
        )
    ) {

        $errors[] =
            'Your session token is invalid or has expired. Please reload the page and try again.';
    }


    /*
    |--------------------------------------------------------------------------
    | Selected financial-year cycle
    |--------------------------------------------------------------------------
    */

    $selectedCycle =
        isset(
            $_POST[
                'financial_year_cycle'
            ]
        )
            ? (int)
              $_POST[
                  'financial_year_cycle'
              ]
            : 0;


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | Save & Continue requires a financial year.
    |
    | Wizard navigation may leave this page incomplete.
    |
    */

    if (
        !$isWizardNavigation &&
        !isset(
            $financialYearCycles[
                $selectedCycle
            ]
        )
    ) {

        $errors[] =
            'Please select a valid financial year.';
    }


    /*
    |--------------------------------------------------------------------------
    | Reject manipulated cycle values
    |--------------------------------------------------------------------------
    |
    | A non-zero selection must always correspond to one of our approved
    | twelve-month financial-year cycles.
    |
    */

    if (
        $selectedCycle !== 0 &&
        !isset(
            $financialYearCycles[
                $selectedCycle
            ]
        )
    ) {

        $errors[] =
            'The selected financial year is invalid.';
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
            | Determine start/end months
            |--------------------------------------------------------------------------
            */

            $startMonth = null;
            $endMonth = null;
            $cycleLabel =
                'Not yet selected';


            if (
                isset(
                    $financialYearCycles[
                        $selectedCycle
                    ]
                )
            ) {

                $selectedFinancialYear =
                    $financialYearCycles[
                        $selectedCycle
                    ];


                $startMonth =
                    (int)
                    $selectedFinancialYear[
                        'start'
                    ];


                $endMonth =
                    (int)
                    $selectedFinancialYear[
                        'end'
                    ];


                $cycleLabel =
                    (string)
                    $selectedFinancialYear[
                        'label'
                    ];
            }


            /*
            |--------------------------------------------------------------------------
            | Update application
            |--------------------------------------------------------------------------
            */

            $statement =
                $connection->prepare(
                    "
                    UPDATE pbo_applications
                    SET
                        financial_year_start_month =
                            :start_month,

                        financial_year_end_month =
                            :end_month,

                        current_step =
                            CASE
                                WHEN current_step < 5
                                    THEN 5
                                ELSE current_step
                            END,

                        updated_by =
                            :updated_by

                    WHERE id =
                        :application_id
                    "
                );


            $statement->execute([

                ':start_month'
                    => $startMonth,

                ':end_month'
                    => $endMonth,

                ':updated_by'
                    => $userId,

                ':application_id'
                    => $applicationId,
            ]);


            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */

            $statement =
                $connection->prepare(
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


            $auditDescription =
                $selectedCycle !== 0
                    ? 'Financial year recorded as ' .
                      $cycleLabel .
                      '.'
                    : 'Financial year section saved as an incomplete draft.';


            $statement->execute([

                ':application_id'
                    => $applicationId,

                ':user_id'
                    => $userId,

                ':description'
                    => $auditDescription,
            ]);


            $connection->commit();


            /*
            |--------------------------------------------------------------------------
            | Wizard destination
            |--------------------------------------------------------------------------
            */

            if ($isWizardNavigation) {

                $wizardPages = [

                    1 => 'create.php',

                    2 => 'objectives.php',

                    3 => 'beneficiaries.php',

                    4 => 'financial-year.php',

                    5 => 'officials.php',

                    6 => 'review.php',
                ];


                $destinationPage =
                    $wizardPages[
                        $wizardDestination
                    ] ??
                    'financial-year.php';


                header(
                    'Location: /pbo-portal/applications/' .
                    $destinationPage .
                    '?id=' .
                    urlencode(
                        (string)
                        $applicationId
                    )
                );

                exit;
            }


            /*
            |--------------------------------------------------------------------------
            | Normal Save & Continue
            |--------------------------------------------------------------------------
            */

            header(
                'Location: /pbo-portal/applications/officials.php?id=' .
                urlencode(
                    (string)
                    $applicationId
                )
            );

            exit;


        } catch (Throwable $exception) {

            if (
                $connection->inTransaction()
            ) {

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

require_once __DIR__ .
    '/../includes/header.php';

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
                Step 4 of 6 — Select the organization's
                twelve-month financial year.
            </p>

        </div>

    </section>


    <!-- ==========================================================
         APPLICATION SUMMARY
         ========================================================== -->

    <div class="admin-info">

        <strong>
            <?= e(
                (string)
                $application['pbo_name']
            ) ?>
        </strong>


        <?php if (
            !empty(
                $application[
                    'application_reference'
                ]
            )
        ): ?>

            &nbsp;&mdash;&nbsp;

            Application Reference:

            <strong>
                <?= e(
                    (string)
                    $application[
                        'application_reference'
                    ]
                ) ?>
            </strong>

        <?php endif; ?>

    </div>


    <?php

    $wizardCurrentStep = 4;

    require __DIR__ .
        '/../includes/application-wizard.php';

    ?>


    <!-- ==========================================================
         ERRORS
         ========================================================== -->

    <?php if ($errors !== []): ?>

        <div class="alert alert-error">

            <strong>
                Please correct the following:
            </strong>

            <ul
                style="
                    margin: 8px 0 0;
                    padding-left: 20px;
                "
            >

                <?php foreach (
                    $errors as $error
                ): ?>

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
        novalidate
        <?php
        /*
        | Autosave key: this page, this application. Keeping the key
        | specific means one application's draft can never be offered
        | back on another's page.
        */
        ?>
        data-autosave="financial-year-<?= (int) ($applicationId ?? 0) ?>"
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

        <input
            type="hidden"
            name="form_action"
            id="form_action"
            value="save_continue"
        >

        <input
            type="hidden"
            name="wizard_destination"
            id="wizard_destination"
            value=""
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
                        Select the organization's approved
                        twelve-month financial year.
                    </p>

                </div>

            </div>


            <div class="admin-info">

                Select one financial-year period.

                Each available option covers exactly
                <strong>12 consecutive months</strong>.

            </div>


            <div class="form-group">

                <label for="financial_year_cycle">

                    Financial Year

                    <span class="required">
                        *
                    </span>

                </label>


                <select
                    id="financial_year_cycle"
                    name="financial_year_cycle"
                    required
                >

                    <option value="">
                        Select Financial Year
                    </option>


                    <?php foreach (
                        $financialYearCycles
                        as $cycleNumber => $cycle
                    ): ?>

                        <option
                            value="<?= (int) $cycleNumber ?>"
                            <?= $selectedCycle ===
                                (int) $cycleNumber
                                    ? 'selected'
                                    : '' ?>
                        >
                            <?= e(
                                (string)
                                $cycle['label']
                            ) ?>
                        </option>

                    <?php endforeach; ?>

                </select>


                <span class="form-help">

                    Example:
                    January – December,
                    March – February,
                    or July – June.

                </span>

            </div>


            <!-- Selected period preview -->

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
                        font-size: 20px;
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
                        (string)
                        $applicationId
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

    const financialYearSelect =
        document.getElementById(
            'financial_year_cycle'
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
    | Financial-year labels
    |--------------------------------------------------------------------------
    */

    const financialYearLabels = {

        1: 'January – December',

        2: 'February – January',

        3: 'March – February',

        4: 'April – March',

        5: 'May – April',

        6: 'June – May',

        7: 'July – June',

        8: 'August – July',

        9: 'September – August',

        10: 'October – September',

        11: 'November – October',

        12: 'December – November'
    };


    /*
    |--------------------------------------------------------------------------
    | Update preview
    |--------------------------------------------------------------------------
    */

    function updatePreview() {

        const selectedCycle =
            parseInt(
                financialYearSelect.value,
                10
            );


        if (
            selectedCycle &&
            financialYearLabels[
                selectedCycle
            ]
        ) {

            previewText.textContent =
                financialYearLabels[
                    selectedCycle
                ];

            preview.style.display =
                '';

        } else {

            previewText.textContent =
                '';

            preview.style.display =
                'none';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Financial-year change
    |--------------------------------------------------------------------------
    */

    financialYearSelect.addEventListener(
        'change',
        updatePreview
    );


    /*
    |--------------------------------------------------------------------------
    | Initial preview
    |--------------------------------------------------------------------------
    */

    updatePreview();


    /*
    |--------------------------------------------------------------------------
    | Normal Save & Continue
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        function (event) {

            /*
            |--------------------------------------------------------------------------
            | Wizard navigation uses its own partial-save pathway.
            |--------------------------------------------------------------------------
            */

            const formAction =
                document.getElementById(
                    'form_action'
                );


            if (
                formAction &&
                formAction.value ===
                    'wizard_navigation'
            ) {

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | A valid financial year is mandatory for Save & Continue.
            |--------------------------------------------------------------------------
            */

            if (
                financialYearSelect.value === ''
            ) {

                event.preventDefault();

                financialYearSelect.focus();

                alert(
                    'Please select a valid financial year.'
                );

                return;
            }


            if (!form.checkValidity()) {

                event.preventDefault();

                form.reportValidity();

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Prevent accidental double submission
            |--------------------------------------------------------------------------
            */

            const submitButton =
                form.querySelector(
                    'button[type="submit"]'
                );


            if (submitButton) {

                submitButton.disabled =
                    true;

                submitButton.innerHTML =
                    'Saving...';
            }
        }
    );


    /*
    |--------------------------------------------------------------------------
    | Wizard navigation
    |--------------------------------------------------------------------------
    |
    | Clicking another wizard step saves the Financial Year selection
    | before navigating.
    |
    */

    const wizardLinks =
        document.querySelectorAll(
            '[data-wizard-link="true"]'
        );


    wizardLinks.forEach(
        function (link) {

            link.addEventListener(
                'click',
                function (event) {

                    const destination =
                        this.getAttribute(
                            'data-wizard-step'
                        );


                    /*
                    |--------------------------------------------------------------------------
                    | Financial Year is already the current page.
                    |--------------------------------------------------------------------------
                    */

                    if (destination === '4') {

                        return;
                    }


                    event.preventDefault();


                    const formAction =
                        document.getElementById(
                            'form_action'
                        );

                    const wizardDestination =
                        document.getElementById(
                            'wizard_destination'
                        );


                    if (
                        !formAction ||
                        !wizardDestination
                    ) {

                        return;
                    }


                    formAction.value =
                        'wizard_navigation';

                    wizardDestination.value =
                        destination;


                    /*
                    |--------------------------------------------------------------------------
                    | Wizard navigation may preserve an incomplete section.
                    |--------------------------------------------------------------------------
                    */

                    form.noValidate = true;


                    /*
                    |--------------------------------------------------------------------------
                    | Save draft and let PHP redirect to requested wizard page.
                    |--------------------------------------------------------------------------
                    */

                    form.submit();
                }
            );
        }
    );

})();
</script>



<!-- Keeps typed input in the browser so a refused save never costs the work. -->
<script src="/pbo-portal/assets/js/form-autosave.js" defer></script>

<?php

/*
|--------------------------------------------------------------------------
| Shared footer
|--------------------------------------------------------------------------
*/

require_once __DIR__ .
    '/../includes/footer.php';

?>