<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Administrator sign-in code (2-step login)
|--------------------------------------------------------------------------
|
| Staff sign in with username + password only.
|
| Administrators also need a 6-digit code sent to their email when:
|   - they sign in on a browser they have not trusted, or
|   - the browser has not been used to sign in for PBO_TRUST_IDLE_DAYS, or
|   - the browser was trusted more than PBO_TRUST_MAX_DAYS ago.
|
| Administrators without an email address skip the code (logged as a
| warning) until an email is added to their account.
|
*/

require_once __DIR__ . '/password-reset.php';

const PBO_TRUST_IDLE_DAYS = 7;          // ask again after this many days unused
const PBO_TRUST_MAX_DAYS = 30;          // ask again at least this often
const PBO_TRUST_COOKIE = 'pbo_trusted_device';
const PBO_PENDING_LOGIN_MINUTES = 15;   // how long the code page stays open


/*
|--------------------------------------------------------------------------
| Finishing a sign-in
|--------------------------------------------------------------------------
*/

/**
 * Load a user by id with the fields needed to sign in.
 */
function pboLoadUserForLogin(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, username, full_name, email, role, is_active, must_change_password
         FROM users
         WHERE id = :id
         LIMIT 1'
    );

    $statement->execute(['id' => $userId]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}


/**
 * Start the signed-in session and send the user to the right page.
 * This is the same thing login.php always did after a correct password.
 */
function pboFinishLogin(PDO $pdo, array $user): void
{
    session_regenerate_id(true);

    unset($_SESSION['pending_login']);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['must_change_password'] = (bool) $user['must_change_password'];

    $pdo->prepare(
        'UPDATE users
         SET last_login_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    )->execute(['id' => $user['id']]);

    if ($user['must_change_password']) {
        header('Location: change-password.php');
    } else {
        header('Location: dashboard.php');
    }

    exit;
}


/**
 * Whether this user must enter a sign-in code on this browser.
 */
function pboLoginNeedsCode(PDO $pdo, array $user): bool
{
    if (($user['role'] ?? '') !== 'admin') {
        return false;
    }

    if (trim((string) ($user['email'] ?? '')) === '') {
        error_log(
            'PBO sign-in: administrator ' . $user['username']
            . ' has no email address, so no sign-in code was required.'
        );
        return false;
    }

    return !pboIsTrustedDevice($pdo, (int) $user['id']);
}


/*
|--------------------------------------------------------------------------
| Waiting for the code (stored in the session, not signed in yet)
|--------------------------------------------------------------------------
*/

function pboStartPendingLogin(int $userId): void
{
    session_regenerate_id(true);

    $_SESSION['pending_login'] = [
        'user_id'    => $userId,
        'started_at' => time(),
        'attempts'   => 0,
    ];
}


function pboPendingLogin(): ?array
{
    $pending = $_SESSION['pending_login'] ?? null;

    if (
        !is_array($pending)
        || (int) ($pending['started_at'] ?? 0) < time() - PBO_PENDING_LOGIN_MINUTES * 60
    ) {
        unset($_SESSION['pending_login']);
        return null;
    }

    return $pending;
}


/**
 * Create and email a sign-in code, unless too many were sent recently.
 *
 * Returns 'sent', 'limited' (a recent code is still valid), or throws
 * when the email cannot be sent.
 */
function pboSendLoginCode(PDO $pdo, array $user): string
{
    if (pboResetRequestsInWindow($pdo, (int) $user['id'], 'login') >= PBO_RESET_MAX_REQUESTS) {
        return 'limited';
    }

    $code = pboCreateResetCode(
        $pdo,
        (int) $user['id'],
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'login'
    );

    pboSendLoginCodeEmail($user, $code);

    return 'sent';
}


/**
 * Use up a correct sign-in code so it cannot be used again.
 */
function pboUseLoginCode(PDO $pdo, int $codeId): bool
{
    $statement = $pdo->prepare(
        "UPDATE password_reset_codes
         SET used_at = NOW()
         WHERE id = :id
           AND purpose = 'login'
           AND used_at IS NULL"
    );

    $statement->execute(['id' => $codeId]);

    return $statement->rowCount() === 1;
}


/*
|--------------------------------------------------------------------------
| Trusted browsers
|--------------------------------------------------------------------------
*/

/**
 * Whether this browser is trusted for the user. Also records the use,
 * so regular use keeps the browser trusted (up to PBO_TRUST_MAX_DAYS).
 */
function pboIsTrustedDevice(PDO $pdo, int $userId): bool
{
    $token = $_COOKIE[PBO_TRUST_COOKIE] ?? '';

    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT id
         FROM trusted_devices
         WHERE token_hash = :token_hash
           AND user_id = :user_id
           AND expires_at > NOW()
           AND last_used_at > (NOW() - INTERVAL ' . PBO_TRUST_IDLE_DAYS . ' DAY)
         LIMIT 1'
    );

    $statement->execute([
        'token_hash' => hash('sha256', $token),
        'user_id'    => $userId,
    ]);

    $deviceId = $statement->fetchColumn();

    if ($deviceId === false) {
        return false;
    }

    $pdo->prepare(
        'UPDATE trusted_devices SET last_used_at = NOW() WHERE id = :id'
    )->execute(['id' => $deviceId]);

    return true;
}


/**
 * Trust this browser for the user and set the cookie.
 */
function pboTrustThisDevice(PDO $pdo, int $userId): void
{
    $token = bin2hex(random_bytes(32));

    $pdo->prepare(
        'INSERT INTO trusted_devices
            (user_id, token_hash, user_agent, last_used_at, expires_at)
         VALUES
            (:user_id, :token_hash, :user_agent, NOW(),
             NOW() + INTERVAL ' . PBO_TRUST_MAX_DAYS . ' DAY)'
    )->execute([
        'user_id'    => $userId,
        'token_hash' => hash('sha256', $token),
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);

    setcookie(PBO_TRUST_COOKIE, $token, [
        'expires'  => time() + PBO_TRUST_MAX_DAYS * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Tidy up old rows now and then.
    $pdo->exec(
        'DELETE FROM trusted_devices
         WHERE expires_at < NOW()
            OR last_used_at < (NOW() - INTERVAL ' . PBO_TRUST_IDLE_DAYS . ' DAY)'
    );
}


/**
 * Forget every trusted browser for the user (e.g. after a password reset).
 */
function pboForgetTrustedDevices(PDO $pdo, int $userId): void
{
    $pdo->prepare(
        'DELETE FROM trusted_devices WHERE user_id = :user_id'
    )->execute(['user_id' => $userId]);
}
