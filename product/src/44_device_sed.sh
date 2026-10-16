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

# TCG PSID revert: crypto-erase + factory-reset a locked SED using the drive's
# printed PSID. Shared primitive behind both the interactive `ask` prompt and
# the automatic `psid` policy (--disable-block-sid). The PSID is a kill-switch
# credential: it is never logged, never echoed, and never written into the
# report — only the boolean `psid_revert` outcome is recorded.
#
# Success is decided by the drive's OWN re-query (`Locked = N`), never by a
# command's exit code or stdout. The unlock cascade tries, least-destructive
# first (BitRaser's SED methods, minus the re-own-and-revert flow whose unlock
# step is the same PSID revert):
#   1. plain PSID revert (--yesIreallywanttoERASEALLmydatausingthePSID)
#   2. Admin-SP PSID revert (--PSIDrevertAdminSP) — drives whose PSID
#      authority lives on the Admin SP rather than the SID authority
device::psid_verified_unlocked() {
    local ctrl="$1"
    sedutil-cli --query "$ctrl" 2>/dev/null | grep -q "Locked = N"
}

device::psid_revert() {
    local dev="$1" psid="$2"
    local ctrl="/dev/${dev%n*}"   # nvme0n1 -> /dev/nvme0 ; sda -> /dev/sda
    local out method reason

    if ! command -v sedutil-cli >/dev/null 2>&1; then
        echo "$dev: PSID revert unavailable — sedutil-cli not found" >&5
        devrow["$dev.psid_revert"]="failed"
        return 1
    fi

    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: PSID_REVERT" >> "$LOG_FILE"

    # 1. Plain PSID revert. The command's output is captured ONLY to classify a
    #    refusal — it is never written to the log or report (it can echo the
    #    credential).
    method="PSID revert"
    out="$(sedutil-cli --yesIreallywanttoERASEALLmydatausingthePSID "$psid" "$ctrl" 2>&1)"
    if device::psid_verified_unlocked "$ctrl"; then
        opal_locked[$dev]="NO"
        devrow["$dev.psid_revert"]="yes"
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] VERIFICATION: SUCCESS - Drive /dev/$dev is UNLOCKED ($method)" >> "$LOG_FILE"
        return 0
    fi

    # 2. Admin-SP PSID revert — some drives gate the PSID on the Admin SP.
    method="Admin-SP PSID revert"
    out="$(sedutil-cli --PSIDrevertAdminSP "$psid" "$ctrl" 2>&1)"
    if device::psid_verified_unlocked "$ctrl"; then
        opal_locked[$dev]="NO"
        devrow["$dev.psid_revert"]="yes"
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] VERIFICATION: SUCCESS - Drive /dev/$dev is UNLOCKED ($method)" >> "$LOG_FILE"
        return 0
    fi

    devrow["$dev.psid_revert"]="failed"
    if printf '%s' "$out" | grep -qiE 'NOT.?AUTHORIZED'; then
        reason="rejected (wrong PSID, or Block SID authentication is enabled)"
    else
        reason="failed (drive still locked — Block SID may be enabled)"
    fi
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] VERIFICATION: FAILED - Drive /dev/$dev remains LOCKED ($reason)" >> "$LOG_FILE"
    return 1
}
