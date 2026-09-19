# =============================================================================
# Entry Point
# =============================================================================

parse_args "$@"

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
        echo "$dev STATUS COMPLETED" >&3
    done
}

fn_main() {
    trap 'ui::cursor_show' EXIT INT TERM

    # Reset per-run state so a repeat run (post-run "Run again" option) starts
    # clean, and rebuild the worker -> UI IPC channel the previous run consumed.
    RERUN=0
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
    START_TS=$(date +%s)

    # Enable attributable (vendor-signed) reports when a valid licence is present.
    license::detect
    if license::verify; then
        license::apply
        printf "%sLicence valid — signed reports enabled.\n" "$TABLE_INDENT"
    fi

    ui::coc_prompt
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

    if [[ "$DRY_RUN" -eq 1 ]]; then
        ui::show_finish_green "DRY-RUN finished"
    else
        ui::show_finish_green "Sanitization process finished"
    fi

    smart::capture_all post

    report_file=$(report::csv)
    if [[ "$DRY_RUN" -eq 0 ]]; then
        report::parse_ftp
        report::upload "$report_file"
        ui::post_run_prompt
    fi
}

