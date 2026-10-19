<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Kenya location lookups
|--------------------------------------------------------------------------
|
| One source of truth for counties and sub-counties, shared by create.php,
| edit.php, view.php, drafts.php and export-excel.php. Previously the county
| list was hardcoded inside create.php only, so the other pages had no way
| to validate what they were showing.
|
| Reads the kenya_* tables created by database/add_kenya_lookups.sql. If
| those tables are not installed yet, kenyaCounties() falls back to the
| original hardcoded list, so nothing breaks on a machine that hasn't run
| the migration.
|
| Nothing here changes how addresses are stored: application_addresses and
| application_operating_locations keep their VARCHAR county / sub_county
| columns and keep storing names.
*/

/**
 * The original hardcoded list, kept as a fallback.
 *
 * @return list<string>
 */
function kenyaCountiesFallback(): array
{
    return [
        'Mombasa', 'Kwale', 'Kilifi', 'Tana River', 'Lamu', 'Taita-Taveta',
        'Garissa', 'Wajir', 'Mandera', 'Marsabit', 'Isiolo', 'Meru',
        'Tharaka-Nithi', 'Embu', 'Kitui', 'Machakos', 'Makueni', 'Nyandarua',
        'Nyeri', 'Kirinyaga', "Murang'a", 'Kiambu', 'Turkana', 'West Pokot',
        'Samburu', 'Trans Nzoia', 'Uasin Gishu', 'Elgeyo-Marakwet', 'Nandi',
        'Baringo', 'Laikipia', 'Nakuru', 'Narok', 'Kajiado', 'Kericho',
        'Bomet', 'Kakamega', 'Vihiga', 'Bungoma', 'Busia', 'Siaya', 'Kisumu',
        'Homa Bay', 'Migori', 'Kisii', 'Nyamira', 'Nairobi City County',
    ];
}

/**
 * All 47 county names, in the official county-code order the form expects.
 *
 * @return list<string>
 */
function kenyaCounties(?PDO $connection = null): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    if ($connection instanceof PDO) {
        try {
            $rows = $connection
                ->query('SELECT county_name FROM kenya_counties ORDER BY county_code')
                ->fetchAll(PDO::FETCH_COLUMN);

            if (is_array($rows) && count($rows) === 47) {
                $cache = $rows;

                return $cache;
            }
        } catch (PDOException $e) {
            // Table not installed yet - fall through to the static list.
        }
    }

    $cache = kenyaCountiesFallback();

    return $cache;
}

/**
 * Sub-counties for one county, looked up by county NAME (that is what the
 * form posts). Returns an empty array for an unknown or empty county.
 *
 * @return list<string>
 */
function kenyaSubCounties(PDO $connection, string $countyName): array
{
    $countyName = trim($countyName);

    if ($countyName === '') {
        return [];
    }

    try {
        $stmt = $connection->prepare(
            'SELECT s.sub_county_name
               FROM kenya_sub_counties s
               JOIN kenya_counties c ON c.county_code = s.county_code
              WHERE c.county_name = :name
           ORDER BY s.sub_county_name'
        );
        $stmt->execute([':name' => $countyName]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Every sub-county grouped by county name.
 *
 * Used to hand the whole map to the browser in one go: it is roughly 8 KB
 * of JSON, far cheaper than a lookup request per keystroke, and it makes
 * the dynamically-added operating-location rows work with no extra wiring.
 *
 * @return array<string, list<string>>
 */
function kenyaSubCountyMap(PDO $connection): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    try {
        $rows = $connection->query(
            'SELECT c.county_name, s.sub_county_name
               FROM kenya_sub_counties s
               JOIN kenya_counties c ON c.county_code = s.county_code
           ORDER BY c.county_code, s.sub_county_name'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cache = [];

        return $cache;
    }

    $map = [];

    foreach ($rows as $row) {
        $map[$row['county_name']][] = $row['sub_county_name'];
    }

    $cache = $map;

    return $cache;
}

/**
 * Postal codes for the datalist: code => post office label.
 *
 * The county is appended to the label only when Posta Kenya's own locator
 * confirmed it. Roughly a third of the rows carry a county from a
 * third-party list, and some of those are plainly wrong (Isinya shown in
 * Migori, Manga in Taita-Taveta, several places dumped into Nairobi), so
 * an unverified county is never shown and never used to fill a field.
 *
 * @return list<array{code: string, label: string}>
 */
function kenyaPostalCodes(PDO $connection): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    try {
        $rows = $connection->query(
            'SELECT p.postal_code, p.post_office, p.county_verified, c.county_name
               FROM kenya_postal_codes p
          LEFT JOIN kenya_counties c ON c.county_code = p.county_code
           ORDER BY p.postal_code'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $cache = [];

        return $cache;
    }

    $out = [];

    foreach ($rows as $row) {
        $label = (string) $row['post_office'];

        if ((int) $row['county_verified'] === 1 && $row['county_name'] !== null) {
            $label .= ' - ' . $row['county_name'];
        }

        $out[] = [
            'code'  => (string) $row['postal_code'],
            'label' => $label,
        ];
    }

    $cache = $out;

    return $cache;
}

/**
 * Is this sub-county actually in this county?
 *
 * Returns true when we cannot tell (lookups missing, county blank), so this
 * is only ever used to raise a soft warning on the review step - never to
 * block a submission. Officers sometimes know about boundaries before the
 * reference data does.
 */
function kenyaSubCountyMatchesCounty(PDO $connection, string $countyName, string $subCountyName): bool
{
    $subCountyName = trim($subCountyName);
    $countyName = trim($countyName);

    if ($subCountyName === '' || $countyName === '') {
        return true;
    }

    $valid = kenyaSubCounties($connection, $countyName);

    if ($valid === []) {
        return true;
    }

    foreach ($valid as $candidate) {
        if (strcasecmp($candidate, $subCountyName) === 0) {
            return true;
        }
    }

    return false;
}
