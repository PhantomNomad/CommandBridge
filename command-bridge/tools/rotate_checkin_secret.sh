#!/usr/bin/env bash
# Replace the shared secret between Command Bridge and the Check-In Gateway: pause, swap, resume.  Run as root:
#     sudo <install folder>/tools/rotate_checkin_secret.sh
#
#  1. Pauses check-in sync (the timer) and waits for a running poll to finish.
#  2. Creates a new key ID (commandbridge-1 -> commandbridge-2) and a new random secret, and saves them here
#     (the old settings file is backed up).
#  3. Shows the new values. On the GATEWAY run:  sudo <gateway folder>/tools/rotate_secret.sh  and enter them.
#  4. Back here, tests the connection and resumes sync when it passes. You can also put the old key back.
#
# Nothing is lost while sync is paused: check-ins wait on the gateway, and updates for the gateway wait here.
set -Eeuo pipefail

TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB_DIR="$(dirname "$TOOLS_DIR")/deploy/lib"
CONF=/etc/commandbridge/install.conf
WEB_USER=www-data

# shellcheck disable=SC1091
. "$LIB_DIR/ui.sh" 2>/dev/null || { echo "deploy/lib/ui.sh is missing next to this script." >&2; exit 1; }
# shellcheck disable=SC1091
. "$LIB_DIR/checkin_setup.sh"

[ "$(id -u)" -eq 0 ] || { echo "Run as root: sudo $0" >&2; exit 1; }
[ -f "$CONF" ] || { echo "$CONF not found. Is Command Bridge installed on this server?" >&2; exit 1; }
# shellcheck disable=SC1090
. "$CONF"
APP_DIR="$INSTALL_DIR/app"
[ -f "$APP_DIR/bin/external_checkin_ping.php" ] || { echo "This Command Bridge is too old for this script. Update it first (update.sh)." >&2; exit 1; }
chk_is_configured || { echo "No Check-In Gateway is configured. Run $TOOLS_DIR/setup_checkin.sh first." >&2; exit 1; }

exec 3>&1
ui_init "Command Bridge - new check-in shared secret"

OLD_KEY_ID="$(chk_env_get EXTERNAL_CHECKIN_HMAC_KEY_ID)"
GATEWAY="$(chk_base_from_env)"
WAS_ON=0
[ "$(chk_env_get EXTERNAL_CHECKIN_ENABLED)" = 1 ] && chk_timer_active && WAS_ON=1

ui_yesno "New shared secret" "This replaces the key ID and shared secret used between this server and the gateway\n    $GATEWAY\n\nCurrent key ID: $OLD_KEY_ID\n\nSteps: pause sync here, create the new key, enter it on the gateway (you need a root session there), test, resume. Check-ins wait safely on the gateway while sync is paused.\n\nStart now?" y \
    || { echo "Cancelled. Nothing was changed." >&3; exit 1; }

# 1. Pause and let a running poll finish
PAUSED=0
if [ -d /run/systemd/system ]; then
    systemctl stop "$CHK_TIMER" 2>/dev/null || true
    for _ in $(seq 1 60); do
        state="$(systemctl is-active "$CHK_SERVICE" 2>/dev/null || true)"
        [ "$state" = active ] || [ "$state" = activating ] || break
        sleep 1
    done
    PAUSED=1
fi
resume() { [ "$WAS_ON" = 1 ] && [ "$PAUSED" = 1 ] && systemctl start "$CHK_TIMER" 2>/dev/null; return 0; }
trap 'printf "\nInterrupted. Check-in sync is PAUSED. Finish with:  sudo %s/setup_checkin.sh --test\n" "$TOOLS_DIR" >&3; exit 1' INT

# 2. New key, saved here (old file backed up)
NEW_KEY_ID="$(chk_next_key_id "$OLD_KEY_ID")"
ui_ask NEW_KEY_ID "New key ID" "New key ID (it must differ from '$OLD_KEY_ID' so both servers can tell old and new apart):" "$NEW_KEY_ID"
if ! chk_valid_key_id "$NEW_KEY_ID" || [ "$NEW_KEY_ID" = "$OLD_KEY_ID" ]; then
    resume; ui_msg "Stopped" "The key ID must be new and 3-64 characters (letters, digits . _ : -). Nothing was changed; sync was resumed."; exit 1
fi
NEW_SECRET="$(chk_new_secret)"
BACKUP="$CHK_ENV_FILE.bak-$(date +%Y%m%d-%H%M%S)"
install -m 0600 -o root -g root "$CHK_ENV_FILE" "$BACKUP"
chk_env_set EXTERNAL_CHECKIN_HMAC_KEY_ID "$NEW_KEY_ID"
chk_env_set EXTERNAL_CHECKIN_HMAC_SECRET "$NEW_SECRET"
chown root:"$WEB_USER" "$CHK_ENV_FILE"; chmod 0640 "$CHK_ENV_FILE"

# 3. Show the values (terminal only, plain text so they can be copied)
[ "$UI_WT" = 1 ] && ui_msg "Enter the new key on the gateway" "The new key ID and secret are printed in the terminal after you press OK, so you can copy them.\n\nSync is paused until the test passes."
{
    echo
    echo "=============================================================="
    echo " On the GATEWAY server ($GATEWAY), run:"
    echo "     sudo <gateway folder>/tools/rotate_secret.sh"
    echo "   (usually /var/www/commandbridge-gateway/tools/rotate_secret.sh)"
    echo " and enter:"
    echo "     Key ID        : $NEW_KEY_ID"
    echo "     Shared secret : $NEW_SECRET"
    echo
    echo " The secret is also stored in $CHK_ENV_FILE."
    echo " Old settings: $BACKUP"
    echo "=============================================================="
} >&3

# 4. Test, then resume or roll back
while :; do
    if [ "$UI_WT" = 1 ]; then
        read -r -p "Press Enter when the gateway has the new key... " _ </dev/tty
    fi
    action=1
    ui_menu action "Finish" "Has the gateway been given the new key ID '$NEW_KEY_ID'?" 1 \
        1 "Yes - test the connection now" \
        2 "Undo - put the old key back here and resume sync" \
        3 "Stop here - leave sync paused and finish later"
    case "$action" in
        1)
            chk_ping >/dev/null 2>&1 || true
            if [ "$CHK_PING_OK" = 1 ]; then
                trap - INT
                if [ "$WAS_ON" = 1 ]; then
                    systemctl start "$CHK_TIMER"
                    ui_msg "Done" "$CHK_PING_OUT\n\nThe new key works and check-in sync has resumed.\n\nThe old settings file is kept at\n    $BACKUP\nDelete it once you are happy:  sudo rm $BACKUP"
                else
                    ui_msg "Done" "$CHK_PING_OUT\n\nThe new key works. Check-in sync was off before and is still off; switch it on with:\n    sudo $TOOLS_DIR/setup_checkin.sh --test"
                fi
                exit 0
            fi
            ui_msg "Test did not pass" "$CHK_PING_OUT\n\nIf you have not finished on the gateway yet, do that and choose Test again. Sync stays paused meanwhile."
            ;;
        2)
            install -m 0640 -o root -g "$WEB_USER" "$BACKUP" "$CHK_ENV_FILE"
            resume; trap - INT
            ui_msg "Old key restored" "Command Bridge uses key ID '$OLD_KEY_ID' again$( [ "$WAS_ON" = 1 ] && printf ' and sync has resumed' ).\n\nIf you already changed the gateway, undo it there too: rotate_secret.sh printed the backup file and the cp command to restore it."
            exit 1
            ;;
        3)
            trap - INT
            ui_msg "Sync is paused" "The new key is saved here. Finish on the gateway, then run:\n    sudo $TOOLS_DIR/setup_checkin.sh --test\nIt tests the connection and switches sync back on."
            exit 1
            ;;
        *) ;;
    esac
done
