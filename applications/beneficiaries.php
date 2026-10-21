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

$pageTitle = 'Beneficiaries';
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

    $_SESSION['csrf_token'] =
        bin2hex(random_bytes(32));

}

$csrfToken =
    (string) $_SESSION['csrf_token'];


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

$application =
    $statement->fetch(PDO::FETCH_ASSOC);

if (!$application) {

    http_response_code(404);
    exit('Application not found.');

}


/*
|--------------------------------------------------------------------------
| Submitted applications cannot be edited
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
| Section of the Public beneficiary options
|--------------------------------------------------------------------------
|
| The duplicated Youth entry from the source document has deliberately
| been reduced to one Youth option as agreed.
|
*/

$beneficiaryOptions = [

    'CHILDREN'
        => 'Children',

    'ELDERLY'
        => 'The Elderly',

    'YOUTH'
        => 'Youth',

    'COMMERCIAL_SEX_WORKERS'
        => 'Commercial sex workers',

    'DRUG_ALCOHOL_ADDICTS'
        => 'Drug and alcohol addicts',

    'HOMELESS_PEOPLE'
        => 'Homeless people',

    'ORPHANS_VULNERABLE_CHILDREN'
        => 'Orphans and Vulnerable Children',

    'PERSONS_WITH_DISABILITY'
        => 'Persons with Disability',

    'REFUGEES'
        => 'Refugees',

    'STREET_CHILDREN'
        => 'Street Children',

    'THE_POOR'
        => 'The Poor',

    'PRISONERS_EX_CONVICTS'
        => 'Prisoners and ex-convict',

    'PEOPLE_LIVING_WITH_HIV_AIDS'
        => 'People living with HIV/AIDS',

    'CAREGIVERS_VULNERABLE_GROUPS'
        => 'Caregivers for vulnerable groups (OVCs, Elderly, PLWAs)',

    'OTHER'
        => 'Others not listed here',

];


/*
|--------------------------------------------------------------------------
| Initial form values
|--------------------------------------------------------------------------
*/

$beneficiaryScope = '';

$selectedBeneficiaries = [];

$otherSpecify = '';

$otherDescription = '';


/*
|--------------------------------------------------------------------------
| Load saved beneficiary information
|--------------------------------------------------------------------------
*/

$statement = $connection->prepare(
    "
    SELECT
        beneficiary_code,
        description
    FROM application_beneficiaries
    WHERE application_id = :application_id
    ORDER BY id ASC
    "
);

$statement->execute([
    ':application_id' => $applicationId,
]);

$savedBeneficiaries =
    $statement->fetchAll(PDO::FETCH_ASSOC);


if ($savedBeneficiaries !== []) {

    foreach ($savedBeneficiaries as $row) {

        $code =
            (string) $row['beneficiary_code'];


        /*
        | PUBLIC is stored as its own beneficiary code.
        */

        if ($code === 'PUBLIC') {

            $beneficiaryScope = 'public';

            break;

        }

    }


    /*
    | If there is no PUBLIC row, saved rows represent
    | Section of the Public.
    */

    if ($beneficiaryScope !== 'public') {

        $beneficiaryScope =
            'section_of_public';


        foreach ($savedBeneficiaries as $row) {

            $code =
                (string) $row['beneficiary_code'];


            if (
                array_key_exists(
                    $code,
                    $beneficiaryOptions
                )
            ) {

                $selectedBeneficiaries[] =
                    $code;

            }


            /*
            |--------------------------------------------------------------------------
            | Decode OTHER
            |--------------------------------------------------------------------------
            */

            if ($code === 'OTHER') {

                $storedDescription =
                    trim(
                        (string)
                        ($row['description'] ?? '')
                    );


                if ($storedDescription !== '') {

                    $decoded =
                        json_decode(
                            $storedDescription,
                            true
                        );


                    if (
                        is_array($decoded) &&
                        isset($decoded['specify'])
                    ) {

                        $otherSpecify =
                            trim(
                                (string)
                                ($decoded['specify'] ?? '')
                            );

                        $otherDescription =
                            trim(
                                (string)
                                ($decoded['description'] ?? '')
                            );

                    } else {

                        /*
                        | Backward compatibility with any older
                        | plain-text OTHER records.
                        */

                        $otherDescription =
                            $storedDescription;

                    }

                }

            }

        }

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        /*
    |--------------------------------------------------------------------------
    | Submission action
    |--------------------------------------------------------------------------
    */

    $formAction =
        (string)
        ($_POST['form_action'] ?? 'save_continue');


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

        $wizardDestination = 3;
    }


    /*
    |--------------------------------------------------------------------------
    | CSRF validation
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
    | Public / Section of Public
    |--------------------------------------------------------------------------
    */

    $beneficiaryScope =
        trim(
            (string)
            ($_POST['beneficiary_scope'] ?? '')
        );


    if (
    !$isWizardNavigation &&
    !in_array(
        $beneficiaryScope,
        [
            'public',
            'section_of_public',
        ],
        true
    )
) {

    $errors[] =
        'Please indicate whether the beneficiaries are the Public or a Section of the Public.';
}

    /*
    |--------------------------------------------------------------------------
    | Selected beneficiary groups
    |--------------------------------------------------------------------------
    */

    $postedBeneficiaries =
        $_POST['beneficiaries'] ?? [];


    if (!is_array($postedBeneficiaries)) {

        $postedBeneficiaries = [];

    }


    $postedBeneficiaries =
        array_values(
            array_unique(
                array_map(
                    static fn ($value): string =>
                        trim((string) $value),
                    $postedBeneficiaries
                )
            )
        );


    $validCodes =
        array_keys($beneficiaryOptions);


    $selectedBeneficiaries =
        array_values(
            array_filter(
                $postedBeneficiaries,
                static fn (string $code): bool =>
                    in_array(
                        $code,
                        $validCodes,
                        true
                    )
            )
        );


    /*
    |--------------------------------------------------------------------------
    | OTHER fields
    |--------------------------------------------------------------------------
    */

    $otherSpecify =
        trim(
            (string)
            ($_POST['other_specify'] ?? '')
        );


    $otherDescription =
        trim(
            (string)
            ($_POST['other_description'] ?? '')
        );


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if (
    !$isWizardNavigation &&
    $beneficiaryScope ===
    'section_of_public'
) {

        if ($selectedBeneficiaries === []) {

            $errors[] =
                'Select at least one beneficiary group under Section of the Public.';

        }


        if (
            in_array(
                'OTHER',
                $selectedBeneficiaries,
                true
            )
        ) {

            if ($otherSpecify === '') {

                $errors[] =
                    'Please specify the beneficiary group under Others not listed here.';

            }


            if ($otherDescription === '') {

                $errors[] =
                    'Please describe the beneficiary group entered under Others not listed here.';

            }

        }

    }


    /*
    | If Public is selected, ignore any checkbox values
    | that may still have been posted.
    */

    if ($beneficiaryScope === 'public') {

        $selectedBeneficiaries = [];

        $otherSpecify = '';

        $otherDescription = '';

    }


    /*
    | OTHER wasn't selected.
    */

    if (
        !in_array(
            'OTHER',
            $selectedBeneficiaries,
            true
        )
    ) {

        $otherSpecify = '';

        $otherDescription = '';

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
            | Remove previous selections
            |--------------------------------------------------------------------------
            */

            $statement =
                $connection->prepare(
                    "
                    DELETE FROM application_beneficiaries
                    WHERE application_id = :application_id
                    "
                );


            $statement->execute([
                ':application_id'
                    => $applicationId,
            ]);


            /*
            |--------------------------------------------------------------------------
            | PUBLIC
            |--------------------------------------------------------------------------
            */

            if (
                $beneficiaryScope ===
                'public'
            ) {

                $statement =
                    $connection->prepare(
                        "
                        INSERT INTO application_beneficiaries
                        (
                            application_id,
                            beneficiary_code,
                            description
                        )
                        VALUES
                        (
                            :application_id,
                            'PUBLIC',
                            NULL
                        )
                        "
                    );


                $statement->execute([
                    ':application_id'
                        => $applicationId,
                ]);

                } elseif (
    $beneficiaryScope ===
    'section_of_public'
) {

        


                /*
                |--------------------------------------------------------------------------
                | SECTION OF THE PUBLIC
                |--------------------------------------------------------------------------
                */

                $insert =
                    $connection->prepare(
                        "
                        INSERT INTO application_beneficiaries
                        (
                            application_id,
                            beneficiary_code,
                            description
                        )
                        VALUES
                        (
                            :application_id,
                            :beneficiary_code,
                            :description
                        )
                        "
                    );


                foreach (
                    $selectedBeneficiaries
                    as $code
                ) {

                    $description = null;


                    /*
                    | Preserve both Specify and Describe in
                    | the existing description column.
                    */

                    if ($code === 'OTHER') {

                        $description =
                            json_encode(
                                [
                                    'specify'
                                        => $otherSpecify,

                                    'description'
                                        => $otherDescription,
                                ],
                                JSON_UNESCAPED_UNICODE |
                                JSON_UNESCAPED_SLASHES
                            );

                    }


                    $insert->execute([

                        ':application_id'
                            => $applicationId,

                        ':beneficiary_code'
                            => $code,

                        ':description'
                            => $description,

                    ]);

                }

            }


            /*
            |--------------------------------------------------------------------------
            | Advance to Financial Year
            |--------------------------------------------------------------------------
            */

            $statement =
                $connection->prepare(
                    "
                    UPDATE pbo_applications
                    SET
                        current_step =
                            CASE
                                WHEN current_step < 4
                                    THEN 4
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

            $auditDescription =
                $beneficiaryScope === 'public'
                    ? 'Beneficiaries recorded as Public.'
                    : count($selectedBeneficiaries) .
                      ' Section of the Public beneficiary group(s) saved.';


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
                        'BENEFICIARIES_SAVED',
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
                    => $auditDescription,

            ]);


            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            $connection->commit();




            /*
            |--------------------------------------------------------------------------
            | Continue
            |--------------------------------------------------------------------------
            */

            /*
|--------------------------------------------------------------------------
| Destination after save
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
        $wizardPages[$wizardDestination]
        ?? 'beneficiaries.php';


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
    'Location: /pbo-portal/applications/financial-year.php?id=' .
    urlencode(
        (string)
        $applicationId
    )
);

exit;

        } catch (Throwable $exception) {

            if ($connection->inTransaction()) {

                $connection->rollBack();

            }


            error_log(
                'Saving beneficiaries failed: ' .
                $exception->getMessage()
            );


            $errors[] =
                'The beneficiary information could not be saved. No changes were committed.';

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
                Beneficiaries
            </h1>

            <p class="subtitle">
                Step 3 of 6 — Identify who will benefit from the
                organization's public benefit activities.
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
                $application['application_reference']
            )
        ): ?>

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


    <?php

$wizardCurrentStep = 3;

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
                    margin: 8px 0 0
                    ;
        padding-left: 20px;
    "
>

    <?php foreach ($errors as $error): ?>

        <li>
            <?= e($error) ?>
        </li>

    <?php endforeach; ?>

</ul>

        </div>

    <?php endif; ?>


    <!-- ==========================================================
         BENEFICIARIES FORM
         ========================================================== -->

    <form
        method="post"
        action=""
        class="application-form"
        id="beneficiaries-form"
        <?php
        /*
        | Autosave key: this page, this application. Keeping the key
        | specific means one application's draft can never be offered
        | back on another's page.
        */
        ?>
        data-autosave="beneficiaries-<?= (int) ($applicationId ?? 0) ?>"
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


        <!-- ======================================================
             SECTION 1 — BENEFICIARY SCOPE
             ====================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    3
                </div>

                <div>

                    <h2>
                        Who are your beneficiaries?
                    </h2>

                    <p>
                        Indicate whether the organization benefits the
                        public generally or a particular section of
                        the public.
                    </p>

                </div>

            </div>


            <div class="admin-info">

                Select <strong>Public</strong> where the organization's
                activities are intended to benefit the public generally.

                Select <strong>Section of the Public</strong> where the
                activities are directed towards particular groups of
                beneficiaries.

            </div>


            <div class="form-group">

                <label>
                    Beneficiary Category
                    <span class="required">*</span>
                </label>


                <div class="radio-group">


                    <!-- PUBLIC -->

                    <label class="radio-option">

                        <input
                            type="radio"
                            id="beneficiary_scope_public"
                            name="beneficiary_scope"
                            value="public"
                            <?= $beneficiaryScope === 'public'
                                ? 'checked'
                                : '' ?>
                            required
                        >

                        <span>
                            Public
                        </span>

                    </label>


                    <!-- SECTION OF PUBLIC -->

                    <label class="radio-option">

                        <input
                            type="radio"
                            id="beneficiary_scope_section"
                            name="beneficiary_scope"
                            value="section_of_public"
                            <?= $beneficiaryScope === 'section_of_public'
                                ? 'checked'
                                : '' ?>
                            required
                        >

                        <span>
                            Section of the Public
                        </span>

                    </label>

                </div>

            </div>

        </section>


        <!-- ======================================================
             SECTION 2 — SECTION OF THE PUBLIC
             ====================================================== -->

        <section
            class="application-section"
            id="section-of-public-container"
            <?= $beneficiaryScope === 'section_of_public'
                ? ''
                : 'hidden' ?>
        >

            <div class="application-section-heading">

                <div class="application-section-number">
                    3A
                </div>

                <div>

                    <h2>
                        Section of the Public
                    </h2>

                    <p>
                        If the beneficiaries are a section of the
                        public, select appropriately from the groups
                        listed below.
                    </p>

                </div>

            </div>


            <div class="admin-info">

                You may select more than one beneficiary group.

                Where <strong>Others not listed here</strong> is
                selected, specify the group and briefly describe it.

            </div>


            <!-- ==================================================
                 BENEFICIARY CHECKLIST
                 ================================================== -->

            <div
                class="checkbox-group"
                style="
                    display: grid;
                    grid-template-columns:
                        repeat(
                            auto-fit,
                            minmax(270px, 1fr)
                        );
                    gap: 10px;
                "
            >

                <?php
                $beneficiaryNumber = 1;

                foreach (
                    $beneficiaryOptions
                    as $code => $label
                ):
                ?>

                    <?php

                    $checked =
                        in_array(
                            $code,
                            $selectedBeneficiaries,
                            true
                        );

                    ?>

                    <label
                        class="checkbox-option"
                        for="beneficiary_<?= e($code) ?>"
                    >

                        <input
                            type="checkbox"
                            id="beneficiary_<?= e($code) ?>"
                            name="beneficiaries[]"
                            value="<?= e($code) ?>"
                            <?= $checked ? 'checked' : '' ?>
                            data-beneficiary-option="true"
                        >

                        <span>

                            <strong
                                style="
                                    margin-right: 4px;
                                    color: var(--blue);
                                "
                            >
                                <?= $beneficiaryNumber ?>.
                            </strong>

                            <?= e($label) ?>

                        </span>

                    </label>

                    <?php
                    $beneficiaryNumber++;
                    ?>

                <?php endforeach; ?>

            </div>


            <!-- ==================================================
                 OTHER BENEFICIARY
                 ================================================== -->

            <div
                id="other-beneficiary-container"
                style="
                    margin-top: 24px;
                    <?= in_array(
                        'OTHER',
                        $selectedBeneficiaries,
                        true
                    )
                        ? ''
                        : 'display: none;' ?>
                "
            >

                <div
                    style="
                        padding: 20px;
                        background: var(--soft-bg);
                        border: 1px solid var(--border);
                        border-left: 4px solid var(--gold);
                        border-radius: var(--radius);
                    "
                >

                    <!-- SPECIFY -->

                    <div class="form-group">

                        <label for="other_specify">

                            Others not listed here, Specify

                            <span class="required">
                                *
                            </span>

                        </label>

                        <input
                            type="text"
                            id="other_specify"
                            name="other_specify"
                            maxlength="255"
                            value="<?= e($otherSpecify) ?>"
                            placeholder="Specify the beneficiary group"
                        >

                        <span class="form-help">
                            Enter the name or category of the beneficiary
                            group that is not listed above.
                        </span>

                    </div>


                    <!-- DESCRIBE -->

                    <div
                        class="form-group"
                        style="margin-bottom: 0;"
                    >

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
                            placeholder="Briefly describe the beneficiary group..."
                        ><?= e($otherDescription) ?></textarea>

                        <span class="form-help">
                            Provide a brief description of the beneficiary
                            group specified above.
                        </span>

                    </div>

                </div>

            </div>

        </section>


        <!-- ======================================================
             ACTIONS
             ====================================================== -->

        <div class="application-actions">


            <!-- LEFT -->

            <div class="application-actions-left">

                <a
                    href="/pbo-portal/applications/objectives.php?id=<?= urlencode(
                        (string) $applicationId
                    ) ?>"
                    class="btn btn-secondary"
                >
                    &larr; Back
                </a>

            </div>


            <!-- RIGHT -->

            <div class="application-actions-right">

                <span
                    style="
                        color: var(--text-muted);
                        font-size: 11px;
                    "
                >
                    Step 3 of 6
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
     BENEFICIARY CONDITIONAL LOGIC
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
            'beneficiaries-form'
        );


    const publicRadio =
        document.getElementById(
            'beneficiary_scope_public'
        );


    const sectionRadio =
        document.getElementById(
            'beneficiary_scope_section'
        );


    const sectionContainer =
        document.getElementById(
            'section-of-public-container'
        );


    const beneficiaryCheckboxes =
        document.querySelectorAll(
            '[data-beneficiary-option="true"]'
        );


    const otherCheckbox =
        document.getElementById(
            'beneficiary_OTHER'
        );


    const otherContainer =
        document.getElementById(
            'other-beneficiary-container'
        );


    const otherSpecify =
        document.getElementById(
            'other_specify'
        );


    const otherDescription =
        document.getElementById(
            'other_description'
        );


    /*
    |--------------------------------------------------------------------------
    | Update Other
    |--------------------------------------------------------------------------
    */

    function updateOtherBeneficiary() {

        const sectionSelected =
            sectionRadio.checked;


        const otherSelected =
            sectionSelected &&
            otherCheckbox &&
            otherCheckbox.checked;


        if (otherSelected) {

            otherContainer.style.display = '';

            otherSpecify.required = true;

            otherDescription.required = true;

        } else {

            otherContainer.style.display = 'none';

            otherSpecify.required = false;

            otherDescription.required = false;

        }

    }


    /*
    |--------------------------------------------------------------------------
    | Update beneficiary scope
    |--------------------------------------------------------------------------
    */

    function updateBeneficiaryScope() {

        const sectionSelected =
            sectionRadio.checked;


        /*
        | Show or hide Section of Public.
        */

        sectionContainer.hidden =
            !sectionSelected;


        /*
        | Disable checkboxes when Public is selected.
        |
        | This prevents hidden checkbox values from being submitted.
        */

        beneficiaryCheckboxes.forEach(
            function (checkbox) {

                checkbox.disabled =
                    !sectionSelected;

            }
        );


        updateOtherBeneficiary();

    }


    /*
    |--------------------------------------------------------------------------
    | Public / Section listeners
    |--------------------------------------------------------------------------
    */

    publicRadio.addEventListener(
        'change',
        updateBeneficiaryScope
    );


    sectionRadio.addEventListener(
        'change',
        updateBeneficiaryScope
    );


    /*
    |--------------------------------------------------------------------------
    | Other listener
    |--------------------------------------------------------------------------
    */

    if (otherCheckbox) {

        otherCheckbox.addEventListener(
            'change',
            updateOtherBeneficiary
        );

    }


    /*
    |--------------------------------------------------------------------------
    | Initial state
    |--------------------------------------------------------------------------
    |
    | This restores the correct state when:
    |
    | - editing an existing application
    | - returning to this page
    | - server-side validation fails
    |
    */

    updateBeneficiaryScope();


    /*
    |--------------------------------------------------------------------------
    | Form submission
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        function (event) {


            /*
            | Synchronize conditional fields before validation.
            */

            updateBeneficiaryScope();


            /*
            |--------------------------------------------------------------------------
            | Section of Public requires at least one checkbox
            |--------------------------------------------------------------------------
            */

            if (sectionRadio.checked) {

                let selectedCount = 0;


                beneficiaryCheckboxes.forEach(
                    function (checkbox) {

                        if (
                            !checkbox.disabled &&
                            checkbox.checked
                        ) {

                            selectedCount++;

                        }

                    }
                );


                if (selectedCount === 0) {

                    event.preventDefault();

                    alert(
                        'Please select at least one beneficiary group under Section of the Public.'
                    );

                    return;

                }

            }


            /*
            |--------------------------------------------------------------------------
            | Browser validation
            |--------------------------------------------------------------------------
            */

            if (!form.checkValidity()) {

                event.preventDefault();

                form.reportValidity();

                return;

            }


            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate submission
            |--------------------------------------------------------------------------
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

        /*
    |--------------------------------------------------------------------------
    | Wizard navigation
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
                    | Beneficiaries is the current page.
                    */

                    if (destination === '3') {

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
                    | Synchronize the conditional controls before POST.
                    */

                    updateBeneficiaryScope();


                    /*
                    | Skip normal Save & Continue validation.
                    */

                    form.noValidate = true;

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

require_once __DIR__ . '/../includes/footer.php';

?>