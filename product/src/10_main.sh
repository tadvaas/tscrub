# =============================================================================
# Entry Point
# =============================================================================

dryrun::simulate_running_eta() {
    local sim_secs elapsed dev

    sim_secs=$(( DRY_RUN_SIM_ETA_MINS * 60 ))

    for dev in "${devices[@]}"; do
        [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] || continue
        echo "$dev STATUS RUNNING" >&3
    done

    for ((elapsed=0; elapsed<sim_secs; elapsed++)); do
        sleep 1
    done

    for dev in "${devices[@]}"; do
        [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] || continue
        echo "$dev STATUS DRY-RUN" >&3
    done
}

# Kill the long-lived session workers (presence heartbeat, BIOS-unlock poll,
# MDM check) and the one-shot registration when the session ends. Best-effort:
# the pids may be empty (workers not yet forked) or already dead. A
# non-interactive shell does NOT kill `&` jobs when it exits — without this they
# would keep heartbeating as orphans while getty respawns a fresh tScrub.
session::teardown() {
    local p
    for p in "${presence_pid:-}" "${bios_unlock_pid:-}" "${remote_pid:-}" "${mdm_pid:-}" "${register_pid:-}"; do
        [[ -n "$p" ]] || continue
        kill "$p" 2>/dev/null || true
    done
    # Flush + unmount the report USB if it is still mounted. A pure-triage
    # session (Esc without erasing) never reaches report::sync_out, so without
    # this the boot-time diagnostics snapshot written to the stick would be
    # lost on power-off AND the mount would leak across getty respawns.
    report::sync_out
}

fn_main() {
    # Restore the cursor on exit; make Ctrl+C / SIGTERM actually abort (after
    # showing the cursor) instead of being silently swallowed. The EXIT trap
    # also tears down the long-lived session workers.
    trap 'ui::spinner_stop; ui::cursor_show; exit 130' INT
    trap 'ui::spinner_stop; ui::cursor_show; exit 143' TERM
    trap 'ui::sigwinch' WINCH
    trap 'ui::spinner_stop; ui::cursor_show; session::teardown' EXIT

    # Reset per-run state. (The worker -> UI IPC channel is opened per erasure
    # inside erasure::run, since one session can run several erasures.)
    REPORT_USB_STATUS=""
    REPORT_USB_REASON=""
    REPORT_DASH_STATUS=""
    REPORT_DASH_REASON=""
    REPORT_NET_STATUS=""
    REPORT_NET_REASON=""
    MDM_STATUS=""
    MDM_VERDICT=""
    FINISH_MSG=""
    BIOS_PASSWORD_STATUS=""
    BIOS_DETECTION_METHOD=""
    mdm_pid=""
    presence_pid=""
    bios_unlock_pid=""
    remote_pid=""
    REMOTE_ERASE=0
    REMOTE_ERASE_DRIVES=""
    rm -f "${REMOTE_ERASE_MARKER:-/tmp/tscrub-remote-erase}" 2>/dev/null || true
    register_pid=""
    devrow=()
    ui_eta_row=()
    pids=()
    rm -f "$MDM_RESULT_FILE"

    if [ -t 1 ]; then
        # Ensure each run starts from default terminal colors.
        printf "\033[0m"
        clear
    fi
    UI_COMPLETE_THEME=0
    if [[ -t 1 ]] && [[ -n "${TERM:-}" ]] && [[ "${TERM:-}" != "dumb" ]]; then
        UI_INPLACE=1
    fi

    ui::cursor_hide

    if [[ "$DRY_RUN" -eq 1 ]]; then
        printf "%s*** DRY RUN MODE — NO WIPE WILL BE EXECUTED ***\n\n" "$TABLE_INDENT"
    fi

    system::gather_info
    # BIOS lock check: a synchronous local read (sysfs + dmidecode) that
    # completes in <1s, so it runs inline before the first render — no worker.
    bios::detect

    # Apply any on-stick tscrub.conf (dashboard upload, COCID, licence URL)
    # first, so the licence/COCID resolution below sees it.
    config::load_usb
    # Also parse the kernel command line NOW (rather than at report-upload time)
    # so a PXE-supplied tscrub_api_token= / tscrub_upload= is available to the
    # boot-time device registration and presence heartbeat, both of which run
    # long before the final report upload. Cmdline wins over tscrub.conf.
    report::parse_upload
    # Same for operator/validator/asset-tag/media-source/destination: parse them
    # early so the report (and any boot-time snapshot) already carries them.
    report::parse_identity
    # Same for the network upload destination (tscrub_output=ftp:/sftp:) — parse
    # it early so the boot-time diagnostics snapshot is delivered to LAN the
    # same way the erasure report is.
    report::parse_output

    # Enable attributable (vendor-signed) reports when a valid licence is present.
    # tScrub always requires a licence — even the free tier.
    license::detect
    if license::verify; then
        if license::apply; then
            printf "%sLicence valid — signed reports enabled.\n" "$TABLE_INDENT"
        else
            printf "%sLicence valid — free tier (self-signed reports).\n" "$TABLE_INDENT"
        fi
    else
        if [[ -f "$LICENSE_FILE" ]]; then
            printf "%s[!] Licence at %s is invalid or expired.\n" "$TABLE_INDENT" "$LICENSE_FILE" >&2
        else
            printf "%s[!] No licence file found. tScrub requires a licence (even a free one).\n" "$TABLE_INDENT" >&2
            printf "%s    Get one at https://tscrub.com/download, or supply --license / --license-url.\n" "$TABLE_INDENT" >&2
            printf "%s    To use a .lic file on the boot USB: copy it to the root of the USB\n" "$TABLE_INDENT" >&2
            printf "%s    stick (the writable partition), or run with --license <path>.\n" "$TABLE_INDENT" >&2
        fi
        # Keep the error on screen: on the appliance getty respawns tScrub when
        # it exits, so a bare `exit 1` here clears the console and loops back
        # into a black screen, hiding the reason. Show the network state and
        # wait for a keypress when interactive so the operator can read the
        # error (and this diagnostic) and retry. Headless runs — e.g. the VM
        # test harness — still exit immediately.
        if [[ -t 0 ]]; then
            printf "\n%sNetwork state:\n" "$TABLE_INDENT" >&2
            ip link 2>/dev/null | sed 's/^/    /' >&2 || true
            ip addr 2>/dev/null | grep -E 'inet |link/ether' | sed 's/^/    /' >&2 || true
            ip route 2>/dev/null | sed 's/^/    /' >&2 || true
            printf "\n%sPress Enter to retry, or Ctrl+Alt+Del to reboot. " "$TABLE_INDENT" >&2
            read -r _ 2>/dev/null || true
        fi
        exit 1
    fi

    # Provision the report destination (mount the boot USB) now, before the
    # triage/selection screen, so the device registration snapshot can be saved
    # to the stick immediately. The final report reuses the same mount.
    report::detect_output

    cocid::detect
    if ! cocid::is_valid "$COCID"; then
        printf "%s[!] Invalid COCID '%s'. Must be exactly 5 digits.\n" "$TABLE_INDENT" "$COCID" >&2
        exit 1
    fi
    # Clear the centered COCID prompt so its text doesn't linger while the
    # discovery -> SMART -> unfreeze sequence runs below.
    ui::terminal_controls_supported && clear

    # The session timer starts once the COCID has been resolved (the "app
    # start" moment), so the Elapsed panel does not count COCID entry time.
    START_TS="$(ts::now)"

    # Connect to the network explicitly, so the presence heartbeat, remote
    # BIOS unlock and the LAN-IP display are reliable from the start (a no-op
    # once a default route already exists). Runs AFTER the COCID prompt so a
    # no-network machine doesn't stall on a blank screen before the prompt.
    if command -v ip >/dev/null 2>&1; then
        network::ensure || true
    fi

    # Autonuke is opt-in only: --autonuke / tscrub_autonuke=1. Supplying a COCID
    # (CLI flag, kernel cmdline or tscrub.conf) no longer implies autonuke — a
    # COCID'd boot lands on the triage screen like any other boot.
    if [[ "$AUTONUKE" -eq 1 ]] || cmdline::autonuke; then
        AUTONUKE=1
    fi

    # Long-lived background workers — the presence heartbeat, the remote
    # BIOS-unlock poll, the remote power poll and (opt-in) the MDM/Autopilot
    # check. They are forked
    # DETACHED (IPC fds closed) and run for the WHOLE session; the erasure
    # workflow must never kill them. The MDM verdict travels via its result
    # file and is re-read by the triage + wipe screens (mdm::sync_state).
    # Placeholder shown until the worker publishes the dashboard's real label —
    # an honest ASCII "Pending" (NOT "Checking…", and NOT the Unicode "…" which
    # the appliance console renders as a single dot) so a not-yet-published
    # state can't be mistaken for the server's own "checking".
    # A fresh boot starts with no erasure state; clear any stale marker so the
    # first heartbeat reports "not wiping" (the server resets its phase).
    status::clear
    MDM_STATUS="Pending"
    { mdm::detect; } 3>&- 4<&- &
    mdm_pid=$!
    { presence::loop; } 3>&- 4<&- &
    presence_pid=$!
    { bios_unlock::loop; } 3>&- 4<&- &
    bios_unlock_pid=$!
    { remote::loop; } 3>&- 4<&- &
    remote_pid=$!
    device::install_sedutil
    ui::spinner_start "Discovering devices..."
    if ! device::discover; then
        ui::spinner_stop
        table::build
        table::render
        exit 1
    fi
    smart::capture_all pre
    ui::spinner_stop

    # Unfreezing prints its own lines (the suspend/resume IS the progress), so
    # the spinner is off here to keep them from fighting over the same line.
    device::handle_locks
    device::frozen

    ui::spinner_start "Preparing..."
    device::detect
    ui::spinner_stop

    # Optional hardware self-tests / diagnostics suite (opt-in: --selftest /
    # tscrub_selftest=1 for the fast CPU+storage pair, or --diag / tscrub_diag=1
    # for the full automatic tier). Storage short self-tests take ~2 min/drive,
    # so they only run on request; verdicts land in the diagnostics snapshot.
    if [[ "$DIAG" -eq 1 ]] || cmdline::diag; then
        DIAG=1
        ui::spinner_start "Running hardware diagnostics..."
        diag::run
        ui::spinner_stop
    elif [[ "$SELFTEST" -eq 1 ]] || cmdline::selftest; then
        SELFTEST=1
        ui::spinner_start "Running hardware self-tests..."
        selftest::run
        ui::spinner_stop
    fi

    table::build
    ui::spinner_stop

    # Derived "BIOS lockdown suspected" flag — needs the per-drive state that
    # table::build just classified (SED-locked or still-frozen drives).
    hardware::lockdown

    # ITAD triage: register the machine with the portal (identity + hardware +
    # drive inventory) and save the snapshot to the USB, BEFORE any wipe. Then
    # either autonuke (wipe everything) or let the operator pick the drives.
    # Runs in the background so a slow/late network (USB NIC) can't stall the
    # transition to the selection screen; its retries happen in parallel. fd 3
    # is closed so the job doesn't hold the UI IPC pipe open.
    { register::push; } 3>&- &
    register_pid=$!
    # The MDM worker publishes its verdict to a result file; pull its latest
    # state back before the first screen renders.
    mdm::sync_state

    if [[ "$AUTONUKE" -eq 1 ]]; then
        # PXE fleet / --autonuke: wipe every drive immediately, report, then
        # exit (no triage screen, no post-run prompt).
        erasure::run
        ui::cursor_show
    else
        # Default: the persistent triage screen. Erasure is entered on Shift+T.
        triage::run
        ui::cursor_show
    fi

    # The background registration is a one-shot POST; reap it if it outlived
    # the session (its USB snapshot write is best-effort, so a late finish is
    # fine). The presence / BIOS-unlock / MDM workers are long-lived and are
    # intentionally left to die with the process.
    if [[ -n "${register_pid:-}" ]]; then
        kill "$register_pid" 2>/dev/null || true
        wait "$register_pid" 2>/dev/null || true
    fi
}

# =============================================================================
# ERASURE WORKFLOW (repeatable) — entered from the triage screen via Shift+T,
# or immediately when --autonuke is set. Runs one full select → wipe → report
# → finish cycle, then returns to the caller (the triage screen re-renders).
# =============================================================================
erasure::run() {
    local dev initiated

    # Record who triggered this erasure cycle (remote dashboard vs local keys).
    if [[ "${REMOTE_ERASE:-0}" -eq 1 ]]; then
        initiated="remote"
    else
        initiated="local"
    fi
    printf 'erasure initiated: %s\n' "$initiated" >&5

    # A repeat erasure starts clean. Rebuild the drive table (re-runs
    # device::classify, resetting status→PLANNED and clearing the timing
    # caches) and clear the per-cycle selection + report-timing fields the
    # previous cycle left behind. table::build is required here because
    # device::normalize_outcome rewrites class/cert/method for non-completed
    # drives at the end of each cycle.
    table::build
    for dev in "${devices[@]}"; do
        devrow["$dev.selected"]=0
        devrow["$dev.start_ts"]=""
        devrow["$dev.end_ts"]=""
        devrow["$dev.verify_result"]=""
        devrow["$dev.verify_sectors"]=""
        devrow["$dev.hpa_result"]=""
        devrow["$dev.dco_result"]=""
    done
    ui_eta_row=()
    pids=()

    # Report-delivery state is per-cycle, not per-session.
    REPORT_USB_STATUS=""
    REPORT_USB_REASON=""
    REPORT_DASH_STATUS=""
    REPORT_DASH_REASON=""
    REPORT_NET_STATUS=""
    REPORT_NET_REASON=""

    # Re-mount the report destination if a previous cycle unmounted it (the
    # boot-time mount is consumed by each erasure's sync_out).
    if [[ -z "${REPORT_USB_MNT:-}" ]]; then
        report::detect_output
    fi

    # Fresh worker -> UI IPC channel for this erasure (the previous one was
    # consumed; a session can run several erasures).
    ipc::open

    if [[ "$AUTONUKE" -eq 1 ]]; then
        select::all
        table::render
    elif [[ "${REMOTE_ERASE:-0}" -eq 1 ]]; then
        # Remote-initiated: the triage loop set REMOTE_ERASE_DRIVES ("all", or a
        # newline-separated serial list). Apply it after the per-cycle reset.
        if [[ "${REMOTE_ERASE_DRIVES:-}" == "all" ]]; then
            select::all
        else
            while IFS= read -r drv; do
                for dev in "${devices[@]}"; do
                    [[ "${devrow[$dev.serial],,}" == "${drv,,}" ]] && devrow["$dev.selected"]=1
                done
            done <<< "$REMOTE_ERASE_DRIVES"
        fi
        table::render
    else
        # The interactive selection screen renders itself (blue, with markers)
        # inside select::run — rendering a black, marker-less table here first
        # would flash it before the real screen. Headless (no terminal) has no
        # selection UI, so render once for it here instead.
        if ! ui::terminal_controls_supported; then
            table::render
        fi
        if ! select::run; then
            # Esc on the selection screen: land back on the triage screen, not a
            # frozen selection screen. select::run has just cleared SELECT_MODE,
            # so the marker rows and the selection footer legend on the console
            # are stale — re-render the idle table (blue, triage legend). Return
            # 2 so the caller (triage::run) knows to resume its normal MDM
            # re-renders instead of pinning the finish screen (return 0 means
            # the finish screen is up as the post-erasure result view).
            report::sync_out
            ipc::close
            TRIAGE_MODE=1
            UI_COMPLETE_THEME=4
            mdm::sync_state
            table::render
            return 2
        fi
    fi

    # Unselected drives are recorded (not wiped) as SKIPPED.
    for dev in "${devices[@]}"; do
        [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] || devrow["$dev.status"]="SKIPPED"
    done

    if [[ "$DRY_RUN" -eq 1 ]]; then
        if [[ "$DRY_RUN_SIM_ETA_MINS" -gt 0 ]]; then
            for dev in "${devices[@]}"; do
                [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] || continue
                devrow["$dev.eta_mins"]="$DRY_RUN_SIM_ETA_MINS"
                devrow["$dev.status"]="PLANNED"
                devrow["$dev.wipe_start"]=""
            done
            table::render

            dryrun::simulate_running_eta &
            pids+=($!)
        else
            for dev in "${devices[@]}"; do
                [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] || continue
                devrow["$dev.status"]="DRY-RUN"
            done
            table::render
        fi
    else
        # Blue background while the wipe is in progress; the outcome colour
        # (green/red/amber) is painted only once everything has finished.
        UI_COMPLETE_THEME=4
        verify::plant_all
        status::erase_start
        for dev in "${devices[@]}"; do
            [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] || continue
            device::execute "$dev" &
            pids+=($!)
        done
    fi
    exec 3>&-
    exec {UI[1]}>&-

    ui::loop

    for pid in "${pids[@]}"; do
        wait "$pid"
    done

    # Settle the MDM verdict for the report from the worker's result file
    # (non-blocking — the worker is long-lived and must NOT be killed here; it
    # finishes its own bounded polling and the triage screen keeps showing its
    # latest label).
    mdm::sync_state

    # The erasure report must only be produced/uploaded once every drive has
    # reached a terminal state — never while an erase is still in progress. A
    # worker that died without reporting (OOM/killed) leaves a drive
    # non-terminal; flag it and withhold the report (a half-done run is not an
    # erasure report).
    local erasure_complete=1
    if ! ui::all_drives_terminal; then
        erasure_complete=0
        for dev in "${devices[@]}"; do
            case "${devrow[$dev.status]:-}" in
                COMPLETED|FAILED|BLOCKED|FROZEN|DRY-RUN|SKIPPED) ;;
                *) devrow["$dev.status"]="UNKNOWN" ;;
            esac
        done
    fi

    # Make the report honest: drives that didn't complete must not carry the
    # optimistic class/cert/method they were classified for.
    device::normalize_outcome

    # Publish the final erasure state (a worker that died without reporting is
    # UNKNOWN above; ui::loop may already have published done/failed on the
    # last terminal line — this call is idempotent).
    if [[ "$DRY_RUN" -eq 0 ]]; then
        status::drive_terminal
    fi

    smart::capture_all post
    verify::check_all

    # A dry-run report is not evidence of a real wipe — never vendor-sign it.
    if [[ "$DRY_RUN" -eq 1 ]]; then
        VERIFY_MODE="none"
        HPA_MODE="off"
        unset REPORT_KEY
    fi

    if [[ "$erasure_complete" -eq 1 ]]; then
        if report_file=$(report::csv); then
            # report::csv runs in a command-substitution subshell, so record the
            # USB-save outcome here (detect_output may already have flagged "fail").
            [[ -z "${REPORT_USB_STATUS:-}" ]] && REPORT_USB_STATUS="ok"
        else
            REPORT_USB_STATUS="fail"
            REPORT_USB_REASON="failed to write report file"
        fi
        if [[ "$DRY_RUN" -eq 0 && -n "$report_file" ]]; then
            report::parse_upload
            report::parse_output
            # If an upload destination is configured but the boot-time DHCP missed
            # the link-up window, re-request a lease before trying to send.
            if [[ -n "${TSCRUB_API_TOKEN:-}" || -n "${TSCRUB_NET_PROTO:-}" ]]; then
                network::ensure
            fi
            report::upload "$report_file"
        fi
    else
        printf "%sErasure report withheld: not all drives reached a terminal state (erasure incomplete).\n" "$TABLE_INDENT" >&5
        REPORT_USB_STATUS="fail"
        REPORT_USB_REASON="erasure incomplete — report withheld"
    fi
    debug::save
    report::sync_out

    # Track report-delivery outcome for the finish colour (red > amber > green).
    # A run counts as delivered if AT LEAST ONE destination succeeded; amber is
    # reserved for when every configured destination failed. The per-destination
    # OK/FAILED detail is printed by report::print_summary.
    local report_ok=0 report_failed=0
    [[ "${REPORT_USB_STATUS:-}" == "ok" ]]    && report_ok=1
    [[ "${REPORT_DASH_STATUS:-}" == "ok" ]]   && report_ok=1
    [[ "${REPORT_NET_STATUS:-}" == "ok" ]]    && report_ok=1
    [[ "${REPORT_USB_STATUS:-}" == "fail" ]]  && report_failed=1
    [[ "${REPORT_DASH_STATUS:-}" == "fail" ]] && report_failed=1
    [[ "${REPORT_NET_STATUS:-}" == "fail" ]]  && report_failed=1

    # The finish screen doubles as the post-erasure result view: show the triage
    # key legend in its footer so the operator can re-erase (Shift+T), power off
    # (R/S) or exit (Esc) without a blocking prompt.
    TRIAGE_MODE=1

    # Paint the outcome colour only now that the wipe, SMART capture and report
    # delivery have all finished — no green/red then amber flash.
    if [[ "$DRY_RUN" -eq 1 ]]; then
        ui::show_finish_green "DRY-RUN finished"
    else
        local failed_count=0
        for dev in "${devices[@]}"; do
            case "${devrow[$dev.status]}" in
                COMPLETED|DRY-RUN|SKIPPED) ;;
                *) failed_count=$((failed_count + 1)) ;;
            esac
        done
        if (( failed_count > 0 )); then
            ui::show_finish_green "Sanitization process finished — $failed_count drive(s) did not complete"
        elif [[ "$report_ok" -eq 0 && "$report_failed" -eq 1 ]]; then
            ui::show_finish_orange "Sanitization process finished — no report destination succeeded"
        else
            ui::show_finish_green "Sanitization process finished"
        fi
    fi

    # Consume this erasure's IPC channel so the next erasure (or the triage
    # screen) can open a fresh one. The long-lived telemetry workers are
    # deliberately left running — they die with the process, never here.
    ipc::close
    return 0
}

