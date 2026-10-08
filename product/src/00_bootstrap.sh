#!/bin/bash

# =============================================================================
# Metadata & Globals
# =============================================================================

SCRIPT_NAME="tScrub"
SCRIPT_VERSION="v1.11.30"
REPORT_DIR="/"
REPORT_USB_MNT=""
LICENSE_USB_DEV=""
REPORT_OUTPUT=""
TABLE_INDENT="    "
COCID=""
CONFIG_USB_DEBUG=""
AUTONUKE=0
SELFTEST=0
DIAG=0
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
UI_RUNTIME_ROW=0
UI_RUNTIME_COL=0
UI_RUNTIME_VALUE_W=0
UI_ETA_COL=178
UI_ETA_W=9
UI_STATUS_COL=0
UI_STATUS_W=9
UI_WAVE_FRAME=0
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
declare -Ag ui_last_key
UI_LAYOUT_FP=""
UI_LAYOUT_FP_CACHED=""
UI_THEME_LAST=""
UI_DEV_COUNT_LAST=""
UI_MODE_LAST=""
UI_RESIZED=0

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
SYS_CPU_SPEC=""
SYS_DISPLAY=""
SYS_WIFI=""
SYS_RAM_GB=""
SYS_SKU=""
SYS_ASSET_TAG=""
SYS_BIOS_VENDOR=""
SYS_BOARD=""
SYS_FAMILY=""
SYS_BOARD_PRODUCT=""
SYS_BOARD_VERSION=""
SYS_SYSTEM_VERSION=""
SYS_TPM=""
SYS_TPM_EKPUB=""
SYS_TPM_GETCAP=""
SYS_TPM_CAPS=""
SYS_MSDM_KEY=""
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

# Battery (laptops) — model / serial / state-of-charge / health / cycles.
# Isolated so the sysfs root is overridable in tests (POWER_SUPPLY_DIR).

# Format the battery's current full-charge capacity in watt-hours, e.g.
# "33.4 Wh". $1 = "energy" (µWh) or "charge" (µAh); $2 = capacity; $3 = nominal
# voltage in µV (charge path only). Prints nothing when it can't be derived.
battery::_full_wh() {
    local _mode="$1" _cap="$2" _volt="${3:-}"
    local _tenths
    [[ "$_cap" =~ ^[0-9]+$ && "$_cap" -gt 0 ]] || return 0
    if [[ "$_mode" == "energy" ]]; then
        _tenths=$(( (_cap + 50000) / 100000 ))                    # µWh -> Wh (1 dp)
    else
        [[ "$_volt" =~ ^[0-9]+$ && "$_volt" -gt 0 ]] || return 0
        _tenths=$(( (_cap * _volt + 50000000000) / 100000000000 ))  # µAh·µV -> Wh
    fi
    printf '%d.%d Wh' "$(( _tenths / 10 ))" "$(( _tenths % 10 ))"
    return 0
}

battery::capture() {
    local _ps_dir="${POWER_SUPPLY_DIR:-/sys/class/power_supply}"
    local _bat _b_model _b_serial _b_cap _b_status _b_cycles _b_full _b_design _b_health _b_energy _b_vdesign _b_wh _bat_list=""
    for _bat in "$_ps_dir"/BAT*; do
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
        # Prefer energy_* (µWh), fall back to charge_* (µAh) + nominal voltage.
        _b_energy=0
        _b_vdesign=""
        _b_full="$(cat "$_bat/energy_full" 2>/dev/null)"
        _b_design="$(cat "$_bat/energy_full_design" 2>/dev/null)"
        if [[ -z "$_b_full" || -z "$_b_design" ]]; then
            _b_full="$(cat "$_bat/charge_full" 2>/dev/null)"
            _b_design="$(cat "$_bat/charge_full_design" 2>/dev/null)"
            _b_vdesign="$(cat "$_bat/voltage_min_design" 2>/dev/null)"
        else
            _b_energy=1
        fi
        _b_health=""
        # Health is only meaningful when the firmware exposes a design capacity
        # that DIFFERS from the current full capacity. On HP EliteBook/ProBook
        # (and many ACPI-battery laptops) charge_full_design is reported equal
        # to charge_full, so full/design would always be 100% regardless of
        # wear — omitting the figure is more honest than printing a bogus 100%.
        if [[ "$_b_full" =~ ^[0-9]+$ && "$_b_design" =~ ^[0-9]+$ && "$_b_full" -gt 0 && "$_b_design" -gt 0 && "$_b_full" -lt "$_b_design" ]]; then
            _b_health="$(( (_b_full * 100 + _b_design / 2) / _b_design ))%"
        fi
        # Current full-charge capacity in Wh — the real, label-comparable wear
        # signal, available even when the firmware hides the design capacity.
        if [[ "$_b_energy" -eq 1 ]]; then
            _b_wh="$(battery::_full_wh energy "$_b_full")"
        else
            _b_wh="$(battery::_full_wh charge "$_b_full" "$_b_vdesign")"
        fi
        _bat_list="${_bat_list}${_bat_list:+; }${_b_model:-Battery}${_b_serial:+ SN=$_b_serial}${_b_cap:+ @ ${_b_cap}%}"
        _bat_list="${_bat_list}${_b_health:+ (health ${_b_health})}${_b_wh:+ (full ${_b_wh})}${_b_cycles:+ (${_b_cycles} cycles)}${_b_status:+ [${_b_status}]}"
    done
    SYS_BATTERY="$(printf '%s' "${_bat_list:-N/A}" | tr -d ',' | sed -e 's/[[:space:]]\+/ /g' -e 's/^ //' -e 's/ $//')"
}

# Parse a raw 128-byte EDID blob into a compact panel description, e.g.
# "AUO 1920x1080 13.3\" (2018)" — manufacturer (3-letter PNP ID), native
# resolution from the preferred timing descriptor, physical diagonal size and
# year of manufacture. Prints nothing when the blob is unreadable/too short.
edid::parse() {
    local f="$1"
    [[ -r "$f" ]] || return 0
    local -a _b
    _b=( $(od -An -tu1 -N 128 "$f" 2>/dev/null) )
    [[ ${#_b[@]} -ge 62 ]] || return 0

    # Manufacturer: bytes 8-9 hold three 5-bit letters (A=1).
    local _m1=$(( ( (_b[8] >> 2) & 0x1F ) + 64 ))
    local _m2=$(( ( ((_b[8] & 0x03) << 3) | (_b[9] >> 5) ) + 64 ))
    local _m3=$(( ( _b[9] & 0x1F ) + 64 ))
    local _mfr
    _mfr="$(printf '%b%b%b' "\\$(printf '%03o' "$_m1")" "\\$(printf '%03o' "$_m2")" "\\$(printf '%03o' "$_m3")")"

    # Year of manufacture (byte 17 is offset from 1990).
    local _year=$(( _b[17] + 1990 ))

    # Physical size: bytes 21-22 are the screen dimensions in cm; diagonal in".
    # Busybox awk lacks sqrt(), so compute it with pure integer math.
    local _hc=${_b[21]} _vc=${_b[22]} _size=""
    if [[ "$_hc" =~ ^[0-9]+$ && "$_vc" =~ ^[0-9]+$ && "$_hc" -gt 0 && "$_vc" -gt 0 ]]; then
        local _dsq=$(( _hc*_hc + _vc*_vc ))
        # Diagonal in tenths of cm = sqrt(100 * _dsq), via integer Newton sqrt.
        local _n=$(( _dsq * 100 ))
        local _r=$_n _y
        while :; do
            _y=$(( (_r + _n / _r) / 2 ))
            [[ "$_y" -ge "$_r" ]] && break
            _r=$_y
        done
        local _in10=$(( (_r * 100 + 127) / 254 ))   # inches, 1 decimal
        _size="$(printf '%d.%d"' "$(( _in10 / 10 ))" "$(( _in10 % 10 ))")"
    fi

    # Native resolution: first detailed timing descriptor (bytes 54-71).
    local _hact=$(( _b[56] + ((_b[58] & 0xF0) << 4) ))
    local _vact=$(( _b[59] + ((_b[61] & 0xF0) << 4) ))
    local _res=""
    [[ "$_hact" -gt 0 && "$_vact" -gt 0 ]] && _res="${_hact}x${_vact}"

    printf '%s' "${_mfr}${_res:+ ${_res}}${_size:+ ${_size}}${_year:+ (${_year})}"
    return 0
}

# Internal display panel (eDP) or, on desktops, the first readable connector.
display::capture() {
    local _drm="${SYS_DRM_DIR:-/sys/class/drm}" _edid
    SYS_DISPLAY=""
    for _edid in "$_drm"/*eDP*/edid "$_drm"/card*/edid; do
        [[ -r "$_edid" ]] || continue
        SYS_DISPLAY="$(edid::parse "$_edid")"
        [[ -n "$SYS_DISPLAY" ]] && break
    done
}

# TPM fields for off-device OAv3 4K hash building. The EK modulus (type 25) is
# the raw RSA-2048 endorsement-key modulus; the raw tpm2_getcap output is used
# to derive the type-13 "TPM-Version:..." descriptor off-device. Best-effort:
# empty when tpm2-tools/openssl are absent (TPM 1.2 has no tpm2_readpublic EK
# either, matching oa3tool's omission of type 25 for 1.2).
system::tpm_ekpub() {
    command -v tpm2_readpublic >/dev/null 2>&1 || return 1
    command -v openssl >/dev/null 2>&1 || return 1
    local _pem _handle _mod _ctx
    _pem="$(mktemp /tmp/tscrub-ek.XXXXXX 2>/dev/null)" || return 1
    for _handle in 0x81010001 0x81010003; do
        tpm2_readpublic -c "$_handle" -f pem -o "$_pem" >/dev/null 2>&1 && break
    done
    if [[ ! -s "$_pem" ]] && command -v tpm2_createek >/dev/null 2>&1; then
        _ctx="$(mktemp /tmp/tscrub-ekctx.XXXXXX 2>/dev/null)"
        if [[ -n "$_ctx" ]] && tpm2_createek -c "$_ctx" -G rsa >/dev/null 2>&1; then
            tpm2_readpublic -c "$_ctx" -f pem -o "$_pem" >/dev/null 2>&1 || true
            rm -f "$_ctx"
        fi
    fi
    _mod=""
    if [[ -s "$_pem" ]]; then
        _mod="$(openssl rsa -pubin -in "$_pem" -noout -modulus 2>/dev/null | sed 's/^Modulus=//')"
    fi
    rm -f "$_pem"
    printf '%s' "${_mod,,}"
}

system::tpm_getcap_raw() {
    command -v tpm2_getcap >/dev/null 2>&1 || return 1
    tpm2_getcap properties-fixed 2>/dev/null | tr '\n' ';' | tr -d '\r'
}

system::msdm_key() {
    local _msdm=/sys/firmware/acpi/tables/MSDM _key
    [[ -r "$_msdm" ]] || return 1
    _key="$(dd if="$_msdm" 2>/dev/null | grep -aoE '[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}' | head -n1)"
    printf '%s' "$_key"
}

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
        SYS_FAMILY="$(dmidecode -s system-family 2>/dev/null | head -n1 || echo N/A)"
        SYS_BOARD_PRODUCT="$(dmidecode -s baseboard-product-name 2>/dev/null | head -n1 || echo N/A)"
        SYS_BOARD_VERSION="$(dmidecode -s baseboard-version 2>/dev/null | head -n1 || echo N/A)"
        SYS_SYSTEM_VERSION="$(dmidecode -s system-version 2>/dev/null | head -n1 || echo N/A)"
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
        SYS_FAMILY="$(cat /sys/class/dmi/id/product_family 2>/dev/null || echo N/A)"
        SYS_BOARD_PRODUCT="$(cat /sys/class/dmi/id/board_name 2>/dev/null || echo N/A)"
        SYS_BOARD_VERSION="$(cat /sys/class/dmi/id/board_version 2>/dev/null || echo N/A)"
        SYS_SYSTEM_VERSION="$(cat /sys/class/dmi/id/product_version 2>/dev/null || echo N/A)"
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
              SYS_SKU SYS_ASSET_TAG SYS_BIOS_VENDOR SYS_BOARD \
              SYS_FAMILY SYS_BOARD_PRODUCT SYS_BOARD_VERSION SYS_SYSTEM_VERSION; do
        _val="${!_v}"
        case "${_val,,}" in
            ""|"not specified"|"none"|"unknown"|"to be filled by o.e.m."|"default string"|"system product name"|"system manufacturer"|"0")
                printf -v "$_v" "%s" "N/A" ;;
        esac
    done

    # Free-text identifiers (SKU, asset tag, board, BIOS vendor) must never
    # break the report CSV: strip commas and collapse whitespace.
    for _v in SYS_SKU SYS_ASSET_TAG SYS_BIOS_VENDOR SYS_BOARD \
              SYS_FAMILY SYS_BOARD_PRODUCT SYS_BOARD_VERSION SYS_SYSTEM_VERSION; do
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

    # Core/thread count (resale grading) — physical cores vs logical threads.
    SYS_CPU_SPEC=""
    if command -v lscpu &>/dev/null; then
        local _socks _cps _tpc _lcpu _cores _threads
        _socks="$(lscpu 2>/dev/null | awk -F': *' '/^Socket\(s\):/{print $2}' | head -n1 | tr -d ' ')"
        _cps="$(lscpu 2>/dev/null | awk -F': *' '/^Core\(s\) per socket:/{print $2}' | head -n1 | tr -d ' ')"
        _tpc="$(lscpu 2>/dev/null | awk -F': *' '/^Thread\(s\) per core:/{print $2}' | head -n1 | tr -d ' ')"
        _lcpu="$(lscpu 2>/dev/null | awk -F': *' '/^CPU\(s\):/{print $2}' | head -n1 | tr -d ' ')"
        if [[ "$_socks" =~ ^[0-9]+$ && "$_cps" =~ ^[0-9]+$ ]]; then
            _cores=$(( _socks * _cps ))
            _threads="${_lcpu:-$(( _cores * _tpc ))}"
            [[ "$_threads" =~ ^[0-9]+$ ]] && SYS_CPU_SPEC="${_cores}C/${_threads}T"
        fi
    fi

    # CPU max (turbo) speed from SMBIOS Type 4 — resale grading detail.
    if command -v dmidecode &>/dev/null && [[ $EUID -eq 0 ]]; then
        local _cpu_max
        _cpu_max="$(dmidecode -t 4 2>/dev/null | awk -F': *' '/^[[:space:]]*Max Speed:/{v=$2; sub(/ .*/,"",v); if (v+0>0) printf "%.1f GHz", v/1000; exit}')"
        [[ -n "$_cpu_max" ]] && SYS_CPU_SPEC="${SYS_CPU_SPEC:+${SYS_CPU_SPEC} · }${_cpu_max} max"
    fi

    if command -v lspci &>/dev/null; then
        SYS_GPU_LIST="$(lspci 2>/dev/null | awk '/VGA compatible controller|3D controller|Display controller/ {sub(/^[^ ]+ +/, ""); sub(/^[^:]+: /, ""); print}' | nl -w1 -s'. ')"
    elif command -v lshw &>/dev/null; then
        SYS_GPU_LIST="$(lshw -C display 2>/dev/null | awk -F': ' '/product:/ {print $2}' | nl -w1 -s'. ')"
    fi

    if [[ -z "$SYS_GPU_LIST" ]]; then
        SYS_GPU_LIST="N/A"
    fi

    # Wi-Fi adapter model (resale grading) — PCIe wireless from lspci.
    SYS_WIFI=""
    if command -v lspci &>/dev/null; then
        SYS_WIFI="$(lspci 2>/dev/null | awk '/Network controller/ {sub(/^[^ ]+ +/, ""); sub(/^[^:]+: /, ""); sub(/\(rev [0-9a-f]+\)[[:space:]]*$/, ""); gsub(/^[[:space:]]+|[[:space:]]+$/, ""); print}' | head -n1)"
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

    # Board's maximum supported RAM (SMBIOS Type 16) — upgrade headroom.
    local _ram_max=""
    if command -v dmidecode &>/dev/null && [[ $EUID -eq 0 ]]; then
        _ram_max="$(dmidecode -t 16 2>/dev/null | awk -F': *' '/^[[:space:]]*Maximum Capacity:/{sub(/^[[:space:]]+/,"",$2); print $2; exit}')"
    fi

    local _ram_extra=""
    [[ -n "$_ram_max" && "$_ram_max" != "Unknown" ]] && _ram_extra="max $_ram_max"
    [[ -n "$_ram_summary" ]] && _ram_extra="${_ram_extra:+${_ram_extra}, }$_ram_summary"

    if [[ -n "$_ram_extra" ]]; then
        SYS_RAM_GB="$_ram_total ($_ram_extra)"
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

    # TPM fields for off-device 4K hash building (type 25 EK modulus + raw
    # tpm2_getcap output, from which the type-13 descriptor is derived
    # off-device).  Best-effort: no tpm2-tools -> empty; TPM 1.2 -> no EK.
    SYS_TPM_EKPUB="$(system::tpm_ekpub 2>/dev/null || true)"
    SYS_TPM_GETCAP="$(system::tpm_getcap_raw 2>/dev/null || true)"
    # TPM 1.2 sysfs caps (manufacturer / version / firmware) for the type-13
    # descriptor; TPM 2.0 derives the descriptor from tpm_getcap instead.
    SYS_TPM_CAPS="$(cat /sys/class/tpm/tpm0/caps 2>/dev/null | tr '\n' ';' | tr -d '\r')"

    # OEM Windows product key from the MSDM ACPI table (for off-device
    # ProductKeyId / hash type 24 derivation).
    SYS_MSDM_KEY="$(system::msdm_key 2>/dev/null || true)"

    # Battery (laptops) — model / serial / state-of-charge / health / cycles.
    battery::capture

    # Internal display panel (eDP) — manufacturer / resolution / size / year.
    display::capture

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

    # Per-DIMM inventory (size/mfr/type/form-factor/speed/part#/serial).
    local _dimm=""
    if command -v dmidecode &>/dev/null && [[ $EUID -eq 0 ]]; then
        _dimm="$(dmidecode -t 17 2>/dev/null | awk '
            /Memory Device$/        { size=""; stype=""; speed=""; sn=""; pn=""; mfr=""; ff="" }
            /^[[:space:]]*Size:/ {
                sub(/^[[:space:]]*Size:[[:space:]]*/,"")
                if ($0 !~ /No Module/) size=$0
                next
            }
            /^[[:space:]]*Type:/     { sub(/^[[:space:]]*Type:[[:space:]]*/,""); stype=$0; next }
            /^[[:space:]]*Form Factor:/ { sub(/^[[:space:]]*Form Factor:[[:space:]]*/,""); ff=$0; next }
            /^[[:space:]]*Manufacturer:/ { sub(/^[[:space:]]*Manufacturer:[[:space:]]*/,""); mfr=$0; next }
            /^[[:space:]]*Configured Memory Speed:/ { sub(/^[[:space:]]*Configured Memory Speed:[[:space:]]*/,""); speed=$0; next }
            /^[[:space:]]*Speed:/    { if (speed=="") { sub(/^[[:space:]]*Speed:[[:space:]]*/,""); speed=$0 } ; next }
            /^[[:space:]]*Serial Number:/ { sub(/^[[:space:]]*Serial Number:[[:space:]]*/,""); sn=$0; next }
            /^[[:space:]]*Part Number:/   { sub(/^[[:space:]]*Part Number:[[:space:]]*/,""); pn=$0; next }
            /^$/ && size!="" {
                out=size
                if (mfr!="" && mfr!="Unknown" && mfr!="None" && mfr!="Not Specified" && mfr!="[Empty]") out=out " " mfr
                if (stype!="" && stype!="Unknown") out=out " " stype
                if (ff!="" && ff!="Unknown" && ff!="None" && ff!="Not Specified") out=out " " ff
                if (speed!="" && speed!="Unknown") out=out " @ " speed
                if (pn!="" && pn!="Unknown" && pn!="None" && pn!="Not Specified" && pn!="[Empty]") out=out " P/N=" pn
                if (sn!="" && sn!="Unknown" && sn!="None" && sn!="Not Specified") out=out " SN=" sn
                gsub(/,/, " ", out)
                gsub(/[ \t]+/, " ", out)
                print out
                size=""; stype=""; speed=""; sn=""; pn=""; mfr=""; ff=""
            }
            END {
                if (size!="") {
                    out=size
                    if (mfr!="" && mfr!="Unknown" && mfr!="None" && mfr!="Not Specified" && mfr!="[Empty]") out=out " " mfr
                    if (stype!="" && stype!="Unknown") out=out " " stype
                    if (ff!="" && ff!="Unknown" && ff!="None" && ff!="Not Specified") out=out " " ff
                    if (speed!="" && speed!="Unknown") out=out " @ " speed
                    if (pn!="" && pn!="Unknown" && pn!="None" && pn!="Not Specified" && pn!="[Empty]") out=out " P/N=" pn
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

    # Intel VMD (Volume Management Device) — NVMe drives behind VMD are in
    # Intel RST "RAID mode". Detected once; per-drive flag in device::discover.
    SYS_VMD=""
    # RAID-capable HBA present (Smart Array / MegaRAID / PERC / SAS3xxx / …).
    SYS_RAID_HBA=""
    if command -v lspci >/dev/null 2>&1; then
        if lspci 2>/dev/null | grep -qi "Volume Management Device"; then
            SYS_VMD=1
        fi
        if lspci 2>/dev/null | grep -qiE 'Smart Array|MegaRAID|PERC|ServeRAID|SAS3008|SAS3108|Adaptec|SmartHBA|HBA 11|HBA 9'; then
            SYS_RAID_HBA=1
        fi
    fi

    # Extended hardware inventory (USB, full PCI, full SMBIOS, NIC detail,
    # UEFI boot entries, peripheral presence) — see 46_hardware.sh.
    hardware::inventory
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
# fd 3; the UI reads them from fd 4. One erasure cycle consumes (closes) the
# channel, so erasure::run opens a fresh one at the start of each cycle.
ipc::open() {
    coproc UI { cat; }
    exec 3>&${UI[1]}
    exec 4<&${UI[0]}
}

# Tear down the worker -> UI IPC channel. Best-effort — the fds may already be
# closed (ui::loop closes fds 4 and UI[0]; erasure::run closes fd 3 + UI[1]
# before ui::loop). Called on the selection-abort path and at the end of each
# erasure so a repeat erasure opens a clean channel.
ipc::close() {
    exec 3>&- 2>/dev/null || true
    exec 4<&- 2>/dev/null || true
    { exec {UI[0]}<&-; } 2>/dev/null || true
    { exec {UI[1]}>&-; } 2>/dev/null || true
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
            --diag)
                DIAG=1
                ;;
            --diag=*)
                case "${arg#*=}" in
                    true|1|yes|on|all) DIAG=1 ;;
                    *)                 DIAG=0 ;;
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
            --verify)
                shift
                [[ $# -gt 0 ]] || { echo "--verify requires none|sampled|full"; exit 1; }
                VERIFY_MODE="$1"
                ;;
            --verify=*)
                VERIFY_MODE="${arg#*=}"
                ;;
            --hpa)
                shift
                [[ $# -gt 0 ]] || { echo "--hpa requires on|off"; exit 1; }
                HPA_MODE="$1"
                ;;
            --hpa=*)
                HPA_MODE="${arg#*=}"
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
                ;;
            --cocid)
                shift
                [[ $# -gt 0 ]] || { echo "--cocid requires a 5-digit Chain of Custody ID"; exit 1; }
                COCID="$1"
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
                echo "  --cocid 12345        Set the Chain of Custody ID (no longer implies autonuke)."
                echo "  --autonuke           Select every drive and start erasure without the triage screen."
                echo "  --operator NAME      Record the erasure technician on the report."
                echo "  --validator NAME     Record the validation official on the report."
                echo "  --asset-tag TAG      Override the asset tag (default: firmware chassis asset tag)."
                echo "  --media-source SRC   Record the media source (e.g. 'IT decommissioning')."
                echo "  --media-destination DST  Record the media destination (e.g. 'resale', 'recycle')."
                echo "  --verify MODE       Post-erasure verification: none|sampled|full (default sampled)."
                echo "  --hpa MODE         Reset HPA/DCO hidden areas before erasure: on|off (default on)."
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

# Hardware diagnostics suite can be forced from the kernel command line
# (tscrub_diag=1) — same PXE-fleet convenience as tscrub_selftest=.
cmdline::diag() {
    local param
    param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_diag=//p' | head -n 1)"
    [[ -n "$param" ]] || return 1
    case "${param//\"/}" in
        true|1|yes|on|all) return 0 ;;
        *) return 1 ;;
    esac
}

