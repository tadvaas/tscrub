# =============================================================================
# Entry Point
# =============================================================================

dryrun::simulate_running_eta() {
    local sim_secs elapsed dev

    sim_secs=$(( DRY_RUN_SIM_ETA_MINS * 60 ))

    for dev in "${devices[@]}"; do
        echo "$dev STATUS RUNNING" >&3
    done

    for ((elapsed=0; elapsed<sim_secs; elapsed++)); do
        sleep 1
    done

    for dev in "${devices[@]}"; do
        echo "$dev STATUS DRY-RUN" >&3
    done
}

fn_main() {
    # Restore the cursor on exit; make Ctrl+C / SIGTERM actually abort (after
    # showing the cursor) instead of being silently swallowed.
    trap 'ui::cursor_show; exit 130' INT
    trap 'ui::cursor_show; exit 143' TERM
    trap 'ui::cursor_show' EXIT

    # Reset per-run state so a repeat run (post-run "Run again" option) starts
    # clean, and rebuild the worker -> UI IPC channel the previous run consumed.
    RERUN=0
    REPORT_USB_STATUS=""
    REPORT_USB_REASON=""
    REPORT_DASH_STATUS=""
    REPORT_DASH_REASON=""
    REPORT_NET_STATUS=""
    REPORT_NET_REASON=""
    devrow=()
    ui_eta_row=()
    pids=()
    ipc::open

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
    START_TS="$(ts::now)"

    # Apply any on-stick tscrub.conf (dashboard upload, COCID, licence URL)
    # first, so the licence/COCID resolution below sees it.
    config::load_usb

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
        exit 1
    fi

    cocid::detect
    if ! cocid::is_valid "$COCID"; then
        printf "%s[!] Invalid COCID '%s'. Must be exactly 5 digits.\n" "$TABLE_INDENT" "$COCID" >&2
        exit 1
    fi
    device::install_sedutil
    if ! device::discover; then
        table::build
        table::render
        exit 1
    fi
    smart::capture_all pre

    device::handle_locks
    device::frozen
    device::detect
    table::build
    table::render

    pids=()

    if [[ "$DRY_RUN" -eq 1 ]]; then
        if [[ "$DRY_RUN_SIM_ETA_MINS" -gt 0 ]]; then
            for dev in "${devices[@]}"; do
                devrow["$dev.eta_mins"]="$DRY_RUN_SIM_ETA_MINS"
                devrow["$dev.status"]="PLANNED"
                devrow["$dev.wipe_start"]=""
            done
            table::render

            dryrun::simulate_running_eta &
            pids+=($!)
        else
            for dev in "${devices[@]}"; do
                devrow["$dev.status"]="DRY-RUN"
            done
            table::render
        fi
    else
        # Blue background while the wipe is in progress; the outcome colour
        # (green/red/amber) is painted only once everything has finished.
        UI_COMPLETE_THEME=4
        for dev in "${devices[@]}"; do
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

    # A worker that died without reporting a terminal status (OOM/killed) must
    # not leave a drive showing RUNNING on the report.
    for dev in "${devices[@]}"; do
        case "${devrow[$dev.status]:-}" in
            COMPLETED|FAILED|BLOCKED|FROZEN|DRY-RUN) ;;
            *) devrow["$dev.status"]="UNKNOWN" ;;
        esac
    done

    smart::capture_all post

    report::detect_output
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

    # Track whether any report destination failed; used to pick the finish
    # colour (red > amber > green). report::print_summary lists each outcome.
    local report_failed=0
    [[ "${REPORT_USB_STATUS:-}" == "fail" ]] && report_failed=1
    [[ "${REPORT_DASH_STATUS:-}" == "fail" ]] && report_failed=1
    [[ "${REPORT_NET_STATUS:-}" == "fail" ]] && report_failed=1

    # Paint the outcome colour only now that the wipe, SMART capture and report
    # delivery have all finished — no green/red then amber flash.
    if [[ "$DRY_RUN" -eq 1 ]]; then
        ui::show_finish_green "DRY-RUN finished"
    else
        local failed_count=0
        for dev in "${devices[@]}"; do
            case "${devrow[$dev.status]}" in
                COMPLETED|DRY-RUN) ;;
                *) failed_count=$((failed_count + 1)) ;;
            esac
        done
        if (( failed_count > 0 )); then
            ui::show_finish_green "Sanitization process finished — $failed_count drive(s) did not complete"
        elif [[ "$report_failed" -eq 1 ]]; then
            ui::show_finish_orange "Sanitization process finished — report delivery failed"
        else
            ui::show_finish_green "Sanitization process finished"
        fi
    fi

    report::print_summary

    if [[ "$DRY_RUN" -eq 0 ]]; then
        [[ "$NON_INTERACTIVE" -eq 1 ]] || ui::post_run_prompt
    fi
}

