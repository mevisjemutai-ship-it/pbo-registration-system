<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| international-head-office-test.php
|--------------------------------------------------------------------------
|
|   php tests/international-head-office-test.php
|
| Covers the head office rework:
|
|   * an international organization is asked whether its head office is in
|     Kenya, and the answer decides which card it fills in - never both;
|   * the overseas card stands where the Kenyan one does, not at the foot
|     of the page;
|   * areas of operation outside Kenya are offered to national
|     organizations too;
|   * the word "Parent" is gone from everything staff read, and "Country
|     of Origin" is now "Country";
|   * the submit-time check asks for one head office, not two.
|
| The last one is the bug that started this: the old check demanded the
| eleven overseas particulars of every international organization, so an
| organization whose head office is in Kenya could not be submitted until
| somebody copied its Kenyan address into the overseas fields - and the
| register then recorded a Kenyan organization as its own foreign parent.
|
| SQLite rather than MySQL so this runs anywhere.
*/

require __DIR__ . '/_sandbox.php';

$root = dirname(__DIR__);
$configPath = $root . '/config/database.php';

if (is_file($configPath)) {
    fwrite(STDERR, "config/database.php is still present - refusing to overwrite it.\n");
    exit(2);
}

$dbPath = sys_get_temp_dir() . '/pbo-head-office-test-' . getmypid() . '.sqlite';

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

/*
| The one MySQL function these pages use that SQLite has no equivalent
| for: FIELD(value, a, b, c) gives the position of value in the list, and
| the officials are ordered by it so they come out Chairperson,
| Secretary, Treasurer rather than alphabetically.
*/
$field = static function (...$arguments): int {
    $needle = array_shift($arguments);
    $position = array_search($needle, $arguments, false);

    return $position === false ? 0 : (int) $position + 1;
};

$pdo->sqliteCreateFunction('FIELD', $field, -1);

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
        created_by INTEGER,
        updated_by INTEGER,
        submitted_by INTEGER,
        verified_by INTEGER,
        verified_at TEXT,
        created_at TEXT,
        updated_at TEXT
    );
    CREATE TABLE application_addresses (
        id INTEGER PRIMARY KEY, application_id INTEGER, county TEXT, sub_county TEXT,
        town TEXT, city_town TEXT, physical_address TEXT, postal_address TEXT,
        postal_code TEXT, phone_number TEXT, alternative_phone_number TEXT, email TEXT
    );
    CREATE TABLE application_operating_locations (
        id INTEGER PRIMARY KEY, application_id INTEGER, county TEXT, sub_county TEXT,
        city_town TEXT, physical_address TEXT, postal_address TEXT, postal_code TEXT,
        phone_number TEXT, alternative_phone_number TEXT, email TEXT
    );
    CREATE TABLE application_foreign_locations (
        id INTEGER PRIMARY KEY, application_id INTEGER, country TEXT, city TEXT,
        physical_address TEXT, postal_address TEXT, postal_code TEXT,
        phone_number TEXT, alternative_phone_number TEXT, email TEXT
    );
    CREATE TABLE application_international_details (
        id INTEGER PRIMARY KEY, application_id INTEGER,
        registration_status TEXT,
        parent_organization_name TEXT, country_of_origin TEXT, registration_number TEXT,
        parent_physical_address TEXT, parent_postal_address TEXT, parent_postal_code TEXT,
        parent_phone_number TEXT, parent_email TEXT,
        contact_person_name TEXT, contact_person_phone TEXT, contact_person_email TEXT
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
        tax_id_type TEXT, tax_id_number TEXT, phone_number TEXT, email TEXT,
        signature TEXT, postal_address TEXT, postal_code TEXT, physical_address TEXT
    );
    CREATE TABLE application_directors (
        id INTEGER PRIMARY KEY, application_id INTEGER, full_name TEXT, designation TEXT,
        nationality TEXT, id_passport_number TEXT, kra_pin TEXT,
        tax_id_type TEXT, tax_id_number TEXT, phone_number TEXT, email TEXT
    );
    CREATE TABLE users (
        id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, role TEXT,
        is_active INTEGER
    );
    CREATE TABLE application_reference_sequences (
        year INTEGER PRIMARY KEY, last_number INTEGER
    );
    CREATE TABLE application_audit_log (
        id INTEGER PRIMARY KEY, application_id INTEGER, user_id INTEGER,
        action TEXT, description TEXT, changes TEXT, ip_address TEXT,
        field_name TEXT, old_value TEXT, new_value TEXT, created_at TEXT
    );
');


/*
| Three records, one for each way the head office question can stand.
|
|   10  national                                 - never asked
|   11  international, head office in Kenya      - the case that was broken
|   12  international, head office abroad        - the case the form assumed
*/

$insert = $pdo->prepare(
    'INSERT INTO pbo_applications
        (id, application_reference, pbo_name, pbo_registration_number,
         registration_date, registration_type, headquarters_in_kenya,
         status, current_step, financial_year_start_month,
         financial_year_end_month, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 12, 1)'
);

$insert->execute([10, 'APP-10', 'AMANI CHILDREN TRUST', 'PBO/2019/0041',
    '2019-03-14', 'national', 1, 'draft']);

$insert->execute([11, 'APP-11', 'HARAMBEE GLOBAL NETWORK', 'PBO/2022/0210',
    '2022-06-01', 'international', 1, 'draft']);

$insert->execute([12, 'APP-12', 'CROSS-BORDER RELIEF NETWORK', 'PBO/2023/0310',
    '2023-11-29', 'international', 0, 'draft']);

/* An international record entered before the question existed. */
$insert->execute([13, 'APP-13', 'OLDER INTERNATIONAL RECORD', 'PBO/2018/0007',
    '2018-02-02', 'international', null, 'draft']);

/*
| Both international organizations have a complete Kenyan head office.
| Only the one actually based abroad has overseas details - which is the
| point: the other one has nothing abroad to describe.
*/
$pdo->exec("
    INSERT INTO application_addresses
        (application_id, county, physical_address, postal_address,
         postal_code, phone_number, email)
    VALUES
        (10, 'NAIROBI CITY COUNTY', 'RING ROAD PLAZA', 'P.O. BOX 1234', '00100',
             '0712345678', 'info@amani.example'),
        (11, 'NAIROBI CITY COUNTY', 'UPPER HILL CHAMBERS', 'P.O. BOX 5566', '00100',
             '0720000111', 'info@harambee.example'),
        (12, 'MOMBASA COUNTY', 'NYALI ROAD', 'P.O. BOX 7788', '80100',
             '0730000222', 'kenya@relief.example');

    INSERT INTO application_international_details
        (application_id, parent_organization_name, country_of_origin,
         registration_number, parent_physical_address, parent_postal_address,
         parent_postal_code, parent_phone_number, parent_email,
         contact_person_name, contact_person_phone, contact_person_email)
    VALUES
        (12, 'CROSS-BORDER RELIEF NETWORK', 'CANADA', 'CA-998877',
             '120 BAY STREET', 'PO BOX 44', 'M5J 2T3', '+14165550000',
             'head@relief.example', 'LOUISE TREMBLAY', '+14165551111',
             'louise@relief.example');

    INSERT INTO application_foreign_locations
        (application_id, country, city, physical_address, phone_number, email)
    VALUES
        (10, 'UGANDA', 'KAMPALA', 'PLOT 14 KIRA ROAD', '+256700000000', 'ug@amani.example'),
        (12, 'FRANCE', 'LYON', '8 RUE DE LA PAIX', '+33100000000', 'fr@relief.example');
");

/*
| view.php only shows submitted records, so each head office case needs a
| submitted twin: 20 national, 21 international based in Kenya, 22
| international based abroad, 23 international never asked.
*/
$pdo->exec("
    INSERT INTO pbo_applications
        (id, application_reference, pbo_name, pbo_registration_number,
         registration_date, registration_type, headquarters_in_kenya, status,
         current_step, financial_year_start_month, financial_year_end_month,
         submitted_at, created_by)
    SELECT id + 10, 'APP-' || (id + 10), pbo_name, pbo_registration_number,
           registration_date, registration_type, headquarters_in_kenya,
           'submitted', 6, 1, 12, '2026-01-05 09:00:00', 1
    FROM pbo_applications WHERE id IN (10, 11, 12, 13);

    INSERT INTO application_addresses
        (application_id, county, physical_address, postal_address, postal_code,
         phone_number, email)
    SELECT application_id + 10, county, physical_address, postal_address,
           postal_code, phone_number, email
    FROM application_addresses;

    INSERT INTO application_international_details
        (application_id, parent_organization_name, country_of_origin,
         registration_number, parent_physical_address, parent_postal_address,
         parent_postal_code, parent_phone_number, parent_email,
         contact_person_name, contact_person_phone, contact_person_email)
    SELECT application_id + 10, parent_organization_name, country_of_origin,
           registration_number, parent_physical_address, parent_postal_address,
           parent_postal_code, parent_phone_number, parent_email,
           contact_person_name, contact_person_phone, contact_person_email
    FROM application_international_details;

    INSERT INTO application_foreign_locations
        (application_id, country, city, physical_address, phone_number, email)
    SELECT application_id + 10, country, city, physical_address, phone_number, email
    FROM application_foreign_locations;
");

$pdo->exec("
    INSERT INTO users (id, full_name, username, role, is_active)
    VALUES (1, 'TEST OFFICER', 'test.officer', 'admin', 1);
");

file_put_contents(
    $configPath,
    "<?php\n\$pdo = new PDO('sqlite:" . $dbPath . "');\n"
    . "\$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);\n"
    . "\$pdo->sqliteCreateFunction('FIELD', function (...\$a) {\n"
    . "    \$n = array_shift(\$a);\n"
    . "    \$p = array_search(\$n, \$a, false);\n"
    . "    return \$p === false ? 0 : (int) \$p + 1;\n"
    . "}, -1);\n"
    . "\$db = \$pdo;\n"
);


/*
|--------------------------------------------------------------------------
| Running a page
|--------------------------------------------------------------------------
*/

function render(
    string $page,
    array $query = [],
    array $session = [],
    ?array $post = null
): string
{
    $command = 'php -d display_errors=1 -d error_reporting=E_ALL '
        . escapeshellarg(__DIR__ . '/_render-page.php')
        . ' 2>&1';

    $env = $_ENV + ['PATH' => getenv('PATH')];
    $env['PBO_TEST_PAGE'] = $page;
    $env['PBO_TEST_QUERY'] = http_build_query($query);
    $env['PBO_TEST_SESSION'] = $session === []
        ? ''
        : (string) json_encode($session);
    $env['PBO_TEST_POST'] = $post === null
        ? ''
        : (string) json_encode($post);

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, null, $env);

    if (!is_resource($process)) {
        return '';
    }

    $output = (string) stream_get_contents($pipes[1])
        . (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $output;
}


/*
|--------------------------------------------------------------------------
| Checks
|--------------------------------------------------------------------------
*/

$passed = 0;
$failures = [];

function check(string $description, bool $condition): void
{
    global $passed, $failures;

    if ($condition) {
        $passed++;

        return;
    }

    $failures[] = $description;
}

/** Position of a marker, or -1 when it is not there at all. */
function at(string $haystack, string $needle): int
{
    $position = strpos($haystack, $needle);

    return $position === false ? -1 : $position;
}

echo "International head office\n";
echo str_repeat('-', 44), "\n\n";


/*
|--------------------------------------------------------------------------
| 1. The form, one case at a time
|--------------------------------------------------------------------------
*/

echo "1. applications/create.php\n";

$cases = [
    'national' => ['id' => 10, 'question' => false, 'kenyan' => true,  'overseas' => false],
    'international, head office in Kenya'
                 => ['id' => 11, 'question' => true,  'kenyan' => true,  'overseas' => false],
    'international, head office abroad'
                 => ['id' => 12, 'question' => true,  'kenyan' => false, 'overseas' => true],
    'international, never asked'
                 => ['id' => 13, 'question' => true,  'kenyan' => false, 'overseas' => false],
];

$html = '';

foreach ($cases as $label => $case) {

    $page = render('applications/create.php', ['id' => $case['id']]);

    if ($case['id'] === 12) {
        $html = $page;     /* kept for the shared checks below */
    }

    check(
        $label . ': the page rendered without a PHP error',
        !str_contains($page, 'Fatal error')
            && !str_contains($page, 'Warning:')
            && str_contains($page, 'data-headquarters-question')
    );

    /*
    | Each of the three blocks carries its own inline display, so the
    | right card is there from the first paint rather than after the
    | script runs. A card that appears and then vanishes looks like a
    | fault to the person filling the form in.
    */

    $question = (bool) preg_match(
        '/data-headquarters-question\s*\n?\s*style="display:\s*block/',
        $page
    );

    $kenyan = (bool) preg_match(
        '/data-kenyan-head-office\s*\n?\s*style="display:\s*block/',
        $page
    );

    $overseas = (bool) preg_match(
        '/id="international-section"\s*\n?\s*style="display:\s*block/',
        $page
    );

    check($label . ': the question card is ' . ($case['question'] ? 'shown' : 'hidden'),
        $question === $case['question']);

    check($label . ': the Kenyan head office card is ' . ($case['kenyan'] ? 'shown' : 'hidden'),
        $kenyan === $case['kenyan']);

    check($label . ': the overseas card is ' . ($case['overseas'] ? 'shown' : 'hidden'),
        $overseas === $case['overseas']);

    check($label . ': never both head office cards at once',
        !($kenyan && $overseas));

    /* Areas of operation stay available whatever the type. */
    check($label . ': areas of operation outside Kenya are offered',
        str_contains($page, 'id="foreign-locations-section"'));
}


/*
|--------------------------------------------------------------------------
| 2. Where the overseas card sits
|--------------------------------------------------------------------------
|
| It stands in place of the Kenyan card, so it has to come before areas
| of operation, not after them. It used to be the last section on the
| page, which is what made it read as an afterthought nobody could make
| sense of.
*/

echo "2. Order on the page\n";

$kenyanAt   = at($html, 'data-kenyan-head-office');
$overseasAt = at($html, 'id="international-section"');
$countiesAt = at($html, 'id="other-locations-section"');
$foreignAt  = at($html, 'id="foreign-locations-section"');

check('the overseas card comes straight after the Kenyan one',
    $kenyanAt > -1 && $overseasAt > $kenyanAt);

check('the overseas card comes BEFORE areas of operation in Kenya',
    $countiesAt > -1 && $overseasAt < $countiesAt);

check('areas of operation outside Kenya come after the counties',
    $foreignAt > $countiesAt);

check('the overseas card is not a card inside a card',
    !preg_match(
        '/<section[^>]*id="international-section"/',
        $html
    ));


/*
|--------------------------------------------------------------------------
| 3. The word "Parent"
|--------------------------------------------------------------------------
|
| Checked against what the page actually prints, not the source: a
| comment explaining why the columns are still named parent_* is fine,
| and so are the field names themselves. What staff read is not.
*/

echo "3. Labels\n";

/*
| Reduce the page to what a member of staff actually reads: no comments
| explaining why the columns are still named parent_*, no scripts, no
| stylesheet - "transparent" contains the word and would fail this on its
| own - and no tag attributes, since the field NAMES are deliberately
| unchanged.
*/
$visible = preg_replace('/<!--.*?-->/s', '', $html) ?? '';
$visible = preg_replace('/<script\b.*?<\/script>/s', '', $visible) ?? '';
$visible = preg_replace('/<style\b.*?<\/style>/s', '', $visible) ?? '';
$visible = preg_replace('/<[^>]*>/', ' ', $visible) ?? '';

check('"Parent" appears nowhere a member of staff can read it',
    !preg_match('/\bparent/i', $visible));

check('"Country of Origin" is gone',
    stripos($visible, 'country of origin') === false);

check('the overseas heading says where it applies',
    str_contains($visible, 'Head Office Outside Kenya'));

check('the overseas card asks for a Country',
    str_contains($html, '>Country<') || str_contains($visible, 'Country'));

check('the overseas card still asks for the organization name',
    str_contains($visible, 'Name of the Organization'));

/* The columns are unchanged - renaming them would touch nine files. */
check('the form still posts to the parent_* columns',
    str_contains($html, 'name="parent_organization_name"')
        && str_contains($html, 'name="country_of_origin"'));


/*
|--------------------------------------------------------------------------
| 4. Areas of operation outside Kenya
|--------------------------------------------------------------------------
*/

echo "4. Areas of operation outside Kenya\n";

check('the question is asked',
    str_contains($html, 'name="operates_abroad"'));

check('saved countries come back onto the form',
    str_contains($html, 'FRANCE'));

check('each card posts an indexed row',
    (bool) preg_match('/name="foreign_locations\[\d+\]\[country\]"/', $html));

check('there is a way to add another country',
    str_contains($html, 'id="add-foreign-location"'));

check('countries are suggested as you type',
    str_contains($html, 'id="country-list"'));

/* A national organization gets the same section. */
$nationalPage = render('applications/create.php', ['id' => 10]);

check('a national organization can record areas outside Kenya',
    str_contains($nationalPage, 'name="operates_abroad"'));

check('and its saved country comes back too',
    str_contains($nationalPage, 'UGANDA'));


/*
|--------------------------------------------------------------------------
| 5. The submit-time check
|--------------------------------------------------------------------------
|
| The bug this whole change exists to fix.
*/

echo "5. Submit-time check\n";

require_once $root . '/includes/application-validation.php';

$errorsFor = static function (int $id) use ($pdo): array {
    return pboOrganizationSectionErrors($pdo, $id);
};

$national = $errorsFor(10);
$kenyanHq = $errorsFor(11);
$abroadHq = $errorsFor(12);
$neverAsked = $errorsFor(13);

check('a complete national organization passes',
    $national === []);

check('an international organization with its head office in Kenya passes '
    . 'on the Kenyan address alone',
    $kenyanHq === []);

check('and is NOT asked for overseas details it does not have',
    !str_contains(strtolower(implode(' ', $kenyanHq)), 'head office outside'));

check('an international organization based abroad passes on the overseas card',
    $abroadHq === []);

check('and is NOT asked for a Kenyan head office as well',
    !str_contains(implode(' ', $abroadHq), 'Head Office County'));

check('an international record that never answered the question is asked it',
    in_array('Say whether the head office is in Kenya.', $neverAsked, true));

/* No message anywhere calls anything a parent. */
check('no submit-time message says "Parent"',
    stripos(implode(' ', array_merge($national, $kenyanHq, $abroadHq, $neverAsked)),
        'parent') === false);

/*
| The two checks below take rows away, so they work on copies - 31 and 32
| - rather than on 11 and 12, which the page checks further down still
| need intact.
*/
$pdo->exec('
    INSERT INTO pbo_applications
        (id, application_reference, pbo_name, pbo_registration_number,
         registration_date, registration_type, headquarters_in_kenya, status,
         current_step, financial_year_start_month, financial_year_end_month,
         created_by)
    SELECT id + 20, \'APP-\' || (id + 20), pbo_name, pbo_registration_number,
           registration_date, registration_type, headquarters_in_kenya,
           \'draft\', 1, 1, 12, 1
    FROM pbo_applications WHERE id IN (11, 12);
');

/* Take the Kenyan address away from the one that needs it. */

check('an international organization based in Kenya with no address is stopped',
    in_array('Head Office County is required.', $errorsFor(31), true));

/* And the overseas details away from the one that needs those. */
$stopped = $errorsFor(32);

check('an international organization based abroad with no overseas details is stopped',
    in_array('Name of the Organization is required.', $stopped, true));

check('and the message naming the country does not say "of Origin"',
    in_array('Country is required.', $stopped, true));


/*
|--------------------------------------------------------------------------
| 6. The records page
|--------------------------------------------------------------------------
*/

echo "6. applications/view.php\n";

$records = [
    'national'                 => ['id' => 20, 'kenyan' => true,  'overseas' => false],
    'international, in Kenya'   => ['id' => 21, 'kenyan' => true,  'overseas' => false],
    'international, abroad'     => ['id' => 22, 'kenyan' => false, 'overseas' => true],
    'international, never asked' => ['id' => 23, 'kenyan' => false, 'overseas' => true],
];

foreach ($records as $label => $case) {

    $record = render('applications/view.php', ['id' => $case['id']]);

    check('record, ' . $label . ': rendered without a PHP error',
        !str_contains($record, 'Fatal error') && !str_contains($record, 'Warning:'));

    $hasKenyan = str_contains($record, 'Headquarters Address');
    $hasOverseas = str_contains($record, 'Head Office Outside Kenya');

    check('record, ' . $label . ': Kenyan head office card '
        . ($case['kenyan'] ? 'shown' : 'absent'),
        $hasKenyan === $case['kenyan']);

    check('record, ' . $label . ': never two head office cards',
        !($hasKenyan && $hasOverseas));

    check('record, ' . $label . ': areas outside Kenya are reported',
        str_contains($record, 'Areas of Operation Outside Kenya'));

    /* Nothing visible says Parent. */
    $plain = preg_replace('/<!--.*?-->/s', '', $record) ?? '';
    $plain = preg_replace('/<script\b.*?<\/script>/s', '', $plain) ?? '';
    $plain = preg_replace('/<style\b.*?<\/style>/s', '', $plain) ?? '';
    $plain = preg_replace('/<[^>]*>/', ' ', $plain) ?? '';

    check('record, ' . $label . ': nothing visible says "Parent"',
        !preg_match('/\bparent/i', $plain),
        (string) (preg_match('/.{0,40}\bparent.{0,40}/i', $plain, $m) ? $m[0] : ''));
}

/* The record based abroad prints the overseas details, not a dash. */
$abroadRecord = render('applications/view.php', ['id' => 22]);

check('record: the overseas country is printed',
    str_contains($abroadRecord, 'CANADA'));

check('record: the overseas organization name is printed',
    str_contains($abroadRecord, 'CROSS-BORDER RELIEF NETWORK'));

check('record: the head office answer is stated next to the type',
    str_contains($abroadRecord, 'Outside Kenya'));

$kenyaRecord = render('applications/view.php', ['id' => 21]);

check('record: an international organization based here says so',
    str_contains($kenyaRecord, 'In Kenya'));

$unansweredRecord = render('applications/view.php', ['id' => 23]);

check('record: an unanswered question is reported as not recorded, not guessed',
    str_contains($unansweredRecord, 'Not recorded'));

/* Areas outside Kenya appear for a national organization too. */
$nationalRecord = render('applications/view.php', ['id' => 20]);

check('record: a national organization lists its country outside Kenya',
    str_contains($nationalRecord, 'UGANDA'));


/*
|--------------------------------------------------------------------------
| 7. The review page
|--------------------------------------------------------------------------
*/

echo "7. applications/review.php\n";

$reviews = [
    'national'                   => ['id' => 10, 'kenyan' => true,  'overseas' => false],
    'international, in Kenya'    => ['id' => 11, 'kenyan' => true,  'overseas' => false],
    'international, abroad'      => ['id' => 12, 'kenyan' => false, 'overseas' => true],
    'international, never asked' => ['id' => 13, 'kenyan' => false, 'overseas' => true],
];

foreach ($reviews as $label => $case) {

    $page = render('applications/review.php', ['id' => $case['id']]);

    check('review, ' . $label . ': rendered without a PHP error',
        !str_contains($page, 'Fatal error') && !str_contains($page, 'Warning:'));

    $hasOverseas = str_contains($page, 'Head Office Outside Kenya');

    check('review, ' . $label . ': overseas section '
        . ($case['overseas'] ? 'shown' : 'absent'),
        $hasOverseas === $case['overseas']);

    check('review, ' . $label . ': areas outside Kenya are listed',
        str_contains($page, 'Areas of Operation Outside Kenya'));

    $plain = preg_replace('/<!--.*?-->/s', '', $page) ?? '';
    $plain = preg_replace('/<script\b.*?<\/script>/s', '', $plain) ?? '';
    $plain = preg_replace('/<style\b.*?<\/style>/s', '', $plain) ?? '';
    $plain = preg_replace('/<[^>]*>/', ' ', $plain) ?? '';

    check('review, ' . $label . ': nothing visible says "Parent"',
        !preg_match('/\bparent/i', $plain),
        (string) (preg_match('/.{0,40}\bparent.{0,40}/i', $plain, $m) ? $m[0] : ''));
}

$reviewAbroad = render('applications/review.php', ['id' => 12]);

check('review: the overseas particulars are shown',
    str_contains($reviewAbroad, 'CANADA'));

check('review: the head office answer is stated',
    str_contains($reviewAbroad, 'Outside Kenya'));

check('review: the country recorded outside Kenya is listed',
    str_contains($reviewAbroad, 'FRANCE'));

$reviewKenya = render('applications/review.php', ['id' => 11]);

check('review: an international organization based here says so',
    str_contains($reviewKenya, 'In Kenya'));

check('review: and its Kenyan head office is still shown',
    str_contains($reviewKenya, 'UPPER HILL CHAMBERS'));


/*
|--------------------------------------------------------------------------
| 8. The two edit pages
|--------------------------------------------------------------------------
|
| Both are checked, not just one. They are near-identical files, and a
| fix applied to one and forgotten in the other is how this codebase
| ended up with two different ideas of what the overseas fields are
| called.
*/

echo "8. The edit pages\n";

/** The session an administrator needs before an edit page shows the form. */
$editSession = static fn (int $id): array => [
    'admin_edit_authorization' => [
        'user_id' => 1,
        'application_id' => $id,
        'expires_at' => time() + 600,
    ],
];

foreach (['applications/edit.php', 'applications/edit-submitted.php'] as $page) {

    $short = basename($page);

    foreach ([
        'national'              => ['id' => 20, 'kenyan' => true,  'overseas' => false],
        'international, in Kenya' => ['id' => 21, 'kenyan' => true,  'overseas' => false],
        'international, abroad' => ['id' => 22, 'kenyan' => false, 'overseas' => true],
    ] as $label => $case) {

        $form = render($page, ['id' => $case['id']], $editSession($case['id']));

        check($short . ', ' . $label . ': rendered without a PHP error',
            !str_contains($form, 'Fatal error')
                && !str_contains($form, 'Warning:')
                && !str_contains($form, 'Authorization Required'));

        $kenyan = (bool) preg_match(
            '/data-kenyan-head-office\s*\n?\s*style="display:\s*block/', $form);

        $overseas = (bool) preg_match(
            '/id="international-section"\s*\n?\s*style="display:\s*block/', $form);

        check($short . ', ' . $label . ': Kenyan card '
            . ($case['kenyan'] ? 'shown' : 'hidden'), $kenyan === $case['kenyan']);

        check($short . ', ' . $label . ': overseas card '
            . ($case['overseas'] ? 'shown' : 'hidden'), $overseas === $case['overseas']);

        check($short . ', ' . $label . ': never both at once', !($kenyan && $overseas));

        check($short . ', ' . $label . ': the question is on the page',
            str_contains($form, 'data-headquarters-question'));

        check($short . ', ' . $label . ': areas outside Kenya are offered',
            str_contains($form, 'name="operates_abroad"'));

        check($short . ', ' . $label . ': required follows the visible card',
            str_contains($form, 'data-kenyan-required="true"'));

        $plain = preg_replace('/<!--.*?-->/s', '', $form) ?? '';
        $plain = preg_replace('/<script\b.*?<\/script>/s', '', $plain) ?? '';
        $plain = preg_replace('/<style\b.*?<\/style>/s', '', $plain) ?? '';
        $plain = preg_replace('/<[^>]*>/', ' ', $plain) ?? '';

        check($short . ', ' . $label . ': nothing visible says "Parent"',
            !preg_match('/\bparent/i', $plain),
            (string) (preg_match('/.{0,40}\bparent.{0,40}/i', $plain, $m) ? $m[0] : ''));

        check($short . ', ' . $label . ': nothing says "Country of Origin"',
            !preg_match('/country of origin/i', $plain));
    }

    /* The overseas card stands where the Kenyan one does, not at the foot. */
    $abroadForm = render($page, ['id' => 22], $editSession(22));

    $kenyanAt   = at($abroadForm, 'data-kenyan-head-office');
    $overseasAt = at($abroadForm, 'id="international-section"');
    $areasAt    = at($abroadForm, 'name="operates_abroad"');

    check($short . ': the overseas card comes straight after the Kenyan one',
        $kenyanAt > -1 && $overseasAt > $kenyanAt);

    check($short . ': and BEFORE areas of operation, not after them',
        $areasAt > -1 && $overseasAt < $areasAt);

    check($short . ': the saved overseas details are on the form',
        str_contains($abroadForm, 'CANADA'));

    check($short . ': the saved country outside Kenya is on the form',
        str_contains($abroadForm, 'FRANCE'));

    check($short . ': countries are suggested as you type',
        str_contains($abroadForm, 'id="country-list"'));

    check($short . ': each country card posts an indexed row',
        (bool) preg_match('/name="foreign_locations\[\d+\]\[country\]"/', $abroadForm));

    /* The step numbers run 1, 2, 2, 3, 4... with no gap where 4 used to be. */
    check($short . ': the step numbers have no gap',
        !preg_match('/\n {13}8\. /', $abroadForm . file_get_contents($page)));
}


/*
|--------------------------------------------------------------------------
| 9. Saving
|--------------------------------------------------------------------------
|
| The render checks say the right card is on screen. This says the right
| thing reaches the database, which is where a record actually gets
| damaged.
*/

echo "9. Saving an edit\n";

$savePost = static function (int $id, array $overrides) use ($pdo): array {

    $application = $pdo->query(
        'SELECT * FROM pbo_applications WHERE id = ' . $id
    )->fetch(PDO::FETCH_ASSOC);

    $address = $pdo->query(
        'SELECT * FROM application_addresses WHERE application_id = ' . $id
            . ' ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $base = [
        'csrf_token' => 'test-token',
        'application_id' => (string) $id,
        'pbo_name' => (string) $application['pbo_name'],
        'pbo_registration_number' => (string) $application['pbo_registration_number'],
        'registration_date' => (string) $application['registration_date'],
        'registration_type' => (string) $application['registration_type'],
        'county' => (string) ($address['county'] ?? ''),
        'town' => '',
        'sub_county' => '',
        'physical_address' => (string) ($address['physical_address'] ?? ''),
        'postal_address' => (string) ($address['postal_address'] ?? ''),
        'postal_code' => (string) ($address['postal_code'] ?? ''),
        'phone_number' => (string) ($address['phone_number'] ?? ''),
        'alternative_phone_number' => '',
        'email' => (string) ($address['email'] ?? ''),
        'financial_year_start_month' => '1',
        'financial_year_end_month' => '12',
        'objectives' => ['EDUCATION'],
        'beneficiaries' => ['CHILDREN'],
        'beneficiary_scope' => 'section_of_public',
        'operating_locations' => [],

        /*
        | The three principal officials are required on this page, so a
        | post that leaves them out is refused - correctly. Filling them
        | in is what makes this a test of the head office change rather
        | than a re-test of the officials rules.
        */
        'officials' => [
            'CHAIRPERSON' => [
                'full_name' => 'JANE WANJIRU',
                'nationality' => 'KENYAN',
                'kra_pin' => 'A001234567X',
                'id_passport_number' => '12345678',
                'phone_number' => '0712345678',
            ],
            'SECRETARY' => [
                'full_name' => 'MARY OTIENO',
                'nationality' => 'KENYAN',
                'kra_pin' => 'A007654321Y',
                'id_passport_number' => '87654321',
                'phone_number' => '0733555666',
            ],
            'TREASURER' => [
                'full_name' => 'PETER KAMAU',
                'nationality' => 'KENYAN',
                'kra_pin' => 'A001122334Z',
                'id_passport_number' => '11223344',
                'phone_number' => '0722333444',
            ],
        ],
    ];

    return $overrides + $base;
};

$saveSession = static fn (int $id): array => [
    'csrf_token' => 'test-token',
    'admin_edit_authorization' => [
        'user_id' => 1,
        'application_id' => $id,
        'expires_at' => time() + 600,
    ],
];

/*
| Record 22 is international and based abroad, so it HAS an overseas row.
| Say the head office is in Kenya instead: the overseas row must go,
| rather than sitting in the register as a foreign head office that no
| longer exists.
*/
$before = (int) $pdo->query(
    'SELECT COUNT(*) FROM application_international_details WHERE application_id = 22'
)->fetchColumn();

check('record 22 starts with overseas details', $before === 1);

render(
    'applications/edit.php',
    ['id' => 22],
    $saveSession(22),
    $savePost(22, ['headquarters_in_kenya' => '1'])
);

$after = (int) $pdo->query(
    'SELECT COUNT(*) FROM application_international_details WHERE application_id = 22'
)->fetchColumn();

check('moving the head office to Kenya removes the overseas row', $after === 0);

check('and the answer is written down, not left to be guessed',
    (int) $pdo->query(
        'SELECT headquarters_in_kenya FROM pbo_applications WHERE id = 22'
    )->fetchColumn() === 1);

/* Areas of operation outside Kenya, saved from a national organization. */
render(
    'applications/edit.php',
    ['id' => 20],
    $saveSession(20),
    $savePost(20, [
        'operates_abroad' => 'yes',
        'foreign_locations' => [
            ['country' => 'Rwanda', 'city' => 'Kigali', 'physical_address' => 'KN 4 Ave'],
            ['country' => '', 'city' => 'nothing here'],
        ],
    ])
);

$saved = $pdo->query(
    'SELECT country, city FROM application_foreign_locations
      WHERE application_id = 20 ORDER BY country'
)->fetchAll(PDO::FETCH_ASSOC);

check('a national organization can save a country outside Kenya',
    count($saved) === 1, 'saved ' . count($saved) . ' rows');

check('and it is stored in block capitals like everything else',
    ($saved[0]['country'] ?? '') === 'RWANDA', (string) ($saved[0]['country'] ?? ''));

check('an empty card nobody filled in is dropped, not saved as a blank row',
    !in_array('', array_column($saved, 'country'), true));

/* And saying no again clears them. */
render(
    'applications/edit.php',
    ['id' => 20],
    $saveSession(20),
    $savePost(20, ['operates_abroad' => 'no'])
);

check('saying no again clears the countries',
    (int) $pdo->query(
        'SELECT COUNT(*) FROM application_foreign_locations WHERE application_id = 20'
    )->fetchColumn() === 0);


/*
|--------------------------------------------------------------------------
| 10. Block capitals must not eat the code fields
|--------------------------------------------------------------------------
|
| A regression found while writing the save test above, and the worst
| one in this round: the block-capitals rule raised EVERY posted field,
| including the ones holding a code rather than text. "national" became
| "NATIONAL", the check looking for "national" failed, and every save on
| the Organization page was refused with "Please select the Type of
| Organization" - with the box correctly filled in. The form could not
| be saved at all.
|
| Checked here at both levels: the rule itself, and a real save through
| the page, because a unit check on the rule would not have caught the
| page refusing to save.
*/

echo "10. Block capitals and the code fields\n";

require_once $root . '/includes/text-case.php';

foreach ([
    'registration_type' => 'national',
    'headquarters_in_kenya' => '1',
    'beneficiary_scope' => 'section_of_public',
    'operates_abroad' => 'yes',
    'registration_date' => '2019-03-14',
] as $field => $value) {
    check('a code field keeps its value: ' . $field,
        pboStoredCase($field, $value) === $value,
        pboStoredCase($field, $value));
}

foreach ([
    'pbo_name' => 'amani children trust',
    'county' => 'nairobi city county',
    'parent_organization_name' => 'amani international',
    'country_of_origin' => 'canada',
    'physical_address' => 'ring road plaza',
] as $field => $value) {
    check('and real text is still raised: ' . $field,
        pboStoredCase($field, $value) === mb_strtoupper($value),
        pboStoredCase($field, $value));
}

check('an email is still lower-cased rather than raised',
    pboStoredCase('email', 'Info@Amani.Example') === 'info@amani.example');

/* And the save the bug blocked. */
$created = render(
    'applications/create.php',
    ['id' => 10],
    ['csrf_token' => 'test-token'],
    [
        'csrf_token' => 'test-token',
        'application_id' => '10',
        'form_action' => 'save',
        'pbo_name' => 'Amani Children Trust',
        'pbo_registration_number' => 'PBO/2019/0041',
        'registration_date' => '2019-03-14',
        'registration_type' => 'national',
        'county' => 'Nairobi City County',
        'physical_address' => 'Ring Road Plaza',
        'postal_address' => 'P.O. Box 1234',
        'phone_number' => '0712345678',
        'email' => 'info@amani.example',
    ]
);

check('the entry form accepts a correctly filled in Type of Organization',
    !str_contains($created, 'Please select the Type of Organization'));

check('and the name it saved is in block capitals',
    (string) $pdo->query(
        'SELECT pbo_name FROM pbo_applications WHERE id = 10'
    )->fetchColumn() === 'AMANI CHILDREN TRUST');

check('while the type it saved is still a code the portal can read',
    (string) $pdo->query(
        'SELECT registration_type FROM pbo_applications WHERE id = 10'
    )->fetchColumn() === 'national');


/*
|--------------------------------------------------------------------------
| Result
|--------------------------------------------------------------------------
*/

echo "\n", str_repeat('-', 44), "\n";

if ($failures === []) {
    echo "\nAll {$passed} checks passed.\n\n";
    exit(0);
}

echo "\n{$passed} passed, " . count($failures) . " FAILED:\n\n";

foreach ($failures as $index => $failure) {
    echo '  ', $index + 1, '. ', $failure, "\n";
}

echo "\n";
exit(1);
