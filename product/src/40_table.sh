# =============================================================================
# TABLE
# =============================================================================

table::build() {
    local dev cap

    for dev in "${devices[@]}"; do
        cap="${capability[$dev]}"

        devrow["$dev.device"]="$dev"
        devrow["$dev.model"]="${model[$dev]}"
        devrow["$dev.serial"]="${serial[$dev]}"
        devrow["$dev.size"]="${size[$dev]}"
        devrow["$dev.bus"]="${bus[$dev]}"
        devrow["$dev.type"]="${type[$dev]}"
        devrow["$dev.capability"]="$cap"
        devrow["$dev.status"]="PLANNED"
        devrow["$dev.eta_mins"]=""
        devrow["$dev.wipe_start"]=""

        device::classify "$dev"
    done
}

ui::format_runtime() {
    local now="$1"
    local runtime_s runtime_h runtime_m runtime_sec

    runtime_s=0
    if [[ "$START_TS" =~ ^[0-9]+$ ]] && [[ "$START_TS" -gt 0 ]]; then
        runtime_s=$(( now - START_TS ))
        (( runtime_s < 0 )) && runtime_s=0
    fi
    runtime_h=$(( runtime_s / 3600 ))
    runtime_m=$(( (runtime_s % 3600) / 60 ))
    runtime_sec=$(( runtime_s % 60 ))
    printf "%02d:%02d:%02d" "$runtime_h" "$runtime_m" "$runtime_sec"
}

ui::eta_text_for() {
    local dev="$1"
    local now="$2"
    local eta_mins wipe_start dev_status remain rh rm rs

    eta_mins="${devrow[$dev.eta_mins]}"
    wipe_start="${devrow[$dev.wipe_start]}"
    dev_status="${devrow[$dev.status]}"

    case "$dev_status" in
        PLANNED|DRY-RUN)
            if [[ "$eta_mins" =~ ^[0-9]+$ ]]; then
                if (( eta_mins >= 60 )); then
                    printf "~%dh%dm" "$(( eta_mins / 60 ))" "$(( eta_mins % 60 ))"
                else
                    printf "~%dmin" "$eta_mins"
                fi
            else
                printf "N/A"
            fi
            ;;
        RUNNING|*%)
            if [[ "$eta_mins" =~ ^[0-9]+$ ]]; then
                if [[ "$wipe_start" =~ ^[0-9]+$ ]]; then
                    remain=$(( eta_mins * 60 - (now - wipe_start) ))
                    (( remain < 0 )) && remain=0
                    rh=$(( remain / 3600 ))
                    rm=$(( (remain % 3600) / 60 ))
                    rs=$(( remain % 60 ))
                    if (( rh > 0 )); then
                        printf "~%dh%dm%ds" "$rh" "$rm" "$rs"
                    elif (( rm > 0 )); then
                        printf "~%dm%ds" "$rm" "$rs"
                    else
                        printf "~%ds" "$rs"
                    fi
                else
                    if (( eta_mins >= 60 )); then
                        printf "~%dh%dm" "$(( eta_mins / 60 ))" "$(( eta_mins % 60 ))"
                    else
                        printf "~%dmin" "$eta_mins"
                    fi
                fi
            else
                printf "N/A"
            fi
            ;;
        COMPLETED)
            printf "Done"
            ;;
        FAILED|FROZEN|BLOCKED)
            printf '%s' "--"
            ;;
        *)
            printf "N/A"
            ;;
    esac
}

ui::tick_inplace() {
    local now runtime_str dev row eta_col

    if [[ "$UI_INPLACE" -ne 1 ]]; then
        return 1
    fi
    if [[ "$UI_RUNTIME_ROW" -le 0 || "$UI_RUNTIME_COL" -le 0 ]]; then
        return 1
    fi

    now=$(date +%s)
    runtime_str="$(ui::format_runtime "$now")"

    printf "\0337"
    printf "\033[%d;%dH%-69.69s" "$UI_RUNTIME_ROW" "$UI_RUNTIME_COL" "$runtime_str"

    for dev in "${devices[@]}"; do
        row="${ui_eta_row[$dev]:-}"
        [[ "$row" =~ ^[0-9]+$ ]] || continue
        eta_col="$(ui::eta_text_for "$dev" "$now")"
        printf "\033[%d;%dH%-9.9s" "$row" "$UI_ETA_COL" "$eta_col"
    done

    printf "\0338"
}

table::render() {
    if [[ "$UI_COMPLETE_THEME" -ne 0 ]] && [[ -t 1 ]]; then
        if [[ "$UI_COMPLETE_THEME" -eq 2 ]]; then
            # Red background: one or more drives failed/blocked.
            printf "\033[0;41;37m\033[2J\033[H"
        else
            # Green background: all drives completed successfully.
            printf "\033[0;42;30m\033[2J\033[H"
        fi
    else
        clear
        printf "\n"
    fi

    local now
    local runtime_s runtime_h runtime_m runtime_sec runtime_str
    local main_w panel_w hline
    local cpu_line cpu_printed gpu_line gpu_printed
    local cpu_rows gpu_rows row
    local eta_base_row completion_base_row
    local sys_label_w sys_value_w runtime_label_w runtime_value_w
    now=$(date +%s)
    runtime_str="$(ui::format_runtime "$now")"

    main_w=170
    panel_w=$(( (main_w - 2) / 2 ))
    hline="$(printf "%*s" $((panel_w - 2)) "" | tr ' ' '-')"
    sys_label_w=11
    sys_value_w=$((panel_w - sys_label_w - 5))
    runtime_label_w=10
    runtime_value_w=$((panel_w - runtime_label_w - 5))
    eta_base_row=16
    completion_base_row=17
    UI_RUNTIME_ROW=5
    UI_RUNTIME_COL=$((1 + ${#TABLE_INDENT} + 2 + 10 + 1 + (panel_w - 15) + 6 + 10 + 1))

    cpu_printed=0
    cpu_rows=0
    while IFS= read -r cpu_line; do
        [[ -z "$cpu_line" ]] && continue
        cpu_rows=$(( cpu_rows + 1 ))
    done <<< "$SYS_CPU_LIST"
    (( cpu_rows == 0 )) && cpu_rows=1

    gpu_printed=0
    gpu_rows=0
    while IFS= read -r gpu_line; do
        [[ -z "$gpu_line" ]] && continue
        gpu_rows=$(( gpu_rows + 1 ))
    done <<< "$SYS_GPU_LIST"
    (( gpu_rows == 0 )) && gpu_rows=1

    ui_eta_row=()

    # Top header: two side-by-side tables (left: system info, right: runtime)
    printf "%s+%s+  +%s+\n" "$TABLE_INDENT" "$hline" "$hline"
    printf "%s| %-*.*s |  | %-*.*s |\n" \
        "$TABLE_INDENT" \
        $((panel_w - 4)) $((panel_w - 4)) "System Info" \
        $((panel_w - 4)) $((panel_w - 4)) "Runtime"
    printf "%s+%s+  +%s+\n" "$TABLE_INDENT" "$hline" "$hline"
    printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
        "$TABLE_INDENT" "${sys_label_w}" "System:" "$sys_value_w" "$sys_value_w" "$SYS_MANUFACTURER $SYS_PRODUCT" \
        "$runtime_label_w" "Elapsed:" "$runtime_value_w" "$runtime_value_w" "$runtime_str"
    printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
        "$TABLE_INDENT" "$sys_label_w" "System SN:" "$sys_value_w" "$sys_value_w" "$SYS_SERIAL" \
        "$runtime_label_w" "COCID:" "$runtime_value_w" "$runtime_value_w" "${COCID:-N/A}"
    printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
        "$TABLE_INDENT" "$sys_label_w" "Board SN:" "$sys_value_w" "$sys_value_w" "$SYS_BASEBOARD_SERIAL" \
        "$runtime_label_w" "" "$runtime_value_w" "$runtime_value_w" ""
    printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
        "$TABLE_INDENT" "$sys_label_w" "Chassis SN:" "$sys_value_w" "$sys_value_w" "$SYS_CHASSIS_SERIAL" \
        "$runtime_label_w" "" "$runtime_value_w" "$runtime_value_w" ""
    printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
        "$TABLE_INDENT" "$sys_label_w" "Chassis:" "$sys_value_w" "$sys_value_w" "$SYS_CHASSIS_TYPE" \
        "$runtime_label_w" "" "$runtime_value_w" "$runtime_value_w" ""
    printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
        "$TABLE_INDENT" "$sys_label_w" "BIOS:" "$sys_value_w" "$sys_value_w" "$SYS_BIOS_VERSION ($SYS_BIOS_DATE)" \
        "$runtime_label_w" "" "$runtime_value_w" "$runtime_value_w" ""

    while IFS= read -r cpu_line; do
        [[ -z "$cpu_line" ]] && continue
        if [[ "$cpu_printed" -eq 0 ]]; then
            printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
                "$TABLE_INDENT" "$sys_label_w" "CPUs:" "$sys_value_w" "$sys_value_w" "$cpu_line" \
                "$runtime_label_w" "" "$runtime_value_w" "$runtime_value_w" ""
            cpu_printed=1
        else
            printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
                "$TABLE_INDENT" "$sys_label_w" "" "$sys_value_w" "$sys_value_w" "$cpu_line" \
                "$runtime_label_w" "" "$runtime_value_w" "$runtime_value_w" ""
        fi
    done <<< "$SYS_CPU_LIST"

    if [[ "$cpu_printed" -eq 0 ]]; then
        printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
            "$TABLE_INDENT" "$sys_label_w" "CPUs:" "$sys_value_w" "$sys_value_w" "N/A" \
            "$runtime_label_w" "" "$runtime_value_w" "$runtime_value_w" ""
    fi

    while IFS= read -r gpu_line; do
        [[ -z "$gpu_line" ]] && continue
        if [[ "$gpu_printed" -eq 0 ]]; then
            printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
                "$TABLE_INDENT" "$sys_label_w" "GPUs:" "$sys_value_w" "$sys_value_w" "$gpu_line" \
                "$runtime_label_w" "" "$runtime_value_w" "$runtime_value_w" ""
            gpu_printed=1
        else
            printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
                "$TABLE_INDENT" "$sys_label_w" "" "$sys_value_w" "$sys_value_w" "$gpu_line" \
                "$runtime_label_w" "" "$runtime_value_w" "$runtime_value_w" ""
        fi
    done <<< "$SYS_GPU_LIST"

    if [[ "$gpu_printed" -eq 0 ]]; then
        printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
            "$TABLE_INDENT" "$sys_label_w" "GPUs:" "$sys_value_w" "$sys_value_w" "N/A" \
            "$runtime_label_w" "" "$runtime_value_w" "$runtime_value_w" ""
    fi

    printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
        "$TABLE_INDENT" "$sys_label_w" "RAM:" "$sys_value_w" "$sys_value_w" "$SYS_RAM_GB" \
        "$runtime_label_w" "" "$runtime_value_w" "$runtime_value_w" ""
    printf "%s+%s+  +%s+\n\n" "$TABLE_INDENT" "$hline" "$hline"

    printf "%s%-30s %-25s %-8s %-8s %-8s %-8s %-6s %-8s %-13s %-15s %-20s %-12s %-9s\n" \
        "$TABLE_INDENT" \
        "MODEL" "SERIAL" "SIZE" "BUS" "TYPE" "SMART" "TEMP" "DEVICE" "CLASS" "CERT" "METHOD" "STATUS" "ETA"

    printf "%s%s\n" "$TABLE_INDENT" "$(printf "%*s" 186 "" | tr ' ' '-')"

    if [[ "$NO_SUPPORTED_DRIVES" -eq 1 ]]; then
        printf "%s%-186s\n" "$TABLE_INDENT" "[!] $DISCOVERY_NOTICE"
    fi

    for dev in "${devices[@]}"; do
        local eta_col smart_col temp_col
        eta_col="$(ui::eta_text_for "$dev" "$now")"
        smart_col="${devrow[$dev.smart]:--}"
        temp_col="${devrow[$dev.temp]:-}"
        [[ -n "$temp_col" ]] && temp_col="${temp_col}C" || temp_col="-"
        row=$((eta_base_row + cpu_rows + gpu_rows + ${#ui_eta_row[@]} ))
        ui_eta_row["$dev"]="$row"

        printf "%s%-30s %-25s %-8s %-8s %-8s %-8s %-6s %-8s %-13s %-15s %-20s %-12s %-9s\n" \
            "$TABLE_INDENT" \
            "${devrow[$dev.model]}" \
            "${devrow[$dev.serial]}" \
            "${devrow[$dev.size]}" \
            "${devrow[$dev.bus]}" \
            "${devrow[$dev.type]}" \
            "$smart_col" \
            "$temp_col" \
            "${devrow[$dev.device]}" \
            "${devrow[$dev.class]}" \
            "${devrow[$dev.cert]}" \
            "${devrow[$dev.method]}" \
            "${devrow[$dev.status]}" \
            "$eta_col"

    done

    printf "%s%s\n" "$TABLE_INDENT" "$(printf "%*s" 186 "" | tr ' ' '-')"

    if [[ "$UI_COMPLETE_THEME" -ne 0 ]] && [[ -t 1 ]]; then
        printf "\033[%d;1H" "$((completion_base_row + cpu_rows + gpu_rows + ${#devices[@]}))"
    fi

}

ui::loop() {
    local _rc _dev _key _value
    while true; do
        IFS=' ' read -t 1 -r -u4 _dev _key _value; _rc=$?
        if (( _rc == 0 )); then
            [[ -z "${_dev:-}" ]] && continue
            if [[ "$_key" == "STATUS" ]]; then
                devrow["$_dev.status"]="$_value"
                # Record when an ATA wipe starts (first RUNNING only)
                if [[ "$_value" == "RUNNING" ]] && \
                   [[ -n "${devrow[$_dev.eta_mins]}" ]] && \
                   [[ -z "${devrow[$_dev.wipe_start]}" ]]; then
                    devrow["$_dev.wipe_start"]="$(date +%s)"
                fi
                table::render
            fi
        elif (( _rc > 128 )); then
            # read timed out — update only time fields to avoid full-screen flicker
            if ! ui::tick_inplace; then
                table::render
            fi
            # If every drive is already terminal but the pipe read end never
            # EOFs (e.g. a leaked writer keeps fd 3 open), stop instead of
            # ticking forever so the finish screen/report can still run.
            if ui::all_drives_terminal; then
                break
            fi
        else
            # read failed without timing out. This is normally EOF (all writers
            # closed), but a timed read can also be cut short when a SIGCHLD
            # arrives from a short-lived helper (sleep/awk/nvme/date) spawned by
            # a worker's monitor. Only stop once every drive has actually
            # reached a terminal state, or the IPC coprocess has truly exited;
            # otherwise this was a spurious wake-up and we must keep reading so
            # we never paint the finish screen while a wipe is still running.
            if ui::all_drives_terminal; then
                break
            fi
            if [[ -z "${UI_PID:-}" ]] || ! kill -0 "$UI_PID" 2>/dev/null; then
                break
            fi
        fi
    done
    exec 4<&-
}

# Returns success (0) only when every drive has reached a terminal state.
ui::all_drives_terminal() {
    local dev
    for dev in "${devices[@]}"; do
        case "${devrow[$dev.status]}" in
            COMPLETED|FAILED|FROZEN|BLOCKED|DRY-RUN) ;;
            *) return 1 ;;
        esac
    done
    return 0
}


