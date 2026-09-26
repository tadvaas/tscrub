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
        CAP_SCSI_NWIPE)              echo "SCSI Clear (nwipe Quick)" ;;
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
            
            # OPAL Lock Check
            if sedutil-cli --query "$ctrl_dev" 2>/dev/null | grep -q "Locked = Y"; then
                opal_locked[$dev]="YES"
            else
                opal_locked[$dev]="NO"
            fi

            bytes=$(blockdev --getsize64 /dev/$dev 2>/dev/null)
            if [[ -n "$bytes" && "$bytes" =~ ^[0-9]+$ ]]; then
                size[$dev]=$(( bytes / 1000000000 ))" GB"
            else
                size[$dev]="N/A"
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
                
                # OPAL Lock Check for SATA
                if sedutil-cli --query "/dev/$dev" 2>/dev/null | grep -q "Locked = Y"; then
                    opal_locked[$dev]="YES"
                else
                    opal_locked[$dev]="NO"
                fi

                bytes=$(blockdev --getsize64 /dev/$dev 2>/dev/null)
                if [[ -n "$bytes" && "$bytes" =~ ^[0-9]+$ ]]; then
                    size[$dev]=$(( bytes / 1000000000 ))" GB"
                else
                    size[$dev]="N/A"
                fi
            else
                continue
            fi
        else
            continue
        fi
    done

    # Return control to the caller so the UI can still render system information.
    if [[ ${#devices[@]} -eq 0 ]]; then
        NO_SUPPORTED_DRIVES=1
        DISCOVERY_NOTICE="No supported drives found."
        return 1
    fi
}

device::handle_locks() {
    for dev in "${devices[@]}"; do
        # Only process if discovery flagged it as YES
        if [[ "${opal_locked[$dev]}" == "YES" ]]; then
            local attempt=1
            local max_attempts=20
            local ctrl="/dev/${dev%n*}"

            while [ $attempt -le $max_attempts ]; do
                clear
                printf "\n%s[!] HARDWARE LOCK DETECTED: /dev/%s (Attempt %d/%d)\n" \
                    "$TABLE_INDENT" "$dev" "$attempt" "$max_attempts"
                printf "%sModel:  %s\n" "$TABLE_INDENT" "${model[$dev]}"
                printf "%sSerial: %s\n" "$TABLE_INDENT" "${serial[$dev]}"
                printf "%s%s\n" "$TABLE_INDENT" "$(printf "%*s" 60 "" | tr ' ' '-')"
                
                # --- START COCID STRATEGY ---
                ui::cursor_show
                printf "%sEnter 32-char PSID (blank to skip): " "$TABLE_INDENT"
                
                # Read specifically from tty
                read -r psid < /dev/tty
                ui::cursor_hide

                # Trim whitespace using xargs and force Uppercase
                psid="$(printf "%s" "$psid" | xargs | tr '[:lower:]' '[:upper:]')"
                # --- END COCID STRATEGY ---

                # Exit loop if user skips
                [[ -z "$psid" ]] && break

                # Length Guard (PSIDs are strictly 32 chars)
                if [[ ${#psid} -ne 32 ]]; then
                    printf "\n%s[!] Error: Input is %d chars. PSID must be 32.\n" "$TABLE_INDENT" "${#psid}"
                    sleep 2
                    ((attempt++))
                    continue
                fi

                printf "\n%s[i] Sending PSID Revert command... " "$TABLE_INDENT"
                
                # Execute the PSID revert ONCE and log the result.
                cmd_result=$(sedutil-cli --yesIreallywanttoERASEALLmydatausingthePSID "$psid" "$ctrl" 2>&1)
                
                {
                    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev | ACTION: PSID_REVERT"
                    echo "COMMAND OUTPUT: $cmd_result"
                    echo "----------------------------------------------------------"
                } >> "$LOG_FILE"

                # --- FRESH QUERY VERIFICATION (The Source of Truth) ---
                # We ignore the cmd_result for a moment and ask the drive directly
                current_query=$(sedutil-cli --query "$ctrl" 2>/dev/null)

                if echo "$current_query" | grep -q "Locked = N"; then
                    # The drive is definitely unlocked
                    printf "SUCCESS!\n"
                    printf "%sHardware lock removed. Drive is ready for sanitization.\n" "$TABLE_INDENT"
                    opal_locked[$dev]="NO"
                    
                    # Log the verification success
                    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] VERIFICATION: SUCCESS - Drive /dev/$dev is UNLOCKED" >> "$LOG_FILE"
                    
                    sleep 2
                    break
                else
                    # The drive is still locked. Now we check the result to see WHY.
                    if echo "$cmd_result" | grep -qi "NOT.AUTHORIZED"; then
                        printf "REJECTED.\n"
                        printf "%sThe drive rejected the PSID. Check characters carefully.\n" "$TABLE_INDENT"
                    else
                        printf "FAILED.\n"
                        printf "%sThe drive is still locked. Check $LOG_FILE for errors.\n" "$TABLE_INDENT"
                    fi
                    
                    # Log the verification failure
                    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] VERIFICATION: FAILED - Drive /dev/$dev remains LOCKED" >> "$LOG_FILE"
                    
                    ((attempt++))
                    sleep 2
                fi
            done

            if [[ "${opal_locked[$dev]}" == "YES" ]]; then
                printf "%s[!] Warning: /dev/%s remains LOCKED and will be marked PHYS_DESTR.\n" "$TABLE_INDENT" "$dev"
                sleep 2
            fi
        fi
    done
}

device::frozen() {
    local status
    local i
    local times

    for dev in "${devices[@]}"; do
        if [[ "$dev" == sd* ]]; then
            if [[ "${bus[$dev]}" != "SATA" && "${bus[$dev]}" != "ATA" ]]; then
                continue
            fi

            i=1
            times=5
            while :; do
                status="$(hdparm -I /dev/$dev 2>&5)"
                if [[ $status == *"not"?"frozen"* || $status != *"frozen"* ]]; then
                    # Not frozen (or no security section at all) — nothing to do.
                    echo " $dev not_frozen"
                    break
                fi
                echo " $dev frozen"
                echo " $dev unfreezing"
                sleep 1 #give some time to CTRL+C if wanted
                rtcwake -m mem -s 5 >&5 2>&5
                if [[ $i -ge $times ]];then
                    if [[ "${NON_INTERACTIVE:-0}" -eq 1 ]]; then
                        echo " $dev unfreeze exhausted; continuing (non-interactive)"
                        i=0
                        continue
                    fi
                    printf "%sTried unfreezing %s %s times: Continue? [Y/n]: " "$TABLE_INDENT" "$dev" "$i"
                    if ! read -r answer < /dev/tty 2>/dev/null; then
                        echo " $dev aborted (no terminal)."
                        exit 1
                    fi
                    i=0
                    if [[ "$answer" != "${answer#[Nn]}" ]]; then
                        echo " $dev aborted."
                        exit 0
                    fi
                fi
            ((i++))
            done
        fi
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
                    capability[$device]="CAP_SCSI_NWIPE"
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
                    capability[$device]="CAP_NONE"
                    continue
                fi

                if grep -Eq '^[[:space:]]*frozen[[:space:]]*$' <<<"$security_block"; then
                    capability[$device]="CAP_ATA_FROZEN"
                    continue
                fi

                if grep -qE '^[[:space:]]+supported: enhanced erase' <<<"$security_block"; then
                    capability[$device]="CAP_ATA_PURGE_ENHANCED"
                    continue
                fi

                if grep -q "SECURITY ERASE UNIT" <<<"$features"; then
                    capability[$device]="CAP_ATA_CLEAR"
                    continue
                fi

                capability[$device]="CAP_NONE"
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
# "Frozen Drive"), so it is left untouched.
device::normalize_outcome() {
    local dev
    for dev in "${devices[@]}"; do
        case "${devrow[$dev.status]:-}" in
            COMPLETED|DRY-RUN|FROZEN)
                ;;
            BLOCKED)
                devrow["$dev.class"]="BLOCKED"
                devrow["$dev.cert"]="NOT SANITISED"
                devrow["$dev.method"]="Blocked by firmware (Block SID)"
                ;;
            UNKNOWN)
                devrow["$dev.class"]="FAILED"
                devrow["$dev.cert"]="NOT SANITISED"
                devrow["$dev.method"]="Sanitisation did not complete"
                ;;
            *)
                devrow["$dev.class"]="FAILED"
                devrow["$dev.cert"]="NOT SANITISED"
                devrow["$dev.method"]="Sanitisation failed"
                ;;
        esac
    done
}

