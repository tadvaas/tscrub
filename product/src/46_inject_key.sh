# =============================================================================
# KEY INJECTION (remote) — pull a product key staged in the dashboard and write
# it into this machine's MSDM ACPI table in firmware. Same pull model as the
# remote BIOS-unlock feature: the appliance polls GET .../key/inject/pending,
# the dashboard stages a key, and the appliance patches + reflashes the MSDM
# table once it is safe.
#
# The MSDM patch/checksum is done in pure bash (the appliance image has no
# guaranteed Python): read the 85-byte table, overwrite the 29-byte key field at
# offset 56, and recompute the ACPI checksum byte at offset 9. The actual write
# is a full-SPI flashrom round-trip (dump -> patch in-dump -> reflash); when
# flashrom is absent or the region is write-protected we report "unsupported".
# =============================================================================

# How often (seconds) the appliance checks for a staged injection.
KEY_INJECT_POLL_SECONDS=5

key_inject::pending_endpoint() {
    local url="${TSCRUB_UPLOAD_URL:-https://tscrub.com/api/reports}"
    url="${url%/}"
    [[ "$url" == */api/reports ]] && url="${url%/api/reports}"
    printf '%s/api/key/inject/pending' "$url"
}

key_inject::result_endpoint() {
    local url="${TSCRUB_UPLOAD_URL:-https://tscrub.com/api/reports}"
    url="${url%/}"
    [[ "$url" == */api/reports ]] && url="${url%/api/reports}"
    printf '%s/api/key/inject/result' "$url"
}

# Report the outcome of a claimed injection back to the dashboard (best-effort).
key_inject::report() {
    local cmd_id="$1" result="$2" detail="${3:-}" json_body
    json_body="$(printf '{"id":%s,"result":"%s","detail":"%s"}' \
        "$cmd_id" "$result" "$(report::_json_field "$detail")")"
    curl -fsS --connect-timeout 5 --max-time 15 \
        -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
        -H "Content-Type: application/json" \
        --data-binary "$json_body" \
        "$(key_inject::result_endpoint)" >/dev/null 2>&1
    return 0
}

# ---- MSDM table helpers (pure bash) ----------------------------------------

# Normalise + validate a product key. Echoes the 29-char uppercase key, or
# returns 1. Permissive charset — real MSDM keys can contain letters a strict
# base-24 check would reject.
msdm::normalize_key() {
    local key
    key="$(printf '%s' "$1" | tr 'a-z' 'A-Z' | tr -d '[:space:]')"
    [[ "$key" =~ ^[A-Z0-9]{5}(-[A-Z0-9]{5}){4}$ ]] || return 1
    printf '%s' "$key"
    return 0
}

# Write the 29-char key at the given offset (no padding: 25 chars + 4 hyphens).
msdm::patch_key() {  # $1=file $2=offset $3=key
    local f="$1" off="$2" key="$3"
    [[ ${#key} -eq 29 ]] || return 1
    printf '%s' "$key" | dd of="$f" bs=1 count=29 seek="$off" conv=notrunc 2>/dev/null || return 1
    return 0
}

# Fix the ACPI checksum byte (byte 9 of an 85-byte table at $2). The checksum is
# chosen so the sum of all 85 bytes == 0 mod 256.
msdm::fix_checksum() {  # $1=file $2=table_offset
    local f="$1" base="$2" i byte sum=0 cksum
    dd if=/dev/zero of="$f" bs=1 count=1 seek=$((base + 9)) conv=notrunc 2>/dev/null || return 1
    for (( i = 0; i < 85; i++ )); do
        byte="$(dd if="$f" bs=1 count=1 skip=$((base + i)) 2>/dev/null | od -An -tu1 | tr -d ' ')"
        [[ "$byte" =~ ^[0-9]+$ ]] || byte=0
        sum=$(( (sum + byte) % 256 ))
    done
    cksum=$(( (256 - sum) % 256 ))
    printf "\\$(printf '%03o' "$cksum")" | dd of="$f" bs=1 count=1 seek=$((base + 9)) conv=notrunc 2>/dev/null || return 1
    return 0
}

# Byte offset of the "MSDM" signature inside a firmware dump ('' when absent).
msdm::find_offset() {
    grep -abo 'MSDM' "$1" 2>/dev/null | head -1 | cut -d: -f1
}

# Read + patch the live MSDM table into a temp file (85 bytes). Returns 0 and
# leaves the file at $1, or 1 (with no file) when there is nothing to patch.
msdm::stage_table() {  # $1=out_file $2=key
    local out="$1" key="$2" size
    if ! dd if=/sys/firmware/acpi/tables/MSDM of="$out" bs=85 count=1 2>/dev/null; then
        return 1
    fi
    size="$(wc -c < "$out" 2>/dev/null | tr -d ' ')"
    [[ "$size" == "85" ]] || return 1
    [[ "$(dd if="$out" bs=4 count=1 2>/dev/null)" == "MSDM" ]] || return 1
    msdm::patch_key "$out" 56 "$key" || return 1
    msdm::fix_checksum "$out" 0 || return 1
    return 0
}

# Write a staged key into firmware. Sets KEY_INJECT_RESULT (injected|failed|
# unsupported) and KEY_INJECT_DETAIL. Returns 0 only on injected.
msdm::inject() {
    local key table dump off backup
    KEY_INJECT_RESULT="failed"
    KEY_INJECT_DETAIL="unknown error"

    key="$(msdm::normalize_key "$1")" || {
        KEY_INJECT_DETAIL="invalid key format"
        return 1
    }
    [[ $EUID -eq 0 ]] || {
        KEY_INJECT_DETAIL="root required to write firmware"
        return 1
    }

    table="/tmp/tscrub-msdm-table.$$"
    dump="/tmp/tscrub-msdm-dump.$$"
    rm -f "$table" "$dump"

    if ! msdm::stage_table "$table" "$key"; then
        rm -f "$table" "$dump"
        KEY_INJECT_RESULT="unsupported"
        KEY_INJECT_DETAIL="no MSDM table to patch (fresh-table build not yet supported)"
        return 1
    fi

    if ! command -v flashrom >/dev/null 2>&1; then
        rm -f "$table" "$dump"
        KEY_INJECT_RESULT="unsupported"
        KEY_INJECT_DETAIL="flashrom not available — needs external programmer"
        return 1
    fi

    if ! flashrom -p internal -r "$dump" >/dev/null 2>&1; then
        rm -f "$table" "$dump"
        KEY_INJECT_RESULT="unsupported"
        KEY_INJECT_DETAIL="firmware read failed (write-protected?)"
        return 1
    fi
    backup="/tmp/msdm-backup-$(date +%s).bin"
    cp "$dump" "$backup" 2>/dev/null || true

    off="$(msdm::find_offset "$dump")"
    if [[ -z "$off" || ! "$off" =~ ^[0-9]+$ ]]; then
        rm -f "$table" "$dump"
        KEY_INJECT_RESULT="unsupported"
        KEY_INJECT_DETAIL="MSDM not found in firmware dump"
        return 1
    fi
    msdm::patch_key "$dump" $((off + 56)) "$key" || {
        rm -f "$table" "$dump"
        KEY_INJECT_DETAIL="dump patch failed"
        return 1
    }
    msdm::fix_checksum "$dump" "$off" || {
        rm -f "$table" "$dump"
        KEY_INJECT_DETAIL="dump checksum failed"
        return 1
    }

    if ! flashrom -p internal -w "$dump" >/dev/null 2>&1; then
        rm -f "$table" "$dump"
        KEY_INJECT_RESULT="failed"
        KEY_INJECT_DETAIL="firmware write failed (backup at $backup)"
        return 1
    fi

    rm -f "$table" "$dump"
    KEY_INJECT_RESULT="injected"
    KEY_INJECT_DETAIL="key written to MSDM; effective at next boot (backup at $backup)"
    return 0
}

# Pull a staged key (if any) and, when safe, write it. Returns 0 always — a
# missing command or a failed poll must never fail the session.
key_inject::poll_and_execute() {
    local resp cmd_id key
    [[ -n "${TSCRUB_API_TOKEN:-}" ]] || return 0
    # Never touch firmware during a wipe — leave the command staged (pending).
    [[ "$(status::field phase)" == "wiping" ]] && return 0

    resp="$(curl -fsS -G --connect-timeout 5 --max-time 15 \
        -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
        --data-urlencode "serial=${SYS_SERIAL:-}" \
        --data-urlencode "uuid=${SYS_UUID:-}" \
        "$(key_inject::pending_endpoint)" 2>/dev/null)" || return 0
    cmd_id="$(printf '%s' "$resp" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -n 1)"
    key="$(printf '%s' "$resp" | sed -n 's/.*"key":"\([^"]*\)".*/\1/p' | head -n 1)"
    [[ -n "$cmd_id" && -n "$key" ]] || return 0

    # Re-check in case a wipe started between the claim above and now.
    if [[ "$(status::field phase)" == "wiping" ]]; then
        key_inject::report "$cmd_id" deferred "wipe in progress"
        return 0
    fi

    msdm::inject "$key"
    key_inject::report "$cmd_id" "$KEY_INJECT_RESULT" "$KEY_INJECT_DETAIL"
    return 0
}

# Background loop: check for a staged key every few seconds for the life of the
# run. Forked by fn_main and killed when the run finishes.
key_inject::loop() {
    [[ -n "${TSCRUB_API_TOKEN:-}" ]] || return 0
    while :; do
        key_inject::poll_and_execute
        sleep "$KEY_INJECT_POLL_SECONDS"
    done
}
