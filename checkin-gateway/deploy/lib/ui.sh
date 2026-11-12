# Shared text-UI helpers for install.sh and update.sh (source this file).
# Uses whiptail (ncurses dialogs) when running on a real terminal; otherwise falls back to
# plain prompts. Unattended runs (CB_ASSUME_YES=1) and CB_PLAIN=1 always use plain mode.
# whiptail talks to /dev/tty directly, so it keeps working while stdout is being logged.

UI_WT=0
UI_TITLE="Command Bridge"
UI_STEP_TOTAL=1

ui_init() {  # ui_init [title]   - call after /tmp-safe preflight; may install whiptail
    [ -n "${1:-}" ] && UI_TITLE="$1"
    UI_WT=0
    [ "${CB_ASSUME_YES:-0}" = 1 ] && return 0
    [ "${CB_PLAIN:-0}" = 1 ] && return 0
    [ -t 0 ] && [ -t 1 ] && [ -r /dev/tty ] && [ -w /dev/tty ] || return 0
    [ "${TERM:-dumb}" != dumb ] || return 0
    if ! command -v whiptail >/dev/null 2>&1 && [ "${CB_SKIP_PACKAGES:-0}" != 1 ] && command -v apt-get >/dev/null 2>&1; then
        echo "Installing whiptail for a friendlier installer..."
        { apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq whiptail; } >/dev/null 2>&1 || true
    fi
    command -v whiptail >/dev/null 2>&1 && UI_WT=1
    return 0
}

_ui_height() {  # _ui_height "text" extra  -> dialog height that fits the wrapped text
    local lines
    lines="$(printf '%b' "$1" | fold -s -w 66 | wc -l)"
    echo $(( lines + $2 ))
}

# Run whiptail with its screen on /dev/tty and its answer (stderr) captured.
_wt() { whiptail --backtitle "$UI_TITLE" "$@" 2>&1 1>/dev/tty </dev/tty; }

ui_msg() {  # ui_msg "Title" "text"
    if [ "$UI_WT" = 1 ]; then
        _wt --title "$1" --msgbox "$(printf '%b' "$2")" "$(_ui_height "$2" 7)" 72 || true
    else
        printf '\n%s\n%b\n' "$1" "$2"
    fi
}

ui_textfile() {  # ui_textfile "Title" file     (use for secrets: never on a command line)
    if [ "$UI_WT" = 1 ]; then
        _wt --title "$1" --scrolltext --textbox "$2" 22 76 || true
    else
        printf '\n%s\n' "$1"; cat "$2"
    fi
}

ui_ask() {  # ui_ask VAR "Title" "Prompt" "default"
    local var=$1 title=$2 prompt=$3 default=${4:-} reply
    if [ "${CB_ASSUME_YES:-0}" = 1 ]; then printf -v "$var" '%s' "$default"; return 0; fi
    if [ "$UI_WT" = 1 ]; then
        reply="$(_wt --title "$title" --inputbox "$(printf '%b' "$prompt")" "$(_ui_height "$prompt" 8)" 72 "$default")" || { ui_cancelled; }
    else
        read -r -p "$(printf '%b' "$prompt") [$default]: " reply || reply=""
    fi
    printf -v "$var" '%s' "${reply:-$default}"
}

ui_pass() {  # ui_pass VAR "Prompt"
    local var=$1 prompt=$2 reply
    if [ "$UI_WT" = 1 ]; then
        reply="$(_wt --title "Password" --passwordbox "$prompt" 10 72)" || { ui_cancelled; }
    else
        read -r -s -p "$prompt: " reply; echo
    fi
    printf -v "$var" '%s' "$reply"
}

ui_yesno() {  # ui_yesno "Title" "Question" default(y|n)   -> status 0 = yes
    local title=$1 q=$2 def=${3:-y} reply
    if [ "${CB_ASSUME_YES:-0}" = 1 ]; then [ "$def" = y ]; return; fi
    if [ "$UI_WT" = 1 ]; then
        local opt=(); [ "$def" = n ] && opt=(--defaultno)
        _wt --title "$title" "${opt[@]}" --yesno "$(printf '%b' "$q")" "$(_ui_height "$q" 7)" 72 >/dev/null
    else
        read -r -p "$(printf '%b' "$q") [$( [ "$def" = y ] && echo Y/n || echo y/N )]: " reply || reply=""
        reply=${reply:-$def}; [[ $reply =~ ^[Yy] ]]
    fi
}

ui_menu() {  # ui_menu VAR "Title" "Prompt" default tag "text" tag "text" ...
    local var=$1 title=$2 prompt=$3 default=$4 reply n
    shift 4
    n=$(( $# / 2 ))
    if [ "${CB_ASSUME_YES:-0}" = 1 ]; then printf -v "$var" '%s' "$default"; return 0; fi
    if [ "$UI_WT" = 1 ]; then
        reply="$(_wt --title "$title" --default-item "$default" --menu "$(printf '%b' "$prompt")" "$(( $(_ui_height "$prompt" 8) + n ))" 76 "$n" "$@")" || { ui_cancelled; }
    else
        printf '\n%b\n' "$prompt"
        local i=1 args=("$@"); while [ $i -le $# ]; do printf '   %s) %s\n' "${args[$((i-1))]}" "${args[$i]}"; i=$((i+2)); done
        read -r -p "Choose [$default]: " reply || reply=""
    fi
    printf -v "$var" '%s' "${reply:-$default}"
}

ui_cancelled() { printf 'Cancelled.\n' >&3 2>/dev/null || printf 'Cancelled.\n'; exit 1; }

ui_steps() { UI_STEP_TOTAL=$1; UI_STEP_N=0; }
ui_stage() {  # ui_stage "label"   - progress screen (whiptail) or a heading (plain)
    UI_STEP_N=$(( ${UI_STEP_N:-0} + 1 ))
    local pct=$(( UI_STEP_N * 100 / UI_STEP_TOTAL )) filled bar="" i
    filled=$(( pct * 40 / 100 ))
    for ((i=0;i<40;i++)); do [ $i -lt $filled ] && bar+="#" || bar+="."; done
    if [ "$UI_WT" = 1 ]; then
        whiptail --backtitle "$UI_TITLE" --title "Working" --infobox "Step $UI_STEP_N of $UI_STEP_TOTAL\n\n[$bar] ${pct}%\n\n$1 ..." 10 60 >/dev/tty 2>/dev/null </dev/tty || true
        printf '== %s\n' "$1"          # goes to the log
    else
        printf '\n== %s\n' "$1"
    fi
}
