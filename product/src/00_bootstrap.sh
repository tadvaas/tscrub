#!/bin/bash

# =============================================================================
# Metadata & Globals
# =============================================================================

SCRIPT_NAME="tScrub"
SCRIPT_VERSION="v1.4.50"
REPORT_DIR="/"
REPORT_USB_MNT=""
LICENSE_USB_DEV=""
REPORT_OUTPUT=""
TABLE_INDENT="    "
COCID=""
CONFIG_USB_DEBUG=""
NON_INTERACTIVE=0
LOG_FILE="/$SCRIPT_NAME.log"
DRY_RUN=0
DRY_RUN_SIM_ETA_MINS=0
START_TS=0
UI_INPLACE=0
# Completion theme: 0=normal, 1=green (all good), 2=red (drive failed),
# 3=amber (report delivery failed), 4=blue (wipe in progress).
UI_COMPLETE_THEME=0
REPORT_USB_STATUS=""
REPORT_USB_REASON=""
REPORT_DASH_STATUS=""
REPORT_DASH_REASON=""
REPORT_NET_STATUS=""
REPORT_NET_REASON=""
RERUN=0
UI_RUNTIME_ROW=0
UI_RUNTIME_COL=0
UI_RUNTIME_VALUE_W=0
UI_ETA_COL=178
UI_ETA_W=9
UI_TABLE_MAIN_W=182
UI_TABLE_INDENT="    "
LICENSE_FILE="/etc/tscrub/license.key"
LICENSE_URL=""
LICENSE_SOURCE_SET=0
LICENSE_VENDOR_PUBLIC_KEY_B64=""
LICENSE_CUSTOMER=""
LICENSE_EXPIRY=""
LICENSE_TIER=""
UI_SPINNER_FRAME=0
NO_SUPPORTED_DRIVES=0
DISCOVERY_NOTICE=""
SMART_TIMEOUT="${SMART_TIMEOUT:-10}"

declare -Ag devrow
declare -Ag ui_eta_row

# Monotonic seconds, used for every duration measurement (Elapsed, ETA countdown,
# NVMe monitor timeout). Wall-clock `date +%s` is NOT monotonic: on machines with
# a dead/flaky RTC (or an NTP/hwclock step) the clock can be wrong, frozen, or
# jump backwards, which freezes the Elapsed counter at 00:00:00. Read
# /proc/uptime (immune to clock changes) and fall back to `date +%s` where
# /proc/uptime is unavailable (e.g. non-Linux hosts).
ts::now() {
    local u _idle
    if [[ -r /proc/uptime ]]; then
        read -r u _idle < /proc/uptime 2>/dev/null
        u="${u%%.*}"
        [[ "$u" =~ ^[0-9]+$ ]] && { printf '%s' "$u"; return 0; }
    fi
    printf '%s' "$(date +%s)"
}

# System info globals
SYS_MANUFACTURER=""
SYS_PRODUCT=""
SYS_SERIAL=""
SYS_BASEBOARD_SERIAL=""
SYS_CHASSIS_SERIAL=""
SYS_CHASSIS_TYPE=""
SYS_BIOS_VERSION=""
SYS_BIOS_DATE=""
SYS_CPU_LIST=""
SYS_GPU_LIST=""
SYS_RAM_GB=""

system::gather_info() {
    # Try dmidecode first (requires root)
    if command -v dmidecode &>/dev/null && [[ $EUID -eq 0 ]]; then
        SYS_MANUFACTURER="$(dmidecode -s system-manufacturer 2>/dev/null | head -n1 || echo N/A)"
        SYS_PRODUCT="$(dmidecode -s system-product-name 2>/dev/null | head -n1 || echo N/A)"
        SYS_SERIAL="$(dmidecode -s system-serial-number 2>/dev/null | head -n1 || echo N/A)"
        SYS_BASEBOARD_SERIAL="$(dmidecode -s baseboard-serial-number 2>/dev/null | head -n1 || echo N/A)"
        SYS_CHASSIS_SERIAL="$(dmidecode -s chassis-serial-number 2>/dev/null | head -n1 || echo N/A)"
        SYS_CHASSIS_TYPE="$(dmidecode -s chassis-type 2>/dev/null | head -n1 || echo N/A)"
        SYS_BIOS_VERSION="$(dmidecode -s bios-version 2>/dev/null | head -n1 || echo N/A)"
        SYS_BIOS_DATE="$(dmidecode -s bios-release-date 2>/dev/null | head -n1 || echo N/A)"
    else
        # Fallback to /sys/class/dmi/id/ (works on most Linux, even non-root)
        SYS_MANUFACTURER="$(cat /sys/class/dmi/id/sys_vendor 2>/dev/null || echo N/A)"
        SYS_PRODUCT="$(cat /sys/class/dmi/id/product_name 2>/dev/null || echo N/A)"
        SYS_SERIAL="$(cat /sys/class/dmi/id/product_serial 2>/dev/null || echo N/A)"
        SYS_BASEBOARD_SERIAL="$(cat /sys/class/dmi/id/board_serial 2>/dev/null || echo N/A)"
        SYS_CHASSIS_SERIAL="$(cat /sys/class/dmi/id/chassis_serial 2>/dev/null || echo N/A)"
        SYS_CHASSIS_TYPE="$(cat /sys/class/dmi/id/chassis_type 2>/dev/null || echo N/A)"
        SYS_BIOS_VERSION="$(cat /sys/class/dmi/id/bios_version 2>/dev/null || echo N/A)"
        SYS_BIOS_DATE="$(cat /sys/class/dmi/id/bios_date 2>/dev/null || echo N/A)"
    fi

    [[ -n "${SYS_SERIAL//[[:space:]]/}" ]] || SYS_SERIAL="N/A"
    [[ -n "${SYS_BASEBOARD_SERIAL//[[:space:]]/}" ]] || SYS_BASEBOARD_SERIAL="N/A"
    [[ -n "${SYS_CHASSIS_SERIAL//[[:space:]]/}" ]] || SYS_CHASSIS_SERIAL="N/A"
    [[ -n "${SYS_MANUFACTURER//[[:space:]]/}" ]] || SYS_MANUFACTURER="N/A"
    [[ -n "${SYS_PRODUCT//[[:space:]]/}" ]] || SYS_PRODUCT="N/A"
    [[ -n "${SYS_CHASSIS_TYPE//[[:space:]]/}" ]] || SYS_CHASSIS_TYPE="N/A"
    [[ -n "${SYS_BIOS_VERSION//[[:space:]]/}" ]] || SYS_BIOS_VERSION="N/A"
    [[ -n "${SYS_BIOS_DATE//[[:space:]]/}" ]] || SYS_BIOS_DATE="N/A"

    # Normalise vendor placeholder strings (QEMU "Not Specified", Dell/Lenovo
    # "To Be Filled By O.E.M.", etc.) to a single "N/A" for a clean display.
    local _v _val
    for _v in SYS_SERIAL SYS_BASEBOARD_SERIAL SYS_CHASSIS_SERIAL SYS_MANUFACTURER \
              SYS_PRODUCT SYS_CHASSIS_TYPE SYS_BIOS_VERSION SYS_BIOS_DATE; do
        _val="${!_v}"
        case "${_val,,}" in
            ""|"not specified"|"none"|"unknown"|"to be filled by o.e.m."|"default string"|"system product name"|"system manufacturer"|"0")
                printf -v "$_v" "%s" "N/A" ;;
        esac
    done

    # Processor info (physical CPU sockets only, each displayed individually)
    # Use lscpu if available (more reliable), fall back to /proc/cpuinfo
    if command -v lscpu &>/dev/null; then
        local cpu_model="$(lscpu 2>/dev/null | grep -i 'model name' | head -n1 | awk -F': *' '{$1=""; print}' | sed 's/^ //')"
        local num_sockets="$(lscpu 2>/dev/null | grep -i 'socket(s)' | awk '{print $NF}')"
        if [[ -n "$num_sockets" && "$num_sockets" -gt 0 ]]; then
            SYS_CPU_LIST="$(for ((i=1; i<=num_sockets; i++)); do echo "$i. $cpu_model"; done)"
        fi
    fi
    # Fallback if lscpu failed or unavailable
    if [[ -z "$SYS_CPU_LIST" ]]; then
        SYS_CPU_LIST="$(awk '/physical id/{pid=$NF} /model name/{if(!seen[pid]++) {sub(/^.*: /, ""); print}}' /proc/cpuinfo | nl -w1 -s'. ')"
    fi

    if command -v lspci &>/dev/null; then
        SYS_GPU_LIST="$(lspci 2>/dev/null | awk '/VGA compatible controller|3D controller|Display controller/ {sub(/^[^ ]+ +/, ""); sub(/^[^:]+: /, ""); print}' | nl -w1 -s'. ')"
    elif command -v lshw &>/dev/null; then
        SYS_GPU_LIST="$(lshw -C display 2>/dev/null | awk -F': ' '/product:/ {print $2}' | nl -w1 -s'. ')"
    fi

    if [[ -z "$SYS_GPU_LIST" ]]; then
        SYS_GPU_LIST="N/A"
    fi

    # RAM info: total rounded to nearest power-of-2 GB (matches marketing sizes).
    local _ram_total
    _ram_total="$(awk '/MemTotal/ {
        val = ($2 * 1024) / 1000000000
        p = 1; while (p * 2 < val) p *= 2
        if (val - p >= p * 2 - val) p = p * 2
        printf "%d GB", p
    }' /proc/meminfo 2>/dev/null)"

    local _ram_summary=""
    if command -v dmidecode &>/dev/null && [[ $EUID -eq 0 ]]; then
        _ram_summary="$(dmidecode -t 17 2>/dev/null | awk '
            /Memory Device$/        { size=""; cfg=""; speed="" }
            /^[[:space:]]*Size:/ {
                sub(/^[[:space:]]*Size:[[:space:]]*/,"")
                if ($0 !~ /No Module/) size=$0
                next
            }
            /^[[:space:]]*Configured Memory Speed:/ {
                sub(/^[[:space:]]*Configured Memory Speed:[[:space:]]*/,"")
                cfg=$0; next
            }
            /^[[:space:]]*Speed:/ {
                if (speed=="") { sub(/^[[:space:]]*Speed:[[:space:]]*/,""); speed=$0 }
                next
            }
            /^$/ && size!="" {
                s=(cfg!="" && cfg!="Unknown") ? cfg : speed
                sub(/ .*/,"",s)
                if (size ~ /MB/) { n=size+0; size=sprintf("%dGB", int((n+512)/1024)) }
                else { sub(/ /,"",size) }
                print size " @ " (s==""||s=="Unknown" ? "N/A" : s)
                size=""; cfg=""; speed=""
            }
            END {
                if (size!="") {
                    s=(cfg!="" && cfg!="Unknown") ? cfg : speed
                    sub(/ .*/,"",s)
                    if (size ~ /MB/) { n=size+0; size=sprintf("%dGB", int((n+512)/1024)) }
                    else { sub(/ /,"",size) }
                    print size " @ " (s==""||s=="Unknown" ? "N/A" : s)
                }
            }
        ' | sort | uniq -c | awk '
            { count=$1; $1=""; sub(/^ /,""); printf "%s%d x %s", sep, count, $0; sep=", " }
        ')"
    fi

    if [[ -n "$_ram_summary" ]]; then
        SYS_RAM_GB="$_ram_total ($_ram_summary)"
    else
        SYS_RAM_GB="$_ram_total"
    fi
}

# =============================================================================
# UI / IPC setup
# =============================================================================

# Log fd is opened once for the lifetime of the process. When the default
# path is not writable (e.g. an unprivileged test run), fall back to /tmp so
# fd 5 is always available to the workers.
if [[ ! -w "$(dirname "$LOG_FILE")" ]]; then
    LOG_FILE="/tmp/$SCRIPT_NAME.log"
fi
# `exec` with only redirections applies them to the current shell PERMANENTLY,
# so a `2>/dev/null` here would silently swallow ALL later stderr output (the
# licence error, diagnostics). Do not suppress stderr on this exec.
exec 5>>"$LOG_FILE" || exec 5>/dev/null

# (Re)establish the worker -> UI IPC channel. Workers write status lines to
# fd 3; the UI reads them from fd 4. A single run consumes (closes) fds 3 and 4
# and lets the coprocess exit, so this is called at the start of every run to
# rebuild the channel when the post-run prompt's "Run again" option is chosen.
ipc::open() {
    coproc UI { cat; }
    exec 3>&${UI[1]}
    exec 4<&${UI[0]}
}

parse_args() {
    local arg mins

    while (($# > 0)); do
        arg="$1"
        case "$arg" in
            --dry-run|-n)
                DRY_RUN=1
                ;;
            --simulate-running-eta=*)
                mins="${arg#*=}"
                if [[ "$mins" =~ ^[0-9]+$ ]] && (( mins > 0 )); then
                    DRY_RUN_SIM_ETA_MINS="$mins"
                else
                    echo "Invalid value for --simulate-running-eta: '$mins' (expected integer minutes > 0)"
                    exit 1
                fi
                ;;
            --license=*)
                LICENSE_FILE="${arg#*=}"
                [[ -n "$LICENSE_FILE" ]] || { echo "--license requires a non-empty path"; exit 1; }
                LICENSE_SOURCE_SET=1
                ;;
            --license)
                shift
                [[ $# -gt 0 ]] || { echo "--license requires a path"; exit 1; }
                LICENSE_FILE="$1"
                LICENSE_SOURCE_SET=1
                ;;
            --license-url=*)
                LICENSE_URL="${arg#*=}"
                [[ -n "$LICENSE_URL" ]] || { echo "--license-url requires a non-empty URL"; exit 1; }
                LICENSE_SOURCE_SET=1
                ;;
            --license-url)
                shift
                [[ $# -gt 0 ]] || { echo "--license-url requires a URL"; exit 1; }
                LICENSE_URL="$1"
                LICENSE_SOURCE_SET=1
                ;;
            --output=*)
                REPORT_OUTPUT="${arg#*=}"
                [[ -n "$REPORT_OUTPUT" ]] || { echo "--output requires a non-empty path"; exit 1; }
                ;;
            --output)
                shift
                [[ $# -gt 0 ]] || { echo "--output requires a path"; exit 1; }
                REPORT_OUTPUT="$1"
                ;;
            --cocid=*)
                COCID="${arg#*=}"
                NON_INTERACTIVE=1
                ;;
            --cocid)
                shift
                [[ $# -gt 0 ]] || { echo "--cocid requires a 5-digit Chain of Custody ID"; exit 1; }
                COCID="$1"
                NON_INTERACTIVE=1
                ;;
            --help|-h)
                echo "Usage: $0 [--dry-run] [--simulate-running-eta=MINUTES] [--license PATH] [--license-url URL] [--output DIR] [--cocid 12345]"
                echo "       $0 verify <report.csv> [public-key.pem]"
                echo ""
                echo "Modes:"
                echo "  (default)            Run disk sanitisation."
                echo "  --dry-run            Simulate without wiping any drive."
                echo "  --license PATH       Read the licence from PATH (default: boot USB, then /etc/tscrub/license.key)."
                echo "  --license-url URL    Fetch the licence from URL (e.g. http://192.168.1.10/license.key)."
                echo "  --output DIR         Write reports to DIR (default: boot USB, then /)."
                echo "  --cocid 12345        Set the Chain of Custody ID and run non-interactively (autonuke)."
                echo "  verify <csv>         Verify a signed report (SHA-256 + signature)."
                exit 0
                ;;
            *)
                echo "Unknown argument: $arg"
                exit 1
                ;;
        esac
        shift
    done

    if [[ "$DRY_RUN" -eq 0 ]] && [[ "$DRY_RUN_SIM_ETA_MINS" -gt 0 ]]; then
        echo "--simulate-running-eta requires --dry-run"
        exit 1
    fi
}

