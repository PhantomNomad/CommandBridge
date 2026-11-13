#!/usr/bin/env bash
# Command Bridge Check-In Gateway installer - fresh installs on Debian 12 / 13.
# Run as root from the unpacked release folder:   sudo ./install.sh
#
# The gateway is the small PUBLIC web application that lets Check-In Recorders and Staging Area
# Managers check resources in. It has its own database and never connects to Command Bridge's.
# This installer installs packages, creates the database, copies the application, writes the
# Apache site (with optional TLS), creates the shared-secret settings file and checks the result.
#
# Unattended use: CB_ASSUME_YES=1 plus any of CB_INSTALL_DIR, CB_SERVER_NAME, CB_TLS_MODE (1-4),
# CB_TIMEZONE, CB_HMAC_KEY_ID, CB_HMAC_SECRET, CB_DELIVERY_MODE (pull|push), CB_EVENTS_URL,
# CB_CERT_FILE, CB_KEY_FILE, CB_LE_EMAIL. CB_SKIP_PACKAGES=1 skips apt. CB_PLAIN=1 forces plain prompts.

set -Eeuo pipefail
umask 022

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG=/var/log/commandbridge-gateway-install.log
CONF_DIR=/etc/commandbridge
ENV_FILE=$CONF_DIR/checkin_gateway.env
INSTALL_CONF=$CONF_DIR/gateway-install.conf
DB_NAME=commandbridge_gateway
DB_USER=commandbridge_gateway
SITE_NAME=commandbridge-gateway
WEB_USER=www-data
ASSUME_YES="${CB_ASSUME_YES:-0}"

# shellcheck disable=SC1091
. "$SRC_DIR/deploy/lib/ui.sh" 2>/dev/null || { echo "deploy/lib/ui.sh is missing - run install.sh from the unpacked gateway folder." >&2; exit 1; }
# shellcheck disable=SC1091
. "$SRC_DIR/deploy/lib/timesync.sh"

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
ui_init "Check-In Gateway installer"
if [ "$UI_WT" = 1 ]; then exec > >(cat >> "$LOG") 2>&1; else exec > >(tee -a "$LOG") 2>&1; fi

[ -f "$SRC_DIR/database/schema.sql" ] && [ -d "$SRC_DIR/app" ] && [ -d "$SRC_DIR/public" ] || die "Run install.sh from the unpacked gateway folder (app/, public/ and database/ must be next to it)."
VERSION="$(tr -d '[:space:]' < "$SRC_DIR/VERSION")"
UI_TITLE="Command Bridge Check-In Gateway $VERSION installer"

if [ "$UI_WT" = 1 ]; then
    ui_msg "Welcome" "This installer sets up the Command Bridge Check-In Gateway $VERSION on this server.\n\nThis is the public-facing web application for check-in recorders. It needs its own web address (ideally a public DNS name) and gets its own database. It is NOT the main Command Bridge server.\n\nA log is written to $LOG."
else
    say "=============================================="
    say " Command Bridge Check-In Gateway $VERSION - installer"
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
ui_ask INSTALL_DIR "Install folder" "Where should the gateway be installed?\n\nOnly its public/ subfolder is served by the web server." "${CB_INSTALL_DIR:-/var/www/commandbridge-gateway}"
case "$INSTALL_DIR" in /*) ;; *) die "Install folder must be an absolute path." ;; esac
INSTALL_DIR="${INSTALL_DIR%/}"
[ -n "$INSTALL_DIR" ] && [ "$INSTALL_DIR" != / ] || die "Refusing to install into /."
if [ -e "$INSTALL_DIR/public" ] || [ -f "$ENV_FILE" ] || [ -f "$INSTALL_CONF" ]; then
    die "An existing gateway install was found. This installer is for fresh installs only; use update.sh to upgrade."
fi
ui_yesno "Confirm install folder" "Install the gateway into:\n\n    $INSTALL_DIR" y || die "Cancelled."
ROOT_DIR="$INSTALL_DIR"
APP_DIR="$INSTALL_DIR/public"   # Apache document root

DEFAULT_NAME="${CB_SERVER_NAME:-$(hostname -f 2>/dev/null || hostname)}"
ui_ask SERVER_NAME "Web address" "Server name - the public DNS name people will reach the gateway at (for example checkin.example.ca):" "$DEFAULT_NAME"
[[ $SERVER_NAME =~ ^[A-Za-z0-9._-]+$ ]] || die "Server name may only contain letters, digits, dots, hyphens and underscores."

ui_menu TLS_MODE "Encryption (HTTPS)" "How should the connection be encrypted?\n\nThe gateway is meant to be reachable from the internet, so Let's Encrypt is normally the right choice. HTTPS is required before synchronisation with Command Bridge is enabled." "${CB_TLS_MODE:-1}" \
    1 "Let's Encrypt (needs public DNS and ports 80/443)" \
    2 "Self-signed certificate (testing only)" \
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
    warn "Plain HTTP selected: synchronisation stays disabled and PINs would cross the network in clear text."
    ui_yesno "No encryption" "PINs would cross the network in clear text and synchronisation will be left disabled.\n\nReally continue without encryption?" n || die "Cancelled."
fi

DETECT_TZ="$(cat /etc/timezone 2>/dev/null || timedatectl show -p Timezone --value 2>/dev/null || echo UTC)"
ui_ask TZ_NAME "Time zone" "Time zone for times shown to check-in recorders (for example America/Edmonton):" "${CB_TIMEZONE:-$DETECT_TZ}"
[ "$TZ_NAME" = UTC ] || [ -e "/usr/share/zoneinfo/$TZ_NAME" ] || [ "${CB_SKIP_PACKAGES:-0}" = 1 ] || die "Unknown time zone '$TZ_NAME' (see: timedatectl list-timezones)."

ui_menu DELIVERY_MODE "How events reach Command Bridge" "How should check-ins get back to Command Bridge?\n\nPull is recommended: your internal Command Bridge server contacts the gateway, so Command Bridge never needs to accept connections from the internet." "${CB_DELIVERY_MODE:-pull}" \
    pull "Command Bridge polls the gateway (recommended)" \
    push "Gateway posts to Command Bridge (legacy)"
[[ $DELIVERY_MODE =~ ^(pull|push)$ ]] || die "Delivery mode must be pull or push."
EVENTS_URL="${CB_EVENTS_URL:-}"
if [ "$DELIVERY_MODE" = push ]; then
    ui_ask EVENTS_URL "Push delivery" "Full https:// URL of Command Bridge's check-in events endpoint:" "${EVENTS_URL:-https://commandbridge.example.ca/api/external_checkin/v1/events.php}"
    [[ $EVENTS_URL =~ ^https:// ]] || die "The events URL must start with https://"
fi

HMAC_KEY_ID="${CB_HMAC_KEY_ID:-commandbridge-1}"
ui_ask HMAC_KEY_ID "Shared secret" "Key ID that identifies the shared secret between Command Bridge and the gateway (the same value goes in Command Bridge's settings):" "$HMAC_KEY_ID"
[[ $HMAC_KEY_ID =~ ^[A-Za-z0-9._:-]{3,64}$ ]] || die "Key ID must be 3-64 characters (letters, digits . _ : -)."
HMAC_SECRET="${CB_HMAC_SECRET:-}"; GENERATED_SECRET=0
if [ -z "$HMAC_SECRET" ]; then
    if ui_yesno "Shared secret" "Generate a strong random shared secret?\n\nChoose No to paste one you already created in Command Bridge (32 or more characters)." y; then
        HMAC_SECRET="$(openssl rand -base64 48 | tr -d '\n')"; GENERATED_SECRET=1
    else
        ui_pass HMAC_SECRET "Paste the shared secret (32+ characters)"
    fi
fi
[ "${#HMAC_SECRET}" -ge 32 ] || die "The shared secret must be at least 32 characters."

SYNC_ENABLED=0
# A pasted secret came from Command Bridge's setup, which is then ready for sync; a generated one still has to be copied there.
SYNC_DEFAULT=n; [ "$GENERATED_SECRET" = 0 ] && SYNC_DEFAULT=y
if [ "$TLS_MODE" != 4 ] && ui_yesno "Synchronisation" "Accept synchronisation from Command Bridge as soon as the gateway is installed?\n\nChoose Yes if Command Bridge already has this key ID and secret (you pasted them from its setup). Choose No to leave it disabled until you have configured Command Bridge." "$SYNC_DEFAULT"; then SYNC_ENABLED=1; fi

TLS_LABEL=("" "Let's Encrypt" "Self-signed certificate" "Your own certificate" "None (plain HTTP)")
SUMMARY="  Install folder : $INSTALL_DIR\n  Web address    : $SERVER_NAME\n  Encryption     : ${TLS_LABEL[$TLS_MODE]}\n  Time zone      : $TZ_NAME\n  Database       : $DB_NAME (local MariaDB)\n  Delivery       : $DELIVERY_MODE\n  Key ID         : $HMAC_KEY_ID\n  Synchronisation: $([ $SYNC_ENABLED = 1 ] && echo enabled || echo disabled)"
ui_yesno "Ready to install" "Please review:\n\n$SUMMARY\n\nProceed with the installation?" y || die "Cancelled."
ui_steps 7

# ---------------------------------------------------------------- packages
ui_stage "Installing packages (this can take a few minutes)"
PACKAGES=(apache2 mariadb-server mariadb-client php php-cli libapache2-mod-php php-mysql php-curl php-mbstring php-xml rsync curl ca-certificates openssl)
[ "$TLS_MODE" = 1 ] && PACKAGES+=(certbot)
TIMESYNC_PKG="$(timesync_packages)"; [ -n "$TIMESYNC_PKG" ] && PACKAGES+=("$TIMESYNC_PKG")
if [ "${CB_SKIP_PACKAGES:-0}" != 1 ]; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y --no-install-recommends "${PACKAGES[@]}"
fi
systemctl enable --now mariadb >/dev/null 2>&1 || true
TIMESYNC_WARN="$(timesync_check)" || warn "$TIMESYNC_WARN"

# ---------------------------------------------------------------- database
ui_stage "Creating the database"
SQL="mariadb"; command -v mariadb >/dev/null || SQL="mysql"
if $SQL -N -e "SHOW DATABASES LIKE '$DB_NAME'" | grep -q .; then die "A database named '$DB_NAME' already exists. Remove it or use update.sh."; fi
DB_PASS="$(rand_hex 20)"
# The gateway's own account can only read and write data; schema changes are made by update.sh as root.
$SQL <<SQLEOF
CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQLEOF
$SQL "$DB_NAME" < "$SRC_DIR/database/schema.sql"
{
    echo "INSERT INTO schema_migrations (version) VALUES ('0000_baseline');"
    for f in "$SRC_DIR"/database/migrations/[0-9]*.sql; do
        if [ -e "$f" ]; then echo "INSERT IGNORE INTO schema_migrations (version) VALUES ('$(basename "$f" .sql)');"; fi
    done
    true
} | $SQL "$DB_NAME"
TABLES="$($SQL -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'")"
[ "$TABLES" -ge 9 ] || die "Database import looks incomplete ($TABLES tables)."
say "Database ready ($TABLES tables)."

# ---------------------------------------------------------------- files
ui_stage "Copying the application"
mkdir -p "$INSTALL_DIR"
rsync -a --exclude '.git' --exclude '.github' --exclude '*.zip' "$SRC_DIR"/ "$INSTALL_DIR"/
if [ -f "$SRC_DIR/../LICENSE.txt" ]; then install -m 0640 "$SRC_DIR/../LICENSE.txt" "$INSTALL_DIR/LICENSE.txt"; fi
mkdir -p "$APP_DIR/.well-known/acme-challenge"
chown -R root:"$WEB_USER" "$INSTALL_DIR"
find "$INSTALL_DIR" -type d -exec chmod 0750 {} +
find "$INSTALL_DIR" -type f -exec chmod 0640 {} +
chmod 0750 "$INSTALL_DIR/install.sh" "$INSTALL_DIR/update.sh" "$INSTALL_DIR"/tools/*.sh 2>/dev/null || true

if [ ! -d "$CONF_DIR" ]; then mkdir -p "$CONF_DIR"; chown root:"$WEB_USER" "$CONF_DIR"; chmod 0750 "$CONF_DIR"; fi
install -m 0640 -o root -g "$WEB_USER" /dev/null "$ENV_FILE"
cat > "$ENV_FILE" <<ENVEOF
# Command Bridge Check-In Gateway settings - written by install.sh. Keep private.
GATEWAY_ENV=production
GATEWAY_SYNC_ENABLED=$SYNC_ENABLED
GATEWAY_TIMEZONE=$TZ_NAME
GATEWAY_DB_DSN="mysql:host=127.0.0.1;dbname=$DB_NAME;charset=utf8mb4"
GATEWAY_DB_USER=$DB_USER
GATEWAY_DB_PASSWORD=$DB_PASS
EXTERNAL_CHECKIN_HMAC_KEY_ID=$HMAC_KEY_ID
EXTERNAL_CHECKIN_HMAC_SECRET=$HMAC_SECRET
GATEWAY_DELIVERY_MODE=$DELIVERY_MODE
GATEWAY_PULL_LEASE_SECONDS=300
COMMANDBRIDGE_EVENTS_URL=$EVENTS_URL
GATEWAY_REQUEST_TTL_SECONDS=300
GATEWAY_SESSION_IDLE_SECONDS=28800
GATEWAY_CONNECT_TIMEOUT_SECONDS=5
GATEWAY_REQUEST_TIMEOUT_SECONDS=20
GATEWAY_MAX_ATTEMPTS=12
ENVEOF
chmod 0640 "$ENV_FILE"; chown root:"$WEB_USER" "$ENV_FILE"
cat > "$INSTALL_CONF" <<CONFEOF
# Written by install.sh; read by update.sh.
INSTALL_DIR=$INSTALL_DIR
DB_NAME=$DB_NAME
SITE_NAME=$SITE_NAME
SERVER_NAME=$SERVER_NAME
TLS_MODE=$TLS_MODE
CONFEOF
chmod 0644 "$INSTALL_CONF"

# ---------------------------------------------------------------- PHP
ui_stage "Applying PHP settings"
PHPVER="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || true)"
if [ -n "$PHPVER" ] && [ -d "/etc/php/$PHPVER" ]; then
    for sapi in apache2 cli; do
        [ -d "/etc/php/$PHPVER/$sapi/conf.d" ] || continue
        cat > "/etc/php/$PHPVER/$sapi/conf.d/90-commandbridge-gateway.ini" <<PHPEOF
; Command Bridge Check-In Gateway
expose_php = Off
date.timezone = $TZ_NAME
session.cookie_httponly = 1
session.use_strict_mode = 1
PHPEOF
    done
fi

# ---------------------------------------------------------------- Apache
ui_stage "Configuring Apache and encryption"
APACHE_SITES=/etc/apache2/sites-available
COMMON="$(sed "s#__APP_DIR__#$APP_DIR#g" "$SRC_DIR/deploy/apache/gateway-common.inc.template")"
LOGLINES='    ErrorLog ${APACHE_LOG_DIR}/commandbridge-gateway-error.log
    CustomLog ${APACHE_LOG_DIR}/commandbridge-gateway-access.log combined'
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
        SSL_DIR=/etc/ssl/commandbridge-gateway; mkdir -p "$SSL_DIR"; chmod 0755 "$SSL_DIR"
        if [[ $SERVER_NAME =~ ^[0-9.]+$ ]]; then SAN="IP:$SERVER_NAME"; else SAN="DNS:$SERVER_NAME"; fi
        openssl req -x509 -nodes -newkey rsa:3072 -days 825 -sha256 -keyout "$SSL_DIR/commandbridge-gateway.key" -out "$SSL_DIR/commandbridge-gateway.crt" \
            -subj "/CN=$SERVER_NAME/O=Command Bridge Gateway" -addext "subjectAltName=$SAN" 2>/dev/null
        chmod 0640 "$SSL_DIR/commandbridge-gateway.key"; chown root:"$WEB_USER" "$SSL_DIR/commandbridge-gateway.key"
        write_redirect_80; append_https "$SSL_DIR/commandbridge-gateway.crt" "$SSL_DIR/commandbridge-gateway.key" 0
        TLS_RESULT="self-signed (valid 825 days: $SSL_DIR/commandbridge-gateway.crt)" ;;
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
            SSL_DIR=/etc/ssl/commandbridge-gateway; mkdir -p "$SSL_DIR"
            openssl req -x509 -nodes -newkey rsa:3072 -days 825 -sha256 -keyout "$SSL_DIR/commandbridge-gateway.key" -out "$SSL_DIR/commandbridge-gateway.crt" \
                -subj "/CN=$SERVER_NAME/O=Command Bridge Gateway" -addext "subjectAltName=DNS:$SERVER_NAME" 2>/dev/null
            chmod 0640 "$SSL_DIR/commandbridge-gateway.key"; chown root:"$WEB_USER" "$SSL_DIR/commandbridge-gateway.key"
            write_redirect_80; append_https "$SSL_DIR/commandbridge-gateway.crt" "$SSL_DIR/commandbridge-gateway.key" 0
            TLS_RESULT="self-signed (Let's Encrypt failed)"
        fi ;;
esac
a2ensite "$SITE_NAME" >/dev/null
apache2ctl configtest
systemctl enable apache2 >/dev/null 2>&1 || true
systemctl restart apache2

# ---------------------------------------------------------------- systemd (push mode only)
if [ -d /run/systemd/system ]; then
    for u in commandbridge-gateway-dispatch.service commandbridge-gateway-dispatch.timer; do
        sed "s#__APP_DIR__#$ROOT_DIR#g" "$SRC_DIR/deploy/systemd/$u" > "/etc/systemd/system/$u"
    done
    systemctl daemon-reload
    if [ "$DELIVERY_MODE" = push ]; then systemctl enable --now commandbridge-gateway-dispatch.timer; fi
fi

# ---------------------------------------------------------------- self-check
ui_stage "Running the self-check"
CHECK_OK=1; CHECK_LINES=()
SCHEME=https; [ "$TLS_MODE" = 4 ] && SCHEME=http
CURL_BASE=(curl -ksS --max-time 15 -H "Host: $SERVER_NAME")
HEALTH="$("${CURL_BASE[@]}" "$SCHEME://127.0.0.1/health.php" 2>&1 || true)"
case "$HEALTH" in *'"ok":true'*) CHECK_LINES+=("ok   health.php reports the database is reachable") ;; *) CHECK_OK=0; CHECK_LINES+=("FAIL health.php: $HEALTH") ;; esac
for p in /app/bootstrap.php /config/gateway.env.example /database/schema.sql /.env /install.sh /VERSION; do
    code="$("${CURL_BASE[@]}" -o /dev/null -w '%{http_code}' "$SCHEME://127.0.0.1$p" 2>/dev/null || echo 000)"
    case "$code" in 403|404) CHECK_LINES+=("ok   $p is not served ($code)") ;; *) CHECK_OK=0; CHECK_LINES+=("FAIL $p answered HTTP $code") ;; esac
done
perm="$(stat -c '%a' "$ENV_FILE")"; case "$perm" in 640|600|440|400) CHECK_LINES+=("ok   settings file permissions $perm") ;; *) CHECK_OK=0; CHECK_LINES+=("FAIL settings file permissions $perm") ;; esac
printf '%s\n' "${CHECK_LINES[@]}"

BASE_URL="$SCHEME://$SERVER_NAME"
FINAL="$(mktemp)"; chmod 600 "$FINAL"
{
    echo "The Check-In Gateway $VERSION is installed."
    echo
    echo "  Address     : $BASE_URL/"
    echo "  Delivery    : $DELIVERY_MODE"
    echo "  Sync        : $([ "$SYNC_ENABLED" = 1 ] && echo enabled || echo "DISABLED - set GATEWAY_SYNC_ENABLED=1 in $ENV_FILE when ready")"
    echo "  Encryption  : $TLS_RESULT"
    echo "  Settings    : $ENV_FILE"
    echo "  Install log : $LOG"
    echo
    echo "NEXT, ON THE COMMAND BRIDGE SERVER:"
    if [ "$GENERATED_SECRET" = 1 ]; then
        echo "  Run:  sudo <Command Bridge folder>/tools/setup_checkin.sh"
        echo "  Choose 'Paste the key from the gateway' and enter:"
        echo "    Gateway address : $BASE_URL"
        echo "    Key ID          : $HMAC_KEY_ID"
        echo "    Shared secret   : $HMAC_SECRET"
        echo "    ^ generated secret, shown once - it is also in $ENV_FILE"
    else
        echo "  Command Bridge already has this key ID and secret. Test the connection and switch sync on:"
        echo "    sudo <Command Bridge folder>/tools/setup_checkin.sh --test"
    fi
    echo "  (Older Command Bridge without tools/setup_checkin.sh: see command-bridge/docs/EXTERNAL_CHECKIN.md.)"
    echo
    echo "Gateway tools (run as root on this server):"
    echo "  New shared secret       : $INSTALL_DIR/tools/rotate_secret.sh   (start it from Command Bridge)"
    echo "  Stuck check-ins         : php $INSTALL_DIR/bin/requeue_checkins.php --list"
    echo "  PIN lockouts            : php $INSTALL_DIR/bin/clear_lockouts.php --list"
    echo
    echo "Self-check:"; printf '  %s\n' "${CHECK_LINES[@]}"
    if [ "${#WARNINGS[@]}" -gt 0 ]; then
        echo; echo "NOTICES:"; for w in "${WARNINGS[@]}"; do echo "  - $w"; done
    fi
} > "$FINAL"
# Shown on the terminal only (fd 3), never written to the log - it may contain the generated secret.
[ "$UI_WT" = 1 ] && ui_textfile "Installation complete" "$FINAL"
{ echo; echo "=============================================="; cat "$FINAL"; echo "=============================================="; } >&3
rm -f "$FINAL"
[ "$CHECK_OK" = 1 ] || warn "Some self-checks failed - see the output above."
exit 0
