<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Shared Application Wizard
|--------------------------------------------------------------------------
|
| Expected:
|   $applicationId
|   $wizardCurrentStep
|
*/

$wizardApplicationId =
    isset($applicationId) &&
    is_numeric($applicationId) &&
    (int) $applicationId > 0
        ? (int) $applicationId
        : null;


$wizardCurrentStep =
    isset($wizardCurrentStep)
        ? (int) $wizardCurrentStep
        : 1;


$wizardSteps = [

    1 => [
        'label' => 'Organization',
        'page' => 'create.php',
    ],

    2 => [
        'label' => 'Objectives',
        'page' => 'objectives.php',
    ],

    3 => [
        'label' => 'Beneficiaries',
        'page' => 'beneficiaries.php',
    ],

    4 => [
        'label' => 'Financial Year',
        'page' => 'financial-year.php',
    ],

    5 => [
        'label' => 'Officials',
        'page' => 'officials.php',
    ],

    6 => [
        'label' => 'Review',
        'page' => 'review.php',
    ],
];


$wizardProgress =
    (
        $wizardCurrentStep /
        count($wizardSteps)
    ) * 100;

?>


<style>

.application-wizard {
    margin-bottom: 22px;
    padding: 16px 18px;

    background: #ffffff;

    border: 1px solid var(--border);
    border-radius: var(--radius);

    box-shadow: var(--shadow-sm);
}


.application-wizard-steps {
    display: grid;

    grid-template-columns:
        repeat(6, minmax(0, 1fr));

    gap: 7px;

    margin-bottom: 12px;
}


.application-wizard-step {
    display: flex;

    align-items: center;
    justify-content: center;

    min-height: 40px;

    padding: 7px 8px;

    border: 1px solid transparent;
    border-radius: 6px;

    color: var(--text-secondary);

    font-size: 10px;
    font-weight: 700;

    line-height: 1.2;

    text-align: center;
    text-decoration: none;

    transition:
        background .15s ease,
        border-color .15s ease,
        color .15s ease,
        transform .15s ease;
}


a.application-wizard-step:hover {
    background: var(--soft-bg);

    border-color: var(--border);

    color: var(--blue);

    transform: translateY(-1px);
}


.application-wizard-step.is-active {
    background: var(--blue);

    border-color: var(--blue);

    color: #ffffff;
}


.application-wizard-step.is-disabled {
    opacity: .45;

    cursor: not-allowed;
}


.application-wizard-number {
    margin-right: 4px;

    font-weight: 800;
}


.application-wizard-progress {
    width: 100%;
    height: 7px;

    overflow: hidden;

    background: #e9edf1;

    border-radius: 20px;
}


.application-wizard-progress-bar {
    height: 100%;

    background:
        linear-gradient(
            90deg,
            var(--blue),
            var(--blue-medium)
        );

    border-radius: 20px;
}


@media (max-width: 900px) {

    .application-wizard-steps {

        grid-template-columns:
            repeat(3, 1fr);
    }

}


@media (max-width: 550px) {

    .application-wizard-steps {

        grid-template-columns:
            repeat(2, 1fr);
    }

}

</style>


<div class="application-wizard">

    <div class="application-wizard-steps">

        <?php foreach (
            $wizardSteps
            as $stepNumber => $step
        ): ?>

            <?php

            $isActive =
                $stepNumber ===
                $wizardCurrentStep;


            /*
            |--------------------------------------------------------------------------
            | A brand-new application has no ID yet
            |--------------------------------------------------------------------------
            */

            $isDisabled = false;

            $classes = [
                'application-wizard-step',
            ];


            if ($isActive) {

                $classes[] =
                    'is-active';
            }


            if ($isDisabled) {

                $classes[] =
                    'is-disabled';
            }


            if (
                $wizardApplicationId !== null
            ) {

                $url =
                    '/pbo-portal/applications/' .
                    $step['page'] .
                    '?id=' .
                    urlencode(
                        (string)
                        $wizardApplicationId
                    );

            } else {
                $url = '#';}

            ?>


            <?php if ($isDisabled): ?>

                <span
                    class="<?= e(
                        implode(
                            ' ',
                            $classes
                        )
                    ) ?>"
                    title="Save Organization first."
                >

                    <span class="application-wizard-number">
                        <?= (int) $stepNumber ?>.
                    </span>

                    <?= e(
                        (string)
                        $step['label']
                    ) ?>

                </span>


            <?php else: ?>

                <a
                    href="<?= e($url) ?>"
                    class="<?= e(
                        implode(
                            ' ',
                            $classes
                        )
                    ) ?>"
                    data-wizard-link="true"
                    data-wizard-step="<?= (int) $stepNumber ?>"
                >

                    <span class="application-wizard-number">
                        <?= (int) $stepNumber ?>.
                    </span>

                    <?= e(
                        (string)
                        $step['label']
                    ) ?>

                </a>

            <?php endif; ?>

        <?php endforeach; ?>

    </div>


    <div class="application-wizard-progress">

        <div
            class="application-wizard-progress-bar"
            style="width: <?= e(
                number_format(
                    $wizardProgress,
                    4,
                    '.',
                    ''
                )
            ) ?>%;"
        ></div>

    </div>

</div>
