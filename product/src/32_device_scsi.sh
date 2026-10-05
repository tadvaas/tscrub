# =============================================================================
# DEVICE SCSI
# =============================================================================


device::exec_scsi_nwipe() {
    local dev="$1"
    local nwipe_help
    local -a nwipe_cmd

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
    nwipe_cmd+=("/dev/$dev")

    # nwipe --nogui emits no per-drive percentage lines (the GUI progress
    # thread is never created), so progress is reflected only by
    # RUNNING -> COMPLETED/FAILED.
    "${nwipe_cmd[@]}" 2>&1 | while IFS= read -r line; do
        echo "$line" >&5
    done
    # Check exit status of nwipe
    if [ "${PIPESTATUS[0]}" -eq 0 ]; then
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
        return
    fi

    # A drive that rejects SANITIZE (invalid opcode / field in CDB) was
    # optimistically classified — fall back to nwipe and correct the outcome.
    if grep -qiE 'invalid (command operation code|field in cdb)|not supported' <<<"$out"; then
        echo "$dev LOG sanitize unsupported; falling back to nwipe" >&3
        devrow["$dev.class"]="CLEAR"
        devrow["$dev.cert"]="SANITISATION"
        devrow["$dev.method"]="nwipe Quick"
        device::exec_scsi_nwipe "$dev"
        return
    fi

    echo "$dev STATUS FAILED" >&3
    echo "$dev LOG sanitize failed; manual physical destruction required" >&3
}

