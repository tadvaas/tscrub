# =============================================================================
# HP REPLICATED SETUP (hp-repsetup) — fallback BIOS password surface for HP
# machines whose firmware publishes no hp-bioscfg password surface (Z840 class).
#
# The kernel's firmware-attributes path (36_bios.sh Layer 1, 38_bios_unlock.sh
# Layer 1) exposes nothing on pre-2018 HP business firmware, so detection
# reports UNKNOWN and the clear finds no writable interface. HP's own
# Replicated Setup utility (softpaq sp171387, v3.26) speaks the BIOS's native
# interface through the small hpuefi kernel module and DOES work there —
# verified live on the Z840 bench (2026-11-07), including a full password
# round-trip (set → reboot → present; wrong password rejected; clear → reboot
# → absent). Plan: research/hp-repsetup-integration/README.md.
#
# Verified semantics that shape this module (all on the Z840, BIOS M60 2.61):
#   * `-g -a` prints the settings dump in ASCII. Each option's ACTIVE value is
#     the value line marked with a leading `*`. Reading needs NO password even
#     when one is set (identity fields are denied either way — expected).
#   * `-n <pw>` sets, `-p <cur> -n /` clears. Both print "Admin password
#     updated successfully." on acceptance, "(Access Denied)" when the current
#     password is wrong or missing — and the change is STAGED: the dump keeps
#     showing the OLD value until the NEXT BOOT. Any "did the clear take?"
#     check must therefore run post-boot; 36_bios.sh re-runs detection every
#     boot, which is the natural verification loop.
#   * the module does NOT create /dev/hpuefi — mknod is required (Buildroot
#     ships no udev).
#   * `-cspwdfile` does NOT accept a plaintext file ("Access Denied") — it
#     wants BCU's encrypted file format, which cannot be produced on the
#     appliance. The clear therefore uses `-p` on argv. The password is a
#     shell variable either way (the remote flow decodes it), the tool run is
#     short-lived, and the appliance is single-operator — accepted, recorded,
#     and the password is never logged or written to disk.
#
# Policy: known-password only. This module never attempts a clear without the
# operator's password and has no lost-password path (jumper/SPI stay in
# research/bios-unlock/ — deliberately not product behaviour).
# =============================================================================

HP_REPSETUP_BIN="${HP_REPSETUP_BIN:-/opt/tscrub/bin/hp-repsetup}"
HP_REPSETUP_KO="${HP_REPSETUP_KO:-/lib/modules/$(uname -r)/extra/hpuefi.ko}"
HP_REPSETUP_DEV="${HP_REPSETUP_DEV:-/dev/hpuefi}"
# Vendor gate: only HP firmware answers this interface. An explicit override
# (tests) wins; otherwise dmidecode is asked.
HP_REPSETUP_VENDOR="${HP_REPSETUP_VENDOR:-}"

# Is the machine an HP? The interface is HP-proprietary; never probe it
# elsewhere (a wrong-vendor probe is noise at best).
hp_repsetup::_is_hp() {
    local m=""
    if [[ -n "$HP_REPSETUP_VENDOR" ]]; then
        m="$HP_REPSETUP_VENDOR"
    else
        m="$(dmidecode -s system-manufacturer 2>/dev/null || true)"
    fi
    case "$(printf '%s' "$m" | tr '[:upper:]' '[:lower:]')" in
        *hewlett*|*hp*) return 0 ;;
        *) return 1 ;;
    esac
}

# The tool is shipped (or test-injected) and the machine is HP.
hp_repsetup::applicable() {
    hp_repsetup::_is_hp || return 1
    [[ -x "$HP_REPSETUP_BIN" ]] || return 1
    return 0
}

# Load hpuefi and create /dev/hpuefi. The module registers a character device
# but never creates the node itself. With HP_REPSETUP_CALL_CMD set (tests) the
# seam implies availability and this is skipped.
hp_repsetup::_ensure_dev() {
    [[ -n "${HP_REPSETUP_CALL_CMD:-}" ]] && return 0
    [[ -e "$HP_REPSETUP_DEV" ]] && return 0
    [[ -f "$HP_REPSETUP_KO" ]] || return 1
    command -v insmod >/dev/null 2>&1 || return 1
    insmod "$HP_REPSETUP_KO" 2>/dev/null || return 1
    local major=""
    major="$(grep -i 'hpuefi' /proc/devices 2>/dev/null | awk '{print $1}')"
    [[ -n "$major" ]] || return 1
    mknod "$HP_REPSETUP_DEV" c "$major" 0 2>/dev/null || return 1
    [[ -e "$HP_REPSETUP_DEV" ]]
}

# Run the tool with the given arguments, printing its output. With
# HP_REPSETUP_CALL_CMD set (tests), that command is invoked with the same
# arguments instead — a bash function works here, since command substitution
# subshells inherit functions.
hp_repsetup::_run() {
    if [[ -n "${HP_REPSETUP_CALL_CMD:-}" ]]; then
        "$HP_REPSETUP_CALL_CMD" "$@"
        return $?
    fi
    hp_repsetup::_ensure_dev || return 1
    "$HP_REPSETUP_BIN" "$@"
}

# Active value of one option in an ASCII dump: after the option's name line,
# the value line marked with a leading `*`. Prints the bare token ("Yes",
# "No", …) or nothing. Value lines are indented, option names are not — an
# unindented line ends the block.
hp_repsetup::_parse_active() {  # <name> <dump>
    local name="$1" dump="$2" line found=0 stripped=""
    while IFS= read -r line; do
        if (( ! found )); then
            [[ "$line" == "$name" ]] && found=1
            continue
        fi
        stripped="$(printf '%s' "$line" | sed -E 's/^[[:space:]]+//')"
        [[ "$line" == "$stripped" ]] && return 1   # next option name
        case "$stripped" in
            "*"*) printf '%s' "${stripped#\*}" ; return 0 ;;
        esac
    done <<< "$dump"
    return 1
}

# ------------------------------------------------------------------------------
# Probe — detection layer for 36_bios.sh
# ------------------------------------------------------------------------------
# Return: 1 = LOCKED (authoritative), 2 = UNLOCKED evidence, 0 = no signal.
hp_repsetup::probe() {
    local dump admin pw labels=""
    hp_repsetup::applicable || return 0
    bios::_saw "hp-repsetup"
    dump="$(hp_repsetup::_run -g -a 2>/dev/null)" || {
        BIOS_SRC_UNREADABLE="${BIOS_SRC_UNREADABLE:-hp-repsetup could not read the BIOS settings}"
        return 0
    }
    admin="$(hp_repsetup::_parse_active "Admin Password Present" "$dump")"
    pw="$(hp_repsetup::_parse_active "Power-On Password Present" "$dump")"
    if [[ -z "$admin" && -z "$pw" ]]; then
        BIOS_SRC_UNREADABLE="${BIOS_SRC_UNREADABLE:-hp-repsetup: no password presence fields in the settings dump}"
        return 0
    fi
    bios::_truthy "$admin" && labels="Admin"
    bios::_truthy "$pw" && labels="${labels:+$labels, }Power-On"
    if [[ -n "$labels" ]]; then
        BIOS_PASSWORD_STATUS="LOCKED"
        BIOS_DETECTION_METHOD="hp-repsetup ($labels password present)"
        return 1
    fi
    BIOS_PASSWORD_STATUS="UNLOCKED"
    BIOS_DETECTION_METHOD="hp-repsetup (no BIOS password present)"
    return 2
}

# ------------------------------------------------------------------------------
# Clear — fallback arm for 38_bios_unlock.sh (known password only)
# ------------------------------------------------------------------------------
# Returns 0 accepted-and-staged, 1 attempted-but-failed (detail set), 2 not
# applicable. NOTE: success is STAGED until the next boot — the detail says so,
# and the post-boot detection run is the verification.
hp_repsetup::clear() {
    local unlock_pwd="$1" out rc
    hp_repsetup::applicable || return 2
    out="$(hp_repsetup::_run -p "$unlock_pwd" -n / 2>&1)"; rc=$?
    case "$out" in
        *"updated successfully"*)
            BIOS_UNLOCK_RESULT="cleared"
            BIOS_UNLOCK_DETAIL="hp-repsetup: the firmware accepted the clear — staged until the next boot; the next detection run confirms it"
            return 0 ;;
        *"Access Denied"*)
            BIOS_UNLOCK_RESULT="failed"
            BIOS_UNLOCK_DETAIL="hp-repsetup: wrong password — the firmware refused the current password"
            return 1 ;;
        *"Error opening"*)
            BIOS_UNLOCK_RESULT="failed"
            BIOS_UNLOCK_DETAIL="hp-repsetup: the BIOS settings interface could not be opened (${HP_REPSETUP_DEV})"
            return 1 ;;
    esac
    BIOS_UNLOCK_RESULT="failed"
    BIOS_UNLOCK_DETAIL="hp-repsetup: the clear did not complete (${out:-no output, rc $rc})"
    return 1
}
