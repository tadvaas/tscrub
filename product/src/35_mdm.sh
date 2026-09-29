# =============================================================================
# MDM (Windows Autopilot enrolment) check
# =============================================================================

MDM_STATUS=""     # CHECKING / UNLOCKED / LOCKED / OFFLINE / SKIPPED / NA
MDM_VERDICT=""    # unlocked / locked_this / locked_other / offline / skipped / na / checking
MDM_RESULT_FILE="/tmp/tscrub-mdm.verdict"

mdm::is_configured() {
    # The Autopilot check is opt-in: it runs only when the operator explicitly
    # enabled it — `tscrub_autopilotcheck=true` in tscrub.conf / the kernel
    # cmdline, or the CLI flag `--autopilotcheck` — AND a dashboard API token
    # is present (the dashboard URL has a built-in default).
    [[ "${TSCRUB_AUTOPILOTCHECK:-0}" == "1" && -n "${TSCRUB_API_TOKEN:-}" ]]
}

# Pull tscrub_autopilotcheck= from the kernel command line. tscrub.conf and
# --autopilotcheck already populated TSCRUB_AUTOPILOTCHECK before the worker
# forked; the cmdline is read here so the kernel-param source works too. Also
# runs report::parse_upload — the worker forks BEFORE fn_main does, so a token /
# upload URL supplied on the cmdline wouldn't otherwise reach it.
mdm::parse_cmdline() {
    # No /proc/cmdline on non-Linux (tests, bare environments) — nothing to read.
    [[ -r /proc/cmdline ]] || return 0

    report::parse_upload 2>/dev/null || true

    local param
    param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_autopilotcheck=//p' | head -n 1)"
    case "$param" in
        true|1|yes|on) TSCRUB_AUTOPILOTCHECK=1 ;;
    esac
}

# The dashboard's MDM endpoint, derived from the report upload URL so a custom
# `tscrub_upload=` host is honoured automatically.
mdm::endpoint() {
    local url="${TSCRUB_UPLOAD_URL:-https://tscrub.com/api/reports}"
    url="${url%/}"
    [[ "$url" == */api/reports ]] && url="${url%/api/reports}"
    printf '%s/api/mdm/autopilot' "$url"
}

# Map the dashboard's machine-readable verdict to a UI status word.
mdm::state_for_verdict() {
    case "${1:-}" in
        locked_this|locked_other) printf 'LOCKED' ;;
        unlocked)                 printf 'UNLOCKED' ;;
        skipped)                  printf 'SKIPPED' ;;
        unknown)                  printf 'UNKNOWN' ;;   # import still queued when the poll window closed
        checking)                 printf 'CHECKING' ;;  # server has it queued/checking — verdict pending
        na)                       printf 'NA' ;;        # no staged hash — the WinPE capture step was skipped
        *)                        printf 'OFFLINE' ;;
    esac
}

# Extract a JSON string field from stdin (single-line JSON from the dashboard).
mdm::json_field() {
    local field="$1"
    sed -n "s/.*\"${field}\":\"\([^\"]*\)\".*/\1/p" | head -n 1
}

# POST the probe payload; echoes the raw response body on success, with the
# same stale-RTC TLS-retry fallback as report::upload.
mdm::http_post() {
    local url="$1" body="$2" resp rc
    resp="$(curl -fsS --connect-timeout 10 --max-time 30 \
        --retry 2 --retry-delay 2 --retry-connrefused \
        -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
        -H "Content-Type: application/json" \
        --data-binary "$body" \
        "$url" 2>&1)"
    rc=$?
    if [[ $rc -ne 0 ]] && [[ "$resp" == *"curl: (60)"* ]]; then
        resp="$(curl -k -fsS --connect-timeout 10 --max-time 30 \
            --retry 2 --retry-delay 2 --retry-connrefused \
            -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
            -H "Content-Type: application/json" \
            --data-binary "$body" \
            "$url" 2>&1)"
        rc=$?
    fi
    printf '%s' "$resp"
    return $rc
}

# Background worker: send the machine identifiers to the dashboard (which holds
# the Azure credentials and runs the Graph probe) and publish the verdict to the
# UI over the worker -> UI IPC channel (fd 3). Forked BEFORE `exec 3>&-` so its
# late status write still reaches the UI reader. The verdict is also written to
# MDM_RESULT_FILE so fn_main can recover it even if the UI loop ended first
# (instant dry-run).
mdm::detect() {
    local url body resp rc verdict status state man prod

    mdm::parse_cmdline

    if ! mdm::is_configured; then
        verdict="skipped"
    elif [[ "${SYS_SERIAL:-N/A}" == "N/A" || "${SYS_UUID:-N/A}" == "N/A" ]]; then
        verdict="skipped"
    elif ! network::ensure; then
        # No IPv4 route (and DHCP couldn't obtain one) — skip the probe rather
        # than burning curl retries against an unreachable dashboard.
        verdict="offline"
    else
        man="${SYS_MANUFACTURER:-N/A}"; [[ "$man" == "N/A" ]] && man=""
        prod="${SYS_PRODUCT:-N/A}";   [[ "$prod" == "N/A" ]] && prod=""

        body="$(printf '{"serial":"%s","uuid":"%s","manufacturer":"%s","product":"%s"}' \
            "$(report::_json_field "${SYS_SERIAL}")" \
            "$(report::_json_field "${SYS_UUID}")" \
            "$(report::_json_field "$man")" \
            "$(report::_json_field "$prod")")"
        url="$(mdm::endpoint)"

        # The Graph probe runs in the background on the server (mdm-worker.php),
        # so POST /api/mdm/autopilot returns immediately with
        # {status: queued|checking|done|na, verdict}. Display whatever the server
        # reports right now — the authoritative verdict is picked up later from
        # the dashboard's MDM tab (the device is registered server-side).
        resp="$(mdm::http_post "$url" "$body")"
        rc=$?
        if [[ $rc -ne 0 ]]; then
            verdict="offline"
        else
            verdict="$(printf '%s' "$resp" | mdm::json_field verdict)"
            status="$(printf '%s' "$resp" | mdm::json_field status)"
            if [[ -z "$verdict" ]]; then
                case "$status" in
                    na)              verdict="na" ;;
                    queued|checking) verdict="checking" ;;
                    *)               verdict="unknown" ;;
                esac
            fi
        fi
    fi

    # Persist the verdict so the report can read it even if the UI loop ended
    # before this worker finished (the worker is a subshell — it cannot set the
    # parent's variables directly).
    printf '%s' "$verdict" > "$MDM_RESULT_FILE" 2>/dev/null

    state="$(mdm::state_for_verdict "$verdict")"
    # VERDICT first so it sits on the IPC channel before the terminal STATUS
    # that lets ui::loop consider the check done.
    echo "mdm VERDICT $verdict" >&3
    echo "mdm STATUS $state" >&3
    return 0
}
