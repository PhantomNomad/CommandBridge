#!/usr/bin/env bash
# Command Bridge installer - fresh installs on Debian 12 / 13 (or a close derivative).
# Run as root from the unpacked release folder:   sudo ./install.sh
#
# It installs the required packages, creates the database, copies the application,
# writes the Apache site, optionally sets up TLS, creates your first administrator
# and finishes with a self-check (smoke test). It refuses to touch an existing install.
#
# Unattended use: set CB_ASSUME_YES=1 and any of CB_INSTALL_DIR, CB_SERVER_NAME, CB_TLS_MODE
# (1-4), CB_TIMEZONE, CB_ADMIN_USER, CB_ADMIN_NAME, CB_ADMIN_EMAIL, CB_ADMIN_PASSWORD,
# CB_CERT_FILE, CB_KEY_FILE, CB_LE_EMAIL. CB_SKIP_PACKAGES=1 skips apt (testing/offline).
# On a terminal the installer uses whiptail dialogs (installed automatically); CB_PLAIN=1 forces plain prompts.

set -Eeuo pipefail
umask 022

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG=/var/log/commandbridge-install.log
CONF_DIR=/etc/commandbridge
DB_NAME=commandbridge
DB_USER=commandbridge
SITE_NAME=commandbridge
WEB_USER=www-data
ASSUME_YES="${CB_ASSUME_YES:-0}"

# shellcheck disable=SC1091
. "$SRC_DIR/deploy/lib/ui.sh" 2>/dev/null || { echo "deploy/lib/ui.sh is missing - run install.sh from the unpacked Command Bridge folder." >&2; exit 1; }

WARNINGS=()
say()  { printf '%s\n' "$*"; }
warn() { WARNINGS+=("$*"); printf 'WARNING: %s\n' "$*" >&2; }
die()  {
    printf '\nERROR: %s\n' "$*" >&2
    if [ "$UI_WT" = 1 ]; then
        ui_msg "Installation stopped" "$*\n\nDetails are in $LOG"
        printf '\nERROR: %s\nDetails are in %s\n' "$*" "$LOG" >&3
    else
        printf 'Details are in %s\n' "$LOG" >&2
    fi
    exit 1
}
trap 'die "Installer stopped unexpectedly at line $LINENO."' ERR
rand_hex() { openssl rand -hex "$1"; }

# ---------------------------------------------------------------- preflight
[ "$(id -u)" -eq 0 ] || { echo "Run this installer as root (sudo ./install.sh)." >&2; exit 1; }
mkdir -p "$(dirname "$LOG")"; touch "$LOG"; chmod 600 "$LOG"
exec 3>&1
ui_init "Command Bridge installer"
if [ "$UI_WT" = 1 ]; then exec > >(cat >> "$LOG") 2>&1; else exec > >(tee -a "$LOG") 2>&1; fi

[ -f "$SRC_DIR/database/schema.sql" ] && [ -d "$SRC_DIR/app" ] || die "Run install.sh from the unpacked Command Bridge folder (app/ and database/ must be next to it)."
VERSION="$(tr -d '[:space:]' < "$SRC_DIR/VERSION")"
UI_TITLE="Command Bridge $VERSION installer"

if [ "$UI_WT" = 1 ]; then
    ui_msg "Welcome" "This installer sets up Command Bridge $VERSION on this server.\n\nIt will install the required packages, create the database, configure Apache and encryption, and create your first administrator. You can cancel at any question.\n\nA log is written to $LOG."
else
    say "=============================================="
    say " Command Bridge $VERSION - installer"
    say "=============================================="
    say "Log file: $LOG"
fi

OS_KEY="$( [ -r /etc/os-release ] && . /etc/os-release && echo "${ID:-}:${VERSION_ID:-}" || true )"
OS_PRETTY="$( [ -r /etc/os-release ] && . /etc/os-release && echo "${PRETTY_NAME:-unknown}" || true )"
case "$OS_KEY" in
    debian:12|debian:13) ;;
    *) warn "This installer is written for Debian 12 or 13; this system is '$OS_PRETTY'."
       [ "${CB_FORCE_OS:-0}" = 1 ] || ui_yesno "Unsupported system" "This installer is written for Debian 12 or 13, but this system is '$OS_PRETTY'.\n\nContinue anyway?" n || exit 1 ;;
esac
command -v apt-get >/dev/null || die "apt-get not found."

# ---------------------------------------------------------------- questions
ui_ask INSTALL_DIR "Install folder" "Where should Command Bridge be installed?\n\nOnly its app/ subfolder is served by the web server." "${CB_INSTALL_DIR:-/var/www/commandbridge}"
case "$INSTALL_DIR" in /*) ;; *) die "Install folder must be an absolute path." ;; esac
INSTALL_DIR="${INSTALL_DIR%/}"
[ -n "$INSTALL_DIR" ] && [ "$INSTALL_DIR" != / ] || die "Refusing to install into /."
if [ -e "$INSTALL_DIR/app" ] || [ -f "$CONF_DIR/db.env" ] || [ -f "$CONF_DIR/install.conf" ]; then
    die "An existing Command Bridge install was found. This installer is for fresh installs only; use update.sh to upgrade."
fi
ui_yesno "Confirm install folder" "Install Command Bridge into:\n\n    $INSTALL_DIR" y || die "Cancelled."
APP_DIR="$INSTALL_DIR/app"

DEFAULT_NAME="${CB_SERVER_NAME:-$(hostname -f 2>/dev/null || hostname)}"
ui_ask SERVER_NAME "Web address" "Server name - the DNS name or IP address people will type to reach Command Bridge:" "$DEFAULT_NAME"
[[ $SERVER_NAME =~ ^[A-Za-z0-9._-]+$ ]] || die "Server name may only contain letters, digits, dots, hyphens and underscores."

ui_menu TLS_MODE "Encryption (HTTPS)" "How should the connection be encrypted?\n\nLet's Encrypt only works if this server is reachable from the internet on ports 80/443 under a public DNS name. Most internal servers should use a self-signed certificate or one from their own CA." "${CB_TLS_MODE:-2}" \
    1 "Let's Encrypt (public servers only)" \
    2 "Self-signed certificate (works anywhere)" \
    3 "My own certificate and key files" \
    4 "No encryption - plain HTTP (testing only)"
[[ $TLS_MODE =~ ^[1-4]$ ]] || die "Choose 1, 2, 3 or 4."
LE_EMAIL="${CB_LE_EMAIL:-}"; CERT_FILE="${CB_CERT_FILE:-}"; KEY_FILE="${CB_KEY_FILE:-}"
if [ "$TLS_MODE" = 1 ]; then
    [[ $SERVER_NAME =~ ^[0-9.]+$ ]] && die "Let's Encrypt cannot issue certificates for an IP address."
    ui_ask LE_EMAIL "Let's Encrypt" "Email address for certificate expiry notices:" "$LE_EMAIL"
    [ -n "$LE_EMAIL" ] || die "An email address is required for Let's Encrypt."
elif [ "$TLS_MODE" = 3 ]; then
    ui_ask CERT_FILE "Your certificate" "Path to the certificate file (full chain, PEM):" "$CERT_FILE"
    ui_ask KEY_FILE "Your certificate" "Path to the private key file (PEM):" "$KEY_FILE"
    [ -r "$CERT_FILE" ] && [ -r "$KEY_FILE" ] || die "Certificate or key file not found/readable."
    [ "$(openssl x509 -noout -pubkey -in "$CERT_FILE" 2>/dev/null | openssl md5)" = "$(openssl pkey -pubout -in "$KEY_FILE" 2>/dev/null | openssl md5)" ] \
        || die "The key does not match the certificate."
elif [ "$TLS_MODE" = 4 ]; then
    warn "Plain HTTP selected. Do not use this for real incident data."
    ui_yesno "No encryption" "Passwords and incident data will cross the network in clear text.\n\nReally continue without encryption?" n || die "Cancelled."
fi

DETECT_TZ="$(cat /etc/timezone 2>/dev/null || timedatectl show -p Timezone --value 2>/dev/null || echo UTC)"
ui_ask TZ_NAME "Time zone" "Time zone for incident times (for example America/Edmonton):" "${CB_TIMEZONE:-$DETECT_TZ}"
[ "$TZ_NAME" = UTC ] || [ -e "/usr/share/zoneinfo/$TZ_NAME" ] || [ "${CB_SKIP_PACKAGES:-0}" = 1 ] || die "Unknown time zone '$TZ_NAME' (see: timedatectl list-timezones)."

ui_ask ADMIN_USER "Administrator" "Username for the first administrator (Technical Services Coordinator):" "${CB_ADMIN_USER:-admin}"
[[ $ADMIN_USER =~ ^[A-Za-z0-9._-]{3,64}$ ]] || die "Username must be 3-64 characters (letters, digits . _ -)."
ui_ask ADMIN_NAME "Administrator" "Full name of the administrator:" "${CB_ADMIN_NAME:-Administrator}"
ui_ask ADMIN_EMAIL "Administrator" "Email address (optional - leave blank to skip):" "${CB_ADMIN_EMAIL:-}"
ADMIN_PASSWORD="${CB_ADMIN_PASSWORD:-}"; GENERATED_PW=0
if [ -z "$ADMIN_PASSWORD" ]; then
    if ui_yesno "Administrator password" "Generate a strong random password for '$ADMIN_USER'?\n\nChoose No to type your own (12 or more characters)." y; then
        ADMIN_PASSWORD="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)"; GENERATED_PW=1
    else
        while :; do
            ui_pass ADMIN_PASSWORD "Password (12+ characters)"
            ui_pass pw2 "Repeat the password"
            [ "$ADMIN_PASSWORD" = "$pw2" ] && [ "${#ADMIN_PASSWORD}" -ge 12 ] && break
            ui_msg "Password problem" "The passwords differ or are shorter than 12 characters. Please try again."
        done
    fi
fi
[ "${#ADMIN_PASSWORD}" -ge 12 ] || die "Administrator password must be at least 12 characters."

TLS_LABEL=("" "Let's Encrypt" "Self-signed certificate" "Your own certificate" "None (plain HTTP)")
SUMMARY="  Install folder : $INSTALL_DIR\n  Web address    : $SERVER_NAME\n  Encryption     : ${TLS_LABEL[$TLS_MODE]}\n  Time zone      : $TZ_NAME\n  Database       : $DB_NAME (local MariaDB, user $DB_USER)\n  Administrator  : $ADMIN_USER"
ui_yesno "Ready to install" "Please review:\n\n$SUMMARY\n\nProceed with the installation?" y || die "Cancelled."
ui_steps 7

# ---------------------------------------------------------------- packages
ui_stage "Installing packages (this can take a few minutes)"
PACKAGES=(apache2 mariadb-server mariadb-client php php-cli libapache2-mod-php php-mysql php-curl php-mbstring
          php-xml php-zip php-gd php-intl php-tcpdf pdftk-java poppler-utils ghostscript rsync ca-certificates openssl)
[ "$TLS_MODE" = 1 ] && PACKAGES+=(certbot)
if [ "${CB_SKIP_PACKAGES:-0}" != 1 ]; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y --no-install-recommends "${PACKAGES[@]}"
fi
command -v pdftk >/dev/null || warn "pdftk was not found; official PDF export will not work."
systemctl enable --now mariadb >/dev/null 2>&1 || true

# ---------------------------------------------------------------- database
ui_stage "Creating the database"
SQL="mariadb"; command -v mariadb >/dev/null || SQL="mysql"
if $SQL -N -e "SHOW DATABASES LIKE '$DB_NAME'" | grep -q .; then die "A database named '$DB_NAME' already exists. Remove it or use update.sh."; fi
DB_PASS="$(rand_hex 20)"
$SQL <<SQLEOF
CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, LOCK TABLES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, LOCK TABLES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQLEOF
$SQL "$DB_NAME" < "$SRC_DIR/database/schema.sql"
$SQL "$DB_NAME" < "$SRC_DIR/database/seed.sql"
{
    echo "INSERT INTO schema_migrations (version) VALUES ('0000_baseline');"
    for f in "$SRC_DIR"/database/migrations/[0-9]*.sql; do
        if [ -e "$f" ]; then echo "INSERT IGNORE INTO schema_migrations (version) VALUES ('$(basename "$f" .sql)');"; fi
    done
    true
} | $SQL "$DB_NAME"
TABLES="$($SQL -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'")"
[ "$TABLES" -gt 50 ] || die "Database import looks incomplete ($TABLES tables)."
say "Database ready ($TABLES tables)."

# ---------------------------------------------------------------- files
ui_stage "Copying the application"
mkdir -p "$INSTALL_DIR"
rsync -a --exclude '.git' --exclude '.github' --exclude 'db.env' --exclude '*.zip' "$SRC_DIR"/ "$INSTALL_DIR"/
if [ -f "$SRC_DIR/../LICENSE.txt" ]; then install -m 0640 "$SRC_DIR/../LICENSE.txt" "$INSTALL_DIR/LICENSE.txt"; fi
rm -f "$APP_DIR/db.env"
mkdir -p "$APP_DIR/storage/uploads" "$APP_DIR/storage/branding" "$APP_DIR/.well-known/acme-challenge"
chown -R root:"$WEB_USER" "$INSTALL_DIR"
find "$INSTALL_DIR" -type d -exec chmod 0750 {} +
find "$INSTALL_DIR" -type f -exec chmod 0640 {} +
chmod 0750 "$INSTALL_DIR/install.sh" "$INSTALL_DIR/update.sh" 2>/dev/null || true
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR/storage"
chown "$WEB_USER":"$WEB_USER" "$APP_DIR/.well-known" "$APP_DIR/.well-known/acme-challenge"

mkdir -p "$CONF_DIR"; chown root:"$WEB_USER" "$CONF_DIR"; chmod 0750 "$CONF_DIR"
install -m 0640 -o root -g "$WEB_USER" /dev/null "$CONF_DIR/db.env"
cat > "$CONF_DIR/db.env" <<ENVEOF
# Command Bridge database settings - written by install.sh. Keep private.
DB_HOST=127.0.0.1
DB_NAME=$DB_NAME
DB_APP_USER=$DB_USER
DB_APP_PASSWORD=$DB_PASS
APP_TIMEZONE=$TZ_NAME
ENVEOF
cat > "$CONF_DIR/install.conf" <<CONFEOF
# Written by install.sh; read by update.sh.
INSTALL_DIR=$INSTALL_DIR
DB_NAME=$DB_NAME
SITE_NAME=$SITE_NAME
SERVER_NAME=$SERVER_NAME
TLS_MODE=$TLS_MODE
CONFEOF
chmod 0644 "$CONF_DIR/install.conf"
if [ ! -f "$CONF_DIR/external_checkin_sync.env" ]; then
    install -m 0640 -o root -g "$WEB_USER" "$SRC_DIR/deploy/config/external_checkin_sync.env.example" "$CONF_DIR/external_checkin_sync.env"
fi

# ---------------------------------------------------------------- PHP
ui_stage "Applying PHP settings"
PHPVER="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || true)"
if [ -n "$PHPVER" ] && [ -d "/etc/php/$PHPVER" ]; then
    for sapi in apache2 cli; do
        [ -d "/etc/php/$PHPVER/$sapi/conf.d" ] || continue
        cat > "/etc/php/$PHPVER/$sapi/conf.d/90-commandbridge.ini" <<PHPEOF
; Command Bridge
expose_php = Off
date.timezone = $TZ_NAME
upload_max_filesize = 20M
post_max_size = 25M
max_input_vars = 5000
session.cookie_httponly = 1
session.use_strict_mode = 1
PHPEOF
    done
fi

# ---------------------------------------------------------------- Apache
ui_stage "Configuring Apache and encryption"
APACHE_SITES=/etc/apache2/sites-available
COMMON="$(sed "s#__APP_DIR__#$APP_DIR#g" "$SRC_DIR/deploy/apache/commandbridge-common.inc.template")"
LOGLINES='    ErrorLog ${APACHE_LOG_DIR}/commandbridge-error.log
    CustomLog ${APACHE_LOG_DIR}/commandbridge-access.log combined'
printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/commandbridge-hardening.conf

write_http_only() {
    cat > "$APACHE_SITES/$SITE_NAME.conf" <<VHEOF
# Command Bridge - generated by install.sh
<VirtualHost *:80>
    ServerName $SERVER_NAME
    DocumentRoot $APP_DIR
$LOGLINES
$COMMON
</VirtualHost>
VHEOF
}
write_redirect_80() {  # redirect all HTTP to HTTPS, keeping the ACME path reachable
    cat > "$APACHE_SITES/$SITE_NAME.conf" <<VHEOF
# Command Bridge - generated by install.sh
<VirtualHost *:80>
    ServerName $SERVER_NAME
    DocumentRoot $APP_DIR
    Alias /.well-known/acme-challenge/ $APP_DIR/.well-known/acme-challenge/
    <Directory $APP_DIR/.well-known/acme-challenge>
        Require all granted
    </Directory>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^/?(.*)\$ https://%{HTTP_HOST}/\$1 [R=301,L]
$LOGLINES
</VirtualHost>
VHEOF
}
append_https() {  # cert key hsts(0/1)
    local hsts=""
    [ "$3" = 1 ] && hsts='    Header always set Strict-Transport-Security "max-age=15552000"'
    cat >> "$APACHE_SITES/$SITE_NAME.conf" <<VHEOF

<VirtualHost *:443>
    ServerName $SERVER_NAME
    DocumentRoot $APP_DIR
    SSLEngine on
    SSLCertificateFile $1
    SSLCertificateKeyFile $2
    SSLProtocol -all +TLSv1.2 +TLSv1.3
$hsts
$LOGLINES
$COMMON
</VirtualHost>
VHEOF
}

a2enmod rewrite headers ssl >/dev/null
a2enconf commandbridge-hardening >/dev/null
for s in 000-default default-ssl; do
    [ -e "/etc/apache2/sites-enabled/$s.conf" ] && a2dissite "$s" >/dev/null && say "Disabled Apache's default site '$s'."
done
OTHERS="$(cd /etc/apache2/sites-enabled && ls 2>/dev/null | grep -v "^$SITE_NAME.conf$" || true)"
[ -n "$OTHERS" ] && warn "Other Apache sites are still enabled and were left alone: $(echo $OTHERS)"

TLS_RESULT="none"
case "$TLS_MODE" in
    4) write_http_only; TLS_RESULT="HTTP only" ;;
    3) write_redirect_80; append_https "$CERT_FILE" "$KEY_FILE" 0; TLS_RESULT="your certificate" ;;
    2)
        SSL_DIR=/etc/ssl/commandbridge; mkdir -p "$SSL_DIR"; chmod 0755 "$SSL_DIR"
        if [[ $SERVER_NAME =~ ^[0-9.]+$ ]]; then SAN="IP:$SERVER_NAME"; else SAN="DNS:$SERVER_NAME"; fi
        openssl req -x509 -nodes -newkey rsa:3072 -days 825 -sha256 -keyout "$SSL_DIR/commandbridge.key" -out "$SSL_DIR/commandbridge.crt" \
            -subj "/CN=$SERVER_NAME/O=Command Bridge" -addext "subjectAltName=$SAN" 2>/dev/null
        chmod 0640 "$SSL_DIR/commandbridge.key"; chown root:"$WEB_USER" "$SSL_DIR/commandbridge.key"
        write_redirect_80; append_https "$SSL_DIR/commandbridge.crt" "$SSL_DIR/commandbridge.key" 0
        TLS_RESULT="self-signed (valid 825 days: $SSL_DIR/commandbridge.crt)" ;;
    1)
        write_http_only
        a2ensite "$SITE_NAME" >/dev/null
        apache2ctl configtest && systemctl reload apache2
        if certbot certonly --webroot -w "$APP_DIR" -d "$SERVER_NAME" --non-interactive --agree-tos -m "$LE_EMAIL" \
               --deploy-hook "systemctl reload apache2"; then
            write_redirect_80
            append_https "/etc/letsencrypt/live/$SERVER_NAME/fullchain.pem" "/etc/letsencrypt/live/$SERVER_NAME/privkey.pem" 1
            TLS_RESULT="Let's Encrypt (renews automatically via certbot's timer)"
        else
            warn "Let's Encrypt could not issue a certificate (is $SERVER_NAME public and reachable on port 80?)."
            warn "Falling back to a self-signed certificate. Re-run 'certbot certonly --webroot -w $APP_DIR -d $SERVER_NAME' later."
            SSL_DIR=/etc/ssl/commandbridge; mkdir -p "$SSL_DIR"
            openssl req -x509 -nodes -newkey rsa:3072 -days 825 -sha256 -keyout "$SSL_DIR/commandbridge.key" -out "$SSL_DIR/commandbridge.crt" \
                -subj "/CN=$SERVER_NAME/O=Command Bridge" -addext "subjectAltName=DNS:$SERVER_NAME" 2>/dev/null
            chmod 0640 "$SSL_DIR/commandbridge.key"; chown root:"$WEB_USER" "$SSL_DIR/commandbridge.key"
            write_redirect_80; append_https "$SSL_DIR/commandbridge.crt" "$SSL_DIR/commandbridge.key" 0
            TLS_RESULT="self-signed (Let's Encrypt failed)"
        fi ;;
esac
a2ensite "$SITE_NAME" >/dev/null
apache2ctl configtest
systemctl enable apache2 >/dev/null 2>&1 || true
systemctl restart apache2

# ---------------------------------------------------------------- systemd (check-in sync, left disabled)
if [ -d /run/systemd/system ]; then
    for u in commandbridge-external-checkin-sync.service commandbridge-external-checkin-sync.timer; do
        sed "s#__APP_DIR__#$APP_DIR#g" "$SRC_DIR/deploy/systemd/$u" > "/etc/systemd/system/$u"
    done
    systemctl daemon-reload
    say "Installed (but did not enable) the optional external check-in sync timer; see docs/EXTERNAL_CHECKIN.md."
fi

# ---------------------------------------------------------------- administrator
ui_stage "Creating the administrator"
printf '%s' "$ADMIN_PASSWORD" | runuser -u "$WEB_USER" -- php "$APP_DIR/bin/create_admin.php" \
    --username="$ADMIN_USER" --name="$ADMIN_NAME" ${ADMIN_EMAIL:+--email="$ADMIN_EMAIL"} --password-stdin

# ---------------------------------------------------------------- self-check
ui_stage "Running the self-check"
SMOKE_OK=1
SMOKE_ARGS=(); [ "$TLS_MODE" = 4 ] && SMOKE_ARGS=(--base-url=http://127.0.0.1) || SMOKE_ARGS=(--base-url=https://127.0.0.1 --insecure)
SMOKE_OUT="$(mktemp)"
runuser -u "$WEB_USER" -- php "$APP_DIR/bin/smoke_test.php" "${SMOKE_ARGS[@]}" > "$SMOKE_OUT" 2>&1 || SMOKE_OK=0
cat "$SMOKE_OUT"

SCHEME=https; [ "$TLS_MODE" = 4 ] && SCHEME=http
FINAL="$(mktemp)"; chmod 600 "$FINAL"
{
    echo "Command Bridge $VERSION is installed."
    echo
    echo "  Open        : $SCHEME://$SERVER_NAME/"
    echo "  Username    : $ADMIN_USER"
    if [ "$GENERATED_PW" = 1 ]; then
        echo "  Password    : $ADMIN_PASSWORD"
        echo "                ^ shown once - store it now (it is not saved in the log)"
    else
        echo "  Password    : (the one you typed)"
    fi
    echo "  Encryption  : $TLS_RESULT"
    echo "  App files   : $INSTALL_DIR"
    echo "  Settings    : $CONF_DIR/db.env"
    echo "  Install log : $LOG"
    echo
    echo "After signing in you will be taken through creating your first incident."
    [ "$TLS_MODE" = 2 ] && echo "Browsers will warn about the self-signed certificate until you trust /etc/ssl/commandbridge/commandbridge.crt."
    if [ "$SMOKE_OK" != 1 ]; then
        echo; echo "SELF-CHECK PROBLEMS (see docs/SMOKE_TEST.md):"; grep -E 'FAIL|Failures|^ *- ' "$SMOKE_OUT" | head -15
    fi
    if [ "${#WARNINGS[@]}" -gt 0 ]; then
        echo; echo "NOTICES:"; for w in "${WARNINGS[@]}"; do echo "  - $w"; done
    fi
} > "$FINAL"
# Shown on the terminal only (fd 3), never written to the log - it may contain the generated password.
[ "$UI_WT" = 1 ] && ui_textfile "Installation complete" "$FINAL"
{ echo; echo "=============================================="; cat "$FINAL"; echo "=============================================="; } >&3
rm -f "$FINAL" "$SMOKE_OUT"
exit 0
