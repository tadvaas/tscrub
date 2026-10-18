# =============================================================================
# DEVICE NVMe
# =============================================================================

# Read the most-recent-sanitize state from `nvme sanitize-log`.
#
# Echoes three space-separated fields: "<state> <sprog> <sstat>" where state is
# SSTAT bits 2:0 (or "-" when the field is missing/unparseable):
#   0 = never sanitized      1 = completed      2 = in progress
#   3 = failed               4 = completed with deallocation
# SPROG is the progress counter (0..65535; 65535 is also the idle sentinel, so
# it must never be read as success on its own).
device::nvme_sanitize_state() {
    local dev="$1" log sstat sprog state="-"

    log="$(nvme sanitize-log /dev/$dev 2>&1 || true)"
    sstat="$(awk '/SSTAT/ {print $NF}' <<<"$log")"
    sprog="$(awk '/SPROG/ {print $NF}' <<<"$log")"

    if [[ "$sstat" =~ ^0x[0-9A-Fa-f]+$ || "$sstat" =~ ^[0-9]+$ ]]; then
        state=$(( sstat & 0x7 ))
    fi

    printf '%s %s %s\n' "$state" "${sprog:--}" "${sstat:--}"
}

# Report a failed NVMe operation, distinguishing a BIOS/firmware access-rights
# lockdown from a generic controller rejection.
#
# Two NVMe "command aborted — lack of access rights" statuses are recoverable
# lockdowns, not hardware faults:
#   0x4286 "Access Denied: access to the namespace and/or LBA range is denied…"
#   0x4015 "Operation Denied: the command was denied due to lack of access rights"
# On many BIOS-managed laptops (notably Lenovo) the firmware asserts TCG Block
# SID at every POST, which gates sanitize/format even on an unlocked,
# unprovisioned SED. Such a drive is recoverable after clearing Block SID /
# hard-disk security in firmware (or moving the drive to another machine), so it
# is flagged BLOCKED rather than failed straight to physical destruction.
device::nvme_fail() {
    local dev="$1"
    local op="$2"
    local out="$3"

    {
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: NVMe ${op} FAILED"
        echo "COMMAND OUTPUT: $out"
        echo "----------------------------------------------------------"
    } >> "$LOG_FILE"

    if grep -qiE '0x4286|0x4015|Access Denied|Operation Denied|lack of access rights' <<<"$out"; then
        device::emit_status "$dev" BLOCKED
        echo "$dev LOG ${op} denied — firmware access-rights lockdown (Block SID); clear Block SID / hard-disk security in BIOS, or move the drive to another machine, then re-run" >&3
    else
        device::emit_status "$dev" FAILED
        echo "$dev LOG Controller rejected ${op} command" >&3
    fi
}

# Issue the NVMe firmware-erasure command for a capability, capturing output in
# the global NVME_CMD_OUT/NVME_CMD_OP and returning the command's exit status.
# Kept as one seam so exec_nvme can retry a failed command after a freeze-lock
# suspend/resume (the "second purge often succeeds" behaviour).
device::nvme_issue() {
    local dev="$1" cap="$2"
    NVME_CMD_OUT=""
    case "$cap" in
        CAP_NVME_CLEAR_ONLY)
            NVME_CMD_OP="format"
            NVME_CMD_OUT="$(nvme format /dev/$dev -s 1 2>&1)"; return $?
            ;;
        CAP_NVME_PURGE_CRYPTO)
            NVME_CMD_OP="sanitize"
            NVME_CMD_OUT="$(nvme sanitize /dev/$dev -a 4 2>&1)"; return $?
            ;;
        CAP_NVME_PURGE_BLOCK)
            NVME_CMD_OP="sanitize"
            NVME_CMD_OUT="$(nvme sanitize /dev/$dev -a 2 2>&1)"; return $?
            ;;
        CAP_NVME_PURGE_OVERWRITE)
            NVME_CMD_OP="sanitize"
            NVME_CMD_OUT="$(nvme sanitize /dev/$dev -a 3 2>&1)"; return $?
            ;;
        *)
            NVME_CMD_OP="sanitize"
            NVME_CMD_OUT="unsupported capability"
            return 1
            ;;
    esac
}

device::exec_nvme() {
    local dev="$1"
    local cap="$2"
    local pre state

    # ---- Pre-flight -------------------------------------------------------
    # A sanitize left "in progress" by an earlier run or an interrupted wipe
    # wedges the controller's I/O path: every read returns
    # NVME_SC_SANITIZE_IN_PROGRESS (dmesg sc=0x1d) and a newly issued sanitize
    # is accepted but never advances (observed on an Intel 660p: SSTAT=0x2 with
    # SPROG frozen at 655 for the whole run, all Estimated Time fields
    # 0xffffffff). Detect it in seconds and say what the operator must do,
    # instead of burning the 6-hour monitor cap to a reasonless failure.
    # The sanitize state machine only clears on a real power cycle.
    pre="$(device::nvme_sanitize_state "$dev")"
    state="${pre%% *}"

    if [[ "$state" == "2" ]]; then
        device::emit_status "$dev" STALLED
        echo "$dev LOG a sanitize was already in progress on this drive (from an earlier run or an interrupted wipe); a new one cannot advance — power-cycle the drive (disconnect power ~10-30 s), then re-run" >&3
        {
            echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: NVMe sanitize PRE-FLIGHT STALLED (sanitize already in progress)"
            echo "sanitize-log: state/sporg/sstat = $pre"
            echo "----------------------------------------------------------"
        } >> "$LOG_FILE"
        return 1
    fi

    if [[ "$state" == "3" ]]; then
        # The drive says a previous sanitize failed. That is worth flagging, but
        # a retry is legitimate (transient controller errors happen), so record
        # it and carry on rather than refusing.
        echo "$dev LOG note: this drive reports a previous sanitize as FAILED (SSTAT=$pre); retrying now" >&3
        {
            echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: NVMe sanitize PRE-FLIGHT WARNING (previous sanitize failed)"
            echo "sanitize-log: state/sporg/sstat = $pre"
            echo "----------------------------------------------------------"
        } >> "$LOG_FILE"
    fi

    echo "$dev STATUS RUNNING" >&3
    echo "$dev LOG NVMe operation started" >&3

    if device::nvme_issue "$dev" "$cap"; then
        echo "$NVME_CMD_OUT" >&5
        if [[ "$cap" == "CAP_NVME_CLEAR_ONLY" ]]; then
            device::emit_status "$dev" COMPLETED
        else
            device::monitor_nvme "$dev"
        fi
        return
    fi
    echo "$NVME_CMD_OUT" >&5

    # Firmware command failed. Block SID (0x4286/0x4015) is an access-rights
    # lockdown, not a fault — but a suspend/resume clears the state that caused
    # it on some machines, so attempt ONE freeze-lock-style retry before giving
    # up (the "second purge often succeeds" behaviour). Honours TS_RETRY_PURGE
    # and TS_FLR_MODE.
    if [[ "${TS_RETRY_PURGE:-1}" == "1" && "${TS_FLR_MODE:-auto}" != "disabled" ]]; then
        echo "$dev LOG firmware command failed — suspending to clear the freeze lock, then retrying once" >&3
        device::flr_suspend_resume "$dev"
        if device::nvme_issue "$dev" "$cap"; then
            echo "$NVME_CMD_OUT" >&5
            echo "$dev LOG retry after freeze-lock removal succeeded" >&3
            if [[ "$cap" == "CAP_NVME_CLEAR_ONLY" ]]; then
                device::emit_status "$dev" COMPLETED
            else
                device::monitor_nvme "$dev"
            fi
            return
        fi
        echo "$NVME_CMD_OUT" >&5
    fi

    # Block SID (0x4286/0x4015) is a firmware lockdown, not a hardware fault.
    # The TCG tables are typically unlocked here (Locked = N), so the
    # pre-execution PSID prompt in handle_locks never saw this drive. Under the
    # automatic `psid` policy the PSID is already on hand (psids.conf) and the
    # revert itself is the purge: it crypto-erases the media key and factory
    # resets the drive without needing the BIOS-blocked sanitize command. The
    # interactive `ask` policy is deliberately NOT attempted here — this runs
    # inside a wipe worker, where reading /dev/tty would race the UI loop — so
    # under `ask` the drive falls through to BLOCKED with the clear-Block-SID
    # hint.
    if [[ "${TS_BLOCK_SID_POLICY:-ask}" == "psid" ]] \
        && grep -qiE '0x4286|0x4015|Access Denied|Operation Denied|lack of access rights' <<<"$NVME_CMD_OUT"; then
        if device::psid_unlock_auto "$dev"; then
            devrow["$dev.method"]="PSID Revert (Purge)"
            devrow["$dev.class"]="PURGE"
            devrow["$dev.cert"]="DESTRUCTION"
            devrow["$dev.lock_kind"]="password"
            echo "$dev LOG Block SID lockdown — PSID revert crypto-erased and factory-reset the drive" >&3
            device::emit_status "$dev" COMPLETED
            return
        fi
    fi

    # Firmware erase failed for a NON-lockdown reason: the controller rejected
    # sanitize/format even though (or because) it has no usable erase path —
    # e.g. a Samsung PM981a that advertises Format NVM but answers every
    # sanitize/format with 0x2001, has no Sanitize, and no Opal SED. The drive
    # is still writable, so fall back to a single-pass software overwrite
    # (nwipe), recorded honestly as Clear, instead of failing the drive.
    # Block SID (0x4286/0x4015) is deliberately EXCLUDED: that is a recoverable
    # firmware lockdown with its own PSID/BIOS path, and falling back here would
    # mask it. Gated on TS_NVME_SOFTWARE_FALLBACK (auto=on, off=disabled).
    if [[ "${TS_NVME_SOFTWARE_FALLBACK:-auto}" != "off" ]] \
        && ! grep -qiE '0x4286|0x4015|Access Denied|Operation Denied|lack of access rights' <<<"$NVME_CMD_OUT"; then
        echo "$dev LOG ${NVME_CMD_OP:-sanitize} rejected — no firmware erase path; falling back to software overwrite (nwipe)" >&3
        # Outcome travels over the fd-3 IPC pipe: this runs in the wipe worker's
        # subshell, where direct devrow writes would be lost. Emitted before the
        # wipe — a failed nwipe ends FAILED, which normalize_outcome overwrites
        # back to "Sanitisation failed", so the labels are never falsely Clear.
        echo "$dev METHOD nwipe Quick" >&3
        echo "$dev CLASS CLEAR" >&3
        echo "$dev CERT SANITISATION" >&3
        device::exec_nwipe "$dev"
        return
    fi

    device::nvme_fail "$dev" "${NVME_CMD_OP:-sanitize}" "$NVME_CMD_OUT"
}

# Poll `nvme sanitize-log` until the sanitize reaches a terminal state.
#
# Two independent guards, deliberately different in kind:
#   * STALL  — an INACTIVITY detector. If neither SSTAT nor SPROG changes for
#              NVME_STALL_POLLS polls (default 450 x 2s = 15 min) the
#              controller's state machine has wedged. A slow but genuinely
#              progressing overwrite sanitize resets this on every change, so
#              long wipes are never killed for taking a long time.
#   * CAP    — a 6-hour backstop for "still moving, still never finishing".
# Both end in STALLED (drive needs operator intervention: a power cycle).
device::monitor_nvme() {
    local dev="$1"
    local timeout=$((60 * 60 * 6))
    local stall_polls="${NVME_STALL_POLLS:-450}"
    local start=$(ts::now)
    local no_progress=0
    local state_sp state sprog sstat pct key window window_txt
    local last_key="" last_change=0

    window=$(( stall_polls * 2 ))
    if (( window >= 60 )); then window_txt="$(( window / 60 )) min"; else window_txt="${window}s"; fi

    while :; do
        sleep 2
        state_sp="$(device::nvme_sanitize_state "$dev")"
        read -r state sprog sstat <<<"$state_sp"

        case "$state" in
            1|4)
                device::emit_status "$dev" COMPLETED
                return 0
                ;;
            3)
                device::emit_status "$dev" FAILED
                echo "$dev LOG Controller reported sanitize failure (SSTAT=$sstat)" >&3
                return 1
                ;;
            2)
                no_progress=0
                if [[ "$sprog" =~ ^[0-9]+$ ]]; then
                    pct=$(( sprog * 100 / 65535 ))
                    echo "$dev STATUS ${pct}%" >&3
                else
                    echo "$dev STATUS RUNNING" >&3
                fi
                ;;
            0|-|"")
                # Never sanitized (or unreadable SSTAT): fail fast after a short
                # grace period instead of burning the full 6-hour timeout.
                no_progress=$((no_progress + 1))
                if (( no_progress >= 15 )); then
                    device::emit_status "$dev" FAILED
                    echo "$dev LOG No sanitize progress reported (SSTAT=$sstat)" >&3
                    return 1
                fi
                echo "$dev STATUS RUNNING" >&3
                ;;
        esac

        # Stall detection: any change to (state, SPROG) proves the controller is
        # still working. A frozen pair means it has wedged — SSTAT stays 2
        # ("in progress") forever while reads fail with 0x1d.
        key="$state:$sprog"
        if [[ "$key" != "$last_key" ]]; then
            last_key="$key"
            last_change=$(ts::now)
        elif (( $(ts::now) - last_change >= window )); then
            device::emit_status "$dev" STALLED
            echo "$dev LOG sanitize stalled — no progress for $window_txt (SSTAT=$sstat, SPROG=$sprog); power-cycle the drive (disconnect power ~10-30 s), then re-run" >&3
            return 1
        fi

        if (( $(ts::now) - start > timeout )); then
            device::emit_status "$dev" STALLED
            echo "$dev LOG sanitize did not complete within $(( timeout / 3600 )) h (SSTAT=$sstat, SPROG=$sprog); power-cycle the drive (disconnect power ~10-30 s), then re-run" >&3
            return 1
        fi
    done
}

