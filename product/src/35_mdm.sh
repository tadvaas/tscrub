# =============================================================================
# MDM (Windows Autopilot enrolment) check
# =============================================================================

MDM_STATUS=""     # exact display text from the dashboard (rendered verbatim in the Runtime panel)
MDM_VERDICT=""    # machine-readable verdict for the report CSV (unlocked / locked_this / locked_other / offline / skipped / na / checking)
MDM_RESULT_FILE="/tmp/tscrub-mdm.verdict"
# How long the worker polls GET /api/mdm/status for the authoritative verdict
# once the check is queued server-side (the Graph probe resolves in the
# background via mdm-worker.php, cron every minute). ~5 minutes covers one cron
# cycle plus a healthy probe, so the panel resolves during a real wipe; a fast
# wipe/dry-run is released by fn_main's bounded wait instead of blocking here.
MDM_POLL_SECONDS=10
MDM_POLL_MAX=30

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

# The dashboard's MDM status endpoint (GET) — polled for the authoritative
# verdict once a check is queued. Serial/uuid are passed as query params.
mdm::status_endpoint() {
    local url="${TSCRUB_UPLOAD_URL:-https://tscrub.com/api/reports}"
    url="${url%/}"
    [[ "$url" == */api/reports ]] && url="${url%/api/reports}"
    printf '%s/api/mdm/status' "$url"
}

# GET the current check status; echoes the raw response body on success, with
# the same stale-RTC TLS-retry fallback as mdm::http_post.
mdm::http_get() {
    local url="$1" resp rc
    resp="$(curl -fsS --get --connect-timeout 10 --max-time 20 \
        -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
        --data-urlencode "serial=${SYS_SERIAL:-}" \
        --data-urlencode "uuid=${SYS_UUID:-}" \
        "$url" 2>&1)"
    rc=$?
    if [[ $rc -ne 0 ]] && [[ "$resp" == *"curl: (60)"* ]]; then
        resp="$(curl -k -fsS --get --connect-timeout 10 --max-time 20 \
            -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
            --data-urlencode "serial=${SYS_SERIAL:-}" \
            --data-urlencode "uuid=${SYS_UUID:-}" \
            "$url" 2>&1)"
        rc=$?
    fi
    printf '%s' "$resp"
    return $rc
}

# Map the dashboard's machine-readable verdict to a UI status word.
# (Removed — the dashboard now supplies the exact display text in `label` and
# the Runtime panel renders it verbatim, so wording is owned server-side.)

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

# Publish the current verdict + label to both the UI IPC channel (fd 3) and the
# result file fn_main reads as a fallback (the worker is a subshell, so it
# cannot set the parent's variables directly). VERDICT goes first so it sits on
# the channel before the terminal STATUS that lets ui::loop consider it done.
mdm::publish() {
    local verdict="$1" label="$2"
    printf '%s\n%s' "$verdict" "$label" > "$MDM_RESULT_FILE" 2>/dev/null
    echo "mdm VERDICT $verdict" >&3
    echo "mdm STATUS $label" >&3
}

# Background worker: send the machine identifiers to the dashboard (which holds
# the Azure credentials and runs the Graph probe) and publish the verdict to the
# UI over the worker -> UI IPC channel (fd 3). Forked BEFORE `exec 3>&-` so its
# late status write still reaches the UI reader. The verdict is also written to
# MDM_RESULT_FILE so fn_main can recover it even if the UI loop ended first
# (instant dry-run).
#
# The Graph probe resolves server-side in the background (mdm-worker.php, cron
# every minute), so the initial POST returns queued/checking. The worker then
# POLLS GET /api/mdm/status (bounded) so the Runtime panel resolves to the
# dashboard's exact `label` instead of sticking at "Queued"/"Checking…".
mdm::detect() {
    local url body resp rc verdict status label man prod status_url poll=0

    mdm::parse_cmdline

    if ! mdm::is_configured; then
        mdm::publish "skipped" "Skipped"
        return 0
    fi
    if [[ "${SYS_SERIAL:-N/A}" == "N/A" || "${SYS_UUID:-N/A}" == "N/A" ]]; then
        mdm::publish "skipped" "Skipped"
        return 0
    fi

    # Publish an honest "Queued" placeholder BEFORE the network/POST work so the
    # panel never lingers on the initial "Pending" while DHCP and the POST settle.
    mdm::publish "checking" "Queued"

    if ! network::ensure; then
        # No IPv4 route (and DHCP couldn't obtain one) — skip the probe rather
        # than burning curl retries against an unreachable dashboard.
        mdm::publish "offline" "Offline"
        return 0
    fi

    man="${SYS_MANUFACTURER:-N/A}"; [[ "$man" == "N/A" ]] && man=""
    prod="${SYS_PRODUCT:-N/A}";   [[ "$prod" == "N/A" ]] && prod=""

    body="$(printf '{"serial":"%s","uuid":"%s","manufacturer":"%s","product":"%s"}' \
        "$(report::_json_field "${SYS_SERIAL}")" \
        "$(report::_json_field "${SYS_UUID}")" \
        "$(report::_json_field "$man")" \
        "$(report::_json_field "$prod")")"
    url="$(mdm::endpoint)"

    # POST /api/mdm/autopilot enqueues the check and returns immediately with
    # {status: queued|checking|done|na, verdict, label}. The dashboard owns the
    # wording via `label`; the Runtime panel renders it verbatim.
    resp="$(mdm::http_post "$url" "$body")"
    rc=$?
    if [[ $rc -ne 0 ]]; then
        mdm::publish "offline" "Offline"
        return 0
    fi

    verdict="$(printf '%s' "$resp" | mdm::json_field verdict)"
    status="$(printf '%s' "$resp" | mdm::json_field status)"
    label="$(printf '%s' "$resp" | mdm::json_field label)"
    if [[ -z "$verdict" ]]; then
        case "$status" in
            na)              verdict="na" ;;
            queued|checking) verdict="checking" ;;
            *)               verdict="unknown" ;;
        esac
    fi
    # Defensive fallback only (older dashboard without `label`): show the
    # machine verdict verbatim rather than mapping it locally.
    [[ -z "$label" ]] && label="${verdict:-Unknown}"
    mdm::publish "$verdict" "$label"

    # Still pending → poll the status endpoint until the server settles on a
    # verdict (or we hit the bound). Each poll re-publishes so the panel tracks
    # the server's label as it changes (Queued → Checking… → Locked/Unlocked/…).
    if [[ "$verdict" == "checking" ]]; then
        status_url="$(mdm::status_endpoint)"
        while [[ $poll -lt "$MDM_POLL_MAX" ]]; do
            sleep "$MDM_POLL_SECONDS"
            poll=$((poll + 1))
            if ! resp="$(mdm::http_get "$status_url")"; then
                # The server became unreachable mid-check — stop polling.
                verdict="offline"
                label="Offline"
                mdm::publish "$verdict" "$label"
                break
            fi
            status="$(printf '%s' "$resp" | mdm::json_field status)"
            verdict="$(printf '%s' "$resp" | mdm::json_field verdict)"
            label="$(printf '%s' "$resp" | mdm::json_field label)"
            if [[ -z "$verdict" ]]; then
                case "$status" in
                    na)              verdict="na" ;;
                    queued|checking) verdict="checking" ;;
                    *)               verdict="unknown" ;;
                esac
            fi
            [[ -z "$label" ]] && label="${verdict:-Unknown}"
            mdm::publish "$verdict" "$label"
            # Terminal once the job leaves queued/checking (done/failed/na).
            case "$status" in
                queued|checking) : ;;
                *) break ;;
            esac
        done
        if [[ "$verdict" == "checking" ]]; then
            # Poll window exhausted with the job still pending — show N/A rather
            # than an indefinite "Checking…". The CSV keeps the honest "checking"
            # verdict; the panel shows the no-answer marker.
            label="N/A"
            mdm::publish "$verdict" "$label"
        fi
    fi
    return 0
}
