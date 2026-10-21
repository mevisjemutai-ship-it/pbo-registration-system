<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| _render-page.php - test harness only
|--------------------------------------------------------------------------
|
| Renders one portal page from the command line with a signed-in session
| and a query string, so a test can read what the page produced.
|
|   PBO_TEST_PAGE=applications/export-registry.php \
|   PBO_TEST_QUERY='mode=list&format=print' \
|   php tests/_render-page.php
|
| Not reachable from the web: it is outside the pages directory, it takes
| its input from environment variables rather than the request, and it
| refuses to run anywhere but the command line.
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$page = (string) getenv('PBO_TEST_PAGE');

if ($page === '') {
    fwrite(STDERR, "PBO_TEST_PAGE is not set.\n");
    exit(2);
}

$path = realpath(dirname(__DIR__) . '/' . $page);

if ($path === false || !is_file($path)) {
    fwrite(STDERR, "No such page: {$page}\n");
    exit(2);
}

/*
| Start the session the way the portal does, then sign in, before the page
| requires auth.php: session_start() there finds a session already active
| and leaves these values alone.
*/
require_once dirname(__DIR__) . '/includes/session.php';

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'Test Officer';
$_SESSION['must_change_password'] = false;

parse_str((string) getenv('PBO_TEST_QUERY'), $_GET);

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/pbo-portal/' . $page;

require $path;
