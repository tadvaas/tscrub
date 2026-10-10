# =============================================================================
# BIOS UNLOCK (remote clear) — pull a password staged in the dashboard and
# clear the BIOS admin/setup password on this machine.
#
# The kernel's firmware-attributes sysfs model is the write surface. Two vendor
# quirks are handled here:
#   * Password objects sit under <driver>/attributes/ on Dell/Lenovo but under
#     <driver>/authentication/ on HP (hp-bioscfg), so BOTH are scanned.
#   * hp-bioscfg and dell-wmi-sysman are linked BEFORE firmware_attributes_class.o
#     in drivers/platform/x86/Makefile yet use the same initcall level, so their
#     class device is created before the class is registered and is left
#     orphaned at /sys/devices/<driver> (no /sys/class/firmware-attributes
#     symlink) — the class directory then looks empty even though the interface
#     exists. Such orphaned trees are scanned too (BIOS_FA_ORPHAN_GLOB).
# =============================================================================

# How often (seconds) the appliance checks for a staged unlock command.
BIOS_UNLOCK_POLL_SECONDS=5

# Glob(s) scanned for firmware-attribute trees the kernel never published under
# the firmware-attributes class (see the header note). Override for tests.
BIOS_FA_ORPHAN_GLOB="${BIOS_FA_ORPHAN_GLOB:-/sys/devices/*}"

# Enumerate firmware-attribute *device* directories to inspect. Two layouts are
# covered:
#   * the kernel class — <BIOS_FA_ROOT>/<driver>/ (e.g.
#     /sys/class/firmware-attributes/hp-bioscfg/)
#   * an orphaned device tree from the initcall-ordering bug above
#     (BIOS_FA_ORPHAN_GLOB matches each such device dir directly).
# BIOS_FA_DEVICES (space-separated) overrides both for tests. One dir per line.
bios_unlock::_fa_devices() {
    local d
    if [[ -n "${BIOS_FA_DEVICES:-}" ]]; then
        printf '%s\n' $BIOS_FA_DEVICES
        return 0
    fi
    if [[ -n "${BIOS_FA_ROOT:-}" && -d "$BIOS_FA_ROOT" ]]; then
        for d in "$BIOS_FA_ROOT"/*/; do
            if [[ -d "${d}attributes" || -d "${d}authentication" ]]; then
                printf '%s\n' "${d%/}"
            fi
        done
    fi
    for d in ${BIOS_FA_ORPHAN_GLOB}; do
        if [[ -d "$d/attributes" || -d "$d/authentication" ]]; then
            printf '%s\n' "$d"
        fi
    done
    return 0
}

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

# Write a value to a firmware-attributes file, capturing the shell's own error
# text in BIOS_UNLOCK_WRITE_ERR. The text distinguishes the two failure modes:
#   "<shell>: <path>: <reason>"              the open() failed — the attribute is
#                                            not writable (read-only/permission)
#   "<shell>: printf: write error: <reason>" the driver/firmware REJECTED the
#                                            value (this is the informative one)
# Returns 1 on failure.
bios_unlock::_write_attr() {
    local f="$1" v="$2" err
    err="$( { printf '%s' "$v" > "$f"; } 2>&1 )" || {
        BIOS_UNLOCK_WRITE_ERR="${err:-write failed}"
        return 1
    }
    BIOS_UNLOCK_WRITE_ERR=""
    return 0
}

# Turn a captured shell error into a reason an operator can act on. Only a
# "write error" means the firmware evaluated the value; the errno then says why.
bios_unlock::_write_error_reason() {
    local e="$1"
    case "$e" in
        *"write error"*)
            case "$e" in
                *"Permission denied"*)
                    printf 'wrong password — the firmware rejected the current password' ;;
                *"Invalid argument"*)
                    printf 'rejected — the value does not meet the firmware password policy' ;;
                *"Operation not supported"*)
                    printf 'this firmware/driver does not support setting or clearing BIOS passwords' ;;
                *"Operation not permitted"*)
                    printf 'not permitted — root / CAP_SYS_ADMIN is required' ;;
                *"Read-only file system"*)
                    printf 'the password attribute is read-only' ;;
                *)
                    printf 'the firmware reported a failure (%s)' "${e##*: }" ;;
            esac ;;
        *"Permission denied"*)
            printf 'the password attribute is not writable' ;;
        *"No such file"*)
            printf 'the password attribute is missing' ;;
        *)
            printf 'could not write the password attribute (%s)' "${e##*: }" ;;
    esac
}

# Write the current password, then a blank new password, to a firmware
# attributes admin-password attribute dir. Returns 0 when the writes were
# ACCEPTED — which is not a claim that the password changed; re-read to confirm.
#
# The "blank" value is a single newline, not an empty string: a 0-byte write can
# be dropped before it reaches the driver's store, and every driver strips a
# trailing newline (so "\n" == "no new password" == clear).
bios_unlock::_write_clear() {
    local base="$1" unlock_pwd="$2"
    [[ -f "$base/current_password" && -f "$base/new_password" ]] || return 1
    bios_unlock::_write_attr "$base/current_password" "$unlock_pwd" || return 1
    bios_unlock::_write_attr "$base/new_password" $'\n' || return 1
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
# 1 when the slot still reads as set. Drivers differ: Dell/Lenovo expose
# is_password_set, HP exposes is_enabled, and a few only expose current_value.
bios_unlock::_verify_cleared() {
    local base="$1" v f
    for f in is_password_set is_enabled; do
        [[ -f "$base/$f" ]] || continue
        v="$(bios::_read "$base/$f")" || return 0
        bios::_truthy "$v" && return 1
        return 0
    done
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
    local unlock_pwd="$1" hp_new dev sub attr name base
    local prio best_prio=-1 best_base="" best_name="" best_rel=""
    local devs_seen=0 ro_dirs=0
    BIOS_UNLOCK_RESULT="unsupported"
    BIOS_UNLOCK_DETAIL="no writable BIOS password interface found"
    BIOS_UNLOCK_WRITE_ERR=""

    # Layer 1 — firmware-attributes sysfs (Dell dell-wmi-sysman, Lenovo
    # think_lmi, HP hp-bioscfg, …). Password objects live under attributes/ on
    # Dell/Lenovo but under authentication/ on HP, so scan both. Prefer the
    # setup/admin slot over power-on/system — the wipe should not clear a
    # power-on password the operator didn't ask for.
    while IFS= read -r dev; do
        devs_seen=$((devs_seen + 1))
        for sub in attributes authentication; do
            for attr in "$dev/$sub"/*/; do
                base="${attr%/}"
                [[ -d "$base" ]] || continue
                name="$(basename "$base")"
                if bios::_password_name "$name" && ! bios::_password_policy_name "$name"; then
                    if [[ -f "$base/current_password" && -f "$base/new_password" ]]; then
                        prio="$(bios_unlock::_slot_priority "$name")"
                        if (( prio > best_prio )); then
                            best_prio="$prio"
                            best_base="$base"
                            best_name="$name"
                            best_rel="$(basename "$dev")/${base#"$dev"/}"
                        fi
                    elif [[ -f "$base/role" ]]; then
                        # A password object with no write path — HP exposes its
                        # authentication objects only (no password reset).
                        ro_dirs=$((ro_dirs + 1))
                    fi
                fi
            done
        done
    done < <(bios_unlock::_fa_devices)

    if [[ -n "$best_base" ]]; then
        if bios_unlock::_write_clear "$best_base" "$unlock_pwd"; then
            # The writes were accepted — confirm the password actually went away.
            if bios_unlock::_verify_cleared "$best_base"; then
                BIOS_UNLOCK_RESULT="cleared"
                BIOS_UNLOCK_DETAIL="sysfs: ${best_rel}"
                return 0
            fi
            BIOS_UNLOCK_RESULT="failed"
            BIOS_UNLOCK_DETAIL="password still set after clear — the writes were accepted (nothing was rejected) but the password is unchanged, so this firmware exposes no clear path from Linux and the password cannot be validated here (sysfs: ${best_rel})"
            return 1
        fi
        BIOS_UNLOCK_RESULT="failed"
        BIOS_UNLOCK_DETAIL="$(bios_unlock::_write_error_reason "$BIOS_UNLOCK_WRITE_ERR") (sysfs: ${best_rel})"
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

    # Nothing writable — say why as precisely as we can: a read-only interface
    # is a different problem from the kernel publishing no interface at all.
    if [[ $ro_dirs -gt 0 ]]; then
        BIOS_UNLOCK_DETAIL="BIOS password interface is read-only (${ro_dirs} password object(s) with no write path) — cannot be cleared from Linux"
    elif [[ $devs_seen -eq 0 ]]; then
        BIOS_UNLOCK_DETAIL="no firmware-attributes device published by the kernel (class dir empty, no orphaned tree matching ${BIOS_FA_ORPHAN_GLOB}) — no BIOS password interface to write to"
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
