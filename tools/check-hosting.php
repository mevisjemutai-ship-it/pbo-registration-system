<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| check-hosting.php - what is different about the server
|--------------------------------------------------------------------------
|
| Run on the SERVER, from the portal's folder:
|
|     php tools/check-hosting.php
|
| Then run the same command on the local WSL copy and compare the two
| outputs. The lines that differ are the answer: everything here is a
| setting that works locally and can quietly fail once hosted.
|
| It reports. It changes nothing, sends no email, and writes no files
| except one throwaway test file it deletes again.
|
| Deliberately command-line only. It prints whether an SMTP password is
| configured and where session files live, which is not something to leave
| reachable from a browser.
|
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is for the command line only.\n");
}

$root = dirname(__DIR__);

$problems = [];
$warnings = [];

function heading(string $text): void
{
    echo "\n", $text, "\n", str_repeat('-', strlen($text)), "\n";
}

function line(string $label, string $value): void
{
    printf("  %-34s %s\n", $label . ':', $value);
}

function verdict(string $symbol, string $text): void
{
    echo '  ', $symbol, ' ', $text, "\n";
}


echo "PBO portal - hosting check\n";
echo "Run at ", date('Y-m-d H:i:s T'), "\n";
line('Folder', $root);
line('Host', (string) (gethostname() ?: 'unknown'));


/*
|--------------------------------------------------------------------------
| 1. PHP
|--------------------------------------------------------------------------
*/

heading('1. PHP');

line('Version', PHP_VERSION);
line('Loaded php.ini', (string) (php_ini_loaded_file() ?: 'none'));

if (version_compare(PHP_VERSION, '8.0', '<')) {
    $problems[] = 'PHP ' . PHP_VERSION . ' is older than 8.0; this portal uses PHP 8 syntax.';
    verdict('X', 'PHP is too old for this code.');
} else {
    verdict('OK', 'PHP version is fine.');
}

foreach (['pdo_mysql', 'openssl', 'mbstring'] as $extension) {
    if (!extension_loaded($extension)) {
        $problems[] = 'The PHP extension ' . $extension . ' is not loaded.';
        verdict('X', $extension . ' is MISSING.');
    }
}

if (!extension_loaded('openssl')) {
    $problems[] = 'Without openssl, PHPMailer cannot use TLS, so no sign-in code can be sent.';
}


/*
|--------------------------------------------------------------------------
| 2. Sessions - the usual reason people get signed out
|--------------------------------------------------------------------------
*/

heading('2. Sessions');

$configuredPath = $root . '/storage/sessions';
$effectivePath = session_save_path();

line('gc_maxlifetime (seconds)', (string) ini_get('session.gc_maxlifetime'));
line('save_path in php.ini', $effectivePath !== '' ? $effectivePath : '(server default, usually /tmp)');
line('Portal session folder', $configuredPath);

if (!is_dir($configuredPath)) {
    $warnings[] = 'storage/sessions does not exist yet. It is created on the first page load, '
        . 'but only if the web server user may write to ' . $root . '.';
    verdict('!', 'storage/sessions does not exist yet.');
} elseif (!is_writable($configuredPath)) {
    $problems[] = 'storage/sessions exists but the web server cannot write to it, so the portal '
        . 'falls back to the shared session folder and sign-outs will continue. '
        . 'Fix with: sudo chown -R www-data:www-data ' . $configuredPath;
    verdict('X', 'storage/sessions is NOT writable by this user.');
} else {
    verdict('OK', 'storage/sessions exists and is writable.');
}

/* Can the web server create it at all? Test the parent. */
if (!is_dir($configuredPath) && !is_writable($root)) {
    $problems[] = 'The portal folder is not writable, so storage/sessions can never be created. '
        . 'Create it by hand and give it to the web server user.';
}

$lifetime = (int) ini_get('session.gc_maxlifetime');

if ($lifetime > 0 && $lifetime < 3600) {
    $warnings[] = 'session.gc_maxlifetime is ' . $lifetime . ' seconds ('
        . round($lifetime / 60) . ' minutes). includes/session.php raises this at runtime, '
        . 'so this only matters if that file is missing from the server.';
}

if (!is_file($root . '/includes/session.php')) {
    $problems[] = 'includes/session.php is NOT on this server. That file is the fix for the '
        . 'sign-out problem; without it the server defaults apply.';
    verdict('X', 'includes/session.php is missing - the sign-out fix is not deployed.');
} else {
    verdict('OK', 'includes/session.php is present.');
}

/* A shared /tmp is the classic shared-hosting trap. */
if ($effectivePath === '' || str_starts_with($effectivePath, '/tmp')) {
    $warnings[] = 'The server default session folder is shared with every other site on this '
        . 'machine. Other sites delete files there on their own schedule - this is the usual '
        . 'cause of being signed out while still working.';
}


/*
|--------------------------------------------------------------------------
| 3. Email - the usual reason the sign-in code never arrives
|--------------------------------------------------------------------------
*/

heading('3. Email (administrator sign-in codes)');

$mailFile = $root . '/config/mail.php';

if (!is_file($mailFile)) {
    $problems[] = 'config/mail.php is MISSING on this server. It is excluded from Git on purpose '
        . '(it holds a password), so it is never deployed by a git pull or a zip from the repo. '
        . 'Copy config/mail.example.php to config/mail.php and fill it in. '
        . 'Until then every sign-in code fails before anything is sent, and the administrator '
        . 'only sees "We could not send your sign-in code".';
    verdict('X', 'config/mail.php is MISSING. This alone breaks the sign-in code.');
    $mail = null;
} else {
    verdict('OK', 'config/mail.php exists.');

    $mail = require $mailFile;

    if (!is_array($mail)) {
        $problems[] = 'config/mail.php does not return an array.';
        $mail = null;
    }
}

if (is_array($mail)) {
    line('SMTP host', (string) ($mail['host'] ?? '(not set)'));
    line('SMTP port', (string) ($mail['port'] ?? '(not set)'));
    line('Encryption', (string) ($mail['encryption'] ?? '(not set)'));
    line('Username', (string) ($mail['username'] ?? '(not set)'));
    line('Password set', ($mail['password'] ?? '') !== '' ? 'yes' : 'NO');
    line('From address', (string) ($mail['from_email'] ?? '(not set)'));
    line('show_errors', !empty($mail['show_errors']) ? 'true' : 'false');

    foreach (['host', 'username', 'password', 'from_email'] as $key) {
        if (trim((string) ($mail[$key] ?? '')) === '') {
            $problems[] = 'config/mail.php has no value for "' . $key . '".';
        }
    }

    if (str_contains((string) ($mail['username'] ?? ''), 'your.address@')) {
        $problems[] = 'config/mail.php still contains the example values from mail.example.php.';
        verdict('X', 'The example values were never replaced.');
    }

    /*
    | The decisive test: can this server open the SMTP port at all? Many
    | hosts block outbound 587 and 465 to stop spam, which is invisible
    | locally and fatal once hosted.
    */
    $host = (string) ($mail['host'] ?? '');
    $port = (int) ($mail['port'] ?? 587);

    if ($host !== '') {
        echo "\n";
        verdict('..', 'Trying to open ' . $host . ':' . $port . ' (10 second limit)...');

        $errorNumber = 0;
        $errorText = '';

        $socket = @fsockopen($host, $port, $errorNumber, $errorText, 10);

        if ($socket) {
            stream_set_timeout($socket, 10);
            $banner = trim((string) fgets($socket, 512));
            fclose($socket);

            verdict('OK', 'Connected. Server said: ' . ($banner !== '' ? $banner : '(no banner)'));

            if ($banner !== '' && !str_starts_with($banner, '220')) {
                $warnings[] = 'The SMTP server answered with "' . $banner
                    . '" instead of the usual 220 greeting.';
            }
        } else {
            $problems[] = 'This server cannot reach ' . $host . ':' . $port
                . ' (' . $errorText . '). Outbound SMTP is almost certainly blocked by the host. '
                . 'Ask the hosting provider to open it, or switch to a provider-approved relay '
                . 'or an email API.';
            verdict('X', 'Could NOT connect: ' . $errorText);
        }
    }
}


/*
|--------------------------------------------------------------------------
| 3b. Form submission limits
|--------------------------------------------------------------------------
|
| The officials page posts a lot of fields and uploads signature images.
| Three separate limits can swallow that submission, and none of them says
| anything on screen - which is what "it saved but not the rest of what I
| entered" and "Access to Server denied" look like from the outside.
|
*/

heading('3b. Form submission limits');

$inputVars = (int) ini_get('max_input_vars');

function toBytes(string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    $unit = strtolower($value[strlen($value) - 1]);
    $number = (int) $value;

    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

$postMax = toBytes((string) ini_get('post_max_size'));
$uploadMax = toBytes((string) ini_get('upload_max_filesize'));

line('max_input_vars', (string) ($inputVars ?: 'unlimited'));
line('post_max_size', (string) ini_get('post_max_size'));
line('upload_max_filesize', (string) ini_get('upload_max_filesize'));
line('max_file_uploads', (string) ini_get('max_file_uploads'));
line('memory_limit', (string) ini_get('memory_limit'));
line('max_execution_time', (string) ini_get('max_execution_time'));

/*
| An application operating in every county posts roughly 450 fields; a
| generous margin on top of that is 800. Below that and PHP starts
| DISCARDING fields past the limit, silently - the save looks fine and
| the data is simply not there.
*/
if ($inputVars > 0 && $inputVars < 800) {
    $problems[] = 'max_input_vars is ' . $inputVars . '. A full application posts several '
        . 'hundred fields, and PHP silently discards everything past this limit - no error, '
        . 'no warning, just missing data. Raise it to at least 3000.';
    verdict('X', 'max_input_vars is too low: ' . $inputVars);
} else {
    verdict('OK', 'max_input_vars leaves room for a full application.');
}

/* post_max_size must clear the signature uploads with room to spare. */
if ($postMax > 0 && $postMax < 16 * 1024 * 1024) {
    $warnings[] = 'post_max_size is ' . ini_get('post_max_size') . '. The officials page can '
        . 'carry several signature images; if a submission exceeds this, PHP throws the WHOLE '
        . 'submission away and the page sees an empty form with no explanation. 16M or more '
        . 'is safer.';
}

if ($uploadMax > $postMax && $postMax > 0) {
    $problems[] = 'upload_max_filesize (' . ini_get('upload_max_filesize') . ') is larger than '
        . 'post_max_size (' . ini_get('post_max_size') . '). A file that passes the first check '
        . 'is still discarded by the second.';
    verdict('X', 'upload_max_filesize exceeds post_max_size.');
}

/*
| A web application firewall in front of PHP answers with its own 403 page
| before the portal is reached - which is why "Access to Server denied"
| appears in no part of the portal's own code.
*/
$modules = function_exists('apache_get_modules') ? apache_get_modules() : [];

if ($modules !== []) {
    $firewall = array_values(array_filter(
        $modules,
        static fn ($m) => str_contains($m, 'security') || str_contains($m, 'waf')
    ));

    line('Apache modules loaded', (string) count($modules));

    if ($firewall !== []) {
        $warnings[] = 'A web application firewall is loaded (' . implode(', ', $firewall)
            . '). These commonly block file uploads and large form posts, answering with '
            . 'their own "access denied" page before PHP runs. If staff see an access-denied '
            . 'message when saving the officials page, ask the host for that module\'s log '
            . 'around that time - it records what rule fired and why.';
        verdict('!', 'A firewall module is loaded: ' . implode(', ', $firewall));
    }
} else {
    $warnings[] = 'Could not list the web server modules from the command line, so this check '
        . 'cannot say whether a firewall module is in front of PHP. If staff see an '
        . 'access-denied message when saving, ask the host to check the error log and any '
        . 'firewall log for that request.';
}


/*
|--------------------------------------------------------------------------
| 4. Database
|--------------------------------------------------------------------------
*/

heading('4. Database');

$databaseFile = $root . '/config/database.php';

if (!is_file($databaseFile)) {
    $problems[] = 'config/database.php is missing on this server.';
    verdict('X', 'config/database.php is MISSING.');
} else {
    verdict('OK', 'config/database.php exists.');

    try {
        require $databaseFile;

        $connection = $pdo ?? $connection ?? $db ?? null;

        if ($connection instanceof PDO) {
            $tables = $connection
                ->query('SHOW TABLES')
                ->fetchAll(PDO::FETCH_COLUMN);

            verdict('OK', 'Connected. ' . count($tables) . ' tables.');

            foreach (
                [
                    'users',
                    'password_reset_codes',
                    'trusted_devices',
                    'kenya_counties',
                ] as $table
            ) {
                if (!in_array($table, $tables, true)) {
                    $problems[] = 'The table "' . $table . '" is missing from this database.';
                    verdict('X', 'Table ' . $table . ' is MISSING.');
                }
            }

            /* An administrator with no email can never receive a code. */
            if (in_array('users', $tables, true)) {
                $adminsWithoutEmail = (int) $connection->query(
                    "SELECT COUNT(*) FROM users
                      WHERE role = 'admin'
                        AND (email IS NULL OR TRIM(email) = '')"
                )->fetchColumn();

                line('Admins with no email address', (string) $adminsWithoutEmail);

                if ($adminsWithoutEmail > 0) {
                    $warnings[] = $adminsWithoutEmail . ' administrator account(s) have no email '
                        . 'address. Those accounts skip the sign-in code entirely - which looks '
                        . 'like "the code never came" from the outside.';
                }
            }
        } else {
            $problems[] = 'config/database.php did not produce a PDO connection.';
        }
    } catch (Throwable $exception) {
        $problems[] = 'Could not connect to the database: ' . $exception->getMessage();
        verdict('X', 'Connection failed: ' . $exception->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| 5. Where errors are being written
|--------------------------------------------------------------------------
*/

heading('5. Error log');

line('log_errors', ini_get('log_errors') ? 'on' : 'OFF');
line('error_log', (string) (ini_get('error_log') ?: '(the web server log)'));

if (!ini_get('log_errors')) {
    $warnings[] = 'log_errors is off, so the reasons behind failed sign-in codes are being '
        . 'thrown away. Turn it on while investigating.';
}


/*
|--------------------------------------------------------------------------
| 6. Can the portal write where it needs to
|--------------------------------------------------------------------------
*/

heading('6. Folder permissions');

line('Running as', (string) (function_exists('posix_getpwuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
    : 'unknown'));

foreach (['uploads', 'storage/sessions'] as $relative) {
    $folder = $root . '/' . $relative;

    if (!is_dir($folder)) {
        verdict('!', $relative . ' does not exist.');
        continue;
    }

    $probe = $folder . '/.write-test-' . bin2hex(random_bytes(4));
    $written = @file_put_contents($probe, 'test') !== false;

    if ($written) {
        @unlink($probe);
        verdict('OK', $relative . ' is writable.');
    } else {
        $problems[] = $relative . ' is not writable by the web server user.';
        verdict('X', $relative . ' is NOT writable.');
    }
}


/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

heading('Summary');

if ($problems === [] && $warnings === []) {
    echo "  Nothing found. If the problem persists, run this on the local copy too\n";
    echo "  and compare - the difference is somewhere this script does not look.\n";
}

if ($problems !== []) {
    echo "\n  PROBLEMS (" . count($problems) . ")\n\n";

    foreach ($problems as $index => $text) {
        echo '  ', $index + 1, '. ', wordwrap($text, 72, "\n     "), "\n\n";
    }
}

if ($warnings !== []) {
    echo "\n  WORTH KNOWING (" . count($warnings) . ")\n\n";

    foreach ($warnings as $index => $text) {
        echo '  ', $index + 1, '. ', wordwrap($text, 72, "\n     "), "\n\n";
    }
}

exit($problems === [] ? 0 : 1);
