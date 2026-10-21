<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Rules about an official's particulars
|--------------------------------------------------------------------------
|
| Shared by applications/officials.php, edit.php and edit-submitted.php so
| the create wizard and the two edit pages cannot drift apart. Display
| pages use the same labels.
|
| The rule that matters
| ---------------------
| A KRA PIN is issued by the Kenya Revenue Authority, so only a Kenyan
| official reliably has one. Testing against real files found non-Kenyan
| officials - commonly on the boards of international organizations - with
| no KRA PIN at all, and the old form refused to save without one. Staff
| worked around it by inventing numbers, which is worse than a blank.
|
| So:
|   - Kenyan official      -> KRA PIN required.
|   - non-Kenyan official  -> KRA PIN not required. If they hold a tax
|                             number in their own country, it is recorded
|                             as what it is (type + number) rather than
|                             being forced into the KRA PIN box.
|
| Nationality is free text, so the match below is deliberately generous:
| the cost of reading "kenyan citizen" as Kenyan is nil, and the cost of
| not reading it that way is a form that refuses to save.
|
*/


/**
 * Whether this nationality should be treated as Kenyan.
 *
 * A blank nationality counts as NOT Kenyan: nationality is required on its
 * own, and the KRA PIN rule should not fire a second, confusing error
 * before the staff member has said who the person is.
 */
function officialIsKenyan(?string $nationality): bool
{
    $value = strtolower(trim((string) $nationality));

    if ($value === '') {
        return false;
    }

    /* Strip anything that is not a letter: "Kenyan (by birth)" etc. */
    $letters = preg_replace('/[^a-z]/', '', $value) ?? '';

    foreach (['kenyan', 'kenya', 'kenyancitizen', 'citizenofkenya'] as $match) {
        if ($letters === $match) {
            return true;
        }
    }

    /*
    | Catches "Kenyan by birth", "Dual - Kenyan and British", "Kenya Citizen".
    | A word-boundary check, so "Kenyatta" does not count.
    */
    return (bool) preg_match('/\bkenya(n)?\b/', $value);
}


/**
 * Is the KRA PIN compulsory for this official?
 */
function officialNeedsKraPin(array $record): bool
{
    return officialIsKenyan($record['nationality'] ?? '');
}


/**
 * Problems with one official's tax identification.
 *
 * @return string[] empty when there is nothing wrong
 */
function officialTaxIdErrors(array $record, string $label): array
{
    $errors = [];

    $kraPin = trim((string) ($record['kra_pin'] ?? ''));
    $taxType = trim((string) ($record['tax_id_type'] ?? ''));
    $taxNumber = trim((string) ($record['tax_id_number'] ?? ''));

    if (officialNeedsKraPin($record)) {

        if ($kraPin === '') {
            $errors[] = $label
                . ': KRA PIN is required for a Kenyan official. '
                . 'If this official is not Kenyan, correct the nationality first.';
        }
    }

    /*
    | A type with no number, or a number with no type, is half a record.
    | Either both or neither.
    */
    if ($taxType !== '' && $taxNumber === '') {
        $errors[] = $label
            . ': enter the tax identification number, or clear the type.';
    }

    if ($taxNumber !== '' && $taxType === '') {
        $errors[] = $label
            . ': say what kind of tax identification number this is '
            . '(for example TIN, SSN, NIF).';
    }

    return $errors;
}


/**
 * How to describe an official's tax identification on a page or report.
 *
 * Returns ['label' => ..., 'value' => ...], or null when there is nothing
 * recorded. Never prints a foreign number under the heading "KRA PIN".
 *
 * @return array{label: string, value: string}|null
 */
function officialTaxIdDisplay(array $record): ?array
{
    $kraPin = trim((string) ($record['kra_pin'] ?? ''));

    if ($kraPin !== '') {
        return ['label' => 'KRA PIN', 'value' => $kraPin];
    }

    $taxType = trim((string) ($record['tax_id_type'] ?? ''));
    $taxNumber = trim((string) ($record['tax_id_number'] ?? ''));

    if ($taxNumber === '') {
        return null;
    }

    return [
        'label' => $taxType !== ''
            ? 'Tax ID (' . $taxType . ')'
            : 'Tax ID',
        'value' => $taxNumber,
    ];
}


/**
 * Whether database/add_official_tax_id.sql has been run on this database.
 *
 * The pages check this before reading or writing the two new columns, so a
 * server that has the new code but not yet the migration keeps working
 * instead of throwing "Unknown column" at a member of staff mid-application.
 */
function officialTaxColumnsExist(PDO $connection): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        /*
        | Ask for the columns rather than for a list of columns: LIMIT 0
        | fetches no rows, so this costs nothing, and it answers the
        | question directly - can these two be selected? SHOW COLUMNS
        | would do the same on MySQL and fail on anything else, which
        | would quietly report "no" on a database that has them.
        */
        $result = $connection->query(
            'SELECT tax_id_type, tax_id_number FROM application_officials LIMIT 0'
        );

        /*
        | With PDO::ERRMODE_SILENT there is no exception to catch: query()
        | simply returns false. Checking the return value as well means the
        | answer is right whichever error mode the connection was built
        | with.
        */
        $exists = $result !== false;
    } catch (PDOException $e) {
        $exists = false;
    }

    return $exists;
}


/**
 * Tidy a tax identification type for storage: "ssn" -> "SSN", "tin" -> "TIN".
 */
function cleanTaxIdType(mixed $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    /* Short all-letter codes are acronyms; anything longer is a phrase. */
    if (strlen($value) <= 5 && preg_match('/^[A-Za-z]+$/', $value)) {
        return strtoupper($value);
    }

    return $value;
}
