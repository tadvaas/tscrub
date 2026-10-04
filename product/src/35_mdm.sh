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
        --retry 2 --retry-delay 2 --retry-connrefused \
        -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
        --data-urlencode "serial=${SYS_SERIAL:-}" \
        --data-urlencode "uuid=${SYS_UUID:-}" \
        "$url" 2>&1)"
    rc=$?
    if [[ $rc -ne 0 ]] && [[ "$resp" == *"curl: (60)"* ]]; then
        resp="$(curl -k -fsS --get --connect-timeout 10 --max-time 20 \
            --retry 2 --retry-delay 2 --retry-connrefused \
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
    # Best-effort publish to the UI IPC channel (fd 3) when it is open. The
    # result file above is the single source of truth — the worker is forked
    # DETACHED from the erasure IPC, so fd 3 is normally closed here.
    #
    # Each write is wrapped in a group whose stderr is redirected, because a
    # bare `... >&3 2>/dev/null` does NOT silence the redirection failure:
    # bash reports the bad-fd error on the ORIGINAL stderr before the
    # `2>/dev/null` is applied (left-to-right redirection order), so a closed
    # fd 3 printed "Bad file descriptor" on the console every publish.
    { echo "mdm VERDICT $verdict" >&3; } 2>/dev/null || true
    { echo "mdm STATUS $label" >&3; } 2>/dev/null || true
}

# Pull the worker's latest published state back into the parent shell. The
# worker is a subshell forked DETACHED (no fd-3 IPC), so its publishes travel
# ONLY through the result file; the triage + wipe screens call this on every
# tick to keep the MDM cell live. Always overwrite from the file so a settled
# verdict (Queued → Locked/Unlocked/…) keeps advancing.
mdm::sync_state() {
    [[ -f "$MDM_RESULT_FILE" ]] || return 0
    local verdict label
    verdict="$(sed -n '1p' "$MDM_RESULT_FILE" 2>/dev/null)"
    label="$(sed -n '2p' "$MDM_RESULT_FILE" 2>/dev/null)"
    [[ -n "$verdict" ]] && MDM_VERDICT="$verdict"
    [[ -n "$label" ]] && MDM_STATUS="$label"
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
# POLLS GET /api/mdm/status for the WHOLE session (unbounded) so the Runtime
# panel always tracks the dashboard's exact live `label` — and keeps tracking
# it even after a verdict, so a later Re-check updates the panel too.
mdm::detect() {
    local url body resp rc verdict status label man prod status_url poll=0 consecutive=0
    local boot_attempt post_attempt

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

    # This worker is forked BEFORE device discovery, so its first attempt races
    # a USB NIC / DHCP that is still coming up. Retry network::ensure (each call
    # is a no-op once a default route exists). On total failure publish Offline
    # but DO NOT exit — the poll loop below retries every tick and recovers when
    # the link comes back.
    boot_attempt=0
    while ! network::ensure; do
        boot_attempt=$((boot_attempt + 1))
        if [[ $boot_attempt -ge 6 ]]; then
            mdm::publish "offline" "Offline"
            break
        fi
        sleep 10
    done

    if [[ $boot_attempt -lt 6 ]]; then
        man="${SYS_MANUFACTURER:-N/A}"; [[ "$man" == "N/A" ]] && man=""
        prod="${SYS_PRODUCT:-N/A}";   [[ "$prod" == "N/A" ]] && prod=""

        body="$(printf '{"serial":"%s","uuid":"%s","manufacturer":"%s","product":"%s"}' \
            "$(report::_json_field "${SYS_SERIAL}")" \
            "$(report::_json_field "${SYS_UUID}")" \
            "$(report::_json_field "$man")" \
            "$(report::_json_field "$prod")")"
        url="$(mdm::endpoint)"

        # POST /api/mdm/autopilot enqueues the check (idempotent) and returns the
        # first status. The dashboard owns the wording via `label`; the Runtime
        # panel renders it verbatim. Bounded retry (mdm::http_post also retries
        # 2x internally); on failure publish Offline and let the poll loop recover.
        post_attempt=0
        rc=1
        while :; do
            resp="$(mdm::http_post "$url" "$body")"
            rc=$?
            [[ $rc -eq 0 ]] && break
            post_attempt=$((post_attempt + 1))
            if [[ $post_attempt -ge 3 ]]; then
                mdm::publish "offline" "Offline"
                break
            fi
            sleep 5
        done
        if [[ $rc -eq 0 ]]; then
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
        fi
    fi

    # Poll GET /api/mdm/status for the WHOLE session so the panel always tracks
    # the dashboard's live state (--- → Queued → Checking… → Locked/Unlocked/…,
    # back to Offline on a dropped link, recovering when it returns). Unbounded
    # in production (the worker is killed with the session); a terminal verdict
    # does NOT stop the poll — a later Re-check from the dashboard updates the
    # panel too. MDM_POLL_MAX caps the loop for the test harness only.
    status_url="$(mdm::status_endpoint)"
    consecutive=0
    poll=0
    while :; do
        sleep "$MDM_POLL_SECONDS"
        poll=$((poll + 1))
        if ! resp="$(mdm::http_get "$status_url")"; then
            # A single failed GET is usually transient (a blip or TLS timeout) —
            # tolerate a few before declaring the server unreachable, so a one-off
            # failure doesn't freeze the panel while the check resolves.
            consecutive=$((consecutive + 1))
            [[ $consecutive -ge 3 ]] && mdm::publish "offline" "Offline"
        else
            consecutive=0
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
        fi
        [[ -n "${MDM_POLL_MAX:-}" && $poll -ge "$MDM_POLL_MAX" ]] && break
    done
}
