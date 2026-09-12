# =============================================================================
# DEVICE ATA
# =============================================================================

device::exec_ata() {
    local dev="$1"
    local cap="$2"

    if [[ "${bus[$dev]}" != "SATA" && "${bus[$dev]}" != "ATA" ]]; then
        echo "$dev STATUS FAILED" >&3
        echo "$dev LOG Unsupported transport '${bus[$dev]}' for ATA operation" >&3
        return
    fi

    case "$cap" in
        CAP_ATA_FROZEN)
            echo "$dev STATUS FROZEN" >&3
            echo "$dev LOG Drive is frozen – power cycle or suspend required" >&3
            return
            ;;
    esac

    echo "$dev STATUS RUNNING" >&3
    echo "$dev LOG ATA operation started" >&3

    # Set temporary password (required for security erase)
    if ! hdparm --user-master u --security-set-pass p /dev/$dev >&5 2>&5; then
        echo "$dev STATUS FAILED" >&3
        echo "$dev LOG Failed to set ATA security password" >&3
        return
    fi

    case "$cap" in
        CAP_ATA_PURGE_ENHANCED)
            if hdparm --user-master u --security-erase-enhanced p /dev/$dev >&5 2>&5; then
                echo "$dev STATUS COMPLETED" >&3
            else
                echo "$dev STATUS FAILED" >&3
            fi
            return
            ;;

        CAP_ATA_CLEAR)
            if hdparm --user-master u --security-erase p /dev/$dev >&5 2>&5; then
                echo "$dev STATUS COMPLETED" >&3
            else
                echo "$dev STATUS FAILED" >&3
            fi
            return
            ;;

        *)
            echo "$dev STATUS FAILED" >&3
            echo "$dev LOG Unsupported ATA capability" >&3
            return
            ;;
    esac

    device::monitor_ata "$dev"
}

device::monitor_ata() {
    local dev="$1"
    local last_state=""
    local timeout=$((60 * 60 * 24))   # 24h safety net
    local start=$(date +%s)

    while :; do
        sleep 5

        status="$(hdparm --sanitize-status /dev/$dev 2>&5 || true)"

        state=$(awk '/State:/ {print $2}' <<<"$status")
        prog=$(awk '/Progress:/ {print $3}' <<<"$status")

        # Completed
        if [[ "$state" == "SD0" ]]; then
            echo "$dev STATUS COMPLETED" >&3
            return 0
        fi

        # In progress
        if [[ "$state" == "SD1" ]]; then
            if [[ -n "$prog" ]]; then
                echo "$dev STATUS $prog" >&3
            else
                echo "$dev STATUS RUNNING" >&3
            fi
            last_state="$state"
        fi

        # Safety timeout
        if (( $(date +%s) - start > timeout )); then
            echo "$dev STATUS FAILED" >&3
            return 1
        fi
    done
}

