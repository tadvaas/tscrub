# =============================================================================
# PRESENCE (heartbeat) — tell the dashboard this machine is online right now
# =============================================================================

# How often the appliance pings the dashboard while it is booted (seconds).
# The dashboard marks a device "offline" when no heartbeat is seen for
# roughly 3× this interval (90s).
PRESENCE_PING_SECONDS=30

# The heartbeat endpoint, derived from the report upload URL the same way as
# the MDM endpoints so a custom `tscrub_upload=` host is honoured.
presence::endpoint() {
    local url="${TSCRUB_UPLOAD_URL:-https://tscrub.com/api/reports}"
    url="${url%/}"
    [[ "$url" == */api/reports ]] && url="${url%/api/reports}"
    printf '%s/api/heartbeat' "$url"
}

# Send one heartbeat (best-effort — a failed ping never fails the run).
presence::ping() {
    local url body
    [[ -n "${TSCRUB_API_TOKEN:-}" ]] || return 0
    body="$(printf '{"serial":"%s","uuid":"%s"}' \
        "$(report::_json_field "${SYS_SERIAL:-}")" \
        "$(report::_json_field "${SYS_UUID:-}")")"
    url="$(presence::endpoint)"
    curl -fsS --connect-timeout 5 --max-time 10 \
        -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
        -H "Content-Type: application/json" \
        --data-binary "$body" "$url" >/dev/null 2>&1
    return 0
}

# Background loop: ping every PRESENCE_PING_SECONDS for the life of the run.
# Forked by fn_main and killed when the run finishes.
presence::loop() {
    [[ -n "${TSCRUB_API_TOKEN:-}" ]] || return 0
    while :; do
        presence::ping
        sleep "$PRESENCE_PING_SECONDS"
    done
}
