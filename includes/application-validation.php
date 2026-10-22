<?php

declare(strict_types=1);

require_once __DIR__ . '/organization-reach.php';

/*
|--------------------------------------------------------------------------
| Application completeness checks
|--------------------------------------------------------------------------
|
| Saving and moving between wizard pages is never blocked by these checks.
| They only decide whether an application is complete enough to SUBMIT.
|
| Use from review.php (or wherever the final submit is handled):
|
|     require_once __DIR__ . '/../includes/application-validation.php';
|
|     $organizationErrors =
|         pboOrganizationSectionErrors($connection, $applicationId);
|
|     if (count($organizationErrors) > 0) {
|         // show the list and do not submit
|     }
|
*/


/*
|--------------------------------------------------------------------------
| Registration date
|--------------------------------------------------------------------------
|
| An organization cannot have been registered on a date that has not
| happened yet. Testing found records carrying 2027 and 2028, and December
| 2026 - typed into a date box that accepted anything.
|
| Shared by create.php, edit.php and edit-submitted.php so all three agree,
| and mirrored by the max= attribute on the input for an immediate warning.
|
*/

/** The earliest registration date the form will accept. */
const PBO_EARLIEST_REGISTRATION_DATE = '1900-01-01';


/**
 * What is wrong with a registration date, or null when it is fine.
 *
 * Accepts a blank: whether the date is REQUIRED is a separate question,
 * answered by the page, so a blank gets one message rather than two.
 */
function pboRegistrationDateError(
    ?string $value,
    string $label = 'Registration Date'
): ?string {
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    /*
    | createFromFormat is forgiving - it reads "2026-13-45" as a date in
    | 2027. Comparing the formatted result back against the input is what
    | actually rejects a day that does not exist.
    */
    if ($date === false || $date->format('Y-m-d') !== $value) {
        return $label . ' is not a valid date.';
    }

    $today = new DateTimeImmutable('today');

    if ($date > $today) {
        return $label . ' cannot be in the future. '
            . 'Enter the date the organization was actually registered, '
            . 'on or before ' . $today->format('j M Y') . '.';
    }

    if ($date < new DateTimeImmutable(PBO_EARLIEST_REGISTRATION_DATE)) {
        return $label . ' looks wrong - it is before 1900. '
            . 'Check the year.';
    }

    return null;
}


/**
 * Today, as the date input's max= attribute wants it.
 */
function pboTodayForInput(): string
{
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}


/**
 * Returns a list of problems in the Organization page that must be fixed
 * before the application can be submitted. An empty array means the
 * Organization page is complete.
 *
 * Other areas of operation are optional: selecting counties is enough.
 * Office details for those counties only need to be valid if entered.
 *
 * @return string[]
 */
function pboOrganizationSectionErrors(PDO $db, int $applicationId): array
{
    $errors = [];

    $statement = $db->prepare(
        'SELECT pbo_name, pbo_registration_number, registration_date, registration_type'
        . (pboHeadquartersColumnAvailable($db) ? ', headquarters_in_kenya' : '') . '
         FROM pbo_applications
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute([':id' => $applicationId]);
    $application = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$application) {
        return ['Organization details have not been saved yet.'];
    }

    $isBlank = static function ($value): bool {
        return trim((string) ($value ?? '')) === '';
    };

    $isBadEmail = static function ($value): bool {
        $value = trim((string) ($value ?? ''));

        return $value !== ''
            && filter_var($value, FILTER_VALIDATE_EMAIL) === false;
    };

    /*
    | Organization details
    */

    $basicFields = [
        'pbo_name' => 'Name of the Public Benefit Organization',
        'pbo_registration_number' => 'PBO Registration Number',
        'registration_date' => 'Registration Date',
    ];

    foreach ($basicFields as $field => $label) {
        if ($isBlank($application[$field] ?? null)) {
            $errors[] = $label . ' is required.';
        }
    }

    $type = (string) ($application['registration_type'] ?? '');

    if (!in_array($type, ['national', 'international'], true)) {
        $errors[] = 'Type of Organization is required.';
    }

    /*
    |--------------------------------------------------------------------------
    | Head office - one card or the other, never both
    |--------------------------------------------------------------------------
    |
    | An organization has one head office. Where it is decides which card
    | has to be complete, and the other is not asked for at all:
    |
    |   in Kenya  -> the Kenyan head office card;
    |   abroad    -> the overseas card in its place.
    |
    | The old check demanded both of every international organization, and
    | that is the bug this fixes: organizations registered as international
    | whose head office is in Kenya have no body abroad to describe, so
    | staff copied the Kenyan details into the overseas fields to get past
    | the page. The register then said a Kenyan organization was its own
    | foreign parent.
    |
    | A national organization is in Kenya by definition, so it takes the
    | Kenyan branch without being asked.
    |
    */

    if (pboHeadquartersInKenya($application)) {

        $statement = $db->prepare(
            'SELECT county, physical_address, postal_address, phone_number, email
             FROM application_addresses
             WHERE application_id = :id
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute([':id' => $applicationId]);
        $address = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        $headquartersFields = [
            'county' => 'Head Office County',
            'physical_address' => 'Head Office Physical Address',
            'postal_address' => 'Head Office Postal Address',
            'phone_number' => 'Head Office Organization Phone Number',
            'email' => 'Head Office Email of the Organization',
        ];

        foreach ($headquartersFields as $field => $label) {
            if ($isBlank($address[$field] ?? null)) {
                $errors[] = $label . ' is required.';
            }
        }

        if ($isBadEmail($address['email'] ?? null)) {
            $errors[] = 'Head Office Email of the Organization must be a valid email address.';
        }

    } else {

        /*
        | The labels come from pboOverseasFields() rather than a second
        | copy here, so there is one place a label is written and the
        | page, the review list and this check cannot disagree about
        | what a field is called.
        */
        $overseasFields = pboOverseasFields();

        $statement = $db->prepare(
            'SELECT ' . implode(', ', array_keys($overseasFields)) . '
             FROM application_international_details
             WHERE application_id = :id
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute([':id' => $applicationId]);
        $international = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach ($overseasFields as $field => $label) {
            if ($isBlank($international[$field] ?? null)) {
                $errors[] = $label . ' is required.';
            }
        }

        foreach (['parent_email', 'contact_person_email'] as $field) {
            if ($isBadEmail($international[$field] ?? null)) {
                $errors[] = $overseasFields[$field]
                    . ' must be a valid email address.';
            }
        }
    }

    /*
    | An international organization has to have answered where its head
    | office is. Without the answer the check above falls back to
    | "abroad", which is a guess, and the submitted record would carry it
    | as a fact.
    */
    if ($type === 'international' && !pboHeadquartersAnswered($application)) {
        $errors[] = 'Say whether the head office is in Kenya.';
    }

    /*
    | Other areas of operation: details are optional,
    | but an email that was entered must be valid.
    */

    $statement = $db->prepare(
        'SELECT county, email
         FROM application_operating_locations
         WHERE application_id = :id'
    );
    $statement->execute([':id' => $applicationId]);

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $location) {
        if ($isBadEmail($location['email'] ?? null)) {
            $errors[] = (string) $location['county']
                . ': the organization email entered is not a valid email address.';
        }
    }

    /*
    | Areas of operation outside Kenya, on the same terms: naming the
    | country is enough, but an address that was typed has to be a real
    | one. Checked through the same shared function the save uses, so
    | the two cannot drift apart.
    */
    foreach (
        pboForeignLocationErrors(pboForeignLocations($db, $applicationId))
        as $foreignError
    ) {
        $errors[] = $foreignError;
    }

    return $errors;
}