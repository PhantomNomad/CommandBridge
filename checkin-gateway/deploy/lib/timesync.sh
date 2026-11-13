# Clock check shared by the Command Bridge and Check-In Gateway installers (source this file).
# Command Bridge and the gateway sign every request with the current time and reject requests more than
# 5 minutes old, so both servers need an accurate clock. Keep the copies in command-bridge/ and
# checkin-gateway/ identical.

# timesync_packages  -> prints "systemd-timesyncd" when no time-sync service is installed yet.
timesync_packages() {
    local p
    for p in systemd-timesyncd chrony ntpsec ntp openntpd; do
        dpkg-query -W -f='${Status}' "$p" 2>/dev/null | grep -q 'install ok installed' && return 0
    done
    echo systemd-timesyncd
}

# timesync_check  -> turns network time on if it is off; prints a warning line (and returns 1) if the
#                    clock is not synchronised yet. Prints nothing when all is well.
timesync_check() {
    command -v timedatectl >/dev/null 2>&1 || { echo "timedatectl not found; make sure this server's clock is kept accurate (NTP)."; return 1; }
    if [ "$(timedatectl show -p NTP --value 2>/dev/null)" != yes ]; then
        timedatectl set-ntp true >/dev/null 2>&1 || true
    fi
    local i
    for i in 1 2 3 4 5 6 7 8 9 10; do
        [ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" = yes ] && return 0
        sleep 1
    done
    echo "The clock is not synchronised with a time server yet (check: timedatectl status). Check-in sync fails if this server's clock is more than 5 minutes off."
    return 1
}
