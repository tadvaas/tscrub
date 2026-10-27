# =============================================================================
# FORMAT (post-erasure drive preparation)
# =============================================================================
# After a successful wipe, leave the drive ready to use: a single partition and
# a cross-platform filesystem, automatically, with zero operator intervention.
#
# This is a COSMETIC convenience step, orthogonal to sanitisation: it never
# strengthens or weakens the "data destroyed" evidence (which comes from the
# erase method + post-erasure verification). A failed or unsupported format
# leaves the drive sanitised-but-unallocated and must not fail the wipe.
#
# Policy (confirmed 2026-10-16):
#   filesystem   exFAT for drives > 32 GiB, FAT32 for <= 32 GiB
#   partition    MBR where the capacity allows (2^32 logical sectors),
#                GPT beyond; single partition aligned at 1 MiB
#
# "Most accessible method" = a tool-chain cascade (best tool present, graceful
# degradation): sfdisk -> sgdisk for partitioning, dosfstools -> busybox for
# FAT32, exfatprogs -> exfat-utils for exFAT. A missing tool records
# `unsupported`, never a fabricated "formatted".
#
# Guardrails:
#   1. Format ONLY drives that completed a real wipe (status == COMPLETED,
#      DRY_RUN == 0, selected == 1). The formatter performs no discovery and
#      never touches a device outside the wiped set.
#   2. Fixed vocabulary: format_result in {n/a, skipped, unsupported, failed,
#      ok}; format_fs in {exfat, fat32, ""}; format_scheme in {mbr, gpt, ""}.
#   3. `ok` requires the partition-table writer AND mkfs to both exit 0. A
#      `blkid` type confirmation is recorded separately (advisory).
#   4. Headless-safe: no prompts; controlled purely by config/CLI/cmdline.

# Resolve FORMAT_MODE / FORMAT_FS / FORMAT_SCHEME / FORMAT_LABEL. Precedence:
# CLI (--format/--fs/--scheme/--label) -> tscrub.conf (config::load_usb) ->
# kernel cmdline (tscrub_*). Invalid/empty values fall back to defaults.
format::resolve_config() {
    if [[ -r /proc/cmdline ]]; then
        local params
        params="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null)"
        [[ -z "${FORMAT_MODE:-}" ]]   && FORMAT_MODE="$(printf '%s\n' "$params"   | sed -nE 's/^tscrub_format=//p' | head -n 1)"
        [[ -z "${FORMAT_FS:-}" ]]     && FORMAT_FS="$(printf '%s\n' "$params"     | sed -nE 's/^tscrub_fs=//p' | head -n 1)"
        [[ -z "${FORMAT_SCHEME:-}" ]] && FORMAT_SCHEME="$(printf '%s\n' "$params" | sed -nE 's/^tscrub_scheme=//p' | head -n 1)"
        [[ -z "${FORMAT_LABEL:-}" ]]  && FORMAT_LABEL="$(printf '%s\n' "$params"  | sed -nE 's/^tscrub_label=//p' | head -n 1)"
    fi
    case "${FORMAT_MODE:-}" in
        auto|off) ;;
        *) FORMAT_MODE="auto" ;;
    esac
    case "${FORMAT_FS:-}" in
        auto|fat32|exfat) ;;
        *) FORMAT_FS="auto" ;;
    esac
    case "${FORMAT_SCHEME:-}" in
        auto|mbr|gpt) ;;
        *) FORMAT_SCHEME="auto" ;;
    esac
    # ERASED, not TSCRUB: this label is stamped on every drive the appliance
    # prepares for a customer, and TSCRUB/TSCRUB-USB are the appliance's OWN
    # identity markers — device::protected_disks() protects any disk carrying
    # them, so a stick formatted here would come back unerasable by the very
    # appliance that prepared it. Naming the appliance's media stays a
    # deliberate act (--label TSCRUB / tscrub_label=TSCRUB).
    [[ -n "${FORMAT_LABEL:-}" ]] || FORMAT_LABEL="ERASED"
}

# Pure policy: <size_bytes> <sector_size> -> "scheme fs".
#   scheme: gpt when the drive has more logical sectors than MBR's 32-bit LBA
#           can address (2^32), else mbr.
#   fs:     exfat when > 32 GiB (34359738368 bytes), else fat32.
format::policy_for() {
    local bytes="${1:-0}" ss="${2:-512}" lsectors scheme fs
    [[ "$bytes" =~ ^[0-9]+$ ]] || bytes=0
    [[ "$ss" =~ ^[0-9]+$ ]] || ss=512
    (( ss > 0 )) || ss=512
    lsectors=$(( bytes / ss ))
    scheme="mbr"
    (( lsectors > 0xFFFFFFFE )) && scheme="gpt"
    if (( bytes > 34359738368 )); then fs="exfat"; else fs="fat32"; fi
    printf '%s %s' "$scheme" "$fs"
}

# --- Tool availability (overridable so tests can simulate a slim image) -------
format::_have() { command -v "$1" >/dev/null 2>&1; }

format::_partitioner() {   # -> sfdisk | sgdisk | ""
    if format::_have sfdisk; then echo sfdisk; return 0; fi
    if format::_have sgdisk; then echo sgdisk; return 0; fi
    return 0
}

format::_mkfs_fat_bin() {  # -> mkfs.fat | mkfs.vfat | ""
    if format::_have mkfs.fat; then echo mkfs.fat; return 0; fi
    if format::_have mkfs.vfat; then echo mkfs.vfat; return 0; fi
    return 0
}

format::_mkfs_exfat_bin() { # -> mkfs.exfat | mkexfatfs | ""
    if format::_have mkfs.exfat; then echo mkfs.exfat; return 0; fi
    if format::_have mkexfatfs; then echo mkexfatfs; return 0; fi
    return 0
}

# --- Low-level operations (overridable as shell functions in tests) -----------
format::_part_node() {     # <dev> -> partition node (sda -> sda1, nvme0n1 -> nvme0n1p1)
    local dev="$1"
    if [[ "$dev" == nvme* ]]; then printf '%s' "${dev}p1"; else printf '%s' "${dev}1"; fi
}

format::_start_sector() {  # <sector_size> -> first LBA (1 MiB alignment)
    local ss="${1:-512}" start
    [[ "$ss" =~ ^[0-9]+$ ]] && (( ss > 0 )) || ss=512
    start=$(( 1048576 / ss ))
    (( start < 1 )) && start=1
    printf '%s' "$start"
}

format::_sanitize_label() { # <label> -> FAT-safe 8.3 uppercase label (<=11 chars)
    local l="${1:-ERASED}"
    l="$(printf '%s' "$l" | tr '[:lower:]' '[:upper:]' | tr -cd 'A-Z0-9' | cut -c1-11)"
    [[ -n "$l" ]] || l="ERASED"
    printf '%s' "$l"
}

format::_wipe_signature() { # <dev> -> 0/1
    local dev="$1"
    if format::_have wipefs; then wipefs -a "/dev/$dev" >/dev/null 2>&1; return 0; fi
    if format::_have sgdisk; then sgdisk -Z "/dev/$dev" >/dev/null 2>&1; return 0; fi
    if format::_have sfdisk; then sfdisk --zap "/dev/$dev" >/dev/null 2>&1; return 0; fi
    return 1
}

format::_write_partition() { # <dev> <scheme> <fs> <start_sector> -> 0/1
    local dev="$1" scheme="$2" fs="$3" start="$4" type_code
    local part_tool; part_tool="$(format::_partitioner)"
    case "$part_tool" in
        sfdisk)
            if [[ "$scheme" == "mbr" ]]; then
                [[ "$fs" == "fat32" ]] && type_code="0c" || type_code="07"
                printf 'label: dos\nstart=%s, type=%s\n' "$start" "$type_code" | sfdisk "/dev/$dev" >/dev/null 2>&1
            else
                printf 'label: gpt\nstart=%s, type=%s\n' "$start" "EBD0A0A2-B9E5-4433-87C0-68B6B72699C7" | sfdisk "/dev/$dev" >/dev/null 2>&1
            fi
            ;;
        sgdisk)
            # sgdisk is GPT-only; a plain MBR table cannot be written by it.
            [[ "$scheme" == "gpt" ]] || return 1
            sgdisk -Z -n "1:${start}:0" -t "1:EBD0A0A2-B9E5-4433-87C0-68B6B72699C7" "/dev/$dev" >/dev/null 2>&1
            ;;
        *)
            return 1 ;;
    esac
}

format::_mkfs_run() {       # <fs> <label> <partnode> -> 0/1
    local fs="$1" label="$2" part="$3" bin
    case "$fs" in
        fat32)
            bin="$(format::_mkfs_fat_bin)"
            [[ -n "$bin" ]] || return 1
            "$bin" -F 32 -n "$label" "/dev/$part" >/dev/null 2>&1
            ;;
        exfat)
            bin="$(format::_mkfs_exfat_bin)"
            [[ -n "$bin" ]] || return 1
            "$bin" -n "$label" "/dev/$part" >/dev/null 2>&1
            ;;
        *)
            return 1 ;;
    esac
}

format::_reread() {         # <dev> — best effort (the writers already re-read)
    blockdev --rereadpt "/dev/$1" 2>/dev/null || true
}

format::_blkid_type() {     # <partnode> -> vfat | exfat | ""
    blkid -s TYPE -o value "/dev/$1" 2>/dev/null
}

# --- Per-drive formatting -----------------------------------------------------
format::format_drive() {
    local dev="$1"

    # Idempotent reset: every drive that flows through the cycle gets a verdict.
    devrow["$dev.format_result"]=""
    devrow["$dev.format_fs"]=""
    devrow["$dev.format_scheme"]=""
    devrow["$dev.format_label"]=""
    devrow["$dev.format_confirm"]=""

    [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] || { devrow["$dev.format_result"]="n/a"; return 0; }
    [[ "${devrow[$dev.status]:-}" == "COMPLETED" ]] || { devrow["$dev.format_result"]="n/a"; return 0; }
    [[ "${devrow[$dev.capability]:-}" != "CAP_NONE" ]] || { devrow["$dev.format_result"]="n/a"; return 0; }

    if [[ "${FORMAT_MODE:-auto}" != "auto" || "${DRY_RUN:-0}" -eq 1 ]]; then
        devrow["$dev.format_result"]="skipped"
        return 0
    fi

    # Geometry: `sectors[$dev]` is 512-byte-equivalent (bytes / 512); combine
    # with the real logical sector size to recover the byte size and the true
    # logical-sector count (which is what MBR's 32-bit LBA limit is defined in).
    local total_bytes=0 sector_sz="${secsize[$dev]:-512}"
    [[ "${sectors[$dev]:-}" =~ ^[0-9]+$ ]] && total_bytes=$(( sectors[$dev] * 512 ))

    local scheme fs lsectors=0
    read -r scheme fs <<< "$(format::policy_for "$total_bytes" "$sector_sz")"
    [[ "$sector_sz" =~ ^[0-9]+$ ]] && (( sector_sz > 0 )) && lsectors=$(( total_bytes / sector_sz ))

    case "${FORMAT_FS:-auto}" in
        fat32|exfat) fs="$FORMAT_FS" ;;
    esac
    case "${FORMAT_SCHEME:-auto}" in
        mbr|gpt) scheme="$FORMAT_SCHEME" ;;
    esac

    # Refuse to force MBR onto a drive MBR cannot address (never truncate).
    if [[ "$scheme" == "mbr" ]] && (( lsectors > 0xFFFFFFFE )); then
        devrow["$dev.format_result"]="failed"
        devrow["$dev.format_fs"]="$fs"
        devrow["$dev.format_scheme"]="$scheme"
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] FORMAT: $dev | mbr requested but drive has $lsectors logical sectors (> 2^32)" >> "$LOG_FILE"
        return 0
    fi

    local part_tool mkfs_bin
    part_tool="$(format::_partitioner)"
    case "$fs" in
        fat32) mkfs_bin="$(format::_mkfs_fat_bin)" ;;
        exfat) mkfs_bin="$(format::_mkfs_exfat_bin)" ;;
    esac

    if [[ -z "$part_tool" || -z "$mkfs_bin" ]] || { [[ "$scheme" == "mbr" && "$part_tool" == "sgdisk" ]]; }; then
        devrow["$dev.format_result"]="unsupported"
        devrow["$dev.format_fs"]="$fs"
        devrow["$dev.format_scheme"]="$scheme"
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] FORMAT: $dev | unsupported (partitioner=${part_tool:-none}, mkfs=${mkfs_bin:-none}, scheme=$scheme)" >> "$LOG_FILE"
        return 0
    fi

    local label start part
    label="$(format::_sanitize_label "${FORMAT_LABEL:-ERASED}")"
    start="$(format::_start_sector "$sector_sz")"
    part="$(format::_part_node "$dev")"

    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] FORMAT: $dev | scheme=$scheme fs=$fs label=$label tool=$part_tool" >> "$LOG_FILE"

    if ! format::_wipe_signature "$dev"; then
        devrow["$dev.format_result"]="failed"
        devrow["$dev.format_fs"]="$fs"
        devrow["$dev.format_scheme"]="$scheme"
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] FORMAT: $dev | signature wipe failed" >> "$LOG_FILE"
        return 0
    fi

    if ! format::_write_partition "$dev" "$scheme" "$fs" "$start"; then
        devrow["$dev.format_result"]="failed"
        devrow["$dev.format_fs"]="$fs"
        devrow["$dev.format_scheme"]="$scheme"
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] FORMAT: $dev | partition write failed" >> "$LOG_FILE"
        return 0
    fi

    if ! format::_mkfs_run "$fs" "$label" "$part"; then
        devrow["$dev.format_result"]="failed"
        devrow["$dev.format_fs"]="$fs"
        devrow["$dev.format_scheme"]="$scheme"
        echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] FORMAT: $dev | mkfs $fs failed" >> "$LOG_FILE"
        return 0
    fi

    format::_reread "$dev"

    local blk_type=""
    blk_type="$(format::_blkid_type "$part")"
    case "$blk_type" in
        vfat)  devrow["$dev.format_confirm"]="blkid:vfat" ;;
        exfat) devrow["$dev.format_confirm"]="blkid:exfat" ;;
    esac

    devrow["$dev.format_result"]="ok"
    devrow["$dev.format_fs"]="$fs"
    devrow["$dev.format_scheme"]="$scheme"
    devrow["$dev.format_label"]="$label"
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] FORMAT: $dev | ok fs=$fs scheme=$scheme label=$label" >> "$LOG_FILE"
    return 0
}

# Format every eligible drive sequentially (one filesystem at a time — avoids
# an I/O storm and USB-bus contention). Runs after post-erasure verification
# and before report generation (see erasure::run in 10_main.sh).
format::run_all() {
    format::resolve_config
    local dev
    for dev in "${devices[@]}"; do
        format::format_drive "$dev"
    done
}
