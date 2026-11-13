#!/usr/bin/env bash
# Check-In Gateway: install a new shared secret from Command Bridge.  Run as root:
#     sudo /var/www/commandbridge-gateway/tools/rotate_secret.sh
#
# Start the rotation on Command Bridge (sudo <install folder>/tools/rotate_checkin_secret.sh). It pauses sync,
# creates the new key ID and secret, and tells you to run this script. Here you type the new key ID and paste
# the secret (hidden). This script backs up the settings file, writes the new values and checks the gateway
# still answers. Back on Command Bridge, the rotation script then tests the connection and resumes sync.
# Check-ins submitted meanwhile wait safely on the gateway.
#
# To put the previous values back:  sudo cp <backup file shown at the end> /etc/commandbridge/checkin_gateway.env
set -Eeuo pipefail

TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(dirname "$TOOLS_DIR")"
ENV_FILE=/etc/commandbridge/checkin_gateway.env
WEB_USER=www-data

# shellcheck disable=SC1091
. "$ROOT_DIR/deploy/lib/ui.sh" 2>/dev/null || { echo "deploy/lib/ui.sh is missing next to this script." >&2; exit 1; }
die() { printf '\nERROR: %s\n' "$*" >&2; [ "$UI_WT" = 1 ] && ui_msg "Stopped" "$*"; exit 1; }

[ "$(id -u)" -eq 0 ] || { echo "Run as root: sudo $0" >&2; exit 1; }
[ -f "$ENV_FILE" ] || { echo "$ENV_FILE not found. Is the Check-In Gateway installed on this server?" >&2; exit 1; }
exec 3>&1
ui_init "Check-In Gateway - new shared secret"

env_get() { sed -n "s/^$1=//p" "$ENV_FILE" | tail -1 | sed 's/^"\(.*\)"$/\1/'; }
env_set() {  # env_set KEY VALUE   (VALUE is restricted to [A-Za-z0-9+/=._:-], so it is safe in sed)
    if grep -q "^$1=" "$ENV_FILE"; then sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"; else printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"; fi
}

OLD_KEY_ID="$(env_get EXTERNAL_CHECKIN_HMAC_KEY_ID)"
SUGGEST="${OLD_KEY_ID:-commandbridge-1}"
if [[ $SUGGEST =~ ^(.*[^0-9])([0-9]+)$ ]]; then SUGGEST="${BASH_REMATCH[1]}$(( BASH_REMATCH[2] + 1 ))"; else SUGGEST="$SUGGEST-2"; fi

ui_msg "New shared secret" "This replaces the key ID and shared secret the gateway uses to recognise Command Bridge.\n\nCurrent key ID: ${OLD_KEY_ID:-(none)}\n\nHave the new key ID and secret from Command Bridge's rotation screen ready. Sync from Command Bridge is refused until both servers have the same new values."

ui_ask NEW_KEY_ID "New key ID" "Key ID shown on Command Bridge:" "$SUGGEST"
[[ $NEW_KEY_ID =~ ^[A-Za-z0-9._:-]{3,64}$ ]] || die "Key ID must be 3-64 characters (letters, digits . _ : -)."
ui_pass NEW_SECRET "Paste the new shared secret (hidden)"
NEW_SECRET="$(printf '%s' "$NEW_SECRET" | tr -d '[:space:]')"
[ "${#NEW_SECRET}" -ge 32 ] || die "The shared secret must be at least 32 characters. Nothing was changed."
[[ $NEW_SECRET =~ ^[A-Za-z0-9+/=._:-]+$ ]] || die "The shared secret contains characters that are not allowed. Copy it again from Command Bridge. Nothing was changed."
[ "$NEW_SECRET" != "$(env_get EXTERNAL_CHECKIN_HMAC_SECRET)" ] || die "That is the secret the gateway already has. Nothing was changed."

ui_yesno "Confirm" "Replace key ID '${OLD_KEY_ID:-none}' with '$NEW_KEY_ID' and install the new secret?" y || { echo "Cancelled. Nothing was changed." >&3; exit 1; }

BACKUP="$ENV_FILE.bak-$(date +%Y%m%d-%H%M%S)"
install -m 0600 -o root -g root "$ENV_FILE" "$BACKUP"
env_set EXTERNAL_CHECKIN_HMAC_KEY_ID "$NEW_KEY_ID"
env_set EXTERNAL_CHECKIN_HMAC_SECRET "$NEW_SECRET"
chown root:"$WEB_USER" "$ENV_FILE"; chmod 0640 "$ENV_FILE"

SYNC_NOTE=""
if [ "$(env_get GATEWAY_SYNC_ENABLED)" != 1 ]; then
    if ui_yesno "Sync is switched off" "GATEWAY_SYNC_ENABLED is not 1, so the gateway refuses Command Bridge entirely.\n\nSwitch sync on now?" y; then
        env_set GATEWAY_SYNC_ENABLED 1
    else
        SYNC_NOTE="Sync is still switched OFF on the gateway (GATEWAY_SYNC_ENABLED=0), so the test on Command Bridge will fail until you set it to 1."
    fi
fi

# The settings file is read on every request, so no restart is needed. Make sure the gateway still answers.
SERVER_NAME="$(sed -n 's/^SERVER_NAME=//p' /etc/commandbridge/gateway-install.conf 2>/dev/null | tail -1 || true)"
HEALTH="$(curl -ksS --max-time 10 ${SERVER_NAME:+-H "Host: $SERVER_NAME"} https://127.0.0.1/health.php 2>&1 || true)"
case "$HEALTH" in *'"ok":true'*) HEALTH_LINE="ok   The gateway is answering (health.php)." ;; *) HEALTH_LINE="warn health.php did not answer as expected: $HEALTH" ;; esac

MSG="New key ID '$NEW_KEY_ID' and secret installed on the gateway.\n\n$HEALTH_LINE\n\nPrevious settings saved to:\n    $BACKUP\n(To undo: sudo cp $BACKUP $ENV_FILE)\n\nNow go back to Command Bridge and let the rotation script test the connection. It resumes sync when the test passes."
[ -n "$SYNC_NOTE" ] && MSG="$MSG\n\n$SYNC_NOTE"
ui_msg "Done on the gateway" "$MSG"
[ "$UI_WT" = 1 ] && printf '%b\n' "$MSG" >&3
exit 0
