# =============================================================================
# REMOTE POWER — pull a shutdown/reboot command staged in the dashboard and
# power this machine off (or restart it). Same pull model as the remote
# BIOS-unlock feature: the appliance polls GET .../commands/pending, the
# dashboard stages a command, and the appliance executes it once it is safe.
# =============================================================================

# How often (seconds) the appliance checks for a staged power command.
REMOTE_POLL_SECONDS=10

# A wipe command is not executed by this worker — it is handed to the console
# (the triage loop) via a marker file, which reports the real outcome.
REMOTE_ERASE_MARKER="/tmp/tscrub-remote-erase"
# Seconds the console waits before a remote-initiated wipe (any key cancels).
REMOTE_ERASE_GRACE_SECONDS=5

remote::pending_endpoint() {
    local url="${TSCRUB_UPLOAD_URL:-https://tscrub.com/api/reports}"
    url="${url%/}"
    [[ "$url" == */api/reports ]] && url="${url%/api/reports}"
    printf '%s/api/devices/commands/pending' "$url"
}

remote::result_endpoint() {
    local url="${TSCRUB_UPLOAD_URL:-https://tscrub.com/api/reports}"
    url="${url%/}"
    [[ "$url" == */api/reports ]] && url="${url%/api/reports}"
    printf '%s/api/devices/commands/result' "$url"
}

# Report the outcome of a claimed command back to the dashboard (best-effort).
# Retries a few times so a single lost POST doesn't strand the job "dispatched"
# (the server requeues it after 10 min as a second safety net).
remote::report() {
    local cmd_id="$1" result="$2" detail="${3:-}" json_body attempt resp
    json_body="$(printf '{"id":%s,"result":"%s","detail":"%s"}' \
        "$cmd_id" "$result" "$(report::_json_field "$detail")")"
    for attempt in 1 2 3; do
        if resp="$(curl -fsS --connect-timeout 5 --max-time 15 \
            -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
            -H "Content-Type: application/json" \
            --data-binary "$json_body" \
            "$(remote::result_endpoint)" 2>&1)"; then
            return 0
        fi
        # A dead RTC battery leaves the system clock wrong, so TLS certificate
        # verification fails (curl error 60) on an otherwise healthy server.
        # Retry once without verification, mirroring presence/mdm/bios_unlock.
        if [[ "$resp" == *"curl: (60)"* ]]; then
            curl -k -fsS --connect-timeout 5 --max-time 15 \
                -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
                -H "Content-Type: application/json" \
                --data-binary "$json_body" \
                "$(remote::result_endpoint)" >/dev/null 2>&1 && return 0
        fi
        sleep 2
    done
    return 0
}

# GET the pending endpoint (with the TLS clock-skew retry). Prints the body;
# returns non-zero when no command could be fetched.
remote::_fetch_pending() {
    local resp
    resp="$(curl -fsS -G --connect-timeout 5 --max-time 15 \
        -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
        --data-urlencode "serial=${SYS_SERIAL:-}" \
        --data-urlencode "uuid=${SYS_UUID:-}" \
        "$(remote::pending_endpoint)" 2>&1)" && { printf '%s' "$resp"; return 0; }
    if [[ "$resp" == *"curl: (60)"* ]]; then
        curl -k -fsS -G --connect-timeout 5 --max-time 15 \
            -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
            --data-urlencode "serial=${SYS_SERIAL:-}" \
            --data-urlencode "uuid=${SYS_UUID:-}" \
            "$(remote::pending_endpoint)" 2>/dev/null
        return $?
    fi
    return 1
}

# Write a staged wipe to the marker file for the console to pick up. The worker
# does NOT report a result here — the console reports started/cancelled/failed.
remote::stage_erase() {
    local cmd_id="$1" resp="$2" dry_run drives_json s tmp
    tmp="${REMOTE_ERASE_MARKER}.$$"
    dry_run="$(printf '%s' "$resp" | sed -n 's/.*"dry_run":true.*/1/p')"
    [[ -n "$dry_run" ]] || dry_run=0

    # Write the COMPLETE marker to a temp file, then atomically rename it into
    # place. The triage loop polls the marker every 0.5 s; a half-written marker
    # (id/dry_run present but no drive lines) read as an empty drive list and
    # failed with "no drives matched".
    {
        printf 'id=%s\n' "$cmd_id"
        printf 'dry_run=%s\n' "$dry_run"
        if printf '%s' "$resp" | grep -q '"drives":"all"'; then
            printf 'scope=all\n'
        else
            printf 'scope=list\n'
            drives_json="$(printf '%s' "$resp" | sed -n 's/.*"drives":\[\(.*\)\].*/\1/p')"
            # `|| [[ -n "$s" ]]` processes a final serial with no trailing
            # newline (command substitution strips it), mirroring the triage loop.
            while IFS= read -r s || [[ -n "$s" ]]; do
                printf 'drive=%s\n' "$s"
            done < <(printf '%s' "$drives_json" | grep -o '"[^"]*"' | tr -d '"')
        fi
    } > "$tmp"
    mv -f "$tmp" "$REMOTE_ERASE_MARKER"
    return 0
}

# Consume the staged-erase marker (if any): print its KEY=VALUE lines and remove
# the file so a stale marker can never re-trigger a wipe. Outputs nothing when
# the marker is absent. Claims the marker with a rename first so a concurrent
# writer can never leave us reading a half-written file.
remote::consume_erase_marker() {
    [[ -f "$REMOTE_ERASE_MARKER" ]] || return 0
    mv -f "$REMOTE_ERASE_MARKER" "$REMOTE_ERASE_MARKER.claimed" 2>/dev/null || return 0
    cat "$REMOTE_ERASE_MARKER.claimed"
    rm -f "$REMOTE_ERASE_MARKER.claimed"
    return 0
}

# Short cancellable countdown before a remote-initiated wipe. Returns 0 to
# proceed, 1 if a key was pressed (cancel) or if there is no interactive console
# (no operator to warn — proceed straight through).
remote::grace_confirm() {
    local i key
    if ! ui::terminal_controls_supported; then
        return 0
    fi
    printf "\n%sRemote erase requested — starting in %ds… (press any key to cancel)\n" \
        "$TABLE_INDENT" "$REMOTE_ERASE_GRACE_SECONDS"
    for (( i = REMOTE_ERASE_GRACE_SECONDS; i > 0; i-- )); do
        IFS= read -rsn1 -t 1 key < /dev/tty 2>/dev/null && {
            printf "%sCancelled.\n" "$TABLE_INDENT"
            return 1
        }
    done
    return 0
}

# Pull a staged command (if any) and, when safe, execute it. Returns 0 always —
# a missing command or a failed poll must never fail the session.
remote::poll_and_execute() {
    local resp cmd_id command
    [[ -n "${TSCRUB_API_TOKEN:-}" ]] || return 0
    # Never act while a wipe is in progress — a power cut mid-sanitise can brick
    # a drive and loses the report. Leave the command staged; the server keeps
    # it pending and we pick it up once the machine is idle again.
    [[ "$(status::field phase)" == "wiping" ]] && return 0

    resp="$(remote::_fetch_pending)" || return 0
    cmd_id="$(printf '%s' "$resp" | sed -n 's/.*"id":\([0-9]*\).*/\1/p' | head -n 1)"
    command="$(printf '%s' "$resp" | sed -n 's/.*"command":"\([^"]*\)".*/\1/p' | head -n 1)"
    [[ -n "$cmd_id" ]] || return 0

    case "$command" in
        shutdown|reboot) : ;;
        wipe) remote::stage_erase "$cmd_id" "$resp"; return 0 ;;
        *) remote::report "$cmd_id" failed "unknown command: ${command}"; return 0 ;;
    esac

    # Re-check in case a wipe started between the claim above and now.
    if [[ "$(status::field phase)" == "wiping" ]]; then
        remote::report "$cmd_id" deferred "wipe in progress"
        return 0
    fi

    remote::report "$cmd_id" done ""
    sleep 1   # let the result POST flush before the power drops
    if [[ "$command" == "reboot" ]]; then
        reboot 2>/dev/null || reboot -f 2>/dev/null || true
    else
        poweroff 2>/dev/null || poweroff -f 2>/dev/null || true
    fi
    return 0
}

# Background loop: check for a staged command every few seconds for the life of
# the run. Forked by fn_main and killed when the run finishes.
remote::loop() {
    [[ -n "${TSCRUB_API_TOKEN:-}" ]] || return 0
    while :; do
        remote::poll_and_execute
        sleep "$REMOTE_POLL_SECONDS"
    done
}
