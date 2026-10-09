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

$pageTitle = 'Public Benefit Objectives';
$activePage = 'applications';


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
| Current user
|--------------------------------------------------------------------------
*/

$userId =
    (int)
    ($_SESSION['user_id'] ?? 0);


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


/*
|--------------------------------------------------------------------------
| CSRF token
|--------------------------------------------------------------------------
*/

if (
    empty(
        $_SESSION['csrf_token']
    )
) {

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
|
| Draft applications can only be edited by their creator.
|
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
| Public Benefit Purposes
|--------------------------------------------------------------------------
|
| 28 options from the current PBO registration form.
|
*/

$objectives = [

    'AGRICULTURE'
        => 'Agriculture',

    'CHILDREN'
        => 'Children',

    'CULTURE'
        => 'Culture',

    'DISABILITY'
        => 'Disability',

    'ENERGY'
        => 'Energy',

    'EDUCATION'
        => 'Education',

    'ENVIRONMENTAL_CONSERVATION'
        => 'Environmental and Conservation',

    'GENDER'
        => 'Gender',

    'GOVERNANCE'
        => 'Governance',

    'POVERTY_ERADICATION'
        => 'Poverty Eradication',

    'HEALTH'
        => 'Health',

    'HOUSING_SETTLEMENT'
        => 'Housing & Settlement',

    'HUMAN_RIGHTS'
        => 'Human Rights',

    'HIV_AIDS'
        => 'HIV/AIDS',

    'INFORMATION'
        => 'Information',

    'INFORMAL_SECTOR'
        => 'Informal Sector',

    'LEGAL_AID'
        => 'Legal Aid',

    'OLD_AGE'
        => 'Old Age',

    'PEACE_BUILDING'
        => 'Peace Building',

    'POPULATION_REPRODUCTIVE_HEALTH'
        => 'Population & Reproductive Health',

    'REFUGEES'
        => 'Refugees',

    'DISASTER_PREVENTION'
        => 'Disaster prevention, preparedness and mitigation',

    'RELIEF_PASTORALISM_MARGINALIZED'
        => 'Relief Pastoralism and marginalized communities',

    'SPORTS'
        => 'Sports',

    'WATER_SANITATION'
        => 'Water and sanitation',

    'ANIMAL_WELFARE'
        => 'Animal welfare',

    'YOUTH'
        => 'Youth',

    'OTHER'
        => 'Any Other',
];


/*
|--------------------------------------------------------------------------
| Wizard destinations
|--------------------------------------------------------------------------
|
| Centralized here so we do not repeat or contradict redirect logic.
|
*/

$wizardPages = [

    1 => 'create.php',
    2 => 'objectives.php',
    3 => 'beneficiaries.php',
    4 => 'financial-year.php',
    5 => 'officials.php',
    6 => 'review.php',
];


/*
|--------------------------------------------------------------------------
| Existing selections
|--------------------------------------------------------------------------
*/

$selectedObjectives = [];
$otherDescription = '';


$statement =
    $connection->prepare(
        "
        SELECT
            objective_code,
            description

        FROM application_objectives

        WHERE application_id =
            :application_id

        ORDER BY id ASC
        "
    );


$statement->execute([

    ':application_id'
        => $applicationId,
]);


$savedObjectives =
    $statement->fetchAll(
        PDO::FETCH_ASSOC
    );


foreach (
    $savedObjectives
    as $row
) {

    $code =
        (string)
        $row['objective_code'];


    if (
        array_key_exists(
            $code,
            $objectives
        )
    ) {

        $selectedObjectives[] =
            $code;
    }


    if (
        $code === 'OTHER'
    ) {

        $otherDescription =
            trim(
                (string)
                (
                    $row[
                        'description'
                    ] ?? ''
                )
            );
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
    | Determine submission mode
    |--------------------------------------------------------------------------
    */

    $formAction =
        trim(
            (string)
            (
                $_POST[
                    'form_action'
                ] ?? 'save_continue'
            )
        );


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
        !isset(
            $wizardPages[
                (int)
                $wizardDestination
            ]
        )
    ) {

        $wizardDestination = 2;
    }


    $wizardDestination =
        (int)
        $wizardDestination;


    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $submittedToken =
        (string)
        (
            $_POST[
                'csrf_token'
            ] ?? ''
        );


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
    | Read submitted objectives
    |--------------------------------------------------------------------------
    */

    $postedObjectives =
        $_POST[
            'objectives'
        ] ?? [];


    if (
        !is_array(
            $postedObjectives
        )
    ) {

        $postedObjectives = [];
    }


    $postedObjectives =
        array_values(
            array_unique(
                array_map(
                    static fn (
                        mixed $value
                    ): string =>
                        trim(
                            (string)
                            $value
                        ),
                    $postedObjectives
                )
            )
        );


    $validCodes =
        array_keys(
            $objectives
        );


    $selectedObjectives =
        array_values(
            array_filter(
                $postedObjectives,
                static fn (
                    string $code
                ): bool =>
                    in_array(
                        $code,
                        $validCodes,
                        true
                    )
            )
        );


    $otherDescription =
        trim(
            (string)
            (
                $_POST[
                    'other_description'
                ] ?? ''
            )
        );


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | Save & Continue requires at least one objective.
    |
    | Wizard navigation may leave this page empty. If it is empty, we simply
    | navigate and do not alter previously saved objectives.
    |
    */

    if (
        !$isWizardNavigation &&
        $selectedObjectives === []
    ) {

        $errors[] =
            'Select at least one public benefit purpose.';
    }


    if (
        in_array(
            'OTHER',
            $selectedObjectives,
            true
        ) &&
        $otherDescription === ''
    ) {

        $errors[] =
            'Please describe the public benefit purpose selected under Any Other.';
    }


    if (
        !in_array(
            'OTHER',
            $selectedObjectives,
            true
        )
    ) {

        $otherDescription = '';
    }


    /*
    |--------------------------------------------------------------------------
    | Empty wizard navigation
    |--------------------------------------------------------------------------
    |
    | This is the key behavior:
    |
    | If no objectives are selected and the user clicks another wizard page,
    | navigate directly without deleting previously stored objectives.
    |
    */

    if (
        $errors === [] &&
        $isWizardNavigation &&
        $selectedObjectives === []
    ) {

        $redirectPage =
            $wizardPages[
                $wizardDestination
            ];


        header(
            'Location: /pbo-portal/applications/' .
            $redirectPage .
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
    | Save selected objectives
    |--------------------------------------------------------------------------
    */

    if (
        $errors === []
    ) {

        try {

            $connection->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | Replace previous objective selections
            |--------------------------------------------------------------------------
            */

            $statement =
                $connection->prepare(
                    "
                    DELETE FROM
                        application_objectives

                    WHERE application_id =
                        :application_id
                    "
                );


            $statement->execute([

                ':application_id'
                    => $applicationId,
            ]);


            /*
            |--------------------------------------------------------------------------
            | Insert selections
            |--------------------------------------------------------------------------
            */

            $insert =
                $connection->prepare(
                    "
                    INSERT INTO
                        application_objectives
                    (
                        application_id,
                        objective_code,
                        description
                    )

                    VALUES
                    (
                        :application_id,
                        :objective_code,
                        :description
                    )
                    "
                );


            foreach (
                $selectedObjectives
                as $code
            ) {

                $description =
                    $code === 'OTHER'
                        ? $otherDescription
                        : null;


                $insert->execute([

                    ':application_id'
                        => $applicationId,

                    ':objective_code'
                        => $code,

                    ':description'
                        => $description,
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | Advance progress
            |--------------------------------------------------------------------------
            |
            | Saving valid Objectives means Step 2 has been completed.
            |
            */

            $statement =
                $connection->prepare(
                    "
                    UPDATE
                        pbo_applications

                    SET
                        current_step =
                            CASE
                                WHEN current_step < 3
                                    THEN 3
                                ELSE current_step
                            END,

                        updated_by =
                            :updated_by

                    WHERE id =
                        :application_id
                    "
                );


            $statement->execute([

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
                    INSERT INTO
                        application_audit_log
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
                        'OBJECTIVES_SAVED',
                        :description
                    )
                    "
                );


            $statement->execute([

                ':application_id'
                    => $applicationId,

                ':user_id'
                    => $userId,

                ':description'
                    =>
                        count(
                            $selectedObjectives
                        ) .
                        ' public benefit purpose(s) saved.',
            ]);


            $connection->commit();


            /*
            |--------------------------------------------------------------------------
            | Destination
            |--------------------------------------------------------------------------
            |
            | Wizard click:
            |   go exactly where the user clicked.
            |
            | Save & Continue:
            |   go to Beneficiaries.
            |
            */

            if (
                $isWizardNavigation
            ) {

                $redirectPage =
                    $wizardPages[
                        $wizardDestination
                    ];

            } else {

                $redirectPage =
                    'beneficiaries.php';
            }


            header(
                'Location: /pbo-portal/applications/' .
                $redirectPage .
                '?id=' .
                urlencode(
                    (string)
                    $applicationId
                )
            );

            exit;


        } catch (
            Throwable $exception
        ) {

            if (
                $connection->inTransaction()
            ) {

                $connection->rollBack();
            }


            error_log(
                'Saving objectives failed: ' .
                $exception->getMessage()
            );


            $errors[] =
                'The public benefit purposes could not be saved. No changes were committed.';
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
                Public Benefit Purposes
            </h1>


            <p class="subtitle">
                Step 2 of 6 — Select the activities that most closely
                relate to the organization's public benefit purposes.
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
                $application[
                    'pbo_name'
                ]
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


    <!-- ==========================================================
         SHARED WIZARD
         ========================================================== -->

    <?php

    $wizardCurrentStep = 2;

    require __DIR__ .
        '/../includes/application-wizard.php';

    ?>


    <!-- ==========================================================
         ERRORS
         ========================================================== -->

    <?php if (
        $errors !== []
    ): ?>

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
                    $errors
                    as $error
                ): ?>

                    <li>
                        <?= e($error) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>


    <!-- ==========================================================
         OBJECTIVES FORM
         ========================================================== -->

    <form
        method="post"
        action=""
        class="application-form"
        id="objectives-form"
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


        <input
            type="hidden"
            name="csrf_token"
            value="<?= e(
                $csrfToken
            ) ?>"
        >


        <input
            type="hidden"
            name="application_id"
            value="<?= (int)
                $applicationId ?>"
        >


        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    2
                </div>


                <div>

                    <h2>
                        Public Benefit Purposes
                    </h2>


                    <p>
                        Select appropriately from the activities below
                        those which most closely relate to the
                        organization's public benefit purposes.
                    </p>

                </div>

            </div>


            <div class="admin-info">

                You may select more than one public benefit purpose.

                Where
                <strong>Any Other</strong>
                is selected, describe that purpose in the field provided.

            </div>


            <!-- ==================================================
                 OBJECTIVE CHECKLIST
                 ================================================== -->

            <div
                class="checkbox-group"
                style="
                    display: grid;
                    grid-template-columns:
                        repeat(
                            auto-fit,
                            minmax(
                                270px,
                                1fr
                            )
                        );
                    gap:                        10px;
                "
            >

                <?php

                $number = 1;

                foreach (
                    $objectives
                    as $code => $label
                ):

                    $checked =
                        in_array(
                            $code,
                            $selectedObjectives,
                            true
                        );

                ?>

                    <label
                        class="checkbox-option"
                        for="objective_<?= e(
                            $code
                        ) ?>"
                    >

                        <input
                            type="checkbox"
                            id="objective_<?= e(
                                $code
                            ) ?>"
                            name="objectives[]"
                            value="<?= e(
                                $code
                            ) ?>"
                            <?= $checked
                                ? 'checked'
                                : '' ?>
                        >


                        <span>

                            <strong
                                style="
                                    color: var(--blue);
                                    margin-right: 4px;
                                "
                            >
                                <?= $number ?>.
                            </strong>

                            <?= e($label) ?>

                        </span>

                    </label>

                    <?php $number++; ?>

                <?php endforeach; ?>

            </div>


            <!-- ==================================================
                 ANY OTHER
                 ================================================== -->

            <div
                id="other-objective-container"
                style="
                    margin-top: 24px;
                    <?= in_array(
                        'OTHER',
                        $selectedObjectives,
                        true
                    )
                        ? ''
                        : 'display: none;' ?>
                "
            >

                <div class="form-group">

                    <label for="other_description">

                        If so, Describe

                        <span class="required">
                            *
                        </span>

                    </label>


                    <textarea
                        id="other_description"
                        name="other_description"
                        maxlength="5000"
                        placeholder="Describe the other public benefit purpose..."
                    ><?= e(
                        $otherDescription
                    ) ?></textarea>


                    <span class="form-help">
                        Briefly describe the public benefit purpose
                        that is not included in the options above.
                    </span>

                </div>

            </div>

        </section>


        <!-- ======================================================
             ACTIONS
             ====================================================== -->

        <div class="application-actions">

            <div class="application-actions-left">

                <a
                    href="/pbo-portal/applications/create.php?id=<?= urlencode(
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
                    Step 2 of 6
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
     OBJECTIVES PAGE LOGIC
     ============================================================== -->

<script>
(function () {

    'use strict';


    const form =
        document.getElementById(
            'objectives-form'
        );


    const otherCheckbox =
        document.getElementById(
            'objective_OTHER'
        );


    const otherContainer =
        document.getElementById(
            'other-objective-container'
        );


    const otherDescription =
        document.getElementById(
            'other_description'
        );


    const formAction =
        document.getElementById(
            'form_action'
        );


    const wizardDestination =
        document.getElementById(
            'wizard_destination'
        );


    /*
    |--------------------------------------------------------------------------
    | Guard
    |--------------------------------------------------------------------------
    */

    if (!form) {
        return;
    }


    /*
    |--------------------------------------------------------------------------
    | Any Other
    |--------------------------------------------------------------------------
    */

    function updateOtherObjective() {

        if (
            !otherCheckbox ||
            !otherContainer ||
            !otherDescription
        ) {
            return;
        }


        const selected =
            otherCheckbox.checked;


        if (selected) {

            otherContainer.style.display =
                '';

            otherDescription.required =
                true;

        } else {

            otherContainer.style.display =
                'none';

            otherDescription.required =
                false;

            /*
            | Do not clear the value here.
            |
            | This prevents text from disappearing if the user
            | accidentally unchecks/rechecks Any Other before saving.
            */
        }
    }


    if (otherCheckbox) {

        otherCheckbox.addEventListener(
            'change',
            updateOtherObjective
        );
    }


    updateOtherObjective();


    /*
    |--------------------------------------------------------------------------
    | Normal Save & Continue
    |--------------------------------------------------------------------------
    |
    | Reset the action explicitly. This matters if a wizard link was
    | previously interacted with but navigation did not complete.
    |
    */

    form.addEventListener(
        'submit',
        function (event) {

            if (
                formAction &&
                formAction.value !==
                    'wizard_navigation'
            ) {

                formAction.value =
                    'save_continue';
            }


            updateOtherObjective();


            /*
            | Normal form submission must satisfy browser validation.
            |
            | Wizard navigation uses form.submit() below, which bypasses
            | this submit event and browser constraint validation.
            */

            if (
                formAction &&
                formAction.value ===
                    'save_continue' &&
                !form.checkValidity()
            ) {

                event.preventDefault();

                form.reportValidity();

                return;
            }


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
    | The shared wizard provides:
    |
    | data-wizard-link="true"
    | data-wizard-step="1..6"
    |
    | When another step is clicked:
    |
    | 1. Prevent the anchor's normal navigation.
    | 2. Tell PHP this is wizard navigation.
    | 3. Tell PHP exactly which step was requested.
    | 4. Submit the Objectives form without HTML required validation.
    |
    | PHP then either:
    |
    | - navigates without changing Objectives when the selection is empty, or
    | - saves the current selections and redirects to the requested step.
    |--------------------------------------------------------------------------
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
                    | Objectives is already the current page.
                    */

                    if (
                        destination === '2'
                    ) {

                        event.preventDefault();

                        return;
                    }


                    /*
                    | If required hidden controls somehow do not exist,
                    | allow the original wizard hyperlink to work instead
                    | of trapping the user on this page.
                    */

                    if (
                        !formAction ||
                        !wizardDestination ||
                        !destination
                    ) {

                        return;
                    }


                    event.preventDefault();


                    formAction.value =
                        'wizard_navigation';


                    wizardDestination.value =
                        destination;


                    /*
                    | Use the native submit() method intentionally.
                    |
                    | This bypasses browser constraint validation and the
                    | normal submit event. PHP performs the wizard-specific
                    | validation and redirect.
                    */

                    form.submit();
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

require_once __DIR__ .
    '/../includes/footer.php';

?>