# =============================================================================
# DEVICE SCSI
# =============================================================================


device::exec_scsi_nwipe() {
    local dev="$1"
    local nwipe_help logfile rc line pct eta_sec
    local -a nwipe_cmd
    local nwipe_pid pump_pid tail_pid

    if ! command -v nwipe > /dev/null 2>&1; then
        echo "$dev STATUS FAILED" >&3
        echo "$dev LOG nwipe not found; cannot sanitize non-ATA device" >&3
        return
    fi

    echo "$dev STATUS RUNNING" >&3
    echo "$dev LOG Nwipe quick operation started" >&3

    nwipe_cmd=(nwipe --autonuke --method=zero)
    nwipe_help="$(nwipe --help 2>&1 || true)"
    if grep -q -- '--nogui' <<<"$nwipe_help"; then
        nwipe_cmd+=(--nogui)
    fi
    # nwipe --nogui only logs progress when signalled (SIGUSR1). A --logfile is
    # flushed per line, so we can follow it live: run nwipe in the background,
    # pump SIGUSR1 every ~10s, and tail the log for "NN.NN%, …, eta HH:MM:SS".
    logfile="$(mktemp /tmp/tscrub-nwipe.XXXXXX)"
    nwipe_cmd+=(--logfile "$logfile")
    nwipe_cmd+=("/dev/$dev")

    "${nwipe_cmd[@]}" 2>>"$logfile" &
    nwipe_pid=$!

    # Progress pump: nwipe blocks SIGUSR1 in main before wiping and logs a
    # progress line whenever the signal arrives. Start after a short delay so
    # the handler is installed, then signal every 10s while nwipe lives.
    (
        sleep 3
        while kill -0 "$nwipe_pid" 2>/dev/null; do
            sleep 10
            kill -USR1 "$nwipe_pid" 2>/dev/null || true
        done
    ) 3>&- 4<&- &
    pump_pid=$!

    # Follow the log by re-reading only newly appended bytes once a second:
    # forward every line to the detail log (fd 5) and translate progress lines
    # ("NN.NN%, …, eta HH:MM:SS") into STATUS/ETA IPC so the parent ui::loop
    # can aggregate live % + time remaining for the dashboard heartbeat.
    local offset=0 size=0
    while kill -0 "$nwipe_pid" 2>/dev/null; do
        size=$(wc -c < "$logfile" 2>/dev/null || echo 0)
        if (( size > offset )); then
            tail -c +$((offset + 1)) "$logfile" 2>/dev/null | while IFS= read -r line; do
                echo "$line" >&5
                if [[ "$line" =~ ([0-9]+)(\.[0-9]+)?% ]]; then
                    pct="${BASH_REMATCH[1]}"
                    if [[ "$line" =~ eta[[:space:]]+([0-9]+):([0-9]+):([0-9]+) ]]; then
                        eta_sec=$(( ${BASH_REMATCH[1]} * 3600 + ${BASH_REMATCH[2]} * 60 + ${BASH_REMATCH[3]} ))
                        echo "$dev ETA $eta_sec" >&3
                    fi
                    echo "$dev STATUS ${pct}%" >&3
                fi
            done
            offset=$size
        fi
        sleep 1
    done

    wait "$nwipe_pid"; rc=$?
    # Drain any lines written between the last poll and nwipe's exit.
    tail -c +$((offset + 1)) "$logfile" 2>/dev/null | while IFS= read -r line; do
        echo "$line" >&5
        if [[ "$line" =~ ([0-9]+)(\.[0-9]+)?% ]]; then
            pct="${BASH_REMATCH[1]}"
            if [[ "$line" =~ eta[[:space:]]+([0-9]+):([0-9]+):([0-9]+) ]]; then
                eta_sec=$(( ${BASH_REMATCH[1]} * 3600 + ${BASH_REMATCH[2]} * 60 + ${BASH_REMATCH[3]} ))
                echo "$dev ETA $eta_sec" >&3
            fi
            echo "$dev STATUS ${pct}%" >&3
        fi
    done
    kill "$pump_pid" 2>/dev/null || true
    wait "$pump_pid" 2>/dev/null || true
    rm -f "$logfile"

    if [ $rc -eq 0 ]; then
        echo "$dev STATUS COMPLETED" >&3
    else
        echo "$dev STATUS FAILED" >&3
        echo "$dev LOG nwipe quick operation failed; manual physical destruction required" >&3
    fi
}

# True when the SCSI/SAS drive supports the SCSI SANITIZE command with the
# overwrite service action (opcode 0x48, SA 0x01). Conservative: any probe
# failure (missing tool, no REPORT SUPPORTED OPCODES support, or the drive
# rejecting the query) means "no" so the drive falls back to nwipe.
device::scsi_sanitize_supported() {
    local dev="$1" out
    command -v sg_sanitize >/dev/null 2>&1 || return 1
    command -v sg_opcodes >/dev/null 2>&1 || return 1
    out="$(sg_opcodes --opcode=0x48,0x1 "/dev/$dev" 2>/dev/null || true)"
    [[ "$out" == *"Command supported"* ]]
}

# SCSI/SAS firmware sanitise: `sg_sanitize --overwrite --zero`. Firmware
# overwrite is a Purge per NIST 800-88, so a successful sanitize is recorded
# as DESTRUCTION. When the drive rejects SANITIZE (invalid opcode / field in
# CDB — mis-detected support), fall back to nwipe software overwrite and
# correct the recorded outcome to the honest CLEAR/SANITISATION label.
device::exec_scsi_sanitize() {
    local dev="$1"
    local tmp_out rc out

    if ! command -v sg_sanitize >/dev/null 2>&1; then
        # Tool vanished since detection — degrade to nwipe honestly.
        devrow["$dev.class"]="CLEAR"
        devrow["$dev.cert"]="SANITISATION"
        devrow["$dev.method"]="nwipe Quick"
        device::exec_scsi_nwipe "$dev"
        return
    fi

    echo "$dev STATUS RUNNING" >&3
    echo "$dev LOG SCSI sanitize (overwrite) started" >&3
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: SAS SANITIZE (overwrite, zero)" >> "$LOG_FILE"

    tmp_out="$(mktemp /tmp/tscrub-sg.XXXXXX)" || {
        echo "$dev STATUS FAILED" >&3
        return
    }

    # --quick skips sg_sanitize's 15-second reconsideration delay. With neither
    # --early nor --wait it polls REQUEST SENSE every 60 s until the sanitize
    # finishes, so this call blocks until completion. Output streams to the UI
    # (fd 5) and is tee'd into a temp file for the unsupported fallback check.
    sg_sanitize --overwrite --zero --quick "/dev/$dev" 2>&1 | tee "$tmp_out" >&5
    rc=${PIPESTATUS[0]}
    out="$(cat "$tmp_out" 2>/dev/null)"
    rm -f "$tmp_out"

    if (( rc == 0 )); then
        echo "$dev STATUS COMPLETED" >&3
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: SAS SANITIZE COMPLETED" >> "$LOG_FILE"
        return
    fi

    # A drive that rejects SANITIZE (invalid opcode / field in CDB) was
    # optimistically classified — fall back to nwipe and correct the outcome.
    if grep -qiE 'invalid (command operation code|field in cdb)|not supported' <<<"$out"; then
        echo "$dev LOG sanitize unsupported; falling back to nwipe" >&3
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: SAS SANITIZE unsupported -> nwipe" >> "$LOG_FILE"
        devrow["$dev.class"]="CLEAR"
        devrow["$dev.cert"]="SANITISATION"
        devrow["$dev.method"]="nwipe Quick"
        device::exec_scsi_nwipe "$dev"
        return
    fi

    echo "$dev STATUS FAILED" >&3
    echo "$dev LOG sanitize failed; manual physical destruction required" >&3
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: SAS SANITIZE FAILED" >> "$LOG_FILE"
}

