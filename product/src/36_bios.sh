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
#   Layer 3b UEFI variable UserCred (HP business: an "AdminPW" credential
#            record exists iff an admin/setup password is set)
#   Layer 4  UNKNOWN — the layer records WHY in BIOS_DETECTION_METHOD (which
#            interfaces were present, and whether any could not be read), so an
#            operator can tell "this firmware publishes no password state" from
#            "a source existed but we could not read it". Both used to render as
#            the same bare amber "Unknown".
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
# Resolve the firmware_attributes class directory. Kernel 6.18+ registers the
# class as "firmware-attributes" (hyphen); older kernels used
# "firmware_attributes" (underscore). Prefer whichever exists. An explicitly
# set BIOS_FA_ROOT (tests use a fake tree) always wins; the base directory is
# overridable for tests via BIOS_FA_CLASS_BASE.
BIOS_FA_CLASS_BASE="${BIOS_FA_CLASS_BASE:-/sys/class}"
if [[ -z "${BIOS_FA_ROOT:-}" ]]; then
    if [[ -d "$BIOS_FA_CLASS_BASE/firmware-attributes" ]]; then
        BIOS_FA_ROOT="$BIOS_FA_CLASS_BASE/firmware-attributes"
    else
        BIOS_FA_ROOT="$BIOS_FA_CLASS_BASE/firmware_attributes"
    fi
fi
BIOS_HP_WMI_FILE="${BIOS_HP_WMI_FILE:-/sys/devices/platform/hp-wmi/bios_password}"
BIOS_TP_ACPI_FILE="${BIOS_TP_ACPI_FILE:-/sys/devices/platform/thinkpad_acpi/pws_setting}"
BIOS_DMIDECODE_CMD="${BIOS_DMIDECODE_CMD:-dmidecode}"
# Layer 3b reads a UEFI variable. The appliance mounts efivarfs read-only in
# system::gather_info(), which runs BEFORE bios::detect() (10_main.sh), so the
# variables are already visible here; tests point this at a fake tree.
BIOS_EFIVARS_ROOT="${BIOS_EFIVARS_ROOT:-${EFIVARS_BASE:-/sys/firmware/efi/efivars}}"

# --- state --------------------------------------------------------------------
BIOS_PASSWORD_STATUS="UNKNOWN"   # LOCKED / UNLOCKED / UNKNOWN
BIOS_DETECTION_METHOD="NONE"     # human-readable source of the verdict

# Layer 4 explanation state. BIOS_SRC_SEEN lists the password interfaces that
# were actually PRESENT (whether or not they produced a verdict);
# BIOS_SRC_UNREADABLE records the first source that refused to be read — which is
# a different answer from "this firmware publishes nothing", and must not be
# reported as if it were.
BIOS_SRC_SEEN=""
BIOS_SRC_UNREADABLE=""

# Note that a password interface was present. Deduped, order preserved.
bios::_saw() {
    case ";$BIOS_SRC_SEEN;" in
        *";$1;"*) return 0 ;;
    esac
    BIOS_SRC_SEEN="${BIOS_SRC_SEEN:+$BIOS_SRC_SEEN, }$1"
}

# The human reason for an UNKNOWN verdict.
bios::_unknown_method() {
    local msg=""
    if [[ -n "$BIOS_SRC_SEEN" ]]; then
        msg="no password state published; sources present: $BIOS_SRC_SEEN"
        [[ -n "$BIOS_SRC_UNREADABLE" ]] && msg="$msg; $BIOS_SRC_UNREADABLE"
    elif [[ -n "$BIOS_SRC_UNREADABLE" ]]; then
        msg="no verdict; $BIOS_SRC_UNREADABLE"
    else
        msg="no password interface published"
    fi
    printf 'NONE (%s)' "$msg"
}

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
        bios::_saw "firmware-attributes"
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
        bios::_saw "legacy hp-wmi"
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
        bios::_saw "legacy thinkpad_acpi"
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
    local out line val label evidence=0 saw_record=0
    command -v "$BIOS_DMIDECODE_CMD" >/dev/null 2>&1 || {
        BIOS_SRC_UNREADABLE="${BIOS_SRC_UNREADABLE:-dmidecode is not installed}"
        return 0
    }
    # A non-zero exit means dmidecode REFUSED (normally: not running as root),
    # which is a different answer from "this firmware publishes no Type 24" — do
    # not report one as the other.
    if ! out="$("$BIOS_DMIDECODE_CMD" -t 24 2>/dev/null)"; then
        BIOS_SRC_UNREADABLE="${BIOS_SRC_UNREADABLE:-dmidecode could not read SMBIOS}"
        return 0
    fi
    # NOTE: deliberately NOT guarded by `[[ -n "$out" ]]` (the previous code
    # was). dmidecode exits 0 AND prints a 3-line header even when the requested
    # type does not exist — measured on a firmware with no Type 24 record: 67
    # bytes, 0 "Password Status" lines. The record's existence can only be told
    # from a Password Status line, which is what sets saw_record below.

    while IFS= read -r line; do
        [[ "$line" == *"Password Status"* ]] || continue
        saw_record=1
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

    if (( saw_record )); then
        bios::_saw "SMBIOS Type 24"
    fi

    if [[ $evidence -eq 1 ]]; then
        BIOS_PASSWORD_STATUS="UNLOCKED"
        BIOS_DETECTION_METHOD="SMBIOS Type 24 (no password enabled)"
        return 2
    fi
    return 0
}

# ------------------------------------------------------------------------------
# Layer 3b — UEFI variable UserCred (HP business EliteBook/ZBook, 2018-era+)
# ------------------------------------------------------------------------------
# Found on 2026-10-10 by diffing two IDENTICAL EliteBook 830 G5 units — one with
# an admin password, one without — across their entire UEFI variable set. See
# research/bios-unlock/20-insyde-setup-password-state.md §4.
#
#   UserCred-f66687ff-8cf3-4a19-b4ac-f5f0b78e4d18
#     LOCKED   : 07 00 00 00 | 02 00 00 00 … 41 00 64 00 6d 00 69 00 6e 00 50 …
#                                             └─ "AdminPW" in UTF-16LE
#     UNLOCKED : 07 00 00 00 | 05 00 00 00 … ff ff ff ff …  (unset sentinel)
#
# The firmware keeps a credential RECORD named "AdminPW" only while an
# admin/setup password is set; clearing the password drops the name and leaves
# the 0xFFFFFFFF unset mark. Correlated 4/4 against SMBIOS Type 24 on hardware.
#
# NOTE the variable SIZE is NOT the signal: a locked ZBook and an unlocked
# 830 G5 share the same 4548 B, while a locked 820 G3 and a locked 830 G5 share
# 3540 B. Only the record matters.
#
# ⚠ Record-PRESENCE only — the password itself is never read (an opaque digest
#   follows the name and is deliberately not decoded).
# ⚠ HP-business only: the consumer Insyde machine (.170) has no UserCred
#   variable at all, so this does NOT resolve the UNKNOWN class. It corroborates
#   machines Type 24 already covers, and covers HP business firmware that omits
#   the Type 24 table.
BIOS_USERCRED_VAR="UserCred"
# "AdminPW" as UTF-16LE hex (matches `od -An -tx1` output, lower case).
BIOS_USERCRED_ADMINPW_HEX="410064006d0069006e00500057"

# Return: 1 = LOCKED, 0 = no signal. Deliberately never claims UNLOCKED —
# absence of the record is not yet validated widely enough to assert the
# negative (see the note in the research doc).
bios::probe_efivars() {
    local f="" hx=""
    for f in "$BIOS_EFIVARS_ROOT/$BIOS_USERCRED_VAR"-*; do
        [[ -r "$f" ]] || continue
        bios::_saw "UEFI UserCred"
        hx="$(od -An -tx1 -v "$f" 2>/dev/null | tr -d ' \n')"
        [[ -n "$hx" ]] || continue
        case "$hx" in
            *"$BIOS_USERCRED_ADMINPW_HEX"*)
                BIOS_PASSWORD_STATUS="LOCKED"
                BIOS_DETECTION_METHOD="UEFI var UserCred (AdminPW credential record)"
                return 1 ;;
        esac
    done
    return 0
}

# ------------------------------------------------------------------------------
# Orchestrator — cascade with "LOCKED wins, then UNLOCKED evidence, else UNKNOWN"
# ------------------------------------------------------------------------------
bios::detect() {
    local rc
    BIOS_PASSWORD_STATUS="UNKNOWN"
    BIOS_DETECTION_METHOD="NONE"
    BIOS_SRC_SEEN=""
    BIOS_SRC_UNREADABLE=""

    bios::probe_sysfs; rc=$?
    [[ $rc -eq 1 ]] && return 1

    bios::probe_legacy; rc=$?
    [[ $rc -eq 1 ]] && return 1

    bios::probe_smbios; rc=$?
    [[ $rc -eq 1 ]] && return 1

    # Layer 3b runs LAST on purpose: it is a corroborating source that only
    # speaks when everything above is silent, so it can never contradict a
    # verdict the stronger layers already reached.
    bios::probe_efivars; rc=$?
    [[ $rc -eq 1 ]] && return 1

    # Nothing produced a verdict: say WHY rather than leaving a bare "Unknown".
    # The probes set UNLOCKED + method themselves when they found positive "not
    # set" evidence, so only a still-UNKNOWN verdict needs explaining.
    if [[ "$BIOS_PASSWORD_STATUS" == "UNKNOWN" ]]; then
        BIOS_DETECTION_METHOD="$(bios::_unknown_method)"
    fi
    return 0
}
