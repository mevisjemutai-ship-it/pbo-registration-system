<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Where an organization is based, and where it works
|--------------------------------------------------------------------------
|
| The problem this solves
| -----------------------
| "International" on the form used to mean "a branch of a foreign parent
| body", and the section it opened demanded eleven particulars about that
| parent. Testing against real files found organizations registered as
| international whose head office is in Kenya and which have no parent body
| at all - so staff filled the overseas fields with the Kenyan head office
| details to get past the page, and the register ended up recording a
| Kenyan organization as the foreign parent of itself.
|
| The form now asks outright whether the head office is in Kenya:
|
|   yes  - the ordinary Kenyan head office card, the same one a national
|          organization fills in, and nothing overseas is recorded;
|   no   - the overseas card takes its place.
|
| Separately, an organization of either type may work outside Kenya. The
| only place to record where an organization operates was a list of the 47
| counties, so Canada, the United States and France went unrecorded. There
| is now a section for those, open to national organizations too - a Kenyan
| organization running a programme abroad is an ordinary thing, and the
| register could not say so.
|
| ON THE WORD "PARENT"
| --------------------
| The labels no longer say it: the overseas card describes the
| organization's own head office abroad, not a separate body it answers to.
| The database columns are still named parent_*, deliberately. Renaming
| eleven columns would touch nine files to change nothing a person sees,
| and the names are documented here instead. If they are ever renamed, this
| is the file to start from.
|
*/

require_once __DIR__ . '/text-case.php';


/*
| The overseas head office fields: column name => label as staff see it.
|
| The column names are the old parent_* ones; only the labels changed.
*/
function pboOverseasFields(): array
{
    return [
        'parent_organization_name' => 'Name of the Organization',
        'country_of_origin'        => 'Country',
        'registration_number'      => 'Registration Number in that Country',
        'parent_physical_address'  => 'Physical Address',
        'parent_postal_address'    => 'Postal Address',
        'parent_postal_code'       => 'Postal Code',
        'parent_phone_number'      => 'Telephone',
        'parent_email'             => 'Email',
        'contact_person_name'      => 'Contact Person',
        'contact_person_phone'     => 'Contact Person Telephone',
        'contact_person_email'     => 'Contact Person Email',
    ];
}


/** The heading the overseas card carries. */
function pboOverseasHeading(): string
{
    return 'International Organization Details (Head Office Outside Kenya)';
}


/**
 * Is this record's head office in Kenya?
 *
 * A national organization always is. An international one is whatever was
 * answered - and NULL, meaning never asked, is read as "abroad", because
 * that is what the old form assumed of every international record. Reading
 * it the other way would silently reclassify every record entered before
 * the question existed.
 */
function pboHeadquartersInKenya(array $application): bool
{
    $type = strtolower(trim((string) ($application['registration_type'] ?? '')));

    if ($type !== 'international') {
        return true;
    }

    $answer = $application['headquarters_in_kenya'] ?? null;

    if ($answer === null || $answer === '') {
        return false;
    }

    return (int) $answer === 1;
}


/** Does this record use the overseas card rather than the Kenyan one? */
function pboUsesOverseasHeadOffice(array $application): bool
{
    return !pboHeadquartersInKenya($application);
}


/**
 * Whether the headquarters question has ever been answered.
 *
 * Worth distinguishing on a records page: "not recorded" is honest about
 * an older record, where "head office abroad" would be a guess presented
 * as a fact.
 */
function pboHeadquartersAnswered(array $application): bool
{
    $type = strtolower(trim((string) ($application['registration_type'] ?? '')));

    if ($type !== 'international') {
        return true;
    }

    $answer = $application['headquarters_in_kenya'] ?? null;

    return $answer !== null && $answer !== '';
}


/**
 * Which head office card a FORM should show.
 *
 * Deliberately not the same question as pboUsesOverseasHeadOffice().
 * Reading a stored record, "never answered" has to mean abroad, because
 * that is what the old form assumed of every international record. On the
 * form itself it means not answered yet, and the honest thing is to show
 * NEITHER card until it is answered - so the question is the obvious next
 * thing to deal with, rather than a card appearing that may be the wrong
 * one.
 *
 * The page's JavaScript follows exactly this, so what the server renders
 * and what happens when the radio is clicked cannot disagree.
 *
 * @return array{question: bool, kenyan: bool, overseas: bool}
 */
function pboHeadOfficeCards(string $registrationType, string $answer): array
{
    $international = strtolower(trim($registrationType)) === 'international';

    return [
        'question' => $international,
        'kenyan'   => !$international || $answer === '1',
        'overseas' => $international && $answer === '0',
    ];
}


/*
| Areas of operation outside Kenya: column name => label.
|
| Deliberately the same shape as a county card, with country in place of
| county and city in place of sub-county, so staff meet one pattern rather
| than two.
*/
function pboForeignLocationFields(): array
{
    return [
        'country'                  => 'Country',
        'city'                     => 'City / Town',
        'physical_address'         => 'Physical Address',
        'postal_address'           => 'Postal Address',
        'postal_code'              => 'Postal Code',
        'phone_number'             => 'Telephone',
        'alternative_phone_number' => 'Alternative Telephone',
        'email'                    => 'Email',
    ];
}


/** Just the column names, for building queries. */
function pboForeignLocationColumns(): array
{
    return array_keys(pboForeignLocationFields());
}


/**
 * Does this table exist yet?
 *
 * The pages check before reading or writing, so a server running the new
 * code without the migration keeps working instead of failing in front of
 * a member of staff. Asks for the columns rather than for a list of them,
 * which works on any database and in either PDO error mode.
 */
function pboForeignLocationsAvailable(PDO $connection): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        $available = $connection->query(
            'SELECT id FROM application_foreign_locations LIMIT 0'
        ) !== false;
    } catch (PDOException $e) {
        $available = false;
    }

    return $available;
}


/** Same question for the headquarters column. */
function pboHeadquartersColumnAvailable(PDO $connection): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        $available = $connection->query(
            'SELECT headquarters_in_kenya FROM pbo_applications LIMIT 0'
        ) !== false;
    } catch (PDOException $e) {
        $available = false;
    }

    return $available;
}


/**
 * Read the foreign locations for one application.
 *
 * @return list<array<string, string>>
 */
function pboForeignLocations(PDO $connection, int $applicationId): array
{
    if (!pboForeignLocationsAvailable($connection)) {
        return [];
    }

    $statement = $connection->prepare(
        'SELECT ' . implode(', ', pboForeignLocationColumns()) . '
           FROM application_foreign_locations
          WHERE application_id = :id
       ORDER BY country, id'
    );

    $statement->execute([':id' => $applicationId]);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


/**
 * Take the posted rows and keep the ones worth storing.
 *
 * A row with no country is not an area of operation - it is an empty card
 * somebody added and did not fill in. Those are dropped silently rather
 * than reported as errors: adding a card and changing your mind is not a
 * mistake worth a message.
 *
 * @return list<array<string, string>>
 */
function pboNormaliseForeignLocations(mixed $posted): array
{
    if (!is_array($posted)) {
        return [];
    }

    $rows = [];

    foreach ($posted as $row) {
        if (!is_array($row)) {
            continue;
        }

        $clean = [];

        foreach (pboForeignLocationColumns() as $column) {
            $clean[$column] = pboStoredCase($column, $row[$column] ?? '');
        }

        if ($clean['country'] === '') {
            continue;
        }

        $rows[] = $clean;
    }

    return $rows;
}


/**
 * Replace the stored foreign locations with these.
 *
 * Delete then insert, the same pattern the other child tables use, so it
 * works whether or not there is a unique key and a save can never leave
 * duplicates behind. Expects to be inside the caller's transaction.
 */
function pboSaveForeignLocations(PDO $connection, int $applicationId, array $rows): void
{
    if (!pboForeignLocationsAvailable($connection)) {
        return;
    }

    $connection
        ->prepare('DELETE FROM application_foreign_locations WHERE application_id = :id')
        ->execute([':id' => $applicationId]);

    if ($rows === []) {
        return;
    }

    $columns = pboForeignLocationColumns();

    $insert = $connection->prepare(
        'INSERT INTO application_foreign_locations
            (application_id, ' . implode(', ', $columns) . ')
         VALUES
            (:application_id, :' . implode(', :', $columns) . ')'
    );

    foreach ($rows as $row) {
        $parameters = [':application_id' => $applicationId];

        foreach ($columns as $column) {
            $parameters[':' . $column] = $row[$column] !== '' ? $row[$column] : null;
        }

        $insert->execute($parameters);
    }
}


/**
 * Problems with the foreign locations, for the submit-time check.
 *
 * @return string[]
 */
function pboForeignLocationErrors(array $rows): array
{
    $errors = [];

    foreach ($rows as $index => $row) {

        $where = trim((string) ($row['country'] ?? '')) !== ''
            ? (string) $row['country']
            : 'Area outside Kenya ' . ($index + 1);

        $email = trim((string) ($row['email'] ?? ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = $where . ': the email entered is not a valid email address.';
        }
    }

    return $errors;
}
