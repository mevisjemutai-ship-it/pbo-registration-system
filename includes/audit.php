<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Audit trail helpers
|--------------------------------------------------------------------------
|
| Only administrator edits of submitted records are recorded.
|
| In applications/edit.php, before the save starts:
|
|     $auditBefore = pboAuditSnapshot($connection, $applicationId);
|
| After everything is saved, just before commit():
|
|     pboAuditRecord(
|         $connection,
|         $applicationId,
|         currentUserId(),
|         PBO_AUDIT_EDIT_ACTION,
|         'Administrator edited the submitted PBO record.',
|         pboAuditDiff($auditBefore, pboAuditSnapshot($connection, $applicationId))
|     );
|
| Each change is saved as one line, for example:
|
|     Added      Objective            Health
|     Removed    Beneficiary          Children
|     Changed    Organization name    Old Name  ->  New Name
|
*/

const PBO_AUDIT_EDIT_ACTION = 'submitted_record_edited';


/* ------------------------------------------------------------------
   Readable names for stored codes
   ------------------------------------------------------------------ */

function pboAuditObjectiveLabels(): array
{
    return [
        'AGRICULTURE' => 'Agriculture',
        'CHILDREN' => 'Children',
        'CULTURE' => 'Culture',
        'DISABILITY' => 'Disability',
        'ENERGY' => 'Energy',
        'EDUCATION' => 'Education',
        'ENVIRONMENTAL_CONSERVATION' => 'Environmental and Conservation',
        'GENDER' => 'Gender',
        'GOVERNANCE' => 'Governance',
        'POVERTY_ERADICATION' => 'Poverty Eradication',
        'HEALTH' => 'Health',
        'HOUSING_SETTLEMENT' => 'Housing & Settlement',
        'HUMAN_RIGHTS' => 'Human Rights',
        'HIV_AIDS' => 'HIV/AIDS',
        'INFORMATION' => 'Information',
        'INFORMAL_SECTOR' => 'Informal Sector',
        'LEGAL_AID' => 'Legal Aid',
        'OLD_AGE' => 'Old Age',
        'PEACE_BUILDING' => 'Peace Building',
        'POPULATION_REPRODUCTIVE_HEALTH' => 'Population & Reproductive Health',
        'REFUGEES' => 'Refugees',
        'DISASTER_PREVENTION' => 'Disaster prevention, preparedness and mitigation',
        'RELIEF_PASTORALISM_MARGINALIZED' => 'Relief Pastoralism and marginalized communities',
        'SPORTS' => 'Sports',
        'WATER_SANITATION' => 'Water and sanitation',
        'ANIMAL_WELFARE' => 'Animal welfare',
        'YOUTH' => 'Youth',
        'OTHER' => 'Other',
    ];
}

function pboAuditBeneficiaryLabels(): array
{
    return [
        'PUBLIC' => 'The general public',
        'CHILDREN' => 'Children',
        'ELDERLY' => 'The Elderly',
        'YOUTH' => 'Youth',
        'COMMERCIAL_SEX_WORKERS' => 'Commercial sex workers',
        'DRUG_ALCOHOL_ADDICTS' => 'Drug and alcohol addicts',
        'HOMELESS_PEOPLE' => 'Homeless people',
        'ORPHANS_VULNERABLE_CHILDREN' => 'Orphans and Vulnerable Children',
        'PERSONS_WITH_DISABILITY' => 'Persons with Disability',
        'REFUGEES' => 'Refugees',
        'STREET_CHILDREN' => 'Street Children',
        'THE_POOR' => 'The Poor',
        'PRISONERS_EX_CONVICTS' => 'Prisoners and ex-convicts',
        'PEOPLE_LIVING_WITH_HIV_AIDS' => 'People living with HIV/AIDS',
        'CAREGIVERS_VULNERABLE_GROUPS' => 'Caregivers for vulnerable groups (OVCs, Elderly, PLWAs)',
        'OTHER' => 'Other',
    ];
}

function pboAuditMonthName($value): string
{
    $number = (int) $value;

    if ($number < 1 || $number > 12) {
        return trim((string) $value);
    }

    return date('F', mktime(0, 0, 0, $number, 1));
}


/* ------------------------------------------------------------------
   Snapshot
   ------------------------------------------------------------------
   Returns:
     'fields' => ['Organization name' => 'Agri Trust', ...]
     'lists'  => [
         'Objective'   => ['HEALTH' => ['name' => 'Health', 'details' => []], ...],
         'Beneficiary' => [...],
         'Other area'  => ['Mombasa' => ['name' => 'Mombasa', 'details' => ['Phone' => '...']]],
         'Director'    => ['ID 1234' => ['name' => 'Jane Doe', 'details' => [...]]],
     ]
   ------------------------------------------------------------------ */

function pboAuditSnapshot(PDO $db, int $applicationId): array
{
    $fields = [];
    $lists = [
        'Objective'   => [],
        'Beneficiary' => [],
        'Other area'  => [],
        'Director'    => [],
    ];

    $one = static function (string $sql) use ($db, $applicationId): array {
        $statement = $db->prepare($sql);
        $statement->execute(['id' => $applicationId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    $all = static function (string $sql) use ($db, $applicationId): array {
        $statement = $db->prepare($sql);
        $statement->execute(['id' => $applicationId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    };

    $text = static fn ($value): string => trim((string) ($value ?? ''));


    /* Organization */

    $application = $one(
        'SELECT pbo_name, pbo_registration_number, registration_date, registration_type,
                financial_year_start_month, financial_year_end_month
         FROM pbo_applications WHERE id = :id LIMIT 1'
    );

    $fields['Organization name']        = $text($application['pbo_name'] ?? '');
    $fields['PBO registration number']  = $text($application['pbo_registration_number'] ?? '');
    $fields['Registration date']        = $text($application['registration_date'] ?? '');
    $fields['Type of organization']     = ucfirst($text($application['registration_type'] ?? ''));
    $fields['Financial year starts']    = pboAuditMonthName($application['financial_year_start_month'] ?? '');
    $fields['Financial year ends']      = pboAuditMonthName($application['financial_year_end_month'] ?? '');


    /* Headquarters */

    $address = $one(
        'SELECT * FROM application_addresses WHERE application_id = :id ORDER BY id DESC LIMIT 1'
    );

    $addressLabels = [
        'county'                   => 'County',
        'sub_county'               => 'Sub-county',
        'physical_address'         => 'Physical address',
        'postal_address'           => 'Postal address',
        'postal_code'              => 'Postal code',
        'phone_number'             => 'Phone',
        'alternative_phone_number' => 'Alternative phone',
        'email'                    => 'Email',
    ];

    $town = $text($address['town'] ?? '') !== ''
        ? $text($address['town'] ?? '')
        : $text($address['city_town'] ?? '');

    foreach ($addressLabels as $column => $label) {
        $fields['Headquarters ' . strtolower($label)] = $text($address[$column] ?? '');

        if ($column === 'county') {
            $fields['Headquarters town'] = $town;
        }
    }


    /* Other areas of operation */

    $locations = $all(
        'SELECT * FROM application_operating_locations WHERE application_id = :id ORDER BY county, id'
    );

    foreach ($locations as $location) {
        $county = $text($location['county'] ?? '');

        if ($county === '') {
            continue;
        }

        $details = [];

        foreach (['sub_county', 'physical_address', 'postal_address', 'postal_code',
                  'phone_number', 'alternative_phone_number', 'email'] as $column) {
            $details[$addressLabels[$column]] = $text($location[$column] ?? '');
        }

        $lists['Other area'][$county] = ['name' => $county, 'details' => $details];
    }


    /* International details */

    $international = $one(
        'SELECT * FROM application_international_details WHERE application_id = :id ORDER BY id DESC LIMIT 1'
    );

    $internationalLabels = [
        'parent_organization_name' => 'Parent organization',
        'country_of_origin'        => 'Country of origin',
        'registration_number'      => 'Parent registration number',
        'parent_physical_address'  => 'Parent physical address',
        'parent_postal_address'    => 'Parent postal address',
        'parent_postal_code'       => 'Parent postal code',
        'parent_phone_number'      => 'Parent phone',
        'parent_email'             => 'Parent email',
        'contact_person_name'      => 'Contact person',
        'contact_person_phone'     => 'Contact person phone',
        'contact_person_email'     => 'Contact person email',
    ];

    foreach ($internationalLabels as $column => $label) {
        $fields[$label] = $text($international[$column] ?? '');
    }


    /* Objectives */

    $objectiveLabels = pboAuditObjectiveLabels();

    foreach ($all('SELECT objective_code, description FROM application_objectives
                   WHERE application_id = :id ORDER BY id') as $row) {
        $code = strtoupper($text($row['objective_code']));
        $name = $objectiveLabels[$code] ?? ucwords(strtolower(str_replace('_', ' ', $code)));

        if ($code === 'OTHER' && $text($row['description']) !== '') {
            $name = 'Other: ' . $text($row['description']);
        }

        $lists['Objective'][$code] = ['name' => $name, 'details' => []];
    }


    /* Beneficiaries */

    $beneficiaryLabels = pboAuditBeneficiaryLabels();

    foreach ($all('SELECT beneficiary_code, description FROM application_beneficiaries
                   WHERE application_id = :id ORDER BY id') as $row) {
        $code = strtoupper($text($row['beneficiary_code']));
        $name = $beneficiaryLabels[$code] ?? ucwords(strtolower(str_replace('_', ' ', $code)));
        $details = [];

        if ($code === 'OTHER') {
            $other = json_decode($text($row['description']), true);

            if (is_array($other)) {
                if ($text($other['specify'] ?? '') !== '') {
                    $name = 'Other: ' . $text($other['specify']);
                }
                $details['Description'] = $text($other['description'] ?? '');
            } elseif ($text($row['description']) !== '') {
                $name = 'Other: ' . $text($row['description']);
            }
        }

        $lists['Beneficiary'][$code] = ['name' => $name, 'details' => $details];
    }


    /* Officials */

    $officials = $all(
        "SELECT * FROM application_officials WHERE application_id = :id
         ORDER BY FIELD(designation, 'CHAIRPERSON', 'TREASURER', 'SECRETARY', 'DIRECTOR'), id"
    );

    $officialLabels = [
        'full_name'          => 'name',
        'nationality'        => 'nationality',
        'id_passport_number' => 'ID / passport',
        'kra_pin'            => 'KRA PIN',
        'phone_number'       => 'phone',
        'email'              => 'email',
        'signature'          => 'signature',
    ];

    foreach ($officials as $official) {
        $designation = strtoupper($text($official['designation'] ?? ''));

        if ($designation === 'DIRECTOR') {
            $details = [];

            foreach ($officialLabels as $column => $label) {
                if ($column !== 'full_name') {
                    $details[ucfirst($label)] = $column === 'signature'
                        ? basename($text($official[$column] ?? ''))
                        : $text($official[$column] ?? '');
                }
            }

            $name = $text($official['full_name'] ?? '');
            $key  = $text($official['id_passport_number'] ?? '') !== ''
                ? 'ID ' . strtoupper($text($official['id_passport_number']))
                : 'Name ' . strtolower($name);

            $lists['Director'][$key] = ['name' => $name, 'details' => $details];
            continue;
        }

        $role = ucfirst(strtolower($designation));

        foreach ($officialLabels as $column => $label) {
            $fields[$role . ' ' . $label] = $column === 'signature'
                ? basename($text($official[$column] ?? ''))
                : $text($official[$column] ?? '');
        }
    }

    return ['fields' => $fields, 'lists' => $lists];
}


/* ------------------------------------------------------------------
   Diff
   ------------------------------------------------------------------
   Returns a list of changes:
     ['type' => 'added'|'removed'|'changed', 'field' => ..., 'before' => ..., 'after' => ...]
   ------------------------------------------------------------------ */

function pboAuditDiff(array $before, array $after): array
{
    $changes = [];

    $add = static function (string $field, string $old, string $new) use (&$changes): void {
        if ($old === $new) {
            return;
        }

        $changes[] = [
            'type'   => $old === '' ? 'added' : ($new === '' ? 'removed' : 'changed'),
            'field'  => $field,
            'before' => $old,
            'after'  => $new,
        ];
    };


    /* Single fields */

    $beforeFields = $before['fields'] ?? [];
    $afterFields  = $after['fields'] ?? [];

    foreach (array_unique(array_merge(array_keys($beforeFields), array_keys($afterFields))) as $field) {
        $add((string) $field, (string) ($beforeFields[$field] ?? ''), (string) ($afterFields[$field] ?? ''));
    }


    /* Lists: objectives, beneficiaries, other areas, directors */

    $beforeLists = $before['lists'] ?? [];
    $afterLists  = $after['lists'] ?? [];

    foreach (array_unique(array_merge(array_keys($beforeLists), array_keys($afterLists))) as $listName) {
        $old = $beforeLists[$listName] ?? [];
        $new = $afterLists[$listName] ?? [];

        foreach ($new as $key => $item) {
            if (!array_key_exists($key, $old)) {
                $changes[] = [
                    'type'   => 'added',
                    'field'  => (string) $listName,
                    'before' => '',
                    'after'  => pboAuditItemText($item),
                ];
            }
        }

        foreach ($old as $key => $item) {
            if (!array_key_exists($key, $new)) {
                $changes[] = [
                    'type'   => 'removed',
                    'field'  => (string) $listName,
                    'before' => pboAuditItemText($item),
                    'after'  => '',
                ];
            }
        }

        foreach ($new as $key => $item) {
            if (!array_key_exists($key, $old)) {
                continue;
            }

            $oldItem = $old[$key];
            $prefix  = $listName . ' (' . ($item['name'] !== '' ? $item['name'] : $key) . ')';

            if ((string) $oldItem['name'] !== (string) $item['name']) {
                $add($prefix . ' name', (string) $oldItem['name'], (string) $item['name']);
            }

            $detailKeys = array_unique(array_merge(
                array_keys($oldItem['details'] ?? []),
                array_keys($item['details'] ?? [])
            ));

            foreach ($detailKeys as $detail) {
                $add(
                    $prefix . ' ' . strtolower((string) $detail),
                    (string) ($oldItem['details'][$detail] ?? ''),
                    (string) ($item['details'][$detail] ?? '')
                );
            }
        }
    }

    return $changes;
}

/**
 * "Mombasa (Sub-county: Nyali, Phone: 0712...)" - only filled details.
 */
function pboAuditItemText(array $item): string
{
    $parts = [];

    foreach ($item['details'] ?? [] as $label => $value) {
        if ((string) $value !== '' && strcasecmp((string) $label, 'signature') !== 0) {
            $parts[] = $label . ': ' . $value;
        }
    }

    $name = (string) ($item['name'] ?? '');

    return $parts === [] ? $name : $name . ' (' . implode(', ', $parts) . ')';
}


/* ------------------------------------------------------------------
   Save
   ------------------------------------------------------------------ */

function pboAuditColumns(PDO $db): array
{
    static $columns = null;

    if ($columns === null) {
        $columns = [];

        foreach ($db->query('SHOW COLUMNS FROM application_audit_log')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[$column['Field']] = true;
        }
    }

    return $columns;
}

function pboAuditRecord(
    PDO $db,
    int $applicationId,
    int $userId,
    string $action,
    string $description,
    array $changes = []
): void {
    $values = [
        'application_id' => $applicationId,
        'user_id'        => $userId,
        'action'         => $action,
        'description'    => $description,
        'changes'        => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'ip_address'     => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ];

    /* Works even if sql/audit-trail.sql has not been run yet. */
    $values = array_intersect_key($values, pboAuditColumns($db));

    $names = array_keys($values);

    $db->prepare(
        'INSERT INTO application_audit_log (' . implode(', ', $names) . ')
         VALUES (:' . implode(', :', $names) . ')'
    )->execute($values);
}


/* ------------------------------------------------------------------
   Display helpers for admin/audit-trail.php
   ------------------------------------------------------------------ */

/**
 * Read a stored changes column. Also understands the older
 * {field, before, after} format.
 */
function pboAuditDecodeChanges(?string $json): array
{
    $decoded = json_decode((string) $json, true);

    if (!is_array($decoded)) {
        return [];
    }

    $changes = [];

    foreach ($decoded as $change) {
        if (!is_array($change) || !isset($change['field'])) {
            continue;
        }

        $old = (string) ($change['before'] ?? '');
        $new = (string) ($change['after'] ?? '');

        $changes[] = [
            'type'   => (string) ($change['type'] ?? ($old === '' ? 'added' : ($new === '' ? 'removed' : 'changed'))),
            'field'  => (string) $change['field'],
            'before' => $old,
            'after'  => $new,
        ];
    }

    return $changes;
}

function pboAuditIsSignature(string $field): bool
{
    return str_ends_with(strtolower($field), 'signature');
}
