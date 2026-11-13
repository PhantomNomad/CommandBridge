#!/usr/bin/env bash
# Connect Command Bridge to a Check-In Gateway, test the connection and switch sync on or off.  Run as root:
#
#     sudo <install folder>/tools/setup_checkin.sh            set up or change the connection (menu)
#     sudo <install folder>/tools/setup_checkin.sh --test     test it; offers to switch sync on if it is off
#     sudo <install folder>/tools/setup_checkin.sh --disable  switch sync off (settings are kept)
#
# install.sh runs the same steps when you choose to connect a gateway during installation.
# The connection test never collects or changes check-ins, so it is safe during an incident.
# To change the shared secret on both servers use tools/rotate_checkin_secret.sh instead.
set -Eeuo pipefail

TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB_DIR="$(dirname "$TOOLS_DIR")/deploy/lib"
CONF=/etc/commandbridge/install.conf
WEB_USER=www-data

# shellcheck disable=SC1091
. "$LIB_DIR/ui.sh" 2>/dev/null || { echo "deploy/lib/ui.sh is missing next to this script." >&2; exit 1; }
# shellcheck disable=SC1091
. "$LIB_DIR/checkin_setup.sh"
# shellcheck disable=SC1091
. "$LIB_DIR/timesync.sh"

die() { printf '\nERROR: %s\n' "$*" >&2; [ "$UI_WT" = 1 ] && ui_msg "Stopped" "$*"; exit 1; }

MODE=menu
case "${1:-}" in
    "") ;;
    --test) MODE=test ;;
    --disable) MODE=disable ;;
    -h|--help) sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $1 (use --test, --disable or no option)" >&2; exit 2 ;;
esac

[ "$(id -u)" -eq 0 ] || { echo "Run as root: sudo $0 ${1:-}" >&2; exit 1; }
[ -f "$CONF" ] || { echo "$CONF not found. Is Command Bridge installed on this server (install.sh)?" >&2; exit 1; }
# shellcheck disable=SC1090
. "$CONF"
APP_DIR="$INSTALL_DIR/app"
[ -d "$APP_DIR" ] || { echo "Command Bridge app folder $APP_DIR not found." >&2; exit 1; }
[ -f "$APP_DIR/bin/external_checkin_ping.php" ] || { echo "This Command Bridge is too old for this script. Update it first (update.sh), then run $INSTALL_DIR/tools/setup_checkin.sh." >&2; exit 1; }

exec 3>&1
ui_init "Command Bridge - Check-In Gateway"

show_secret_screen() {  # terminal only; never logged
    local f; f="$(mktemp)"; chmod 600 "$f"
    chk_instructions "$f"
    [ "$UI_WT" = 1 ] && ui_textfile "Check-In Gateway" "$f"
    { echo; echo "=============================================="; cat "$f"; echo "=============================================="; } >&3
    rm -f "$f"
}

do_test() {
    local test_out
    CHK_SYNC_ON=0
    [ "$(chk_env_get EXTERNAL_CHECKIN_ENABLED)" = 1 ] && chk_timer_active && CHK_SYNC_ON=1
    chk_ping >/dev/null 2>&1 || true
    test_out="$CHK_PING_OUT"
    if [ "$CHK_PING_OK" = 1 ] && [ "$CHK_SYNC_ON" = 1 ]; then
        ui_msg "Connection test passed" "$test_out\n\nCheck-in sync is on (the timer polls the gateway every minute)."
    elif [ "$CHK_PING_OK" = 1 ]; then
        if ui_yesno "Connection test passed" "$test_out\n\nCheck-in sync is OFF. Switch it on now?\n\nThis queues the current locations, expected resources and CIR/SAM users for the gateway, then starts the timer that polls every minute." y; then
            if chk_enable; then ui_msg "Check-in sync is on" "The gateway is polled every minute. Watch it in Command Bridge under Admin > Sync status, or with:\n    journalctl -u $CHK_SERVICE -n 20"
            else ui_msg "Problem" "Sync could not be switched on completely. See the messages above."; return 1; fi
        fi
    else
        ui_msg "Connection test did not pass" "$test_out\n\nFix the FAIL line above and run this again:\n    sudo $TOOLS_DIR/setup_checkin.sh --test$( [ "$CHK_SYNC_ON" = 1 ] && printf '\n\nSync is still on; the timer keeps retrying and check-ins wait safely on the gateway.' )"
        return 1
    fi
}

do_configure() {
    local was_on=0 time_warn
    [ "$(chk_env_get EXTERNAL_CHECKIN_ENABLED)" = 1 ] && chk_timer_active && was_on=1
    CHK_BASE_URL="$(chk_base_from_env)"
    [ "$CHK_BASE_URL" = https://checkin.example.ca ] && CHK_BASE_URL=""
    chk_ask skip_intro
    [ "$CHK_WANTED" = 1 ] || exit 1
    if [ "$was_on" = 1 ]; then
        ui_yesno "Pause sync" "Check-in sync is on. It will be paused while the new settings are written and tested; check-ins wait safely on the gateway meanwhile.\n\nContinue?" y || { echo "Cancelled. Nothing was changed." >&3; exit 1; }
        chk_disable
    fi
    chk_write_env
    if time_warn="$(timesync_check)"; then :; else ui_msg "Clock" "$time_warn"; fi
    chk_ping >/dev/null 2>&1 || true
    chk_offer_enable || true
    show_secret_screen
}

case "$MODE" in
    test)
        chk_is_configured || die "No gateway is configured yet. Run: sudo $TOOLS_DIR/setup_checkin.sh"
        do_test ;;
    disable)
        ui_yesno "Switch off check-in sync" "Stop polling the Check-In Gateway?\n\nSettings are kept; check-ins wait on the gateway until sync is switched on again with:\n    sudo $TOOLS_DIR/setup_checkin.sh --test" n || exit 1
        chk_disable
        ui_msg "Check-in sync is off" "The timer is stopped and EXTERNAL_CHECKIN_ENABLED=0." ;;
    menu)
        if chk_is_configured; then
            choice=1
            ui_menu choice "Check-In Gateway" "This server is connected to:\n    $(chk_base_from_env)\n    key ID $(chk_env_get EXTERNAL_CHECKIN_HMAC_KEY_ID)\n\nWhat do you want to do?" 1 \
                1 "Test the connection (and switch sync on if it is off)" \
                2 "Change the gateway address or key" \
                3 "Switch check-in sync off"
            case "$choice" in
                1) do_test ;;
                2) do_configure ;;
                3) exec "$0" --disable ;;
                *) die "Choose 1, 2 or 3." ;;
            esac
        else
            do_configure
        fi ;;
esac
