# =============================================================================
# DEVICE REGISTRATION — send this machine's identity + hardware + drive
# inventory to the portal immediately on boot (ITAD triage) as a DIAGNOSTICS
# report (the first of two report kinds; the signed erasure report follows at
# the end of the run), and save the same snapshot to the report USB if one is
# mounted.
# =============================================================================

# The diagnostics-report endpoint, derived from the report upload URL the same
# way as the MDM / presence / BIOS-unlock endpoints so a custom
# `tscrub_upload=` host is honoured.
register::endpoint() {
    local url="${TSCRUB_UPLOAD_URL:-https://tscrub.com/api/reports}"
    url="${url%/}"
    [[ "$url" == */api/reports ]] && url="${url%/api/reports}"
    printf '%s/api/reports/diagnostics' "$url"
}

# Build the registration JSON body (one line, WITHOUT the report_id /
# digital_identifier audit fields). Pure string builder — unit-testable.
register::json_body() {
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
    printf ',"sku":"%s","asset_tag":"%s","bios_vendor":"%s","board":"%s","board_serial":"%s","tpm":"%s"' \
        "$(report::_json_field "${SYS_SKU:-}")" \
        "$(report::_json_field "${ASSET_TAG:-${SYS_ASSET_TAG:-}}")" \
        "$(report::_json_field "${SYS_BIOS_VENDOR:-}")" \
        "$(report::_json_field "${SYS_BOARD:-}")" \
        "$(report::_json_field "${SYS_BASEBOARD_SERIAL:-}")" \
        "$(report::_json_field "${SYS_TPM:-}")"
    printf ',"macs":"%s","storage_controllers":"%s","tool_version":"%s"' \
        "$(report::_json_field "${SYS_MAC_LIST:-}")" \
        "$(report::_json_field "${SYS_STORAGE_CTRLS:-}")" \
        "$(report::_json_field "${SCRIPT_VERSION:-}")"
    printf ',"battery":"%s","secure_boot":"%s","dimms":"%s"' \
        "$(report::_json_field "${SYS_BATTERY:-}")" \
        "$(report::_json_field "${SYS_SECUREBOOT:-}")" \
        "$(report::_json_field "${SYS_DIMM_LIST:-}")"
    printf ',"selftest_cpu":"%s"' \
        "$(report::_json_field "${SELFTEST_CPU:-}")"
    printf ',"operator":"%s","validator":"%s","media_source":"%s","media_destination":"%s"' \
        "$(report::_json_field "${OPERATOR_NAME:-}")" \
        "$(report::_json_field "${VALIDATOR_NAME:-}")" \
        "$(report::_json_field "${MEDIA_SOURCE:-}")" \
        "$(report::_json_field "${MEDIA_DESTINATION:-}")"
    printf ',"drives":['
    for dev in "${devices[@]}"; do
        [[ "$first" -eq 1 ]] && first=0 || printf ','
        printf '{"device":"%s","model":"%s","serial":"%s","size":"%s","bus":"%s","type":"%s","capability":"%s","class":"%s","firmware":"%s","sector_size":"%s","sectors":"%s","smart":"%s","selftest":"%s","realloc":"%s","selftest_run":"%s","opal_locked":%s}' \
            "$(report::_json_field "${devrow[$dev.device]:-$dev}")" \
            "$(report::_json_field "${devrow[$dev.model]:-}")" \
            "$(report::_json_field "${devrow[$dev.serial]:-}")" \
            "$(report::_json_field "${devrow[$dev.size]:-}")" \
            "$(report::_json_field "${devrow[$dev.bus]:-}")" \
            "$(report::_json_field "${devrow[$dev.type]:-}")" \
            "$(report::_json_field "${devrow[$dev.capability]:-}")" \
            "$(report::_json_field "${devrow[$dev.class]:-}")" \
            "$(report::_json_field "${firmware[$dev]:-}")" \
            "$(report::_json_field "${secsize[$dev]:-}")" \
            "$(report::_json_field "${sectors[$dev]:-}")" \
            "$(report::_json_field "${devrow[$dev.smart]:-}")" \
            "$(report::_json_field "${devrow[$dev.selftest]:-}")" \
            "$(report::_json_field "${devrow[$dev.realloc]:-}")" \
            "$(report::_json_field "${devrow[$dev.selftest_run]:-}")" \
            "$([[ "${opal_locked[$dev]:-}" == "YES" ]] && printf 'true' || printf 'false')"
    done
    printf ']}\n'
}

# Wrap the body with a per-run report identity: a UUID + a SHA-256 over the
# body (audit fields excluded, so the hash is deterministic). REPORT_ID is
# generated once and reused, so the portal POST and the USB snapshot carry the
# same id + hash. Pure string builder — unit-testable.
register::json() {
    local body inner sha id
    [[ -n "${REPORT_ID:-}" ]] || {
        id="$(cat /proc/sys/kernel/random/uuid 2>/dev/null || true)"
        if [[ -z "$id" ]] && command -v uuidgen >/dev/null 2>&1; then
            id="$(uuidgen 2>/dev/null || true)"
        fi
        REPORT_ID="${id:-n/a}"
    }
    body="$(register::json_body)"
    inner="${body#\{}"
    inner="${inner%\}}"
    sha="$(printf '%s' "$body" | sha256sum 2>/dev/null | awk '{print $1}')"
    [[ -n "$sha" ]] || sha="$(printf '%s' "$body" | openssl dgst -sha256 2>/dev/null | awk '{print $NF}')"
    printf '{"report_id":"%s","digital_identifier":"%s",%s}\n' "$REPORT_ID" "$sha" "$inner"
}

# POST the snapshot to the portal (best-effort — a failed push never fails the
# run). The portal marks the device online and shows it on the Devices tab even
# before anything has been wiped.
register::send() {
    local body resp attempt
    [[ -n "${TSCRUB_API_TOKEN:-}" ]] || return 0
    command -v curl >/dev/null 2>&1 || return 0

    body="$(register::json)"

    # One-shot at boot — but the boot-time DHCP may not have completed yet
    # (USB Ethernet adapters on laptops without a built-in NIC can bring their
    # link up several seconds after boot). Retry a few times like
    # license::detect; each retry re-runs network::ensure (a no-op once a
    # default route exists).
    for attempt in 1 2 3; do
        if command -v ip >/dev/null 2>&1; then
            network::ensure
        fi
        if resp="$(curl -fsS --connect-timeout 5 --max-time 15 \
            -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
            -H "Content-Type: application/json" \
            --data-binary "$body" "$(register::endpoint)" 2>&1)"; then
            return 0
        fi
        # Dead RTC clock skew breaks TLS verification (curl error 60); retry
        # once without verification, mirroring report::upload_http.
        if [[ "$resp" == *"curl: (60)"* ]]; then
            if curl -kfsS --connect-timeout 5 --max-time 15 \
                -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
                -H "Content-Type: application/json" \
                --data-binary "$body" "$(register::endpoint)" >/dev/null 2>&1; then
                return 0
            fi
        fi
        [[ "$attempt" -lt 3 ]] && sleep 5
    done
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
