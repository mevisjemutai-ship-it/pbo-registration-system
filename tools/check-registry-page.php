<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| check-registry-page.php - why are the export buttons not showing?
|--------------------------------------------------------------------------
|
|   php tools/check-registry-page.php
|
| There are only three reasons the toolbar above the List of PBOs can be
| missing, and this says which one applies:
|
|   1. the updated files are not on the server;
|   2. they are there, but the browser is still showing a cached stylesheet
|      or script;
|   3. everything is in place and the list is simply empty - the toolbar
|      only appears when there are rows to act on.
|
| Reports only. Changes nothing.
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

$root = dirname(__DIR__);

$problems = [];
$notes = [];

function line(string $label, string $value): void
{
    printf("  %-40s %s\n", $label . ':', $value);
}

function verdict(string $symbol, string $text): void
{
    echo '  ', $symbol, ' ', $text, "\n";
}

echo "List of PBOs - export and print buttons\n";
echo str_repeat('-', 44), "\n\n";


/*
|--------------------------------------------------------------------------
| 1. Are the files there?
|--------------------------------------------------------------------------
*/

echo "1. Files\n";

$required = [
    'applications/pbos.php' => 'data-registry-actions',
    'applications/export-registry.php' => 'MAX_DETAIL_RECORDS',
    'assets/js/registry-export.js' => 'data-registry-tick',
    'includes/pbo-registry-filters.php' => 'pboRegistrySortOptions',
    'assets/css/style.css' => '.registry-actions',
];

foreach ($required as $relative => $marker) {

    $path = $root . '/' . $relative;

    if (!is_file($path)) {
        $problems[] = $relative . ' is not on this server. The update was not '
            . 'copied over, or was copied to the wrong folder.';
        verdict('X', $relative . ' is MISSING');

        continue;
    }

    $contents = (string) file_get_contents($path);

    if (!str_contains($contents, $marker)) {
        $problems[] = $relative . ' is present but is the OLD version - it does '
            . 'not contain "' . $marker . '". Copy the update again and make sure '
            . 'it lands in ' . $root . '.';
        verdict('X', $relative . ' is the OLD version');

        continue;
    }

    verdict('OK', $relative . '  (' . date('j M H:i', (int) filemtime($path)) . ')');
}


/*
|--------------------------------------------------------------------------
| 2. Can the web server read them?
|--------------------------------------------------------------------------
*/

echo "\n2. Permissions\n";

foreach (array_keys($required) as $relative) {

    $path = $root . '/' . $relative;

    if (!is_file($path)) {
        continue;
    }

    if (!is_readable($path)) {
        $problems[] = $relative . ' cannot be read by this account. Run: '
            . 'sudo chown -R www-data:www-data ' . $root;
        verdict('X', $relative . ' is not readable');
    }
}

if ($problems === []) {
    verdict('OK', 'Every file is readable.');
}


/*
|--------------------------------------------------------------------------
| 3. Is there anything in the list?
|--------------------------------------------------------------------------
|
| The toolbar is inside the "if there are rows" branch, so an empty
| register shows no buttons. That is deliberate - buttons that would
| export nothing are worse than no buttons - but it looks identical to a
| failed update from the outside.
*/

echo "\n3. Records in the register\n";

$configPath = $root . '/config/database.php';

if (!is_file($configPath)) {

    $notes[] = 'config/database.php is missing, so the number of records could '
        . 'not be checked.';
    verdict('!', 'No database settings; skipping this check.');

} else {

    try {
        require $configPath;

        $connection = $pdo ?? $db ?? $connection ?? null;

        if (!$connection instanceof PDO) {
            throw new RuntimeException('config/database.php produced no connection.');
        }

        $submitted = (int) $connection
            ->query("SELECT COUNT(*) FROM pbo_applications WHERE status = 'submitted'")
            ->fetchColumn();

        $drafts = (int) $connection
            ->query("SELECT COUNT(*) FROM pbo_applications WHERE status = 'draft'")
            ->fetchColumn();

        line('Submitted (these appear in the list)', (string) $submitted);
        line('Drafts (these do not)', (string) $drafts);

        if ($submitted === 0) {
            $problems[] = 'There are no SUBMITTED applications, so the List of PBOs '
                . 'is empty and the toolbar has nothing to act on - which is why no '
                . 'buttons appear. '
                . ($drafts > 0
                    ? 'There are ' . $drafts . ' draft(s): a draft only reaches the '
                      . 'list once it is submitted from the Review page.'
                    : 'Submit an application and the buttons will appear.');
            verdict('X', 'The register is empty - this is almost certainly the reason.');
        } else {
            verdict('OK', $submitted . ' submitted organization(s) - the list has rows.');
        }

    } catch (Throwable $exception) {
        $notes[] = 'Could not count the records: ' . $exception->getMessage();
        verdict('!', 'Database check failed: ' . $exception->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| Verdict
|--------------------------------------------------------------------------
*/

echo "\n", str_repeat('-', 44), "\n";

if ($problems === []) {

    /*
    | Only claim the register has rows if that was actually checked. A
    | tool that reports something it did not look at is worse than one
    | that says it could not look.
    */
    echo $notes === []
        ? "\nEverything is in place and the register has rows.\n\n"
        : "\nThe files are all in place. The number of records was NOT checked\n"
          . "(see the note below), so an empty register is still possible.\n\n";

    echo "If the buttons still do not show, the browser is holding an old\n";
    echo "copy of the stylesheet or the script. Reload the List of PBOs with\n";
    echo "Ctrl+Shift+R (Cmd+Shift+R on a Mac), which fetches them again\n";
    echo "instead of reusing what it has.\n\n";
} else {
    echo "\nFound " . count($problems) . ":\n\n";

    foreach ($problems as $index => $text) {
        echo '  ', $index + 1, '. ', wordwrap($text, 70, "\n     "), "\n\n";
    }
}

foreach ($notes as $note) {
    echo '  Note: ', wordwrap($note, 70, "\n        "), "\n";
}

exit($problems === [] ? 0 : 1);
