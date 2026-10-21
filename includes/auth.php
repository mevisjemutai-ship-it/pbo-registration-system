<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Session
|--------------------------------------------------------------------------
*/

/*
 * Configured in one place - see includes/session.php for why plain
 * session_start() was signing people out on the hosted server.
 */
require_once __DIR__ . '/session.php';


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

/**
 * Require a logged-in user.
 */
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /pbo-portal/login.php');
        exit;
    }
}


/**
 * Require an administrator.
 */
function requireAdmin(): void
{
    requireLogin();

    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Access denied.');
    }
}


/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/

/**
 * Return the currently logged-in user's ID.
 */
function currentUserId(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}


/**
 * Return the currently logged-in user's full name.
 */
function currentUserName(): string
{
    return (string) ($_SESSION['full_name'] ?? '');
}


/**
 * Return the currently logged-in user's role.
 */
function currentUserRole(): string
{
    return (string) ($_SESSION['role'] ?? '');
}


/*
|--------------------------------------------------------------------------
| First-Login Password Change
|--------------------------------------------------------------------------
*/

/**
 * Prevent access to the application until a temporary password
 * has been replaced with a permanent password.
 */
function requirePasswordChanged(): void
{
    requireLogin();

    if (!empty($_SESSION['must_change_password'])) {
        header('Location: /pbo-portal/change-password.php');
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| CSRF Protection
|--------------------------------------------------------------------------
*/

/**
 * Return the current session CSRF token.
 *
 * A token is automatically created when one does not already exist.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(
            random_bytes(32)
        );
    }

    return (string) $_SESSION['csrf_token'];
}


/**
 * Verify a submitted CSRF token.
 */
function verifyCsrfToken(?string $token): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($token)
        || $sessionToken === ''
        || !hash_equals($sessionToken, $token)
    ) {
        http_response_code(419);

        exit(
            'Invalid security token. Please reload the page and try again.'
        );
    }
}


/*
|--------------------------------------------------------------------------
| Administrator Submitted-Record Editing
|--------------------------------------------------------------------------
*/

/**
 * Grant temporary authorization for an administrator
 * to edit ONE specific submitted application.
 *
 * Authorization lasts for 15 minutes.
 */
function grantAdminEditAuthorization(int $applicationId): void
{
    $_SESSION['admin_edit_authorization'] = [
        'user_id'        => currentUserId(),
        'application_id' => $applicationId,
        'verified_at'    => time(),
        'expires_at'     => time() + 900,
    ];
}


/**
 * Check whether the current administrator has valid,
 * temporary authorization to edit the specified application.
 */
function hasValidAdminEditAuthorization(
    PDO $connection,
    int $applicationId
): bool {
    /*
     * Only administrators can edit submitted records.
     */
    if (currentUserRole() !== 'admin') {
        return false;
    }

    /*
     * Retrieve the temporary authorization.
     */
    $authorization =
        $_SESSION['admin_edit_authorization'] ?? null;

    if (!is_array($authorization)) {
        return false;
    }

    /*
     * Authorization must belong to:
     *
     * - the current logged-in user
     * - this exact application
     * - a non-expired authorization
     */
    if (
        (int) ($authorization['user_id'] ?? 0)
            !== currentUserId()
        ||
        (int) ($authorization['application_id'] ?? 0)
            !== $applicationId
        ||
        (int) ($authorization['expires_at'] ?? 0)
            < time()
    ) {
        return false;
    }

    /*
     * Re-check the administrator in the database.
     *
     * This means that if an administrator is disabled or
     * demoted after authentication, the temporary authorization
     * can no longer be used.
     */
    $statement = $connection->prepare(
        'SELECT
            role,
            is_active
         FROM users
         WHERE id = :id
         LIMIT 1'
    );

    $statement->execute([
        'id' => currentUserId(),
    ]);

    $user = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return false;
    }

    return (
        $user['role'] === 'admin'
        && (int) $user['is_active'] === 1
    );
}


/**
 * Remove temporary submitted-record edit authorization.
 */
function clearAdminEditAuthorization(): void
{
    unset(
        $_SESSION['admin_edit_authorization']
    );
}