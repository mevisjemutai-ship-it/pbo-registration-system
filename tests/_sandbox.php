<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| _sandbox.php - run the tests somewhere safe
|--------------------------------------------------------------------------
|
| The tests need a config/database.php pointing at a throwaway SQLite file.
| A deployed portal already has one, pointing at the real database, and
| swapping it out and back on a live server is not something a test should
| ever do: kill the process at the wrong moment and the portal is down.
|
| So when a config/database.php is found, the test copies the portal to a
| temporary folder, runs itself there, and deletes the copy afterwards.
| The real installation is never written to, and the tests can be run on
| the server without any preparation.
|
| Called as the first line of each test:
|
|     require __DIR__ . '/_sandbox.php';
|
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

/**
 * Copy a directory, skipping what a test has no use for.
 *
 * The exclusions matter for speed and for safety: .git is large, uploads
 * may hold scanned signatures, and the SQL dumps are tens of megabytes.
 * None of them is read by the pages under test.
 */
function sandboxCopy(string $from, string $to): void
{
    $skipNames = ['.git', 'node_modules', 'uploads', 'storage'];

    @mkdir($to, 0700, true);

    /*
    | config/ holds the database settings and the SMTP password. Nothing
    | under test reads either, and on a properly locked-down server the
    | mail settings are not even readable by the account running the
    | tests - so copying them is both pointless and noisy.
    |
    | The directory itself is created, because the test writes its own
    | throwaway config/database.php into it.
    */
    if (basename($from) === 'config') {
        return;
    }

    $entries = scandir($from);

    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {

        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $source = $from . '/' . $entry;
        $target = $to . '/' . $entry;

        if (is_dir($source)) {

            if (in_array($entry, $skipNames, true)) {
                continue;
            }

            sandboxCopy($source, $target);

            continue;
        }

        /* Database dumps are big and nothing here reads them. */
        if (str_ends_with($entry, '.sql') && basename($from) === 'database') {
            /* The migrations are small and worth having; the dumps are not. */
            if (filesize($source) > 2 * 1024 * 1024) {
                continue;
            }
        }

        /*
        | A file this account cannot read is not a reason to fail, and a
        | raw PHP warning in the middle of a test run looks like a broken
        | test. Skip it and say so once at the end.
        */
        if (!is_readable($source)) {
            $GLOBALS['sandboxSkipped'][] = $source;

            continue;
        }

        @copy($source, $target);
    }
}


function sandboxRemove(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);

        return;
    }

    $entries = scandir($path);

    if ($entries !== false) {
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                sandboxRemove($path . '/' . $entry);
            }
        }
    }

    @rmdir($path);
}


$sandboxRoot = dirname(__DIR__);

/* Already inside the copy: carry on and run the test. */
if (getenv('PBO_TEST_SANDBOX') === '1') {
    return;
}

/* No settings file in the way: run here, as before. */
if (!is_file($sandboxRoot . '/config/database.php')) {
    return;
}

$sandboxTarget = sys_get_temp_dir() . '/pbo-test-' . bin2hex(random_bytes(6));

echo "A config/database.php is present, so this installation is left alone.\n";
echo "Running the test on a temporary copy instead.\n\n";

$GLOBALS['sandboxSkipped'] = [];

sandboxCopy($sandboxRoot, $sandboxTarget);

if ($GLOBALS['sandboxSkipped'] !== []) {
    echo 'Skipped ' . count($GLOBALS['sandboxSkipped'])
        . " unreadable file(s); nothing under test reads them.\n\n";
}

register_shutdown_function(static function () use ($sandboxTarget): void {
    sandboxRemove($sandboxTarget);
});

$sandboxTest = $sandboxTarget . '/tests/' . basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

if (!is_file($sandboxTest)) {
    fwrite(STDERR, "Could not place the test in the temporary copy.\n");
    exit(2);
}

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sandboxTest);

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

$process = proc_open(
    $command,
    $descriptors,
    $pipes,
    $sandboxTarget,
    ['PBO_TEST_SANDBOX' => '1', 'PATH' => (string) getenv('PATH')]
);

if (!is_resource($process)) {
    fwrite(STDERR, "Could not start the test in the temporary copy.\n");
    exit(2);
}

echo stream_get_contents($pipes[1]);

$errors = stream_get_contents($pipes[2]);

if (trim($errors) !== '') {
    fwrite(STDERR, $errors);
}

fclose($pipes[1]);
fclose($pipes[2]);

exit(proc_close($process));
