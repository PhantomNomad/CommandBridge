#!/usr/bin/env bash
# Command Bridge updater. Run as root from the folder of the NEW release:   sudo ./update.sh
# Backs up the database, replaces the application code (never touching storage/ or db.env),
# applies pending database migrations and runs the self-check. See docs/UPDATING.md.
set -Eeuo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF=/etc/commandbridge/install.conf
WEB_USER=www-data
LOG=/var/log/commandbridge-update.log
# shellcheck disable=SC1091
. "$SRC_DIR/deploy/lib/ui.sh" 2>/dev/null || { echo "deploy/lib/ui.sh missing - run update.sh from the unpacked new release." >&2; exit 1; }
die() {
    printf '\nERROR: %s\n' "$*" >&2
    [ "$UI_WT" = 1 ] && { ui_msg "Update stopped" "$*\n\nDetails: $LOG"; printf '\nERROR: %s\nDetails: %s\n' "$*" "$LOG" >&3; }
    exit 1
}
trap 'die "Update stopped unexpectedly at line $LINENO. Your database backup (if made) is in /var/backups/commandbridge."' ERR

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo ./update.sh)." >&2; exit 1; }
touch "$LOG"; chmod 600 "$LOG"
exec 3>&1
ui_init "Command Bridge updater"
if [ "$UI_WT" = 1 ]; then exec > >(cat >> "$LOG") 2>&1; else exec > >(tee -a "$LOG") 2>&1; fi
[ -f "$CONF" ] || die "No Command Bridge install found ($CONF missing). Use install.sh for a fresh install."
# shellcheck disable=SC1090
. "$CONF"
[ -d "$INSTALL_DIR/app" ] || die "Install folder $INSTALL_DIR/app not found."
[ -f "$SRC_DIR/app/VERSION" ] || die "Run update.sh from the unpacked new release folder."

OLD_VER="$(tr -d '[:space:]' < "$INSTALL_DIR/VERSION" 2>/dev/null || echo unknown)"
NEW_VER="$(tr -d '[:space:]' < "$SRC_DIR/VERSION")"
APP_DIR="$INSTALL_DIR/app"
SQL=mariadb; command -v mariadb >/dev/null || SQL=mysql
[ "$SRC_DIR" != "$INSTALL_DIR" ] || die "Unpack the new release into a separate folder and run its update.sh."
UI_TITLE="Command Bridge updater"
ui_yesno "Update Command Bridge" "Update Command Bridge in:\n    $INSTALL_DIR\n\nfrom version $OLD_VER to $NEW_VER?\n\nThe database is backed up first. Uploads and database settings are kept." y || { echo "Cancelled." >&3; exit 1; }
ui_steps 4

# 1. Back up the database
ui_stage "Backing up the database"
BK=/var/backups/commandbridge; mkdir -p "$BK"; chmod 0700 "$BK"
BKFILE="$BK/${DB_NAME}-$(date +%Y%m%d-%H%M%S)-before-${NEW_VER}.sql.gz"
mariadb-dump --single-transaction --routines "$DB_NAME" 2>/dev/null | gzip > "$BKFILE" || \
    mysqldump --single-transaction --routines "$DB_NAME" | gzip > "$BKFILE"
[ -s "$BKFILE" ] || die "Database backup failed."
chmod 0600 "$BKFILE"; echo "Database backup: $BKFILE"

# 2. Time zone setting (older installs did not have one)
ENVF=/etc/commandbridge/db.env; [ -f "$ENVF" ] || ENVF="$APP_DIR/db.env"
if [ -f "$ENVF" ] && ! grep -q '^APP_TIMEZONE=' "$ENVF"; then
    TZ_NAME="$(cat /etc/timezone 2>/dev/null || echo UTC)"
    echo "APP_TIMEZONE=$TZ_NAME" >> "$ENVF"; echo "Added APP_TIMEZONE=$TZ_NAME to $ENVF (edit it if wrong)."
fi

ui_stage "Replacing application files"
# 3. Code (keeps uploads, branding changes and database settings)
rsync -a --delete --exclude 'storage/' --exclude 'db.env' --exclude '.well-known/' "$SRC_DIR/app/" "$APP_DIR/"
if [ -f "$SRC_DIR/../LICENSE.txt" ]; then install -m 0640 "$SRC_DIR/../LICENSE.txt" "$INSTALL_DIR/LICENSE.txt"; fi
rsync -a --delete "$SRC_DIR/database/" "$INSTALL_DIR/database/"
rsync -a --delete "$SRC_DIR/deploy/" "$INSTALL_DIR/deploy/"
rsync -a --delete "$SRC_DIR/docs/" "$INSTALL_DIR/docs/"
[ -d "$SRC_DIR/tools" ] && rsync -a --delete "$SRC_DIR/tools/" "$INSTALL_DIR/tools/"
for f in VERSION CHANGELOG.md README.md LICENSE SECURITY.md THIRD_PARTY.md update.sh install.sh; do
    [ -f "$SRC_DIR/$f" ] && install -m 0640 "$SRC_DIR/$f" "$INSTALL_DIR/$f"
done
chmod 0750 "$INSTALL_DIR/update.sh" "$INSTALL_DIR/install.sh" "$INSTALL_DIR"/tools/*.sh 2>/dev/null || true
chown -R root:"$WEB_USER" "$INSTALL_DIR"
find "$INSTALL_DIR" -path "$APP_DIR/storage" -prune -o -type d -exec chmod 0750 {} +
find "$INSTALL_DIR" -path "$APP_DIR/storage" -prune -o -type f ! -name '*.sh' -exec chmod 0640 {} +
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR/storage"

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
if [ -d /run/systemd/system ] && [ -f /etc/systemd/system/commandbridge-external-checkin-sync.service ]; then
    for u in commandbridge-external-checkin-sync.service commandbridge-external-checkin-sync.timer; do
        sed "s#__APP_DIR__#$APP_DIR#g" "$INSTALL_DIR/deploy/systemd/$u" > "/etc/systemd/system/$u"
    done
    systemctl daemon-reload
fi
CHECK_OK=1; CHECK_OUT="$(mktemp)"
runuser -u "$WEB_USER" -- php "$APP_DIR/bin/smoke_test.php" --only=env > "$CHECK_OUT" 2>&1 || CHECK_OK=0
cat "$CHECK_OUT"
MSG="Updated $OLD_VER -> $NEW_VER.\n\nDatabase backup: $BKFILE\nMigrations applied: $APPLIED"
[ "$CHECK_OK" = 1 ] || MSG="$MSG\n\nThe self-check reported problems:\n$(grep -E 'FAIL|^ *- ' "$CHECK_OUT" | head -10)\n\nSee $LOG and docs/SMOKE_TEST.md."
ui_msg "Update finished" "$MSG"
printf '%b\n' "$MSG" >&3
rm -f "$CHECK_OUT"
