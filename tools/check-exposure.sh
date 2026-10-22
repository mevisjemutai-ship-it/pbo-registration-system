#!/bin/bash
# ---------------------------------------------------------------------------
# check-exposure.sh - what can be downloaded from the portal without logging in
# ---------------------------------------------------------------------------
#
#   bash tools/check-exposure.sh
#   bash tools/check-exposure.sh http://192.168.1.50/pbo-portal
#
# The portal is served out of its own folder, so every file in that folder
# is reachable over HTTP unless the web server is told otherwise. The
# .htaccess in the portal root does the telling - but Apache only reads it
# when the site is configured with "AllowOverride All" (or at least
# FileInfo Limit Indexes). If it is set to None, that file is ignored
# SILENTLY and everything is still exposed.
#
# This asks the running portal directly, which is the only way to know.
#
# Reports only. Changes nothing.
# ---------------------------------------------------------------------------

set -u

BASE="${1:-http://localhost/pbo-portal}"
BASE="${BASE%/}"

printf 'Checking what %s hands out\n' "$BASE"
printf -- '%.0s-' {1..66}; echo
echo

if ! command -v curl >/dev/null 2>&1; then
    echo "curl is not installed. Install it with: sudo apt install curl"
    exit 2
fi

problems=0
checked=0

# A path that must NOT be downloadable, and why it matters.
blocked() {
    local path="$1" why="$2"
    local code

    checked=$((checked + 1))

    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE/$path")

    case "$code" in
        200)
            problems=$((problems + 1))
            printf '  EXPOSED  %-52s HTTP %s\n' "$path" "$code"
            printf '           %s\n\n' "$why"
            ;;
        000)
            printf '  ?        %-52s no reply\n' "$path"
            ;;
        *)
            printf '  blocked  %-52s HTTP %s\n' "$path" "$code"
            ;;
    esac
}

# A path that MUST still work, so we know the rules did not go too far.
works() {
    local path="$1"
    local code

    checked=$((checked + 1))

    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE/$path")

    # 200 or a redirect to the sign-in page are both fine; 403 or 404 are not.
    case "$code" in
        200|301|302)
            printf '  ok       %-52s HTTP %s\n' "$path" "$code"
            ;;
        000)
            printf '  ?        %-52s no reply - is the portal running?\n' "$path"
            ;;
        *)
            problems=$((problems + 1))
            printf '  BROKEN   %-52s HTTP %s\n' "$path" "$code"
            printf '           This should be reachable. The rules are too broad.\n\n'
            ;;
    esac
}


echo "Must NOT be downloadable"
echo

blocked "pbo_portal_before_commit.sql" \
    "A full database dump: the users table with password hashes, and real applicants' names, ID numbers and KRA PINs."
blocked "schema.sql" \
    "The database structure, which tells an attacker exactly what to go after."
blocked "database/schema.sql" \
    "The same."
blocked "database/pbo_portal_before_operating_locations_20261006_120039.sql" \
    "Another full dump with the same contents."
blocked "config/database.php" \
    "The database password."
blocked "config/mail.php" \
    "The mail account's password."
blocked ".git/config" \
    "The whole source and its history, including every dump ever committed."
blocked ".git/HEAD" \
    "The same."
blocked ".gitignore" \
    "Names the files worth asking for."
blocked "tools/check-hosting.php" \
    "A diagnostic that reports the server's configuration."
blocked "tests/_render-page.php" \
    "A test harness that signs itself in as an administrator."
blocked "storage/sessions/" \
    "Live session files; reading one is reading somebody's sign-in."
blocked "database/" \
    "Directory listing of the dumps."

echo
echo "Must still work"
echo

works "login.php"
works "index.php"
works "assets/css/style.css"

echo
printf -- '%.0s-' {1..66}; echo
echo

if [ "$problems" -eq 0 ]; then
    cat <<'DONE'
Nothing exposed, and the portal still serves. That means Apache IS
reading the .htaccess in the portal root.
DONE
    exit 0
fi

cat <<'FIX'
Found files that should not be downloadable.

If EVERYTHING above is exposed, Apache is ignoring the portal's .htaccess
file. That happens when the site is configured with "AllowOverride None",
which is the default on a fresh Apache. Fix it in the site's
configuration - usually /etc/apache2/sites-available/000-default.conf -
by adding, inside the <VirtualHost> block:

    <Directory /var/www/html/pbo-portal>
        AllowOverride All
    </Directory>

then:

    sudo apache2ctl configtest
    sudo systemctl reload apache2

and run this again.

Separately, and regardless of the above: a database dump has no business
living inside the folder the web server serves. Move them somewhere else
entirely, for example:

    sudo mkdir -p /var/backups/pbo-portal
    sudo mv /var/www/html/pbo-portal/*.sql /var/backups/pbo-portal/
    sudo mv /var/www/html/pbo-portal/database/pbo_portal_before_*.sql \
            /var/backups/pbo-portal/

Keep the migrations (database/add_*.sql, database/schema.sql) where they
are - those are instructions, not data.
FIX

exit 1
