<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Block capitals
|--------------------------------------------------------------------------
|
| The register is kept in block capitals, the way the paper forms are. Staff
| type normally and the portal stores the capitals, so nobody has to hold
| the shift key down for an afternoon and no record depends on which
| officer entered it.
|
| ONE THING TO BE CLEAR ABOUT: this is not reversible. Once "Murang'a" is
| stored as "MURANG'A" the original is gone, and names that carry meaning
| in their capitals - McDonald, de Souza, van der Merwe - lose it. For a
| register that is printed in capitals anyway that is an acceptable trade,
| but it is a trade, and it is made at the moment of saving rather than at
| the moment of printing.
|
| WHAT IS DELIBERATELY LEFT ALONE:
|
|   Email addresses. The part before the @ is case-sensitive by the
|   standard, and while most mail servers ignore that, some do not. An
|   address is something we send to, not something we display on a
|   certificate, and breaking delivery to save appearances is a bad bargain.
|   They are lower-cased instead, which IS safe and makes duplicates easier
|   to spot.
|
|   Passwords, obviously, and anything holding a web address.
|
*/


/**
 * Fields that hold a code or a filename, not text a person reads.
 *
 * Nothing here is displayed as words. Each one is either compared by the
 * program against a fixed value or used to find something on disk, so
 * raising its case breaks it rather than changing how anything looks.
 *
 * Two of these were found the hard way:
 *
 *   registration_type - "national" was stored as "NATIONAL", the check
 *   looking for "national" stopped matching, and every save on the
 *   Organization page was refused with "Please select the Type of
 *   Organization" while the box was correctly filled in. The form could
 *   not be saved at all.
 *
 *   existing_signature - the officials page posts back the filename of a
 *   signature already uploaded, so that re-saving the page does not need
 *   the file again. Raised to capitals it no longer matched the file on
 *   disk, and on Linux, where filenames are case-sensitive, the
 *   signature image was lost from the record.
 *
 * Matched on the whole field name, not a substring: a code field is a
 * specific field, and guessing from a fragment of a name is what would
 * quietly exempt somebody's actual data from the house rule.
 */
function pboFieldIsCode(string $field): bool
{
    return in_array(strtolower($field), [
        'registration_type',      /* national | international */
        'headquarters_in_kenya',  /* 1 | 0 */
        'beneficiary_scope',      /* public | section_of_public */
        'status',
        'form_action',
        'wizard_destination',
        'operates_abroad',        /* yes | no */
        'operates_other_locations',
        'registration_date',      /* a date, not a word */
        'signature',              /* a filename on disk */
        'existing_signature',     /* the same filename, posted back */
    ], true);
}


/**
 * Fields that keep the case they were typed in.
 *
 * Matched on the field name, so it works the same whether the value
 * arrived as `email`, `parent_email` or `officials[chairperson][email]`.
 */
function pboFieldKeepsCase(string $field): bool
{
    if (pboFieldIsCode($field)) {
        return true;
    }

    $field = strtolower($field);

    foreach (['email', 'password', 'url', 'website', 'token', 'csrf'] as $needle) {
        if (str_contains($field, $needle)) {
            return true;
        }
    }

    return false;
}


/**
 * One value, in block capitals.
 *
 * mb_strtoupper rather than strtoupper so that accented and non-ASCII
 * letters are raised too; strtoupper only knows about plain ASCII and
 * would leave half a name untouched.
 */
function pboBlockCaps(mixed $value): string
{
    return mb_strtoupper(trim((string) $value), 'UTF-8');
}


/**
 * Apply the house rule to one field, by name.
 *
 * Emails are lower-cased: not for appearance, but because it makes two
 * entries of the same address look the same.
 */
function pboStoredCase(string $field, mixed $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        return '';
    }

    if (!pboFieldKeepsCase($field)) {
        return pboBlockCaps($value);
    }

    if (str_contains(strtolower($field), 'email')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return $value;
}


/**
 * The same rule across an array of field => value.
 *
 * @param array<string, mixed> $values
 * @return array<string, string>
 */
function pboStoredCaseAll(array $values): array
{
    $out = [];

    foreach ($values as $field => $value) {
        $out[$field] = is_scalar($value) || $value === null
            ? pboStoredCase((string) $field, $value)
            : $value;
    }

    return $out;
}
