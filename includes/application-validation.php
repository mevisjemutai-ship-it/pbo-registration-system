<?php

declare(strict_types=1);

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
        'SELECT pbo_name, pbo_registration_number, registration_date, registration_type
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
    | Headquarters (latest saved row)
    */

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

    /*
    | International / parent organization details
    */

    if ($type === 'international') {

        $statement = $db->prepare(
            'SELECT parent_organization_name, country_of_origin, registration_number,
                    parent_physical_address, parent_postal_address, parent_postal_code,
                    parent_phone_number, parent_email, contact_person_name,
                    contact_person_phone, contact_person_email
             FROM application_international_details
             WHERE application_id = :id
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute([':id' => $applicationId]);
        $international = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

        $internationalFields = [
            'parent_organization_name' => 'Parent Organization Name',
            'country_of_origin' => 'Country of Origin',
            'registration_number' => 'Registration Number',
            'parent_physical_address' => 'Parent Physical Address',
            'parent_postal_address' => 'Parent Postal Address',
            'parent_postal_code' => 'Parent Postal Code',
            'parent_phone_number' => 'Parent Phone Number',
            'parent_email' => 'Parent Email',
            'contact_person_name' => 'Contact Person Name',
            'contact_person_phone' => 'Contact Person Phone',
            'contact_person_email' => 'Contact Person Email',
        ];

        foreach ($internationalFields as $field => $label) {
            if ($isBlank($international[$field] ?? null)) {
                $errors[] = $label . ' is required.';
            }
        }

        foreach (['parent_email' => 'Parent Email', 'contact_person_email' => 'Contact Person Email'] as $field => $label) {
            if ($isBadEmail($international[$field] ?? null)) {
                $errors[] = $label . ' must be a valid email address.';
            }
        }
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

    return $errors;
}