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

# Resolve the coverage percentage: CLI (--verify-coverage) wins, then
# tscrub.conf (config::load_usb fills it), then the kernel cmdline. Empty, 0 or
# junk means off, which is the default.
verify::resolve_coverage() {
    if [[ -z "${VERIFY_COVERAGE_PCT:-}" ]]; then
        local param
        param="$( { tr ' ' '\n' < /proc/cmdline; } 2>/dev/null | sed -nE 's/^tscrub_verify_coverage=//p' | head -n 1)"
        [[ -n "$param" ]] && VERIFY_COVERAGE_PCT="$param"
    fi
    if [[ "${VERIFY_COVERAGE_PCT:-0}" =~ ^[0-9]+$ ]]; then
        VERIFY_COVERAGE_PCT=$(( 10#${VERIFY_COVERAGE_PCT:-0} ))
        (( VERIFY_COVERAGE_PCT >= 1 && VERIFY_COVERAGE_PCT <= 100 )) || VERIFY_COVERAGE_PCT=0
    else
        VERIFY_COVERAGE_PCT=0
    fi
}

# --- Coverage sampling (opt-in) --------------------------------------------
# Read `count` 1 MiB blocks starting at block `skip` and report whether every
# byte is zero. Overridable as a shell function in tests.
#   0 = all zero, 1 = data found, 2 = read error / tool unavailable
verify::_range_is_zero() {   # <dev> <skip_blocks> <count_blocks>
    local dev="$1" skip="$2" count="$3" rc_dd rc_cmp
    dd if="/dev/$dev" bs=1M skip="$skip" count="$count" 2>/dev/null | cmp -s - /dev/zero
    rc_dd=${PIPESTATUS[0]}; rc_cmp=$?
    (( rc_dd == 0 )) || return 2
    (( rc_cmp == 0 )) && return 0
    return 1
}

# Pre-flight for the primitive above: a `cmp` that cannot report "equal" on a
# known-zero stream would read as "data found" on every range and fail a good
# erase. Cheap, and it turns a silent false negative into "not applicable".
verify::_range_probe_ok() {
    dd if=/dev/zero bs=1M count=1 2>/dev/null | cmp -s - /dev/zero
}

# Sample VERIFY_COVERAGE_PCT of the address space as VERIFY_COVERAGE_RANGES
# contiguous 1 MiB-aligned ranges spread evenly across the drive, requiring
# every sampled byte to be zero. Only valid where the whole address space once
# carried a known pattern, i.e. after nwipe (whose trailing blanking pass leaves
# the drive at zero) — see the header guardrail.
#   0 = pass (VERIFY_COVERAGE_ACTUAL set), 1 = data found, 2 = not applicable
verify::_coverage_check() {   # <dev>
    local dev="$1" total ss bytes total_mib ranges pct want per span read i skip rc
    pct="${VERIFY_COVERAGE_PCT:-0}"
    (( pct > 0 )) || return 2
    command -v cmp >/dev/null 2>&1 || return 2
    verify::_range_probe_ok || return 2

    ss="${secsize[$dev]:-512}"
    total="$(verify::_total_sectors "$dev")" || return 2
    (( total > 0 )) || return 2
    bytes=$(( total * ss ))
    total_mib=$(( bytes / 1048576 ))
    (( total_mib >= 2 )) || return 2

    ranges=$(( ${VERIFY_COVERAGE_RANGES:-20} ))
    (( ranges < 1 )) && ranges=1
    (( ranges > total_mib )) && ranges=$total_mib
    want=$(( total_mib * pct / 100 ))
    (( want < ranges )) && want=$ranges      # at least 1 MiB per range
    per=$(( want / ranges ))
    span=$(( total_mib / ranges ))
    (( per > span )) && per=$span
    (( per >= 1 )) || return 2

    read=0
    for (( i = 0; i < ranges; i++ )); do
        skip=$(( i * span ))
        verify::_range_is_zero "$dev" "$skip" "$per"
        rc=$?
        if (( rc == 2 )); then
            return 2
        elif (( rc == 1 )); then
            # Round to the nearest percent: a 5% sample must not be recorded
            # as 4 because integer division floored it.
            VERIFY_COVERAGE_ACTUAL=$(( (read * 100 + total_mib / 2) / total_mib ))
            return 1
        fi
        read=$(( read + per ))
    done
    VERIFY_COVERAGE_ACTUAL=$(( (read * 100 + total_mib / 2) / total_mib ))
    return 0
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
    verify::resolve_coverage
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
    devrow["$dev.verify_technique"]="none"
    devrow["$dev.verify_coverage"]=""

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

    # Declared BEFORE the technique block below: a `local` declaration after an
    # assignment to an undeclared name would shadow it and reset the flag.
    local ss="${secsize[$dev]:-512}"
    local lba hash tmp got sz failed=0 unreadable=0 checked=0 cov_failed=0

    # What kind of evidence this drive can honestly carry.
    #
    # A crypto erase destroys the media encryption key, so the read-back is not
    # the mechanism that makes the data unrecoverable — scoring a sentinel there
    # reports a "pass" that proves nothing. Record the exemption instead.
    if [[ "${devrow[$dev.capability]:-}" == "CAP_OPAL_CRYPTO" ]]; then
        devrow["$dev.verify_technique"]="ce-exempt"
        devrow["$dev.verify_coverage"]=""
        devrow["$dev.verify_sectors"]=""
        devrow["$dev.verify_result"]="ce-exempt"
        return 0
    fi

    # Coverage sampling needs a known pattern over the WHOLE address space, so
    # it applies only where nwipe actually ran (it sets `standard`). A firmware
    # erase leaves undefined content, where a percentage read would prove
    # readability rather than absence.
    if [[ -n "${devrow[$dev.standard]:-}" ]] && (( ${VERIFY_COVERAGE_PCT:-0} > 0 )); then
        devrow["$dev.verify_technique"]="overwrite-sample"
        verify::_coverage_check "$dev"
        case $? in
            0) devrow["$dev.verify_coverage"]="$VERIFY_COVERAGE_ACTUAL" ;;
            1) devrow["$dev.verify_coverage"]="$VERIFY_COVERAGE_ACTUAL"
               cov_failed=1 ;;
            *) # Not applicable (no cmp, bad geometry, unreadable media): the
               # sentinel verdict below is the real evidence, so do not claim a
               # technique that never produced a number.
               devrow["$dev.verify_technique"]="sentinel"
               devrow["$dev.verify_coverage"]="" ;;
        esac
    else
        devrow["$dev.verify_technique"]="sentinel"
        devrow["$dev.verify_coverage"]=""
    fi

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
    if (( failed || cov_failed )); then
        devrow["$dev.verify_result"]="failed"
    elif (( unreadable )); then
        devrow["$dev.verify_result"]="unreadable"
    else
        devrow["$dev.verify_result"]="passed"
    fi
}
