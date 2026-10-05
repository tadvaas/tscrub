# =============================================================================
# VERIFY (post-erasure read-back)
# =============================================================================
# After an erasure, prove the wipe actually reached the media instead of merely
# asserting it from command completion. A per-run sentinel (nonce + LBA) is
# written to a small window of sectors at 5 fixed percentile positions (first,
# 25%, 50%, 75%, and the tail) BEFORE the wipe, then read back AFTER and checked
# to be gone. Uniform across NVMe/ATA/SCSI — every erase path goes through the
# raw block device, so one code path covers them all.
#
# Modes: none | sampled (default) | full.  `full` re-reads every sector and is
# opt-in + slow. Sampled = 5 positions x VERIFY_WINDOW sectors (~instant).
#
# Honest claim (guardrail): "N sectors re-read, sentinel not found" — never
# "entire drive verified". A drive whose erase FAILED/BLOCKED/FROZEN is `n/a`
# (its sentinel legitimately survives); only COMPLETED drives are scored.

# Resolve VERIFY_MODE: CLI (--verify) wins, then tscrub.conf (filled in
# config::load_usb), then the kernel cmdline. Invalid/empty -> "sampled".
verify::resolve_mode() {
    if [[ -z "${VERIFY_MODE:-}" ]]; then
        local param
        param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_verify=//p' | head -n 1)"
        case "$param" in
            none|sampled|full) VERIFY_MODE="$param" ;;
        esac
    fi
    case "${VERIFY_MODE:-}" in
        none|sampled|full) ;;
        *) VERIFY_MODE="sampled" ;;
    esac
}

# Deterministic, sector-sized sentinel for a given (nonce, LBA): the token
# "<nonce>:<lba>:" repeated to fill one logical sector. The unique nonce makes a
# coincidental read-back match effectively impossible.
verify::sentinel_bytes() {
    local nonce="$1" lba="$2" ss="${3:-512}"
    local token="${nonce}:${lba}:" out=""
    while (( ${#out} < ss )); do
        out+="$token"
    done
    printf '%s' "${out:0:ss}"
}

# SHA-256 of stdin (sha256sum -> shasum -> openssl), same chain as report::_sha256.
verify::_sha256_stdin() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum | awk '{print $1}'
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 | awk '{print $1}'
    elif command -v openssl >/dev/null 2>&1; then
        openssl dgst -sha256 | awk '{print $NF}'
    fi
}

verify::_sentinel_hash() {
    printf '%s' "$(verify::sentinel_bytes "$1" "$2" "$3")" | verify::_sha256_stdin
}

# --- Low-level I/O (overridable as shell functions in tests) -----------------
verify::_total_sectors() {   # <dev> -> total sectors (size / logical sector size)
    local dev="$1" ss bytes
    ss="${secsize[$dev]:-512}"
    bytes="$(blockdev --getsize64 "/dev/$dev" 2>/dev/null)"
    [[ "$bytes" =~ ^[0-9]+$ ]] || return 1
    echo $(( bytes / ss ))
}

verify::_write_sector() {    # <dev> <lba> <ss> -> write sentinel, return 0/1
    local dev="$1" lba="$2" ss="$3"
    printf '%s' "$(verify::sentinel_bytes "$VERIFY_NONCE" "$lba" "$ss")" \
        | dd of="/dev/$dev" bs="$ss" count=1 seek="$lba" conv=notrunc,fsync 2>/dev/null
}

verify::_read_sector() {     # <dev> <lba> <ss> <out-file> -> read one sector
    local dev="$1" lba="$2" ss="$3" out="$4"
    dd if="/dev/$dev" of="$out" bs="$ss" count=1 skip="$lba" 2>/dev/null
}

verify::_flush() {           # <dev> -> flush write cache (best effort)
    local dev="$1"
    blockdev --flushbufs "/dev/$dev" 2>/dev/null || true
}

# --- Plant (pre-erase) -------------------------------------------------------
verify::plant_all() {
    verify::resolve_mode
    [[ "$VERIFY_MODE" != "none" ]] || return 0

    VERIFY_NONCE="$(cat /proc/sys/kernel/random/uuid 2>/dev/null \
        || uuidgen 2>/dev/null \
        || printf 'nonce-%s' "$$")"
    VERIFY_STATE_DIR="$(mktemp -d /tmp/tscrub-verify.XXXXXX)" || return 0

    local dev
    for dev in "${devices[@]}"; do
        verify::_plant_drive "$dev"
    done
}

verify::_plant_drive() {
    local dev="$1"
    devrow["$dev.verify_result"]="n/a"
    devrow["$dev.verify_sectors"]=""

    [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] || return 0
    [[ "${DRY_RUN:-0}" -eq 0 ]] || return 0
    case "${devrow[$dev.class]:-}" in
        FAILED|FROZEN) return 0 ;;
    esac
    [[ "${devrow[$dev.capability]:-}" == "CAP_NONE" ]] && return 0

    local ss="${secsize[$dev]:-512}" window="${VERIFY_WINDOW:-8}"
    local total
    total="$(verify::_total_sectors "$dev")" || { devrow["$dev.verify_result"]="skipped"; return 0; }
    (( total >= 2 )) || { devrow["$dev.verify_result"]="skipped"; return 0; }

    local state_file="$VERIFY_STATE_DIR/$dev.verify"
    : > "$state_file" 2>/dev/null || { devrow["$dev.verify_result"]="skipped"; return 0; }

    local pos lba w cur count=0 hash
    for pos in 0 1 2 3 4; do
        case "$pos" in
            0) lba=0 ;;
            1) lba=$(( total / 4 )) ;;
            2) lba=$(( total / 2 )) ;;
            3) lba=$(( total / 2 + total / 4 )) ;;
            4) lba=$(( total - window )); (( lba < 0 )) && lba=0 ;;
        esac
        for (( w = 0; w < window; w++ )); do
            cur=$(( lba + w ))
            (( cur < total )) || break
            if verify::_write_sector "$dev" "$cur" "$ss"; then
                hash="$(verify::_sentinel_hash "$VERIFY_NONCE" "$cur" "$ss")"
                printf '%s:%s\n' "$cur" "$hash" >> "$state_file"
                count=$(( count + 1 ))
            fi
        done
    done

    verify::_flush "$dev"

    if (( count == 0 )); then
        devrow["$dev.verify_result"]="skipped"
    fi
    devrow["$dev.verify_sectors"]="$count"
}

# --- Check (post-erase) ------------------------------------------------------
verify::check_all() {
    [[ "${VERIFY_MODE:-none}" != "none" ]] || return 0
    [[ -n "${VERIFY_STATE_DIR:-}" ]] || return 0

    # Force read-backs to hit media — the page cache would return the sentinel
    # and produce a false FAILED. Linux only; a no-op elsewhere (tests, macOS).
    if [[ -w /proc/sys/vm/drop_caches ]]; then
        sync 2>/dev/null || true
        echo 1 > /proc/sys/vm/drop_caches 2>/dev/null || true
    fi

    local dev
    for dev in "${devices[@]}"; do
        verify::_check_drive "$dev"
    done
}

verify::_check_drive() {
    local dev="$1"
    local state_file="$VERIFY_STATE_DIR/$dev.verify"
    [[ -f "$state_file" ]] || return 0
    # Only score drives whose erase actually completed; a FAILED/BLOCKED/FROZEN
    # drive is already "not sanitised" and its sentinel may legitimately survive.
    # Also drop the plant-time sector count, so such a drive reports "n/a" with
    # no "N sectors" figure (nothing was actually re-read).
    if [[ "${devrow[$dev.status]:-}" != "COMPLETED" ]]; then
        devrow["$dev.verify_sectors"]=""
        return 0
    fi

    local ss="${secsize[$dev]:-512}"
    local lba hash tmp got sz failed=0 unreadable=0 checked=0
    while IFS=: read -r lba hash; do
        [[ "$lba" =~ ^[0-9]+$ && -n "$hash" ]] || continue
        tmp="$(mktemp /tmp/tscrub-vr.XXXXXX)" || { unreadable=1; continue; }
        if verify::_read_sector "$dev" "$lba" "$ss" "$tmp"; then
            sz="$(wc -c < "$tmp" 2>/dev/null | awk '{print $1}')"
            if [[ "$sz" == "$ss" ]]; then
                got="$(report::_sha256 "$tmp")"
                if [[ -n "$got" && "$got" == "$hash" ]]; then
                    failed=1
                fi
            else
                unreadable=1
            fi
        else
            unreadable=1
        fi
        rm -f "$tmp"
        checked=$(( checked + 1 ))
    done < "$state_file"

    devrow["$dev.verify_sectors"]="$checked"
    if (( failed )); then
        devrow["$dev.verify_result"]="failed"
    elif (( unreadable )); then
        devrow["$dev.verify_result"]="unreadable"
    else
        devrow["$dev.verify_result"]="passed"
    fi
}
