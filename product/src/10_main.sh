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

fn_main() {
    # Restore the cursor on exit; make Ctrl+C / SIGTERM actually abort (after
    # showing the cursor) instead of being silently swallowed.
    trap 'ui::spinner_stop; ui::cursor_show; exit 130' INT
    trap 'ui::spinner_stop; ui::cursor_show; exit 143' TERM
    trap 'ui::spinner_stop; ui::cursor_show' EXIT

    # Reset per-run state so a repeat run (post-run "Run again" option) starts
    # clean, and rebuild the worker -> UI IPC channel the previous run consumed.
    RERUN=0
    REPORT_USB_STATUS=""
    REPORT_USB_REASON=""
    REPORT_DASH_STATUS=""
    REPORT_DASH_REASON=""
    REPORT_NET_STATUS=""
    REPORT_NET_REASON=""
    MDM_STATUS=""
    MDM_VERDICT=""
    BIOS_PASSWORD_STATUS=""
    BIOS_DETECTION_METHOD=""
    mdm_pid=""
    devrow=()
    ui_eta_row=()
    pids=()
    ipc::open
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
    START_TS="$(ts::now)"

    # Apply any on-stick tscrub.conf (dashboard upload, COCID, licence URL)
    # first, so the licence/COCID resolution below sees it.
    config::load_usb
    # Also parse the kernel command line NOW (rather than at report-upload time)
    # so a PXE-supplied tscrub_api_token= / tscrub_upload= is available to the
    # boot-time device registration and presence heartbeat, both of which run
    # long before the final report upload. Cmdline wins over tscrub.conf.
    report::parse_upload

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
    # Autonuke: wipe every drive immediately (no selection screen) when forced
    # by --autonuke, implied by --cocid / tscrub_cocid= (NON_INTERACTIVE), or
    # requested from the kernel command line (tscrub_autonuke=1).
    if [[ "$AUTONUKE" -eq 1 || "$NON_INTERACTIVE" -eq 1 ]] || cmdline::autonuke; then
        AUTONUKE=1
    fi
    # Kick off the MDM (Autopilot) check in the background — it runs in parallel
    # with device discovery/SMART capture and publishes its verdict to the UI
    # over the worker IPC channel (fd 3). Forked before the `exec 3>&-` below so
    # its late status write still reaches the UI reader. The appliance only sends
    # serial/uuid; the dashboard holds the Azure credentials.
    # Placeholder shown until the worker publishes the dashboard's real label —
    # an honest ASCII "Pending" (NOT "Checking…", and NOT the Unicode "…" which
    # the appliance console renders as a single dot) so a not-yet-published
    # state can't be mistaken for the server's own "checking".
    MDM_STATUS="Pending"
    mdm::detect &
    mdm_pid=$!
    presence::loop &
    presence_pid=$!
    bios_unlock::loop &
    bios_unlock_pid=$!
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
    table::build
    ui::spinner_stop

    # ITAD triage: register the machine with the portal (identity + hardware +
    # drive inventory) and save the snapshot to the USB, BEFORE any wipe. Then
    # either autonuke (wipe everything) or let the operator pick the drives.
    # Runs in the background so a slow/late network (USB NIC) can't stall the
    # transition to the selection screen; its retries happen in parallel. fd 3
    # is closed so the job doesn't hold the UI IPC pipe open.
    { register::push; } 3>&- &
    register_pid=$!
    # The MDM worker's result travels over the IPC pipe that only ui::loop reads
    # (during the wipe), so pull its latest published state back before the
    # selection screen renders — otherwise the panel shows the "Pending"
    # placeholder even though the verdict already settled.
    mdm::sync_state
    if [[ "$AUTONUKE" -eq 1 ]]; then
        select::all
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
            ui::cursor_show
            printf "%s%s\n" "$TABLE_INDENT" "Selection aborted — nothing was erased."
            report::sync_out
            exit 0
        fi
    fi

    pids=()

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

    # Settle the MDM verdict before the report is written: wait for the worker
    # (bounded by its curl --max-time) and recover its verdict from the result
    # file if the UI loop ended before it published (e.g. an instant dry-run).
    if [[ -n "${mdm_pid:-}" ]]; then
        # If the Autopilot worker outlived the drive workers the screen would
        # otherwise sit frozen — both the elapsed timer and its spinner stop
        # the moment the last drive completes. Keep ticking the timer/spinner
        # in place and swap the MDM cell to "Finalising…" (same static ellipsis
        # as "Checking…") until the worker actually exits.
        if [[ -t 1 ]] && kill -0 "$mdm_pid" 2>/dev/null; then
            local mdm_row=$(( UI_RUNTIME_ROW + 6 ))
            local mdm_stat_line mdm_state mdm_ticks=0
            printf "\033[%d;%dH%-*.*s" "$mdm_row" "$UI_RUNTIME_COL" \
                "$UI_RUNTIME_VALUE_W" "$UI_RUNTIME_VALUE_W" "Finalising..."
            while kill -0 "$mdm_pid" 2>/dev/null && (( mdm_ticks < 20 )); do
                # A finished-but-unreaped worker is a zombie and kill -0 still
                # succeeds for it — stop as soon as its /proc state reads "Z".
                if [[ -r "/proc/$mdm_pid/stat" ]]; then
                    mdm_stat_line="$(< "/proc/$mdm_pid/stat")"
                    mdm_state="${mdm_stat_line##*) }"; mdm_state="${mdm_state:0:1}"
                    [[ "$mdm_state" == "Z" ]] && break
                fi
                # Advances the elapsed timer + spinner (and refreshes the ETA
                # cells) while the worker settles, bounded so a still-pending
                # probe can't hold the finish screen open.
                ui::tick_inplace || true
                sleep 0.25
                mdm_ticks=$(( mdm_ticks + 1 ))
            done
        fi
        # The MDM worker polls the dashboard in the background and normally
        # finishes long before the wipe does; a fast wipe/dry-run can outrun it.
        # Stop it here so the finish screen/report are never blocked on a
        # still-pending probe — the latest published label is recovered below.
        kill "$mdm_pid" 2>/dev/null || true
        wait "$mdm_pid" 2>/dev/null || true
        if [[ -f "$MDM_RESULT_FILE" ]]; then
            [[ -z "${MDM_VERDICT:-}" ]] && MDM_VERDICT="$(sed -n '1p' "$MDM_RESULT_FILE" 2>/dev/null)"
            if [[ -z "${MDM_STATUS:-}" || "${MDM_STATUS:-}" == "Pending" ]]; then
                MDM_STATUS="$(sed -n '2p' "$MDM_RESULT_FILE" 2>/dev/null)"
                [[ -n "${MDM_STATUS:-}" ]] || MDM_STATUS="Offline"
            fi
        fi
    fi

    # A worker that died without reporting a terminal status (OOM/killed) must
    # not leave a drive showing RUNNING on the report.
    for dev in "${devices[@]}"; do
        case "${devrow[$dev.status]:-}" in
            COMPLETED|FAILED|BLOCKED|FROZEN|DRY-RUN|SKIPPED) ;;
            *) devrow["$dev.status"]="UNKNOWN" ;;
        esac
    done

    # Make the report honest: drives that didn't complete must not carry the
    # optimistic class/cert/method they were classified for.
    device::normalize_outcome

    smart::capture_all post

    # A dry-run report is not evidence of a real wipe — never vendor-sign it.
    if [[ "$DRY_RUN" -eq 1 ]]; then
        unset REPORT_KEY
    fi
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

    ui::print_drive_guidance

    if [[ "$(report::skipped_count)" -gt 0 ]]; then
        printf "\033[K%sWiped %s of %d drive(s); %d skipped (not selected).\n" \
            "$TABLE_INDENT" "$(report::selected_count)" "${#devices[@]}" "$(report::skipped_count)"
    fi

    report::print_summary

    # Stop the presence heartbeat — the run is finished.
    if [[ -n "${presence_pid:-}" ]]; then
        kill "$presence_pid" 2>/dev/null || true
    fi
    if [[ -n "${bios_unlock_pid:-}" ]]; then
        kill "$bios_unlock_pid" 2>/dev/null || true
    fi
    # The background registration is a one-shot POST; reap it if it outlived
    # the run (its USB snapshot write is best-effort, so a late finish is fine).
    if [[ -n "${register_pid:-}" ]]; then
        kill "$register_pid" 2>/dev/null || true
        wait "$register_pid" 2>/dev/null || true
    fi

    if [[ "$DRY_RUN" -eq 0 ]]; then
        [[ "$NON_INTERACTIVE" -eq 1 ]] || ui::post_run_prompt
    fi
}

