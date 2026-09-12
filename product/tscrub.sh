#!/bin/bash

# =============================================================================
# Metadata & Globals
# =============================================================================

SCRIPT_NAME="tScrub"
SCRIPT_VERSION="v1.0"
REPORT_DIR="/"
TABLE_INDENT="    "
COCID=""
LOG_FILE="/$SCRIPT_NAME.log"
DRY_RUN=0
START_TS=0
UI_INPLACE=0
UI_COMPLETE_THEME=0
UI_RUNTIME_ROW=0
UI_RUNTIME_COL=0
UI_ETA_COL=162

declare -Ag devrow
declare -Ag ui_eta_row

# System info globals
SYS_MANUFACTURER=""
SYS_PRODUCT=""
SYS_SERIAL=""
SYS_CHASSIS_TYPE=""
SYS_BIOS_VERSION=""
SYS_BIOS_DATE=""
SYS_CPU_LIST=""
SYS_RAM_GB=""

system::gather_info() {
    # Try dmidecode first (requires root)
    if command -v dmidecode &>/dev/null && [[ $EUID -eq 0 ]]; then
        SYS_MANUFACTURER="$(dmidecode -s system-manufacturer 2>/dev/null | head -n1 || echo N/A)"
        SYS_PRODUCT="$(dmidecode -s system-product-name 2>/dev/null | head -n1 || echo N/A)"
        SYS_SERIAL="$(dmidecode -s system-serial-number 2>/dev/null | head -n1 || echo N/A)"
        SYS_CHASSIS_TYPE="$(dmidecode -s chassis-type 2>/dev/null | head -n1 || echo N/A)"
        SYS_BIOS_VERSION="$(dmidecode -s bios-version 2>/dev/null | head -n1 || echo N/A)"
        SYS_BIOS_DATE="$(dmidecode -s bios-release-date 2>/dev/null | head -n1 || echo N/A)"
    else
        # Fallback to /sys/class/dmi/id/ (works on most Linux, even non-root)
        SYS_MANUFACTURER="$(cat /sys/class/dmi/id/sys_vendor 2>/dev/null || echo N/A)"
        SYS_PRODUCT="$(cat /sys/class/dmi/id/product_name 2>/dev/null || echo N/A)"
        SYS_SERIAL="$(cat /sys/class/dmi/id/product_serial 2>/dev/null || echo N/A)"
        SYS_CHASSIS_TYPE="$(cat /sys/class/dmi/id/chassis_type 2>/dev/null || echo N/A)"
        SYS_BIOS_VERSION="$(cat /sys/class/dmi/id/bios_version 2>/dev/null || echo N/A)"
        SYS_BIOS_DATE="$(cat /sys/class/dmi/id/bios_date 2>/dev/null || echo N/A)"
    fi

    # Processor info (physical CPU sockets only, each displayed individually)
    # Use lscpu if available (more reliable), fall back to /proc/cpuinfo
    if command -v lscpu &>/dev/null; then
        local cpu_model="$(lscpu 2>/dev/null | grep -i 'model name' | head -n1 | awk -F': *' '{$1=""; print}' | sed 's/^ //')"
        local num_sockets="$(lscpu 2>/dev/null | grep -i 'socket(s)' | awk '{print $NF}')"
        if [[ -n "$num_sockets" && "$num_sockets" -gt 0 ]]; then
            SYS_CPU_LIST="$(for ((i=1; i<=num_sockets; i++)); do echo "$i. $cpu_model"; done)"
        fi
    fi
    # Fallback if lscpu failed or unavailable
    if [[ -z "$SYS_CPU_LIST" ]]; then
        SYS_CPU_LIST="$(awk '/physical id/{pid=$NF} /model name/{if(!seen[pid]++) {sub(/^.*: /, ""); print}}' /proc/cpuinfo | nl -w1 -s'. ')"
    fi
    # RAM info (total)
    SYS_RAM_GB="$(awk '/MemTotal/ {printf "%.1f", $2/1024/1024}' /proc/meminfo 2>/dev/null) GB"
}

# =============================================================================
# UI / IPC setup
# =============================================================================

coproc UI { cat; }
exec 3>&${UI[1]}
exec 4<&${UI[0]}
exec 5>>"$LOG_FILE"

parse_args() {
    for arg in "$@"; do
        case "$arg" in
            --dry-run|-n)
                DRY_RUN=1
                ;;
            --help|-h)
                echo "Usage: $0 [--dry-run]"
                exit 0
                ;;
            *)
                echo "Unknown argument: $arg"
                exit 1
                ;;
        esac
    done
}

# =============================================================================
# Entry Point
# =============================================================================

parse_args "$@"
fn_main() {
    if [ -t 1 ]; then
        # Ensure each run starts from default terminal colors.
        printf "\033[0m"
        clear
    fi
    UI_COMPLETE_THEME=0
    if [[ -t 1 ]] && [[ -n "${TERM:-}" ]] && [[ "${TERM:-}" != "dumb" ]]; then
        UI_INPLACE=1
    fi

    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf "%s*** DRY RUN MODE — NO WIPE WILL BE EXECUTED ***\n\n" "$TABLE_INDENT"
    fi

    system::gather_info
    START_TS=$(date +%s)

    ui::coc_prompt
    if ! cocid::is_valid "$COCID"; then
        printf "%s[!] Invalid COCID '%s'. Must be exactly 5 digits.\n" "$TABLE_INDENT" "$COCID" >&2
        exit 1
    fi
    device::install_sedutil
    device::discover
    device::handle_locks
    device::frozen
    device::detect
    table::build
    table::render

    pids=()

    if [[ "$DRY_RUN" -eq 1 ]]; then
        for dev in "${devices[@]}"; do
            devrow["$dev.status"]="DRY-RUN"
        done
        table::render
    else
        for dev in "${devices[@]}"; do
            device::execute "$dev" &
            pids+=($!)
        done
    fi
    exec 3>&-
    exec {UI[1]}>&-

    ui::loop

    for pid in "${pids[@]}"; do
        wait "$pid"
    done

    report_file=$(report::csv)
    if [[ "$DRY_RUN" -eq 0 ]]; then
        report::parse_ftp
        report::upload "$report_file"
    fi

    if [[ "$DRY_RUN" -eq 1 ]]; then
        ui::show_finish_green "DRY-RUN finished"
    else
        ui::show_finish_green "Sanitization process finished"
    fi
}

# =============================================================================
# UI FUNCTIONS
# =============================================================================

cocid::is_valid() {
    [[ "$1" =~ ^[0-9]{5}$ ]]
}

ui::coc_prompt() {
    tput cnorm
    printf "\n"

    while :; do
        printf "%sEnter Chain of Custody ID (exactly 5 digits): " "$TABLE_INDENT"
        read -r COCID < /dev/tty

        # Trim whitespace before validation
        COCID="$(printf "%s" "$COCID" | xargs)"

        if cocid::is_valid "$COCID"; then
            export COCID
            return
        fi

        printf "%s[!] Invalid COCID. It must be exactly 5 digits (00000-99999), blank is not allowed.\n" "$TABLE_INDENT"
    done
}

ui::show_finish_green() {
    [[ -t 1 ]] || return 0
    [[ -n "${TERM:-}" ]] || return 0
    [[ "${TERM:-}" != "dumb" ]] || return 0

    UI_COMPLETE_THEME=1
    UI_INPLACE=0
    table::render
}

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
        printf "%s[!] sedutil-cli not found. Downloading...\n" "$TABLE_INDENT"
        
        if wget -qO /usr/bin/sedutil-cli extra.tfix.co.uk/sedutil-cli; then
            chmod +x /usr/bin/sedutil-cli
            printf "%s[+] sedutil-cli installed successfully.\n" "$TABLE_INDENT"
            sleep 1
        else
            printf "%s[!] Failed to download sedutil-cli. Network down?\n" "$TABLE_INDENT"
            sleep 3
        fi
    fi
}

device::discover() {
    devices=()
    # Ensure arrays are accessible globally as they are used in the table
    declare -Ag serial
    declare -Ag bus
    declare -Ag model
    declare -Ag size
    declare -Ag type
    declare -Ag opal_locked

    for path in /sys/block/*; do
        dev="${path##*/}"

        # Filter out virtual devices and optical drives
        [[ "$dev" =~ ^(loop|ram|dm-) ]] && continue
        [[ "$dev" =~ ^sr[0-9]+$ ]] && continue

        # --- NVMe DISCOVERY ---
        if [[ "$dev" =~ ^nvme[0-9]+n[0-9]+$ ]]; then
            devices+=("$dev")
            bus[$dev]="NVMe"
            type[$dev]="SSD"
            
            # Map nvme0n1 -> /dev/nvme0 for sedutil-cli
            local ctrl_dev="/dev/${dev%n*}" 

            # Metadata extraction
            serial[$dev]=$(nvme id-ctrl /dev/$dev 2>&5 | awk -F': *' '/^sn[[:space:]]*:/{print $2}' | xargs)
            model[$dev]=$(nvme id-ctrl /dev/$dev 2>&5 | awk -F': *' '/^mn[[:space:]]*:/{print $2}' | xargs)
            
            # OPAL Lock Check
            if sedutil-cli --query "$ctrl_dev" 2>/dev/null | grep -q "Locked = Y"; then
                opal_locked[$dev]="YES"
            else
                opal_locked[$dev]="NO"
            fi

            bytes=$(blockdev --getsize64 /dev/$dev 2>/dev/null)
            size[$dev]=$(( bytes / 1000000000 ))" GB"

        # --- SATA/SCSI DISCOVERY ---
        elif [[ "$dev" =~ ^sd[a-z]+$ ]]; then
            # Accept any non-USB block device (SATA, SCSI, SAS) — hdparm determines capability
            if ! realpath /sys/block/$dev/device | grep -q '/usb'; then
                devices+=("$dev")
                
                # Check for rotation (HDD vs SSD)
                if [[ -f /sys/block/$dev/queue/rotational ]]; then
                    if [[ "$(cat /sys/block/$dev/queue/rotational)" -eq 1 ]]; then
                        type[$dev]="HDD"
                    else
                        type[$dev]="SSD"
                    fi
                else
                    type[$dev]="N/A"
                fi

                # Detect Bus/Transport
                if [[ -f /sys/block/$dev/device/transport ]]; then
                    transport=$(< /sys/block/$dev/device/transport)
                    # Show kernel-reported transport directly for broader SCSI visibility.
                    bus[$dev]="${transport^^}"
                else
                    # Fallback for transport detection
                    link=$(readlink -f /sys/block/$dev 2>&5)
                    if [[ "$link" == *ata* ]]; then
                        bus[$dev]="SATA"
                    elif [[ "$link" == *pci* ]]; then
                        bus[$dev]="PCI"
                    else
                        bus[$dev]="N/A"
                    fi
                fi

                # Extract Serial and Model via hdparm
                serial[$dev]=$(hdparm -I /dev/$dev 2>&5 | awk -F': *' '/^[[:space:]]*Serial Number/ {print $2}' | xargs | tr -d ' ')
                model[$dev]=$(hdparm -I /dev/$dev 2>&5 | awk -F': *' '/^[[:space:]]*Model Number/ {print $2}' | xargs)
                
                # OPAL Lock Check for SATA
                if sedutil-cli --query "/dev/$dev" 2>/dev/null | grep -q "Locked = Y"; then
                    opal_locked[$dev]="YES"
                else
                    opal_locked[$dev]="NO"
                fi

                bytes=$(blockdev --getsize64 /dev/$dev 2>/dev/null)
                size[$dev]=$(( bytes / 1000000000 ))" GB"
            else
                continue
            fi
        else
            continue
        fi
    done

    # Exit if no disks found
    if [[ ${#devices[@]} -eq 0 ]]; then
        printf "%s[!] No supported drives found. Exiting.\n" "$TABLE_INDENT" >&2
        echo " N/A N/A NoneFound" >&3
        exit 1
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
                tput cnorm
                printf "%sEnter 32-char PSID (blank to skip): " "$TABLE_INDENT"
                
                # Read specifically from tty
                read -r psid < /dev/tty

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
                
                # Execute and Log ONLY the sedutil output
                cmd_result=$(sedutil-cli --yesIreallywanttoERASEALLmydatausingthePSID "$psid" "$ctrl" 2>&1)
                
                echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] DRIVE: $dev CMD: PSID_REVERT" >> "$LOG_FILE"
                echo "$cmd_result" >> "$LOG_FILE"
                
                # --- EXECUTION & LOGGING ---
                # Run the command and append raw output to the log
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

    for dev in ${devices[@]}; do
        if [[ "$dev" == "sd"? ]]; then
            if [[ "${bus[$dev]}" != "SATA" && "${bus[$dev]}" != "ATA" ]]; then
                continue
            fi

            i=1
            times=5
            while :; do
                status="$(hdparm -I /dev/$dev 2>&5)"
                if [[ $status == *"not"?"frozen"* ]]; then
                    echo " $dev not_frozen" >&3
                    break
                elif [[ $status == *"frozen"* ]]; then
                    echo " $dev frozen" >&3
                    echo " $dev unfreezing" >&3
                    sleep 1 #give some time to CTRL+C if wanted
                    rtcwake -m mem -s 5 >&5 2>&5
                fi
                if [[ $i -ge $times ]];then
                    echo -n "Tried unfreezing $dev $i times: Continue? [Y/n]: " >&3
                    read answer < /dev/tty
                    i=0
                    if [[ "$answer" != "${answer#[Nn]}" ]]; then
                        echo " $dev aborted." >&3
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

                if ! grep -q "supported" <<<"$security_block"; then
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

        CAP_NVME_PURGE_*)
            devrow["$dev.class"]="PURGE"
            devrow["$dev.cert"]="DESTRUCTION"
            devrow["$dev.method"]="Secure Erase"
            ;;

        CAP_ATA_PURGE_ENHANCED)
            devrow["$dev.class"]="PURGE"
            devrow["$dev.cert"]="DESTRUCTION"
            devrow["$dev.method"]="Enhanced Erase"
            local _t="${ata_enhanced_time[$dev]:-${ata_erase_time[$dev]:-}}"
            if [[ "$_t" =~ ^[0-9]+$ ]]; then devrow["$dev.eta_mins"]="$_t"; fi
            ;;

        CAP_ATA_CLEAR)
            if [[ "${type[$dev]}" == "HDD" ]]; then
                # HDD = purge
                devrow["$dev.class"]="PURGE"
                devrow["$dev.cert"]="DESTRUCTION"
                devrow["$dev.method"]="HDD Overwrite"
            else
                # SSD = clear
                devrow["$dev.class"]="CLEAR"
                devrow["$dev.cert"]="SANITISATION"
                devrow["$dev.method"]="ATA Secure Erase"
            fi
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
    local cmd="${devrow[$dev.command]}"

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

    "${nwipe_cmd[@]}" 2>&1 | while IFS= read -r line; do
        echo "$line" >&5
        # Parse progress lines like: [sda]  12% complete, ...
        if [[ "$line" =~ \[$dev\][[:space:]]+([0-9]+)% ]]; then
            pct="${BASH_REMATCH[1]}%"
            echo "$dev STATUS $pct" >&3
        fi
    done
    # Check exit status of nwipe
    if [ "${PIPESTATUS[0]}" -eq 0 ]; then
        echo "$dev STATUS COMPLETED" >&3
    else
        echo "$dev STATUS FAILED" >&3
        echo "$dev LOG nwipe quick operation failed; manual physical destruction required" >&3
    fi
}

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

# =============================================================================
# TABLE
# =============================================================================

table::build() {
    local dev cap

    for dev in "${devices[@]}"; do
        cap="${capability[$dev]}"

        devrow["$dev.device"]="$dev"
        devrow["$dev.model"]="${model[$dev]}"
        devrow["$dev.serial"]="${serial[$dev]}"
        devrow["$dev.size"]="${size[$dev]}"
        devrow["$dev.bus"]="${bus[$dev]}"
        devrow["$dev.type"]="${type[$dev]}"
        devrow["$dev.capability"]="$cap"
        devrow["$dev.status"]="PLANNED"
        devrow["$dev.eta_mins"]=""
        devrow["$dev.wipe_start"]=""

        device::classify "$dev"
    done
}

ui::format_runtime() {
    local now="$1"
    local runtime_s runtime_h runtime_m runtime_sec

    runtime_s=0
    if [[ "$START_TS" =~ ^[0-9]+$ ]] && [[ "$START_TS" -gt 0 ]]; then
        runtime_s=$(( now - START_TS ))
        (( runtime_s < 0 )) && runtime_s=0
    fi
    runtime_h=$(( runtime_s / 3600 ))
    runtime_m=$(( (runtime_s % 3600) / 60 ))
    runtime_sec=$(( runtime_s % 60 ))
    printf "%02d:%02d:%02d" "$runtime_h" "$runtime_m" "$runtime_sec"
}

ui::eta_text_for() {
    local dev="$1"
    local now="$2"
    local eta_mins wipe_start dev_status remain rh rm rs

    eta_mins="${devrow[$dev.eta_mins]}"
    wipe_start="${devrow[$dev.wipe_start]}"
    dev_status="${devrow[$dev.status]}"

    case "$dev_status" in
        PLANNED|DRY-RUN)
            if [[ "$eta_mins" =~ ^[0-9]+$ ]]; then
                if (( eta_mins >= 60 )); then
                    printf "~%dh%dm" "$(( eta_mins / 60 ))" "$(( eta_mins % 60 ))"
                else
                    printf "~%dmin" "$eta_mins"
                fi
            else
                printf "N/A"
            fi
            ;;
        RUNNING|*%)
            if [[ "$eta_mins" =~ ^[0-9]+$ ]]; then
                if [[ "$wipe_start" =~ ^[0-9]+$ ]]; then
                    remain=$(( eta_mins * 60 - (now - wipe_start) ))
                    (( remain < 0 )) && remain=0
                    rh=$(( remain / 3600 ))
                    rm=$(( (remain % 3600) / 60 ))
                    rs=$(( remain % 60 ))
                    if (( rh > 0 )); then
                        printf "~%dh%dm%ds" "$rh" "$rm" "$rs"
                    elif (( rm > 0 )); then
                        printf "~%dm%ds" "$rm" "$rs"
                    else
                        printf "~%ds" "$rs"
                    fi
                else
                    if (( eta_mins >= 60 )); then
                        printf "~%dh%dm" "$(( eta_mins / 60 ))" "$(( eta_mins % 60 ))"
                    else
                        printf "~%dmin" "$eta_mins"
                    fi
                fi
            else
                printf "N/A"
            fi
            ;;
        COMPLETED)
            printf "Done"
            ;;
        FAILED|FROZEN)
            printf "--"
            ;;
        *)
            printf "N/A"
            ;;
    esac
}

ui::tick_inplace() {
    local now runtime_str dev row eta_col

    if [[ "$UI_INPLACE" -ne 1 ]]; then
        return 1
    fi
    if [[ "$UI_RUNTIME_ROW" -le 0 || "$UI_RUNTIME_COL" -le 0 ]]; then
        return 1
    fi

    now=$(date +%s)
    runtime_str="$(ui::format_runtime "$now")"

    printf "\0337"
    printf "\033[%d;%dH%-69.69s" "$UI_RUNTIME_ROW" "$UI_RUNTIME_COL" "$runtime_str"

    for dev in "${devices[@]}"; do
        row="${ui_eta_row[$dev]:-}"
        [[ "$row" =~ ^[0-9]+$ ]] || continue
        eta_col="$(ui::eta_text_for "$dev" "$now")"
        printf "\033[%d;%dH%-9.9s" "$row" "$UI_ETA_COL" "$eta_col"
    done

    printf "\0338"
}

table::render() {
    if [[ "$UI_COMPLETE_THEME" -eq 1 ]] && [[ -t 1 ]]; then
        printf "\033[0;42;30m\033[2J\033[H"
    else
        clear
        printf "\n"
    fi

    local now
    local runtime_s runtime_h runtime_m runtime_sec runtime_str
    local main_w panel_w hline
    local cpu_line cpu_printed
    local cpu_rows row
    now=$(date +%s)
    runtime_str="$(ui::format_runtime "$now")"

    main_w=170
    panel_w=$(( (main_w - 2) / 2 ))
    hline="$(printf "%*s" $((panel_w - 2)) "" | tr ' ' '-')"
    UI_RUNTIME_ROW=5
    UI_RUNTIME_COL=$((1 + ${#TABLE_INDENT} + 2 + 10 + 1 + (panel_w - 15) + 6 + 10 + 1))

    cpu_printed=0
    cpu_rows=0
    while IFS= read -r cpu_line; do
        [[ -z "$cpu_line" ]] && continue
        cpu_rows=$(( cpu_rows + 1 ))
    done <<< "$SYS_CPU_LIST"
    (( cpu_rows == 0 )) && cpu_rows=1
    ui_eta_row=()

    # Top header: two side-by-side tables (left: system info, right: runtime)
    printf "%s+%s+  +%s+\n" "$TABLE_INDENT" "$hline" "$hline"
    printf "%s| %-*.*s |  | %-*.*s |\n" \
        "$TABLE_INDENT" \
        $((panel_w - 4)) $((panel_w - 4)) "System Info" \
        $((panel_w - 4)) $((panel_w - 4)) "Runtime"
    printf "%s+%s+  +%s+\n" "$TABLE_INDENT" "$hline" "$hline"
    printf "%s| %-10s %-*.*s |  | %-10s %-*.*s |\n" \
        "$TABLE_INDENT" "System:" $((panel_w - 15)) $((panel_w - 15)) "$SYS_MANUFACTURER $SYS_PRODUCT" \
        "Elapsed:" $((panel_w - 15)) $((panel_w - 15)) "$runtime_str"
    printf "%s| %-10s %-*.*s |  | %-10s %-*.*s |\n" \
        "$TABLE_INDENT" "Serial:" $((panel_w - 15)) $((panel_w - 15)) "$SYS_SERIAL" \
        "" $((panel_w - 15)) $((panel_w - 15)) ""
    printf "%s| %-10s %-*.*s |  | %-10s %-*.*s |\n" \
        "$TABLE_INDENT" "Chassis:" $((panel_w - 15)) $((panel_w - 15)) "$SYS_CHASSIS_TYPE" \
        "" $((panel_w - 15)) $((panel_w - 15)) ""
    printf "%s| %-10s %-*.*s |  | %-10s %-*.*s |\n" \
        "$TABLE_INDENT" "BIOS:" $((panel_w - 15)) $((panel_w - 15)) "$SYS_BIOS_VERSION ($SYS_BIOS_DATE)" \
        "" $((panel_w - 15)) $((panel_w - 15)) ""

    while IFS= read -r cpu_line; do
        [[ -z "$cpu_line" ]] && continue
        if [[ "$cpu_printed" -eq 0 ]]; then
            printf "%s| %-10s %-*.*s |  | %-10s %-*.*s |\n" \
                "$TABLE_INDENT" "CPUs:" $((panel_w - 15)) $((panel_w - 15)) "$cpu_line" \
                "" $((panel_w - 15)) $((panel_w - 15)) ""
            cpu_printed=1
        else
            printf "%s| %-10s %-*.*s |  | %-10s %-*.*s |\n" \
                "$TABLE_INDENT" "" $((panel_w - 15)) $((panel_w - 15)) "$cpu_line" \
                "" $((panel_w - 15)) $((panel_w - 15)) ""
        fi
    done <<< "$SYS_CPU_LIST"

    if [[ "$cpu_printed" -eq 0 ]]; then
        printf "%s| %-10s %-*.*s |  | %-10s %-*.*s |\n" \
            "$TABLE_INDENT" "CPUs:" $((panel_w - 15)) $((panel_w - 15)) "N/A" \
            "" $((panel_w - 15)) $((panel_w - 15)) ""
    fi

    printf "%s| %-10s %-*.*s |  | %-10s %-*.*s |\n" \
        "$TABLE_INDENT" "RAM:" $((panel_w - 15)) $((panel_w - 15)) "$SYS_RAM_GB" \
        "" $((panel_w - 15)) $((panel_w - 15)) ""
    printf "%s+%s+  +%s+\n\n" "$TABLE_INDENT" "$hline" "$hline"

    printf "%s%-30s %-25s %-8s %-8s %-8s %-8s %-13s %-15s %-20s %-12s %-9s\n" \
        "$TABLE_INDENT" \
        "MODEL" "SERIAL" "SIZE" "BUS" "TYPE" "DEVICE" "CLASS" "CERT" "METHOD" "STATUS" "ETA"

    printf "%s%s\n" "$TABLE_INDENT" "$(printf "%*s" 170 "" | tr ' ' '-')"

    for dev in "${devices[@]}"; do
        local eta_col
        eta_col="$(ui::eta_text_for "$dev" "$now")"
        row=$((14 + cpu_rows + ${#ui_eta_row[@]} ))
        ui_eta_row["$dev"]="$row"

        printf "%s%-30s %-25s %-8s %-8s %-8s %-8s %-13s %-15s %-20s %-12s %-9s\n" \
            "$TABLE_INDENT" \
            "${devrow[$dev.model]}" \
            "${devrow[$dev.serial]}" \
            "${devrow[$dev.size]}" \
            "${devrow[$dev.bus]}" \
            "${devrow[$dev.type]}" \
            "${devrow[$dev.device]}" \
            "${devrow[$dev.class]}" \
            "${devrow[$dev.cert]}" \
            "${devrow[$dev.method]}" \
            "${devrow[$dev.status]}" \
            "$eta_col"

    done

    printf "%s%s\n" "$TABLE_INDENT" "$(printf "%*s" 170 "" | tr ' ' '-')"

    if [[ "$UI_COMPLETE_THEME" -eq 1 ]] && [[ -t 1 ]]; then
        printf "\033[%d;1H" "$((15 + cpu_rows + ${#devices[@]}))"
    fi

}

ui::loop() {
    local _rc _dev _key _value
    while true; do
        IFS=' ' read -t 1 -r -u4 _dev _key _value; _rc=$?
        if (( _rc == 0 )); then
            [[ -z "${_dev:-}" ]] && continue
            if [[ "$_key" == "STATUS" ]]; then
                devrow["$_dev.status"]="$_value"
                # Record when an ATA wipe starts (first RUNNING only)
                if [[ "$_value" == "RUNNING" ]] && \
                   [[ -n "${devrow[$_dev.eta_mins]}" ]] && \
                   [[ -z "${devrow[$_dev.wipe_start]}" ]]; then
                    devrow["$_dev.wipe_start"]="$(date +%s)"
                fi
                table::render
            fi
        elif (( _rc > 128 )); then
            # read timed out — update only time fields to avoid full-screen flicker
            if ! ui::tick_inplace; then
                table::render
            fi
        else
            # EOF — all writers closed
            break
        fi
    done
    exec 4<&-
}


# =============================================================================
# REPORT
# =============================================================================

report::csv() {
    local report_file="$REPORT_DIR${SCRIPT_NAME}_${COCID}_$(date -u +%Y%m%dT%H%M%SZ).csv"

    {
        echo "COCID,Timestamp,Model,Serial,Size,Bus,Type,Device,Class,Certification,Method,FinalStatus"
        for dev in "${devices[@]}"; do
            printf '"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s"\n' \
                "$COCID" \
                "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
                "${devrow[$dev.model]}" \
                "${devrow[$dev.serial]}" \
                "${devrow[$dev.size]}" \
                "${devrow[$dev.bus]}" \
                "${devrow[$dev.type]}" \
                "${devrow[$dev.device]}" \
                "${devrow[$dev.class]}" \
                "${devrow[$dev.cert]}" \
                "${devrow[$dev.method]}" \
                "${devrow[$dev.status]}"
        done
    } > "$report_file"

    printf "%sReport written to: %s\n" "$TABLE_INDENT" "$report_file" >&2

    echo "$report_file"
}

report::parse_ftp() {
    local param

    param="$(tr ' ' '\n' < /proc/cmdline | sed -n 's/^shredos_output=//p')"
    [ -z "$param" ] && return 1

    param="${param#\"}"
    param="${param%\"}"

    IFS=':' read -r SHRED_PROTO SHRED_HOST SHRED_PATH SHRED_USER SHRED_PASS _ <<< "$param"

    export SHRED_PROTO SHRED_HOST SHRED_PATH SHRED_USER SHRED_PASS
}

report::upload() {
    local file="$1"
    [[ "$SHRED_PROTO" == "ftp" ]] || return
    [[ -f "$file" ]] || return

    printf "%sUploading report...\n" "$TABLE_INDENT"

    if lftp -u "$SHRED_USER,$SHRED_PASS" "$SHRED_HOST" \
        -e "cd $SHRED_PATH; put $file; bye"; then
        printf "%sReport uploaded successfully.\n" "$TABLE_INDENT"
    else
        printf "%sReport upload FAILED.\n" "$TABLE_INDENT"
        return 1
    fi
}

fn_main