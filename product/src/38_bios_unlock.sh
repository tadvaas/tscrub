# =============================================================================
# BIOS UNLOCK (remote clear) — clear the BIOS admin/setup password for a
# password staged in the dashboard.
#
# This file owns the CLEAR. The poll/claim/report transport is the unified
# remote-command worker in 42_remote.sh (one queue for every remote command),
# which decodes the claimed password and calls bios_unlock::clear.
#
# The kernel's firmware-attributes sysfs model is the write surface. Two vendor
# quirks are handled here:
#   * Password objects sit under <driver>/attributes/ on Dell/Lenovo but under
#     <driver>/authentication/ on HP (hp-bioscfg), so BOTH are scanned.
#   * hp-bioscfg and dell-wmi-sysman are linked BEFORE firmware_attributes_class.o
#     in drivers/platform/x86/Makefile yet use the same initcall level, so their
#     class device is created before the class is registered and is left
#     orphaned at /sys/devices/<driver> (no /sys/class/firmware-attributes
#     symlink) — the class directory then looks empty even though the interface
#     exists. Such orphaned trees are scanned too (BIOS_FA_ORPHAN_GLOB).
# =============================================================================

# Glob(s) scanned for firmware-attribute trees the kernel never published under
# the firmware-attributes class (see the header note). Override for tests.
BIOS_FA_ORPHAN_GLOB="${BIOS_FA_ORPHAN_GLOB:-/sys/devices/*}"

# Enumerate firmware-attribute *device* directories to inspect. Two layouts are
# covered:
#   * the kernel class — <BIOS_FA_ROOT>/<driver>/ (e.g.
#     /sys/class/firmware-attributes/hp-bioscfg/)
#   * an orphaned device tree from the initcall-ordering bug above
#     (BIOS_FA_ORPHAN_GLOB matches each such device dir directly).
# BIOS_FA_DEVICES (space-separated) overrides both for tests. One dir per line.
bios_unlock::_fa_devices() {
    local d
    if [[ -n "${BIOS_FA_DEVICES:-}" ]]; then
        printf '%s\n' $BIOS_FA_DEVICES
        return 0
    fi
    if [[ -n "${BIOS_FA_ROOT:-}" && -d "$BIOS_FA_ROOT" ]]; then
        for d in "$BIOS_FA_ROOT"/*/; do
            if [[ -d "${d}attributes" || -d "${d}authentication" ]]; then
                printf '%s\n' "${d%/}"
            fi
        done
    fi
    for d in ${BIOS_FA_ORPHAN_GLOB}; do
        if [[ -d "$d/attributes" || -d "$d/authentication" ]]; then
            printf '%s\n' "$d"
        fi
    done
    return 0
}

# Write a value to a firmware-attributes file, capturing the shell's own error
# text in BIOS_UNLOCK_WRITE_ERR. The text distinguishes the two failure modes:
#   "<shell>: <path>: <reason>"              the open() failed — the attribute is
#                                            not writable (read-only/permission)
#   "<shell>: printf: write error: <reason>" the driver/firmware REJECTED the
#                                            value (this is the informative one)
# Returns 1 on failure.
bios_unlock::_write_attr() {
    local f="$1" v="$2" err
    err="$( { printf '%s' "$v" > "$f"; } 2>&1 )" || {
        BIOS_UNLOCK_WRITE_ERR="${err:-write failed}"
        return 1
    }
    BIOS_UNLOCK_WRITE_ERR=""
    return 0
}

# Turn a captured shell error into a reason an operator can act on. Only a
# "write error" means the firmware evaluated the value; the errno then says why.
bios_unlock::_write_error_reason() {
    local e="$1"
    case "$e" in
        *"write error"*)
            case "$e" in
                *"Permission denied"*)
                    printf 'wrong password — the firmware rejected the current password' ;;
                *"Invalid argument"*)
                    printf 'rejected — the value does not meet the firmware password policy' ;;
                *"Operation not supported"*)
                    printf 'this firmware/driver does not support setting or clearing BIOS passwords' ;;
                *"Operation not permitted"*)
                    printf 'not permitted — root / CAP_SYS_ADMIN is required' ;;
                *"Read-only file system"*)
                    printf 'the password attribute is read-only' ;;
                *)
                    printf 'the firmware reported a failure (%s)' "${e##*: }" ;;
            esac ;;
        *"Permission denied"*)
            printf 'the password attribute is not writable' ;;
        *"No such file"*)
            printf 'the password attribute is missing' ;;
        *)
            printf 'could not write the password attribute (%s)' "${e##*: }" ;;
    esac
}

# Write the current password, then a blank new password, to a firmware
# attributes admin-password attribute dir. Returns 0 when the writes were
# ACCEPTED — which is not a claim that the password changed; re-read to confirm.
#
# The "blank" value is a single newline, not an empty string: a 0-byte write can
# be dropped before it reaches the driver's store, and every driver strips a
# trailing newline (so "\n" == "no new password" == clear).
bios_unlock::_write_clear() {
    local base="$1" unlock_pwd="$2"
    [[ -f "$base/current_password" && -f "$base/new_password" ]] || return 1
    bios_unlock::_write_attr "$base/current_password" "$unlock_pwd" || return 1
    bios_unlock::_write_attr "$base/new_password" $'\n' || return 1
    return 0
}

# Slot priority: setup/admin password (2) > power-on/system password (1) >
# any other password-named attribute (0).
bios_unlock::_slot_priority() {
    local name
    name="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
    case "$name" in
        *admin*|*setup*) printf '2' ;;
        *system*|*power*|*boot*) printf '1' ;;
        *) printf '0' ;;
    esac
}

# Re-read a cleared slot to confirm the password actually went away. Returns 0
# when cleared (or when there is no re-read signal — some drivers expose none),
# 1 when the slot still reads as set. Drivers differ: Dell/Lenovo expose
# is_password_set, HP exposes is_enabled, and a few only expose current_value.
bios_unlock::_verify_cleared() {
    local base="$1" v f
    for f in is_password_set is_enabled; do
        [[ -f "$base/$f" ]] || continue
        v="$(bios::_read "$base/$f")" || return 0
        bios::_truthy "$v" && return 1
        return 0
    done
    if [[ -f "$base/current_value" ]]; then
        v="$(bios::_read "$base/current_value")" || return 0
        [[ -z "$v" ]] && return 0
        bios::_truthy "$v" && return 1
        return 0
    fi
    return 0
}

# =============================================================================
# Layer 3 — HP WMI clear
# =============================================================================
#
# HP machines (2016+ through at least the 2021 Fury G8) publish NO password
# object through firmware-attributes, so Layers 1-2 above always come up empty
# on them. The firmware's SetBiosSetting WMI method nevertheless accepts a plain
# three-element frame, and that is exactly what Windows uses:
#
#     [Setup Password] [<utf-16/>] [<utf-16/>CURRENT_PASSWORD]
#        name           new value    current password
#
# Verified on hardware 2026-10-10 on an EliteBook 830 G5; see
# research/bios-unlock/18-live-wmi-probing-results.md (the clear, confirmed by
# reboot and by SMBIOS Type 24) and 19-shipping-plan-wmi-clear.md (this plan).
#
# A transport is needed because 6.18 exposes no userspace WMI invocation path,
# so the narrow hp_biospw module carries the frame (hardcoded GUID/method id,
# the password never logged).

BIOS_WMI_GUID="${BIOS_WMI_GUID:-1F4C91EB-DC5C-460b-951D-C7CB9B4B8D5E}"
BIOS_WMI_PW_SLOT="${BIOS_WMI_PW_SLOT:-Setup Password}"
BIOS_WMI_MODULE="${BIOS_WMI_MODULE:-/lib/modules/$(uname -r)/extra/hp_biospw.ko}"
BIOS_WMI_PROC="${BIOS_WMI_PROC:-/proc/hp_biospw}"
# A setting that is refused without administrator rights while a password is
# set, and accepted once it is gone. This is the corroboration probe.
BIOS_WMI_PROBE_SETTING="${BIOS_WMI_PROBE_SETTING:-Ownership Tag}"
BIOS_WMI_DEVICE_DIR="${BIOS_WMI_DEVICE_DIR:-/sys/bus/wmi/devices}"

# Does this machine offer the HP BIOS-settings WMI block at all?
#
# Match on the FIRST GUID field, case-insensitively, and do not repeat the whole
# GUID. Two traps have already bitten here:
#   * the kernel renders the GUID with UPPERCASE hex (…-DC5C-460B-…) while the
#     driver's #define spells digits lowercase (…-DC5C-460b-…);
#   * v1.11.46 shipped a GUID with a hand-typed typo in the LAST field
#     (…C7CB9B4D8D5E instead of …C7CB9B4B8D5E), copied from a line-wrapped read.
# Together they made this match fail on every HP machine, silently skipping the
# whole layer — which let the sysfs path below report a false 'cleared' while the
# BIOS still demanded the password. A short, stable prefix avoids both. The
# module keeps the driver's exact GUID (it must: the kernel matches it exactly);
# this function only answers "is the interface here".
bios_unlock::_wmi_applicable() {
    ls "$BIOS_WMI_DEVICE_DIR" 2>/dev/null | grep -qi "^${BIOS_WMI_GUID%%-*}-"
}

# Make /proc/hp_biospw available. A caller override implies availability (tests).
bios_unlock::_wmi_available() {
    [[ -n "${BIOS_WMI_CALL_CMD:-}" ]] && return 0
    [[ -e "$BIOS_WMI_PROC" ]] && return 0
    [[ -f "$BIOS_WMI_MODULE" ]] || return 1
    command -v insmod >/dev/null 2>&1 || return 1
    insmod "$BIOS_WMI_MODULE" 2>/dev/null || return 1
    [[ -e "$BIOS_WMI_PROC" ]]
}

# Send one frame and print the firmware's status token ("0x06"), or nothing on a
# transport failure. Fields are NUL-separated; the optional 4th argument means
# "this frame has a credential element", so a 3-field write is used (an empty
# credential is still an element — encoding it as absent is what makes the
# firmware answer 0x04).
#
# The credential must already carry the "<utf-16/>" encoding prefix; so must an
# empty new value. Callers do that, not this function.
#
# Test seam: with BIOS_WMI_CALL_CMD set, that command is invoked with the same
# arguments and prints the status token itself (no proc file involved).
bios_unlock::_wmi_call() {
    local name="$1" value="$2" cred="$3" want_cred="$4" out

    if [[ -n "${BIOS_WMI_CALL_CMD:-}" ]]; then
        if [[ -n "$want_cred" ]]; then
            "$BIOS_WMI_CALL_CMD" "$name" "$value" "$cred" || return 1
        else
            "$BIOS_WMI_CALL_CMD" "$name" "$value" || return 1
        fi
        return 0
    fi

    if [[ -n "$want_cred" ]]; then
        { printf '%s\000%s\000%s' "$name" "$value" "$cred" > "$BIOS_WMI_PROC"; } 2>/dev/null || return 1
    else
        { printf '%s\000%s' "$name" "$value" > "$BIOS_WMI_PROC"; } 2>/dev/null || return 1
    fi
    out="$(cat "$BIOS_WMI_PROC" 2>/dev/null)" || return 1
    case "$out" in
        "status 0x"*) printf '%s' "${out#status }" ;;
        *) return 1 ;;
    esac
}

# Status of a no-credential write to the probe setting. "0x06" while an
# administrator password is set, "0x00" once it is gone.
bios_unlock::_wmi_probe() {
    local base="/sys/class/firmware-attributes/hp-bioscfg/attributes/$BIOS_WMI_PROBE_SETTING"
    local cur=""
    cur="$(bios::_read "$base/current_value")" || cur=""
    bios_unlock::_wmi_call "$BIOS_WMI_PROBE_SETTING" "$cur" "" ""
}

# Attempt the clear. Returns 0 cleared, 1 attempted-but-failed (detail set),
# 2 not applicable (caller should fall through to its generic message).
bios_unlock::_wmi_clear() {
    local unlock_pwd="$1" before status after repeat

    bios_unlock::_wmi_applicable || return 2
    if ! bios_unlock::_wmi_available; then
        # Fall through (2) rather than failing outright: the sysfs path below
        # still deserves its chance, and if it also finds nothing the generic
        # message explains the interface state.
        BIOS_UNLOCK_DETAIL="HP WMI: the BIOS-settings interface is present but the hp_biospw transport could not be loaded (${BIOS_WMI_MODULE})"
        return 2
    fi

    before="$(bios_unlock::_wmi_probe)" || before=""
    if [[ "$before" == "0x00" ]]; then
        # Nothing is refused, so there is no administrator password to remove.
        rmmod hp_biospw 2>/dev/null
        BIOS_UNLOCK_RESULT="cleared"
        BIOS_UNLOCK_DETAIL="HP WMI: no administrator password was set — a privileged setting write was already accepted (${BIOS_WMI_PROBE_SETTING})"
        return 0
    fi

    status="$(bios_unlock::_wmi_call "$BIOS_WMI_PW_SLOT" "<utf-16/>" \
                                      "<utf-16/>$unlock_pwd" 1)" || status=""

    case "$status" in
        0x00) ;;                                  # accepted — corroborate below
        0x06)
            rmmod hp_biospw 2>/dev/null
            BIOS_UNLOCK_RESULT="failed"
            BIOS_UNLOCK_DETAIL="HP WMI: wrong password — the firmware refused the current password for '${BIOS_WMI_PW_SLOT}' (status 0x06)"
            return 1 ;;
        0x05)
            rmmod hp_biospw 2>/dev/null
            BIOS_UNLOCK_RESULT="failed"
            BIOS_UNLOCK_DETAIL="HP WMI: the firmware rejected the request for '${BIOS_WMI_PW_SLOT}' as an invalid value (status 0x05) — it is usually returned when no administrator password is set; confirm the password and the slot name"
            return 1 ;;
        0x04)
            rmmod hp_biospw 2>/dev/null
            BIOS_UNLOCK_RESULT="failed"
            BIOS_UNLOCK_DETAIL="HP WMI: the firmware does not recognise the setting '${BIOS_WMI_PW_SLOT}' (status 0x04)"
            return 1 ;;
        "")
            rmmod hp_biospw 2>/dev/null
            BIOS_UNLOCK_RESULT="failed"
            BIOS_UNLOCK_DETAIL="HP WMI: the call did not complete (no status returned by the transport)"
            return 1 ;;
        *)
            rmmod hp_biospw 2>/dev/null
            BIOS_UNLOCK_RESULT="failed"
            BIOS_UNLOCK_DETAIL="HP WMI: the firmware answered an unexpected status ${status} for '${BIOS_WMI_PW_SLOT}'"
            return 1 ;;
    esac

    # Corroborate. A 0x00 on the clear frame means only that the firmware
    # processed the request — measured on hardware, a 0x00 can be returned for a
    # write that changes nothing. So require an independent signal: the probe
    # write that was refused a moment ago must now be accepted, or (weaker) the
    # same clear frame sent again must stop being accepted because its
    # credential no longer authenticates against anything.
    # The second attempt (below) is only sent when the probe did not
    # corroborate: each password attempt counts once as far as HP's lockout
    # mode is concerned (research/bios-unlock/15-…), so never send one needlessly.
    after="$(bios_unlock::_wmi_probe)" || after=""
    if [[ "$after" == "0x00" ]]; then
        rmmod hpbiospw 2>/dev/null
        BIOS_UNLOCK_RESULT="cleared"
        BIOS_UNLOCK_DETAIL="HP WMI: the firmware accepted the clear for '${BIOS_WMI_PW_SLOT}' and a privileged setting write that was refused beforehand is now accepted (confirm with 'dmidecode -t 24' after the next boot)"
        return 0
    fi

    repeat="$(bios_unlock::_wmi_call "$BIOS_WMI_PW_SLOT" "<utf-16/>" \
                                      "<utf-16/>$unlock_pwd" 1)" || repeat=""
    rmmod hpbiospw 2>/dev/null

    if [[ "$repeat" == "0x05" ]]; then
        BIOS_UNLOCK_RESULT="cleared"
        BIOS_UNLOCK_DETAIL="HP WMI: the firmware accepted the clear for '${BIOS_WMI_PW_SLOT}' and the same request is no longer accepted because its password no longer authenticates (confirm with 'dmidecode -t 24' after the next boot)"
        return 0
    fi

    BIOS_UNLOCK_RESULT="failed"
    BIOS_UNLOCK_DETAIL="HP WMI: the firmware accepted the clear for '${BIOS_WMI_PW_SLOT}' (status 0x00) but a privileged setting write is still refused, so the password could not be confirmed as removed — check the password and confirm with 'dmidecode -t 24' after the next boot"
    return 1
}

# Clear the BIOS admin/setup password. Sets BIOS_UNLOCK_RESULT (cleared|failed|
# unsupported) and BIOS_UNLOCK_DETAIL (source or error). Returns 0 on cleared.
bios_unlock::clear() {
    local unlock_pwd="$1" hp_new dev sub attr name base
    local prio best_prio=-1 best_base="" best_name="" best_rel=""
    local devs_seen=0 ro_dirs=0
    BIOS_UNLOCK_RESULT="unsupported"
    BIOS_UNLOCK_DETAIL="no writable BIOS password interface found"
    BIOS_UNLOCK_WRITE_ERR=""

    # Layer 3 — HP WMI — runs FIRST, before the sysfs scan, when this firmware
    # has the BIOS-settings WMI block. It is the only path that actually works
    # there, and the sysfs password path must not be trusted on such machines:
    # measured live on an EliteBook 830 G5 (2026-10-10), writing
    # authentication/<slot>/current_password + new_password returned success and
    # the verification (is_enabled) said cleared while the BIOS still demanded
    # the password — the driver caches attribute values from probe time, so a
    # password set out of band makes that verdict a false positive. Returns 2
    # when this is not such a machine (or has no transport), so the sysfs scan
    # below still runs.
    bios_unlock::_wmi_clear "$unlock_pwd"
    case $? in
        0) return 0 ;;   # cleared — result and detail are set, corroborated
        1) return 1 ;;   # attempted and failed — the detail is specific
    esac

    # Layer 1 — firmware-attributes sysfs (Dell dell-wmi-sysman, Lenovo
    # think_lmi, HP hp-bioscfg, …). Password objects live under attributes/ on
    # Dell/Lenovo but under authentication/ on HP, so scan both. Prefer the
    # setup/admin slot over power-on/system — the wipe should not clear a
    # power-on password the operator didn't ask for.
    while IFS= read -r dev; do
        devs_seen=$((devs_seen + 1))
        for sub in attributes authentication; do
            for attr in "$dev/$sub"/*/; do
                base="${attr%/}"
                [[ -d "$base" ]] || continue
                name="$(basename "$base")"
                if bios::_password_name "$name" && ! bios::_password_policy_name "$name"; then
                    if [[ -f "$base/current_password" && -f "$base/new_password" ]]; then
                        prio="$(bios_unlock::_slot_priority "$name")"
                        if (( prio > best_prio )); then
                            best_prio="$prio"
                            best_base="$base"
                            best_name="$name"
                            best_rel="$(basename "$dev")/${base#"$dev"/}"
                        fi
                    elif [[ -f "$base/role" ]]; then
                        # A password object with no write path — HP exposes its
                        # authentication objects only (no password reset).
                        ro_dirs=$((ro_dirs + 1))
                    fi
                fi
            done
        done
    done < <(bios_unlock::_fa_devices)

    if [[ -n "$best_base" ]]; then
        if bios_unlock::_write_clear "$best_base" "$unlock_pwd"; then
            # The writes were accepted — confirm the password actually went away.
            if bios_unlock::_verify_cleared "$best_base"; then
                # Do NOT trust this verdict if we can ask the firmware directly:
                # those attributes are cached by the driver from probe time, so
                # this reports success while the BIOS still demands a password —
                # measured live twice, most recently on a shipped v1.11.46 image
                # where the WMI layer was skipped and this path claimed 'cleared'
                # with the password demonstrably still set.
                #
                # Deliberately NOT gated on _wmi_applicable: when that gate was
                # wrong (the case-sensitivity bug) it was exactly this path that
                # over-claimed. On a machine without the HP interface the probe
                # answers nothing, so this is skipped anyway.
                if bios_unlock::_wmi_available; then
                    local confirm
                    confirm="$(bios_unlock::_wmi_probe)"
                    rmmod hpbiospw 2>/dev/null
                    if [[ -n "$confirm" && "$confirm" != "0x00" ]]; then
                        BIOS_UNLOCK_RESULT="failed"
                        BIOS_UNLOCK_DETAIL="the sysfs password attributes reported the password cleared (${best_rel}) but the firmware still requires it (privileged write refused, status ${confirm}) — the sysfs verdict is not trustworthy on this firmware"
                        return 1
                    fi
                fi
                BIOS_UNLOCK_RESULT="cleared"
                BIOS_UNLOCK_DETAIL="sysfs: ${best_rel}"
                return 0
            fi
            BIOS_UNLOCK_RESULT="failed"
            BIOS_UNLOCK_DETAIL="password still set after clear — the writes were accepted (nothing was rejected) but the password is unchanged, so this firmware exposes no clear path from Linux and the password cannot be validated here (sysfs: ${best_rel})"
            return 1
        fi
        BIOS_UNLOCK_RESULT="failed"
        BIOS_UNLOCK_DETAIL="$(bios_unlock::_write_error_reason "$BIOS_UNLOCK_WRITE_ERR") (sysfs: ${best_rel})"
        return 1
    fi

    # Layer 2 — HP legacy hp-wmi node (no re-read signal; trust the write).
    hp_new="${BIOS_HP_WMI_FILE%/*}/new_bios_password"
    if [[ -f "$BIOS_HP_WMI_FILE" && -f "$hp_new" ]]; then
        if printf '%s' "$unlock_pwd" > "$BIOS_HP_WMI_FILE" 2>/dev/null \
           && printf '' > "$hp_new" 2>/dev/null; then
            BIOS_UNLOCK_RESULT="cleared"
            BIOS_UNLOCK_DETAIL="legacy: hp-wmi bios_password"
            return 0
        fi
        BIOS_UNLOCK_RESULT="failed"
        BIOS_UNLOCK_DETAIL="write failed (hp-wmi)"
        return 1
    fi

    # (The HP WMI clear used to live here. It now runs first, at the top of this
    # function, because the sysfs password path above cannot be trusted on the
    # firmware that has that interface.)

    # Nothing writable — say why as precisely as we can: a read-only interface
    # is a different problem from the kernel publishing no interface at all.
    if [[ $ro_dirs -gt 0 ]]; then
        BIOS_UNLOCK_DETAIL="BIOS password interface is read-only (${ro_dirs} password object(s) with no write path) — cannot be cleared from Linux"
    elif [[ $devs_seen -eq 0 ]]; then
        BIOS_UNLOCK_DETAIL="no firmware-attributes device published by the kernel (class dir empty, no orphaned tree matching ${BIOS_FA_ORPHAN_GLOB}) — no BIOS password interface to write to"
    fi
    return 1
}
