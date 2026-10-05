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
# Build one device row as a single string (marker gutter + cells + optional
# cursor highlight). Factored out so the full render and the in-place selection
# repaint share identical formatting.
table::row_text() {
    local dev="$1" now="$2"
    local eta_col smart_col temp_col label val fg_reset
    local -a cells
    local i w cell hot row

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
            MODEL)
                val="${devrow[$dev.model]}"
                if [[ "${SELECT_MODE:-0}" -eq 1 ]]; then
                    if [[ "$dev" == "${SELECT_CURSOR:-}" ]]; then
                        [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] && val=">[x] $val" || val=">[ ] $val"
                    elif [[ "${devrow[$dev.selected]:-0}" -eq 1 ]]; then
                        val=" [x] $val"
                    else
                        val=" [ ] $val"
                    fi
                fi
                ;;
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

    row="${cells[*]}"
    if [[ "${SELECT_MODE:-0}" -eq 1 && "$dev" == "${SELECT_CURSOR:-}" && -t 1 ]]; then
        row=$'\033[7m'"$row"$'\033[27m'
    fi
    printf '%s' "$row"
}

table::print_row() {
    local dev="$1" now="$2"
    printf "%s%s\n" "$TABLE_INDENT" "$(table::row_text "$dev" "$now")"
}

# One-line warning when any discovered drive is a RAID member — tScrub never
# auto-breaks an array, so the operator is told to dismantle in the controller
# BIOS before erasing.
table::print_raid_warning() {
    local dev note=""
    for dev in "${devices[@]}"; do
        [[ "${raid[$dev]:-none}" != "none" ]] || continue
        note+="${note:+; }${dev} (${raid[$dev]})"
    done
    [[ -z "$note" ]] && return 0
    printf "%s[!] RAID member(s): %s - dismantle in controller BIOS before erasing.\n" \
        "$TABLE_INDENT" "$note"
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

# The Runtime panel's MDM status cell: the dashboard's exact label (verbatim —
# no client-side mapping, so wording can change server-side without an
# appliance release), padded to the panel's value width. Colouring is keyed off
# the machine-readable MDM_VERDICT (green Unlocked / red Locked / amber
# Offline) when $1 is 1. The caller supplies the colour decision because this
# function runs inside a $(...) command substitution (its stdout is a pipe, so
# [[ -t 1 ]] would always be false here).
#
# Two refinements keep it legible on the coloured screens:
#   * the colour reset restores the THEME's text colour (black on green/amber,
#     white on red/blue) — a plain \033[39m would leave the rest of the row the
#     terminal default (white) instead of the theme colour; and
#   * when the status colour would vanish into the finish-screen background
#     (green "Unlocked" on the green success screen, red "Locked" on the red
#     failure screen, amber "Offline" on the amber delivery-failure screen),
#     the value falls back to the theme's text colour in BOLD so it stays
#     readable and subtly distinct.
ui::mdm_render() {
    local state="${MDM_STATUS:-}" verdict="${MDM_VERDICT:-}" colour="${1:-0}" cell w="${UI_RUNTIME_VALUE_W:-20}" esc fg_reset
    cell="$(printf '%-*.*s' "$w" "$w" "${state:-}")"
    if [[ "$colour" -eq 1 ]]; then
        # Theme text colour (same mapping as table::print_row's fg_reset), plus
        # SGR 22 (normal intensity) so the bold clash fallback below cannot leak
        # bold onto the closing "|" and every row after the MDM cell.
        case "${UI_COMPLETE_THEME:-0}" in
            1|3) printf -v fg_reset '\033[22;30m' ;;   # green/amber finish: black
            2|4) printf -v fg_reset '\033[22;37m' ;;   # red/blue: white
            *)   printf -v fg_reset '\033[22;39m' ;;   # normal screen: default fg
        esac
        case "$verdict" in
            unlocked)                 esc=$'\033[32m' ;;
            locked_this|locked_other) esc=$'\033[31m' ;;
            offline|ms_error|error)   esc=$'\033[33m' ;;
            *)                        esc="" ;;
        esac
        # Status colour clashes with the themed background → swap to the theme
        # text colour in bold (readable, subtle).
        case "${UI_COMPLETE_THEME:-0}:${verdict}" in
            1:unlocked|3:offline|3:ms_error|3:error) esc=$'\033[1;30m' ;;   # green/amber bg: bold black
            2:locked_this|2:locked_other)             esc=$'\033[1;37m' ;;   # red bg: bold white
        esac
        if [[ -n "$esc" ]]; then
            printf '%s%s%s' "$esc" "$cell" "$fg_reset"
        else
            printf '%s' "$cell"
        fi
    else
        printf '%s' "$cell"
    fi
}

# The Runtime panel's BIOS-lock cell: mirrors ui::mdm_render (same padding,
# colour and theme-clash rules) for the BIOS password verdict.
ui::bios_render() {
    local state="${BIOS_PASSWORD_STATUS:-}" colour="${1:-0}" label cell w="${UI_RUNTIME_VALUE_W:-20}" esc fg_reset
    case "$state" in
        LOCKED)   label="Locked" ;;
        UNLOCKED) label="Unlocked" ;;
        UNKNOWN)  label="Unknown" ;;
        *)        label="-" ;;
    esac
    cell="$(printf '%-*.*s' "$w" "$w" "$label")"
    if [[ "$colour" -eq 1 ]]; then
        case "${UI_COMPLETE_THEME:-0}" in
            1|3) printf -v fg_reset '\033[22;30m' ;;   # green/amber finish: black
            2|4) printf -v fg_reset '\033[22;37m' ;;   # red/blue: white
            *)   printf -v fg_reset '\033[22;39m' ;;   # normal screen: default fg
        esac
        case "$state" in
            UNLOCKED) esc=$'\033[32m' ;;
            LOCKED)   esc=$'\033[31m' ;;
            UNKNOWN)  esc=$'\033[33m' ;;
            *)        esc="" ;;
        esac
        # Status colour clashes with the themed background → swap to the theme
        # text colour in bold (readable, subtle).
        case "${UI_COMPLETE_THEME:-0}:${state}" in
            1:UNLOCKED|3:UNKNOWN) esc=$'\033[1;30m' ;;   # green/amber bg: bold black
            2:LOCKED)             esc=$'\033[1;37m' ;;   # red bg: bold white
        esac
        if [[ -n "$esc" ]]; then
            printf '%s%s%s' "$esc" "$cell" "$fg_reset"
        else
            printf '%s' "$cell"
        fi
    else
        printf '%s' "$cell"
    fi
}

# Bottom-of-screen footer: a separator line, then the centred brand/version
# line. Called with the cursor on the separator's row; it leaves the cursor on
# the footer text row (the caller's blank bottom-margin row sits below it).
ui::footer() {
    local text term_w pad sep legend=""
    text="${SCRIPT_NAME} ${SCRIPT_VERSION} — tscrub.com"
    term_w="$(table::detect_terminal_width)"
    sep="$(printf "%*s" "$UI_TABLE_MAIN_W" "" | tr ' ' '-')"
    printf "\033[K%s%s\n" "$UI_TABLE_INDENT" "$sep"
    pad=$(( (term_w - ${#text}) / 2 ))
    (( pad < 0 )) && pad=0
    printf "\033[K%*s%s" "$pad" "" "$text"
    if [[ "${SELECT_MODE:-0}" -eq 1 ]]; then
        legend="$(select::legend)"
    elif [[ "${TRIAGE_MODE:-0}" -eq 1 ]]; then
        legend="$(triage::legend)"
    fi
    if [[ -n "$legend" ]]; then
        pad=$(( (term_w - ${#legend}) / 2 ))
        (( pad < 0 )) && pad=0
        printf "\n\033[K%*s%s" "$pad" "" "$legend"
    fi
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
                # Blue background: wipe in progress.
                printf "\033[0;44;37m\033[2J\033[H"
                ;;
            *)
                # Green background: all drives completed successfully.
                printf "\033[0;42;30m\033[2J\033[H"
                ;;
        esac
        # Leading blank line = top margin on every coloured screen, matching the
        # normal running screen and keeping the absolute tick rows aligned.
        printf "\n"
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
    local mdm_colour bios_colour
    now="$(ts::now)"
    mdm_colour=0; [[ -t 1 ]] && mdm_colour=1
    bios_colour=0; [[ -t 1 ]] && bios_colour=1
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
    eta_base_row=17
    completion_base_row=18
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
    printf "%s| %-*s %-*.*s |  | %-*s %s |\n" \
        "$TABLE_INDENT" "$sys_label_w" "LAN IP:" "$sys_value_w" "$sys_value_w" "$(network::lan_ip)" \
        "$runtime_label_w" "BIOS Lock:" "$(ui::bios_render "$bios_colour")"
    printf "%s| %-*s %-*.*s |  | %-*s %s |\n" \
        "$TABLE_INDENT" "$sys_label_w" "BIOS:" "$sys_value_w" "$sys_value_w" "$SYS_BIOS_VERSION ($SYS_BIOS_DATE)" \
        "$runtime_label_w" "MDM:" "$(ui::mdm_render "$mdm_colour")"

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

    table::print_raid_warning

    printf "%s%s\n" "$TABLE_INDENT" "$(printf "%*s" "$UI_TABLE_MAIN_W" "" | tr ' ' '-')"

    if [[ "$UI_COMPLETE_THEME" -ne 0 && "$UI_COMPLETE_THEME" -ne 4 ]] && [[ -t 1 ]]; then
        printf "\033[%d;1H" "$((completion_base_row + cpu_rows + gpu_rows + ${#devices[@]}))"
    fi

    # Sticky footer pinned just above the bottom margin (a blank line matching
    # the top), with a separator line above it. In selection mode an extra key
    # legend line is appended, so the footer starts one row higher. Save/restore
    # the cursor so the caller's position (finish message, in-place tick) is kept.
    if [[ -t 1 ]] && (( rows > 3 )); then
        local footer_row=$(( rows - 2 ))
        if [[ "${SELECT_MODE:-0}" -eq 1 || "${TRIAGE_MODE:-0}" -eq 1 ]]; then
            footer_row=$(( rows - 3 ))
        fi
        printf "\0337"
        printf "\033[%d;1H" "$footer_row"
        ui::footer
        printf "\0338"
    fi

}

ui::loop() {
    local _rc _dev _key _value
    while true; do
        # 0.25 s timeout → the spinner advances 4×/second (one full rotation
        # per second). Elapsed time only changes once per second regardless.
        IFS=' ' read -t 0.25 -r -u4 _dev _key _value; _rc=$?
        if (( _rc == 0 )); then
            [[ -z "${_dev:-}" ]] && continue
            if [[ "$_dev" == "mdm" ]]; then
                case "$_key" in
                    STATUS)
                        MDM_STATUS="$_value"
                        table::render
                        ;;
                    VERDICT)
                        MDM_VERDICT="$_value"
                        ;;
                    *)
                        printf '%s %s %s\n' "$_dev" "$_key" "$_value" >&5
                        ;;
                esac
            elif [[ "$_key" == "STATUS" ]]; then
                devrow["$_dev.status"]="$_value"
                # Record when an ATA wipe starts (first RUNNING only)
                if [[ "$_value" == "RUNNING" ]] && \
                   [[ -n "${devrow[$_dev.eta_mins]}" ]] && \
                   [[ -z "${devrow[$_dev.wipe_start]}" ]]; then
                    devrow["$_dev.wipe_start"]="$(ts::now)"
                fi
                # Report timing: first RUNNING -> start, first terminal -> end.
                if [[ "$_value" == "RUNNING" ]] && [[ -z "${devrow[$_dev.start_ts]}" ]]; then
                    devrow["$_dev.start_ts"]="$(ts::now)"
                    devrow["$_dev.start_at"]="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
                fi
                case "$_value" in
                    COMPLETED|FAILED|BLOCKED|FROZEN|SKIPPED|DRY-RUN)
                        if [[ -z "${devrow[$_dev.end_ts]}" ]]; then
                            devrow["$_dev.end_ts"]="$(ts::now)"
                            devrow["$_dev.end_at"]="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
                        fi
                        # Publish the live erasure state (Wiping -> Complete /
                        # Failed) to the dashboard heartbeat on each terminal
                        # drive transition.
                        status::drive_terminal
                        ;;
                esac
                table::render
            else
                # Forward non-STATUS worker messages (LOG) to the log file.
                printf '%s %s %s\n' "$_dev" "$_key" "$_value" >&5
            fi
        elif (( _rc > 128 )); then
            # read timed out — refresh the MDM cell from the worker's result
            # file (re-render only when the label changes), else update just
            # the time fields in place to avoid full-screen flicker.
            local prev_mdm="${MDM_STATUS:-}"
            mdm::sync_state
            if [[ "${MDM_STATUS:-}" != "$prev_mdm" ]]; then
                table::render
            elif ! ui::tick_inplace && [[ -t 1 ]]; then
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
    # Close the coprocess read end too, so a repeat erasure does not leak one
    # fd per cycle. The stderr suppression is scoped to the group so it does
    # NOT permanently redirect the shell's stderr.
    { exec {UI[0]}<&-; } 2>/dev/null || true
}

# Returns success (0) only when every drive has reached a terminal state.
ui::all_drives_terminal() {
    local dev
    for dev in "${devices[@]}"; do
        case "${devrow[$dev.status]}" in
            COMPLETED|FAILED|FROZEN|BLOCKED|DRY-RUN|SKIPPED) ;;
            *) return 1 ;;
        esac
    done
    return 0
}

# =============================================================================
# DRIVE SELECTION — Space toggles, T starts (entered from the triage screen).
# =============================================================================

# Number of currently selected drives.
select::count() {
    local dev n=0
    for dev in "${devices[@]}"; do
        [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] && n=$((n+1))
    done
    printf '%d' "$n"
}

# Selected drive names, space-separated (used by tests and the wipe loop).
select::chosen() {
    local dev out=""
    for dev in "${devices[@]}"; do
        [[ "${devrow[$dev.selected]:-0}" -eq 1 ]] && out+="$dev "
    done
    printf '%s' "$out"
}

select::toggle() {
    local dev="$1"
    if [[ "${devrow[$dev.selected]:-0}" -eq 1 ]]; then
        devrow["$dev.selected"]=0
    else
        devrow["$dev.selected"]=1
    fi
}

select::all() {
    local dev
    for dev in "${devices[@]}"; do devrow["$dev.selected"]=1; done
}

select::none() {
    local dev
    for dev in "${devices[@]}"; do devrow["$dev.selected"]=0; done
}

# Selection mode globals: when SELECT_MODE=1 the device table shows a marker
# gutter and a cursor row; the full table UI (system/runtime panels + drive
# table + footer) still renders underneath it.
SELECT_MODE=0
SELECT_CURSOR=""

# Triage mode: the idle diagnostics screen (default after COCID). When set the
# footer shows the triage key legend instead of the selection legend.
TRIAGE_MODE=0

# Compact selection legend (also shown in the sticky footer). The selected
# count is embedded so the footer can be repainted in place on each toggle.
select::legend() {
    printf 'Space=select ↑/↓=move A=all N=none T=start Esc   Sel: %d/%d' \
        "$(select::count)" "${#devices[@]}"
}

# Repaint a single drive row in place (no clear/reflow) — used by the selection
# loop so toggling/moving the cursor only touches the affected rows.
select::paint_row() {
    local dev="$1" row
    [[ -t 1 ]] || return 0
    row="${ui_eta_row[$dev]:-}"
    [[ "$row" =~ ^[0-9]+$ ]] || return 0
    printf "\033[%d;1H\033[K%s%s" "$row" "$TABLE_INDENT" "$(table::row_text "$dev" "$(ts::now)")"
}

# Repaint the footer legend in place (the selected count changes on toggle).
select::paint_footer() {
    local rows term_w pad legend
    [[ -t 1 ]] || return 0
    rows="$(table::detect_terminal_height)"
    term_w="$(table::detect_terminal_width)"
    legend="$(select::legend)"
    pad=$(( (term_w - ${#legend}) / 2 ))
    (( pad < 0 )) && pad=0
    printf "\033[%d;1H\033[K%*s%s" "$(( rows - 1 ))" "$pad" "" "$legend"
}

# Selection-screen power actions (Shift+R / Shift+S). Each clears selection
# state and returns 1 so a failed reboot/poweroff aborts instead of wiping.
select::reboot() {
    SELECT_MODE=0
    SELECT_CURSOR=""
    ui::cursor_show
    ui::terminal_controls_supported && clear
    printf "%sRestarting...\n" "$TABLE_INDENT"
    reboot 2>/dev/null || reboot -f 2>/dev/null || true
    return 1
}

select::shutdown() {
    SELECT_MODE=0
    SELECT_CURSOR=""
    ui::cursor_show
    ui::terminal_controls_supported && clear
    printf "%sShutting down...\n" "$TABLE_INDENT"
    poweroff 2>/dev/null || poweroff -f 2>/dev/null || true
    return 1
}

# Interactive drive selection overlaid on the FULL table UI (blue screen).
# Returns 0 when the operator starts (the selected set is in
# devrow[*].selected); 1 on abort (Esc/q). Without an interactive terminal it
# selects everything and starts immediately (headless = autonuke).
select::run() {
    local dev idx key k2 prev
    select::none
    idx=0
    SELECT_MODE=1

    if ! ui::terminal_controls_supported; then
        select::all
        SELECT_MODE=0
        return 0
    fi

    # Blue "in progress" theme for the selection screen, matching the wipe UI.
    UI_COMPLETE_THEME=4
    ui::cursor_hide

    SELECT_CURSOR="${devices[0]:-}"
    mdm::sync_state
    table::render    # one full render; every keystroke below is in-place

    while :; do
        IFS= read -rsn1 key < /dev/tty 2>/dev/null || { select::all; SELECT_MODE=0; return 0; }

        if [[ "$key" == $'\e' ]]; then
            IFS= read -rsn2 -t 0.05 k2 < /dev/tty 2>/dev/null
            key="$key$k2"
        fi

        case "$key" in
            $'\e[A'|'k')
                prev="$SELECT_CURSOR"
                idx=$(( idx > 0 ? idx - 1 : ${#devices[@]} - 1 ))
                SELECT_CURSOR="${devices[$idx]}"
                select::paint_row "$prev"
                select::paint_row "$SELECT_CURSOR"
                ;;
            $'\e[B'|'j')
                prev="$SELECT_CURSOR"
                idx=$(( idx < ${#devices[@]} - 1 ? idx + 1 : 0 ))
                SELECT_CURSOR="${devices[$idx]}"
                select::paint_row "$prev"
                select::paint_row "$SELECT_CURSOR"
                ;;
            ' ')
                select::toggle "$SELECT_CURSOR"
                select::paint_row "$SELECT_CURSOR"
                select::paint_footer
                ;;
            'a')
                select::all
                for dev in "${devices[@]}"; do select::paint_row "$dev"; done
                select::paint_footer
                ;;
            'n')
                select::none
                for dev in "${devices[@]}"; do select::paint_row "$dev"; done
                select::paint_footer
                ;;
            'T')
                if [[ "$(select::count)" -gt 0 ]]; then
                    SELECT_MODE=0
                    SELECT_CURSOR=""
                    return 0
                fi
                ;;
            $'\e'|'q') SELECT_MODE=0; SELECT_CURSOR=""; return 1 ;;
        esac
    done
}

# =============================================================================
# TRIAGE SCREEN — the idle diagnostics screen shown after COCID entry
# =============================================================================

# Triage (idle) screen key legend, shown in the sticky footer.
triage::legend() {
    printf 'Shift+T=erase  R=restart  S=shutdown  Esc=quit'
}

# Persistent triage screen: shows the live diagnostics table (timer, LAN IP,
# MDM, BIOS lock, drive inventory) and waits. Shift+T enters the erasure
# workflow; R/S power off; Esc/q exits (the getty respawns tScrub). The
# presence / BIOS-unlock / MDM workers keep running the whole time, so the
# machine stays "online" while it idles here.
triage::run() {
    local key k2 rc prev_status on_finish=0 marker cmd_id dry_run scope drv _saved_dry_run

    TRIAGE_MODE=1
    SELECT_MODE=0
    SELECT_CURSOR=""
    UI_COMPLETE_THEME=4
    if [[ -t 1 ]] && [[ -n "${TERM:-}" ]] && [[ "${TERM:-}" != "dumb" ]]; then
        UI_INPLACE=1
    else
        UI_INPLACE=0
    fi
    ui::cursor_hide

    mdm::sync_state
    table::render

    if ! ui::terminal_controls_supported; then
        # Headless (no terminal): there is no triage screen to interact with —
        # fall back to wiping everything (the same select-all fallback the old
        # selection screen used), so unattended boots still erase + report.
        TRIAGE_MODE=0
        erasure::run
        return 0
    fi

    while :; do
        IFS= read -t 0.5 -rsn1 key < /dev/tty 2>/dev/null; rc=$?
        if (( rc > 128 )); then
            # Remote-initiated erase: the poll worker drops a marker file when
            # the dashboard stages a wipe. Consume it, run the grace window,
            # then enter erasure with the requested drive selection.
            marker="$(remote::consume_erase_marker)"
            if [[ -n "$marker" ]]; then
                cmd_id="$(printf '%s' "$marker" | sed -n 's/^id=//p' | head -n 1)"
                dry_run="$(printf '%s' "$marker" | sed -n 's/^dry_run=//p' | head -n 1)"
                scope="$(printf '%s' "$marker" | sed -n 's/^scope=//p' | head -n 1)"
                if [[ -n "$cmd_id" ]] && remote::grace_confirm; then
                    REMOTE_ERASE_DRIVES=""
                    if [[ "$scope" == "all" ]]; then
                        REMOTE_ERASE_DRIVES="all"
                    else
                        while IFS= read -r drv; do
                            for dev in "${devices[@]}"; do
                                [[ "${devrow[$dev.serial]}" == "$drv" ]] && REMOTE_ERASE_DRIVES+="$drv"$'\n'
                            done
                        done < <(printf '%s' "$marker" | sed -n 's/^drive=//p')
                        if [[ -z "$REMOTE_ERASE_DRIVES" ]]; then
                            remote::report "$cmd_id" failed "no drives matched"
                            continue
                        fi
                    fi
                    REMOTE_ERASE=1
                    _saved_dry_run="$DRY_RUN"
                    [[ "$dry_run" == "1" ]] && DRY_RUN=1
                    remote::report "$cmd_id" done "started"
                    TRIAGE_MODE=0
                    erasure::run
                    if [[ $? -eq 2 ]]; then
                        on_finish=0
                    else
                        on_finish=1
                    fi
                    if [[ -t 1 ]] && [[ -n "${TERM:-}" ]] && [[ "${TERM:-}" != "dumb" ]]; then
                        UI_INPLACE=1
                    fi
                    ui::cursor_hide
                    DRY_RUN="$_saved_dry_run"
                    REMOTE_ERASE=0
                    REMOTE_ERASE_DRIVES=""
                elif [[ -n "$cmd_id" ]]; then
                    remote::report "$cmd_id" failed "cancelled at the console"
                fi
                continue
            fi

            # Timeout — refresh the elapsed timer in place; re-render only when
            # the MDM verdict label changes. On the finish screen (the result
            # view after an erasure) re-render via ui::paint_finish so the
            # completion colour is kept while the Runtime panel (MDM, elapsed,
            # LAN IP) keeps updating instead of freezing.
            prev_status="${MDM_STATUS:-}"
            mdm::sync_state
            if [[ "${MDM_STATUS:-}" != "$prev_status" ]]; then
                if [[ "$on_finish" -eq 1 ]]; then
                    ui::paint_finish
                else
                    table::render
                fi
            else
                ui::tick_inplace || true
            fi
            continue
        elif (( rc != 0 )); then
            # Read failed (EOF / no tty) — leave triage.
            break
        fi

        if [[ "$key" == $'\e' ]]; then
            IFS= read -rsn2 -t 0.05 k2 < /dev/tty 2>/dev/null
            key="$key$k2"
        fi

        case "$key" in
            T|t)
                # Enter the erasure workflow; return seamlessly afterwards. The
                # finish screen stays up as the result view (erasure::run sets
                # TRIAGE_MODE so its footer carries the triage key legend) with
                # its report summary + drive guidance intact — Shift+T re-erases,
                # R/S power off, Esc exits. The elapsed timer keeps ticking.
                TRIAGE_MODE=0
                erasure::run
                # erasure::run leaves either the result view (finish screen,
                # theme 1/2/3 — kept up, re-rendered only when the MDM cell
                # changes so the panel stays live) or, after a selection abort
                # (return 2), the re-rendered triage screen — resume normal
                # MDM re-renders in that case.
                if [[ $? -eq 2 ]]; then
                    on_finish=0
                else
                    on_finish=1
                fi
                if [[ -t 1 ]] && [[ -n "${TERM:-}" ]] && [[ "${TERM:-}" != "dumb" ]]; then
                    UI_INPLACE=1
                fi
                ui::cursor_hide
                ;;
            R|r) TRIAGE_MODE=0; select::reboot; return 0 ;;
            S|s) TRIAGE_MODE=0; select::shutdown; return 0 ;;
            $'\e'|q|Q) break ;;
        esac
    done

    TRIAGE_MODE=0
    return 0
}


