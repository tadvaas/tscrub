# =============================================================================
# TABLE
# =============================================================================

# Detect the usable terminal width in columns. Falls back to 186 (the full
# layout) when it cannot be determined (e.g. piped output without COLUMNS).
table::detect_terminal_width() {
    local w="${COLUMNS:-}"
    [[ "$w" =~ ^[0-9]+$ ]] && (( w > 0 )) || w=""
    if [[ -z "$w" ]]; then
        if [[ -t 1 ]] && command -v tput &>/dev/null; then
            w="$(tput cols 2>/dev/null || true)"
        fi
        [[ "$w" =~ ^[0-9]+$ ]] && (( w > 0 )) || w=""
    fi
    [[ -z "$w" ]] && w=186
    printf '%s' "$w"
}

# Detect the terminal height in rows (fallback 24).
table::detect_terminal_height() {
    local h="${LINES:-}"
    [[ "$h" =~ ^[0-9]+$ ]] && (( h > 0 )) || h=""
    if [[ -z "$h" ]]; then
        if [[ -t 1 ]] && command -v tput &>/dev/null; then
            h="$(tput lines 2>/dev/null || true)"
        fi
        [[ "$h" =~ ^[0-9]+$ ]] && (( h > 0 )) || h=""
    fi
    [[ -z "$h" ]] && h=24
    printf '%s' "$h"
}

# Chooses a device-table layout that fits the current terminal width and sets
# the globals consumed by table::render() and ui::tick_inplace():
#   UI_TABLE_MAIN_W, UI_TABLE_LABELS, UI_TABLE_WIDTHS, UI_TABLE_FMT,
#   UI_ETA_COL, UI_ETA_W
# The table degrades from the full 12-column / 182-char layout down to a
# 9-column / 76-char layout so an 80-column console still renders on one line.
# CERT is gone (redundant with CLASS); METHOD drops below wide terminals; the
# fixed-vocabulary columns (CLASS/STATUS/ETA/...) are sized so they never clip.
table::compute_layout() {
    local term_w even_w margin=2 avail
    term_w="$(table::detect_terminal_width)"
    # Small, equal left/right margin; the table fills everything in between.
    # Round the available width down to an even number so panel_w=(main_w-2)/2
    # divides exactly (an odd terminal simply leaves one trailing column).
    avail=$(( term_w - 2 * margin ))
    (( avail < 20 )) && avail=20
    even_w=$(( avail - (avail % 2) ))

    # Choose the column set by terminal width (METHOD drops below 118, SMART and
    # TEMP below 100), then let the two free-text columns (MODEL, SERIAL) absorb
    # every remaining column so the table fills the full width edge-to-edge.
    # UI_TABLE_MIN holds the minimum width for every column after MODEL/SERIAL;
    # these minima match the old fixed tiers at their lower bound, so nothing
    # clips on a narrower terminal.
    if (( term_w >= 186 )); then
        UI_TABLE_LABELS=(MODEL SERIAL SIZE BUS TYPE SMART TEMP DEVICE CLASS METHOD STATUS ETA)
        UI_TABLE_MIN=(8 8 8 8 6 8 13 20 12 9)
    elif (( term_w >= 152 )); then
        UI_TABLE_LABELS=(MODEL SERIAL SIZE BUS TYPE SMART TEMP DEVICE CLASS METHOD STATUS ETA)
        UI_TABLE_MIN=(7 6 6 6 5 8 10 19 9 9)
    elif (( term_w >= 118 )); then
        UI_TABLE_LABELS=(MODEL SERIAL SIZE BUS TYPE SMART TEMP DEVICE CLASS STATUS ETA)
        UI_TABLE_MIN=(7 6 6 5 6 8 9 9 9)
    elif (( term_w >= 100 )); then
        UI_TABLE_LABELS=(MODEL SERIAL SIZE BUS TYPE SMART TEMP DEVICE CLASS STATUS ETA)
        UI_TABLE_MIN=(7 6 6 5 6 7 7 9 9)
    else
        UI_TABLE_LABELS=(MODEL SERIAL SIZE BUS TYPE CLASS DEVICE STATUS ETA)
        UI_TABLE_MIN=(7 5 5 7 7 9 8)
    fi

    local n=${#UI_TABLE_LABELS[@]}
    local i model_min=12 serial_min=8 fixed=0
    for ((i=0; i<n-2; i++)); do fixed=$(( fixed + UI_TABLE_MIN[i] )); done
    local base=$(( model_min + serial_min + fixed + (n - 1) ))
    local extra=$(( even_w - base ))
    (( extra < 0 )) && extra=0

    # Split the slack ~50/50 between MODEL and SERIAL (odd column to MODEL), so
    # MODEL stays a touch wider than SERIAL as in the old layouts.
    local half=$(( extra / 2 ))
    UI_TABLE_WIDTHS=("$(( model_min + half + (extra % 2) ))" "$(( serial_min + half ))" "${UI_TABLE_MIN[@]}")

    local content=0
    UI_TABLE_FMT=""
    for ((i=0; i<n; i++)); do
        local w="${UI_TABLE_WIDTHS[i]}"
        UI_TABLE_FMT+="%-${w}.${w}s"
        (( i < n-1 )) && UI_TABLE_FMT+=" "
        content=$(( content + w ))
        (( i < n-1 )) && content=$(( content + 1 ))
    done

    UI_TABLE_MAIN_W=$content
    # Equal left/right margin (also used to indent the finish message/summary).
    printf -v UI_TABLE_INDENT '%*s' "$margin" ''

    UI_ETA_COL=$(( ${#UI_TABLE_INDENT} + content - ${UI_TABLE_WIDTHS[n-1]} + 1 ))
    UI_ETA_W=${UI_TABLE_WIDTHS[n-1]}
}

# Prints one device row using the current layout. Maps each label in
# UI_TABLE_LABELS to its value so dropped/shrunk columns stay consistent.
table::print_row() {
    local dev="$1" now="$2"
    local eta_col smart_col temp_col label val fg_reset
    local -a cells
    local i w cell hot

    eta_col="$(ui::eta_text_for "$dev" "$now")"
    smart_col="${devrow[$dev.smart]:--}"
    temp_col="${devrow[$dev.temp]:-}"
    if [[ -n "$temp_col" ]]; then
        temp_col="${temp_col}C"
    else
        temp_col="-"
    fi

    # After a red temperature reading, restore the active text colour rather
    # than the terminal default, so the green finish screen stays black-on-
    # green (and red stays white-on-red) either side of the hot cell.
    case "${UI_COMPLETE_THEME:-0}" in
        1) printf -v fg_reset "\033[30m" ;;   # green finish: black text
        2) printf -v fg_reset "\033[37m" ;;   # red finish: white text
        3) printf -v fg_reset "\033[30m" ;;   # amber finish: black text
        4) printf -v fg_reset "\033[37m" ;;   # blue running: white text
        *) printf -v fg_reset "\033[39m" ;;   # normal screen: default fg
    esac

    cells=()
    i=0
    for label in "${UI_TABLE_LABELS[@]}"; do
        w="${UI_TABLE_WIDTHS[i]}"
        case "$label" in
            MODEL)  val="${devrow[$dev.model]}" ;;
            SERIAL) val="${devrow[$dev.serial]}" ;;
            SIZE)   val="${devrow[$dev.size]}" ;;
            BUS)    val="${devrow[$dev.bus]}" ;;
            TYPE)   val="${devrow[$dev.type]}" ;;
            SMART)  val="$smart_col" ;;
            TEMP)   val="$temp_col" ;;
            DEVICE) val="${devrow[$dev.device]}" ;;
            CLASS)  val="${devrow[$dev.class]}" ;;
            METHOD) val="${devrow[$dev.method]}" ;;
            STATUS) val="${devrow[$dev.status]}" ;;
            ETA)    val="$eta_col" ;;
        esac

        # Free-text columns (model/serial) get a "..." suffix when truncated;
        # the fixed-vocabulary columns are sized so their values never clip.
        if [[ "$label" == "MODEL" || "$label" == "SERIAL" ]]; then
            if (( ${#val} > w )); then
                val="${val:0:$(( w - 3 ))}..."
            fi
        fi

        cell="$(printf "%-*s" "$w" "${val:0:$w}")"

        # Drives hotter than 75C show a red temperature reading. Red foreground
        # only (background untouched), reset back to the active theme colour.
        if [[ "$label" == "TEMP" && -t 1 && "$val" != "-" ]]; then
            hot="${val%C}"
            if [[ "$hot" =~ ^[0-9]+$ ]] && (( hot > 75 )); then
                printf -v cell "\033[31m%s%s" "$cell" "$fg_reset"
            fi
        fi

        cells+=("$cell")
        i=$(( i + 1 ))
    done

    printf "%s%s\n" "$TABLE_INDENT" "${cells[*]}"
}

table::build() {
    local dev cap

    for dev in "${devices[@]}"; do
        cap="${capability[$dev]}"

        devrow["$dev.device"]="$dev"
        devrow["$dev.model"]="${model[$dev]:-N/A}"
        devrow["$dev.serial"]="${serial[$dev]:-N/A}"
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

# One-frame spinner glyph; advances on UI_SPINNER_FRAME. Appended to the runtime
# value so the console visibly "ticks" even when a wipe is slow (not stuck).
ui::spinner() {
    local frames
    frames=( '|' '/' '-' '\' )
    printf '%s' "${frames[UI_SPINNER_FRAME % 4]}"
}

# Bottom-of-screen footer: brand + version, centred. Clears the line first so a
# terminal resize (or an old, longer footer) never leaves stale text behind.
ui::footer() {
    local text term_w pad
    text="${SCRIPT_NAME} ${SCRIPT_VERSION} — tscrub.com"
    term_w="$(table::detect_terminal_width)"
    pad=$(( (term_w - ${#text}) / 2 ))
    (( pad < 0 )) && pad=0
    printf "\033[K%*s%s" "$pad" "" "$text"
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
            printf '%s' "--"
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
    if [[ "$UI_RUNTIME_ROW" -le 0 || "$UI_RUNTIME_COL" -le 0 || "$UI_RUNTIME_VALUE_W" -le 0 ]]; then
        return 1
    fi

    now="$(ts::now)"
    UI_SPINNER_FRAME=$(( UI_SPINNER_FRAME + 1 ))
    runtime_str="$(ui::format_runtime "$now") $(ui::spinner)"

    printf "\0337"
    printf "\033[%d;%dH%-*.*s" "$UI_RUNTIME_ROW" "$UI_RUNTIME_COL" "$UI_RUNTIME_VALUE_W" "$UI_RUNTIME_VALUE_W" "$runtime_str"

    for dev in "${devices[@]}"; do
        row="${ui_eta_row[$dev]:-}"
        [[ "$row" =~ ^[0-9]+$ ]] || continue
        eta_col="$(ui::eta_text_for "$dev" "$now")"
        printf "\033[%d;%dH%-*.*s" "$row" "$UI_ETA_COL" "$UI_ETA_W" "$UI_ETA_W" "$eta_col"
    done

    printf "\0338"
}

table::render() {
    if [[ "$UI_COMPLETE_THEME" -ne 0 ]] && [[ -t 1 ]]; then
        case "$UI_COMPLETE_THEME" in
            2)
                # Red background: one or more drives failed/blocked.
                printf "\033[0;41;37m\033[2J\033[H"
                ;;
            3)
                # Amber background: wipe finished but report save/upload failed.
                # 43 is the closest 16-colour console shade to orange.
                printf "\033[0;43;30m\033[2J\033[H"
                ;;
            4)
                # Blue background: wipe in progress. Keep the leading blank
                # line of the normal running screen so the in-place tick row
                # coordinates (elapsed/ETA) line up with the painted table.
                printf "\033[0;44;37m\033[2J\033[H"
                printf "\n"
                ;;
            *)
                # Green background: all drives completed successfully.
                printf "\033[0;42;30m\033[2J\033[H"
                ;;
        esac
    elif [[ -t 1 ]]; then
        clear
        printf "\n"
    fi

    local now rows
    local runtime_s runtime_h runtime_m runtime_sec runtime_str
    local main_w panel_w hline
    local cpu_line cpu_printed gpu_line gpu_printed
    local cpu_rows gpu_rows row
    local eta_base_row completion_base_row
    local sys_label_w sys_value_w runtime_label_w runtime_value_w
    now="$(ts::now)"
    rows="$(table::detect_terminal_height)"
    runtime_str="$(ui::format_runtime "$now") $(ui::spinner)"

    # Width shared by the two info panels and the device table so their frames
    # line up. The full layout is 182 columns; on narrower terminals columns
    # shrink and low-value columns are dropped (METHOD below wide terminals,
    # SMART/TEMP on 80-column consoles) so the table still fits on one line
    # without clipping any value. The table is centred in the terminal, and
    # TABLE_INDENT is re-pointed at that centred indent for this screen.
    table::compute_layout
    TABLE_INDENT="$UI_TABLE_INDENT"
    main_w=$UI_TABLE_MAIN_W
    panel_w=$(( (main_w - 2) / 2 ))
    hline="$(printf "%*s" $((panel_w - 2)) "" | tr ' ' '-')"
    sys_label_w=11
    sys_value_w=$((panel_w - sys_label_w - 5))
    runtime_label_w=10
    runtime_value_w=$((panel_w - runtime_label_w - 5))
    UI_RUNTIME_VALUE_W=$runtime_value_w
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
        "$runtime_label_w" "Licence:" "$runtime_value_w" "$runtime_value_w" "${LICENSE_CUSTOMER:-N/A}"
    printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
        "$TABLE_INDENT" "$sys_label_w" "Chassis SN:" "$sys_value_w" "$sys_value_w" "$SYS_CHASSIS_SERIAL" \
        "$runtime_label_w" "Tier:" "$runtime_value_w" "$runtime_value_w" "${LICENSE_TIER:-N/A}"
    printf "%s| %-*s %-*.*s |  | %-*s %-*.*s |\n" \
        "$TABLE_INDENT" "$sys_label_w" "Chassis:" "$sys_value_w" "$sys_value_w" "$SYS_CHASSIS_TYPE" \
        "$runtime_label_w" "Expiry:" "$runtime_value_w" "$runtime_value_w" "${LICENSE_EXPIRY:-N/A}"
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

    printf "%s$UI_TABLE_FMT\n" "$TABLE_INDENT" "${UI_TABLE_LABELS[@]}"

    printf "%s%s\n" "$TABLE_INDENT" "$(printf "%*s" "$UI_TABLE_MAIN_W" "" | tr ' ' '-')"

    if [[ "$NO_SUPPORTED_DRIVES" -eq 1 ]]; then
        printf "%s%-*s\n" "$TABLE_INDENT" "$UI_TABLE_MAIN_W" "[!] $DISCOVERY_NOTICE"
    fi

    for dev in "${devices[@]}"; do
        row=$((eta_base_row + cpu_rows + gpu_rows + ${#ui_eta_row[@]} ))
        ui_eta_row["$dev"]="$row"
        table::print_row "$dev" "$now"
    done

    printf "%s%s\n" "$TABLE_INDENT" "$(printf "%*s" "$UI_TABLE_MAIN_W" "" | tr ' ' '-')"

    if [[ "$UI_COMPLETE_THEME" -ne 0 && "$UI_COMPLETE_THEME" -ne 4 ]] && [[ -t 1 ]]; then
        printf "\033[%d;1H" "$((completion_base_row + cpu_rows + gpu_rows + ${#devices[@]}))"
    fi

    # Sticky footer pinned to the bottom row on every full repaint. Save/restore
    # the cursor so the caller's position (finish message, in-place tick) is kept.
    if [[ -t 1 ]]; then
        printf "\0337"
        printf "\033[%d;1H" "$rows"
        ui::footer
        printf "\0338"
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
                    devrow["$_dev.wipe_start"]="$(ts::now)"
                fi
                table::render
            else
                # Forward non-STATUS worker messages (LOG) to the log file.
                printf '%s %s %s\n' "$_dev" "$_key" "$_value" >&5
            fi
        elif (( _rc > 128 )); then
            # read timed out — update only time fields to avoid full-screen flicker
            if ! ui::tick_inplace && [[ -t 1 ]]; then
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
    # Close the coprocess read end too, so repeated "Run again" (RERUN) does
    # not leak one fd per run.
    # Close the coprocess read end too, so repeated "Run again" (RERUN) does
    # not leak one fd per run. The stderr suppression is scoped to the group so
    # it does NOT permanently redirect the shell's stderr.
    { exec {UI[0]}<&-; } 2>/dev/null || true
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


