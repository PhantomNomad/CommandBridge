#!/usr/bin/env bash
# Command Bridge Check-In Gateway updater. Run as root from the folder of the NEW release:   sudo ./update.sh
# Backs up the database, replaces the application code (never touching storage/ or db.env),
# applies pending database migrations and runs the self-check. See docs/UPDATING.md.
set -Eeuo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF=/etc/commandbridge/gateway-install.conf
WEB_USER=www-data
LOG=/var/log/commandbridge-gateway-update.log
# shellcheck disable=SC1091
. "$SRC_DIR/deploy/lib/ui.sh" 2>/dev/null || { echo "deploy/lib/ui.sh missing - run update.sh from the unpacked new release." >&2; exit 1; }
die() {
    printf '\nERROR: %s\n' "$*" >&2
    [ "$UI_WT" = 1 ] && { ui_msg "Update stopped" "$*\n\nDetails: $LOG"; printf '\nERROR: %s\nDetails: %s\n' "$*" "$LOG" >&3; }
    exit 1
}
trap 'die "Update stopped unexpectedly at line $LINENO. Your database backup (if made) is in /var/backups/commandbridge-gateway."' ERR

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo ./update.sh)." >&2; exit 1; }
touch "$LOG"; chmod 600 "$LOG"
exec 3>&1
ui_init "Check-In Gateway updater"
if [ "$UI_WT" = 1 ]; then exec > >(cat >> "$LOG") 2>&1; else exec > >(tee -a "$LOG") 2>&1; fi
[ -f "$CONF" ] || die "No gateway install found ($CONF missing). Use install.sh for a fresh install."
# shellcheck disable=SC1090
. "$CONF"
[ -d "$INSTALL_DIR/public" ] || die "Install folder $INSTALL_DIR/public not found."
[ -f "$SRC_DIR/VERSION" ] && [ -d "$SRC_DIR/public" ] || die "Run update.sh from the unpacked new release folder."

OLD_VER="$(tr -d '[:space:]' < "$INSTALL_DIR/VERSION" 2>/dev/null || echo unknown)"
NEW_VER="$(tr -d '[:space:]' < "$SRC_DIR/VERSION")"
APP_DIR="$INSTALL_DIR"
SQL=mariadb; command -v mariadb >/dev/null || SQL=mysql
[ "$SRC_DIR" != "$INSTALL_DIR" ] || die "Unpack the new release into a separate folder and run its update.sh."
UI_TITLE="Check-In Gateway updater"
ui_yesno "Update Check-In Gateway" "Update the Check-In Gateway in:\n    $INSTALL_DIR\n\nfrom version $OLD_VER to $NEW_VER?\n\nThe database is backed up first. Your settings in /etc/commandbridge are kept." y || { echo "Cancelled." >&3; exit 1; }
ui_steps 4

# 1. Back up the database
ui_stage "Backing up the database"
BK=/var/backups/commandbridge-gateway; mkdir -p "$BK"; chmod 0700 "$BK"
BKFILE="$BK/${DB_NAME}-$(date +%Y%m%d-%H%M%S)-before-${NEW_VER}.sql.gz"
mariadb-dump --single-transaction --routines "$DB_NAME" 2>/dev/null | gzip > "$BKFILE" || \
    mysqldump --single-transaction --routines "$DB_NAME" | gzip > "$BKFILE"
[ -s "$BKFILE" ] || die "Database backup failed."
chmod 0600 "$BKFILE"; echo "Database backup: $BKFILE"

ui_stage "Replacing application files"
# 3. Code (settings in /etc/commandbridge are never touched)
rsync -a --delete --exclude '.git' --exclude '.github' --exclude '.well-known/' --exclude '*.zip' "$SRC_DIR"/ "$INSTALL_DIR"/
if [ -f "$SRC_DIR/../LICENSE.txt" ]; then install -m 0640 "$SRC_DIR/../LICENSE.txt" "$INSTALL_DIR/LICENSE.txt"; fi
chown -R root:"$WEB_USER" "$INSTALL_DIR"
find "$INSTALL_DIR" -type d -exec chmod 0750 {} +
find "$INSTALL_DIR" -type f ! -name '*.sh' -exec chmod 0640 {} +
chmod 0750 "$INSTALL_DIR/update.sh" "$INSTALL_DIR/install.sh" 2>/dev/null || true

# 4. Database migrations
ui_stage "Applying database migrations"
$SQL "$DB_NAME" -e "CREATE TABLE IF NOT EXISTS schema_migrations (version varchar(64) NOT NULL, applied_at datetime NOT NULL DEFAULT current_timestamp(), PRIMARY KEY (version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    INSERT IGNORE INTO schema_migrations (version) VALUES ('0000_baseline');"
APPLIED=0
for f in "$INSTALL_DIR"/database/migrations/[0-9]*.sql; do
    [ -e "$f" ] || continue
    v="$(basename "$f" .sql)"
    if [ "$($SQL -N "$DB_NAME" -e "SELECT COUNT(*) FROM schema_migrations WHERE version='$v'")" = 0 ]; then
        echo "Applying migration $v"
        $SQL "$DB_NAME" < "$f"
        $SQL "$DB_NAME" -e "INSERT INTO schema_migrations (version) VALUES ('$v')"
        APPLIED=$((APPLIED+1))
    fi
done
echo "Migrations applied: $APPLIED"

# 5. Reload and check
ui_stage "Reloading Apache and checking"
systemctl reload apache2 2>/dev/null || true
if [ -d /run/systemd/system ]; then
    for u in commandbridge-gateway-dispatch.service commandbridge-gateway-dispatch.timer; do
        [ -f "$INSTALL_DIR/deploy/systemd/$u" ] && sed "s#__APP_DIR__#$INSTALL_DIR#g" "$INSTALL_DIR/deploy/systemd/$u" > "/etc/systemd/system/$u"
    done
    systemctl daemon-reload
fi
CHECK_OK=1
SCHEME=https; [ "${TLS_MODE:-2}" = 4 ] && SCHEME=http
HEALTH="$(curl -ksS --max-time 15 -H "Host: ${SERVER_NAME:-localhost}" "$SCHEME://127.0.0.1/health.php" 2>&1 || true)"
case "$HEALTH" in *'"ok":true'*) ;; *) CHECK_OK=0 ;; esac
MSG="Updated $OLD_VER -> $NEW_VER.\n\nDatabase backup: $BKFILE\nMigrations applied: $APPLIED"
[ "$CHECK_OK" = 1 ] || MSG="$MSG\n\nThe health check failed: $HEALTH\n\nSee $LOG and /var/log/apache2/commandbridge-gateway-error.log."
ui_msg "Update finished" "$MSG"
printf '%b\n' "$MSG" >&3
