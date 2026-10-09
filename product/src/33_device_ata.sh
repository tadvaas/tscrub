# =============================================================================
# DEVICE ATA
# =============================================================================

# Resolve HPA_MODE: CLI (--hpa) wins, then tscrub.conf (filled in
# config::load_usb), then the kernel cmdline. Default "on".
device::resolve_hpa_mode() {
    if [[ -z "${HPA_MODE:-}" && -r /proc/cmdline ]]; then
        local param
        param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_hpa=//p' | head -n 1)"
        case "$param" in
            on|off) HPA_MODE="$param" ;;
        esac
    fi
    case "${HPA_MODE:-}" in
        on|off) ;;
        *) HPA_MODE="on" ;;
    esac
}

# Best-effort HPA/DCO reset for an ATA drive, before the security password is
# set. Never aborts the erase on failure. Result vocabulary (see
# research/hpa-dco/README.md): removed | firmware-erased | none | failed | n/a.
device::reset_hpa_dco() {
    local dev="$1"
    local _hpa _dco native cap fw_covers=0
    local hpa_enabled=0 dco_present=0 hpa_gone=0 dco_gone=0

    device::resolve_hpa_mode
    [[ "${HPA_MODE:-on}" == "on" ]] || return 0
    [[ "${DRY_RUN:-0}" -eq 0 ]] || return 0
    [[ "${bus[$dev]}" == "SATA" || "${bus[$dev]}" == "ATA" ]] || return 0

    _hpa="$(hdparm -N /dev/$dev 2>/dev/null)"
    _dco="$(hdparm --dco-identify /dev/$dev 2>/dev/null)"

    [[ "$_dco" == *"DCO Revision"* ]] && dco_present=1
    [[ "$_hpa" == *"HPA is enabled"* ]] && hpa_enabled=1

    if [[ "$dco_present" -eq 0 && "$hpa_enabled" -eq 0 ]]; then
        devrow["$dev.hpa_result"]="none"
        devrow["$dev.dco_result"]="none"
        return 0
    fi

    # DCO first — it caps what HPA can be set to.
    if [[ "$dco_present" -eq 1 ]]; then
        hdparm --dco-restore /dev/$dev >&5 2>&5 || true
    fi

    # Native max = the value AFTER the '/' in "max sectors = X/Y".
    _hpa="$(hdparm -N /dev/$dev 2>/dev/null)"
    native="$(sed -n 's/.*max sectors[^/]*\/\([0-9][0-9]*\).*/\1/p' <<<"$_hpa" | head -n 1)"

    if [[ -n "$native" && "$native" =~ ^[0-9]+$ ]]; then
        hdparm -N p"$native" /dev/$dev >&5 2>&5 || true
    fi

    # Re-read to confirm (the honest before/after).
    [[ "$(hdparm -N /dev/$dev 2>/dev/null)" != *"HPA is enabled"* ]] && hpa_gone=1
    [[ "$(hdparm --dco-identify /dev/$dev 2>/dev/null)" != *"DCO Revision"* ]] && dco_gone=1

    cap="${devrow[$dev.capability]:-}"
    [[ "$cap" == "CAP_ATA_PURGE_ENHANCED" ]] && fw_covers=1

    # HPA result
    if [[ "$hpa_enabled" -eq 0 ]]; then
        devrow["$dev.hpa_result"]="none"
    elif [[ "$hpa_gone" -eq 1 ]]; then
        devrow["$dev.hpa_result"]="removed"
    elif [[ "$fw_covers" -eq 1 ]]; then
        devrow["$dev.hpa_result"]="firmware-erased"
    else
        devrow["$dev.hpa_result"]="failed"
    fi

    # DCO result
    if [[ "$dco_present" -eq 0 ]]; then
        devrow["$dev.dco_result"]="none"
    elif [[ "$dco_gone" -eq 1 ]]; then
        devrow["$dev.dco_result"]="removed"
    elif [[ "$fw_covers" -eq 1 ]]; then
        devrow["$dev.dco_result"]="firmware-erased"
    else
        devrow["$dev.dco_result"]="failed"
    fi

    if [[ "${devrow[$dev.hpa_result]}" == "failed" || "${devrow[$dev.dco_result]}" == "failed" ]]; then
        echo "$dev LOG HPA/DCO: could not remove hidden area - data may remain" >&3
    elif [[ "${devrow[$dev.hpa_result]}" == "removed" || "${devrow[$dev.dco_result]}" == "removed" ]]; then
        echo "$dev LOG HPA/DCO: hidden area removed" >&3
    fi
}

# Clear the temporary security password set for the erase so the drive is not
# left "security enabled" (which locks it on the next power cycle). Best-effort:
# the erase outcome is already decided, and a failed disable must never flip a
# completed erase to FAILED.
device::ata_clear_password() {
    local dev="$1"
    hdparm --user-master u --security-disable p /dev/$dev >&5 2>&5 || true
}

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

    # Reset any HPA/DCO hidden area to native BEFORE setting the password, so
    # the erase covers the full native capacity (best-effort, never aborts).
    device::reset_hpa_dco "$dev"

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
            device::ata_clear_password "$dev"
            return
            ;;

        CAP_ATA_CLEAR)
            if hdparm --user-master u --security-erase p /dev/$dev >&5 2>&5; then
                echo "$dev STATUS COMPLETED" >&3
            else
                echo "$dev STATUS FAILED" >&3
            fi
            device::ata_clear_password "$dev"
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

