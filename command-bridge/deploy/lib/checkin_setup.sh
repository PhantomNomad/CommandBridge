# Check-In Gateway connection setup, shared by install.sh, tools/setup_checkin.sh and
# tools/rotate_checkin_secret.sh. Source it after deploy/lib/ui.sh. The caller sets APP_DIR and WEB_USER.
#
# Secrets: nothing here prints the shared secret on standard output (install.sh sends that to its log).
# chk_instructions writes it to a file the caller shows on the terminal only.

CHK_ENV_FILE=/etc/commandbridge/external_checkin_sync.env
CHK_TIMER=commandbridge-external-checkin-sync.timer
CHK_SERVICE=commandbridge-external-checkin-sync.service

CHK_WANTED=0        # 1 = connect to a gateway
CHK_BASE_URL=""     # https://checkin.example.ca
CHK_KEY_ID=""
CHK_SECRET=""
CHK_GENERATED=0     # 1 = secret generated here (the gateway still needs it)
CHK_PING_OK=0
CHK_PING_OUT=""
CHK_SYNC_ON=0       # 1 = EXTERNAL_CHECKIN_ENABLED=1 and the timer is enabled

chk_env_get() {  # chk_env_get KEY  -> value from the settings file ("" if missing)
    [ -r "$CHK_ENV_FILE" ] || return 0
    sed -n "s/^$1=//p" "$CHK_ENV_FILE" | tail -1
}
chk_env_set() {  # chk_env_set KEY VALUE  (values are validated first, so they are safe in sed)
    if grep -q "^$1=" "$CHK_ENV_FILE"; then sed -i "s|^$1=.*|$1=$2|" "$CHK_ENV_FILE"; else printf '%s=%s\n' "$1" "$2" >> "$CHK_ENV_FILE"; fi
}
chk_is_configured() { [ -n "$(chk_env_get EXTERNAL_CHECKIN_HMAC_SECRET)" ] && [ -n "$(chk_env_get EXTERNAL_CHECKIN_GATEWAY_PULL_URL)" ]; }
chk_timer_active() { systemctl is-active --quiet "$CHK_TIMER" 2>/dev/null; }
chk_tools_dir() { printf '%s/tools' "$(cd "$APP_DIR/.." 2>/dev/null && pwd || echo "$APP_DIR/..")"; }
chk_base_from_env() { chk_env_get EXTERNAL_CHECKIN_GATEWAY_PULL_URL | sed 's|/api/v1/sync/pull\.php$||'; }

chk_normalize_url() {  # strip a trailing slash or a pasted /api/... path
    local u="${1%%/api/*}"
    u="${u%%\?*}"
    printf '%s' "${u%/}"
}
chk_valid_url() { [[ $1 =~ ^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?$ ]]; }
chk_valid_key_id() { [[ $1 =~ ^[A-Za-z0-9._:-]{3,64}$ ]]; }
chk_valid_secret() { [ "${#1}" -ge 32 ] && [[ $1 =~ ^[A-Za-z0-9+/=._:-]+$ ]]; }
chk_new_secret() { openssl rand -base64 48 | tr -d '\n'; }
chk_next_key_id() {  # commandbridge-1 -> commandbridge-2
    if [[ $1 =~ ^(.*[^0-9])([0-9]+)$ ]]; then printf '%s%s' "${BASH_REMATCH[1]}" "$(( BASH_REMATCH[2] + 1 ))"; else printf '%s-2' "${1:-commandbridge}"; fi
}

# chk_ask [skip_intro]  - asks whether to connect, the gateway address and where the key comes from.
# Unattended (CB_ASSUME_YES=1): CB_CHECKIN=1 to connect, CB_CHECKIN_URL, and optionally
# CB_CHECKIN_KEY_ID + CB_CHECKIN_SECRET (otherwise a secret is generated).
chk_ask() {
    CHK_WANTED=0
    if [ "${1:-}" != skip_intro ]; then
        if [ "${CB_ASSUME_YES:-0}" = 1 ]; then
            [ "${CB_CHECKIN:-0}" = 1 ] || return 0
        else
            ui_yesno "Check-In Gateway" "Connect this Command Bridge to a Check-In Gateway?\n\nThe gateway is a separate, internet-facing server where Check-In Recorders and Staging Area Managers check resources in from their phones. Command Bridge polls it once a minute over HTTPS, so Command Bridge itself stays inside your network.\n\nChoose No if you do not use one. You can connect later with:\n    sudo <install folder>/tools/setup_checkin.sh" n || return 0
        fi
    fi
    CHK_WANTED=1

    local tries=0 url
    while :; do
        ui_ask url "Gateway address" "Web address of the Check-In Gateway, for example https://checkin.example.ca\n\nIt must use HTTPS with a certificate this server trusts (Let's Encrypt or your own certificate, not self-signed)." "${CB_CHECKIN_URL:-${CHK_BASE_URL:-https://checkin.example.ca}}"
        url="$(chk_normalize_url "$url")"
        if chk_valid_url "$url" && [ "$url" != https://checkin.example.ca ]; then CHK_BASE_URL="$url"; break; fi
        tries=$((tries + 1))
        if [ "${CB_ASSUME_YES:-0}" = 1 ] || [ $tries -ge 3 ]; then
            CHK_WANTED=0; ui_msg "Check-In Gateway" "No valid gateway address was given, so the gateway was not connected. Run tools/setup_checkin.sh later."; return 0
        fi
        ui_msg "Gateway address" "'$url' is not a valid address. Enter just https:// and the name, for example https://checkin.example.ca"
    done

    local src=1
    if [ -n "${CB_CHECKIN_SECRET:-}" ]; then
        src=2
    elif [ "${CB_ASSUME_YES:-0}" != 1 ]; then
        ui_menu src "Shared secret" "Command Bridge and the gateway share a key ID and secret to prove requests are genuine.\n\nIs the gateway already installed?" 1 \
            1 "No - generate a new key here; I will paste it into the gateway installer" \
            2 "Yes - paste the key ID and secret the gateway installer showed me"
    fi

    if [ "$src" = 2 ]; then
        CHK_GENERATED=0
        ui_ask CHK_KEY_ID "Key ID" "Key ID shown by the gateway installer:" "${CB_CHECKIN_KEY_ID:-commandbridge-1}"
        chk_valid_key_id "$CHK_KEY_ID" || { CHK_WANTED=0; ui_msg "Check-In Gateway" "The key ID must be 3-64 characters (letters, digits . _ : -). The gateway was not connected; run tools/setup_checkin.sh to try again."; return 0; }
        CHK_SECRET="${CB_CHECKIN_SECRET:-}"
        [ -n "$CHK_SECRET" ] || ui_pass CHK_SECRET "Paste the shared secret from the gateway installer (hidden)"
        CHK_SECRET="$(printf '%s' "$CHK_SECRET" | tr -d '[:space:]')"
        chk_valid_secret "$CHK_SECRET" || { CHK_WANTED=0; ui_msg "Check-In Gateway" "That secret is too short (32+ characters) or contains characters that are not allowed. The gateway was not connected; run tools/setup_checkin.sh to try again."; return 0; }
    else
        CHK_GENERATED=1
        CHK_KEY_ID="${CB_CHECKIN_KEY_ID:-commandbridge-1}"
        chk_valid_key_id "$CHK_KEY_ID" || CHK_KEY_ID=commandbridge-1
        CHK_SECRET="$(chk_new_secret)"
    fi
    return 0
}

# chk_write_env  - writes the settings file with sync switched OFF (chk_enable switches it on after a test).
# Tuning values already in the file are kept; an existing file with a secret is backed up first.
chk_write_env() {
    local keep tmp k v
    mkdir -p "$(dirname "$CHK_ENV_FILE")"
    if [ -f "$CHK_ENV_FILE" ] && [ -n "$(chk_env_get EXTERNAL_CHECKIN_HMAC_SECRET)" ]; then
        install -m 0600 -o root -g root "$CHK_ENV_FILE" "$CHK_ENV_FILE.bak-$(date +%Y%m%d-%H%M%S)"
    fi
    keep=""
    for k in EXTERNAL_CHECKIN_REQUEST_TTL_SECONDS EXTERNAL_CHECKIN_MAX_ATTEMPTS EXTERNAL_CHECKIN_CONNECT_TIMEOUT EXTERNAL_CHECKIN_REQUEST_TIMEOUT; do
        v="$(chk_env_get "$k")"
        case "$k" in
            *TTL*) v="${v:-300}" ;; *MAX_ATTEMPTS) v="${v:-12}" ;; *CONNECT_TIMEOUT) v="${v:-5}" ;; *) v="${v:-20}" ;;
        esac
        [[ $v =~ ^[0-9]+$ ]] || v=""
        [ -n "$v" ] && keep+="$k=$v"$'\n'
    done
    tmp="$(mktemp)"
    cat > "$tmp" <<ENVEOF
# Command Bridge <-> Check-In Gateway connection. Written by tools/setup_checkin.sh. Keep private.
# The same key ID and secret must be in /etc/commandbridge/checkin_gateway.env on the gateway.
# Test the connection:  sudo $(chk_tools_dir)/setup_checkin.sh --test
EXTERNAL_CHECKIN_ENABLED=0
EXTERNAL_CHECKIN_GATEWAY_URL=$CHK_BASE_URL/api/v1/sync/events.php
EXTERNAL_CHECKIN_GATEWAY_PULL_URL=$CHK_BASE_URL/api/v1/sync/pull.php
EXTERNAL_CHECKIN_GATEWAY_ACK_URL=$CHK_BASE_URL/api/v1/sync/ack.php
EXTERNAL_CHECKIN_HMAC_KEY_ID=$CHK_KEY_ID
EXTERNAL_CHECKIN_HMAC_SECRET=$CHK_SECRET
${keep}# Public link printed on location QR codes; {location_token} is replaced per location.
EXTERNAL_CHECKIN_PUBLIC_ENTRY_BASE_URL=$CHK_BASE_URL/?location={location_token}
ENVEOF
    install -m 0640 -o root -g "$WEB_USER" "$tmp" "$CHK_ENV_FILE"
    rm -f "$tmp"
}

# chk_ping  - runs the connection test as the web user; sets CHK_PING_OK and CHK_PING_OUT.
chk_ping() {
    CHK_PING_OK=0
    if [ ! -f "$APP_DIR/bin/external_checkin_ping.php" ]; then
        CHK_PING_OUT="FAIL $APP_DIR/bin/external_checkin_ping.php is missing. Update Command Bridge first (update.sh)."
        return 1
    fi
    if CHK_PING_OUT="$(runuser -u "$WEB_USER" -- php "$APP_DIR/bin/external_checkin_ping.php" 2>&1)"; then
        CHK_PING_OK=1
    fi
    printf '%s\n' "$CHK_PING_OUT"      # no secrets in this output
    [ "$CHK_PING_OK" = 1 ]
}

# chk_enable  - switches sync on: settings flag, one-time backfill, timer.
chk_enable() {
    local out
    # Queue what the gateway needs first; only then switch the flag and the timer on, so a failure leaves sync fully off.
    if ! out="$(runuser -u "$WEB_USER" -- php "$APP_DIR/bin/external_checkin_sync_backfill.php" 2>&1)"; then
        printf 'Backfill failed, sync left off: %s\n' "$out"
        return 1
    fi
    printf 'Backfill: %s\n' "$out"
    chk_env_set EXTERNAL_CHECKIN_ENABLED 1
    if [ -d /run/systemd/system ]; then
        if ! systemctl enable --now "$CHK_TIMER" >/dev/null 2>&1; then
            chk_env_set EXTERNAL_CHECKIN_ENABLED 0
            echo "Could not enable $CHK_TIMER, sync left off (see: systemctl status $CHK_TIMER)"
            return 1
        fi
    fi
    CHK_SYNC_ON=1
}

# chk_disable  - switches sync off. Settings and queues are kept.
chk_disable() {
    [ -d /run/systemd/system ] && systemctl disable --now "$CHK_TIMER" >/dev/null 2>&1 || true
    [ -f "$CHK_ENV_FILE" ] && chk_env_set EXTERNAL_CHECKIN_ENABLED 0
    CHK_SYNC_ON=0
}

# chk_offer_enable  - after chk_ping: asks whether to switch sync on (default Yes only if the test passed).
chk_offer_enable() {
    local q
    if [ "$CHK_PING_OK" = 1 ]; then
        q="The connection test passed:\n\n$CHK_PING_OUT\n\nSwitch check-in sync on now? This starts the timer that polls the gateway every minute."
        ui_yesno "Switch on check-in sync" "$q" y || return 0
    else
        q="The connection test did not pass:\n\n$CHK_PING_OUT\n\nThis is expected if the gateway is not installed yet. Leave sync off for now and run\n    sudo $(chk_tools_dir)/setup_checkin.sh --test\nonce the gateway is ready; it switches sync on when the test passes.\n\nSwitch sync on anyway?"
        ui_yesno "Switch on check-in sync" "$q" n || return 0
    fi
    chk_enable
}

# chk_instructions FILE  - appends the "what to do next" text, including a generated secret, to FILE.
chk_instructions() {
    local f=$1 tools
    tools="$(chk_tools_dir)"
    {
        echo "CHECK-IN GATEWAY"
        if [ "$CHK_WANTED" != 1 ]; then
            echo "  Not connected. To connect one later:  sudo $tools/setup_checkin.sh"
            return 0
        fi
        echo "  Gateway       : $CHK_BASE_URL"
        echo "  Key ID        : $CHK_KEY_ID"
        if [ "$CHK_PING_OK" = 1 ]; then echo "  Connection    : test passed"; else echo "  Connection    : test did not pass yet"; { printf '%s\n' "$CHK_PING_OUT" | grep -E '^(FAIL|warn)' | head -3 | sed 's/^/                  /'; } || true; fi
        if [ "$CHK_SYNC_ON" = 1 ]; then echo "  Sync          : ON (polls every minute)"; else echo "  Sync          : off - to test again and switch it on:  sudo $tools/setup_checkin.sh --test"; fi
        if [ "$CHK_GENERATED" = 1 ]; then
            echo
            echo "  Install the gateway on its own server (see checkin-gateway/docs/INSTALL.md):  sudo ./install.sh"
            echo "  When it asks 'Generate a strong random shared secret?' choose No, then enter:"
            echo "    Key ID        : $CHK_KEY_ID"
            echo "    Shared secret : $CHK_SECRET"
            echo "    ^ shown once here - it is also stored in $CHK_ENV_FILE"
            echo "  Answer Yes to 'Accept synchronisation from Command Bridge'."
        fi
    } >> "$f"
}
