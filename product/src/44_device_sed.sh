# =============================================================================
# DEVICE SED (OPAL self-encrypting drive crypto-erase)
# =============================================================================
# A self-encrypting drive (TCG OPAL) that has no NVMe/ATA/SCSI firmware erase
# can still be crypto-erased at the OPAL layer: take ownership, enable + lock
# the global locking range, then revert the TPer, which destroys the media
# encryption key and renders all data unrecoverable (NIST 800-88 Purge).
# Recipe mirrors BitRaser's OPAL flow (research/bitraser/README.md + sedutil
# 1.20.0 CLI): --initialSetup -> --enableLockingRange 0 -> --setLockingRange
# 0 LK -> --revertTPer.

device::exec_opal() {
    local dev="$1"
    local ctrl="/dev/${dev%n*}"   # nvme0n1 -> /dev/nvme0 ; sda -> /dev/sda
    local pwd out rc

    if ! command -v sedutil-cli >/dev/null 2>&1; then
        echo "$dev STATUS FAILED" >&3
        echo "$dev LOG sedutil-cli not found; cannot crypto-erase SED" >&3
        return
    fi

    echo "$dev STATUS RUNNING" >&3
    echo "$dev LOG OPAL crypto-erase started" >&3
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: OPAL CRYPTO-ERASE" >> "$LOG_FILE"

    # Per-run random SID password — never a fixed credential.
    pwd="$(cat /proc/sys/kernel/random/uuid 2>/dev/null \
        || uuidgen 2>/dev/null \
        || printf 'tscrub-%s' "$$")"

    # 1. Take ownership (sets SID + Admin1 to the random password). Without SID
    #    authority revertTPer cannot run, so this is a hard gate.
    out="$(sedutil-cli --initialSetup "$pwd" "$ctrl" 2>&1)"; rc=$?
    if (( rc != 0 )); then
        echo "$out" >&5
        echo "$dev STATUS FAILED" >&3
        echo "$dev LOG OPAL crypto-erase failed: cannot take ownership (already owned?); manual physical destruction required" >&3
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: OPAL CRYPTO-ERASE FAILED" >> "$LOG_FILE"
        return
    fi

    # 2. Enable + lock the global range, then revert the TPer (key destruction).
    out+=$'\n'"$(sedutil-cli --enableLockingRange 0 "$pwd" "$ctrl" 2>&1)"
    out+=$'\n'"$(sedutil-cli --setLockingRange 0 LK admin1 "$pwd" "$ctrl" 2>&1)"
    out+=$'\n'"$(sedutil-cli --revertTPer "$pwd" "$ctrl" 2>&1)"; rc=$?
    echo "$out" >&5

    if (( rc == 0 )) && grep -q "revertTper completed" <<<"$out"; then
        echo "$dev STATUS COMPLETED" >&3
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: OPAL CRYPTO-ERASE COMPLETED" >> "$LOG_FILE"
    else
        echo "$dev STATUS FAILED" >&3
        echo "$dev LOG OPAL crypto-erase failed (owned/unsupported SED?); manual physical destruction required" >&3
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: OPAL CRYPTO-ERASE FAILED" >> "$LOG_FILE"
    fi
}
