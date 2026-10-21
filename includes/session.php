<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Session bootstrap
|--------------------------------------------------------------------------
|
| Every page that needs a session requires THIS file instead of calling
| session_start() directly, so the settings below apply everywhere.
|
| Why this file exists
| --------------------
| The portal used to call session_start() with no configuration, which left
| PHP's defaults in charge:
|
|   - session.gc_maxlifetime defaults to 1440 seconds - 24 minutes idle, and
|     the session is eligible for deletion;
|   - session files go to the server's shared temporary directory, where
|     OTHER sites on the same host sweep them on their own schedule. A site
|     configured with a short lifetime will happily delete our files.
|
| Locally nothing else sweeps that directory, so sessions appeared to last
| forever. Once hosted, staff were signed out mid-application, and the
| administrator sign-in code broke too: the pending login is held in the
| session, so when the session vanished between the password page and the
| code page there was nothing left to check the code against.
|
| Two changes fix both:
|
|   1. The portal keeps its session files in its own directory, which no
|      other site can reach.
|   2. The lifetime is set deliberately, in one place, below.
|
| NOTE: the first deployment of this file signs everybody out once, because
| the old session files are in the old location. That happens only once.
|
*/


/*
|--------------------------------------------------------------------------
| How long an idle session survives
|--------------------------------------------------------------------------
|
| Eight hours covers a full working day, so in normal use nobody is signed
| out while they are working - which was the complaint.
|
| It is NOT unlimited, deliberately. This portal holds the particulars of
| organizations and their officials: names, ID numbers, PINs, addresses.
| A session that never expires means a browser left open on a shared office
| machine stays signed in overnight for whoever sits down next, and the
| audit trail will record their work under the previous person's name.
|
| Change the number here if a different window suits the office better.
| One place, one line.
|
*/
const PBO_SESSION_IDLE_SECONDS = 8 * 60 * 60;


/**
 * Start the session, configured, exactly once.
 */
function pboStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    /*
    | Our own directory for session files, so no other site on the host can
    | read or delete them. Created on first use; kept out of the web root's
    | reach by the .htaccess written alongside it.
    */
    $path = dirname(__DIR__) . '/storage/sessions';

    if (!is_dir($path)) {
        @mkdir($path, 0700, true);
    }

    if (is_dir($path) && is_writable($path)) {
        session_save_path($path);

        /*
        | Deny web access to the directory, for servers that would otherwise
        | serve its contents. Written once; harmless on nginx, which ignores
        | .htaccess - see the deployment note in the README.
        */
        $guard = $path . '/.htaccess';

        if (!is_file($guard)) {
            @file_put_contents(
                $guard,
                "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
            );
        }
    } else {
        /*
        | Could not use our own directory - fall back to the server default
        | rather than refusing to start. Sessions still work; they are just
        | back in the shared directory, so record why in the server log.
        */
        error_log(
            'PBO session: ' . $path . ' is not writable, '
            . 'falling back to the shared session directory. '
            . 'Sessions may be cleared early by other sites on this server.'
        );
    }

    ini_set('session.gc_maxlifetime', (string) PBO_SESSION_IDLE_SECONDS);

    /*
    | Refuse a session id the browser invented rather than adopting it:
    | without this, an attacker can fix a known id on a victim's browser
    | and inherit the session once they sign in.
    */
    ini_set('session.use_strict_mode', '1');

    session_set_cookie_params([
        /*
        | 0 = the cookie dies when the browser closes. Closing the browser
        | is a deliberate act and signing out then is expected; it is the
        | silent sign-out WHILE working that this file exists to stop.
        */
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => pboRequestIsHttps(),
    ]);

    session_start();
}


/**
 * Whether this request arrived over HTTPS, including behind a proxy.
 *
 * Marking the cookie secure on a plain-HTTP request would stop the browser
 * sending it back at all, so this has to be right rather than optimistic.
 */
function pboRequestIsHttps(): bool
{
    if (
        !empty($_SERVER['HTTPS'])
        && strtolower((string) $_SERVER['HTTPS']) !== 'off'
    ) {
        return true;
    }

    if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    /*
    | Behind a reverse proxy or load balancer the connection to PHP is
    | plain HTTP even when the visitor is on HTTPS.
    */
    $forwarded = strtolower(
        (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')
    );

    return $forwarded === 'https';
}


pboStartSession();
