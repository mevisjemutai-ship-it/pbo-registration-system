<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Registry filters and ordering
|--------------------------------------------------------------------------
|
| One place that reads the filter values off the URL and turns them into
| SQL. Shared by the List of PBOs page and by the export, so that
| "export what I am looking at" means exactly what it says: the same
| filters, read by the same code, producing the same rows.
|
| Writing it twice is how an export quietly starts disagreeing with the
| screen it was printed from.
|
*/


/** Organization types the registry recognises. */
function pboRegistryTypes(): array
{
    return [
        'national' => 'National',
        'international' => 'International',
    ];
}


/**
 * How the list may be ordered, and the SQL for each.
 *
 * Name ordering puts the register in the shape people read it in; the
 * date orderings answer "what came in recently" and "what is oldest".
 */
function pboRegistrySortOptions(): array
{
    return [
        'name'    => ['label' => 'Name (A to Z)',     'sql' => 'pbo_name ASC, id ASC'],
        'name_z'  => ['label' => 'Name (Z to A)',     'sql' => 'pbo_name DESC, id DESC'],
        'newest'  => ['label' => 'Newest first',      'sql' => 'registration_date DESC, pbo_name ASC, id DESC'],
        'oldest'  => ['label' => 'Oldest first',      'sql' => 'registration_date ASC, pbo_name ASC, id ASC'],
    ];
}


/**
 * Read every filter off the query string, cleaned and validated.
 *
 * Anything unrecognised becomes blank rather than an error: a filter is
 * not worth a failed page, and a blank filter shows everything, which is
 * the safe direction to fail in.
 *
 * @return array{search: string, year: string, type: string, letter: string, sort: string}
 */
function pboRegistryFilters(array $source): array
{
    $search = trim((string) ($source['q'] ?? ''));
    $year   = trim((string) ($source['year'] ?? ''));
    $type   = trim((string) ($source['type'] ?? ''));
    $letter = strtoupper(trim((string) ($source['letter'] ?? '')));
    $sort   = trim((string) ($source['sort'] ?? ''));

    if (!preg_match('/^\d{4}$/', $year)) {
        $year = '';
    }

    if (!array_key_exists($type, pboRegistryTypes())) {
        $type = '';
    }

    /*
    | A single letter, or "#" for the organizations whose name begins with
    | a digit or punctuation - every register has a few, and without the
    | "#" option they are unreachable from an A-Z filter.
    */
    if ($letter !== '' && $letter !== '#' && !preg_match('/^[A-Z]$/', $letter)) {
        $letter = '';
    }

    if (!array_key_exists($sort, pboRegistrySortOptions())) {
        $sort = 'name';
    }

    return [
        'search' => $search,
        'year'   => $year,
        'type'   => $type,
        'letter' => $letter,
        'sort'   => $sort,
    ];
}


/**
 * Turn those filters into a WHERE clause and its parameters.
 *
 * @return array{sql: string, params: array<string, mixed>}
 */
function pboRegistryWhere(array $filters): array
{
    $where = ["status = 'submitted'"];
    $params = [];

    if ($filters['search'] !== '') {
        /*
        | Two placeholders for one value: PDO's emulation setting decides
        | whether a name may be reused, and reusing one here produced
        | SQLSTATE[HY093] before.
        */
        $where[] = '(pbo_name LIKE :search_name
                     OR pbo_registration_number LIKE :search_registration)';

        $params[':search_name'] = '%' . $filters['search'] . '%';
        $params[':search_registration'] = '%' . $filters['search'] . '%';
    }

    if ($filters['year'] !== '') {
        $where[] = 'YEAR(registration_date) = :registration_year';
        $params[':registration_year'] = (int) $filters['year'];
    }

    if ($filters['type'] !== '') {
        $where[] = 'registration_type = :registration_type';
        $params[':registration_type'] = $filters['type'];
    }

    if ($filters['letter'] === '#') {
        /*
        | Everything that does NOT begin with a letter. Written as a
        | character-class comparison rather than a regular expression so
        | it behaves the same across MySQL versions.
        */
        $where[] = "UPPER(LEFT(TRIM(pbo_name), 1)) NOT BETWEEN 'A' AND 'Z'";
    } elseif ($filters['letter'] !== '') {
        $where[] = 'UPPER(LEFT(TRIM(pbo_name), 1)) = :first_letter';
        $params[':first_letter'] = $filters['letter'];
    }

    return [
        'sql' => implode(' AND ', $where),
        'params' => $params,
    ];
}


/** The ORDER BY for the chosen sort. Never built from user input. */
function pboRegistryOrderBy(array $filters): string
{
    $options = pboRegistrySortOptions();

    return $options[$filters['sort']]['sql'] ?? $options['name']['sql'];
}


/**
 * Bind the filter parameters, with the year as an integer.
 *
 * bindValue rather than execute(), because the list query also binds
 * LIMIT and OFFSET, which MySQL rejects as strings.
 */
function pboRegistryBind(PDOStatement $statement, array $params): void
{
    foreach ($params as $name => $value) {
        $statement->bindValue(
            $name,
            $value,
            $name === ':registration_year' ? PDO::PARAM_INT : PDO::PARAM_STR
        );
    }
}


/**
 * A sentence describing what is being shown, for the top of an export.
 *
 * An exported file outlives the screen it came from. Without this, a
 * printed list of 40 organizations is indistinguishable from a printed
 * register of 40 organizations, and somebody will read it as the latter.
 */
function pboRegistryDescription(array $filters, ?int $selectedCount = null): string
{
    if ($selectedCount !== null) {
        return $selectedCount === 1
            ? '1 organization, chosen from the register'
            : $selectedCount . ' organizations, chosen from the register';
    }

    $parts = [];

    if ($filters['letter'] === '#') {
        $parts[] = 'names not beginning with a letter';
    } elseif ($filters['letter'] !== '') {
        $parts[] = 'names beginning with ' . $filters['letter'];
    }

    if ($filters['type'] !== '') {
        $parts[] = strtolower(pboRegistryTypes()[$filters['type']]) . ' organizations';
    }

    if ($filters['year'] !== '') {
        $parts[] = 'registered in ' . $filters['year'];
    }

    if ($filters['search'] !== '') {
        $parts[] = 'matching "' . $filters['search'] . '"';
    }

    if ($parts === []) {
        return 'All registered organizations';
    }

    return 'Filtered: ' . implode('; ', $parts);
}
