<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Database Configuration Example
|--------------------------------------------------------------------------
|
| Copy this file to:
|
|     config/database.php
|
| Then replace the example values with the database credentials for the
| server where the PBO Registration System is installed.
|
*/

$host = 'localhost';
$dbname = 'pbo_portal';
$dbuser = 'YOUR_DATABASE_USER';
$dbpass = 'YOUR_DATABASE_PASSWORD';


try {

    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $dbuser,
        $dbpass,
        [
            PDO::ATTR_ERRMODE
                => PDO::ERRMODE_EXCEPTION,

            PDO::ATTR_DEFAULT_FETCH_MODE
                => PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES
                => false,
        ]
    );

} catch (PDOException $e) {

    http_response_code(500);

    exit(
        'Database connection failed.'
    );
}
