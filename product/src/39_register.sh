# =============================================================================
# DEVICE REGISTRATION — send this machine's identity + hardware + drive
# inventory to the portal immediately on boot (ITAD triage), and save the same
# snapshot to the report USB if one is mounted.
# =============================================================================

# The registration endpoint, derived from the report upload URL the same way as
# the MDM / presence / BIOS-unlock endpoints so a custom `tscrub_upload=` host
# is honoured.
register::endpoint() {
    local url="${TSCRUB_UPLOAD_URL:-https://tscrub.com/api/reports}"
    url="${url%/}"
    [[ "$url" == */api/reports ]] && url="${url%/api/reports}"
    printf '%s/api/devices/register' "$url"
}

# Build the registration JSON (one line). Pure string builder — unit-testable.
register::json() {
    local dev first=1
    printf '{"serial":"%s","uuid":"%s"' \
        "$(report::_json_field "${SYS_SERIAL:-}")" \
        "$(report::_json_field "${SYS_UUID:-}")"
    printf ',"manufacturer":"%s","product":"%s"' \
        "$(report::_json_field "${SYS_MANUFACTURER:-}")" \
        "$(report::_json_field "${SYS_PRODUCT:-}")"
    printf ',"chassis_serial":"%s","chassis_type":"%s"' \
        "$(report::_json_field "${SYS_CHASSIS_SERIAL:-}")" \
        "$(report::_json_field "${SYS_CHASSIS_TYPE:-}")"
    printf ',"bios_version":"%s","bios_date":"%s"' \
        "$(report::_json_field "${SYS_BIOS_VERSION:-}")" \
        "$(report::_json_field "${SYS_BIOS_DATE:-}")"
    printf ',"bios_lock":"%s","bios_lock_method":"%s"' \
        "$(report::_json_field "${BIOS_PASSWORD_STATUS:-UNKNOWN}")" \
        "$(report::_json_field "${BIOS_DETECTION_METHOD:-}")"
    printf ',"cpu":"%s","gpu":"%s","ram":"%s"' \
        "$(report::_json_field "${SYS_CPU_LIST:-}")" \
        "$(report::_json_field "${SYS_GPU_LIST:-}")" \
        "$(report::_json_field "${SYS_RAM_GB:-}")"
    printf ',"drives":['
    for dev in "${devices[@]}"; do
        [[ "$first" -eq 1 ]] && first=0 || printf ','
        printf '{"device":"%s","model":"%s","serial":"%s","size":"%s","bus":"%s","type":"%s","capability":"%s","class":"%s","opal_locked":%s}' \
            "$(report::_json_field "${devrow[$dev.device]:-$dev}")" \
            "$(report::_json_field "${devrow[$dev.model]:-}")" \
            "$(report::_json_field "${devrow[$dev.serial]:-}")" \
            "$(report::_json_field "${devrow[$dev.size]:-}")" \
            "$(report::_json_field "${devrow[$dev.bus]:-}")" \
            "$(report::_json_field "${devrow[$dev.type]:-}")" \
            "$(report::_json_field "${devrow[$dev.capability]:-}")" \
            "$(report::_json_field "${devrow[$dev.class]:-}")" \
            "$([[ "${opal_locked[$dev]:-}" == "YES" ]] && printf 'true' || printf 'false')"
    done
    printf ']}\n'
}

# POST the snapshot to the portal (best-effort — a failed push never fails the
# run). The portal marks the device online and shows it on the Devices tab even
# before anything has been wiped.
register::send() {
    local body
    [[ -n "${TSCRUB_API_TOKEN:-}" ]] || return 0
    body="$(register::json)"
    curl -fsS --connect-timeout 5 --max-time 15 \
        -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
        -H "Content-Type: application/json" \
        --data-binary "$body" "$(register::endpoint)" >/dev/null 2>&1
    return 0
}

# Save the same snapshot to the report USB (if one is mounted) as an offline
# record of what the machine was at triage time.
register::save_usb() {
    local body
    [[ -n "${REPORT_USB_MNT:-}" && -w "$REPORT_USB_MNT" ]] || return 0
    body="$(register::json)"
    printf '%s\n' "$body" > "${REPORT_USB_MNT%/}/tScrub_device_${SYS_SERIAL:-unknown}.json" 2>/dev/null || true
    return 0
}

# One-shot registration: portal push + USB copy. Call once after discovery and
# capability detection (so the drive inventory is complete), before selection.
register::push() {
    register::send
    register::save_usb
}
