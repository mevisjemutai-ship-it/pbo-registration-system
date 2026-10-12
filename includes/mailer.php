<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sending email
|--------------------------------------------------------------------------
|
| Uses PHPMailer (includes/PHPMailer) with the SMTP settings in
| config/mail.php. See config/mail.example.php.
|
*/

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';


/**
 * Load config/mail.php.
 *
 * @throws RuntimeException when the file is missing or invalid.
 */
function pboMailConfig(): array
{
    $file = __DIR__ . '/../config/mail.php';

    if (!is_file($file)) {
        throw new RuntimeException(
            'Email settings file config/mail.php is missing. '
            . 'Copy config/mail.example.php to config/mail.php and fill it in.'
        );
    }

    $config = require $file;

    if (!is_array($config)) {
        throw new RuntimeException('config/mail.php must return an array.');
    }

    return $config;
}


/**
 * Whether real email errors may be shown on screen (setup only).
 */
function pboMailShowErrors(): bool
{
    try {
        return !empty(pboMailConfig()['show_errors']);
    } catch (Throwable $exception) {
        return false;
    }
}


/**
 * Send one email.
 *
 * @throws Throwable when the email cannot be sent.
 */
function pboSendMail(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): void {
    $config = pboMailConfig();

    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = (string) ($config['host'] ?? '');
    $mail->Port = (int) ($config['port'] ?? 587);
    $mail->Timeout = 20;
    $mail->CharSet = PHPMailer::CHARSET_UTF8;

    $username = (string) ($config['username'] ?? '');

    $mail->SMTPAuth = $username !== '';
    $mail->Username = $username;
    $mail->Password = (string) ($config['password'] ?? '');

    $encryption = strtolower((string) ($config['encryption'] ?? 'tls'));

    if ($encryption === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($encryption === 'tls') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $mail->setFrom(
        (string) ($config['from_email'] ?? $username),
        (string) ($config['from_name'] ?? 'PBO Registration System')
    );

    $mail->addAddress($toEmail, $toName);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = $textBody;

    $mail->send();
}
