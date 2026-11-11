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

# Same channel for a worker-selected method (the restricted-sanitize path names
# the actual operation rather than the class-default "Enhanced Erase").
device::emit_method() {   # <dev> <value>
    local dev="$1" val="$2"
    devrow["$dev.method"]="$val"
    echo "$dev METHOD $val" >&3
}

# --- ACS-4 RESTRICTED SANITIZE OVERRIDES SECURITY (RSOS) --------------------
# A drive that implements RSOS accepts a sanitize operation command (CRYPTO
# SCRAMBLE / BLOCK ERASE / OVERWRITE EXT, FAILURE MODE bit cleared) while in
# SEC4: Security Enabled/Locked/Not Frozen — and a successful sanitize
# invalidates the User password and clears SECURITY ENABLED. That is the
# spec's passwordless recovery for a locked drive.
# Detection: the RSOS bit is Security log page 06h, QWord 48..55, bit 7 (bit 63
# of the QWord = MSB of log byte 48). ACS-4 r14 has NO IDENTIFY copy, so a
# drive that does not implement the Security log reads as unsupported and the
# ladder's honest outcome is unchanged.

# Extract the raw byte at <off> from smartctl's GP-log hex dump.
device::ata_gplog_byte() {   # <dev> <log> <off>
    local dev="$1" log="$2" off="$3" out bytes
    local -a arr
    out="$(smartctl -d sat -l "gplog,0x$log" -T permissive /dev/$dev 2>/dev/null)"
    # Only dump lines carry 16 hex pairs; strip through their 0x address.
    bytes="$(printf '%s\n' "$out" | grep -E '([0-9a-fA-F]{2}[[:space:]]){7}[0-9a-fA-F]{2}' | sed 's/.*0x[0-9a-fA-F]*//' | grep -oE '[0-9a-fA-F]{2}' | tr '\n' ' ')"
    read -r -a arr <<< "$bytes"
    if (( ${#arr[@]} <= off )); then
        return 1
    fi
    printf '%d' "$(( 16#${arr[$off]} ))"
    return 0
}

device::ata_rsos_supported() {   # <dev>
    local dev="$1" b
    b="$(device::ata_gplog_byte "$dev" 06 48)" || return 1
    (( b & 0x80 )) && return 0
    return 1
}

# Issue a restricted sanitize on a SECURITY-LOCKED drive via ATA
# PASS-THROUGH(16) through the SATL and poll SANITIZE STATUS EXT to completion.
# Emits COMPLETED + Recovery/Method on success; returns nonzero otherwise.
device::ata_restricted_sanitize() {   # <dev>
    local dev="$1" sg id_out feature=0 op_name="" method_name=""
    local tries=0 w lo_hi rc
    sg="$(sg_map 2>/dev/null | awk -v d="/dev/$dev" '$2 == d { print $1; exit }')"
    [[ -n "$sg" ]] || return 1
    id_out="$(hdparm -I /dev/$dev 2>/dev/null)"
    if printf '%s\n' "$id_out" | grep -q 'CRYPTO_SCRAMBLE_EXT'; then
        feature=0x11; op_name="crypto"
    elif printf '%s\n' "$id_out" | grep -q 'BLOCK_ERASE_EXT'; then
        feature=0x12; op_name="block"
    elif printf '%s\n' "$id_out" | grep -q 'OVERWRITE_EXT'; then
        feature=0x14; op_name="overwrite"
    else
        return 1
    fi
    case "$op_name" in
        crypto)    method_name="Sanitize Crypto Erase" ;;
        block)     method_name="Sanitize Block Erase" ;;
        overwrite) method_name="Sanitize Overwrite" ;;
    esac
    # ATA PASS-THROUGH(16): protocol non-data, FEATURE=<op> (FAILURE MODE bit
    # cleared — RSOS requires it), COUNT=0, LBA=426B4572h little-endian,
    # COMMAND=B4h.
    if ! sg_raw "$sg" 85 06 20 00 00 "$(printf '%02x' "$feature")" 00 00 72 45 6b 42 00 b4 00 00 >/dev/null 2>&1; then
        echo "$dev LOG restricted sanitize rejected" >&3
        return 1
    fi
    echo "$dev LOG restricted sanitize started ($op_name)" >&3
    while (( tries < ${TS_RSOS_POLL_TRIES:-120} )); do
        # SANITIZE STATUS EXT: PIO Data-In protocol, FEATURE=0000h,
        # LBA=46724C6Bh little-endian, COMMAND=B4h. Status word 0: bit 15
        # completed without error, bit 14 in progress.
        lo_hi="$(sg_raw -r 512 "$sg" 85 08 20 00 00 00 00 00 6b 4c 72 46 00 b4 00 00 2>/dev/null | od -An -tx1 | tr -s ' ' | tr -d ' ')" || { sleep 1; tries=$((tries+1)); continue; }
        if [[ ${#lo_hi} -lt 4 ]]; then
            sleep 1; tries=$((tries+1)); continue
        fi
        w=$(( 16#${lo_hi:0:2} | (16#${lo_hi:2:2} << 8) ))
        if (( w & 0x8000 )); then
            device::emit_recovery "$dev" restricted-sanitize
            device::emit_method "$dev" "$method_name"
            device::emit_status "$dev" COMPLETED
            echo "$dev LOG erased via restricted sanitize ($op_name)" >&3
            return 0
        elif (( w & 0x4000 )); then
            sleep 1; tries=$((tries+1))
        else
            echo "$dev LOG restricted sanitize failed" >&3
            return 1
        fi
    done
    echo "$dev LOG restricted sanitize timed out" >&3
    return 1
}

# ATA SECURITY ERASE UNIT (F4h) via SATL ATA16 passthrough — for drives whose
# host transport cannot complete hdparm's long-running erase (bench-proven on
# mpt3sas: the F4 is DELIVERED — the drive enters its security state and reads
# answer "Security conflict" — but the passthrough completion comes back
# broken, hdparm falls back to the legacy HDIO_DRIVE_TASKFILE ioctl (EINVAL on
# any SCSI device) and reports a misleading CONFIG_IDE_TASK_IOCTL error).
# Issues ERASE PREPARE + ERASE UNIT through sg_raw (the identical ATA16 CDB
# shapes hdparm's working unlock uses) and polls to completion:
#   - identify says "enabled"          -> busy / still erasing
#   - identify says "not enabled"      -> done (a successful erase invalidates
#     the password AND clears SECURITY ENABLED) — accepted only after we have
#     SEEN "enabled", so a drive that was already disabled is never reported
#     COMPLETED without an erase.
#   - after a grace period, a drive that still shows enabled AND serves I/O
#     never engaged the erase -> fail fast instead of waiting out the budget.
# The password travels only as stdin bytes to sg_raw; never into any log line.
device::ata_satl_security_erase() {   # <dev> <pw> <plain|enhanced>
    local dev="$1" pw="$2" mode="${3:-plain}" sg tmp id mins budget
    local -i tries=0 elapsed=0 saw_enabled=0
    local -i poll=${TS_SATL_ERASE_POLL_SECS:-10}
    local -i grace=${TS_SATL_ERASE_GRACE_SECS:-60}
    local -i budget_mins=${TS_SATL_ERASE_BUDGET_MINS:-0}
    local -i budget_secs=${TS_SATL_ERASE_BUDGET_SECS:-0}
    sg="$(sg_map 2>/dev/null | awk -v d="/dev/$dev" '$2 == d { print $1; exit }')"
    [[ -n "$sg" ]] || return 1

    # ERASE PREPARE (F3h): ATA16 non-data, CHECK CONDITION, dev=LBA — must
    # immediately precede the erase.
    if ! sg_raw "$sg" 85 03 20 00 00 00 00 00 00 00 00 00 00 40 f3 00 >/dev/null 2>&1; then
        echo "$dev LOG SATL erase prepare rejected" >&3
        return 1
    fi

    # 512-byte security data block: byte 0 = user (0) | enhanced (2),
    # byte 1 reserved, bytes 2..33 = password, rest zero.
    tmp="$(mktemp)"
    if [[ "$mode" == "enhanced" ]]; then
        printf '\x02' > "$tmp"
    else
        printf '\x00' > "$tmp"
    fi
    printf '\x00' >> "$tmp"
    printf '%s' "$pw" >> "$tmp"
    dd if=/dev/zero bs=1 count=478 >> "$tmp" 2>/dev/null
    # ERASE UNIT (F4h): PIO data-out, one 512-byte block — the exact CDB
    # shape of hdparm's verified working unlock, with command F4.
    if ! sg_raw -s 512 "$sg" 85 0a 06 00 00 00 01 00 00 00 00 00 00 40 f4 00 < "$tmp" >/dev/null 2>&1; then
        echo "$dev LOG SATL erase command rejected" >&3
    fi
    rm -f "$tmp"

    # Budget: the drive's own estimate (identify word 89/90 minutes) + a
    # 10-minute margin, floor 30 minutes. Test seams override directly.
    if (( budget_secs > 0 )); then
        budget=$budget_secs
    elif (( budget_mins > 0 )); then
        budget=$(( budget_mins * 60 ))
    else
        if [[ "$mode" == "enhanced" ]]; then
            mins="$(hdparm -I /dev/$dev 2>/dev/null | sed -n 's/.*\([0-9][0-9]*\)min for ENHANCED SECURITY ERASE UNIT.*/\1/p' | head -n1)"
        else
            mins="$(hdparm -I /dev/$dev 2>/dev/null | sed -n 's/.*\([0-9][0-9]*\)min for SECURITY ERASE UNIT.*/\1/p' | head -n1)"
        fi
        budget=$(( ${mins:-0} * 60 + 600 ))
        (( budget < 1800 )) && budget=1800
    fi

    echo "$dev LOG SATL security erase started ($mode)" >&3
    while (( elapsed < budget && tries < 12000 )); do
        id="$(hdparm -I /dev/$dev 2>/dev/null)"
        if printf '%s\n' "$id" | grep -qE '^[[:space:]]+enabled[[:space:]]*$'; then
            saw_enabled=1
            # After the grace period, an enabled drive that still serves I/O
            # normally never engaged the erase — fail fast, don't wait.
            if (( elapsed >= grace )) && smartctl -a /dev/$dev >/dev/null 2>&1; then
                echo "$dev LOG SATL security erase did not engage (drive still serves I/O)" >&3
                return 1
            fi
        elif printf '%s\n' "$id" | grep -qE '^[[:space:]]+not[[:space:]]+enabled[[:space:]]*$'; then
            if (( saw_enabled == 1 )); then
                echo "$dev LOG SATL security erase completed" >&3
                return 0
            fi
            # Identify answers but the drive was never security-enabled: there
            # is nothing the erase could have done. Fail honestly.
            echo "$dev LOG SATL security erase did not engage (never enabled)" >&3
            return 1
        fi
        # Identify empty/failed: the drive is busy — keep polling.
        sleep "$poll"
        tries=$((tries + 1))
        elapsed=$(( tries * poll ))
        (( tries % 6 == 0 )) && echo "$dev LOG SATL security erase in progress (${elapsed}s of ${budget}s)" >&3
    done
    echo "$dev LOG SATL security erase timed out" >&3
    return 1
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
        # The counter blocks every SECURITY command, but a drive with the ACS-4
        # RESTRICTED SANITIZE OVERRIDES SECURITY capability can still be
        # sanitized while locked — the sanitize command is not a security
        # command and does not use the counter. Try it before giving up.
        if device::ata_rsos_supported "$dev" && device::ata_restricted_sanitize "$dev"; then
            return
        fi
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
            # NEVER to fd 5 (the run log). NOTE: hdparm's exit code is NOT a
            # reliable unlock signal — it returns 0 on an ALREADY-UNLOCKED
            # drive even with the wrong password (bench-proven), so verify the
            # lock is actually gone via identify before trusting the unlock.
            if hdparm --user-master u --security-unlock "$TS_ATA_PASSWORD" /dev/$dev >/dev/null 2>&1 \
                && ! hdparm -I /dev/$dev 2>/dev/null | grep -qE '^[[:space:]]+locked[[:space:]]*$'; then
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
            elif device::ata_rsos_supported "$dev" && device::ata_restricted_sanitize "$dev"; then
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
                        if hdparm --user-master u --security-erase-enhanced "$TS_ATA_PASSWORD" /dev/$dev >/dev/null 2>&1 \
                            || device::ata_satl_security_erase "$dev" "$TS_ATA_PASSWORD" enhanced >/dev/null 2>&1; then
                            device::emit_recovery "$dev" operator-password
                            device::emit_status "$dev" COMPLETED
                            device::ata_clear_password "$dev" "$TS_ATA_PASSWORD" 1
                            return
                        fi
                        ;;
                    CAP_ATA_CLEAR)
                        if hdparm --user-master u --security-erase "$TS_ATA_PASSWORD" /dev/$dev >/dev/null 2>&1 \
                            || device::ata_satl_security_erase "$dev" "$TS_ATA_PASSWORD" plain >/dev/null 2>&1; then
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
                    # Clear the password ONLY on a successful erase: a FAILED
                    # drive must stay locked/security-enabled at the next power
                    # cycle so a retry remains possible.
                    device::ata_clear_password "$dev" "$ata_pw" "$opw_unlocked"
                elif device::ata_satl_security_erase "$dev" "$ata_pw" enhanced >/dev/null 2>&1; then
                    device::emit_status "$dev" COMPLETED
                    device::ata_clear_password "$dev" "$ata_pw" "$opw_unlocked"
                else
                    device::emit_status "$dev" FAILED
                fi
            elif hdparm --user-master u --security-erase-enhanced "$ata_pw" /dev/$dev >&5 2>&5; then
                device::emit_status "$dev" COMPLETED
                device::ata_clear_password "$dev" "$ata_pw" "$opw_unlocked"
            elif device::ata_satl_security_erase "$dev" "$ata_pw" enhanced >&5 2>&5; then
                device::emit_status "$dev" COMPLETED
                device::ata_clear_password "$dev" "$ata_pw" "$opw_unlocked"
            else
                device::emit_status "$dev" FAILED
            fi
            return
            ;;

        CAP_ATA_CLEAR)
            if [[ "$opw_unlocked" -eq 1 ]]; then
                if hdparm --user-master u --security-erase "$ata_pw" /dev/$dev >/dev/null 2>&1; then
                    device::emit_status "$dev" COMPLETED
                    device::ata_clear_password "$dev" "$ata_pw" "$opw_unlocked"
                elif device::ata_satl_security_erase "$dev" "$ata_pw" plain >/dev/null 2>&1; then
                    device::emit_status "$dev" COMPLETED
                    device::ata_clear_password "$dev" "$ata_pw" "$opw_unlocked"
                else
                    device::emit_status "$dev" FAILED
                fi
            elif hdparm --user-master u --security-erase "$ata_pw" /dev/$dev >&5 2>&5; then
                device::emit_status "$dev" COMPLETED
                device::ata_clear_password "$dev" "$ata_pw" "$opw_unlocked"
            elif device::ata_satl_security_erase "$dev" "$ata_pw" plain >&5 2>&5; then
                device::emit_status "$dev" COMPLETED
                device::ata_clear_password "$dev" "$ata_pw" "$opw_unlocked"
            else
                device::emit_status "$dev" FAILED
            fi
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

