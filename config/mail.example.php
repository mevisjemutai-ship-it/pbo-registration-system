<?php

/*
|--------------------------------------------------------------------------
| Email (SMTP) settings
|--------------------------------------------------------------------------
|
| 1. Copy this file to config/mail.php (same folder).
| 2. Fill in the details below.
| 3. Keep config/mail.php out of Git: it contains a password.
|
| Gmail, for testing:
|   - Turn on 2-Step Verification on the Google account.
|   - Create an App Password at https://myaccount.google.com/apppasswords
|   - Paste the 16-character App Password below (not your normal password).
|
/*


return [
    'host'        => 'smtp.gmail.com',
    'port'        => 587,
    'encryption'  => 'tls',   // 'tls' for port 587, 'ssl' for port 465, '' for none
    'username'    => 'your.address@gmail.com',
    'password'    => 'your-app-password',

    'from_email'  => 'your.address@gmail.com',
    'from_name'   => 'PBO Registration System',

    // true only while setting up: shows the real email error on screen.
    // Set back to false afterwards.
    'show_errors' => false,
];
