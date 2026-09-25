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

