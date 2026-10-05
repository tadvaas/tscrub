# =============================================================================
# BIOS lock (setup/admin/power-on password) detection
# =============================================================================
#
# Vendor-agnostic BIOS password detector, run synchronously once per boot (it
# is a local read — sysfs + dmidecode — with no network component, so unlike
# the MDM worker it does NOT need to be forked). The result is published in the
# BIOS_PASSWORD_STATUS / BIOS_DETECTION_METHOD globals, rendered in the Runtime
# panel, and recorded in the report CSV + manifest.
#
# Cascade (LOCKED wins; UNLOCKED is only recorded as evidence; UNKNOWN when no
# layer yields a signal):
#   Layer 1  /sys/class/firmware_attributes/*  (kernel 5.11+ unified model)
#   Layer 2  legacy sysfs — hp-wmi, thinkpad_acpi
#   Layer 3  SMBIOS Type 24 "Hardware Security" (dmidecode -t 24)
#   Layer 4  UNKNOWN
#
# NOTE: detects BIOS *passwords*, NOT TCG "Block SID" drive lockdown (0x4286),
# which device::nvme_fail already reports as BLOCKED.
#
# NOTE (HP): BIOS passwords on HP come through Layer 1 via hp-bioscfg
# (firmware_attributes) on machines ~2018 and newer. The legacy hp-wmi
# "bios_password" sysfs node was removed from upstream hp-wmi in the 6.x
# kernel, and pre-2018 HP firmware (e.g. the Z840) does not implement the
# hp-bioscfg WMI schema — such machines expose no password surface and
# correctly fall through to UNKNOWN.
#
# Testability: every source is overridable via BIOS_FA_ROOT / BIOS_HP_WMI_FILE /
# BIOS_TP_ACPI_FILE / BIOS_DMIDECODE_CMD so the cascade is unit-testable without
# real hardware (see tests/test_bios.sh).

# --- configuration / test overrides ------------------------------------------
BIOS_FA_ROOT="${BIOS_FA_ROOT:-/sys/class/firmware_attributes}"
BIOS_HP_WMI_FILE="${BIOS_HP_WMI_FILE:-/sys/devices/platform/hp-wmi/bios_password}"
BIOS_TP_ACPI_FILE="${BIOS_TP_ACPI_FILE:-/sys/devices/platform/thinkpad_acpi/pws_setting}"
BIOS_DMIDECODE_CMD="${BIOS_DMIDECODE_CMD:-dmidecode}"

# --- state --------------------------------------------------------------------
BIOS_PASSWORD_STATUS="UNKNOWN"   # LOCKED / UNLOCKED / UNKNOWN
BIOS_DETECTION_METHOD="NONE"     # human-readable source of the verdict

# Read a single-value sysfs-style file; print trimmed contents on stdout.
# Returns non-zero when the file is missing/unreadable.
bios::_read() {
    local f="$1" v=""
    [[ -r "$f" ]] || return 1
    read -r v < "$f" 2>/dev/null || true
    v="$(printf '%s' "$v" | tr -d '\r\n' | sed -E 's/^[[:space:]]+|[[:space:]]+$//g')"
    printf '%s' "$v"
    return 0
}

# Truthy strings that indicate "a password IS set / feature enabled".
bios::_truthy() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes|on|enabled|set|locked|configured) return 0 ;;
        *) return 1 ;;
    esac
}

# Attribute-name heuristic: does this firmware_attributes entry look like a
# password attribute (rather than, say, a network toggle)?
bios::_password_name() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
        *pass*|*pwd*|*password*) return 0 ;;
        *) return 1 ;;
    esac
}

# Password-named attributes whose current_value is NOT a lock indicator —
# password *policy* knobs (length, attempts, timeout) and "bypass"/"change"
# switches. NB: use `*minimum*`/`*maximum*`, never bare `*min*` — "min" is a
# substring of "admin" and would wrongly exclude AdminPassword.
bios::_password_policy_name() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
        *bypass*|*change*|*unlock*|*hint*|*length*|*minimum*|*maximum*|*count*|*lockout*|*attempt*|*timeout*|*jumper*|*required*|*protection*|*policy*)
            return 0 ;;
        *) return 1 ;;
    esac
}

# ------------------------------------------------------------------------------
# Layer 1 — modern kernel firmware_attributes sysfs
# ------------------------------------------------------------------------------
# Return: 1 = LOCKED (authoritative), 2 = evidence of UNLOCKED, 0 = no signal.
bios::probe_sysfs() {
    local driver attr name v evidence=0
    [[ -d "$BIOS_FA_ROOT" ]] || return 0

    for driver in "$BIOS_FA_ROOT"/*/; do
        [[ -d "${driver}attributes" ]] || continue
        for attr in "${driver}"attributes/*/; do
            name="$(basename "$attr")"

            # Primary signal: the kernel's own is_password_set flag.
            if [[ -f "${attr}is_password_set" ]]; then
                v="$(bios::_read "${attr}is_password_set")" || v=""
                if bios::_truthy "$v"; then
                    BIOS_PASSWORD_STATUS="LOCKED"
                    BIOS_DETECTION_METHOD="sysfs: $(basename "$driver")/${name} is_password_set"
                    return 1
                fi
                evidence=1
            fi

            # Secondary signal: current_value on a password-named attribute.
            if [[ -f "${attr}current_value" ]] && bios::_password_name "$name" \
               && ! bios::_password_policy_name "$name"; then
                v="$(bios::_read "${attr}current_value")" || v=""
                [[ -n "$v" ]] || continue
                if bios::_truthy "$v"; then
                    BIOS_PASSWORD_STATUS="LOCKED"
                    BIOS_DETECTION_METHOD="sysfs: $(basename "$driver")/${name} current_value='${v}'"
                    return 1
                fi
                evidence=1
            fi
        done
    done

    if [[ $evidence -eq 1 ]]; then
        BIOS_PASSWORD_STATUS="UNLOCKED"
        BIOS_DETECTION_METHOD="sysfs (firmware_attributes: password attributes not set)"
        return 2
    fi
    return 0
}

# ------------------------------------------------------------------------------
# Layer 2 — legacy vendor sysfs nodes (pre-kernel-5.11 machines)
# ------------------------------------------------------------------------------
bios::probe_legacy() {
    local v

    # HP: /sys/devices/platform/hp-wmi/bios_password — 1 = password set.
    # Upstream removed this node from hp-wmi in the 6.x kernel (BIOS passwords
    # moved to hp-bioscfg / Layer 1); it only exists on older kernels, so this
    # branch is effectively for standalone runs on legacy systems.
    if [[ -f "$BIOS_HP_WMI_FILE" ]]; then
        v="$(bios::_read "$BIOS_HP_WMI_FILE")" || v=""
        if bios::_truthy "$v"; then
            BIOS_PASSWORD_STATUS="LOCKED"
            BIOS_DETECTION_METHOD="legacy: hp-wmi bios_password"
            return 1
        fi
        BIOS_PASSWORD_STATUS="UNLOCKED"
        BIOS_DETECTION_METHOD="legacy: hp-wmi bios_password not set"
        return 2
    fi

    # Lenovo ThinkPad: /sys/devices/platform/thinkpad_acpi/pws_setting.
    if [[ -f "$BIOS_TP_ACPI_FILE" ]]; then
        v="$(bios::_read "$BIOS_TP_ACPI_FILE")" || v=""
        case "$(printf '%s' "$v" | tr '[:upper:]' '[:lower:]')" in
            *enabled*|*set*|*locked*)
                BIOS_PASSWORD_STATUS="LOCKED"
                BIOS_DETECTION_METHOD="legacy: thinkpad_acpi pws_setting"
                return 1 ;;
            *disabled*|*"not set"*|*notset*|*unlocked*|*cleared*)
                BIOS_PASSWORD_STATUS="UNLOCKED"
                BIOS_DETECTION_METHOD="legacy: thinkpad_acpi pws_setting (no password)"
                return 2 ;;
        esac
    fi

    return 0
}

# ------------------------------------------------------------------------------
# Layer 3 — SMBIOS Type 24 "Hardware Security" (direct from NVRAM)
# ------------------------------------------------------------------------------
# Any "… Password Status: Enabled" => LOCKED. All Disabled / Not Implemented /
# Cleared with no Enabled => UNLOCKED evidence. "Unknown" contributes nothing.
bios::probe_smbios() {
    local out line val label evidence=0
    command -v "$BIOS_DMIDECODE_CMD" >/dev/null 2>&1 || return 0
    out="$("$BIOS_DMIDECODE_CMD" -t 24 2>/dev/null)" || return 0
    [[ -n "$out" ]] || return 0

    while IFS= read -r line; do
        [[ "$line" == *"Password Status"* ]] || continue
        val="$(printf '%s' "$line" | sed -E 's/^[[:space:]]*[^:]+:[[:space:]]*//' | tr '[:upper:]' '[:lower:]')"
        # dmidecode indents Type 24 lines with a TAB — strip it (and any other
        # surrounding whitespace) from the label or it breaks the TUI alignment.
        label="$(printf '%s' "${line%%:*}" | sed -E 's/^[[:space:]]+|[[:space:]]+$//g')"
        case "$val" in
            enabled)
                BIOS_PASSWORD_STATUS="LOCKED"
                BIOS_DETECTION_METHOD="SMBIOS Type 24 ($label)"
                return 1 ;;
            disabled|cleared|"not implemented"|"not supported"|"not set"|unset)
                evidence=1 ;;
        esac
    done <<< "$out"

    if [[ $evidence -eq 1 ]]; then
        BIOS_PASSWORD_STATUS="UNLOCKED"
        BIOS_DETECTION_METHOD="SMBIOS Type 24 (no password enabled)"
        return 2
    fi
    return 0
}

# ------------------------------------------------------------------------------
# Orchestrator — cascade with "LOCKED wins, then UNLOCKED evidence, else UNKNOWN"
# ------------------------------------------------------------------------------
bios::detect() {
    local rc
    BIOS_PASSWORD_STATUS="UNKNOWN"
    BIOS_DETECTION_METHOD="NONE"

    bios::probe_sysfs; rc=$?
    [[ $rc -eq 1 ]] && return 1

    bios::probe_legacy; rc=$?
    [[ $rc -eq 1 ]] && return 1

    bios::probe_smbios; rc=$?
    [[ $rc -eq 1 ]] && return 1

    # The probes already set UNLOCKED+method when they found positive
    # "not set" evidence; if none did, the default UNKNOWN/NONE stands.
    return 0
}
