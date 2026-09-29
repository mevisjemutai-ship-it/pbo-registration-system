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

$pageTitle = 'View Application';
$activePage = 'applications';


/*
|--------------------------------------------------------------------------
| Database
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


function displayValue(mixed $value): string
{
    $value =
        trim(
            (string) $value
        );

    return $value !== ''
        ? e($value)
        : '<span class="text-muted">Not provided</span>';
}


function organizationTypeLabel(
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


function designationLabel(
    string $designation
): string {

    return match (
        strtoupper($designation)
    ) {

        'CHAIRPERSON'
            => 'Chairperson',

        'TREASURER'
            => 'Treasurer',

        'SECRETARY'
            => 'Secretary',

        'DIRECTOR'
            => 'Director',

        default
            => ucwords(
                strtolower(
                    str_replace(
                        '_',
                        ' ',
                        $designation
                    )
                )
            ),
    };
}


/*
|--------------------------------------------------------------------------
| Signature type
|--------------------------------------------------------------------------
*/

function signatureExtension(
    string $path
): string {

    $path =
        parse_url(
            $path,
            PHP_URL_PATH
        ) ?: $path;


    return strtolower(
        pathinfo(
            $path,
            PATHINFO_EXTENSION
        )
    );
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
| Objective labels
|--------------------------------------------------------------------------
*/

$objectiveLabels = [

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
| Beneficiary labels
|--------------------------------------------------------------------------
*/

$beneficiaryLabels = [

    'PUBLIC'
        => 'Public',

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

    header(
        'Location: /pbo-portal/applications/records.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Master application
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
            financial_year_start_month,
            financial_year_end_month,
            created_by,
            updated_by,
            submitted_by,
            created_at,
            updated_at,
            submitted_at
        FROM pbo_applications
        WHERE id = :application_id
          AND status = 'submitted'
        LIMIT 1
        "
    );


$statement->execute([
    ':application_id'
        => $applicationId,
]);


$application =
    $statement->fetch(
        PDO::FETCH_ASSOC
    );


if (!$application) {

    http_response_code(404);

    exit(
        'Application record not found.'
    );
}


/*
|--------------------------------------------------------------------------
| Records page should expose submitted applications
|--------------------------------------------------------------------------
*/

if (
    $application['status'] !==
    'submitted'
) {

    header(
        'Location: /pbo-portal/applications/review.php?id=' .
        urlencode(
            (string) $applicationId
        )
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Address
|--------------------------------------------------------------------------
*/

$statement =
    $connection->prepare(
        "
        SELECT
            county,
            sub_county,
            physical_address,
            postal_address,
            postal_code,
            phone_number,
            alternative_phone_number,
            email
        FROM application_addresses
        WHERE application_id =
            :application_id
        LIMIT 1
        "
    );


$statement->execute([
    ':application_id'
        => $applicationId,
]);


$address =
    $statement->fetch(
        PDO::FETCH_ASSOC
    );


if (!$address) {

    $address = [

        'county' => '',

        'sub_county' => '',

        'physical_address' => '',

        'postal_address' => '',

        'postal_code' => '',

        'phone_number' => '',

        'alternative_phone_number' => '',

        'email' => '',
    ];
}


/*
|--------------------------------------------------------------------------
| International
|--------------------------------------------------------------------------
*/

$internationalDetails = null;


if (
    $application['registration_type'] ===
    'international'
) {

    $statement =
        $connection->prepare(
            "
            SELECT
                parent_organization_name,
                country_of_origin,
                registration_number,
                parent_physical_address,
                parent_postal_address,
                parent_postal_code,
                parent_phone_number,
                parent_email,
                contact_person_name,
                contact_person_phone,
                contact_person_email
            FROM application_international_details
            WHERE application_id =
                :application_id
            LIMIT 1
            "
        );


    $statement->execute([
        ':application_id'
            => $applicationId,
    ]);


    $internationalDetails =
        $statement->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$internationalDetails) {

        $internationalDetails = [

            'parent_organization_name' => '',

            'country_of_origin' => '',

            'registration_number' => '',

            'parent_physical_address' => '',

            'parent_postal_address' => '',

            'parent_postal_code' => '',

            'parent_phone_number' => '',

            'parent_email' => '',

            'contact_person_name' => '',

            'contact_person_phone' => '',

            'contact_person_email' => '',
        ];
    }
}


/*
|--------------------------------------------------------------------------
| Objectives
|--------------------------------------------------------------------------
*/

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


$objectives =
    $statement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Beneficiaries
|--------------------------------------------------------------------------
*/

$statement =
    $connection->prepare(
        "
        SELECT
            beneficiary_code,
            description
        FROM application_beneficiaries
        WHERE application_id =
            :application_id
        ORDER BY id ASC
        "
    );


$statement->execute([
    ':application_id'
        => $applicationId,
]);


$beneficiaries =
    $statement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Officials
|--------------------------------------------------------------------------
*/

$statement =
    $connection->prepare(
        "
        SELECT
            id,
            full_name,
            designation,
            nationality,
            id_passport_number,
            kra_pin,
            phone_number,
            email,
            signature
        FROM application_officials
        WHERE application_id =
            :application_id
        ORDER BY
            CASE designation
                WHEN 'CHAIRPERSON' THEN 1
                WHEN 'TREASURER' THEN 2
                WHEN 'SECRETARY' THEN 3
                WHEN 'DIRECTOR' THEN 4
                ELSE 5
            END,
            id ASC
        "
    );


$statement->execute([
    ':application_id'
        => $applicationId,
]);


$officials =
    $statement->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Financial year
|--------------------------------------------------------------------------
*/

$startMonth =
    (int)
    ($application[
        'financial_year_start_month'
    ] ?? 0);


$endMonth =
    (int)
    ($application[
        'financial_year_end_month'
    ] ?? 0);


/*
|--------------------------------------------------------------------------
| Submitted date
|--------------------------------------------------------------------------
*/

$submittedDate = '';

if (
    !empty(
        $application['submitted_at']
    )
) {

    $timestamp =
        strtotime(
            (string)
            $application['submitted_at']
        );


    if ($timestamp !== false) {

        $submittedDate =
            date(
                'd F Y, H:i',
                $timestamp
            );
    }
}


/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/header.php';

?>


<style>

/* ==============================================================
   APPLICATION REPORT
   ============================================================== */

.application-report {
    max-width: 1200px;
    margin: 0 auto;
}


.report-cover {
    margin-bottom: 22px;
    overflow: hidden;

    background: #ffffff;

    border: 1px solid var(--border);
    border-radius: var(--radius-lg);

    box-shadow: var(--shadow);
}


.report-cover-top {
    padding: 26px 28px;

    background:
        linear-gradient(
            110deg,
            var(--blue-dark),
            var(--blue)
        );

    color: #ffffff;
}


.report-cover-top h1 {
    margin: 5px 0;

    color: #ffffff;
}


.report-cover-top p {
    margin: 0;

    color: rgba(255, 255, 255, .8);
}


.report-reference {
    display: inline-block;

    margin-top: 14px;
    padding: 7px 12px;

    background: rgba(255, 255, 255, .12);

    border: 1px solid rgba(255, 255, 255, .25);
    border-radius: 6px;

    color: #ffffff;

    font-size: 13px;
    font-weight: 800;

    letter-spacing: .4px;
}


.report-cover-bottom {
    display: grid;
    grid-template-columns:
        repeat(
            auto-fit,
            minmax(190px, 1fr)
        );

    gap: 0;

    border-top: 1px solid var(--border);
}


.report-meta {
    padding: 15px 18px;

    border-right: 1px solid var(--border);
}


.report-meta:last-child {
    border-right: 0;
}


.report-meta span {
    display: block;

    margin-bottom: 4px;

    color: var(--text-muted);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: .6px;
    text-transform: uppercase;
}


.report-meta strong {
    color: var(--heading);
}


.report-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 12px;

    margin-bottom: 18px;
}


.report-toolbar-actions {
    display: flex;
    gap: 8px;
}


.report-section {
    margin-bottom: 18px;
    overflow: hidden;

    background: #ffffff;

    border: 1px solid var(--border);
    border-radius: var(--radius);

    box-shadow: var(--shadow-sm);
}


.report-section-header {
    display: flex;
    align-items: center;

    gap: 12px;

    padding: 15px 18px;

    background:
        linear-gradient(
            100deg,
            var(--blue-light),
            #ffffff
        );

    border-bottom: 1px solid var(--border);
}


.report-section-number {
    display: flex;
    align-items: center;
    justify-content: center;

    flex: 0 0 auto;

    width: 32px;
    height: 32px;

    border-radius: 50%;

    background: var(--blue);

    color: #ffffff;

    font-size: 11px;
    font-weight: 800;
}


.report-section-header h2 {
    margin: 0;

    color: var(--blue-dark);

    font-size: 17px;
}


.report-section-body {
    padding: 20px;
}


.report-grid {
    display: grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(220px, 1fr)
        );

    gap: 14px;
}


.report-field {
    min-width: 0;

    padding: 12px 14px;

    background: var(--soft-bg);

    border: 1px solid var(--border);
    border-radius: var(--radius);
}


.report-field span {
    display: block;

    margin-bottom: 4px;

    color: var(--text-muted);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: .4px;
    text-transform: uppercase;
}


.report-field strong {
    display: block;

    overflow-wrap: anywhere;

    color: var(--heading);

    font-size: 12px;
}


.report-check-grid {
    display: grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(230px, 1fr)
        );

    gap: 9px;
}


.report-check {
    display: flex;
    align-items: flex-start;

    gap: 8px;

    padding: 10px 12px;

    background: var(--soft-bg);

    border: 1px solid var(--border);
    border-radius: var(--radius);

    font-size: 12px;
}


.report-check-mark {
    color: var(--green);

    font-weight: 900;
}


.official-report-card {
    margin-bottom: 17px;
    overflow: hidden;

    border: 1px solid var(--border);
    border-radius: var(--radius);
}


.official-report-card:last-child {
    margin-bottom: 0;
}


.official-report-header {
    padding: 12px 15px;

    background: var(--soft-bg);

    border-bottom: 1px solid var(--border);
}


.official-report-header span {
    display: block;

    color: var(--text-muted);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: .6px;
    text-transform: uppercase;
}


.official-report-header h3 {
    margin: 2px 0 0;

    color: var(--blue-dark);

    font-size: 16px;
}


.official-report-body {
    padding: 18px;
}


/* ==============================================================
   SIGNATURE DISPLAY
   ============================================================== */

.signature-panel {
    margin-top: 16px;
    padding: 14px;

    background:
        linear-gradient(
            135deg,
            #fafbfc,
            #ffffff
        );

    border: 1px solid var(--border);
    border-radius: var(--radius);
}


.signature-panel-label {
    display: block;

    margin-bottom: 10px;

    color: var(--text-muted);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: .6px;
    text-transform: uppercase;
}


.signature-preview {
    display: flex;
    align-items: center;
    justify-content: center;

    min-height: 110px;
    padding: 12px;

    background: #ffffff;

    border: 1px dashed #cbd5df;
    border-radius: 6px;
}


.signature-preview img {
    display: block;

    max-width: 280px;
    max-height: 110px;

    width: auto;
    height: auto;

    object-fit: contain;
}


.signature-pdf {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;

    width: 100%;
}


.signature-pdf-details strong {
    display: block;

    margin-bottom: 3px;

    color: var(--heading);
}


.signature-pdf-details span {
    color: var(--text-muted);

    font-size: 11px;
}


.signature-missing {
    color: var(--red);

    font-size: 11px;
    font-weight: 700;
}


/* ==============================================================
   OTHER DESCRIPTION
   ============================================================== */

.report-description {
    margin-top: 15px;
    padding: 15px 17px;

    background: #fffdf7;

    border: 1px solid #eadfbf;
    border-left: 4px solid var(--gold);
    border-radius: var(--radius);
}


.report-description span {
    display: block;

    margin-bottom: 4px;

    color: var(--text-muted);

    font-size: 9px;
    font-weight: 800;

    letter-spacing: .5px;
    text-transform: uppercase;
}


.report-description strong {
    color: var(--heading);
}


.report-description p {
    margin: 6px 0 0;

    color: var(--text-secondary);

    white-space: pre-wrap;
}


/* ==============================================================
   FINANCIAL YEAR
   ============================================================== */

.financial-year-report {
    display: flex;
    align-items: center;
    justify-content: center;

    gap: 22px;

    padding: 26px;

    text-align: center;

    background:
        linear-gradient(
            110deg,
            var(--blue-light),
            #ffffff
        );

    border: 1px solid #d2e0ec;
    border-radius: var(--radius);
}


.financial-year-month span {
    display: block;

    margin-bottom: 4px;

    color: var(--text-muted);

    font-size: 9px;
    font-weight: 800;

    text-transform: uppercase;
}


.financial-year-month strong {
    color: var(--blue-dark);

    font-size: 20px;
}


.financial-year-arrow {
    color: var(--gold);

    font-size: 26px;
    font-weight: 800;
}


/* ==============================================================
   REPORT FOOTER
   ============================================================== */

.report-document-footer {
    margin-top: 22px;
    padding: 18px 20px;

    background: #ffffff;

    border-top: 3px solid var(--blue);
    border-radius: var(--radius);

    box-shadow: var(--shadow-sm);
}


.report-document-footer-grid {
    display: grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(180px, 1fr)
        );

    gap: 15px;
}


.report-document-footer span {
    display: block;

    margin-bottom: 3px;

    color: var(--text-muted);

    font-size: 9px;
    font-weight: 800;

    text-transform: uppercase;
}


.report-document-footer strong {
    color: var(--heading);

    font-size: 11px;
}


/* ==============================================================
   STATUS BADGE
   ============================================================== */

.report-status {
    display: inline-flex;
    align-items: center;

    gap: 6px;

    padding: 5px 9px;

    border-radius: 20px;

    background: var(--green-light);

    color: var(--green-dark);

    font-size: 10px;
    font-weight: 800;

    text-transform: uppercase;
}


/* ==============================================================
   MOBILE
   ============================================================== */

@media (max-width: 700px) {

    .report-toolbar {
        align-items: stretch;
        flex-direction: column;
    }


    .report-toolbar-actions {
        width: 100%;
    }


    .report-toolbar-actions .btn {
        flex: 1;
    }


    .report-cover-top {
        padding: 21px;
    }


    .report-cover-bottom {
        grid-template-columns: 1fr;
    }


    .report-meta {
        border-right: 0;
        border-bottom: 1px solid var(--border);
    }


    .report-meta:last-child {
        border-bottom: 0;
    }


    .financial-year-report {
        flex-direction: column;

        gap: 10px;
    }


    .financial-year-arrow {
        transform: rotate(90deg);
    }
}


/* ==============================================================
   PRINT
   ============================================================== */

@media print {

    @page {
        size: A4;
        margin: 12mm;
    }


    body {
        background: #ffffff !important;
    }


    header,
    footer,
    nav,
    .sidebar,
    .topbar,
    .report-toolbar,
    .no-print {
        display: none !important;
    }


    .applications-page,
    .application-report {
        width: 100% !important;
        max-width: none !important;

        margin: 0 !important;
        padding: 0 !important;
    }


    .report-cover,
    .report-section,
    .official-report-card,
    .report-document-footer {
        box-shadow: none !important;

        break-inside: avoid;
    }


    .report-section {
        margin-bottom: 10px;
    }


    .official-report-card {
        break-inside: avoid;
    }


    .signature-preview {
        min-height: 80px;
    }


    .signature-preview img {
        max-height: 80px;
    }


    .btn {
        display: none !important;
    }


    a {
        color: inherit !important;
        text-decoration: none !important;
    }
}

</style>


<div class="applications-page">

    <div class="application-report">


        <!-- ======================================================
             TOOLBAR
             ====================================================== -->

        <div class="report-toolbar no-print">

            <a
                href="/pbo-portal/applications/records.php"
                class="btn btn-secondary"
            >
                &larr; Back to Records
            </a>


            <div class="report-toolbar-actions">

                <button
                    type="button"
                    class="btn btn-primary"
                    onclick="window.print();"
                >
                    Print / Save PDF
                </button>

            </div>

        </div>


        <!-- ======================================================
             REPORT COVER
             ====================================================== -->

        <section class="report-cover">

            <div class="report-cover-top">

                <span
                    style="
                        display: block;
                        font-size: 10px;
                        font-weight: 800;
                        letter-spacing: 1px;
                        text-transform: uppercase;
                        opacity: .8;
                    "
                >
                    Public Benefit Organizations Regulatory Authority
                </span>


                <h1>
                    PBO Registration Record
                </h1>


                <p>
                    Complete submitted application record
                </p>


                <div class="report-reference">

                    <?= e(
                        (string)
                        $application[
                            'application_reference'
                        ]
                    ) ?>

                </div>

            </div>


            <div class="report-cover-bottom">


                <div class="report-meta">

                    <span>
                        Organization
                    </span>

                    <strong>
                        <?= displayValue(
                            $application['pbo_name']
                        ) ?>
                    </strong>

                </div>


                <div class="report-meta">

                    <span>
                        Type
                    </span>

                    <strong>
                        <?= e(
                            organizationTypeLabel(
                                (string)
                                $application[
                                    'registration_type'
                                ]
                            )
                        ) ?>
                    </strong>

                </div>


                <div class="report-meta">

                    <span>
                        Status
                    </span>

                    <strong class="report-status">
                        &#10003; Submitted
                    </strong>

                </div>


                <div class="report-meta">

                    <span>
                        Submitted
                    </span>

                    <strong>
                        <?= $submittedDate !== ''
                            ? e($submittedDate)
                            : 'Not available' ?>
                    </strong>

                </div>

            </div>

        </section>


        <!-- ======================================================
             1. ORGANIZATION
             ====================================================== -->

        <section class="report-section">

            <div class="report-section-header">

                <div class="report-section-number">
                    1
                </div>

                <div>

                    <h2>
                        Organization Particulars
                    </h2>

                </div>

            </div>


            <div class="report-section-body">

                <div class="report-grid">


                    <div class="report-field">

                        <span>
                            Name of PBO
                        </span>

                        <strong>
                            <?= displayValue(
                                $application['pbo_name']
                            ) ?>
                        </strong>

                    </div>


                    <div class="report-field">

                        <span>
                            PBO Registration Number
                        </span>

                        <strong>
                            <?= displayValue(
                                $application[
                                    'pbo_registration_number'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="report-field">

                        <span>
                            Type of Organization
                        </span>

                        <strong>
                            <?= e(
                                organizationTypeLabel(
                                    (string)
                                    $application[
                                        'registration_type'
                                    ]
                                )
                            ) ?>
                        </strong>

                    </div>

                </div>

            </div>

        </section>


        <!-- ======================================================
             2. ADDRESS
             ====================================================== -->

        <section class="report-section">

            <div class="report-section-header">

                <div class="report-section-number">
                    2
                </div>

                <div>

                    <h2>
                        Organization Address
                    </h2>

                </div>

            </div>


            <div class="report-section-body">

                <div class="report-grid">


                    <div class="report-field">

                        <span>
                            County
                        </span>

                        <strong>
                            <?= displayValue(
                                $address['county']
                            ) ?>
                        </strong>

                    </div>


                    <div class="report-field">

                        <span>
                            Sub-County
                        </span>

                        <strong>
                            <?= displayValue(
                                $address['sub_county']
                            ) ?>
                        </strong>

                    </div>


                    <div class="report-field">

                        <span>
                            Physical Address
                        </span>

                        <strong>
                            <?= displayValue(
                                $address[
                                    'physical_address'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="report-field">

                        <span>
                            Postal Address
                        </span>

                        <strong>
                            <?= displayValue(
                                $address[
                                    'postal_address'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="report-field">

                        <span>
                            Postal Code
                        </span>

                        <strong>
                            <?= displayValue(
                                $address['postal_code']
                            ) ?>
                        </strong>

                    </div>


                    <div class="report-field">

                        <span>
                            Organization Phone
                        </span>

                        <strong>
                            <?= displayValue(
                                $address['phone_number']
                            ) ?>
                        </strong>

                    </div>


                    <div class="report-field">

                        <span>
                            Alternative Phone
                        </span>

                        <strong>
                            <?= displayValue(
                                $address[
                                    'alternative_phone_number'
                                ]
                            ) ?>
                        </strong>

                    </div>


                    <div class="report-field">

                        <span>
                            Email
                        </span>

                        <strong>
                            <?= displayValue(
                                $address['email']
                            ) ?>
                        </strong>

                    </div>

                </div>

            </div>

        </section>


        <!-- ======================================================
             INTERNATIONAL DETAILS
             ====================================================== -->

        <?php if (
            $application['registration_type'] ===
            'international' &&
            $internationalDetails !== null
        ): ?>

            <section class="report-section">

                <div class="report-section-header">

                    <div class="report-section-number">
                        3
                    </div>

                    <div>

                        <h2>
                            International Registration Status
                        </h2>

                    </div>

                </div>


                <div class="report-section-body">

                    <div class="report-grid">


                        <div class="report-field">

                            <span>
                                Parent Organization
                            </span>

                            <strong>
                                <?= displayValue(
                                    $internationalDetails[
                                        'parent_organization_name'
                                    ]
                                ) ?>
                            </strong>

                        </div>


                        <div class="report-field">

                            <span>
                                Country of Registration
                            </span>

                            <strong>
                                <?= displayValue(
                                    $internationalDetails[
                                        'country_of_origin'
                                    ]
                                ) ?>
                            </strong>

                        </div>


                        <div class="report-field">

                            <span>
                                PBO / Charity Registration Number
                            </span>

                            <strong>
                                <?= displayValue(
                                    $internationalDetails[
                                        'registration_number'
                                    ]
                                ) ?>
                            </strong>

                        </div>


                        <div class="report-field">

                            <span>
                                Parent Physical Address
                            </span>

                            <strong>
                                <?= displayValue(
                                    $internationalDetails[
                                        'parent_physical_address'
                                    ]
                                ) ?>
                            </strong>

                        </div>


                        <div class="report-field">

                            <span>
                                Parent Postal Address
                            </span>

                            <strong>
                                <?= displayValue(
                                    $internationalDetails[
                                        'parent_postal_address'
                                    ]
                                ) ?>
                            </strong>

                        </div>


                        <div class="report-field">

                            <span>
                                Parent Postal Code
                            </span>

                            <strong>
                                <?= displayValue(
                                    $internationalDetails[
                                        'parent_postal_code'
                                    ]
                                ) ?>
                            </strong>

                        </div>


                        <div class="report-field">

                            <span>
                                Parent Phone
                            </span>

                            <strong>
                                <?= displayValue(
                                    $internationalDetails[
                                        'parent_phone_number'
                                    ]
                                ) ?>
                            </strong>

                        </div>


                        <div class="report-field">

                            <span>
                                Parent Email
                            </span>

                            <strong>
                                <?= displayValue(
                                    $internationalDetails[
                                        'parent_email'
                                    ]
                                ) ?>
                            </strong>

                        </div>


                        <div class="report-field">

                            <span>
                                Contact Person
                            </span>

                            <strong>
                                <?= displayValue(
                                    $internationalDetails[
                                        'contact_person_name'
                                    ]
                                ) ?>
                            </strong>

                        </div>


                        <div class="report-field">

                            <span>
                                Contact Person Phone
                            </span>

                            <strong>
                                <?= displayValue(
                                    $internationalDetails[
                                        'contact_person_phone'
                                    ]
                                ) ?>
                            </strong>

                        </div>


                        <div class="report-field">

                            <span>
                                Contact Person Email
                            </span>

                            <strong>
                                <?= displayValue(
                                    $internationalDetails[
                                        'contact_person_email'
                                    ]
                                ) ?>
                            </strong>

                        </div>

                    </div>

                </div>

            </section>

        <?php endif; ?>


        <!-- ======================================================
             PUBLIC BENEFIT PURPOSES
             ====================================================== -->

        <section class="report-section">

            <div class="report-section-header">

                <div class="report-section-number">
                    <?= $application['registration_type'] ===
                        'international'
                        ? '4'
                        : '3' ?>
                </div>

                <div>

                    <h2>
                        Public Benefit Purposes
                    </h2>

                </div>

            </div>


            <div class="report-section-body">


                <?php if ($objectives !== []): ?>

                    <div class="report-check-grid">

                        <?php foreach (
                            $objectives
                            as $objective
                        ): ?>

                            <?php

                            $objectiveCode =
                                (string)
                                $objective[
                                    'objective_code'
                                ];


                            $objectiveLabel =
                                $objectiveLabels[
                                    $objectiveCode
                                ]
                                ??
                                ucwords(
                                    strtolower(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $objectiveCode
                                        )
                                    )
                                );

                            ?>


                            <div class="report-check">

                                <span class="report-check-mark">
                                    &#10003;
                                </span>

                                <span>
                                    <?= e(
                                        $objectiveLabel
                                    ) ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    </div>


                    <?php foreach (
                        $objectives
                        as $objective
                    ): ?>

                        <?php if (
                            $objective[
                                'objective_code'
                            ] === 'OTHER'
                        ): ?>

                            <div class="report-description">

                                <span>
                                    Any Other — Description
                                </span>

                                <p>
                                    <?= displayValue(
                                        $objective[
                                            'description'                                    ]
                                ) ?>
                            </p>

                        </div>

                    <?php endif; ?>

                <?php endforeach; ?>


            <?php else: ?>

                <p class="text-muted">
                    No public benefit purposes recorded.
                </p>

            <?php endif; ?>

        </div>

    </section>


    <!-- ======================================================
         BENEFICIARIES
         ====================================================== -->

    <section class="report-section">

        <div class="report-section-header">

            <div class="report-section-number">
                <?= $application['registration_type'] ===
                    'international'
                    ? '5'
                    : '4' ?>
            </div>

            <div>

                <h2>
                    Beneficiaries
                </h2>

            </div>

        </div>


        <div class="report-section-body">

            <?php if ($beneficiaries !== []): ?>


                <?php

                $isPublic = false;

                foreach (
                    $beneficiaries
                    as $beneficiary
                ) {

                    if (
                        $beneficiary[
                            'beneficiary_code'
                        ] === 'PUBLIC'
                    ) {

                        $isPublic = true;

                        break;
                    }
                }

                ?>


                <?php if ($isPublic): ?>

                    <div
                        style="
                            padding: 17px 19px;
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
                                margin-bottom: 4px;
                                color: var(--text-muted);
                                font-size: 9px;
                                font-weight: 800;
                                letter-spacing: .5px;
                                text-transform: uppercase;
                            "
                        >
                            Beneficiary Category
                        </span>

                        <strong
                            style="
                                color: var(--blue-dark);
                                font-size: 16px;
                            "
                        >
                            Public
                        </strong>

                    </div>


                <?php else: ?>

                    <div
                        style="
                            margin-bottom: 15px;
                        "
                    >

                        <span
                            style="
                                display: block;
                                margin-bottom: 4px;
                                color: var(--text-muted);
                                font-size: 9px;
                                font-weight: 800;
                                letter-spacing: .5px;
                                text-transform: uppercase;
                            "
                        >
                            Beneficiary Category
                        </span>

                        <strong
                            style="
                                color: var(--blue-dark);
                            "
                        >
                            Section of the Public
                        </strong>

                    </div>


                    <div class="report-check-grid">

                        <?php foreach (
                            $beneficiaries
                            as $beneficiary
                        ): ?>

                            <?php

                            $beneficiaryCode =
                                (string)
                                $beneficiary[
                                    'beneficiary_code'
                                ];


                            if (
                                $beneficiaryCode ===
                                'PUBLIC'
                            ) {
                                continue;
                            }


                            $beneficiaryLabel =
                                $beneficiaryLabels[
                                    $beneficiaryCode
                                ]
                                ??
                                ucwords(
                                    strtolower(
                                        str_replace(
                                            '_',
                                            ' ',
                                            $beneficiaryCode
                                        )
                                    )
                                );

                            ?>


                            <div class="report-check">

                                <span class="report-check-mark">
                                    &#10003;
                                </span>

                                <span>
                                    <?= e(
                                        $beneficiaryLabel
                                    ) ?>
                                </span>

                            </div>

                        <?php endforeach; ?>

                    </div>


                    <!-- ==========================================
                         OTHER BENEFICIARY
                         ========================================== -->

                    <?php foreach (
                        $beneficiaries
                        as $beneficiary
                    ): ?>

                        <?php if (
                            (string)
                            $beneficiary[
                                'beneficiary_code'
                            ] === 'OTHER'
                        ): ?>

                            <?php

                            $otherSpecify = '';

                            $otherDescription = '';


                            $storedOther =
                                trim(
                                    (string)
                                    (
                                        $beneficiary[
                                            'description'
                                        ]
                                        ?? ''
                                    )
                                );


                            if ($storedOther !== '') {

                                $decodedOther =
                                    json_decode(
                                        $storedOther,
                                        true
                                    );


                                if (
                                    is_array(
                                        $decodedOther
                                    )
                                ) {

                                    $otherSpecify =
                                        trim(
                                            (string)
                                            (
                                                $decodedOther[
                                                    'specify'
                                                ]
                                                ?? ''
                                            )
                                        );


                                    $otherDescription =
                                        trim(
                                            (string)
                                            (
                                                $decodedOther[
                                                    'description'
                                                ]
                                                ?? ''
                                            )
                                        );

                                } else {

                                    /*
                                    | Compatibility with older
                                    | plain-text records.
                                    */

                                    $otherDescription =
                                        $storedOther;
                                }
                            }

                            ?>


                            <div class="report-description">

                                <span>
                                    Others Not Listed Here
                                </span>


                                <div
                                    class="report-grid"
                                    style="margin-top: 10px;"
                                >

                                    <div class="report-field">

                                        <span>
                                            Specify
                                        </span>

                                        <strong>
                                            <?= displayValue(
                                                $otherSpecify
                                            ) ?>
                                        </strong>

                                    </div>


                                    <div class="report-field">

                                        <span>
                                            Description
                                        </span>

                                        <strong
                                            style="
                                                white-space: pre-wrap;
                                            "
                                        >
                                            <?= displayValue(
                                                $otherDescription
                                            ) ?>
                                        </strong>

                                    </div>

                                </div>

                            </div>

                        <?php endif; ?>

                    <?php endforeach; ?>

                <?php endif; ?>


            <?php else: ?>

                <p class="text-muted">
                    No beneficiary information recorded.
                </p>

            <?php endif; ?>

        </div>

    </section>


    <!-- ======================================================
         FINANCIAL YEAR
         ====================================================== -->

    <section class="report-section">

        <div class="report-section-header">

            <div class="report-section-number">
                <?= $application['registration_type'] ===
                    'international'
                    ? '6'
                    : '5' ?>
            </div>

            <div>

                <h2>
                    Financial Year
                </h2>

            </div>

        </div>


        <div class="report-section-body">


            <?php if (
                isset(
                    $months[$startMonth]
                ) &&
                isset(
                    $months[$endMonth]
                )
            ): ?>

                <div class="financial-year-report">


                    <div class="financial-year-month">

                        <span>
                            Financial Year Starts
                        </span>

                        <strong>
                            <?= e(
                                $months[$startMonth]
                            ) ?>
                        </strong>

                    </div>


                    <div class="financial-year-arrow">
                        &rarr;
                    </div>


                    <div class="financial-year-month">

                        <span>
                            Financial Year Ends
                        </span>

                        <strong>
                            <?= e(
                                $months[$endMonth]
                            ) ?>
                        </strong>

                    </div>


                </div>


            <?php else: ?>

                <p class="text-muted">
                    Financial year information is not available.
                </p>

            <?php endif; ?>


        </div>

    </section>


    <!-- ======================================================
         OFFICIALS AND DIRECTORS
         ====================================================== -->

    <section class="report-section">

        <div class="report-section-header">

            <div class="report-section-number">
                <?= $application['registration_type'] ===
                    'international'
                    ? '7'
                    : '6' ?>
            </div>

            <div>

                <h2>
                    Officials and Directors
                </h2>

            </div>

        </div>


        <div class="report-section-body">


            <?php if ($officials !== []): ?>

                <div
                    style="
                        margin-bottom: 16px;
                        padding: 12px 15px;
                        background: var(--blue-light);
                        border: 1px solid #d2e0ec;
                        border-radius: var(--radius);
                        color: var(--blue-dark);
                        font-size: 11px;
                    "
                >
                    Total officials/directors:

                    <strong>
                        <?= count($officials) ?>
                    </strong>
                </div>


                <?php foreach (
                    $officials
                    as $officialIndex => $official
                ): ?>

                    <?php

                    $designation =
                        designationLabel(
                            (string)
                            $official[
                                'designation'
                            ]
                        );


                    $signature =
                        trim(
                            (string)
                            (
                                $official[
                                    'signature'
                                ]
                                ?? ''
                            )
                        );


                    $signatureExt =
                        $signature !== ''
                            ? signatureExtension(
                                $signature
                            )
                            : '';


                    $imageSignature =
                        in_array(
                            $signatureExt,
                            [
                                'jpg',
                                'jpeg',
                                'png',
                                'webp',
                            ],
                            true
                        );


                    $pdfSignature =
                        $signatureExt ===
                        'pdf';

                    ?>


                    <article class="official-report-card">


                        <!-- ==============================
                             OFFICIAL HEADER
                             ============================== -->

                        <div class="official-report-header">

                            <span>
                                Official / Director
                            </span>

                            <h3>
                                <?= e($designation) ?>

                                <?php if (
                                    strtoupper(
                                        (string)
                                        $official[
                                            'designation'
                                        ]
                                    ) ===
                                    'DIRECTOR'
                                ): ?>

                                    <?= ' ' .
                                        (
                                            $officialIndex -
                                            2
                                        ) ?>

                                <?php endif; ?>

                            </h3>

                        </div>


                        <!-- ==============================
                             OFFICIAL BODY
                             ============================== -->

                        <div class="official-report-body">

                            <div class="report-grid">


                                <div class="report-field">

                                    <span>
                                        Name
                                    </span>

                                    <strong>
                                        <?= displayValue(
                                            $official[
                                                'full_name'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>


                                <div class="report-field">

                                    <span>
                                        Nationality
                                    </span>

                                    <strong>
                                        <?= displayValue(
                                            $official[
                                                'nationality'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>


                                <div class="report-field">

                                    <span>
                                        ID / Passport Number
                                    </span>

                                    <strong>
                                        <?= displayValue(
                                            $official[
                                                'id_passport_number'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>


                                <div class="report-field">

                                    <span>
                                        KRA PIN
                                    </span>

                                    <strong>
                                        <?= displayValue(
                                            $official[
                                                'kra_pin'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>


                                <div class="report-field">

                                    <span>
                                        Phone Number
                                    </span>

                                    <strong>
                                        <?= displayValue(
                                            $official[
                                                'phone_number'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>


                                <div class="report-field">

                                    <span>
                                        Email
                                    </span>

                                    <strong>
                                        <?= displayValue(
                                            $official[
                                                'email'
                                            ]
                                        ) ?>
                                    </strong>

                                </div>

                            </div>


                            <!-- ==============================
                                 SIGNATURE
                                 ============================== -->

                            <div class="signature-panel">

                                <span class="signature-panel-label">
                                    Signature
                                </span>


                                <?php if (
                                    $signature === ''
                                ): ?>

                                    <div class="signature-preview">

                                        <span class="signature-missing">
                                            Signature not available
                                        </span>

                                    </div>


                                <?php elseif (
                                    $imageSignature
                                ): ?>

                                    <div class="signature-preview">

                                        <img
                                            src="<?= e(
                                                $signature
                                            ) ?>"
                                            alt="<?= e(
                                                (string)
                                                $official[
                                                    'full_name'
                                                ]
                                            ) ?> signature"
                                            loading="lazy"
                                        >

                                    </div>


                                <?php elseif (
                                    $pdfSignature
                                ): ?>

                                    <div class="signature-preview">

                                        <div class="signature-pdf">


                                            <div class="signature-pdf-details">

                                                <strong>
                                                    Signature Document
                                                </strong>

                                                <span>
                                                    PDF signature file
                                                </span>

                                            </div>


                                            <a
                                                href="<?= e(
                                                    $signature
                                                ) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="btn btn-secondary btn-sm no-print"
                                            >
                                                View PDF
                                            </a>


                                            <span
                                                class="print-only"
                                                style="
                                                    display: none;
                                                "
                                            >
                                                PDF signature document attached
                                            </span>

                                        </div>

                                    </div>


                                <?php else: ?>

                                    <div class="signature-preview">

                                        <div class="signature-pdf">

                                            <div class="signature-pdf-details">

                                                <strong>
                                                    Signature File
                                                </strong>

                                                <span>
                                                    Stored signature document
                                                </span>

                                            </div>


                                            <a
                                                href="<?= e(
                                                    $signature
                                                ) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="btn btn-secondary btn-sm no-print"
                                            >
                                                Open File
                                            </a>

                                        </div>

                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>


            <?php else: ?>

                <p class="text-muted">
                    No officials or directors are recorded.
                </p>

            <?php endif; ?>

        </div>

    </section>


    <!-- ======================================================
         RECORD INFORMATION
         ====================================================== -->

    <section class="report-document-footer">

        <div class="report-document-footer-grid">


            <div>

                <span>
                    Application Reference
                </span>

                <strong>
                    <?= displayValue(
                        $application[
                            'application_reference'
                        ]
                    ) ?>
                </strong>

            </div>


            <div>

                <span>
                    Record Status
                </span>

                <strong>
                    Submitted
                </strong>

            </div>


            <div>

                <span>
                    Submitted Date
                </span>

                <strong>
                    <?= $submittedDate !== ''
                        ? e($submittedDate)
                        : 'Not available' ?>
                </strong>

            </div>


            <div>

                <span>
                    Organization
                </span>

                <strong>
                    <?= displayValue(
                        $application[
                            'pbo_name'
                        ]
                    ) ?>
                </strong>

            </div>

        </div>


        <div
            style="
                margin-top: 16px;
                padding-top: 13px;
                border-top: 1px solid var(--border);
                color: var(--text-muted);
                font-size: 10px;
                line-height: 1.6;
            "
        >
            This page presents the information recorded in the
            PBO Registration System for this submitted application.
        </div>

    </section>


    <!-- ======================================================
         BOTTOM ACTIONS
         ====================================================== -->

    <div
        class="report-toolbar no-print"
        style="
            margin-top: 20px;
            margin-bottom: 0;
        "
    >

        <a
            href="/pbo-portal/applications/records.php"
            class="btn btn-secondary"
        >
            &larr; Back to Records
        </a>


        <button
            type="button"
            class="btn btn-primary"
            onclick="window.print();"
        >
            Print / Save PDF
        </button>

    </div>


</div>
<!-- END .application-report -->

</div>
<!-- END .applications-page -->


<?php

/*
|--------------------------------------------------------------------------
| Shared footer
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../includes/footer.php';

?>