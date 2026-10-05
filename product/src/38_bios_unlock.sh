# =============================================================================
# BIOS UNLOCK (remote clear) — pull a password staged in the dashboard and
# clear the BIOS admin/setup password on this machine.
# =============================================================================

# How often (seconds) the appliance checks for a staged unlock command.
BIOS_UNLOCK_POLL_SECONDS=5

bios_unlock::pending_endpoint() {
    local url="${TSCRUB_UPLOAD_URL:-https://tscrub.com/api/reports}"
    url="${url%/}"
    [[ "$url" == */api/reports ]] && url="${url%/api/reports}"
    printf '%s/api/bios/unlock/pending' "$url"
}

bios_unlock::result_endpoint() {
    local url="${TSCRUB_UPLOAD_URL:-https://tscrub.com/api/reports}"
    url="${url%/}"
    [[ "$url" == */api/reports ]] && url="${url%/api/reports}"
    printf '%s/api/bios/unlock/result' "$url"
}

# Write the current password + a blank new password to a firmware_attributes
# admin-password attribute dir. Returns 0 on success.
bios_unlock::_write_clear() {
    local base="$1" unlock_pwd="$2"
    [[ -f "$base/current_password" && -f "$base/new_password" ]] || return 1
    printf '%s' "$unlock_pwd" > "$base/current_password" 2>/dev/null || return 1
    printf '' > "$base/new_password" 2>/dev/null || return 1
    return 0
}

# Slot priority: setup/admin password (2) > power-on/system password (1) >
# any other password-named attribute (0).
bios_unlock::_slot_priority() {
    local name
    name="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
    case "$name" in
        *admin*|*setup*) printf '2' ;;
        *system*|*power*|*boot*) printf '1' ;;
        *) printf '0' ;;
    esac
}

# Re-read a cleared slot to confirm the password actually went away. Returns 0
# when cleared (or when there is no re-read signal — some drivers expose none),
# 1 when the slot still reads as set.
bios_unlock::_verify_cleared() {
    local base="$1" v
    if [[ -f "$base/is_password_set" ]]; then
        v="$(bios::_read "$base/is_password_set")" || return 0
        bios::_truthy "$v" && return 1
        return 0
    fi
    if [[ -f "$base/current_value" ]]; then
        v="$(bios::_read "$base/current_value")" || return 0
        [[ -z "$v" ]] && return 0
        bios::_truthy "$v" && return 1
        return 0
    fi
    return 0
}

# Parse a pending-command JSON response into "<id>\n<password>". Prefers the
# JSON-safe base64 field, falls back to the legacy field. Empty on failure.
bios_unlock::_parse_pending() {
    local resp="$1" cmd_id unlock_pwd b64
    cmd_id="$(printf '%s' "$resp" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -n 1)"
    b64="$(printf '%s' "$resp" | sed -n 's/.*"password_b64":"\([^"]*\)".*/\1/p' | head -n 1)"
    if [[ -n "$b64" ]]; then
        unlock_pwd="$(printf '%s' "$b64" | base64 -d 2>/dev/null)"
    else
        unlock_pwd="$(printf '%s' "$resp" | sed -n 's/.*"password":"\([^"]*\)".*/\1/p' | head -n 1)"
    fi
    [[ -n "$cmd_id" && -n "$unlock_pwd" ]] || return 1
    printf '%s\n%s' "$cmd_id" "$unlock_pwd"
}

# GET the pending endpoint (with the TLS clock-skew retry). Prints the body.
bios_unlock::_fetch_pending() {
    local resp
    resp="$(curl -fsS -G --connect-timeout 5 --max-time 15 \
        -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
        --data-urlencode "serial=${SYS_SERIAL:-}" \
        --data-urlencode "uuid=${SYS_UUID:-}" \
        "$(bios_unlock::pending_endpoint)" 2>&1)" && { printf '%s' "$resp"; return 0; }
    if [[ "$resp" == *"curl: (60)"* ]]; then
        curl -k -fsS -G --connect-timeout 5 --max-time 15 \
            -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
            --data-urlencode "serial=${SYS_SERIAL:-}" \
            --data-urlencode "uuid=${SYS_UUID:-}" \
            "$(bios_unlock::pending_endpoint)" 2>/dev/null
        return $?
    fi
    return 1
}

# POST the result (with the TLS clock-skew retry). Fire-and-forget.
bios_unlock::_report_result() {
    local json_body="$1" err
    err="$(curl -fsS --connect-timeout 5 --max-time 15 --retry 3 --retry-delay 2 --retry-connrefused --retry-all-errors \
        -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
        -H "Content-Type: application/json" \
        --data-binary "$json_body" \
        "$(bios_unlock::result_endpoint)" 2>&1 >/dev/null)" && return 0
    if [[ "$err" == *"curl: (60)"* ]]; then
        curl -k -fsS --connect-timeout 5 --max-time 15 --retry 3 --retry-delay 2 --retry-connrefused --retry-all-errors \
            -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
            -H "Content-Type: application/json" \
            --data-binary "$json_body" \
            "$(bios_unlock::result_endpoint)" >/dev/null 2>&1
    fi
    return 0
}

# Clear the BIOS admin/setup password. Sets BIOS_UNLOCK_RESULT (cleared|failed|
# unsupported) and BIOS_UNLOCK_DETAIL (source or error). Returns 0 on cleared.
bios_unlock::clear() {
    local unlock_pwd="$1" driver attr name base hp_new
    local prio best_prio=-1 best_base="" best_name=""
    BIOS_UNLOCK_RESULT="unsupported"
    BIOS_UNLOCK_DETAIL="no writable admin-password attribute found"

    # Layer 1 — modern firmware_attributes sysfs (Dell dell-wmi-sysman, Lenovo
    # think_lmi, …). Prefer the setup/admin slot over power-on/system — the
    # wipe should not clear a power-on password the operator didn't ask for.
    for driver in "$BIOS_FA_ROOT"/*/; do
        [[ -d "${driver}attributes" ]] || continue
        for attr in "${driver}"attributes/*/; do
            base="$attr"
            name="$(basename "$attr")"
            [[ -f "$base/current_password" && -f "$base/new_password" ]] || continue
            if bios::_password_name "$name" && ! bios::_password_policy_name "$name"; then
                prio="$(bios_unlock::_slot_priority "$name")"
                if (( prio > best_prio )); then
                    best_prio="$prio"; best_base="$base"; best_name="$name"
                fi
            fi
        done
    done

    if [[ -n "$best_base" ]]; then
        if bios_unlock::_write_clear "$best_base" "$unlock_pwd"; then
            # Confirm the clear actually took effect before claiming success.
            if bios_unlock::_verify_cleared "$best_base"; then
                BIOS_UNLOCK_RESULT="cleared"
                BIOS_UNLOCK_DETAIL="sysfs: $(basename "$(dirname "$(dirname "$best_base")")")/${best_name}"
                return 0
            fi
            BIOS_UNLOCK_RESULT="failed"
            BIOS_UNLOCK_DETAIL="password still set after clear (sysfs: ${best_name}) — wrong password or read-only"
            return 1
        fi
        BIOS_UNLOCK_RESULT="failed"
        BIOS_UNLOCK_DETAIL="write failed (sysfs: ${best_name}) — wrong password?"
        return 1
    fi

    # Layer 2 — HP legacy hp-wmi node (no re-read signal; trust the write).
    hp_new="${BIOS_HP_WMI_FILE%/*}/new_bios_password"
    if [[ -f "$BIOS_HP_WMI_FILE" && -f "$hp_new" ]]; then
        if printf '%s' "$unlock_pwd" > "$BIOS_HP_WMI_FILE" 2>/dev/null \
           && printf '' > "$hp_new" 2>/dev/null; then
            BIOS_UNLOCK_RESULT="cleared"
            BIOS_UNLOCK_DETAIL="legacy: hp-wmi bios_password"
            return 0
        fi
        BIOS_UNLOCK_RESULT="failed"
        BIOS_UNLOCK_DETAIL="write failed (hp-wmi)"
        return 1
    fi

    return 1
}

# Pull a staged command (if any), clear the password, and report the result.
bios_unlock::poll_and_execute() {
    local resp parsed cmd_id unlock_pwd json_body
    [[ -n "${TSCRUB_API_TOKEN:-}" ]] || return 0

    resp="$(bios_unlock::_fetch_pending)" || return 0
    parsed="$(bios_unlock::_parse_pending "$resp")" || return 0
    [[ -n "$parsed" ]] || return 0
    cmd_id="${parsed%%$'\n'*}"
    unlock_pwd="${parsed#*$'\n'}"

    bios_unlock::clear "$unlock_pwd"
    json_body="$(printf '{"id":%s,"result":"%s","detail":"%s"}' \
        "$cmd_id" \
        "$BIOS_UNLOCK_RESULT" \
        "$(report::_json_field "$BIOS_UNLOCK_DETAIL")")"
    bios_unlock::_report_result "$json_body"
    return 0
}

# Background loop: check for a staged unlock every few seconds for the life of
# the run. Forked by fn_main and killed when the run finishes.
bios_unlock::loop() {
    [[ -n "${TSCRUB_API_TOKEN:-}" ]] || return 0
    while :; do
        bios_unlock::poll_and_execute
        sleep "$BIOS_UNLOCK_POLL_SECONDS"
    done
}
