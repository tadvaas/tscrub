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

# Clear the BIOS admin/setup password. Sets BIOS_UNLOCK_RESULT (cleared|failed|
# unsupported) and BIOS_UNLOCK_DETAIL (source or error). Returns 0 on cleared.
bios_unlock::clear() {
    local unlock_pwd="$1" driver attr name base hp_new
    BIOS_UNLOCK_RESULT="unsupported"
    BIOS_UNLOCK_DETAIL="no writable admin-password attribute found"

    # Layer 1 — modern firmware_attributes sysfs (Dell dell-wmi-sysman, Lenovo
    # thinkpad, …). Reuse 36_bios.sh's password-attribute discovery.
    for driver in "$BIOS_FA_ROOT"/*/; do
        [[ -d "${driver}attributes" ]] || continue
        for attr in "${driver}"attributes/*/; do
            base="$attr"
            name="$(basename "$attr")"
            [[ -f "$base/current_password" && -f "$base/new_password" ]] || continue
            if bios::_password_name "$name" && ! bios::_password_policy_name "$name"; then
                if bios_unlock::_write_clear "$base" "$unlock_pwd"; then
                    BIOS_UNLOCK_RESULT="cleared"
                    BIOS_UNLOCK_DETAIL="sysfs: $(basename "$driver")/${name}"
                    return 0
                fi
                BIOS_UNLOCK_RESULT="failed"
                BIOS_UNLOCK_DETAIL="write failed (sysfs: ${name}) — wrong password?"
                return 1
            fi
        done
    done

    # Layer 2 — HP legacy hp-wmi node.
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
    local resp cmd_id unlock_pwd
    [[ -n "${TSCRUB_API_TOKEN:-}" ]] || return 0
    resp="$(curl -fsS -G --connect-timeout 5 --max-time 15 \
        -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
        --data-urlencode "serial=${SYS_SERIAL:-}" \
        --data-urlencode "uuid=${SYS_UUID:-}" \
        "$(bios_unlock::pending_endpoint)" 2>/dev/null)" || return 0
    cmd_id="$(printf '%s' "$resp" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -n 1)"
    unlock_pwd="$(printf '%s' "$resp" | sed -n 's/.*"password":"\([^"]*\)".*/\1/p' | head -n 1)"
    [[ -n "$cmd_id" && -n "$unlock_pwd" ]] || return 0

    bios_unlock::clear "$unlock_pwd"
    local json_body
    json_body="$(printf '{"id":%s,"result":"%s","detail":"%s"}' \
        "$cmd_id" \
        "$BIOS_UNLOCK_RESULT" \
        "$(report::_json_field "$BIOS_UNLOCK_DETAIL")")"
    curl -fsS --connect-timeout 5 --max-time 15 \
        -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
        -H "Content-Type: application/json" \
        --data-binary "$json_body" \
        "$(bios_unlock::result_endpoint)" >/dev/null 2>&1
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
