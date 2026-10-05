# =============================================================================
# KEY INJECTION (remote) — pull a product key staged in the dashboard and write
# it into this machine's OA3 UEFI NVRAM variable, which the BIOS reads at boot
# to construct the ACPI MSDM table. Same pull model as the remote BIOS-unlock
# feature: the appliance polls GET .../key/inject/pending, the dashboard stages
# a key, and the appliance overwrites the key inside the OA3 variable.
#
# Why a UEFI variable and not SPI flash: on modern OEM machines (OA3 / Windows
# 8+ activation) the MSDM table is NOT a static flash table — the firmware
# derives it at boot from an OA3 UEFI variable (e.g. HP_OA3-<GUID>). SMART DPK
# uses the same path (ClipOaUefiRead / SetVariable). The variable holds the key
# in plaintext, so re-keying is just: find the variable that contains the
# current key and replace those 29 bytes with the new key. The BIOS rebuilds
# the MSDM table + checksum itself — no table assembly or SPI programming.
#
# Locked machines: most OEMs set a one-way lock (e.g. HP_OA3_LOCK=1) once the
# key is committed; the firmware then rejects SetVariable with
# EFI_SECURITY_VIOLATION. We report that honestly as "unsupported".
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

# ---- OA3 UEFI-variable helpers (pure bash) --------------------------------

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

# Load efivarfs (it is a module in the appliance kernel) and mount it. Returns 0
# once /sys/firmware/efi/efivars is populated; 1 when the machine did not boot
# UEFI or the variable store is unavailable.
msdm::efivarfs_ready() {
    [[ -d /sys/firmware/efi/efivars ]] || return 1
    if [[ -z "$(ls -A /sys/firmware/efi/efivars 2>/dev/null)" ]]; then
        modprobe efivarfs 2>/dev/null || true
        mount -t efivarfs efivarfs /sys/firmware/efi/efivars 2>/dev/null || true
    fi
    [[ -n "$(ls -A /sys/firmware/efi/efivars 2>/dev/null)" ]] || return 1
    return 0
}

# Current product key from the live MSDM table (29 chars at offset 56).
msdm::current_key() {
    local k
    k="$(dd if=/sys/firmware/acpi/tables/MSDM bs=1 count=29 skip=56 2>/dev/null)"
    [[ "$k" =~ ^[A-Z0-9]{5}(-[A-Z0-9]{5}){4}$ ]] && { printf '%s' "$k"; return 0; }
    return 1
}

# Find the UEFI variable whose data contains the given key. Echoes its path.
msdm::find_oa3_var() {
    local key="$1" f
    for f in /sys/firmware/efi/efivars/*; do
        [[ -f "$f" ]] || continue
        if grep -aqF "$key" "$f" 2>/dev/null; then
            printf '%s' "$f"
            return 0
        fi
    done
    return 1
}

# Append a timestamped diagnostic line to the appliance log.
msdm::log() {
    printf '[%s] KEY_INJECT: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >> "${LOG_FILE:-/tmp/tScrub.log}" 2>/dev/null || true
}

# Basename of a lock variable guarding the OA3 variable (vendor-specific names),
# or '' when none found.
msdm::lock_var_name() {
    ls /sys/firmware/efi/efivars/ 2>/dev/null | grep -iE 'OA3.*LOCK|LOCK.*OA3|MSDM.*LOCK|LOCK.*MSDM|SLIC.*LOCK|LOCK.*SLIC' | head -1
}

# True (0) when the given lock variable's data is non-zero (locked).
msdm::is_locked() {  # $1=variable basename
    local data
    data="$(cat "/sys/firmware/efi/efivars/$1" 2>/dev/null | tail -c +5 | tr -d '\000')"
    case "$data" in ''|0|00) return 1 ;; *) return 0 ;; esac
}

# Replace $old with $new (equal length) in a binary buffer file $1. Returns 0 on
# success. Pure file I/O (head -c / tail -c) so it is unit-testable.
msdm::replace_bytes() {  # $1=file $2=old $3=new
    local f="$1" old="$2" new="$3" off
    [[ ${#old} -eq ${#new} ]] || return 1
    off="$(grep -aboF "$old" "$f" 2>/dev/null | head -1 | cut -d: -f1)"
    [[ -n "$off" && "$off" =~ ^[0-9]+$ ]] || return 1
    head -c "$off" "$f" > "$f.new" 2>/dev/null || return 1
    printf '%s' "$new" >> "$f.new" || return 1
    tail -c +$((off + ${#old} + 1)) "$f" >> "$f.new" 2>/dev/null || return 1
    mv -f "$f.new" "$f"
    return 0
}

# Write a staged key into the OA3 UEFI variable. Sets KEY_INJECT_RESULT
# (injected|failed|unsupported) and KEY_INJECT_DETAIL. Returns 0 only on
# injected.
msdm::inject() {
    local key old_key var varname lockname tmp
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
    msdm::efivarfs_ready || {
        KEY_INJECT_RESULT="unsupported"
        KEY_INJECT_DETAIL="no UEFI variable store (not a UEFI boot)"
        return 1
    }

    old_key="$(msdm::current_key)" || {
        KEY_INJECT_RESULT="unsupported"
        KEY_INJECT_DETAIL="no MSDM key to replace (fresh-table injection not yet supported)"
        return 1
    }
    msdm::log "current key ...${old_key: -5}"

    [[ "$old_key" == "$key" ]] && {
        KEY_INJECT_RESULT="injected"
        KEY_INJECT_DETAIL="key already present"
        return 0
    }

    var="$(msdm::find_oa3_var "$old_key")" || {
        KEY_INJECT_RESULT="unsupported"
        KEY_INJECT_DETAIL="MSDM key not found in any UEFI variable (unsupported layout)"
        return 1
    }
    varname="$(basename "$var")"
    msdm::log "found OA3 variable: $varname"

    lockname="$(msdm::lock_var_name)"
    if [[ -n "$lockname" ]] && msdm::is_locked "$lockname"; then
        msdm::log "locked by $lockname"
        KEY_INJECT_RESULT="unsupported"
        KEY_INJECT_DETAIL="OA3 variable is locked (by $lockname)"
        return 1
    fi

    # Copy attrs + data, replace the key bytes, write the variable back.
    tmp="/tmp/tscrub-oa3-new.$$"
    rm -f "$tmp"
    if ! cat "$var" > "$tmp" 2>/dev/null; then
        rm -f "$tmp"
        KEY_INJECT_DETAIL="failed to read UEFI variable"
        return 1
    fi
    msdm::replace_bytes "$tmp" "$old_key" "$key" || {
        rm -f "$tmp"
        KEY_INJECT_DETAIL="key offset not found in UEFI variable"
        return 1
    }

    if ! cat "$tmp" > "$var" 2>/dev/null; then
        rm -f "$tmp"
        msdm::log "write rejected for $varname"
        KEY_INJECT_RESULT="unsupported"
        KEY_INJECT_DETAIL="OA3 variable $varname is locked (firmware rejected the write)"
        return 1
    fi
    rm -f "$tmp"

    msdm::log "wrote new key to $varname"
    KEY_INJECT_RESULT="injected"
    KEY_INJECT_DETAIL="key written to $varname; effective at next boot"
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
