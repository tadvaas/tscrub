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

    # hdparm --security-erase[-enhanced] is synchronous (blocks until done), so
    # no progress monitor is needed here.
}

