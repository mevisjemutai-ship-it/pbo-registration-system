<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| export-excel-test.php
|--------------------------------------------------------------------------
|
|   php tests/export-excel-test.php
|
| Renders applications/export-excel.php against two organizations:
|
|   1. a complete one, to check every section appears and the grid holds;
|   2. a sparse one - no second county, no email anywhere, no directors,
|      no parent organization - to check that none of it is printed as
|      dashes or as empty headings.
|
| The second case is the point. The export used to print a dash for every
| field nobody had filled in, which reads as a badly completed record
| rather than as a field that does not apply.
|
| SQLite, so it runs with no database server. Writes a temporary
| config/database.php and removes it; refuses if one already exists.
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

$dbPath = sys_get_temp_dir() . '/pbo-excel-test-' . getmypid() . '.sqlite';

register_shutdown_function(static function () use ($configPath, $dbPath): void {
    @unlink($configPath);
    @unlink($dbPath);
});

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('
    CREATE TABLE pbo_applications (
        id INTEGER PRIMARY KEY, application_reference TEXT, pbo_name TEXT,
        pbo_registration_number TEXT, registration_date TEXT, registration_type TEXT,
        status TEXT, financial_year_start_month INTEGER, financial_year_end_month INTEGER,
        submitted_at TEXT
    );
    CREATE TABLE application_addresses (
        id INTEGER PRIMARY KEY, application_id INTEGER, county TEXT, town TEXT,
        sub_county TEXT, physical_address TEXT, phone_number TEXT,
        alternative_phone_number TEXT, email TEXT, postal_address TEXT, postal_code TEXT
    );
    CREATE TABLE application_operating_locations (
        id INTEGER PRIMARY KEY, application_id INTEGER, county TEXT, sub_county TEXT,
        physical_address TEXT, phone_number TEXT, alternative_phone_number TEXT,
        email TEXT, postal_address TEXT, postal_code TEXT
    );
    CREATE TABLE application_international_details (
        id INTEGER PRIMARY KEY, application_id INTEGER, parent_organization_name TEXT,
        country_of_origin TEXT, registration_number TEXT, parent_physical_address TEXT,
        parent_postal_address TEXT, parent_postal_code TEXT, parent_phone_number TEXT,
        parent_email TEXT, contact_person_name TEXT, contact_person_phone TEXT,
        contact_person_email TEXT
    );
    CREATE TABLE application_objectives (
        id INTEGER PRIMARY KEY, application_id INTEGER, objective_code TEXT, description TEXT
    );
    CREATE TABLE application_beneficiaries (
        id INTEGER PRIMARY KEY, application_id INTEGER, beneficiary_code TEXT, description TEXT
    );
    CREATE TABLE application_officials (
        id INTEGER PRIMARY KEY, application_id INTEGER, full_name TEXT, designation TEXT,
        nationality TEXT, id_passport_number TEXT, kra_pin TEXT, tax_id_type TEXT,
        tax_id_number TEXT, phone_number TEXT, email TEXT, signature TEXT
    );
');

/* 1 - complete, international, with directors. */
$pdo->exec("
    INSERT INTO pbo_applications VALUES
        (1, 'PBORA/2024/0001', 'Amani Children Trust', 'PBO/2019/0041',
         '2019-03-14', 'international', 'submitted', 1, 12, '2024-02-01 09:00:00');

    INSERT INTO application_addresses
        (application_id, county, town, sub_county, physical_address, phone_number,
         alternative_phone_number, email, postal_address, postal_code)
    VALUES (1, 'Nairobi City County', 'Nairobi', 'Westlands', 'Ring Road Plaza',
            '0712345678', '0720000000', 'info@amani.example', 'P.O. Box 1234', '00100');

    INSERT INTO application_operating_locations
        (application_id, county, sub_county, physical_address, phone_number, email,
         postal_address, postal_code)
    VALUES
        (1, 'Kiambu', 'Ruiru', 'Main Street', '0700111222', 'kiambu@amani.example', 'P.O. Box 9', '00900'),
        (1, 'Kisumu', '', '', '', '', '', '');

    INSERT INTO application_international_details
        (application_id, parent_organization_name, country_of_origin, registration_number,
         parent_physical_address, parent_postal_address, parent_postal_code,
         parent_phone_number, parent_email, contact_person_name, contact_person_phone,
         contact_person_email)
    VALUES (1, 'Amani International', 'United States', 'US-55512',
            '44 Harbour Street', 'PO Box 55', '10001', '+1 202 555 0100',
            'hq@amani.example', 'Grace Mutiso', '+1 202 555 0111', 'grace@amani.example');

    INSERT INTO application_objectives (application_id, objective_code, description) VALUES
        (1, 'EDUCATION', ''), (1, 'HEALTH', ''),
        (1, 'OTHER', 'Vocational training for school leavers');

    INSERT INTO application_beneficiaries (application_id, beneficiary_code, description) VALUES
        (1, 'CHILDREN', ''), (1, 'YOUTH', ''),
        (1, 'OTHER', '{\"specify\":\"Caregivers\",\"description\":\"Guardians of enrolled children\"}');

    INSERT INTO application_officials
        (application_id, full_name, designation, nationality, id_passport_number,
         kra_pin, tax_id_type, tax_id_number, phone_number, email, signature)
    VALUES
        (1, 'Jane Wanjiru', 'CHAIRPERSON', 'Kenyan', '12345678', 'A001234567X', NULL, NULL, '0712345678', 'jane@amani.example', 'sig1.png'),
        (1, 'Mary Otieno',  'SECRETARY',   'Kenyan', '87654321', 'A007654321Y', NULL, NULL, '0733555666', '', ''),
        (1, 'John Carter',  'TREASURER',   'American', 'US9988776', '', 'SSN', '123-45-6789', '+1 202 555 0111', 'john@amani.example', ''),
        (1, 'Peter Mwangi', 'DIRECTOR',    'Kenyan', '11223344', 'A009999999Z', NULL, NULL, '0744777888', '', '');
");

/*
| 2 - as sparse as a real record gets: a national organization with a head
| office county and nothing else. No second county, no emails, no
| directors, no objectives description, no financial year.
*/
$pdo->exec("
    INSERT INTO pbo_applications VALUES
        (2, 'PBORA/2024/0002', 'Sparse Community Group', 'PBO/2022/0500',
         '2022-08-08', 'national', 'submitted', NULL, NULL, '2024-03-01 09:00:00');

    INSERT INTO application_addresses
        (application_id, county, town, sub_county, physical_address, phone_number,
         alternative_phone_number, email, postal_address, postal_code)
    VALUES (2, 'Garissa', '', '', 'Township', '0799000111', '', '', '', '');

    INSERT INTO application_officials
        (application_id, full_name, designation, nationality, id_passport_number,
         kra_pin, tax_id_type, tax_id_number, phone_number, email, signature)
    VALUES
        (2, 'Halima Noor', 'CHAIRPERSON', 'Kenyan', '22334455', 'A005555555A', NULL, NULL, '0799000111', '', ''),
        (2, 'Abdi Hassan', 'SECRETARY',   'Kenyan', '33445566', 'A006666666B', NULL, NULL, '0788222333', '', ''),
        (2, 'Fatuma Ali',  'TREASURER',   'Kenyan', '44556677', 'A007777777C', NULL, NULL, '0777444555', '', '');
");

file_put_contents(
    $configPath,
    "<?php\n\$pdo = new PDO('sqlite:" . $dbPath . "');\n"
    . "\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);\n"
    . "\$db = \$pdo;\n"
);


function render(int $id): string
{
    $command = 'php -d display_errors=1 -d error_reporting=E_ALL '
        . escapeshellarg(__DIR__ . '/_render-page.php') . ' 2>&1';

    $env = $_ENV;
    $env['PBO_TEST_PAGE'] = 'applications/export-excel.php';
    $env['PBO_TEST_QUERY'] = 'id=' . $id;
    $env['PATH'] = (string) getenv('PATH');

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, null, $env);

    if (!is_resource($process)) {
        return 'Fatal error: renderer would not start.';
    }

    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $out;
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

echo "export-excel.php\n\n";

$full = render(1);
$sparse = render(2);

foreach (['complete record' => $full, 'sparse record' => $sparse] as $label => $output) {
    $clean = !has($output, 'Fatal error')
        && !has($output, 'Warning:')
        && !has($output, 'Notice:')
        && !has($output, 'Deprecated:')
        && !has($output, 'Uncaught')
        /* A page that produced nothing would otherwise pass every check
           below by default, which is the worst way for a test to lie. */
        && has($output, 'Registration Record')
        && strlen($output) > 2000;

    echo str_pad($label, 20), $clean ? "renders cleanly\n" : "ERRORS\n";
    check($label . ' renders cleanly', $clean, $clean ? '' : substr(trim($output), 0, 500));
}

echo "\nNothing empty is printed\n";

/* The whole complaint, in one check. */
check('no em dashes anywhere in the sparse record', !has($sparse, '&mdash;') && !has($sparse, '—'));
check('no em dashes anywhere in the complete record', !has($full, '&mdash;') && !has($full, '—'));

check('sparse record omits the Other Areas section', !has($sparse, 'Other Areas of Operation'));
check('sparse record omits the Parent Organization section', !has($sparse, 'Parent Organization'));
check('sparse record omits Financial Year', !has($sparse, 'Financial Year Runs'));
check('sparse record omits Beneficiaries', !has($sparse, 'Beneficiaries'));
check('sparse record omits Public Benefit Purposes', !has($sparse, 'Public Benefit Purposes'));
check('sparse record omits the Directors table', !has($sparse, 'Tax Identification'));
check('sparse record has no empty Email label', !has($sparse, '>Email<'));
check('sparse record has no empty Sub-County label', !has($sparse, '>Sub-County<'));

echo "\nWhat IS there is still there\n";

check('sparse: organization name', has($sparse, 'Sparse Community Group'));
check('sparse: head office county', has($sparse, 'Garissa'));
check('sparse: the three officials', has($sparse, 'Halima Noor')
    && has($sparse, 'Abdi Hassan') && has($sparse, 'Fatuma Ali'));

check('full: both operating counties', has($full, 'Kiambu') && has($full, 'Kisumu'));
check('full: parent organization', has($full, 'Amani International'));
check('full: objectives as chips', has($full, 'Education') && has($full, 'Health'));
check('full: other objective text', has($full, 'Vocational training'));
check('full: other beneficiary specify', has($full, 'Caregivers'));
check('full: financial year', has($full, 'January to December'));
check('full: director listed', has($full, 'Peter Mwangi'));
check('full: signature noted where present', has($full, 'Signature on file'));
check('full: officials in order Chairperson, Secretary, Treasurer',
    strpos($full, 'Jane Wanjiru') < strpos($full, 'Mary Otieno')
    && strpos($full, 'Mary Otieno') < strpos($full, 'John Carter'));
check('full: foreign tax number labelled as such, not as a KRA PIN',
    has($full, 'Tax ID (SSN)') && has($full, '123-45-6789'));
check('full: Kenyan official keeps the KRA PIN label',
    has($full, 'KRA PIN') && has($full, 'A001234567X'));

echo "\nExcel reads it properly\n";

foreach (['complete record' => $full, 'sparse record' => $sparse] as $label => $output) {

    /* A label and its value in one cell arrive as "CountyBaringo". */
    check($label . ': label and value are in separate cells',
        !preg_match('/<td[^>]*>\s*<span class="label">/', $output));

    /* Excel sizes columns from <col width> in pixels. */
    check($label . ': column widths are declared in pixels',
        has($output, '<col width="58"'));

    /* 0111053310 becomes 111053310 unless the cell is marked as text. */
    check($label . ': value cells are marked as text for Excel',
        substr_count($output, 'mso-number-format') > 10);
}

/* The masthead was arriving crushed into cell A1. */
/* Count the cells, not the words - the stylesheet names them too. */
check('masthead authority, title and name are three separate rows',
    substr_count($full, 'class="cover-authority"') === 1
    && substr_count($full, 'class="cover-title"') === 1
    && substr_count($full, 'class="cover-name"') === 1);

/* Chips put every purpose in one cell: "Education Poverty Eradication Hiv Aids". */
check('each objective is on its own row',
    !has($full, 'class="chip"'));

echo "\nThe grid holds\n";

foreach (['complete record' => $full, 'sparse record' => $sparse] as $label => $output) {
    $tables = substr_count($output, '<table');
    $grids = substr_count($output, '<colgroup>');

    check($label . ': every table declares the grid', $tables === $grids,
        "{$tables} tables, {$grids} colgroups");

    /*
    | Every row must span exactly twelve columns. A short row is what
    | makes Excel pull the sections out of alignment underneath it.
    */
    preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $output, $rows);

    $bad = [];

    foreach ($rows[1] as $index => $row) {
        preg_match_all('/<t[dh]\b[^>]*?colspan="(\d+)"/', $row, $spans);

        $cells = substr_count($row, '<td') + substr_count($row, '<th');
        $total = array_sum(array_map('intval', $spans[1]));

        /* Cells without a colspan count as one each. */
        $total += $cells - count($spans[1]);

        if ($cells > 0 && $total !== 12) {
            $bad[] = 'row ' . ($index + 1) . ' spans ' . $total;
        }
    }

    check($label . ': every row spans twelve columns', $bad === [],
        implode('; ', array_slice($bad, 0, 5)));
}

echo "\n{$pass} passed, {$fail} failed\n";

exit($fail === 0 ? 0 : 1);
