<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/application-validation.php';
require_once __DIR__ . '/../includes/kenya-locations.php';
require_once __DIR__ . '/../includes/text-case.php';
require_once __DIR__ . '/../includes/organization-reach.php';

requireLogin();
requirePasswordChanged();

$pageTitle = 'PBO Application';
$activePage = 'applications';

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

$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    header('Location: /pbo-portal/login.php');
    exit;
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

function old(array $form, string $field): string
{
    return e((string) ($form[$field] ?? ''));
}

function slugifyCounty(string $county): string
{
    $slug = strtolower(trim($county));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

    return trim($slug, '-');
}

function normalizeCountyName(string $county): string
{
    $county = trim($county);
    $county = preg_replace('/\s+/', ' ', $county) ?? '';
    $county = strtolower($county);
    $county = preg_replace('/\s+county$/', '', $county) ?? $county;
    $county = preg_replace('/\s+city$/', '', $county) ?? $county; // "Nairobi City" -> "Nairobi"
    $county = preg_replace('/[^a-z0-9]+/', '', $county) ?? $county;

    return $county;
}

/**
 * Empty strings become NULL (for optional columns).
 */
function nullIfEmpty(string $value): ?string
{
    return $value !== '' ? $value : null;
}

/*
|--------------------------------------------------------------------------
| Kenyan counties
|--------------------------------------------------------------------------
*/

$countyOptions = kenyaCounties($connection);

/*
| The 47 names now come from includes/kenya-locations.php, which reads
| the kenya_counties table and falls back to the original hardcoded
| list if the lookup migration has not been run yet. edit.php, view.php,
| drafts.php and export-excel.php can use the same helper instead of
| each keeping a copy.
*/

$countyMap = [];

foreach ($countyOptions as $county) {
    $countyMap[slugifyCounty($county)] = $county;
}

/*
|--------------------------------------------------------------------------
| Form
|--------------------------------------------------------------------------
*/

/*
| Areas of operation outside Kenya - open to national organizations too,
| because a Kenyan organization running a programme abroad is an ordinary
| thing the register previously had nowhere to record.
*/
$foreignLocations = [];
$operatesAbroad = 'no';

$form = [
    'pbo_name' => '',
    'pbo_registration_number' => '',
    'registration_date' => '',
    'registration_type' => '',

    /*
    | '1' head office in Kenya, '0' abroad, '' not answered. Only asked of
    | an international organization; a national one is always in Kenya.
    */
    'headquarters_in_kenya' => '',

    'county' => '',
    'city_town' => '',
    'sub_county' => '',
    'physical_address' => '',
    'postal_address' => '',
    'postal_code' => '',
    'phone_number' => '',
    'alternative_phone_number' => '',
    'email' => '',

    'registration_status' => '',
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

$operatingLocationFieldNames = [
    'sub_county',
    'physical_address',
    'postal_address',
    'postal_code',
    'phone_number',
    'alternative_phone_number',
    'email',
];

$operatingLocations = [];
$operatesOtherLocations = 'no';

$errors = [];

/*
| Things worth telling the staff member about that are NOT reasons to
| refuse the save. Carried across the redirect in the session, because a
| save ends in a redirect and anything held in a variable is gone by then.
*/
$warnings = [];

if (!empty($_SESSION['application_warnings'])) {
    $warnings = (array) $_SESSION['application_warnings'];
    unset($_SESSION['application_warnings']);
}

/*
| Things still missing before the application can be SUBMITTED.
| Shown as information only; it never blocks saving or navigation.
*/
$submissionGaps = [];

$applicationId = isset($_GET['id'])
    ? (int) $_GET['id']
    : (int) ($_POST['id'] ?? 0);

$isEdit = false;

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
| Generate application reference
|--------------------------------------------------------------------------
*/

function generateApplicationReference(PDO $connection): string
{
    $year = (int) date('Y');

    $statement = $connection->prepare(
        "
        INSERT INTO application_reference_sequences
            (reference_year, last_number)
        VALUES
            (:reference_year, 0)
        ON DUPLICATE KEY UPDATE
            reference_year = VALUES(reference_year)
        "
    );

    $statement->execute([':reference_year' => $year]);

    $statement = $connection->prepare(
        "
        SELECT last_number
        FROM application_reference_sequences
        WHERE reference_year = :reference_year
        FOR UPDATE
        "
    );

    $statement->execute([':reference_year' => $year]);

    $nextNumber = (int) $statement->fetchColumn() + 1;

    $statement = $connection->prepare(
        "
        UPDATE application_reference_sequences
        SET last_number = :last_number
        WHERE reference_year = :reference_year
        "
    );

    $statement->execute([
        ':last_number' => $nextNumber,
        ':reference_year' => $year,
    ]);

    return 'PBO.REG.' . $year . '.'
        . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
}

/*
|--------------------------------------------------------------------------
| Load existing application
|--------------------------------------------------------------------------
|
| FIX: headquarters and international details are read with
| ORDER BY id DESC so the most recently saved row is always used.
| Previously "LIMIT 1" without ORDER BY could return an older, empty row,
| which made the page look as if everything had been erased.
|
*/

if ($applicationId > 0) {

    $statement = $connection->prepare(
        "
        SELECT *
        FROM pbo_applications
        WHERE id = :id
          AND created_by = :created_by
        LIMIT 1
        "
    );

    $statement->execute([
        ':id' => $applicationId,
        ':created_by' => $userId,
    ]);

    $application = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$application) {
        http_response_code(404);
        exit('Application not found.');
    }

    if (($application['status'] ?? '') !== 'draft') {

        $_SESSION['flash_error'] = 'Only draft applications can be edited.';

        header(
            'Location: /pbo-portal/applications/view.php?id='
            . urlencode((string) $applicationId)
        );

        exit;
    }

    $isEdit = true;

    foreach ($form as $field => $defaultValue) {
        if (array_key_exists($field, $application)) {
            $form[$field] = (string) ($application[$field] ?? '');
        }
    }

    /*
    | Headquarters / head office address
    */

    $statement = $connection->prepare(
        "
        SELECT
            county,
            city_town,
            sub_county,
            physical_address,
            postal_address,
            postal_code,
            phone_number,
            alternative_phone_number,
            email
        FROM application_addresses
        WHERE application_id = :application_id
        ORDER BY id DESC
        LIMIT 1
        "
    );

    $statement->execute([':application_id' => $applicationId]);

    $address = $statement->fetch(PDO::FETCH_ASSOC);

    if ($address) {
        foreach ($address as $field => $value) {
            if (array_key_exists($field, $form)) {
                $form[$field] = (string) ($value ?? '');
            }
        }
    }

    /*
    | International / parent organization details
    */

    $statement = $connection->prepare(
        "
        SELECT
            registration_status,
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
        WHERE application_id = :application_id
        ORDER BY id DESC
        LIMIT 1
        "
    );

    $statement->execute([':application_id' => $applicationId]);

    $internationalDetails = $statement->fetch(PDO::FETCH_ASSOC);

    if ($internationalDetails) {
        foreach ($internationalDetails as $field => $value) {
            if (array_key_exists($field, $form)) {
                $form[$field] = (string) ($value ?? '');
            }
        }
    }

    /*
    | Other areas of operation (national AND international)
    | Ordered oldest -> newest so the newest row wins for each county.
    */

    $statement = $connection->prepare(
        "
        SELECT
            county,
            sub_county,
            physical_address,
            phone_number,
            alternative_phone_number,
            email,
            postal_address,
            postal_code
        FROM application_operating_locations
        WHERE application_id = :application_id
        ORDER BY id ASC
        "
    );

    $statement->execute([':application_id' => $applicationId]);

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $location) {

        $county = (string) ($location['county'] ?? '');
        $slug = slugifyCounty($county);

        if ($slug === '') {
            continue;
        }

        $operatingLocations[$slug] = ['county' => $county];

        foreach ($operatingLocationFieldNames as $fieldName) {
            $operatingLocations[$slug][$fieldName] =
                (string) ($location[$fieldName] ?? '');
        }
    }

    if (count($operatingLocations) > 0) {
        $operatesOtherLocations = 'yes';
    }

    /*
    | Areas of operation outside Kenya. Returns an empty list on a server
    | where the migration has not been run, so the page still works.
    */
    $foreignLocations = pboForeignLocations($connection, $applicationId);

    if (count($foreignLocations) > 0) {
        $operatesAbroad = 'yes';
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $submissionGaps = pboOrganizationSectionErrors(
            $connection,
            $applicationId
        );
    }
}

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

$formAction = 'save_continue';
$isWizardNavigation = false;
$wizardDestination = 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = 'Invalid security token. Please reload the page and try again.';
    }

    $formAction = (string) ($_POST['form_action'] ?? 'save_continue');

    $isWizardNavigation = $formAction === 'wizard_navigation';

    $wizardDestination = max(
        1,
        min(6, (int) ($_POST['wizard_destination'] ?? 1))
    );

    /*
    | Main form
    */

    /*
    | The register is kept in block capitals. Staff type normally and
    | pboStoredCase() raises it - except for email addresses, which are
    | lower-cased instead; see includes/text-case.php for why.
    */
    foreach ($form as $field => $defaultValue) {
        if (array_key_exists($field, $_POST)) {
            $form[$field] = pboStoredCase($field, $_POST[$field]);
        }
    }

    /*
    | Other areas of operation
    |
    | The ticked county checkboxes decide which counties are saved.
    | Office details for each county are OPTIONAL: a county with an empty
    | card is still saved as an area of operation.
    */

    $operatesOtherLocations =
        (string) ($_POST['operates_other_locations'] ?? 'no') === 'yes'
            ? 'yes'
            : 'no';

    $selectedCountySlugs = $_POST['operating_county_selection'] ?? [];
    $postedLocationDetails = $_POST['operating_locations'] ?? [];

    if (!is_array($selectedCountySlugs)) {
        $selectedCountySlugs = [];
    }

    if (!is_array($postedLocationDetails)) {
        $postedLocationDetails = [];
    }

    $operatingLocations = [];

    if ($operatesOtherLocations === 'yes') {

        $headOfficeCounty = normalizeCountyName($form['county']);

        foreach ($selectedCountySlugs as $countySlug) {

            if (!is_string($countySlug) || !isset($countyMap[$countySlug])) {
                continue;
            }

            $countyName = $countyMap[$countySlug];

            // The head office county is not also an "other" area.
            if (
                $headOfficeCounty !== ''
                && normalizeCountyName($countyName) === $headOfficeCounty
            ) {
                continue;
            }

            $details = $postedLocationDetails[$countySlug] ?? [];

            if (!is_array($details)) {
                $details = [];
            }

            $operatingLocations[$countySlug] = [
                'county' => pboBlockCaps($countyName),
            ];

            foreach ($operatingLocationFieldNames as $fieldName) {
                $operatingLocations[$countySlug][$fieldName] =
                    pboStoredCase($fieldName, $details[$fieldName] ?? '');
            }
        }

        if (count($operatingLocations) === 0) {
            $operatesOtherLocations = 'no';
        }
    }

    /*
    | Areas of operation outside Kenya. Rows with no country named are
    | dropped: an empty card somebody added and thought better of is not
    | a mistake worth reporting.
    */
    $operatesAbroad = ($_POST['operates_abroad'] ?? 'no') === 'yes' ? 'yes' : 'no';

    $foreignLocations = $operatesAbroad === 'yes'
        ? pboNormaliseForeignLocations($_POST['foreign_locations'] ?? [])
        : [];

    if ($foreignLocations === []) {
        $operatesAbroad = 'no';
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    |
    | Saving and moving between pages only needs the four draft fields
    | (so empty drafts are never created). Everything else is checked
    | at final submission (see includes/application-validation.php).
    |
    | The only other check: an email that was typed must look like an email.
    |
    */

    if ($form['pbo_name'] === '') {
        $errors[] = 'Name of the Public Benefit Organization is required.';
    }

    if ($form['pbo_registration_number'] === '') {
        $errors[] = 'PBO Registration Number is required.';
    }

    if ($form['registration_date'] === '') {
        $errors[] = 'Registration Date is required.';
    } else {
        $dateError = pboRegistrationDateError($form['registration_date']);

        if ($dateError !== null) {
            $errors[] = $dateError;
        }
    }

    if (!in_array($form['registration_type'], ['national', 'international'], true)) {
        $errors[] = 'Please select the Type of Organization.';
    }

    /*
    | An international organization has to say where its head office is,
    | because the answer decides which card it fills in. A national one is
    | in Kenya by definition, so the question is not asked and the stored
    | answer is set rather than left to drift.
    */
    if ($form['registration_type'] === 'national') {
        $form['headquarters_in_kenya'] = '1';
    } elseif (
        $form['registration_type'] === 'international'
        && !in_array($form['headquarters_in_kenya'], ['0', '1'], true)
    ) {
        $errors[] = 'Say whether the head office is in Kenya.';
    }

    /*
    |--------------------------------------------------------------------------
    | Format problems that must not cost somebody their work
    |--------------------------------------------------------------------------
    |
    | These used to go into $errors, and the save below runs only when
    | $errors is empty - so one mistyped email in one county card threw away
    | everything else on the page, including the parts that were correct.
    | Staff reported moving to the next step and finding the rest of their
    | work gone, which is exactly this.
    |
    | A badly formatted email is not a reason to discard an address. These
    | are collected separately: the page still complains about them, and
    | still refuses to SUBMIT the application (see
    | pboOrganizationSectionErrors), but the draft is saved either way.
    |
    */

    $warnings = [];

    $emailFields = [
        'email' => 'Head Office Email of the Organization',
        'parent_email' => 'Email',
        'contact_person_email' => 'Contact Person Email',
    ];

    foreach ($emailFields as $field => $label) {
        if (
            $form[$field] !== ''
            && filter_var($form[$field], FILTER_VALIDATE_EMAIL) === false
        ) {
            $warnings[] = $label . ' does not look like an email address. '
                . 'It has been saved as typed - correct it before submitting.';
        }
    }

    foreach (pboForeignLocationErrors($foreignLocations) as $foreignWarning) {
        $warnings[] = $foreignWarning
            . ' It has been saved as typed - correct it before submitting.';
    }

    foreach ($operatingLocations as $location) {
        if (
            $location['email'] !== ''
            && filter_var($location['email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            $warnings[] = $location['county']
                . ': the organization email does not look like an email address. '
                . 'It has been saved as typed - correct it before submitting.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Save
    |--------------------------------------------------------------------------
    |
    | FIX: child tables are saved with "delete then insert" inside the
    | transaction. This works whether or not the tables have a UNIQUE key
    | on application_id, so a save can no longer create duplicate rows.
    |
    */

    if (count($errors) === 0) {

        /*
        | Written only when the migration has added the column, so the
        | page keeps working on a server that has the code and not yet
        | the database change.
        */
        $hasHeadquartersColumn = pboHeadquartersColumnAvailable($connection);

        try {

            $connection->beginTransaction();

            $targetStep = $isWizardNavigation ? $wizardDestination : 2;

            if (!$isEdit) {

                $referenceNumber = generateApplicationReference($connection);

                $statement = $connection->prepare(
                    "
                    INSERT INTO pbo_applications
                    (
                        application_reference,
                        pbo_name,
                        pbo_registration_number,
                        registration_date,
                        registration_type,
                        " . ($hasHeadquartersColumn ? 'headquarters_in_kenya,' : '') . "
                        status,
                        current_step,
                        created_by,
                        updated_by,
                        created_at,
                        updated_at
                    )
                    VALUES
                    (
                        :application_reference,
                        :pbo_name,
                        :pbo_registration_number,
                        :registration_date,
                        :registration_type,
                        " . ($hasHeadquartersColumn ? ':headquarters_in_kenya,' : '') . "
                        'draft',
                        :current_step,
                        :created_by,
                        :updated_by,
                        NOW(),
                        NOW()
                    )
                    "
                );

                $parameters = [
                    ':application_reference' => $referenceNumber,
                    ':pbo_name' => $form['pbo_name'],
                    ':pbo_registration_number' => $form['pbo_registration_number'],
                    ':registration_date' => $form['registration_date'],
                    ':registration_type' => $form['registration_type'],
                    ':current_step' => $targetStep,
                    ':created_by' => $userId,
                    ':updated_by' => $userId,
                ];

                if ($hasHeadquartersColumn) {
                    $parameters[':headquarters_in_kenya'] =
                        $form['headquarters_in_kenya'] !== ''
                            ? (int) $form['headquarters_in_kenya']
                            : null;
                }

                $statement->execute($parameters);

                $applicationId = (int) $connection->lastInsertId();

            } else {

                $statement = $connection->prepare(
                    "
                    SELECT id, status
                    FROM pbo_applications
                    WHERE id = :id
                      AND created_by = :created_by
                    FOR UPDATE
                    "
                );

                $statement->execute([
                    ':id' => $applicationId,
                    ':created_by' => $userId,
                ]);

                $lockedApplication = $statement->fetch(PDO::FETCH_ASSOC);

                if (
                    !$lockedApplication
                    || ($lockedApplication['status'] ?? '') !== 'draft'
                ) {
                    throw new RuntimeException(
                        'This application can no longer be edited.'
                    );
                }

                $statement = $connection->prepare(
                    "
                    UPDATE pbo_applications
                    SET
                        pbo_name = :pbo_name,
                        pbo_registration_number = :pbo_registration_number,
                        registration_date = :registration_date,
                        registration_type = :registration_type,
                        " . ($hasHeadquartersColumn ? 'headquarters_in_kenya = :headquarters_in_kenya,' : '') . "
                        current_step = GREATEST(COALESCE(current_step, 1), :current_step),
                        updated_by = :updated_by,
                        updated_at = NOW()
                    WHERE id = :id
                      AND created_by = :created_by
                    "
                );

                $parameters = [
                    ':pbo_name' => $form['pbo_name'],
                    ':pbo_registration_number' => $form['pbo_registration_number'],
                    ':registration_date' => $form['registration_date'],
                    ':registration_type' => $form['registration_type'],
                    ':current_step' => $targetStep,
                    ':updated_by' => $userId,
                    ':id' => $applicationId,
                    ':created_by' => $userId,
                ];

                if ($hasHeadquartersColumn) {
                    $parameters[':headquarters_in_kenya'] =
                        $form['headquarters_in_kenya'] !== ''
                            ? (int) $form['headquarters_in_kenya']
                            : null;
                }

                $statement->execute($parameters);
            }

            /*
            | Headquarters address (one row per application)
            */

            $statement = $connection->prepare(
                "DELETE FROM application_addresses WHERE application_id = :application_id"
            );

            $statement->execute([':application_id' => $applicationId]);

            $statement = $connection->prepare(
                "
                INSERT INTO application_addresses
                (
                    application_id,
                    county,
                    city_town,
                    sub_county,
                    physical_address,
                    postal_address,
                    postal_code,
                    phone_number,
                    alternative_phone_number,
                    email
                )
                VALUES
                (
                    :application_id,
                    :county,
                    :city_town,
                    :sub_county,
                    :physical_address,
                    :postal_address,
                    :postal_code,
                    :phone_number,
                    :alternative_phone_number,
                    :email
                )
                "
            );

            $statement->execute([
                ':application_id' => $applicationId,
                ':county' => $form['county'],
                ':city_town' => nullIfEmpty($form['city_town']),
                ':sub_county' => nullIfEmpty($form['sub_county']),
                ':physical_address' => $form['physical_address'],
                ':postal_address' => $form['postal_address'],
                ':postal_code' => nullIfEmpty($form['postal_code']),
                ':phone_number' => $form['phone_number'],
                ':alternative_phone_number' => nullIfEmpty($form['alternative_phone_number']),
                ':email' => $form['email'],
            ]);

            /*
            | International / parent organization details
            */

            $statement = $connection->prepare(
                "DELETE FROM application_international_details WHERE application_id = :application_id"
            );

            $statement->execute([':application_id' => $applicationId]);

            if ($form['registration_type'] === 'international') {

                $statement = $connection->prepare(
                    "
                    INSERT INTO application_international_details
                    (
                        application_id,
                        registration_status,
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
                    )
                    VALUES
                    (
                        :application_id,
                        :registration_status,
                        :parent_organization_name,
                        :country_of_origin,
                        :registration_number,
                        :parent_physical_address,
                        :parent_postal_address,
                        :parent_postal_code,
                        :parent_phone_number,
                        :parent_email,
                        :contact_person_name,
                        :contact_person_phone,
                        :contact_person_email
                    )
                    "
                );

                $statement->execute([
                    ':application_id' => $applicationId,
                    ':registration_status' => nullIfEmpty($form['registration_status']),
                    ':parent_organization_name' => $form['parent_organization_name'],
                    ':country_of_origin' => $form['country_of_origin'],
                    ':registration_number' => $form['registration_number'],
                    ':parent_physical_address' => $form['parent_physical_address'],
                    ':parent_postal_address' => $form['parent_postal_address'],
                    ':parent_postal_code' => $form['parent_postal_code'],
                    ':parent_phone_number' => $form['parent_phone_number'],
                    ':parent_email' => $form['parent_email'],
                    ':contact_person_name' => $form['contact_person_name'],
                    ':contact_person_phone' => $form['contact_person_phone'],
                    ':contact_person_email' => $form['contact_person_email'],
                ]);
            }

            /*
            | Other areas of operation (national AND international)
            | Office details are optional; empty ones are stored blank.
            */

            $statement = $connection->prepare(
                "DELETE FROM application_operating_locations WHERE application_id = :application_id"
            );

            $statement->execute([':application_id' => $applicationId]);

            if (count($operatingLocations) > 0) {

                $statement = $connection->prepare(
                    "
                    INSERT INTO application_operating_locations
                    (
                        application_id,
                        county,
                        sub_county,
                        physical_address,
                        phone_number,
                        alternative_phone_number,
                        email,
                        postal_address,
                        postal_code
                    )
                    VALUES
                    (
                        :application_id,
                        :county,
                        :sub_county,
                        :physical_address,
                        :phone_number,
                        :alternative_phone_number,
                        :email,
                        :postal_address,
                        :postal_code
                    )
                    "
                );

                foreach ($operatingLocations as $location) {
                    $statement->execute([
                        ':application_id' => $applicationId,
                        ':county' => $location['county'],
                        ':sub_county' => $location['sub_county'],
                        ':physical_address' => $location['physical_address'],
                        ':phone_number' => $location['phone_number'],
                        ':alternative_phone_number' => nullIfEmpty($location['alternative_phone_number']),
                        ':email' => $location['email'],
                        ':postal_address' => $location['postal_address'],
                        ':postal_code' => nullIfEmpty($location['postal_code']),
                    ]);
                }
            }

            /*
            | Areas of operation outside Kenya
            |
            | Delete then insert, like the counties above, so a save can
            | never leave duplicates behind. Does nothing on a server
            | where the table has not been created yet.
            */

            pboSaveForeignLocations($connection, $applicationId, $foreignLocations);

            /*
            | Audit
            */

            $statement = $connection->prepare(
                "
                INSERT INTO application_audit_log
                (
                    application_id,
                    user_id,
                    action,
                    description,
                    created_at
                )
                VALUES
                (
                    :application_id,
                    :user_id,
                    :action,
                    :description,
                    NOW()
                )
                "
            );

            $statement->execute([
                ':application_id' => $applicationId,
                ':user_id' => $userId,
                ':action' => $isEdit
                    ? 'ORGANIZATION_DETAILS_UPDATED'
                    : 'APPLICATION_CREATED',
                ':description' => $isEdit
                    ? 'Organization details, headquarters and operating locations updated.'
                    : 'Application created.',
            ]);

            $connection->commit();

            /*
            | The save succeeded; anything in $warnings is a format problem
            | that still needs correcting before submission. Hand it to the
            | next page rather than losing it in the redirect.
            */
            if ($warnings !== []) {
                $_SESSION['application_warnings'] = $warnings;
            }

            /*
            | Redirect: wizard link -> chosen page, Save & Continue -> Objectives
            */

            $destinationPage = $isWizardNavigation
                ? ($wizardPages[$wizardDestination] ?? 'create.php')
                : 'objectives.php';

            header(
                'Location: /pbo-portal/applications/'
                . $destinationPage
                . '?id='
                . urlencode((string) $applicationId)
            );

            exit;

        } catch (Throwable $exception) {

            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            error_log('PBO application save error: ' . $exception->getMessage());

            $errors[] = 'The application could not be saved. '
                . 'Please check the information entered and try again.';
        }
    }
}

/*
| Which of the two head office cards to show, worked out once here so the
| three places in the markup that depend on it cannot drift apart. The
| page's JavaScript repeats the same rule for when the radio is clicked.
*/
$headOfficeCards = pboHeadOfficeCards(
    $form['registration_type'],
    (string) $form['headquarters_in_kenya']
);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
/*
|--------------------------------------------------------------------------
| Scoped improvements for the application form
|--------------------------------------------------------------------------
|
| These styles only affect this page and preserve the existing
| application-page design language.
|
*/

.applications-page {
    max-width: 1180px;
    margin: 0 auto;
    padding: 0 1rem 3rem;
}

.applications-page .application-section {
    background: #ffffff;
    border: 1px solid rgba(30, 50, 80, 0.10);
    border-radius: 10px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(20, 35, 55, 0.05);
}

.applications-page .application-section-heading {
    margin-bottom: 1.5rem;
}

.applications-page .application-section-heading h2,
.applications-page .application-section-heading h3 {
    margin-top: 0;
    margin-bottom: 0.35rem;
}

.applications-page .application-section-heading p {
    margin: 0;
    line-height: 1.55;
}

.applications-page .form-group {
    margin-bottom: 1.25rem;
}

.applications-page .form-group label {
    display: block;
    margin-bottom: 0.45rem;
    font-weight: 600;
}

.applications-page .form-help {
    line-height: 1.55;
    margin-top: 0.35rem;
}

.applications-page input[type="text"],
.applications-page input[type="date"],
.applications-page input[type="email"],
.applications-page input[type="tel"],
.applications-page select,
.applications-page textarea {
    width: 100%;
    box-sizing: border-box;
}

.applications-page .headquarters-address-heading {
    margin-top: 0.75rem;
    margin-bottom: 1.5rem;
    padding-bottom: 0.8rem;
    border-bottom: 1px solid rgba(30, 50, 80, 0.10);
}

.applications-page .headquarters-address-heading h3 {
    margin: 0 0 0.35rem;
}

.applications-page .headquarters-address-heading p {
    margin: 0;
}

.applications-page .other-locations-container {
    margin-top: 2rem;
    padding-top: 1.75rem;
    border-top: 1px solid rgba(30, 50, 80, 0.12);
}

.applications-page .county-selection-grid {
    background: #f8fafc;
    border: 1px solid rgba(30, 50, 80, 0.10);
    border-radius: 8px;
    padding: 1rem;
}

.applications-page .operating-county-option {
    min-height: 42px;
    padding: 0.55rem 0.65rem;
    border-radius: 6px;
    box-sizing: border-box;
}

.applications-page .operating-county-option:hover {
    background: rgba(30, 50, 80, 0.04);
}

.applications-page .operating-location-block {
    background: #ffffff;
    border: 1px solid rgba(30, 50, 80, 0.12);
    border-left: 4px solid rgba(30, 50, 80, 0.45);
    box-shadow: 0 2px 7px rgba(20, 35, 55, 0.04);
}

.applications-page .operating-location-block
.application-section-heading {
    padding-bottom: 0.9rem;
    border-bottom: 1px solid rgba(30, 50, 80, 0.09);
}

.applications-page .choice-toggle {
    display: inline-flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.applications-page .choice-toggle-option {
    position: relative;
    margin: 0;
    cursor: pointer;
}

.applications-page .choice-toggle-option input {
    position: absolute;
    opacity: 0;
    width: 1px;
    height: 1px;
    pointer-events: none;
}

.applications-page .choice-toggle-option span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 120px;
    min-height: 46px;
    padding: 0.6rem 1.5rem;
    border: 2px solid rgba(30, 50, 80, 0.25);
    border-radius: 8px;
    background: #ffffff;
    font-size: 1rem;
    font-weight: 700;
    transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.applications-page .choice-toggle-option:hover span {
    border-color: var(--blue, #1d4f7c);
}

.applications-page .choice-toggle-option input:checked + span {
    background: var(--blue, #1d4f7c);
    border-color: var(--blue, #1d4f7c);
    color: #ffffff;
}

.applications-page .choice-toggle-option input:focus-visible + span {
    outline: 3px solid rgba(29, 79, 124, 0.35);
    outline-offset: 2px;
}

.applications-page .county-selection-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 0.75rem;
    margin-bottom: 0.75rem;
}

.applications-page .county-selection-count {
    font-size: 0.9rem;
    opacity: 0.75;
}

.applications-page .office-details-heading h4 {
    margin: 0 0 0.25rem;
}

.applications-page .office-details-heading {
    margin-bottom: 0.75rem;
}

.applications-page #operating-location-fields .operating-location-block {
    padding: 0;
    margin-bottom: 0.5rem;
    border-radius: 8px;
}

.applications-page .operating-location-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.65rem 1rem;
}

.applications-page .operating-location-summary strong {
    margin-right: 0.5rem;
}

.applications-page .operating-location-status {
    font-size: 0.85rem;
    opacity: 0.7;
}

.applications-page .operating-location-status.has-details {
    opacity: 1;
    font-weight: 600;
}

.applications-page .operating-location-body {
    padding: 1rem 1rem 0.25rem;
    border-top: 1px solid rgba(30, 50, 80, 0.09);
}

.applications-page .required {
    font-weight: 700;
}

.applications-page .alert {
    margin-bottom: 1.5rem;
}

.applications-page .alert ul {
    margin-bottom: 0;
}

@media (max-width: 760px) {

    .applications-page .application-section {
        padding: 1.25rem;
    }

    .applications-page .form-row {
        display: block;
    }

    .applications-page .county-selection-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>


<div class="applications-page">

    <div class="page-header">

        <div>

            <div class="section-eyebrow">
                PUBLIC BENEFIT ORGANIZATIONS
            </div>

            <h1>
                PBO Application
            </h1>

            <p>
                Enter the organization's registration and address details.
            </p>

        </div>

    </div>


    <?php require_once __DIR__ . '/../includes/application-wizard.php'; ?>


    <?php if (count($errors) > 0): ?>

        <div class="alert alert-danger" role="alert">

            <strong>
                Please correct the following:
            </strong>

            <ul>

                <?php foreach ($errors as $error): ?>

                    <li>
                        <?= e($error) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>


    <?php if (count($warnings) > 0): ?>

        <!--
            Saved, but something here is still wrong. Separate from the
            error box on purpose: the work was NOT thrown away, and the
            message has to say so, or staff retype what is already stored.
        -->
        <div class="alert alert-warning" role="status">

            <strong>
                Saved &mdash; but please correct the following before submitting:
            </strong>

            <ul>

                <?php foreach ($warnings as $warning): ?>

                    <li>
                        <?= e($warning) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>


    <?php if (count($errors) === 0 && count($submissionGaps) > 0): ?>

        <div class="alert alert-info" role="status">

            <strong>
                Saved as a draft. Before this application can be submitted, complete:
            </strong>

            <ul>

                <?php foreach ($submissionGaps as $gap): ?>

                    <li>
                        <?= e($gap) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

            <p class="form-help">
                You can still move between pages and finish these later.
            </p>

        </div>

    <?php endif; ?>


    <form
        method="post"
        id="application-form"
        novalidate
        <?php
        /*
        | Autosave key: this page, this application. Keeping the key
        | specific means one application's draft can never be offered
        | back on another's page.
        */
        ?>
        data-autosave="organization-<?= (int) ($applicationId ?? 0) ?>"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($csrfToken) ?>"
        >

        <?php if ($applicationId > 0): ?>

            <input
                type="hidden"
                name="id"
                value="<?= (int) $applicationId ?>"
            >

        <?php endif; ?>


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


        <!-- ==========================================================
             SECTION 1
             ========================================================== -->

        <section class="application-section">

            <div class="application-section-heading">

                <div class="application-section-number">
                    1
                </div>

                <div>

                    <h2>
                        Organization Details
                    </h2>

                    <p>
                        Enter the basic information of the proposed Public Benefit Organization.
                    </p>

                </div>

            </div>


            <div class="form-group">

                <label for="pbo_name">

                    Name of Public Benefit Organization

                    <span class="required">*</span>

                </label>

                <input
                    type="text"
                    id="pbo_name"
                    name="pbo_name"
                    value="<?= old($form, 'pbo_name') ?>"
                    required
                >

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="pbo_registration_number">

                        PBO Registration Number

                        <span class="required">*</span>

                    </label>

                    <input
                        type="text"
                        id="pbo_registration_number"
                        name="pbo_registration_number"
                        value="<?= old($form, 'pbo_registration_number') ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="registration_date">

                        Registration Date

                        <span class="required">*</span>

                    </label>

                    <!--
                        max stops the date picker offering a day that has
                        not happened yet. The server checks it as well -
                        a browser attribute is a courtesy, not a rule.
                    -->
                    <input
                        type="date"
                        id="registration_date"
                        name="registration_date"
                        value="<?= old($form, 'registration_date') ?>"
                        min="<?= e(PBO_EARLIEST_REGISTRATION_DATE) ?>"
                        max="<?= e(pboTodayForInput()) ?>"
                        required
                    >

                    <span class="form-help">
                        The date the organization was registered.
                        It cannot be in the future.
                    </span>

                </div>

            </div>


            <div class="form-group">

                <label>

                    Type of Organization

                    <span class="required">*</span>

                </label>


                <div class="radio-group">

                    <label class="radio-option">

                        <input
                            type="radio"
                            name="registration_type"
                            value="national"
                            <?= $form['registration_type'] === 'national'
                                ? 'checked'
                                : '' ?>
                            required
                        >

                        <span>
                            National
                        </span>

                    </label>


                    <label class="radio-option">

                        <input
                            type="radio"
                            name="registration_type"
                            value="international"
                            <?= $form['registration_type'] === 'international'
                                ? 'checked'
                                : '' ?>
                            required
                        >

                        <span>
                            International
                        </span>

                    </label>

                </div>

            </div>

        </section>


        <!-- ==========================================================
             SECTION 2
             HEADQUARTERS
             ========================================================== -->

        <!-- ==========================================================
             WHERE IS THE HEAD OFFICE?

             Only asked of an international organization. It decides
             which card they fill in next: the ordinary Kenyan head
             office, or the overseas one. Before this existed, every
             international record was assumed to have a parent body
             abroad, and the ones that did not had the overseas fields
             filled with their own Kenyan address to get past the page.
             ========================================================== -->

        <section
            class="application-section"
            id="headquarters-question-section"
            data-headquarters-question
            style="display: <?= $headOfficeCards['question'] ? 'block' : 'none' ?>;"
        >

            <div class="application-section-heading">

                <div>

                    <h2>
                        Head Office Location
                    </h2>

                    <p>
                        Is the organization's head office in Kenya?
                    </p>

                </div>

            </div>

            <div class="form-group">

                <div
                    class="choice-toggle"
                    role="radiogroup"
                    aria-label="Is the head office in Kenya?"
                >

                    <label class="choice-toggle-option">

                        <input
                            type="radio"
                            name="headquarters_in_kenya"
                            value="1"
                            <?= $form['headquarters_in_kenya'] === '1' ? 'checked' : '' ?>
                        >

                        <span>Yes, in Kenya</span>

                    </label>


                    <label class="choice-toggle-option">

                        <input
                            type="radio"
                            name="headquarters_in_kenya"
                            value="0"
                            <?= $form['headquarters_in_kenya'] === '0' ? 'checked' : '' ?>
                        >

                        <span>No, outside Kenya</span>

                    </label>

                </div>

                <span class="form-help">
                    Many organizations registered as international have their
                    head office here and no body abroad. If that is the case,
                    answer Yes and fill in the Kenyan address as usual.
                </span>

            </div>

        </section>


        <section class="application-section" id="headquarters-section">

            <!--
                The heading and the Kenyan head office fields sit inside
                data-kenyan-head-office so they can be hidden together when
                the head office is abroad. Areas of operation are further
                down in this same section and must stay visible either way,
                which is why the wrapper is here rather than on the section.
            -->
            <div
                data-kenyan-head-office
                style="display: <?= $headOfficeCards['kenyan'] ? 'block' : 'none' ?>;"
            >

            <div class="application-section-heading">

                <div class="application-section-number">
                    2
                </div>

                <div>

                    <h2>
                        HEADQUARTERS
                    </h2>

                    <p>
                        Enter the organization's principal physical, postal and contact information.
                    </p>

                </div>

            </div>


            <!-- HEAD OFFICE ADDRESS -->

            <div class="headquarters-address-heading">

                <h3>
                    Head Office Address
                </h3>

                <p>
                    This is the principal physical location of the organization.
                </p>

            </div>


            <!-- COUNTY + CITY/TOWN -->

            <div class="form-row">

                <div class="form-group">

                    <label for="county">

                        County

                        <span class="required">*</span>

                    </label>

                    <!--
                    County is intentionally a text field.
                    Staff can type the county instead of scrolling.
                    -->

                    <input
                        type="text"
                        id="county"
                        name="county"
                        value="<?= old($form, 'county') ?>"
                        placeholder="Enter County"
                        required
                        data-kenyan-required="true"
                    >

                </div>


                <div class="form-group">

                    <label for="city_town">

                        City / Town

                    </label>

                    <input
                        type="text"
                        id="city_town"
                        name="city_town"
                        value="<?= old($form, 'city_town') ?>"
                        placeholder="Enter City / Town"
                    >

                </div>

            </div>


            <!-- SUB COUNTY + POSTAL CODE -->

            <div class="form-row">

                <div class="form-group">

                    <label for="sub_county">

                        Sub-County

                    </label>

                    <input
                        type="text"
                        id="sub_county"
                        name="sub_county"
                        value="<?= old($form, 'sub_county') ?>"
                        placeholder="Enter Sub-County"
                    >

                </div>


                <div class="form-group">

                    <label for="postal_code">

                        Postal Code

                    </label>

                    <input
                        type="text"
                        id="postal_code"
                        name="postal_code"
                        value="<?= old($form, 'postal_code') ?>"
                        placeholder="Enter Postal Code"
                    >

                </div>

            </div>


            <!-- PHYSICAL ADDRESS -->

            <div class="form-group">

                <label for="physical_address">

                    Physical Address

                    <span class="required">*</span>

                </label>

                <input
                    type="text"
                    id="physical_address"
                    name="physical_address"
                    value="<?= old($form, 'physical_address') ?>"
                    placeholder="Enter Physical Address"
                    required
                    data-kenyan-required="true"
                >

            </div>


            <!-- POSTAL ADDRESS -->

            <div class="form-group">

                <label for="postal_address">

                    Postal Address

                    <span class="required">*</span>

                </label>

                <input
                    type="text"
                    id="postal_address"
                    name="postal_address"
                    value="<?= old($form, 'postal_address') ?>"
                    placeholder="Enter Postal Address"
                    required
                    data-kenyan-required="true"
                >

            </div>


            <!-- PHONE NUMBERS -->

            <div class="form-row">

                <div class="form-group">

                    <label for="phone_number">

                        Organization Phone Number

                        <span class="required">*</span>

                    </label>

                    <input
                        type="tel"
                        id="phone_number"
                        name="phone_number"
                        value="<?= old($form, 'phone_number') ?>"
                        placeholder="Enter Organization Phone Number"
                        required
                        data-kenyan-required="true"
                    >

                </div>


                <div class="form-group">

                    <label for="alternative_phone_number">

                        Alternative Phone Number

                    </label>

                    <input
                        type="tel"
                        id="alternative_phone_number"
                        name="alternative_phone_number"
                        value="<?= old($form, 'alternative_phone_number') ?>"
                        placeholder="Enter Alternative Phone Number"
                    >

                </div>

            </div>


            <!-- EMAIL -->

            <div class="form-group">

                <label for="email">

                    Email of the Organization

                    <span class="required">*</span>

                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= old($form, 'email') ?>"
                    placeholder="Enter Organization Email"
                    required
                    data-kenyan-required="true"
                >

            </div>


            </div><!-- /data-kenyan-head-office -->

        <!-- ==========================================================
             SECTION 2, THE OTHER CARD
             HEAD OFFICE OUTSIDE KENYA

             This stands in PLACE of the Kenyan head office card above,
             never alongside it, which is why it carries the same step
             number and sits here rather than at the foot of the page.
             ========================================================== -->

            <div
                id="international-section"
                style="display: <?= $headOfficeCards['overseas'] ? 'block' : 'none' ?>;"
            >

            <div class="application-section-heading">

                <div class="application-section-number">
                    2
                </div>

                <div>

                    <h2>
                        <?= e(pboOverseasHeading()) ?>
                    </h2>

                    <p>
                        Provide the organization's registration and head office
                        details in the country it is based in.
                    </p>

                </div>

            </div>

            <div class="form-row">

                <div class="form-group">

                    <label for="parent_organization_name">

                        Name of the Organization

                        <span class="required">*</span>

                    </label>

                    <input
                        type="text"
                        id="parent_organization_name"
                        name="parent_organization_name"
                        value="<?= old(
                            $form,
                            'parent_organization_name'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>


                <div class="form-group">

                    <label for="country_of_origin">

                        Country

                        <span class="required">*</span>

                    </label>

                    <input
                        type="text"
                        id="country_of_origin"
                        name="country_of_origin"
                        value="<?= old(
                            $form,
                            'country_of_origin'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>

            </div>


            <div class="form-group">

                <label for="registration_number">

                    Registration Number

                    <span class="required">*</span>

                </label>

                <input
                    type="text"
                    id="registration_number"
                    name="registration_number"
                    value="<?= old(
                        $form,
                        'registration_number'
                    ) ?>"
                    data-international-required="true"
                >

            </div>


            <div class="form-group">

                <label for="parent_physical_address">

                    Physical Address

                    <span class="required">*</span>

                </label>

                <input
                    type="text"
                    id="parent_physical_address"
                    name="parent_physical_address"
                    value="<?= old(
                        $form,
                        'parent_physical_address'
                    ) ?>"
                    data-international-required="true"
                >

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="parent_postal_address">

                        Postal Address

                        <span class="required">*</span>

                    </label>

                    <input
                        type="text"
                        id="parent_postal_address"
                        name="parent_postal_address"
                        value="<?= old(
                            $form,
                            'parent_postal_address'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>


                <div class="form-group">

                    <label for="parent_postal_code">

                        Postal Code

                        <span class="required">*</span>

                    </label>

                    <input
                        type="text"
                        id="parent_postal_code"
                        name="parent_postal_code"
                        value="<?= old(
                            $form,
                            'parent_postal_code'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="parent_phone_number">

                        Telephone

                        <span class="required">*</span>

                    </label>

                    <input
                        type="tel"
                        id="parent_phone_number"
                        name="parent_phone_number"
                        value="<?= old(
                            $form,
                            'parent_phone_number'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>


                <div class="form-group">

                    <label for="parent_email">

                        Email

                        <span class="required">*</span>

                    </label>

                    <input
                        type="email"
                        id="parent_email"
                        name="parent_email"
                        value="<?= old(
                            $form,
                            'parent_email'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>

            </div>


            <div class="form-row">

                <div class="form-group">

                    <label for="contact_person_name">

                        Contact Person Name

                        <span class="required">*</span>

                    </label>

                    <input
                        type="text"
                        id="contact_person_name"
                        name="contact_person_name"
                        value="<?= old(
                            $form,
                            'contact_person_name'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>


                <div class="form-group">

                    <label for="contact_person_phone">

                        Contact Person Phone

                        <span class="required">*</span>

                    </label>

                    <input
                        type="tel"
                        id="contact_person_phone"
                        name="contact_person_phone"
                        value="<?= old(
                            $form,
                            'contact_person_phone'
                        ) ?>"
                        data-international-required="true"
                    >

                </div>

            </div>


            <div class="form-group">

                <label for="contact_person_email">

                    Contact Person Email

                    <span class="required">*</span>

                </label>

                <input
                    type="email"
                    id="contact_person_email"
                    name="contact_person_email"
                    value="<?= old(
                        $form,
                        'contact_person_email'
                    ) ?>"
                    data-international-required="true"
                >

            </div>

            </div><!-- /international-section -->


            <!-- ======================================================
                 OTHER OPERATING LOCATIONS
                 ====================================================== -->

            <div
                id="other-locations-section"
                class="other-locations-container"
            >

                <div class="application-section-heading">

                    <div>

                        <h3>
                            Other Areas of Operation
                        </h3>

                        <p>
                            Does the organization operate in any other county?
                        </p>

                    </div>

                </div>


                <div class="form-group">

                    <div
                        class="choice-toggle"
                        role="radiogroup"
                        aria-label="Does the organization operate in any other county?"
                    >

                        <label class="choice-toggle-option">

                            <input
                                type="radio"
                                name="operates_other_locations"
                                value="yes"
                                <?= $operatesOtherLocations === 'yes'
                                    ? 'checked'
                                    : '' ?>
                            >

                            <span>
                                Yes
                            </span>

                        </label>


                        <label class="choice-toggle-option">

                            <input
                                type="radio"
                                name="operates_other_locations"
                                value="no"
                                <?= $operatesOtherLocations !== 'yes'
                                    ? 'checked'
                                    : '' ?>
                            >

                            <span>
                                No
                            </span>

                        </label>

                    </div>

                </div>


                <div
                    id="operating-counties-wrapper"
                    style="
                        display: <?= $operatesOtherLocations === 'yes'
                            ? 'block'
                            : 'none' ?>;
                    "
                >

                    <div class="form-group">

                        <label>
                            Select Other Operating Locations
                        </label>

                        <p class="form-help">
                            Tick every county where the organization works. Selecting the county is enough.
                            If the organization has an office in a county, you can add its details below.
                        </p>

                    </div>


                    <div class="county-selection-toolbar">

                        <button
                            type="button"
                            class="btn btn-secondary btn-sm"
                            id="select-all-counties"
                        >
                            Select all counties
                        </button>

                        <button
                            type="button"
                            class="btn btn-secondary btn-sm"
                            id="clear-all-counties"
                        >
                            Clear all
                        </button>

                        <span
                            class="county-selection-count"
                            id="county-selection-count"
                            aria-live="polite"
                        ></span>

                    </div>


                    <div
                        class="county-selection-grid"
                        id="county-selection-grid"
                        style="
                            display: grid;
                            grid-template-columns:
                                repeat(
                                    auto-fit,
                                    minmax(190px, 1fr)
                                );
                            gap: 0.5rem 1rem;
                            margin-bottom: 1.5rem;
                        "
                    >

                        <?php foreach (
                            $countyOptions
                            as $county
                        ): ?>

                            <?php

                            $countySlug =
                                slugifyCounty(
                                    $county
                                );

                            $isSelected =
                                isset(
                                    $operatingLocations[
                                        $countySlug
                                    ]
                                );

                            $isHeadOffice =
                                $form['county'] !== ''
                                &&
                                normalizeCountyName($form['county'])
                                === normalizeCountyName($county);

                            ?>

                            <label
                                class="radio-option operating-county-option"
                                data-county-slug="<?= e($countySlug) ?>"
                            >

                                <input
                                    type="checkbox"
                                    name="operating_county_selection[]"
                                    value="<?= e($countySlug) ?>"
                                    data-operating-county="<?= e($countySlug) ?>"
                                    <?= $isSelected
                                        ? 'checked'
                                        : '' ?>
                                    <?= $isHeadOffice
                                        ? 'disabled'
                                        : '' ?>
                                >

                                <span>

                                    <?= e($county) ?>

                                    <?php if (
                                        $isHeadOffice
                                    ): ?>

                                        <small data-head-office-marker="true">
                                            (Head Office)
                                        </small>

                                    <?php endif; ?>

                                </span>

                            </label>

                        <?php endforeach; ?>

                    </div>


                    <div
                        class="office-details-heading"
                        id="office-details-heading"
                    >

                        <h4>
                            Office details by county (optional)
                        </h4>

                        <p class="form-help">
                            Only open a county if the organization has an office there.
                            Details you type are kept even when the county is collapsed.
                        </p>

                    </div>

                    <div id="operating-location-fields"></div>

                </div>

            </div>


            <!-- ======================================================
                 AREAS OF OPERATION OUTSIDE KENYA

                 Open to national organizations too, not only
                 international ones. A Kenyan organization running a
                 programme in Uganda or the United States is an ordinary
                 thing, and the only place to record where an
                 organization works was a list of the 47 counties - so
                 anywhere outside Kenya simply went unrecorded.
                 ====================================================== -->

            <div
                id="foreign-locations-section"
                class="other-locations-container"
            >

                <div class="application-section-heading">

                    <div>

                        <h3>
                            Areas of Operation Outside Kenya
                        </h3>

                        <p>
                            Does the organization operate in any country
                            other than Kenya?
                        </p>

                    </div>

                </div>

                <div class="form-group">

                    <div
                        class="choice-toggle"
                        role="radiogroup"
                        aria-label="Does the organization operate outside Kenya?"
                    >

                        <label class="choice-toggle-option">

                            <input
                                type="radio"
                                name="operates_abroad"
                                value="yes"
                                <?= $operatesAbroad === 'yes' ? 'checked' : '' ?>
                            >

                            <span>Yes</span>

                        </label>


                        <label class="choice-toggle-option">

                            <input
                                type="radio"
                                name="operates_abroad"
                                value="no"
                                <?= $operatesAbroad !== 'yes' ? 'checked' : '' ?>
                            >

                            <span>No</span>

                        </label>

                    </div>

                </div>


                <div
                    id="foreign-locations-body"
                    style="display: <?= $operatesAbroad === 'yes' ? 'block' : 'none' ?>;"
                >

                    <div id="foreign-location-cards">

                        <?php foreach ($foreignLocations as $index => $foreignLocation): ?>

                            <div class="operating-location-block" data-foreign-card>

                                <div class="operating-location-header">

                                    <strong>
                                        Country <?= (int) $index + 1 ?>
                                    </strong>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        data-remove-foreign
                                    >
                                        Remove
                                    </button>

                                </div>

                                <div class="operating-location-body">

                                    <?php foreach (pboForeignLocationFields() as $field => $label): ?>

                                        <div class="form-group">

                                            <label><?= e($label) ?><?php if ($field === 'country'): ?>
                                                <span class="required">*</span>
                                            <?php endif; ?></label>

                                            <input
                                                type="<?= $field === 'email' ? 'email' : 'text' ?>"
                                                name="foreign_locations[<?= (int) $index ?>][<?= e($field) ?>]"
                                                value="<?= e((string) ($foreignLocation[$field] ?? '')) ?>"
                                                <?= $field === 'country' ? 'list="country-list"' : '' ?>
                                            >

                                        </div>

                                    <?php endforeach; ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                    <button
                        type="button"
                        class="btn btn-secondary"
                        id="add-foreign-location"
                    >
                        + Add a country
                    </button>

                    <p class="form-help">
                        Name the country, and any office details you have.
                        Only the country is required.
                    </p>

                </div>

            </div>

        </section>




        <!-- ==========================================================
             ACTIONS
             ========================================================== -->

        <p class="form-help">
            Only the Organization Details (name, PBO number, registration date and type) are needed to save a draft.
            Fields marked <span class="required">*</span> must be completed before the application can be submitted.
        </p>

        <div class="application-actions">

            <button
                type="submit"
                class="btn btn-primary"
                id="save-button"
            >
                Save &amp; Continue
            </button>

        </div>

    </form>

</div>


<script>

(function () {

    'use strict';


    const form =
        document.getElementById(
            'application-form'
        );


    if (!form) {
        return;
    }


    const registrationTypeInputs =
        document.querySelectorAll(
            'input[name="registration_type"]'
        );


    const internationalSection =
        document.getElementById(
            'international-section'
        );


    const otherLocationsSection =
        document.getElementById(
            'other-locations-section'
        );


    const operatingCountiesWrapper =
        document.getElementById(
            'operating-counties-wrapper'
        );


    const operatingLocationFields =
        document.getElementById(
            'operating-location-fields'
        );


    const countyInput =
        document.getElementById(
            'county'
        );


    const countyMap =
        <?= json_encode(
            $countyMap,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) ?>;


    /*
    | Office details already saved for each county (keyed by county slug).
    */

    const existingOperatingLocations =
        <?= json_encode(
            (object) $operatingLocations,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_HEX_TAG |
            JSON_HEX_AMP
        ) ?>;

    const openFilledLocationsOnLoad =
        <?= count($errors) > 0 ? 'true' : 'false' ?>;


    /*
    |--------------------------------------------------------------------------
    | Escape HTML
    |--------------------------------------------------------------------------
    */

    function escapeHtml(value) {

        const div =
            document.createElement(
                'div'
            );

        div.textContent =
            String(value ?? '');

        return div.innerHTML;
    }


    /*
    |--------------------------------------------------------------------------
    | Registration type
    |--------------------------------------------------------------------------
    */

    function getRegistrationType() {

        const selected =
            document.querySelector(
                'input[name="registration_type"]:checked'
            );

        return selected
            ? selected.value
            : '';

    }


    /*
    |--------------------------------------------------------------------------
    | International / National visibility
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Where is the head office?
    |--------------------------------------------------------------------------
    |
    | Asked only of an international organization, and the answer decides
    | which card they fill in:
    |
    |   in Kenya  -> the ordinary Kenyan head office card
    |   abroad    -> the overseas card in its place
    |
    | Areas of operation stay visible either way: they belong to the
    | organization, not to where its head office happens to be.
    |
    | The server decides this too, in includes/organization-reach.php.
    | This is only so the page follows along as the radio is clicked.
    */

    function headquartersAnswer() {

        const chosen = document.querySelector(
            'input[name="headquarters_in_kenya"]:checked'
        );

        return chosen ? chosen.value : '';
    }


    function updateHeadOfficeCards() {

        const type = getRegistrationType();
        const international = type === 'international';
        const answer = headquartersAnswer();

        /* Only an international organization is asked. */
        const questionSection =
            document.querySelector('[data-headquarters-question]');

        if (questionSection) {
            questionSection.style.display = international ? 'block' : 'none';
        }

        /*
        | A national organization is in Kenya by definition. An
        | international one that has not answered yet sees neither card,
        | so the question is the obvious next thing to deal with.
        */
        const abroad = international && answer === '0';
        const showKenyan = !international || answer === '1';

        const kenyanBlock =
            document.querySelector('[data-kenyan-head-office]');

        if (kenyanBlock) {
            kenyanBlock.style.display = showKenyan ? 'block' : 'none';
        }

        if (internationalSection) {
            internationalSection.style.display = abroad ? 'block' : 'none';
        }

        /*
        | A hidden required field blocks submission with a message the
        | staff member cannot see, so required follows visibility.
        */
        if (kenyanBlock) {
            kenyanBlock
                .querySelectorAll('[data-kenyan-required="true"]')
                .forEach(function (field) {
                    field.required = showKenyan;
                });
        }

        document
            .querySelectorAll('[data-international-required="true"]')
            .forEach(function (field) {
                field.required = abroad;
            });
    }


    document
        .querySelectorAll('input[name="headquarters_in_kenya"]')
        .forEach(function (radio) {
            radio.addEventListener('change', updateHeadOfficeCards);
        });


    /*
    |--------------------------------------------------------------------------
    | Areas of operation outside Kenya
    |--------------------------------------------------------------------------
    */

    const foreignBody = document.getElementById('foreign-locations-body');
    const foreignCards = document.getElementById('foreign-location-cards');
    const addForeign = document.getElementById('add-foreign-location');

    const foreignFields = <?= json_encode(
        pboForeignLocationFields(),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
    ) ?>;

    function updateForeignVisibility() {

        const chosen = document.querySelector(
            'input[name="operates_abroad"]:checked'
        );

        const yes = chosen && chosen.value === 'yes';

        if (foreignBody) {
            foreignBody.style.display = yes ? 'block' : 'none';
        }

        /* Offer an empty card straight away rather than a bare button. */
        if (yes && foreignCards && foreignCards.children.length === 0) {
            addForeignCard();
        }
    }

    function renumberForeignCards() {

        if (!foreignCards) {
            return;
        }

        Array.prototype.forEach.call(
            foreignCards.children,
            function (card, index) {

                const heading = card.querySelector('strong');

                if (heading) {
                    heading.textContent = 'Country ' + (index + 1);
                }

                card.querySelectorAll('input').forEach(function (field) {

                    const key = field.getAttribute('data-foreign-field');

                    if (key) {
                        field.name = 'foreign_locations[' + index + '][' + key + ']';
                    }
                });
            }
        );
    }

    function addForeignCard() {

        if (!foreignCards) {
            return;
        }

        const card = document.createElement('div');
        card.className = 'operating-location-block';
        card.setAttribute('data-foreign-card', '');

        let html =
            '<div class="operating-location-header">'
            + '<strong>Country</strong>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" '
            + 'data-remove-foreign>Remove</button>'
            + '</div><div class="operating-location-body">';

        Object.keys(foreignFields).forEach(function (key) {
            html +=
                '<div class="form-group"><label>'
                + foreignFields[key]
                + (key === 'country' ? ' <span class="required">*</span>' : '')
                + '</label><input type="'
                + (key === 'email' ? 'email' : 'text')
                + '" data-foreign-field="' + key + '"'
                + (key === 'country' ? ' list="country-list"' : '')
                + '></div>';
        });

        card.innerHTML = html + '</div>';

        foreignCards.appendChild(card);
        renumberForeignCards();
    }

    /* The cards rendered by PHP need the attribute the rebuild relies on. */
    if (foreignCards) {
        foreignCards.querySelectorAll('input[name]').forEach(function (field) {
            const match = field.name.match(/\[([a-z_]+)\]$/);

            if (match) {
                field.setAttribute('data-foreign-field', match[1]);
            }
        });
    }

    if (addForeign) {
        addForeign.addEventListener('click', addForeignCard);
    }

    document.addEventListener('click', function (event) {

        if (!event.target.closest('[data-remove-foreign]')) {
            return;
        }

        const card = event.target.closest('[data-foreign-card]');

        if (card) {
            card.remove();
            renumberForeignCards();
        }
    });

    document
        .querySelectorAll('input[name="operates_abroad"]')
        .forEach(function (radio) {
            radio.addEventListener('change', updateForeignVisibility);
        });

    updateForeignVisibility();


    function updateRegistrationType() {

        const type =
            getRegistrationType();

        const international =
            type === 'international';

        const national =
            type === 'national';


        updateHeadOfficeCards();


        /*
        | Other areas of operation are always shown, whatever the type.
        */

        if (otherLocationsSection) {
            otherLocationsSection.style.display = 'block';
        }


        /*
        | The overseas fields are required only when the head office is
        | actually abroad, which updateHeadOfficeCards() above decides.
        | A second loop here setting them from the type alone would undo
        | that and demand overseas details of an international
        | organization whose head office is in Kenya.
        */

        updateOtherLocationsVisibility();

    }


    /*
    |--------------------------------------------------------------------------
    | Other locations visibility
    |--------------------------------------------------------------------------
    */

    function updateOtherLocationsVisibility() {

        const selected =
            document.querySelector(
                'input[name="operates_other_locations"]:checked'
            );

        const show =
            selected
            &&
            selected.value === 'yes';


        if (operatingCountiesWrapper) {

            operatingCountiesWrapper.style.display =
                show
                    ? 'block'
                    : 'none';

        }

    }


    /*
    |--------------------------------------------------------------------------
    | Office details per county (compact, collapsed by default)
    |--------------------------------------------------------------------------
    |
    | Ticking a county adds a single slim row: "Mombasa  [Add office details]".
    | The fields only open when staff click the button. Collapsed rows still
    | send whatever was typed, so nothing is lost. Unticking a county hides
    | and disables its row so it is not saved.
    |
    */

    const locationFieldDefinitions = [
        { key: 'sub_county', label: 'Sub-County', type: 'text', wide: false },
        { key: 'physical_address', label: 'Physical Address', type: 'text', wide: false },
        { key: 'postal_address', label: 'Postal Address', type: 'text', wide: true },
        { key: 'postal_code', label: 'Postal Code', type: 'text', wide: false },
        { key: 'phone_number', label: 'Organization Phone Number', type: 'tel', wide: false },
        { key: 'alternative_phone_number', label: 'Alternative Phone Number', type: 'tel', wide: false },
        { key: 'email', label: 'Email of the Organization', type: 'email', wide: false },
    ];

    function findBlock(countySlug) {

        if (!operatingLocationFields) {
            return null;
        }

        return operatingLocationFields.querySelector(
            '[data-operating-location="' + CSS.escape(countySlug) + '"]'
        );
    }

    function blockHasDetails(block) {

        return Array.from(
            block.querySelectorAll('.operating-location-body input')
        ).some(function (field) {
            return field.value.trim() !== '';
        });
    }

    function updateBlockStatus(block) {

        const status = block.querySelector('.operating-location-status');
        const toggle = block.querySelector('[data-toggle-location]');
        const body = block.querySelector('.operating-location-body');

        const hasDetails = blockHasDetails(block);
        const isOpen = Boolean(body) && !body.hidden;

        if (status) {
            status.textContent = hasDetails
                ? 'Office details added'
                : 'No office details (optional)';
            status.classList.toggle('has-details', hasDetails);
        }

        if (toggle) {
            toggle.textContent = isOpen
                ? 'Hide details'
                : (hasDetails ? 'View / edit office details' : 'Add office details');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
    }

    function setBlockOpen(block, open) {

        const body = block.querySelector('.operating-location-body');

        if (body) {
            body.hidden = !open;
        }

        updateBlockStatus(block);
    }

    function setBlockEnabled(block, enabled) {

        block.style.display = enabled ? 'block' : 'none';

        block.querySelectorAll('input, select, textarea').forEach(
            function (field) {
                field.disabled = !enabled;
            }
        );
    }

    function buildField(countySlug, definition, value) {

        return `
            <div class="form-group">
                <label>${escapeHtml(definition.label)}</label>
                <input
                    type="${definition.type}"
                    name="operating_locations[${escapeHtml(countySlug)}][${definition.key}]"
                    value="${escapeHtml(value || '')}"
                    placeholder="Enter ${escapeHtml(definition.label)}"
                >
            </div>
        `;
    }

    function createOperatingLocationBlock(countySlug, countyName, existingValues) {

        if (!operatingLocationFields) {
            return;
        }

        let block = findBlock(countySlug);

        if (block) {
            setBlockEnabled(block, true);
            updateBlockStatus(block);
            return;
        }

        existingValues = existingValues || {};

        const fieldsHtml = [];
        let row = [];

        locationFieldDefinitions.forEach(function (definition) {

            const html = buildField(countySlug, definition, existingValues[definition.key]);

            if (definition.wide) {
                if (row.length) {
                    fieldsHtml.push('<div class="form-row">' + row.join('') + '</div>');
                    row = [];
                }
                fieldsHtml.push(html);
                return;
            }

            row.push(html);

            if (row.length === 2) {
                fieldsHtml.push('<div class="form-row">' + row.join('') + '</div>');
                row = [];
            }
        });

        if (row.length) {
            fieldsHtml.push('<div class="form-row">' + row.join('') + '</div>');
        }

        block = document.createElement('div');
        block.className = 'application-section operating-location-block';
        block.dataset.operatingLocation = countySlug;

        block.innerHTML = `
            <div class="operating-location-summary">
                <div>
                    <strong>${escapeHtml(countyName)}</strong>
                    <span class="operating-location-status"></span>
                </div>
                <button
                    type="button"
                    class="btn btn-secondary btn-sm"
                    data-toggle-location="true"
                    aria-expanded="false"
                ></button>
            </div>
            <div class="operating-location-body" hidden>
                ${fieldsHtml.join('')}
            </div>
        `;

        block.querySelector('[data-toggle-location]').addEventListener(
            'click',
            function () {
                const body = block.querySelector('.operating-location-body');
                setBlockOpen(block, body.hidden);
            }
        );

        block.addEventListener('input', function () {
            updateBlockStatus(block);
        });

        // Keep rows in the same order as the county list.
        const order = Object.keys(countyMap);
        const position = order.indexOf(countySlug);
        const nextBlock = Array.from(operatingLocationFields.children).find(
            function (other) {
                return order.indexOf(other.dataset.operatingLocation) > position;
            }
        );

        operatingLocationFields.insertBefore(block, nextBlock || null);

        updateBlockStatus(block);
    }

    function hideOperatingLocationBlock(countySlug) {

        const block = findBlock(countySlug);

        if (block) {
            setBlockEnabled(block, false);
        }
    }

    function getCountyCheckboxes() {

        return Array.from(
            document.querySelectorAll('input[data-operating-county]')
        );
    }

    function updateSelectionCount() {

        const counter = document.getElementById('county-selection-count');
        const heading = document.getElementById('office-details-heading');

        const boxes = getCountyCheckboxes();
        const available = boxes.filter(function (box) { return !box.disabled; });
        const selected = available.filter(function (box) { return box.checked; });

        if (counter) {
            counter.textContent = selected.length + ' of ' + available.length + ' counties selected';
        }

        if (heading) {
            heading.style.display = selected.length > 0 ? 'block' : 'none';
        }
    }

    function applyCheckbox(checkbox) {

        const countySlug = checkbox.dataset.operatingCounty;
        const countyName = countyMap[countySlug];

        if (!countyName) {
            return;
        }

        if (checkbox.checked && !checkbox.disabled) {
            createOperatingLocationBlock(
                countySlug,
                countyName,
                existingOperatingLocations[countySlug]
            );
        } else {
            hideOperatingLocationBlock(countySlug);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | County selection, Select all / Clear all
    |--------------------------------------------------------------------------
    */

    getCountyCheckboxes().forEach(function (checkbox) {

        checkbox.addEventListener('change', function () {
            applyCheckbox(checkbox);
            updateSelectionCount();
        });
    });

    const selectAllButton = document.getElementById('select-all-counties');
    const clearAllButton = document.getElementById('clear-all-counties');

    if (selectAllButton) {

        selectAllButton.addEventListener('click', function () {

            getCountyCheckboxes().forEach(function (checkbox) {
                if (!checkbox.disabled && !checkbox.checked) {
                    checkbox.checked = true;
                    applyCheckbox(checkbox);
                }
            });

            updateSelectionCount();
        });
    }

    if (clearAllButton) {

        clearAllButton.addEventListener('click', function () {

            getCountyCheckboxes().forEach(function (checkbox) {
                if (checkbox.checked) {
                    checkbox.checked = false;
                    applyCheckbox(checkbox);
                }
            });

            updateSelectionCount();
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Head office county
    |--------------------------------------------------------------------------
    |
    | The head office county cannot also be ticked as an "other" area.
    |
    */

    function normalizeCounty(value) {

        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/\s+county$/i, '')
            .replace(/\s+city$/i, '')
            .replace(/[^a-z0-9]+/g, '');
    }

    function updateHeadOfficeCounty() {

        const headOfficeCounty = countyInput
            ? normalizeCounty(countyInput.value)
            : '';

        getCountyCheckboxes().forEach(function (checkbox) {

            const countySlug = checkbox.dataset.operatingCounty;
            const isHeadOffice =
                headOfficeCounty !== ''
                && normalizeCounty(countyMap[countySlug]) === headOfficeCounty;

            checkbox.disabled = isHeadOffice;

            const option = checkbox.closest('.operating-county-option');
            const span = option ? option.querySelector('span') : null;

            if (span) {

                let marker = span.querySelector('small[data-head-office-marker]');

                if (!marker) {
                    marker = document.createElement('small');
                    marker.dataset.headOfficeMarker = 'true';
                    marker.textContent = ' (Head Office)';
                    span.appendChild(marker);
                }

                marker.style.display = isHeadOffice ? 'inline' : 'none';
            }

            if (isHeadOffice && checkbox.checked) {
                checkbox.checked = false;
            }

            applyCheckbox(checkbox);
        });

        updateSelectionCount();
    }

    if (countyInput) {
        countyInput.addEventListener('input', updateHeadOfficeCounty);
        countyInput.addEventListener('change', updateHeadOfficeCounty);
    }


    /*
    |--------------------------------------------------------------------------
    | Type of organization radio buttons
    |--------------------------------------------------------------------------
    |
    | FIX: these had no change listener, so choosing National/International
    | only took effect after leaving the page and coming back.
    |
    */

    registrationTypeInputs.forEach(function (radio) {
        radio.addEventListener('change', updateRegistrationType);
    });


    /*
    |--------------------------------------------------------------------------
    | Other locations radio buttons
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            'input[name="operates_other_locations"]'
        )
        .forEach(
            function (radio) {

                radio.addEventListener(
                    'change',
                    updateOtherLocationsVisibility
                );

            }
        );


    /*
    |--------------------------------------------------------------------------
    | Wizard links
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            '[data-wizard-link="true"]'
        )
        .forEach(
            function (link) {

                link.addEventListener(
                    'click',
                    function (event) {

                        event.preventDefault();


                        /*
                        | The shared wizard marks each link with
                        | data-wizard-step="1..6".
                        */

                        const destination =
                            parseInt(
                                link.dataset.wizardStep
                                    || link.dataset.wizardDestination
                                    || '1',
                                10
                            );


                        // Organization is the current page: nothing to do.
                        if (destination === 1) {
                            return;
                        }


                        document.getElementById(
                            'form_action'
                        ).value =
                            'wizard_navigation';


                        document.getElementById(
                            'wizard_destination'
                        ).value =
                            String(
                                destination
                            );


                        /*
                        |--------------------------------------------------------------------------
                        | Preserve original wizard behavior.
                        |--------------------------------------------------------------------------
                        */

                        form.noValidate =
                            true;


                        form.submit();

                    }
                );

            }
        );


    /*
    |--------------------------------------------------------------------------
    | Normal submission
    |--------------------------------------------------------------------------
    */

    form.addEventListener(
        'submit',
        function (event) {

            const formAction =
                document.getElementById(
                    'form_action'
                ).value;


            if (
                formAction !==
                'wizard_navigation'
            ) {

                /*
                | Only the four draft fields are needed to save.
                | Everything else is checked at final submission.
                | A typed email must still look like an email.
                */

                const draftFields = [
                    document.getElementById('pbo_name'),
                    document.getElementById('pbo_registration_number'),
                    document.getElementById('registration_date'),
                    document.querySelector('input[name="registration_type"]'),
                ];

                const typedEmails = Array.from(
                    form.querySelectorAll('input[type="email"]')
                ).filter(function (field) {
                    return !field.disabled && field.value.trim() !== '';
                });

                const fieldsToCheck = draftFields
                    .concat(typedEmails)
                    .filter(Boolean);

                for (const field of fieldsToCheck) {

                    const valid = field.type === 'email'
                        ? !field.validity.typeMismatch
                        : field.checkValidity();

                    if (!valid) {

                        event.preventDefault();

                        if (field.type === 'email') {
                            field.setCustomValidity('Please enter a valid email address, or leave it blank.');
                            field.reportValidity();
                            field.addEventListener('input', function () {
                                field.setCustomValidity('');
                            }, { once: true });
                        } else {
                            field.reportValidity();
                        }

                        return;

                    }

                }


                const saveButton =
                    document.getElementById(
                        'save-button'
                    );


                if (saveButton) {

                    saveButton.disabled =
                        true;

                    saveButton.textContent =
                        'Saving...';

                }

            }

        }
    );


    /*
    |--------------------------------------------------------------------------
    | Initial state
    |--------------------------------------------------------------------------
    |
    | updateHeadOfficeCounty() also builds the rows for counties that are
    | already ticked (saved earlier), filled with their saved details.
    |
    */

    updateRegistrationType();

    updateHeadOfficeCounty();

    updateOtherLocationsVisibility();

    /*
    | After a failed save, open rows that have details so errors are visible.
    */

    if (openFilledLocationsOnLoad && operatingLocationFields) {

        operatingLocationFields
            .querySelectorAll('.operating-location-block')
            .forEach(function (block) {
                if (block.style.display !== 'none' && blockHasDetails(block)) {
                    setBlockOpen(block, true);
                }
            });
    }

})();

</script>


<!-- ==========================================================
     Location suggestions (County / Sub-County / Postal Code)

     Reference data is handed to the browser in one go rather than
     looked up per keystroke: the whole sub-county map is a few KB,
     and it keeps the dynamically-added operating-location rows
     working without any extra requests.

     The fields stay free text. This only adds suggestions.
     ========================================================== -->

<script>
window.KENYA_LOCATIONS = <?= json_encode(
    [
        'counties'    => $countyOptions,
        'subCounties' => kenyaSubCountyMap($connection),
        'postal'      => kenyaPostalCodes($connection),
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
) ?>;
</script>

<!-- Suggestions for the Country fields. Free text underneath: the list
     will never cover everywhere, and a country it misses must still be
     enterable. -->
<datalist id="country-list">
    <?php foreach ([
        'Uganda', 'Tanzania', 'Rwanda', 'Burundi', 'South Sudan', 'Somalia',
        'Ethiopia', 'Democratic Republic of the Congo', 'Nigeria', 'Ghana',
        'South Africa', 'Egypt', 'Morocco',
        'United Kingdom', 'Ireland', 'France', 'Germany', 'Netherlands',
        'Belgium', 'Switzerland', 'Sweden', 'Norway', 'Denmark', 'Finland',
        'Italy', 'Spain', 'Portugal',
        'United States of America', 'Canada', 'Brazil',
        'India', 'China', 'Japan', 'Australia', 'New Zealand',
        'United Arab Emirates', 'Qatar', 'Saudi Arabia',
    ] as $countryOption): ?>
        <option value="<?= e($countryOption) ?>"></option>
    <?php endforeach; ?>
</datalist>

<script src="/pbo-portal/assets/js/location-suggest.js"></script>



<!-- Keeps typed input in the browser so a refused save never costs the work. -->
<script src="/pbo-portal/assets/js/form-autosave.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>