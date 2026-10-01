#!/bin/bash

# =============================================================================
# Metadata & Globals
# =============================================================================

SCRIPT_NAME="tScrub"
SCRIPT_VERSION="v1.9.4"
REPORT_DIR="/"
REPORT_USB_MNT=""
LICENSE_USB_DEV=""
REPORT_OUTPUT=""
TABLE_INDENT="    "
COCID=""
CONFIG_USB_DEBUG=""
NON_INTERACTIVE=0
AUTONUKE=0
SELFTEST=0
SELFTEST_CPU=""
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
LICENSE_FILE="/etc/tscrub/license.lic"
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
SYS_UUID=""
SYS_BASEBOARD_SERIAL=""
SYS_CHASSIS_SERIAL=""
SYS_CHASSIS_TYPE=""
SYS_BIOS_VERSION=""
SYS_BIOS_DATE=""
SYS_CPU_LIST=""
SYS_GPU_LIST=""
SYS_RAM_GB=""
SYS_SKU=""
SYS_ASSET_TAG=""
SYS_BIOS_VENDOR=""
SYS_BOARD=""
SYS_TPM=""
SYS_MAC_LIST=""
SYS_STORAGE_CTRLS=""
SYS_BATTERY=""
SYS_SECUREBOOT=""
SYS_DIMM_LIST=""

# Operator / job metadata (configurable via CLI, tscrub.conf, or kernel cmdline).
OPERATOR_NAME=""
VALIDATOR_NAME=""
ASSET_TAG=""
MEDIA_SOURCE=""
MEDIA_DESTINATION=""
# Per-run report identity (generated once; shared by the portal POST + USB snapshot).
REPORT_ID=""

system::gather_info() {
    # Try dmidecode first (requires root)
    if command -v dmidecode &>/dev/null && [[ $EUID -eq 0 ]]; then
        SYS_MANUFACTURER="$(dmidecode -s system-manufacturer 2>/dev/null | head -n1 || echo N/A)"
        SYS_PRODUCT="$(dmidecode -s system-product-name 2>/dev/null | head -n1 || echo N/A)"
        SYS_SERIAL="$(dmidecode -s system-serial-number 2>/dev/null | head -n1 || echo N/A)"
        SYS_UUID="$(dmidecode -s system-uuid 2>/dev/null | head -n1 || echo N/A)"
        SYS_BASEBOARD_SERIAL="$(dmidecode -s baseboard-serial-number 2>/dev/null | head -n1 || echo N/A)"
        SYS_CHASSIS_SERIAL="$(dmidecode -s chassis-serial-number 2>/dev/null | head -n1 || echo N/A)"
        SYS_CHASSIS_TYPE="$(dmidecode -s chassis-type 2>/dev/null | head -n1 || echo N/A)"
        SYS_BIOS_VERSION="$(dmidecode -s bios-version 2>/dev/null | head -n1 || echo N/A)"
        SYS_BIOS_DATE="$(dmidecode -s bios-release-date 2>/dev/null | head -n1 || echo N/A)"
        SYS_SKU="$(dmidecode -s system-sku-number 2>/dev/null | head -n1 || echo N/A)"
        SYS_ASSET_TAG="$(dmidecode -s chassis-asset-tag 2>/dev/null | head -n1 || echo N/A)"
        SYS_BIOS_VENDOR="$(dmidecode -s bios-vendor 2>/dev/null | head -n1 || echo N/A)"
        SYS_BOARD="$(printf '%s %s' \
            "$(dmidecode -s baseboard-manufacturer 2>/dev/null | head -n1)" \
            "$(dmidecode -s baseboard-product-name 2>/dev/null | head -n1)")"
    else
        # Fallback to /sys/class/dmi/id/ (works on most Linux, even non-root)
        SYS_MANUFACTURER="$(cat /sys/class/dmi/id/sys_vendor 2>/dev/null || echo N/A)"
        SYS_PRODUCT="$(cat /sys/class/dmi/id/product_name 2>/dev/null || echo N/A)"
        SYS_SERIAL="$(cat /sys/class/dmi/id/product_serial 2>/dev/null || echo N/A)"
        SYS_UUID="$(cat /sys/class/dmi/id/product_uuid 2>/dev/null || echo N/A)"
        SYS_BASEBOARD_SERIAL="$(cat /sys/class/dmi/id/board_serial 2>/dev/null || echo N/A)"
        SYS_CHASSIS_SERIAL="$(cat /sys/class/dmi/id/chassis_serial 2>/dev/null || echo N/A)"
        SYS_CHASSIS_TYPE="$(cat /sys/class/dmi/id/chassis_type 2>/dev/null || echo N/A)"
        SYS_BIOS_VERSION="$(cat /sys/class/dmi/id/bios_version 2>/dev/null || echo N/A)"
        SYS_BIOS_DATE="$(cat /sys/class/dmi/id/bios_date 2>/dev/null || echo N/A)"
        SYS_SKU="$(cat /sys/class/dmi/id/product_sku 2>/dev/null || echo N/A)"
        SYS_ASSET_TAG="$(cat /sys/class/dmi/id/chassis_asset_tag 2>/dev/null || echo N/A)"
        SYS_BIOS_VENDOR="$(cat /sys/class/dmi/id/bios_vendor 2>/dev/null || echo N/A)"
        SYS_BOARD="$(printf '%s %s' \
            "$(cat /sys/class/dmi/id/board_vendor 2>/dev/null)" \
            "$(cat /sys/class/dmi/id/board_name 2>/dev/null)")"
    fi

    [[ -n "${SYS_SERIAL//[[:space:]]/}" ]] || SYS_SERIAL="N/A"
    [[ -n "${SYS_UUID//[[:space:]]/}" ]] || SYS_UUID="N/A"
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
              SYS_PRODUCT SYS_CHASSIS_TYPE SYS_BIOS_VERSION SYS_BIOS_DATE \
              SYS_SKU SYS_ASSET_TAG SYS_BIOS_VENDOR SYS_BOARD; do
        _val="${!_v}"
        case "${_val,,}" in
            ""|"not specified"|"none"|"unknown"|"to be filled by o.e.m."|"default string"|"system product name"|"system manufacturer"|"0")
                printf -v "$_v" "%s" "N/A" ;;
        esac
    done

    # Free-text identifiers (SKU, asset tag, board, BIOS vendor) must never
    # break the report CSV: strip commas and collapse whitespace.
    for _v in SYS_SKU SYS_ASSET_TAG SYS_BIOS_VENDOR SYS_BOARD; do
        _val="${!_v//,/ }"
        _val="$(printf '%s' "$_val" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/[[:space:]][[:space:]]*/ /g')"
        printf -v "$_v" "%s" "$_val"
    done

    # UUID: normalise missing/placeholder/sentinel values to "N/A" so the MDM
    # check skips (a sentinel UUID is shared by many machines — probing it would
    # falsely report "already assigned").
    case "${SYS_UUID,,}" in
        ""|"n/a"|"none"|"not specified"|"unknown"|"to be filled by o.e.m."|"default string"|"0"|"00000000-0000-0000-0000-000000000000"|"03000200-0400-0500-0006-000700080009")
            SYS_UUID="N/A" ;;
    esac

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

    # TPM presence/version (best-effort; reported, never required for the wipe).
    if [[ -d /sys/class/tpm/tpm0 ]]; then
        local _tpm_maj
        _tpm_maj="$(cat /sys/class/tpm/tpm0/tpm_version_major 2>/dev/null || true)"
        if [[ -n "$_tpm_maj" ]]; then
            SYS_TPM="${_tpm_maj}.0"
        else
            SYS_TPM="Present"
        fi
    else
        SYS_TPM="N/A"
    fi

    # Battery (laptops) — model / serial / state-of-charge / health / cycles.
    # Linux has no direct "health %" file: derive it as the ratio of the
    # current full capacity (energy_full / charge_full) to the design capacity
    # (energy_full_design / charge_full_design), matching what vendor tools
    # report (BitRaser's "Capacity: 51.35%", Apple's "Maximum Capacity").
    local _bat _b_model _b_serial _b_cap _b_status _b_cycles _b_full _b_design _b_health _bat_list=""
    for _bat in /sys/class/power_supply/BAT*; do
        [[ -d "$_bat" ]] || continue
        _b_model="$(cat "$_bat/model_name" 2>/dev/null)"
        _b_serial="$(cat "$_bat/serial_number" 2>/dev/null)"
        _b_cap="$(cat "$_bat/capacity" 2>/dev/null)"
        _b_status="$(cat "$_bat/status" 2>/dev/null)"
        _b_cycles="$(cat "$_bat/cycle_count" 2>/dev/null)"
        # cycle_count is only reported when the firmware exposes a meaningful
        # value; the generic ACPI battery driver leaves it 0/absent on most
        # laptops (Dell included), so omit it rather than print a misleading
        # "0 cycles".
        [[ "$_b_cycles" =~ ^[0-9]+$ && "$_b_cycles" -gt 0 ]] || _b_cycles=""
        # Prefer energy_* (µWh), fall back to charge_* (µAh).
        _b_full="$(cat "$_bat/energy_full" 2>/dev/null)"
        _b_design="$(cat "$_bat/energy_full_design" 2>/dev/null)"
        if [[ -z "$_b_full" || -z "$_b_design" ]]; then
            _b_full="$(cat "$_bat/charge_full" 2>/dev/null)"
            _b_design="$(cat "$_bat/charge_full_design" 2>/dev/null)"
        fi
        _b_health=""
        if [[ "$_b_full" =~ ^[0-9]+$ && "$_b_design" =~ ^[0-9]+$ && "$_b_full" -gt 0 && "$_b_design" -gt 0 ]]; then
            _b_health="$(( (_b_full * 100 + _b_design / 2) / _b_design ))%"
        fi
        _bat_list="${_bat_list}${_bat_list:+; }${_b_model:-Battery}${_b_serial:+ SN=$_b_serial}${_b_cap:+ @ ${_b_cap}%}"
        _bat_list="${_bat_list}${_b_health:+ (health ${_b_health})}${_b_cycles:+ (${_b_cycles} cycles)}${_b_status:+ [${_b_status}]}"
    done
    SYS_BATTERY="$(printf '%s' "${_bat_list:-N/A}" | tr -d ',' | sed -e 's/[[:space:]]\+/ /g' -e 's/^ //' -e 's/ $//')"

    # Secure Boot state (UEFI). Best-effort; "N/A" when unavailable.
    local _sb _sbvar _sbraw
    _sb=""
    if command -v mokutil >/dev/null 2>&1; then
        _sb="$(mokutil --sb-state 2>/dev/null | awk -F': *' '/SecureBoot/{print $2; exit}')"
    fi
    if [[ -z "$_sb" ]]; then
        _sbvar=(/sys/firmware/efi/efivars/SecureBoot-*)
        if [[ -e "${_sbvar[0]}" ]]; then
            _sbraw="$(od -An -tu1 "${_sbvar[0]}" 2>/dev/null | tr -s ' ')"
            case "$_sbraw" in
                *" 1") _sb="Enabled" ;;
                *" 0") _sb="Disabled" ;;
            esac
        fi
    fi
    SYS_SECUREBOOT="${_sb:-N/A}"

    # Per-DIMM inventory (size/type/speed/serial) — resale grading detail.
    local _dimm=""
    if command -v dmidecode &>/dev/null && [[ $EUID -eq 0 ]]; then
        _dimm="$(dmidecode -t 17 2>/dev/null | awk '
            /Memory Device$/        { size=""; stype=""; speed=""; sn="" }
            /^[[:space:]]*Size:/ {
                sub(/^[[:space:]]*Size:[[:space:]]*/,"")
                if ($0 !~ /No Module/) size=$0
                next
            }
            /^[[:space:]]*Type:/     { sub(/^[[:space:]]*Type:[[:space:]]*/,""); stype=$0; next }
            /^[[:space:]]*Configured Memory Speed:/ { sub(/^[[:space:]]*Configured Memory Speed:[[:space:]]*/,""); speed=$0; next }
            /^[[:space:]]*Speed:/    { if (speed=="") { sub(/^[[:space:]]*Speed:[[:space:]]*/,""); speed=$0 } ; next }
            /^[[:space:]]*Serial Number:/ { sub(/^[[:space:]]*Serial Number:[[:space:]]*/,""); sn=$0; next }
            /^$/ && size!="" {
                out=size
                if (stype!="") out=out " " stype
                if (speed!="" && speed!="Unknown") out=out " @ " speed
                if (sn!="" && sn!="Unknown" && sn!="None" && sn!="Not Specified") out=out " SN=" sn
                gsub(/,/, " ", out)
                gsub(/[ \t]+/, " ", out)
                print out
                size=""; stype=""; speed=""; sn=""
            }
            END {
                if (size!="") {
                    out=size
                    if (stype!="") out=out " " stype
                    if (speed!="" && speed!="Unknown") out=out " @ " speed
                    if (sn!="" && sn!="Unknown" && sn!="None" && sn!="Not Specified") out=out " SN=" sn
                    gsub(/,/, " ", out)
                    gsub(/[ \t]+/, " ", out)
                    print out
                }
            }
        ' | awk '{ sub(/^ /,""); sub(/ $/,""); if (NF) { printf "%s%s", sep, $0; sep="; " } }')"
    fi
    SYS_DIMM_LIST="${_dimm:-N/A}"

    # Network adapters (MAC addresses are a stable asset identifier).
    local _if _addr _macs=""
    for _if in /sys/class/net/*/address; do
        [[ -r "$_if" ]] || continue
        _addr="$(tr -d '\n' < "$_if" 2>/dev/null)"
        [[ -n "$_addr" ]] || continue
        _macs="${_macs}${_macs:+; }${_addr}"
    done
    SYS_MAC_LIST="${_macs:-N/A}"

    # Storage controllers (RAID/SAS/FC/HBA) — helps identify a wiped host.
    if command -v lspci >/dev/null 2>&1; then
        SYS_STORAGE_CTRLS="$(lspci 2>/dev/null | awk '
            /SATA controller|RAID|SAS|Fibre Channel|HBA|SCSI storage|NVMe|NVM Express/ {
                sub(/^[^ ]+ +/, "")
                sub(/^[^:]+: /, "")
                gsub(/,/, " ")
                gsub(/^[ \t]+|[ \t]+$/, "")
                printf "%s%d. %s", sep, ++n, $0
                sep = "; "
            }
        ')"
    fi
    [[ -n "$SYS_STORAGE_CTRLS" ]] || SYS_STORAGE_CTRLS="N/A"
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
            --selftest)
                SELFTEST=1
                ;;
            --selftest=*)
                case "${arg#*=}" in
                    true|1|yes|on) SELFTEST=1 ;;
                    *)             SELFTEST=0 ;;
                esac
                ;;
            --autopilotcheck)
                TSCRUB_AUTOPILOTCHECK=1
                ;;
            --autopilotcheck=*)
                case "${arg#*=}" in
                    true|1|yes|on) TSCRUB_AUTOPILOTCHECK=1 ;;
                    *)             TSCRUB_AUTOPILOTCHECK=0 ;;
                esac
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
            --autonuke)
                AUTONUKE=1
                ;;
            --autonuke=*)
                case "${arg#*=}" in
                    true|1|yes|on) AUTONUKE=1 ;;
                    *)             AUTONUKE=0 ;;
                esac
                ;;
            --operator=*)
                OPERATOR_NAME="${arg#*=}"
                ;;
            --operator)
                shift
                [[ $# -gt 0 ]] || { echo "--operator requires a name"; exit 1; }
                OPERATOR_NAME="$1"
                ;;
            --validator=*)
                VALIDATOR_NAME="${arg#*=}"
                ;;
            --validator)
                shift
                [[ $# -gt 0 ]] || { echo "--validator requires a name"; exit 1; }
                VALIDATOR_NAME="$1"
                ;;
            --asset-tag=*)
                ASSET_TAG="${arg#*=}"
                ;;
            --asset-tag)
                shift
                [[ $# -gt 0 ]] || { echo "--asset-tag requires a value"; exit 1; }
                ASSET_TAG="$1"
                ;;
            --media-source=*)
                MEDIA_SOURCE="${arg#*=}"
                ;;
            --media-source)
                shift
                [[ $# -gt 0 ]] || { echo "--media-source requires a value"; exit 1; }
                MEDIA_SOURCE="$1"
                ;;
            --media-destination=*)
                MEDIA_DESTINATION="${arg#*=}"
                ;;
            --media-destination)
                shift
                [[ $# -gt 0 ]] || { echo "--media-destination requires a value"; exit 1; }
                MEDIA_DESTINATION="$1"
                ;;
            --help|-h)
                echo "Usage: $0 [--dry-run] [--simulate-running-eta=MINUTES] [--license PATH] [--license-url URL] [--output DIR] [--cocid 12345] [--autonuke] [--operator NAME] [--validator NAME] [--asset-tag TAG] [--media-source SRC] [--media-destination DST]"
                echo "       $0 verify <report.csv> [public-key.pem]"
                echo ""
                echo "Modes:"
                echo "  (default)            Run disk sanitisation."
                echo "  --dry-run            Simulate without wiping any drive."
                echo "  --license PATH       Read the licence from PATH (default: boot USB, then /etc/tscrub/license.lic)."
                echo "  --license-url URL    Fetch the licence from URL (e.g. http://192.168.1.10/license.lic)."
                echo "  --output DIR         Write reports to DIR (default: boot USB, then /)."
                echo "  --cocid 12345        Set the Chain of Custody ID and run non-interactively (autonuke)."
                echo "  --autonuke           Select every drive and start erasure without the selection screen."
                echo "  --operator NAME      Record the erasure technician on the report."
                echo "  --validator NAME     Record the validation official on the report."
                echo "  --asset-tag TAG      Override the asset tag (default: firmware chassis asset tag)."
                echo "  --media-source SRC   Record the media source (e.g. 'IT decommissioning')."
                echo "  --media-destination DST  Record the media destination (e.g. 'resale', 'recycle')."
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

# Autonuke can also be forced from the kernel command line (tscrub_autonuke=1)
# — useful for PXE fleets that bake the flag into their boot config.
cmdline::autonuke() {
    local param
    param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_autonuke=//p' | head -n 1)"
    [[ -n "$param" ]] || return 1
    case "${param//\"/}" in
        true|1|yes|on) return 0 ;;
        *) return 1 ;;
    esac
}

# Hardware self-tests can also be forced from the kernel command line
# (tscrub_selftest=1) — useful for PXE fleets that want a tested-before-wipe
# run without re-baking the flag into the boot config.
cmdline::selftest() {
    local param
    param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_selftest=//p' | head -n 1)"
    [[ -n "$param" ]] || return 1
    case "${param//\"/}" in
        true|1|yes|on) return 0 ;;
        *) return 1 ;;
    esac
}

