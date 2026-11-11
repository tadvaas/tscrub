# =============================================================================
# DEVICE ATA
# =============================================================================

# Resolve HPA_MODE: CLI (--hpa) wins, then tscrub.conf (filled in
# config::load_usb), then the kernel cmdline. Default "on".
device::resolve_hpa_mode() {
    if [[ -z "${HPA_MODE:-}" && -r /proc/cmdline ]]; then
        local param
        param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_hpa=//p' | head -n 1)"
        case "$param" in
            on|off) HPA_MODE="$param" ;;
        esac
    fi
    case "${HPA_MODE:-}" in
        on|off) ;;
        *) HPA_MODE="on" ;;
    esac
}

# Best-effort HPA/DCO reset for an ATA drive, before the security password is
# set. Never aborts the erase on failure. Result vocabulary (see
# research/hpa-dco/README.md): removed | firmware-erased | none | failed | n/a.
device::reset_hpa_dco() {
    local dev="$1"
    local _hpa _dco native cap fw_covers=0
    local hpa_enabled=0 dco_present=0 hpa_gone=0 dco_gone=0

    device::resolve_hpa_mode
    [[ "${HPA_MODE:-on}" == "on" ]] || return 0
    [[ "${DRY_RUN:-0}" -eq 0 ]] || return 0
    [[ "${bus[$dev]}" == "SATA" || "${bus[$dev]}" == "ATA" ]] || return 0

    _hpa="$(hdparm -N /dev/$dev 2>/dev/null)"
    _dco="$(hdparm --dco-identify /dev/$dev 2>/dev/null)"

    [[ "$_dco" == *"DCO Revision"* ]] && dco_present=1
    [[ "$_hpa" == *"HPA is enabled"* ]] && hpa_enabled=1

    if [[ "$dco_present" -eq 0 && "$hpa_enabled" -eq 0 ]]; then
        devrow["$dev.hpa_result"]="none"
        devrow["$dev.dco_result"]="none"
        return 0
    fi

    # DCO first — it caps what HPA can be set to.
    if [[ "$dco_present" -eq 1 ]]; then
        hdparm --dco-restore /dev/$dev >&5 2>&5 || true
    fi

    # Native max = the value AFTER the '/' in "max sectors = X/Y".
    _hpa="$(hdparm -N /dev/$dev 2>/dev/null)"
    native="$(sed -n 's/.*max sectors[^/]*\/\([0-9][0-9]*\).*/\1/p' <<<"$_hpa" | head -n 1)"

    if [[ -n "$native" && "$native" =~ ^[0-9]+$ ]]; then
        hdparm -N p"$native" /dev/$dev >&5 2>&5 || true
    fi

    # Re-read to confirm (the honest before/after).
    [[ "$(hdparm -N /dev/$dev 2>/dev/null)" != *"HPA is enabled"* ]] && hpa_gone=1
    [[ "$(hdparm --dco-identify /dev/$dev 2>/dev/null)" != *"DCO Revision"* ]] && dco_gone=1

    cap="${devrow[$dev.capability]:-}"
    [[ "$cap" == "CAP_ATA_PURGE_ENHANCED" ]] && fw_covers=1

    # HPA result
    if [[ "$hpa_enabled" -eq 0 ]]; then
        devrow["$dev.hpa_result"]="none"
    elif [[ "$hpa_gone" -eq 1 ]]; then
        devrow["$dev.hpa_result"]="removed"
    elif [[ "$fw_covers" -eq 1 ]]; then
        devrow["$dev.hpa_result"]="firmware-erased"
    else
        devrow["$dev.hpa_result"]="failed"
    fi

    # DCO result
    if [[ "$dco_present" -eq 0 ]]; then
        devrow["$dev.dco_result"]="none"
    elif [[ "$dco_gone" -eq 1 ]]; then
        devrow["$dev.dco_result"]="removed"
    elif [[ "$fw_covers" -eq 1 ]]; then
        devrow["$dev.dco_result"]="firmware-erased"
    else
        devrow["$dev.dco_result"]="failed"
    fi

    if [[ "${devrow[$dev.hpa_result]}" == "failed" || "${devrow[$dev.dco_result]}" == "failed" ]]; then
        echo "$dev LOG HPA/DCO: could not remove hidden area - data may remain" >&3
    elif [[ "${devrow[$dev.hpa_result]}" == "removed" || "${devrow[$dev.dco_result]}" == "removed" ]]; then
        echo "$dev LOG HPA/DCO: hidden area removed" >&3
    fi
}

# Clear the temporary security password set for the erase so the drive is not
# left "security enabled" (which locks it on the next power cycle). Best-effort:
# the erase outcome is already decided, and a failed disable must never flip a
# completed erase to FAILED. Some firmwares end the erase in the "locked" state,
# so if a plain disable is rejected, unlock first and retry once.
device::ata_clear_password() {   # <dev> [pw] [quiet]
    local dev="$1" pw="${2:-p}" quiet="${3:-0}"
    # The operator-password path passes quiet=1: hdparm echoes the password, so
    # its output goes to /dev/null, never to fd 5 (the run log).
    if [[ "$quiet" == "1" ]]; then
        if ! hdparm --user-master u --security-disable "$pw" /dev/$dev >/dev/null 2>&1; then
            hdparm --user-master u --security-unlock "$pw" /dev/$dev >/dev/null 2>&1 || true
            hdparm --user-master u --security-disable "$pw" /dev/$dev >/dev/null 2>&1 || true
        fi
    else
        if ! hdparm --user-master u --security-disable "$pw" /dev/$dev >&5 2>&5; then
            hdparm --user-master u --security-unlock "$pw" /dev/$dev >&5 2>&5 || true
            hdparm --user-master u --security-disable "$pw" /dev/$dev >&5 2>&5 || true
        fi
    fi
}

# Unlock a LOCKED drive with the factory-blank master password. Attempted when
# identify reports master revision 65534 (0xFFFE = factory default, i.e. the
# master password is blank) OR omits the field entirely — some drives (e.g.
# Intel SSDs) stop reporting it once security is enabled, and the blank-master
# attempt is a clean abort on failure, so an absent field must not block it.
# Returns 0 when the drive is unlocked AND the temporary user password is set
# (the normal erase then runs); 1 otherwise.
device::ata_blank_master_unlock() {   # <dev>
    local dev="$1"
    local rev
    [[ -n "$dev" ]] || return 1
    rev="$(hdparm -I /dev/$dev 2>/dev/null | sed -n 's/^[[:space:]]*Master password revision code = \([0-9]*\).*/\1/p' | head -n1)"
    [[ -z "$rev" || "$rev" == "65534" ]] || return 1
    if hdparm --user-master m --security-unlock "" /dev/$dev >&5 2>&5 \
        && hdparm --user-master u --security-set-pass p /dev/$dev >&5 2>&5; then
        echo "$dev LOG unlocked via blank master password" >&3
        return 0
    fi
    return 1
}

# Erase a LOCKED drive directly with the blank master password — the Maximum
# security-level path (at Maximum the master cannot unlock, but SECURITY ERASE
# UNIT with it is allowed while locked, and a successful erase resets the user
# password, which is why the drive comes back usable). Safe on failure: the
# drive aborts the command untouched. Returns 0 when erased.
device::ata_blank_master_erase() {   # <dev> <cap>
    local dev="$1" cap="$2"
    local rev
    [[ -n "$dev" ]] || return 1
    rev="$(hdparm -I /dev/$dev 2>/dev/null | sed -n 's/^[[:space:]]*Master password revision code = \([0-9]*\).*/\1/p' | head -n1)"
    [[ -z "$rev" || "$rev" == "65534" ]] || return 1
    if [[ "$cap" == "CAP_ATA_PURGE_ENHANCED" ]] \
        && hdparm --user-master m --security-erase-enhanced "" /dev/$dev >&5 2>&5; then
        device::emit_status "$dev" COMPLETED
        echo "$dev LOG erased via blank master password (enhanced)" >&3
        return 0
    fi
    if hdparm --user-master m --security-erase "" /dev/$dev >&5 2>&5; then
        device::emit_status "$dev" COMPLETED
        echo "$dev LOG erased via blank master password" >&3
        return 0
    fi
    return 1
}

# Record HOW a locked/security-enabled drive was recovered. Written BOTH to the
# worker's devrow (the direct-call path tests use) AND to fd 3 as a
# "<dev> RECOVERY <value>" line, which ui::apply_outcome applies to the PARENT
# devrow — worker devrow writes do not survive the erasure worker, so the
# fd-3 line is the channel that actually reaches the report.
device::emit_recovery() {   # <dev> <value>
    local dev="$1" val="$2"
    devrow["$dev.recovery"]="$val"
    echo "$dev RECOVERY $val" >&3
}

# Same channel for the ATA lock classification (ata-password / ata-expired /
# ata-enabled): without the fd-3 line the parent's normalize_outcome cannot see
# it and the report falls back to the generic "Sanitisation failed" method.
device::emit_lockkind() {   # <dev> <value>
    local dev="$1" val="$2"
    devrow["$dev.lock_kind"]="$val"
    echo "$dev LOCKKIND $val" >&3
}

device::exec_ata() {
    local dev="$1"
    local cap="$2"
    local opw_unlocked=0 ata_pw="p" was_locked=0 was_enabled=0

    if [[ "${bus[$dev]}" != "SATA" && "${bus[$dev]}" != "ATA" ]]; then
        device::emit_status "$dev" FAILED
        echo "$dev LOG Unsupported transport '${bus[$dev]}' for ATA operation" >&3
        return
    fi

    case "$cap" in
        CAP_ATA_FROZEN)
            device::emit_status "$dev" FROZEN
            echo "$dev LOG Drive is frozen – power cycle or suspend required" >&3
            return
            ;;
    esac

    echo "$dev STATUS RUNNING" >&3
    echo "$dev LOG ATA operation started" >&3

    # Reset any HPA/DCO hidden area to native BEFORE setting the password, so
    # the erase covers the full native capacity (best-effort, never aborts).
    device::reset_hpa_dco "$dev"

    # A drive whose failed-attempt counter is exhausted rejects EVERY security
    # command — even correct passwords — until a power cycle. Detect it first so
    # the ladder below never burns attempts on a drive that cannot accept them.
    if hdparm -I /dev/$dev 2>/dev/null | grep -qE '^[[:space:]]+expired: security count[[:space:]]*$'; then
        device::emit_lockkind "$dev" ata-expired
        device::emit_status "$dev" FAILED
        echo "$dev LOG ATA security count expired - power-cycle the drive and retry" >&3
        return
    fi

    # An earlier tScrub run interrupted mid-erase (power loss, reboot) leaves
    # the drive security-enabled and LOCKED with our own temporary password.
    # Capture the pre-unlock security state so the report records HOW the drive
    # was recovered, then unlock our password first — best-effort: harmless on
    # a drive that was never locked, and it fails on a drive locked with a
    # password we do not know (handled below).
    if hdparm -I /dev/$dev 2>/dev/null | grep -qE '^[[:space:]]+locked[[:space:]]*$'; then
        was_locked=1
    fi
    if hdparm -I /dev/$dev 2>/dev/null | grep -qE '^[[:space:]]+enabled[[:space:]]*$'; then
        was_enabled=1
    fi
    hdparm --user-master u --security-unlock p /dev/$dev >&5 2>&5 || true
    if [[ "$was_locked" -eq 1 ]] \
        && ! hdparm -I /dev/$dev 2>/dev/null | grep -qE '^[[:space:]]+locked[[:space:]]*$'; then
        device::emit_recovery "$dev" temp-password
    fi

    # Still locked? The user password is not ours. Try the operator-supplied
    # password first (the customer knows it), then the factory-blank master
    # (revision 0xFFFE = 65534, or an absent field): at High level it unlocks
    # the drive, at Maximum level it may SECURITY ERASE UNIT directly while
    # locked. The ladder is strictly bounded (at most four failed security
    # commands), so one run can never reach the drive's five-attempt expiry.
    if hdparm -I /dev/$dev 2>/dev/null | grep -qE '^[[:space:]]+locked[[:space:]]*$'; then
        if [[ -n "${TS_ATA_PASSWORD:-}" ]]; then
            # hdparm echoes the password, so its output goes to /dev/null —
            # NEVER to fd 5 (the run log).
            if hdparm --user-master u --security-unlock "$TS_ATA_PASSWORD" /dev/$dev >/dev/null 2>&1; then
                opw_unlocked=1
                device::emit_recovery "$dev" operator-password
                echo "$dev LOG unlocked via operator password" >&3
            else
                echo "$dev LOG operator password not accepted" >&3
            fi
        fi
        if [[ "$opw_unlocked" -eq 0 ]]; then
            if device::ata_blank_master_unlock "$dev"; then
                # Unlocked + temporary password set — the erase below runs.
                device::emit_recovery "$dev" blank-master
            elif device::ata_blank_master_erase "$dev" "$cap"; then
                device::emit_recovery "$dev" blank-master
                return
            elif hdparm -I /dev/$dev 2>/dev/null | grep -qE '^[[:space:]]+expired: security count[[:space:]]*$'; then
                device::emit_lockkind "$dev" ata-expired
                device::emit_status "$dev" FAILED
                echo "$dev LOG ATA security count expired - power-cycle the drive and retry" >&3
                return
            else
                # Neither password works: physical destruction is the outcome.
                device::emit_lockkind "$dev" ata-password
                device::emit_status "$dev" FAILED
                echo "$dev LOG ATA security locked (password unknown) - physical destruction required" >&3
                return
            fi
        fi
    fi

    # Unlocked (or never locked): erase. A drive unlocked with the OPERATOR's
    # password skips set-pass and erases directly with that password, with
    # every hdparm output sunk to /dev/null so the password never reaches the
    # log. NOTE (bench-proven on the Intel): an UNLOCKED drive accepts a
    # SECURITY SET PASSWORD with ANY new value — it replaces the password
    # without needing the old one — so a drive that boots security-enabled but
    # NOT locked (a soft reboot after set-pass) is recovered by the plain
    # set-pass below, no password needed at all.
    if [[ "$opw_unlocked" -eq 1 ]]; then
        ata_pw="$TS_ATA_PASSWORD"
    elif ! hdparm --user-master u --security-set-pass p /dev/$dev >&5 2>&5; then
        # Set-pass failed on a drive that was enabled but not locked (e.g. a
        # Maximum-level drive, where the security level cannot be lowered): the
        # operator's password can still erase directly while unlocked.
        if hdparm -I /dev/$dev 2>/dev/null | grep -qE '^[[:space:]]+enabled[[:space:]]*$'; then
            if [[ -n "${TS_ATA_PASSWORD:-}" ]]; then
                case "$cap" in
                    CAP_ATA_PURGE_ENHANCED)
                        if hdparm --user-master u --security-erase-enhanced "$TS_ATA_PASSWORD" /dev/$dev >/dev/null 2>&1; then
                            device::emit_recovery "$dev" operator-password
                            device::emit_status "$dev" COMPLETED
                            device::ata_clear_password "$dev" "$TS_ATA_PASSWORD" 1
                            return
                        fi
                        ;;
                    CAP_ATA_CLEAR)
                        if hdparm --user-master u --security-erase "$TS_ATA_PASSWORD" /dev/$dev >/dev/null 2>&1; then
                            device::emit_recovery "$dev" operator-password
                            device::emit_status "$dev" COMPLETED
                            device::ata_clear_password "$dev" "$TS_ATA_PASSWORD" 1
                            return
                        fi
                        ;;
                esac
            fi
            device::emit_lockkind "$dev" ata-enabled
            device::emit_status "$dev" FAILED
            echo "$dev LOG ATA security enabled with unknown password - physical destruction required" >&3
            return
        fi
        device::emit_status "$dev" FAILED
        echo "$dev LOG Failed to set ATA security password" >&3
        return
    elif [[ "$was_enabled" -eq 1 && "$was_locked" -eq 0 ]]; then
        # The drive booted security-enabled but NOT locked with a password we
        # never knew: the set-pass above just REPLACED it with ours (allowed
        # while unlocked, no old password needed). Record how it was recovered.
        device::emit_recovery "$dev" set-password
    fi

    case "$cap" in
        CAP_ATA_PURGE_ENHANCED)
            if [[ "$opw_unlocked" -eq 1 ]]; then
                if hdparm --user-master u --security-erase-enhanced "$ata_pw" /dev/$dev >/dev/null 2>&1; then
                    device::emit_status "$dev" COMPLETED
                else
                    device::emit_status "$dev" FAILED
                fi
            elif hdparm --user-master u --security-erase-enhanced "$ata_pw" /dev/$dev >&5 2>&5; then
                device::emit_status "$dev" COMPLETED
            else
                device::emit_status "$dev" FAILED
            fi
            device::ata_clear_password "$dev" "$ata_pw" "$opw_unlocked"
            return
            ;;

        CAP_ATA_CLEAR)
            if [[ "$opw_unlocked" -eq 1 ]]; then
                if hdparm --user-master u --security-erase "$ata_pw" /dev/$dev >/dev/null 2>&1; then
                    device::emit_status "$dev" COMPLETED
                else
                    device::emit_status "$dev" FAILED
                fi
            elif hdparm --user-master u --security-erase "$ata_pw" /dev/$dev >&5 2>&5; then
                device::emit_status "$dev" COMPLETED
            else
                device::emit_status "$dev" FAILED
            fi
            device::ata_clear_password "$dev" "$ata_pw" "$opw_unlocked"
            return
            ;;

        *)
            device::emit_status "$dev" FAILED
            echo "$dev LOG Unsupported ATA capability" >&3
            return
            ;;
    esac

    # hdparm --security-erase[-enhanced] is synchronous (blocks until done), so
    # no progress monitor is needed here.
}

