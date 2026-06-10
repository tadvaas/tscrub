# =============================================================================
# DEVICE NVMe
# =============================================================================

# Report a failed NVMe operation, distinguishing a BIOS/firmware access-rights
# lockdown (NVMe status 0x4286) from a generic controller rejection.
#
# Status 0x4286 = "Access Denied: access to the namespace and/or LBA range is
# denied due to lack of access rights". On many BIOS-managed laptops (notably
# Lenovo) the firmware asserts TCG Block SID at every POST, which gates
# sanitize/format even on an unlocked, unprovisioned SED. Such a drive is
# usually recoverable after clearing Block SID / hard-disk security in firmware,
# so it is flagged BLOCKED rather than failed straight to physical destruction.
device::nvme_fail() {
    local dev="$1"
    local op="$2"
    local out="$3"

    {
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: NVMe ${op} FAILED"
        echo "COMMAND OUTPUT: $out"
        echo "----------------------------------------------------------"
    } >> "$LOG_FILE"

    if grep -qiE '0x4286|Access Denied' <<<"$out"; then
        echo "$dev STATUS BLOCKED" >&3
        echo "$dev LOG ${op} denied (0x4286) — likely BIOS Block SID lockdown; clear Block SID / hard-disk security in firmware and retry" >&3
    else
        echo "$dev STATUS FAILED" >&3
        echo "$dev LOG Controller rejected ${op} command" >&3
    fi
}

device::exec_nvme() {
    local dev="$1"
    local cap="$2"
    local out rc

    echo "$dev STATUS RUNNING" >&3
    echo "$dev LOG NVMe operation started" >&3

    case "$cap" in
        CAP_NVME_CLEAR_ONLY)
            out="$(nvme format /dev/$dev -s 1 -f 2>&1)"; rc=$?
            echo "$out" >&5
            if (( rc == 0 )); then
                echo "$dev STATUS COMPLETED" >&3
            else
                device::nvme_fail "$dev" "format" "$out"
            fi
            return
            ;;
        CAP_NVME_PURGE_CRYPTO)
            out="$(nvme sanitize /dev/$dev -a 4 2>&1)"; rc=$?
            ;;
        CAP_NVME_PURGE_BLOCK)
            out="$(nvme sanitize /dev/$dev -a 2 2>&1)"; rc=$?
            ;;
        CAP_NVME_PURGE_OVERWRITE)
            out="$(nvme sanitize /dev/$dev -a 3 2>&1)"; rc=$?
            ;;
        *)
            echo "$dev STATUS FAILED" >&3
            return
            ;;
    esac

    echo "$out" >&5

    if (( rc != 0 )); then
        device::nvme_fail "$dev" "sanitize" "$out"
        return
    fi

    device::monitor_nvme "$dev"
}

device::monitor_nvme() {
    local dev="$1"
    local timeout=$((60 * 60 * 6))
    local start=$(date +%s)

    while :; do
        sleep 2
        log="$(nvme sanitize-log /dev/$dev 2>&1 || true)"

        sstat=$(awk '/SSTAT/ {print $NF}' <<<"$log")
        sprog=$(awk '/SPROG/ {print $NF}' <<<"$log")

        # Decode the most-recent-sanitize status from SSTAT bits 2:0.
        # 0=never sanitized, 1=completed, 2=in progress, 3=failed, 4=completed w/ dealloc.
        # SPROG=65535 is the idle sentinel and must NOT be treated as success on its own,
        # otherwise a drive that never started a sanitize reports as completed.
        local state=""
        if [[ "$sstat" =~ ^0x[0-9A-Fa-f]+$ || "$sstat" =~ ^[0-9]+$ ]]; then
            state=$(( sstat & 0x7 ))
        fi

        case "$state" in
            1|4)
                echo "$dev STATUS COMPLETED" >&3
                return 0
                ;;
            3)
                echo "$dev STATUS FAILED" >&3
                echo "$dev LOG Controller reported sanitize failure (SSTAT=$sstat)" >&3
                return 1
                ;;
            2)
                if [[ "$sprog" =~ ^[0-9]+$ ]]; then
                    pct=$(( sprog * 100 / 65535 ))
                    echo "$dev STATUS ${pct}%" >&3
                else
                    echo "$dev STATUS RUNNING" >&3
                fi
                ;;
        esac

        if (( $(date +%s) - start > timeout )); then
            echo "$dev STATUS FAILED" >&3
            return 1
        fi
    done
}

