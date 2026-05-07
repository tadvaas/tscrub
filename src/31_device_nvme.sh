# =============================================================================
# DEVICE NVMe
# =============================================================================

device::exec_nvme() {
    local dev="$1"
    local cap="$2"

    echo "$dev STATUS RUNNING" >&3
    echo "$dev LOG NVMe operation started" >&3

    case "$cap" in
        CAP_NVME_CLEAR_ONLY)
            if nvme format /dev/$dev -s 1 -f >&5 2>&5; then
                echo "$dev STATUS COMPLETED" >&3
            else
                echo "$dev STATUS FAILED" >&3
            fi
            return
            ;;
        CAP_NVME_PURGE_CRYPTO)
            nvme sanitize /dev/$dev -a 4 >&5 2>&5
            ;;
        CAP_NVME_PURGE_BLOCK)
            nvme sanitize /dev/$dev -a 2 >&5 2>&5
            ;;
        CAP_NVME_PURGE_OVERWRITE)
            nvme sanitize /dev/$dev -a 3 >&5 2>&5
            ;;
        *)
            echo "$dev STATUS FAILED" >&3
            return
            ;;
    esac

    device::monitor_nvme "$dev"
}

device::monitor_nvme() {
    local dev="$1"
    local timeout=$((60 * 60 * 6))
    local start=$(date +%s)

    while :; do
        sleep 2
        log="$(nvme sanitize-log /dev/$dev 2>&1 || true)"

        # Detect explicit success message
        if grep -q "Success formatting namespace" <<<"$log"; then
            echo "$dev STATUS COMPLETED" >&3
            return
        fi

        sstat=$(awk '/SSTAT/ {print $NF}' <<<"$log")
        sprog=$(awk '/SPROG/ {print $NF}' <<<"$log")

        if [[ "$sstat" == "0x101" || "$sprog" == "65535" ]]; then
            echo "$dev STATUS COMPLETED" >&3
            return
        fi

        if [[ "$sprog" =~ ^[0-9]+$ ]]; then
            pct=$(( sprog * 100 / 65535 ))
            echo "$dev STATUS ${pct}%" >&3
        fi
        
        if (( $(date +%s) - start > timeout )); then
            echo "$dev STATUS FAILED" >&3
            return 1
        fi
    done
}

