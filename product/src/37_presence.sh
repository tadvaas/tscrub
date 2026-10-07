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
    local url body resp phase _total _done _failed _pct _eta
    [[ -n "${TSCRUB_API_TOKEN:-}" ]] || return 0
    phase="$(status::field phase)"
    _total="$(status::field drives_total)";   [[ "$_total"  =~ ^[0-9]+$ ]] || _total=0
    _done="$(status::field drives_done)";     [[ "$_done"   =~ ^[0-9]+$ ]] || _done=0
    _failed="$(status::field drives_failed)"; [[ "$_failed" =~ ^[0-9]+$ ]] || _failed=0
    _pct="$(status::progress_field progress_pct)";       [[ "$_pct" =~ ^-?[0-9]+$ ]] || _pct=-1
    _eta="$(status::progress_field progress_eta_sec)";   [[ "$_eta" =~ ^-?[0-9]+$ ]] || _eta=-1
    # Progress is only meaningful while a wipe is actually running — never let a
    # stale value linger after the phase flips to done/failed.
    if [[ "$phase" != "wiping" ]]; then _pct=-1; _eta=-1; fi
    body="$(printf '{"serial":"%s","uuid":"%s","ip":"%s","phase":"%s","drives_total":%s,"drives_done":%s,"drives_failed":%s,"progress_pct":%s,"progress_eta_sec":%s}' \
        "$(report::_json_field "${SYS_SERIAL:-}")" \
        "$(report::_json_field "${SYS_UUID:-}")" \
        "$(report::_json_field "$(network::lan_ip)")" \
        "$(report::_json_field "$phase")" \
        "$_total" "$_done" "$_failed" "$_pct" "$_eta")"
    url="$(presence::endpoint)"
    if ! resp="$(curl -fsS --connect-timeout 5 --max-time 10 \
        -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
        -H "Content-Type: application/json" \
        --data-binary "$body" "$url" 2>&1)"; then
        # A dead RTC battery leaves the system clock wrong, so TLS certificate
        # verification fails (curl error 60) on an otherwise healthy server.
        # Retry once without verification, mirroring report::upload_http.
        if [[ "$resp" == *"curl: (60)"* ]]; then
            curl -kfsS --connect-timeout 5 --max-time 10 \
                -H "X-Api-Token: ${TSCRUB_API_TOKEN}" \
                -H "Content-Type: application/json" \
                --data-binary "$body" "$url" >/dev/null 2>&1
        fi
    fi
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

# =============================================================================
# ERASURE STATUS — a tiny state file the heartbeat reads so the dashboard can
# show a live "Wiping / Complete / Failed" indicator. The wipe workers are
# subshells that report over the fd-3 IPC pipe; ui::loop (the parent) is the
# single consumer and updates this file. presence::ping includes it in every
# heartbeat, and status::ping_now sends an immediate ping on the important
# transitions (start, each drive terminal, finish) — the 30s loop remains the
# reconciliation safety net if an event ping is lost.
# =============================================================================

STATUS_STATE_FILE="/tmp/tscrub-erasure-state"
STATUS_PROGRESS_FILE="/tmp/tscrub-erasure-progress"
STATUS_LAST_PING_TS=""

status::field() {  # <key> -> value (empty when the file/key is absent)
    sed -n "s/^$1=//p" "$STATUS_STATE_FILE" 2>/dev/null | head -1
}

status::progress_field() {  # <key> -> value from the live-progress file
    sed -n "s/^$1=//p" "$STATUS_PROGRESS_FILE" 2>/dev/null | head -1
}

status::write() {  # <phase> <total> <done> <failed>
    {
        printf 'phase=%s\n' "$1"
        printf 'drives_total=%s\n' "$2"
        printf 'drives_done=%s\n' "$3"
        printf 'drives_failed=%s\n' "$4"
    } > "$STATUS_STATE_FILE" 2>/dev/null || true
}

status::clear() {
    rm -f "$STATUS_STATE_FILE" "$STATUS_PROGRESS_FILE" 2>/dev/null || true
    STATUS_LAST_PING_TS=""
    STATUS_PROGRESS_LAST_PCT=""
    STATUS_PROGRESS_LAST_TS=""
}

# Persist the live aggregate wipe progress for the heartbeat and fire an
# immediate ping when it moved meaningfully (≥5% or ≥15s since the last one).
# The 30s presence loop remains the reconciliation safety net.
status::progress_write() {  # <pct> <eta_sec>
    local now
    {
        printf 'progress_pct=%s\n' "$1"
        printf 'progress_eta_sec=%s\n' "$2"
    } > "$STATUS_PROGRESS_FILE" 2>/dev/null || true

    now="$(ts::now 2>/dev/null || true)"
    [[ "$now" =~ ^[0-9]+$ ]] || now=0
    local last_pct="${STATUS_PROGRESS_LAST_PCT:--2}" last_ts="${STATUS_PROGRESS_LAST_TS:-0}" delta
    delta=$(( $1 - last_pct )); [[ $delta -lt 0 ]] && delta=$(( -delta ))
    if [[ $1 -eq -1 || $delta -ge 5 || $(( now - last_ts )) -ge 15 ]]; then
        STATUS_PROGRESS_LAST_PCT=$1
        STATUS_PROGRESS_LAST_TS=$now
        status::ping_now
    fi
}

# Fire-and-forget immediate heartbeat (debounced ~2s so a burst of terminal
# lines does not spawn a pile of curls). fds 3/4 are closed so the background
# ping can never hold the UI IPC pipe open (ui::loop must EOF when workers end).
status::ping_now() {
    local now
    now="$(ts::now 2>/dev/null || true)"
    [[ "$now" =~ ^[0-9]+$ ]] || now=0
    if [[ -z "$STATUS_LAST_PING_TS" || $now -ge $((STATUS_LAST_PING_TS + 2)) ]]; then
        STATUS_LAST_PING_TS=$now
        { presence::ping; } 3>&- 4<&- >/dev/null 2>&1 &
    fi
}

# A real wipe begins: N selected drives, none finished yet.
status::erase_start() {
    local dev n=0
    for dev in "${devices[@]}"; do
        [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] && n=$((n+1))
    done
    status::write wiping "$n" 0 0
    status::ping_now
}

# Recompute counts from devrow and flip the phase when every drive is terminal.
# Called by ui::loop on each terminal STATUS line and by erasure::run at the end
# (to catch a worker that died without reporting, which erasure::run marks
# UNKNOWN).
status::drive_terminal() {
    local dev total=0 dcount=0 fcount=0 finished=1 phase=wiping
    for dev in "${devices[@]}"; do
        [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] || continue
        total=$((total+1))
        case "${devrow[$dev.status]:-}" in
            COMPLETED|DRY-RUN|SKIPPED) dcount=$((dcount+1)) ;;
            FAILED|BLOCKED|FROZEN|UNKNOWN) fcount=$((fcount+1)) ;;
            *) finished=0 ;;   # still RUNNING/PLANNED
        esac
    done
    # UNKNOWN is the "worker died without reporting" marker — terminal for the
    # dashboard (shown as Failed), even though ui::all_drives_terminal
    # deliberately excludes it so an incomplete run never produces a report.
    if [[ $finished -eq 1 ]]; then
        if [[ $fcount -gt 0 ]]; then phase=failed; else phase=done; fi
    fi
    status::write "$phase" "$total" "$dcount" "$fcount"
    status::ping_now
}

# Aggregate live wipe progress across the selected drives for the heartbeat.
#   progress_pct     = (finished + Σ running_pct/100) / total * 100, clamped to
#                      0..99 while still wiping; -1 when any running drive is
#                      indeterminate (firmware erase with no % source).
#   progress_eta_sec = slowest running drive's estimate (nwipe eta > ATA
#                      timing-word > NVMe %-extrapolation), or -1 when unknown.
status::recompute_progress() {
    local dev total=0 finished=0 pct_sum=0 indeterminate=0 running=0 eta_max=-1
    local st pct eta elapsed start

    for dev in "${devices[@]}"; do
        [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] || continue
        total=$((total+1))
        st="${devrow[$dev.status]:-}"
        pct=""
        case "$st" in
            COMPLETED|DRY-RUN|SKIPPED|FAILED|BLOCKED|FROZEN|UNKNOWN)
                finished=$((finished+1)) ;;
            *)
                running=$((running+1))
                if [[ "$st" =~ ^([0-9]+)%$ ]]; then
                    pct=${BASH_REMATCH[1]}
                    pct_sum=$((pct_sum + pct))
                else
                    indeterminate=$((indeterminate+1))
                fi

                # Best estimate for THIS running drive.
                eta=-1
                if [[ "${devrow[$dev.progress_eta_sec]:-}" =~ ^[0-9]+$ ]]; then
                    eta=${devrow[$dev.progress_eta_sec]}          # nwipe eta (exact)
                elif [[ "${devrow[$dev.eta_mins]:-}" =~ ^[0-9]+$ && "${devrow[$dev.start_ts]:-}" =~ ^[0-9]+$ ]]; then
                    start=${devrow[$dev.start_ts]}
                    elapsed=$(( $(ts::now 2>/dev/null || echo "$start") - start ))
                    [[ $elapsed -lt 0 ]] && elapsed=0
                    eta=$(( ${devrow[$dev.eta_mins]} * 60 - elapsed ))   # ATA word 89
                    [[ $eta -lt 0 ]] && eta=0
                elif [[ "$pct" =~ ^[0-9]+$ && "$pct" -gt 0 && "$pct" -lt 100 && "${devrow[$dev.start_ts]:-}" =~ ^[0-9]+$ ]]; then
                    start=${devrow[$dev.start_ts]}
                    elapsed=$(( $(ts::now 2>/dev/null || echo "$start") - start ))
                    [[ $elapsed -lt 0 ]] && elapsed=0
                    eta=$(( elapsed * (100 - pct) / pct ))   # NVMe extrapolation
                fi
                if [[ $eta -ge 0 ]]; then
                    # Per-drive countdown for the console ETA cell (NVMe/nwipe
                    # have no ATA word-89 eta_mins; ui::eta_text_for falls back
                    # to this). Cleared when the drive has no estimate yet so a
                    # stale value can never linger.
                    devrow["$dev.eta_sec"]="$eta"
                    [[ $eta -gt $eta_max ]] && eta_max=$eta
                else
                    devrow["$dev.eta_sec"]=""
                fi
                ;;
        esac
    done

    local out_pct=-1
    if [[ $total -gt 0 && $running -gt 0 && $indeterminate -eq 0 ]]; then
        out_pct=$(( (finished * 100 + pct_sum) / total ))
        [[ $out_pct -gt 99 ]] && out_pct=99   # never report 100 while still wiping
    fi
    status::progress_write "$out_pct" "$eta_max"
}
