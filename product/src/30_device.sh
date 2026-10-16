# =============================================================================
# DEVICE
# =============================================================================

device::capability_label() {
    case "$1" in
        CAP_NVME_PURGE_CRYPTO)     echo "NVMe Purge (Crypto Erase)" ;;
        CAP_NVME_PURGE_BLOCK)      echo "NVMe Purge (Block Erase)" ;;
        CAP_NVME_PURGE_OVERWRITE)  echo "NVMe Purge (Overwrite)" ;;
        CAP_NVME_CLEAR_ONLY)       echo "NVMe Clear Only (Format)" ;;
        CAP_ATA_PURGE_ENHANCED)      echo "ATA Purge (Enhanced Erase)" ;;
        CAP_ATA_CLEAR)               echo "ATA Clear (Secure Erase)" ;;
        CAP_SCSI_SANITIZE)           echo "SCSI Sanitize (Overwrite)" ;;
        CAP_SCSI_NWIPE)              echo "SCSI Clear (nwipe Quick)" ;;
        CAP_OPAL_CRYPTO)             echo "SED Purge (OPAL Crypto Erase)" ;;
        CAP_NONE)                    echo "No Supported Wipe" ;;
    esac
}

device::install_sedutil() {
    if ! command -v sedutil-cli &> /dev/null; then
        if [[ -z "${SEDUTIL_PAYLOAD_B64:-}" ]]; then
            printf "%s[!] sedutil-cli not found and no embedded payload — SED support unavailable.\n" "$TABLE_INDENT"
            return
        fi
        printf "%s[!] sedutil-cli not found. Extracting embedded binary...\n" "$TABLE_INDENT"

        if printf '%s' "$SEDUTIL_PAYLOAD_B64" | base64 -d > /usr/bin/sedutil-cli 2>/dev/null; then
            chmod +x /usr/bin/sedutil-cli
            printf "%s[+] sedutil-cli installed successfully.\n" "$TABLE_INDENT"
            sleep 1
        else
            printf "%s[!] Failed to extract sedutil-cli.\n" "$TABLE_INDENT"
            sleep 3
        fi
    fi
}

# Fall back to the kernel's block-layer inquiry files for model/serial when
# the hdparm/nvme pass-through returned nothing (e.g. SG_IO rejected on an
# older SATA controller, or a drive whose IDENTIFY is blocked while frozen).
# These sysfs files are populated by the driver for every sd*/nvme* device and
# need no pass-through.
device::identity_fallback() {
    local dev="$1" block_dir="$2"
    if [[ -z "${serial[$dev]:-}" && -r "$block_dir/$dev/device/serial" ]]; then
        serial[$dev]="$(tr -d ' \000-\037\177' < "$block_dir/$dev/device/serial" 2>/dev/null)"
    fi
    if [[ -z "${model[$dev]:-}" && -r "$block_dir/$dev/device/model" ]]; then
        model[$dev]="$(tr -d '\000-\037\177' < "$block_dir/$dev/device/model" 2>/dev/null)"
    fi
}

# True (exit 0) when the drive's identity looks like a hypervisor/emulated disk
# (QEMU/KVM, VMware, VirtualBox, Hyper-V, Xen, virtio). These can never be OPAL
# SEDs, so the OPAL probe is skipped — sedutil's SG_IO ioctl on such disks is
# noisy (and on QEMU can even trap), so probing them is pure console noise.
device::is_virtual_disk() {
    local needle
    needle="$(printf '%s %s' "${1:-}" "${2:-}" | tr '[:lower:]' '[:upper:]')"
    case "$needle" in
        *QEMU*|*VMWARE*|*VBOX*|*VIRTUALBOX*|*VIRTIO*|*XEN*|*HYPER-V*|*MSFT\ VIRTUAL*|*VIRTUAL\ DISK*)
            return 0 ;;
    esac
    return 1
}

device::discover() {
    devices=()
    NO_SUPPORTED_DRIVES=0
    DISCOVERY_NOTICE=""
    # Ensure arrays are accessible globally as they are used in the table
    declare -Ag serial
    declare -Ag bus
    declare -Ag model
    declare -Ag size
    declare -Ag type
    declare -Ag opal_locked
    declare -Ag firmware
    declare -Ag secsize
    declare -Ag sectors
    declare -Ag hpa
    declare -Ag dco
    declare -Ag raid

    # Allow tests to point discovery at a fake block-device tree.
    local block_dir="${SYS_BLOCK_DIR:-/sys/block}"

    for path in "$block_dir"/*; do
        dev="${path##*/}"

        # Filter out virtual devices and optical drives
        [[ "$dev" =~ ^(loop|ram|dm-) ]] && continue
        [[ "$dev" =~ ^sr[0-9]+$ ]] && continue

        # --- NVMe DISCOVERY ---
        if [[ "$dev" =~ ^nvme[0-9]+(c[0-9]+)?n[0-9]+$ ]]; then
            # Exclude USB-attached NVMe (a USB bridge hides the true drive).
            # Thunderbolt is PCIe-attached and intentionally NOT excluded.
            if realpath "$block_dir/$dev/device" 2>/dev/null | grep -q '/usb'; then
                continue
            fi
            devices+=("$dev")
            bus[$dev]="NVMe"
            type[$dev]="SSD"
            
            # Map nvme0n1 -> /dev/nvme0 for sedutil-cli
            local ctrl_dev="/dev/${dev%n*}" 

            # Metadata extraction
            serial[$dev]=$(nvme id-ctrl /dev/$dev 2>/dev/null | awk -F': *' '/^sn[[:space:]]*:/{print $2}' | xargs | tr -d '\000-\037\177')
            model[$dev]=$(nvme id-ctrl /dev/$dev 2>/dev/null | awk -F': *' '/^mn[[:space:]]*:/{print $2}' | xargs | tr -d '\000-\037\177')
            device::identity_fallback "$dev" "$block_dir"
            
            # OPAL/SED lock state (three-way: locked / not locked / unsupported).
            # Emulated disks (QEMU/VMware/…) can never be SEDs — skip the probe.
            local _sedout
            if device::is_virtual_disk "${model[$dev]}" "${serial[$dev]}"; then
                opal_locked[$dev]="NA"
            else
                _sedout="$(sedutil-cli --query "$ctrl_dev" 2>/dev/null)"
                if grep -q "Locked = Y" <<<"$_sedout"; then
                    opal_locked[$dev]="YES"
                elif grep -q "Locked = N" <<<"$_sedout"; then
                    opal_locked[$dev]="NO"
                else
                    opal_locked[$dev]="NA"
                fi
            fi

            # Firmware revision (NVMe: `fr`), logical sector size, total sectors.
            firmware[$dev]=$(nvme id-ctrl /dev/$dev 2>/dev/null | awk -F': *' '/^fr[[:space:]]*:/{print $2}' | xargs | tr -d '\000-\037\177')
            secsize[$dev]="$(blockdev --getss /dev/$dev 2>/dev/null)"
            [[ "${secsize[$dev]}" =~ ^[0-9]+$ ]] || secsize[$dev]=""
            hpa[$dev]="N/A"
            dco[$dev]="N/A"

            bytes=$(blockdev --getsize64 /dev/$dev 2>/dev/null)
            if [[ -n "$bytes" && "$bytes" =~ ^[0-9]+$ ]]; then
                size[$dev]=$(( bytes / 1000000000 ))" GB"
                sectors[$dev]=$(( bytes / 512 ))
            else
                size[$dev]="N/A"
                sectors[$dev]=""
            fi

        # --- SATA/SCSI DISCOVERY ---
        elif [[ "$dev" =~ ^sd[a-z]+$ ]]; then
            # Accept any non-USB block device (SATA, SCSI, SAS) — hdparm determines capability
            if ! realpath "$block_dir/$dev/device" | grep -q '/usb'; then
                devices+=("$dev")
                
                # Check for rotation (HDD vs SSD)
                if [[ -f "$block_dir/$dev/queue/rotational" ]]; then
                    if [[ "$(cat "$block_dir/$dev/queue/rotational")" -eq 1 ]]; then
                        type[$dev]="HDD"
                    else
                        type[$dev]="SSD"
                    fi
                else
                    type[$dev]="N/A"
                fi

                # Detect Bus/Transport
                if [[ -f "$block_dir/$dev/device/transport" ]]; then
                    transport=$(< "$block_dir/$dev/device/transport")
                    # Show kernel-reported transport directly for broader SCSI visibility.
                    bus[$dev]="${transport^^}"
                else
                    # Fallback for transport detection
                    link=$(readlink -f "$block_dir/$dev" 2>&5)
                    if [[ "$link" == *ata* ]]; then
                        bus[$dev]="SATA"
                    elif [[ "$link" == *sas* ]]; then
                        bus[$dev]="SAS"
                    else
                        # PCIe-attached SAS/SCSI target without a transport file:
                        # report SCSI, not the misleading "PCI".
                        bus[$dev]="SCSI"
                    fi
                fi

                # Extract Serial and Model via hdparm
                serial[$dev]=$(hdparm -I /dev/$dev 2>/dev/null | awk -F': *' '/^[[:space:]]*Serial Number/ {print $2}' | xargs | tr -d ' \000-\037\177')
                model[$dev]=$(hdparm -I /dev/$dev 2>/dev/null | awk -F': *' '/^[[:space:]]*Model Number/ {print $2}' | xargs | tr -d '\000-\037\177')
                device::identity_fallback "$dev" "$block_dir"
                
                # OPAL/SED lock state (three-way). Emulated disks (QEMU/VMware/…)
                # can never be SEDs — skip the noisy SG_IO probe.
                local _sedout _hpa _dco
                if device::is_virtual_disk "${model[$dev]}" "${serial[$dev]}"; then
                    opal_locked[$dev]="NA"
                else
                    _sedout="$(sedutil-cli --query "/dev/$dev" 2>/dev/null)"
                    if grep -q "Locked = Y" <<<"$_sedout"; then
                        opal_locked[$dev]="YES"
                    elif grep -q "Locked = N" <<<"$_sedout"; then
                        opal_locked[$dev]="NO"
                    else
                        opal_locked[$dev]="NA"
                    fi
                fi

                # Firmware revision + logical sector size, and ATA hidden-area
                # state (HPA via `hdparm -N`, DCO via `--dco-identify`).
                firmware[$dev]=$(hdparm -I /dev/$dev 2>/dev/null | awk -F': *' '/^[[:space:]]*Firmware Revision/ {print $2}' | xargs | tr -d '\000-\037\177')
                secsize[$dev]="$(blockdev --getss /dev/$dev 2>/dev/null)"
                [[ "${secsize[$dev]}" =~ ^[0-9]+$ ]] || secsize[$dev]=""

                _hpa="$(hdparm -N /dev/$dev 2>/dev/null)"
                if [[ "$_hpa" == *"HPA is enabled"* ]]; then
                    hpa[$dev]="Enabled"
                elif [[ "$_hpa" == *"HPA is disabled"* ]]; then
                    hpa[$dev]="Disabled"
                else
                    hpa[$dev]="N/A"
                fi

                _dco="$(hdparm --dco-identify /dev/$dev 2>/dev/null)"
                if [[ "$_dco" == *"DCO Revision"* ]]; then
                    dco[$dev]="Present"
                elif [[ "$_dco" == *"not supported"* || -z "$_dco" ]]; then
                    dco[$dev]="Doesn't exist"
                else
                    dco[$dev]="N/A"
                fi

                bytes=$(blockdev --getsize64 /dev/$dev 2>/dev/null)
                if [[ -n "$bytes" && "$bytes" =~ ^[0-9]+$ ]]; then
                    size[$dev]=$(( bytes / 1000000000 ))" GB"
                    sectors[$dev]=$(( bytes / 512 ))
                else
                    size[$dev]="N/A"
                    sectors[$dev]=""
                fi
            else
                continue
            fi
        else
            continue
        fi

        device::raid_detect "$dev"
    done

    # Return control to the caller so the UI can still render system information.
    if [[ ${#devices[@]} -eq 0 ]]; then
        NO_SUPPORTED_DRIVES=1
        DISCOVERY_NOTICE="No supported drives found."
        return 1
    fi
}

# Re-run discovery after a hot-plug change, probing ONLY the newly arrived
# drives (identity + capability + pre-wipe SMART) so existing drives keep their
# cached state and the wipe set is never disturbed. Leaves the already-known
# rows untouched (their devrow status survives — important on the finish
# screen). Returns 0 always.
device::rediscover() {
    local dev
    local -A _known=()
    for dev in "${devices[@]}"; do _known[$dev]=1; done

    if ! device::discover; then
        # Every drive was removed — rebuild an empty table so the UI shows the
        # "no supported drives" state.
        table::build
        return 0
    fi

    local -a _all=("${devices[@]}")
    local -a _new=()
    for dev in "${_all[@]}"; do
        [[ -n "${_known[$dev]:-}" ]] || _new+=("$dev")
    done

    if [[ ${#_new[@]} -gt 0 ]]; then
        # Narrow `devices` to the newcomers so detect + SMART pre only probe
        # them, then restore the full list.
        devices=("${_new[@]}")
        device::detect
        smart::capture_all pre
        devices=("${_all[@]}")

        # Populate the new rows exactly like table::build does.
        for dev in "${_new[@]}"; do
            devrow["$dev.device"]="$dev"
            devrow["$dev.model"]="${model[$dev]:-N/A}"
            devrow["$dev.serial"]="${serial[$dev]:-N/A}"
            devrow["$dev.size"]="${size[$dev]}"
            devrow["$dev.bus"]="${bus[$dev]}"
            devrow["$dev.type"]="${type[$dev]}"
            devrow["$dev.capability"]="${capability[$dev]}"
            devrow["$dev.status"]="PLANNED"
            devrow["$dev.eta_mins"]=""
            devrow["$dev.wipe_start"]=""
            device::classify "$dev"
        done
    fi
    return 0
}

device::psid_lookup() {
    local dev="$1" key
    key="$(printf '%s' "${serial[$dev]:-}" | xargs | tr '[:lower:]' '[:upper:]')"
    [[ -n "$key" ]] && printf '%s' "${PSID_MAP[$key]:-}"
}

# Handle hardware locks on SED (OPAL) drives per TS_BLOCK_SID_POLICY:
#   ask  — interactive PSID prompt (operator types the 32-char PSID).
#   psid — automatic: one PSID revert per locked drive whose serial is in the
#          on-stick PSID map (psids.conf). No terminal is read, so an
#          unattended bench never blocks; a missing/refused PSID degrades to
#          physical destruction, not a retry loop.
#   off  — no PSID attempt; locked drives go straight to physical destruction.
# A successful revert sets opal_locked=NO so device::detect (later) classifies
# the now-unlocked drive for a normal erase instead of PHYS_DESTR.
device::handle_locks() {
    local dev psid attempt max_attempts

    for dev in "${devices[@]}"; do
        # Only process if discovery flagged it as YES
        if [[ "${opal_locked[$dev]}" == "YES" ]]; then
            # Record the lock shape for the report: a TCG-locked SED
            # (read/write protected). The "password" shape (purge-gated but
            # read/write fine) is set later in device::normalize_outcome when a
            # purge returns 0x4286/0x4015.
            devrow["$dev.lock_kind"]="locked"

            case "${TS_BLOCK_SID_POLICY:-ask}" in
                off)
                    echo "$dev: PSID handling disabled (--disable-block-sid=off) — physical destruction required" >&5
                    continue
                    ;;
                psid)
                    device::psid_unlock_auto "$dev"
                    continue
                    ;;
            esac

            # --- ask (default): interactive, one bounded retry loop ---
            attempt=1
            max_attempts=20

            while [ $attempt -le $max_attempts ]; do
                clear
                printf "\n%s[!] HARDWARE LOCK DETECTED: /dev/%s (Attempt %d/%d)\n" \
                    "$TABLE_INDENT" "$dev" "$attempt" "$max_attempts"
                printf "%sModel:  %s\n" "$TABLE_INDENT" "${model[$dev]}"
                printf "%sSerial: %s\n" "$TABLE_INDENT" "${serial[$dev]}"
                printf "%s%s\n" "$TABLE_INDENT" "$(printf "%*s" 60 "" | tr ' ' '-')"

                ui::cursor_show
                printf "%sEnter 32-char PSID (blank to skip): " "$TABLE_INDENT"
                if ! read -r psid < /dev/tty 2>/dev/null; then
                    ui::cursor_hide
                    echo "$dev: no terminal — PSID prompt aborted" >&5
                    break
                fi
                ui::cursor_hide

                # Trim whitespace and force uppercase.
                psid="$(printf "%s" "$psid" | xargs | tr '[:lower:]' '[:upper:]')"

                # Exit loop if user skips.
                [[ -z "$psid" ]] && break

                # Length Guard (PSIDs are strictly 32 chars).
                if [[ ${#psid} -ne 32 ]]; then
                    printf "\n%s[!] Error: Input is %d chars. PSID must be 32.\n" "$TABLE_INDENT" "${#psid}"
                    sleep 2
                    ((attempt++))
                    continue
                fi

                printf "\n%s[i] Sending PSID Revert command... " "$TABLE_INDENT"
                if device::psid_revert "$dev" "$psid"; then
                    printf "SUCCESS!\n"
                    printf "%sHardware lock removed. Drive is ready for sanitization.\n" "$TABLE_INDENT"
                    sleep 2
                    break
                fi
                printf "FAILED.\n"
                printf "%sThe drive is still locked. Check %s for errors.\n" "$TABLE_INDENT" "$LOG_FILE"
                ((attempt++))
                sleep 2
            done

            if [[ "${opal_locked[$dev]}" == "YES" ]]; then
                printf "%s[!] Warning: /dev/%s remains LOCKED and will be marked PHYS_DESTR.\n" "$TABLE_INDENT" "$dev"
                sleep 2
            fi
        fi
    done
}

# One suspend-to-RAM cycle (ACPI S3). The freeze lock is volatile and is
# re-asserted only at POST, so entering S3 and resuming clears it on both ATA
# and NVMe drives. Kept as its own seam so tests can stub it and so the ATA
# unfreeze loop and the NVMe retry path share one implementation.
# NOTE: rtcwake must never run inside command substitution — the suspend would
# hold the subshell's pipe open (same class as the SMART watchdog fix).
device::flr_suspend_resume() {
    local secs="${TS_FLR_SUSPEND_SECS:-5}"
    rtcwake -m mem -s "$secs" >&5 2>&5
    return 0
}

# Vendor-tuned remediation hint for a lock the software could not clear on its
# own. The manufacturer comes from the DMI capture (SYS_MANUFACTURER), so a
# generic "clear Block SID in BIOS" becomes the menu the operator actually has.
device::lock_hint() {
    local kind="$1"
    local up="$(printf '%s' "${SYS_MANUFACTURER:-}" | tr '[:lower:]' '[:upper:]')"
    case "$kind" in
        frozen)
            case "$up" in
                *DELL*)     echo "enable S3 suspend: BIOS -> Power Management -> uncheck 'Block Sleep (S3 State)', then re-run" ;;
                *MICROSOFT*|*SURFACE*) echo "this BIOS may not support suspend-to-RAM; try a newer (or older) BIOS, then re-run" ;;
                *)          echo "power-cycle (or suspend/resume) the machine, or enable Suspend-to-RAM (S3) in the BIOS, then re-run" ;;
            esac
            ;;
        block_sid)
            case "$up" in
                *LENOVO*)   echo "clear Block SID: BIOS -> Security -> Hard Drive Utilities (or Hard Disk Passwords) -> clear Block SID, then re-run" ;;
                *)          echo "clear Block SID / hard-disk security in the BIOS, or move the drive to another machine, then re-run" ;;
            esac
            ;;
        *)          echo "power-cycle the machine and re-run" ;;
    esac
}

# Remove freeze locks so firmware-erasure commands can run. ATA drives expose
# the lock directly in IDENTIFY (hdparm); NVMe has no equivalent up-front
# probe, so NVMe is handled at execution time (device::exec_nvme retries a
# failed command after one suspend/resume). Honours TS_FLR_MODE:
#   auto     — suspend/resume up to TS_FLR_MAX_ATTEMPTS times
#   forced   — same, plus a final longer suspend before giving up
#   disabled — skip entirely (leave the lock in place)
device::unfreeze() {
    local dev status i times secs

    for dev in "${devices[@]}"; do
        if [[ "$dev" != sd* || ( "${bus[$dev]}" != "SATA" && "${bus[$dev]}" != "ATA" ) ]]; then
            continue
        fi
        if [[ "${TS_FLR_MODE:-auto}" == "disabled" ]]; then
            continue
        fi

        i=1
        times="${TS_FLR_MAX_ATTEMPTS:-5}"
        while :; do
            status="$(hdparm -I /dev/$dev 2>&5)"
            if [[ $status == *"not"?"frozen"* || $status != *"frozen"* ]]; then
                # Confirm success only when we actually unfroze it — a drive
                # that was never frozen needs no line (keeps the boot quiet).
                if [[ "$i" -gt 1 ]]; then
                    printf "%s%s: not frozen\n" "$TABLE_INDENT" "$dev"
                    devrow["$dev.freeze_state"]="removed"
                    devrow["$dev.flr_attempts"]="$((i - 1))"
                fi
                break
            fi
            # Frozen: one clear line instead of the old "frozen" then
            # "unfreezing" pair — the unfreeze IS a suspend/resume, so say
            # so (the brief blank screen is the machine suspending to RAM).
            printf "%s%s: frozen — suspending to clear the freeze lock (attempt %s/%s)\n" "$TABLE_INDENT" "$dev" "$i" "$times"
            sleep 1 #give some time to CTRL+C if wanted
            secs="${TS_FLR_SUSPEND_SECS:-5}"
            # Forced mode escalates the final attempt to a longer suspend — some
            # firmwares only release the lock on a deeper sleep cycle.
            if [[ "${TS_FLR_MODE:-auto}" == "forced" && "$i" -eq "$times" ]]; then
                secs=10
            fi
            rtcwake -m mem -s "$secs" >&5 2>&5
            if [[ $i -ge $times ]]; then
                devrow["$dev.freeze_state"]="stuck"
                devrow["$dev.flr_attempts"]="$i"
                if [[ "${AUTONUKE:-0}" -eq 1 ]]; then
                    printf "%s%s: still frozen — giving up (autonuke)\n" "$TABLE_INDENT" "$dev"
                    break
                fi
                printf "%s%s: still frozen — %s\n" "$TABLE_INDENT" "$dev" "$(device::lock_hint frozen)"
                printf "%sTried unfreezing %s %s times: Continue? [Y/n]: " "$TABLE_INDENT" "$dev" "$i"
                if ! read -r answer < /dev/tty 2>/dev/null; then
                    printf "%s%s: aborted (no terminal).\n" "$TABLE_INDENT" "$dev"
                    exit 1
                fi
                i=0
                if [[ "$answer" != "${answer#[Nn]}" ]]; then
                    printf "%s%s: aborted.\n" "$TABLE_INDENT" "$dev"
                    exit 0
                fi
            fi
            ((i++))
        done
    done
}

device::detect() {
    declare -Ag capability
    declare -Ag ata_erase_time
    declare -Ag ata_enhanced_time
    local features

    for device in "${devices[@]}"; do
        # Drives that remain OPAL-locked (including user-skipped PSID unlock) must be physically destroyed.
        if [[ "${opal_locked[$device]}" == "YES" ]]; then
            capability[$device]="CAP_NONE"
            continue
        fi

        capability[$device]="CAP_NONE"

        if [[ "$device" == nvme* ]]; then
            features="$(nvme id-ctrl -H /dev/$device 2>&5 || true)"

            case "$features" in
                *"Crypto Erase Sanitize Operation Supported"*)
                    capability[$device]="CAP_NVME_PURGE_CRYPTO"
                    ;;
                *"Block Erase Sanitize Operation Supported"*)
                    capability[$device]="CAP_NVME_PURGE_BLOCK"
                    ;;
                *"Overwrite Sanitize Operation Supported"*)
                    capability[$device]="CAP_NVME_PURGE_OVERWRITE"
                    ;;
                *"Format NVM Supported"*)
                    capability[$device]="CAP_NVME_CLEAR_ONLY"
                    ;;
            esac

            elif [[ "$device" == sd* ]]; then
                if [[ "${bus[$device]}" != "SATA" && "${bus[$device]}" != "ATA" ]]; then
                    # SCSI/SAS: prefer the firmware SANITIZE (overwrite) path,
                    # falling back to software nwipe only when unsupported.
                    if device::scsi_sanitize_supported "$device"; then
                        capability[$device]="CAP_SCSI_SANITIZE"
                    else
                        capability[$device]="CAP_SCSI_NWIPE"
                    fi
                    continue
                fi

                features="$(hdparm -I /dev/$device 2>&5 || true)"
                security_block="$(
                    hdparm -I /dev/$device 2>&5 |
                    awk '
                        /^Security:/ {in_sec=1; next}
                        in_sec && /^[^[:space:]]/ {exit}
                        in_sec {print}
                    '
                )"

                ata_erase_time[$device]=$(printf '%s\n' "$security_block" | sed -n 's/.*[[:space:]]\([0-9][0-9]*\)min for SECURITY ERASE UNIT.*/\1/p' | head -1)
                ata_enhanced_time[$device]=$(printf '%s\n' "$security_block" | sed -n 's/.*[[:space:]]\([0-9][0-9]*\)min for ENHANCED SECURITY ERASE UNIT.*/\1/p' | head -1)

                if ! grep -qE '^[[:space:]]+supported' <<<"$security_block"; then
                    # No ATA Security Mode feature set — leave CAP_NONE so the
                    # OPAL fallback below can still crypto-erase a SED.
                    capability[$device]="CAP_NONE"
                elif grep -Eq '^[[:space:]]*frozen[[:space:]]*$' <<<"$security_block"; then
                    capability[$device]="CAP_ATA_FROZEN"
                elif grep -qE '^[[:space:]]+supported: enhanced erase' <<<"$security_block"; then
                    capability[$device]="CAP_ATA_PURGE_ENHANCED"
                else
                    # Security Mode feature set is "supported" (checked above), the
                    # drive is not frozen, and enhanced erase is not advertised.
                    # SECURITY ERASE UNIT is a mandatory command of the Security
                    # Mode feature set, so a normal Secure Erase (Clear) is always
                    # available here. Do NOT key on the "Nmin for SECURITY ERASE
                    # UNIT" timing line — hdparm omits it when the drive reports
                    # word 89 as 0 (e.g. old Seagate Momentus drives), which
                    # previously misclassified such drives as CAP_NONE -> FAILED.
                    capability[$device]="CAP_ATA_CLEAR"
                fi
            fi

        # Unlocked SED (OPAL) fallback: a drive with no firmware NVMe/ATA/SCSI
        # erase can still be crypto-erased at the OPAL layer (revertTPer destroys
        # the encryption key). Only when unlocked — a locked drive is CAP_NONE and
        # goes to the PSID-revert prompt / physical destruction.
        if [[ "${capability[$device]}" == "CAP_NONE" && "${opal_locked[$device]:-}" == "NO" ]]; then
            capability[$device]="CAP_OPAL_CRYPTO"
        fi

    done
}

device::classify() {
    local dev="$1"
    local cap="${capability[$dev]}"

    case "$cap" in

        CAP_NVME_PURGE_CRYPTO)
            devrow["$dev.class"]="PURGE"
            devrow["$dev.cert"]="DESTRUCTION"
            devrow["$dev.method"]="NVMe Crypto Purge"
            ;;

        CAP_NVME_PURGE_BLOCK)
            devrow["$dev.class"]="PURGE"
            devrow["$dev.cert"]="DESTRUCTION"
            devrow["$dev.method"]="NVMe Block Purge"
            ;;

        CAP_NVME_PURGE_OVERWRITE)
            devrow["$dev.class"]="PURGE"
            devrow["$dev.cert"]="DESTRUCTION"
            devrow["$dev.method"]="NVMe Overwrite Purge"
            ;;

        CAP_ATA_PURGE_ENHANCED)
            devrow["$dev.class"]="PURGE"
            devrow["$dev.cert"]="DESTRUCTION"
            devrow["$dev.method"]="Enhanced Erase"
            local _t="${ata_enhanced_time[$dev]:-${ata_erase_time[$dev]:-}}"
            if [[ "$_t" =~ ^[0-9]+$ ]]; then devrow["$dev.eta_mins"]="$_t"; fi
            ;;

        CAP_ATA_CLEAR)
            # `hdparm --security-erase` (non-enhanced) is a Clear per NIST 800-88
            # for both HDD and SSD — the label must match what is actually run.
            devrow["$dev.class"]="CLEAR"
            devrow["$dev.cert"]="SANITISATION"
            devrow["$dev.method"]="ATA Secure Erase"
            local _t="${ata_erase_time[$dev]:-}"
            if [[ "$_t" =~ ^[0-9]+$ ]]; then devrow["$dev.eta_mins"]="$_t"; fi
            ;;


        CAP_SCSI_NWIPE)
            devrow["$dev.class"]="CLEAR"
            devrow["$dev.cert"]="SANITISATION"
            devrow["$dev.method"]="nwipe Quick"
            ;;

        CAP_SCSI_SANITIZE)
            devrow["$dev.class"]="PURGE"
            devrow["$dev.cert"]="DESTRUCTION"
            devrow["$dev.method"]="SAS Sanitize"
            ;;

        CAP_OPAL_CRYPTO)
            devrow["$dev.class"]="PURGE"
            devrow["$dev.cert"]="DESTRUCTION"
            devrow["$dev.method"]="OPAL Crypto Erase"
            ;;

        CAP_NVME_CLEAR_ONLY)
            devrow["$dev.class"]="CLEAR"
            devrow["$dev.cert"]="SANITISATION"
            devrow["$dev.method"]="NVMe Format"
            ;;

        CAP_ATA_FROZEN)
            devrow["$dev.class"]="FROZEN"
            devrow["$dev.cert"]="PHYS_DESTR"
            devrow["$dev.method"]="Frozen Drive"
            ;;

        CAP_NONE)
            devrow["$dev.class"]="FAILED"
            devrow["$dev.cert"]="PHYS_DESTR"
            devrow["$dev.method"]="Phys. Destr."
            ;;

        *)
            devrow["$dev.class"]="FAILED"
            devrow["$dev.cert"]="PHYS_DESTR"
            devrow["$dev.method"]="Phys. Destr."
            ;;
    esac
}

device::execute() {
    local dev="$1"
    local cap="${devrow[$dev.capability]}"

    if [[ "${devrow[$dev.class]}" == "FAILED" ]]; then
        echo "$dev STATUS FAILED" >&3
        return
    fi

    echo "$dev STATUS RUNNING" >&3

    case "$cap" in
        CAP_NVME_*)
            device::exec_nvme "$dev" "$cap"
            ;;
        CAP_SCSI_NWIPE)
            device::exec_scsi_nwipe "$dev"
            ;;
        CAP_SCSI_SANITIZE)
            device::exec_scsi_sanitize "$dev"
            ;;
        CAP_OPAL_CRYPTO)
            device::exec_opal "$dev"
            ;;
        CAP_ATA_*)
            device::exec_ata "$dev" "$cap"
            ;;
        *)
            echo "$dev STATUS FAILED" >&3
            ;;
    esac
}

# After every worker finishes, rewrite the per-drive outcome fields so the
# report (CSV + certificate) is honest. A drive that did not complete must not
# keep the optimistic class/cert/method it was classified for — e.g. a BLOCKED
# NVMe drive was classified "NVMe Crypto Purge / DESTRUCTION" but nothing was
# purged or destroyed. FROZEN is already honest (FROZEN / PHYS_DESTR /
# "Frozen Drive"), so it is left untouched. DRY-RUN wipes nothing, so it is
# normalised to an explicit "Dry run" outcome too.
device::normalize_outcome() {
    local dev
    for dev in "${devices[@]}"; do
        case "${devrow[$dev.status]:-}" in
            COMPLETED|FROZEN)
                ;;
            DRY-RUN)
                devrow["$dev.class"]="DRY-RUN"
                devrow["$dev.cert"]="NOT SANITISED"
                devrow["$dev.method"]="Dry run — no sanitisation performed"
                ;;
            BLOCKED)
                devrow["$dev.class"]="BLOCKED"
                devrow["$dev.cert"]="NOT SANITISED"
                devrow["$dev.method"]="Blocked by firmware (Block SID)"
                devrow["$dev.block_sid"]="yes"
                devrow["$dev.lock_kind"]="password"
                ;;
            STALLED)
                devrow["$dev.class"]="STALLED"
                devrow["$dev.cert"]="NOT SANITISED"
                devrow["$dev.method"]="Sanitisation stalled — power-cycle the drive"
                ;;
            SKIPPED)
                devrow["$dev.class"]="SKIPPED"
                devrow["$dev.cert"]="NOT SANITISED"
                devrow["$dev.method"]="Not selected"
                ;;
            UNKNOWN)
                devrow["$dev.class"]="FAILED"
                devrow["$dev.cert"]="NOT SANITISED"
                devrow["$dev.method"]="Sanitisation did not complete"
                ;;
            *)
                devrow["$dev.class"]="FAILED"
                devrow["$dev.cert"]="NOT SANITISED"
                if [[ "${devrow[$dev.lock_kind]:-none}" == "locked" ]]; then
                    devrow["$dev.method"]="TCG locked — physical destruction required"
                else
                    devrow["$dev.method"]="Sanitisation failed"
                fi
                ;;
        esac
    done
}

