<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| registry-export-test.php
|--------------------------------------------------------------------------
|
|   php tests/registry-export-test.php
|
| Builds a throwaway SQLite database with a handful of organizations and
| renders applications/export-registry.php against it, once per mode, so
| that a broken query or a bad variable is caught here rather than by a
| member of staff pressing Print.
|
| SQLite rather than MySQL so the test runs anywhere, including a machine
| with no database server. The handful of MySQL functions the page uses
| are registered below.
|
| It writes a temporary config/database.php and removes it afterwards; if
| one already exists it refuses rather than overwrite it.
*/

/*
| On a deployed portal there is already a config/database.php pointing at
| the real database. _sandbox.php copies the portal somewhere temporary
| and re-runs this test there, so the installation is never written to.
*/
require __DIR__ . '/_sandbox.php';

$root = dirname(__DIR__);
$configPath = $root . '/config/database.php';

if (is_file($configPath)) {
    fwrite(STDERR, "config/database.php is still present - refusing to overwrite it.\n");
    exit(2);
}

$dbPath = sys_get_temp_dir() . '/pbo-registry-test-' . getmypid() . '.sqlite';

register_shutdown_function(static function () use ($configPath, $dbPath): void {
    @unlink($configPath);
    @unlink($dbPath);
});


/*
|--------------------------------------------------------------------------
| The database
|--------------------------------------------------------------------------
*/

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* The MySQL functions the registry queries lean on. */
$pdo->sqliteCreateFunction('YEAR', static fn ($d) => (int) substr((string) $d, 0, 4), 1);
$pdo->sqliteCreateFunction('LEFT', static fn ($s, $n) => substr((string) $s, 0, (int) $n), 2);

$pdo->exec('
    CREATE TABLE pbo_applications (
        id INTEGER PRIMARY KEY,
        application_reference TEXT,
        pbo_name TEXT,
        pbo_registration_number TEXT,
        registration_date TEXT,
        registration_type TEXT,
        headquarters_in_kenya INTEGER,
        status TEXT,
        current_step INTEGER,
        financial_year_start_month INTEGER,
        financial_year_end_month INTEGER,
        submitted_at TEXT,
        created_by INTEGER
    );
    CREATE TABLE application_addresses (
        id INTEGER PRIMARY KEY, application_id INTEGER, county TEXT, sub_county TEXT,
        physical_address TEXT, postal_address TEXT, postal_code TEXT,
        phone_number TEXT, alternative_phone_number TEXT, email TEXT
    );
    CREATE TABLE application_operating_locations (
        id INTEGER PRIMARY KEY, application_id INTEGER, county TEXT, sub_county TEXT,
        physical_address TEXT, postal_address TEXT, postal_code TEXT,
        phone_number TEXT, alternative_phone_number TEXT, email TEXT
    );
    CREATE TABLE application_international_details (
        id INTEGER PRIMARY KEY, application_id INTEGER,
        parent_organization_name TEXT, country_of_origin TEXT, registration_number TEXT,
        parent_physical_address TEXT, parent_postal_address TEXT, parent_postal_code TEXT,
        parent_phone_number TEXT, parent_email TEXT,
        contact_person_name TEXT, contact_person_phone TEXT, contact_person_email TEXT
    );
    CREATE TABLE application_foreign_locations (
        id INTEGER PRIMARY KEY, application_id INTEGER, country TEXT, city TEXT,
        physical_address TEXT, postal_address TEXT, postal_code TEXT,
        phone_number TEXT, alternative_phone_number TEXT, email TEXT
    );
    CREATE TABLE application_objectives (
        id INTEGER PRIMARY KEY, application_id INTEGER, objective_code TEXT, description TEXT
    );
    CREATE TABLE application_beneficiaries (
        id INTEGER PRIMARY KEY, application_id INTEGER, beneficiary_code TEXT, description TEXT
    );
    CREATE TABLE application_officials (
        id INTEGER PRIMARY KEY, application_id INTEGER, full_name TEXT, designation TEXT,
        nationality TEXT, id_passport_number TEXT, kra_pin TEXT,
        tax_id_type TEXT, tax_id_number TEXT, phone_number TEXT, email TEXT, signature TEXT
    );
');

$organizations = [
    [1, 'Amani Children Trust',        'PBO/2019/0041', '2019-03-14', 'national'],
    [2, 'Baraka Health Initiative',    'PBO/2021/0188', '2021-07-02', 'national'],
    [3, 'Cross-Border Relief Network', 'PBO/2023/0310', '2023-11-29', 'international'],
    [4, '4Hope Foundation',            'PBO/2020/0099', '2020-01-20', 'national'],
    [5, 'Zawadi Education Fund',       'PBO/2024/0402', '2024-05-06', 'national'],
];

$insert = $pdo->prepare(
    'INSERT INTO pbo_applications
        (id, application_reference, pbo_name, pbo_registration_number,
         registration_date, registration_type, status, current_step,
         financial_year_start_month, financial_year_end_month, submitted_at, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, 6, 1, 12, ?, 1)'
);

foreach ($organizations as [$id, $name, $number, $date, $type]) {
    $insert->execute([
        $id, 'APP-' . $id, $name, $number, $date, $type, 'submitted', $date . ' 09:00:00',
    ]);
}

/* A draft, which must never appear in an export of the register. */
$insert->execute([
    99, 'APP-99', 'Draft Organization That Must Not Appear',
    'PBO/2026/9999', '2026-01-01', 'national', 'draft', '2026-01-01 09:00:00',
]);

$pdo->exec("UPDATE pbo_applications SET headquarters_in_kenya = 0 WHERE id = 3");

$pdo->exec("
    INSERT INTO application_international_details
        (application_id, parent_organization_name, country_of_origin,
         registration_number, parent_physical_address, parent_postal_address,
         parent_postal_code, parent_phone_number, parent_email,
         contact_person_name, contact_person_phone, contact_person_email)
    VALUES (3, 'Cross-Border Relief Network', 'Canada', 'CA-998877',
            '120 Bay Street', 'PO Box 44', 'M5J 2T3', '+1 416 555 0000',
            'head@relief.example', 'Louise Tremblay', '+1 416 555 1111',
            'louise@relief.example');

    INSERT INTO application_foreign_locations
        (application_id, country, city, physical_address, phone_number, email)
    VALUES (1, 'Uganda', 'Kampala', 'Plot 14 Kira Road', '+256 700 000 000',
            'uganda@amani.example');
");

$pdo->exec("
    INSERT INTO application_addresses
        (application_id, county, sub_county, physical_address, postal_address,
         postal_code, phone_number, alternative_phone_number, email)
    VALUES (1, 'Nairobi City County', 'Westlands', 'Ring Road Plaza',
            'P.O. Box 1234', '00100', '0712345678', '', 'info@amani.example');

    INSERT INTO application_operating_locations
        (application_id, county, sub_county, physical_address, phone_number)
    VALUES (1, 'Kiambu', 'Ruiru', 'Main Street', '0700111222');

    INSERT INTO application_objectives (application_id, objective_code, description)
    VALUES (1, 'EDUCATION', 'Support for primary school children');

    INSERT INTO application_beneficiaries (application_id, beneficiary_code, description)
    VALUES (1, 'CHILDREN', 'Children under 14');

    INSERT INTO application_officials
        (application_id, full_name, designation, nationality,
         id_passport_number, kra_pin, tax_id_type, tax_id_number, phone_number, email)
    VALUES
        (1, 'Jane Wanjiru', 'CHAIRPERSON', 'Kenyan', '12345678', 'A001234567X', NULL, NULL, '0712345678', ''),
        (1, 'John Carter',  'TREASURER',   'American', 'US9988776', '', 'SSN', '123-45-6789', '0722333444', ''),
        (1, 'Mary Otieno',  'SECRETARY',   'Kenyan', '87654321', 'A007654321Y', NULL, NULL, '0733555666', '');
");

file_put_contents(
    $configPath,
    "<?php\n\$pdo = new PDO('sqlite:" . $dbPath . "');\n"
    . "\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);\n"
    . "\$pdo->sqliteCreateFunction('YEAR', fn (\$d) => (int) substr((string) \$d, 0, 4), 1);\n"
    . "\$pdo->sqliteCreateFunction('LEFT', fn (\$s, \$n) => substr((string) \$s, 0, (int) \$n), 2);\n"
    . "\$db = \$pdo;\n"
);


/*
|--------------------------------------------------------------------------
| Running the page
|--------------------------------------------------------------------------
*/

$_SESSION = [
    'user_id' => 1,
    'role' => 'admin',
    'full_name' => 'Test Officer',
    'must_change_password' => false,
];

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/pbo-portal/applications/export-registry.php';

/**
 * Render the page in a fresh process, so each case starts clean and a
 * fatal error in one does not take the whole test run with it.
 */
function render(array $query, string $page = 'applications/export-registry.php'): string
{
    $command = 'php -d display_errors=1 -d error_reporting=E_ALL '
        . escapeshellarg(__DIR__ . '/_render-page.php')
        . ' 2>&1';

    $env = $_ENV + [
        'PBO_TEST_PAGE' => $page,
        'PBO_TEST_QUERY' => http_build_query($query),
        'PATH' => getenv('PATH'),
    ];

    $env['PBO_TEST_PAGE'] = $page;
    $env['PBO_TEST_QUERY'] = http_build_query($query);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, null, $env);

    if (!is_resource($process)) {
        return 'Fatal error: could not start the renderer.';
    }

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $output;
}

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
    } else {
        $fail++;
        echo "  FAIL {$name}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
    }
}

function has(string $haystack, string $needle): bool
{
    return str_contains($haystack, $needle);
}

echo "export-registry.php\n\n";

$cases = [
    'list, everything'          => ['mode' => 'list', 'format' => 'print'],
    'list, letter B'            => ['mode' => 'list', 'format' => 'print', 'letter' => 'B'],
    'list, non-letter names'    => ['mode' => 'list', 'format' => 'print', 'letter' => '#'],
    'list, oldest first'        => ['mode' => 'list', 'format' => 'print', 'sort' => 'oldest'],
    'list, international only'  => ['mode' => 'list', 'format' => 'print', 'type' => 'international'],
    'list, year 2021'           => ['mode' => 'list', 'format' => 'print', 'year' => '2021'],
    'list, selected ids'        => ['mode' => 'list', 'format' => 'print', 'ids' => '1,3'],
    'details, one record'       => ['mode' => 'details', 'format' => 'print', 'ids' => '1'],
    'details, excel'            => ['mode' => 'details', 'format' => 'excel', 'ids' => '1'],
    'details, head office abroad' => ['mode' => 'details', 'format' => 'print', 'ids' => '3'],
    'list, nonsense filters'    => ['mode' => 'list', 'format' => 'print', 'sort' => "x'; DROP TABLE pbo_applications; --", 'letter' => 'ZZZ', 'year' => 'abcd', 'ids' => 'nope,-4'],
];

$outputs = [];

foreach ($cases as $name => $query) {
    $out = render($query);
    $outputs[$name] = $out;

    echo str_pad($name, 28);

    $clean = !has($out, 'Fatal error')
        && !has($out, 'Warning:')
        && !has($out, 'Notice:')
        && !has($out, 'Deprecated:')
        && !has($out, 'Uncaught');

    check($name . ' renders cleanly', $clean, $clean ? '' : substr(trim($out), 0, 400));

    echo $clean ? "ok\n" : "ERRORS\n";
}

echo "\nContent\n";

$all = $outputs['list, everything'];
check('all five organizations listed',
    substr_count($all, 'PBO/20') === 5, 'found ' . substr_count($all, 'PBO/20'));
check('drafts excluded',
    !has($all, 'Must Not Appear'));
check('alphabetical by default: Amani before Zawadi',
    strpos($all, 'Amani') < strpos($all, 'Zawadi'));
check('"4Hope" sorts before the letters',
    strpos($all, '4Hope') < strpos($all, 'Amani'));

$letterB = $outputs['list, letter B'];
check('letter B: only Baraka', has($letterB, 'Baraka') && !has($letterB, 'Amani'));
check('letter B: says so in the header', has($letterB, 'names beginning with B'));

$hash = $outputs['list, non-letter names'];
check('# finds 4Hope', has($hash, '4Hope'));
check('# excludes Amani', !has($hash, 'Amani Children'));

$oldest = $outputs['list, oldest first'];
check('oldest first: Amani (2019) before Zawadi (2024)',
    strpos($oldest, 'Amani') < strpos($oldest, 'Zawadi'));

$intl = $outputs['list, international only'];
check('international only: one row', substr_count($intl, 'PBO/20') === 1);
check('international only: the right one', has($intl, 'Cross-Border'));

$year = $outputs['list, year 2021'];
check('year filter: one row', substr_count($year, 'PBO/20') === 1);
check('year filter: Baraka', has($year, 'Baraka'));

$ids = $outputs['list, selected ids'];
check('selection: two rows', substr_count($ids, 'PBO/20') === 2);
check('selection: says chosen from the register', has($ids, 'chosen from the register'));

$details = $outputs['details, one record'];
check('details: head office', has($details, 'Ring Road Plaza'));
check('details: other areas', has($details, 'Ruiru'));
check('details: objectives', has($details, 'primary school children'));
check('details: beneficiaries', has($details, 'Children under 14'));
check('details: officials', has($details, 'Jane Wanjiru'));
check('details: officials in order Chairperson, Secretary, Treasurer',
    strpos($details, 'Jane Wanjiru') < strpos($details, 'Mary Otieno')
    && strpos($details, 'Mary Otieno') < strpos($details, 'John Carter'));
check('details: Kenyan official shows a KRA PIN', has($details, 'A001234567X'));
check('details: foreign number is labelled as a Tax ID, not a KRA PIN',
    has($details, 'Tax ID (SSN)') && has($details, '123-45-6789'));

/* Areas of operation outside Kenya, for a national organization. */
check('details: a national organization\'s area outside Kenya is printed',
    has($details, 'Areas of Operation Outside Kenya') && has($details, 'Kampala'));

/*
| And the record whose head office is abroad: the overseas table in
| place of the Kenyan one, never both, and nothing calling it a parent.
*/
$abroadDetails = $outputs['details, head office abroad'];

check('details, abroad: the overseas head office table is printed',
    has($abroadDetails, 'Head Office Outside Kenya'));
check('details, abroad: its country is printed',
    has($abroadDetails, 'Canada'));
check('details, abroad: no Kenyan head office table as well',
    !has($abroadDetails, '>Head Office<'));
check('details, abroad: nothing printed says "Parent"',
    !preg_match('/\\bParent/i', $abroadDetails));
check('details, abroad: nothing printed says "Country of Origin"',
    !has($abroadDetails, 'Country of Origin'));

$nonsense = $outputs['list, nonsense filters'];
check('bad sort falls back rather than injecting',
    has($nonsense, 'Amani') && !has($nonsense, 'DROP TABLE'));
check('bad ids ignored, not fatal', has($nonsense, 'PBO/20'));

/* The table survived the injection attempt. */
$still = (int) $pdo->query('SELECT COUNT(*) FROM pbo_applications')->fetchColumn();
check('database intact after injection attempt', $still === 6, 'rows: ' . $still);

/* Every table shares one column grid, which is what keeps Excel even. */
$grids = substr_count($details, '<colgroup>');
$tables = substr_count($details, '<table');
check('every table carries the shared column grid',
    $grids === $tables, "{$grids} colgroups for {$tables} tables");

echo "\nNothing empty, and no raw codes\n";

/* The complaint: dashes standing in for fields nobody filled. */
check('no em dash anywhere in the details export',
    !has($details, '&mdash;') && !has($details, "\u{2014}"));
check('no em dash anywhere in the list export',
    !has($all, '&mdash;') && !has($all, "\u{2014}"));

/* An empty labelled box is the same problem as a dash. */
/*
| Match the rendered label cell, not the words: a CSS comment mentions
| them, and a label now lives in its own cell rather than a span, because
| Excel runs stacked spans together into one string.
*/
check('no Alternative Telephone box when there is none recorded',
    !has($details, '>Alternative Telephone<'));
check('the Telephone box that DOES have a value is still there',
    has($details, '>Telephone<'));

/* Codes are for the database, not for a supervisor reading a printout. */
check('objective printed as a label, not a code',
    has($details, 'Education') && !has($details, 'EDUCATION'));
check('beneficiary printed as a label, not a code',
    has($details, 'Children') && !has($details, 'CHILDREN'));

/* The organization name is introduced rather than left bare. */
check('organization name carries its own label',
    has($details, 'NAME OF ORGANIZATION') && has($details, 'Amani Children Trust'));

echo "\nExcel reads it properly\n";

/*
| The faults the real Excel screenshots showed, each as a check.
*/

/* A label and its value in one cell arrive as "Registration Number20397". */
check('label and value are in separate cells',
    !preg_match('/<td[^>]*>[^<]*<span class="label">/', $details));

/* Excel sizes columns from <col width> in pixels; a percentage says nothing. */
check('column widths are declared in pixels',
    has($details, '<col width="58"'));

/* Phone numbers lose their leading zero unless the cell is marked as text. */
check('value cells are marked as text for Excel',
    substr_count($details, 'mso-number-format') > 10);

/* The masthead was ending up crushed into cell A1. */
check('masthead is three rows, not one cell',
    has($details, 'cover-authority') && has($details, 'cover-title'));

echo "\n{$pass} passed, {$fail} failed\n";

exit($fail === 0 ? 0 : 1);
