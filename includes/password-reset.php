<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Administrator password reset by email code
|--------------------------------------------------------------------------
|
| forgot-password.php  -> admin enters username or email, a 6-digit
|                         code is emailed to the address saved on the
|                         account.
| reset-password.php   -> admin enters the code and a new password.
|
| Staff passwords are still reset by an administrator.
|
*/

require_once __DIR__ . '/mailer.php';

const PBO_RESET_CODE_MINUTES = 10;   // code lifetime
const PBO_RESET_MAX_ATTEMPTS = 5;    // wrong codes before the code is locked
const PBO_RESET_MAX_REQUESTS = 3;    // codes per account per window
const PBO_RESET_WINDOW_MINUTES = 15; // request window
const PBO_RESET_SESSION_MINUTES = 30; // how long the reset page stays open


/**
 * Find an active administrator by username or email.
 * Only accounts with an email address can use this.
 */
function pboFindAdminForReset(PDO $pdo, string $identifier): ?array
{
    $statement = $pdo->prepare(
        "SELECT id, username, full_name, email
         FROM users
         WHERE (username = :username OR email = :email)
           AND role = 'admin'
           AND is_active = 1
           AND email IS NOT NULL
           AND email <> ''
         LIMIT 1"
    );

    $statement->execute([
        'username' => $identifier,
        'email'    => $identifier,
    ]);

    $admin = $statement->fetch(PDO::FETCH_ASSOC);

    return $admin ?: null;
}


/**
 * Number of codes requested for this account in the current window.
 */
function pboResetRequestsInWindow(
    PDO $pdo,
    int $userId,
    string $purpose = 'reset'
): int {
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM password_reset_codes
         WHERE user_id = :user_id
           AND purpose = :purpose
           AND created_at > (NOW() - INTERVAL ' . PBO_RESET_WINDOW_MINUTES . ' MINUTE)'
    );

    $statement->execute(['user_id' => $userId, 'purpose' => $purpose]);

    return (int) $statement->fetchColumn();
}


/**
 * Create a new 6-digit code for the account and return it.
 * Any earlier unused code of the same purpose stops working.
 *
 * $purpose is 'reset' (forgot password) or 'login' (sign-in code).
 */
function pboCreateResetCode(
    PDO $pdo,
    int $userId,
    string $ipAddress,
    string $purpose = 'reset'
): string {
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $pdo->beginTransaction();

    try {
        $pdo->prepare(
            'UPDATE password_reset_codes
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND purpose = :purpose
               AND used_at IS NULL'
        )->execute(['user_id' => $userId, 'purpose' => $purpose]);

        $pdo->prepare(
            'INSERT INTO password_reset_codes
                (user_id, purpose, code_hash, expires_at, requested_ip)
             VALUES
                (:user_id, :purpose, :code_hash,
                 NOW() + INTERVAL ' . PBO_RESET_CODE_MINUTES . ' MINUTE,
                 :requested_ip)'
        )->execute([
            'user_id'      => $userId,
            'purpose'      => $purpose,
            'code_hash'    => password_hash($code, PASSWORD_DEFAULT),
            'requested_ip' => substr($ipAddress, 0, 45),
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return $code;
}


/**
 * Email the code to the administrator.
 *
 * @throws Throwable when the email cannot be sent.
 */
function pboSendResetCodeEmail(array $admin, string $code): void
{
    $name = (string) ($admin['full_name'] ?: $admin['username']);
    $minutes = PBO_RESET_CODE_MINUTES;

    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#26313d;max-width:480px;">
    <p>Hello {$safeName},</p>
    <p>Use this code to reset your PBO Registration System password:</p>
    <p style="font-size:28px;font-weight:bold;letter-spacing:6px;color:#123b69;margin:18px 0;">{$safeCode}</p>
    <p>The code expires in {$minutes} minutes and can be used once.</p>
    <p style="color:#66717e;">If you did not ask to reset your password, ignore this email
    and tell the system administrator. Your password has not been changed.</p>
</div>
HTML;

    $text = "Hello {$name},\n\n"
        . "Use this code to reset your PBO Registration System password: {$code}\n\n"
        . "The code expires in {$minutes} minutes and can be used once.\n\n"
        . "If you did not ask to reset your password, ignore this email and tell the "
        . "system administrator. Your password has not been changed.\n";

    pboSendMail(
        (string) $admin['email'],
        $name,
        'Your PBO password reset code',
        $html,
        $text
    );
}


/**
 * Email a sign-in code to the administrator.
 *
 * @throws Throwable when the email cannot be sent.
 */
function pboSendLoginCodeEmail(array $admin, string $code): void
{
    $name = (string) ($admin['full_name'] ?: $admin['username']);
    $minutes = PBO_RESET_CODE_MINUTES;

    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#26313d;max-width:480px;">
    <p>Hello {$safeName},</p>
    <p>Your sign-in code for the PBO Registration System is:</p>
    <p style="font-size:28px;font-weight:bold;letter-spacing:6px;color:#123b69;margin:18px 0;">{$safeCode}</p>
    <p>The code expires in {$minutes} minutes and can be used once.</p>
    <p style="color:#66717e;">If you did not just try to sign in, someone may know your
    password. Change it and tell the system administrator.</p>
</div>
HTML;

    $text = "Hello {$name},\n\n"
        . "Your sign-in code for the PBO Registration System is: {$code}\n\n"
        . "The code expires in {$minutes} minutes and can be used once.\n\n"
        . "If you did not just try to sign in, someone may know your password. "
        . "Change it and tell the system administrator.\n";

    pboSendMail(
        (string) $admin['email'],
        $name,
        'Your PBO sign-in code',
        $html,
        $text
    );
}


/**
 * Check a code for the account.
 *
 * Returns ['status' => 'ok', 'code_id' => int] when the code is right.
 * Other statuses: 'invalid', 'expired', 'locked', 'none'.
 * A wrong code counts as an attempt.
 */
function pboCheckResetCode(
    PDO $pdo,
    int $userId,
    string $code,
    string $purpose = 'reset'
): array {
    $statement = $pdo->prepare(
        'SELECT id, code_hash, attempts, (expires_at <= NOW()) AS is_expired
         FROM password_reset_codes
         WHERE user_id = :user_id
           AND purpose = :purpose
           AND used_at IS NULL
         ORDER BY id DESC
         LIMIT 1'
    );

    $statement->execute(['user_id' => $userId, 'purpose' => $purpose]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['status' => 'none'];
    }

    if ((int) $row['is_expired'] === 1) {
        return ['status' => 'expired'];
    }

    if ((int) $row['attempts'] >= PBO_RESET_MAX_ATTEMPTS) {
        return ['status' => 'locked'];
    }

    if (
        !preg_match('/^\d{6}$/', $code)
        || !password_verify($code, (string) $row['code_hash'])
    ) {
        $pdo->prepare(
            'UPDATE password_reset_codes
             SET attempts = attempts + 1
             WHERE id = :id'
        )->execute(['id' => $row['id']]);

        $attempts = (int) $row['attempts'] + 1;

        return [
            'status' => $attempts >= PBO_RESET_MAX_ATTEMPTS ? 'locked' : 'invalid',
        ];
    }

    return ['status' => 'ok', 'code_id' => (int) $row['id']];
}


/**
 * Save the new password and use up the code.
 *
 * Returns false if the code was already used (e.g. submitted twice).
 */
function pboCompletePasswordReset(
    PDO $pdo,
    int $userId,
    int $codeId,
    string $newPassword
): bool {
    $pdo->beginTransaction();

    try {
        $useCode = $pdo->prepare(
            'UPDATE password_reset_codes
             SET used_at = NOW()
             WHERE id = :id
               AND user_id = :user_id
               AND purpose = :purpose
               AND used_at IS NULL'
        );

        $useCode->execute([
            'id'      => $codeId,
            'user_id' => $userId,
            'purpose' => 'reset',
        ]);

        if ($useCode->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        $updateUser = $pdo->prepare(
            "UPDATE users
             SET password_hash = :password_hash,
                 must_change_password = 0
             WHERE id = :id
               AND role = 'admin'
               AND is_active = 1"
        );

        $updateUser->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id'            => $userId,
        ]);

        if ($updateUser->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| Per-browser limits (stored in the session)
|--------------------------------------------------------------------------
*/

/**
 * Whether this browser may request another code right now.
 * Limits one browser to 5 requests per 15 minutes, whatever the account.
 */
function pboSessionMayRequestCode(): bool
{
    $window = PBO_RESET_WINDOW_MINUTES * 60;
    $requests = $_SESSION['password_reset_requests'] ?? [];

    $requests = array_values(array_filter(
        is_array($requests) ? $requests : [],
        static fn ($time): bool => is_int($time) && $time > time() - $window
    ));

    $_SESSION['password_reset_requests'] = $requests;

    return count($requests) < 5;
}


function pboRecordSessionCodeRequest(): void
{
    $_SESSION['password_reset_requests'][] = time();
}


/**
 * Start the reset page for this browser.
 * $userId is 0 when no matching administrator was found, so the page
 * behaves the same either way and does not reveal which accounts exist.
 */
function pboStartResetSession(int $userId): void
{
    $_SESSION['password_reset'] = [
        'user_id'    => $userId,
        'started_at' => time(),
        'attempts'   => 0,
    ];
}


/**
 * Current reset state, or null when there is none or it has run out.
 */
function pboResetSession(): ?array
{
    $reset = $_SESSION['password_reset'] ?? null;

    if (
        !is_array($reset)
        || (int) ($reset['started_at'] ?? 0) < time() - PBO_RESET_SESSION_MINUTES * 60
    ) {
        unset($_SESSION['password_reset']);
        return null;
    }

    return $reset;
}


function pboClearResetSession(): void
{
    unset($_SESSION['password_reset']);
}
