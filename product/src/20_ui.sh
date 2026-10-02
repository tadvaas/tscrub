# =============================================================================
# UI FUNCTIONS
# =============================================================================

ui::terminal_controls_supported() {
    [[ -t 1 ]] || return 1
    [[ -n "${TERM:-}" ]] || return 1
    [[ "${TERM:-}" != "dumb" ]] || return 1
}

ui::cursor_hide() {
    ui::terminal_controls_supported || return 0
    tput civis 2>/dev/null || true
}

ui::cursor_show() {
    ui::terminal_controls_supported || return 0
    tput cnorm 2>/dev/null || true
}

# Animated progress line: a background loop redraws the SAME line in place while
# a synchronous step runs, advancing the shared spinner glyph. Call
# ui::spinner_stop before any other console output; the loop also exits when its
# parent shell dies (so an abnormal exit can't leave an orphan scribbling). A
# no-op when stdout isn't a terminal.
ui::spinner_start() {
    ui::terminal_controls_supported || return 0
    local msg="$1"
    (
        while kill -0 "$PPID" 2>/dev/null; do
            printf "\r\033[K%s %s %s" "$TABLE_INDENT" "$(ui::spinner)" "$msg"
            UI_SPINNER_FRAME=$(( UI_SPINNER_FRAME + 1 ))
            sleep 0.25
        done
    ) &
    SPINNER_PID=$!
}

ui::spinner_stop() {
    if [[ -n "${SPINNER_PID:-}" ]]; then
        kill "$SPINNER_PID" 2>/dev/null || true
        wait "$SPINNER_PID" 2>/dev/null || true
        SPINNER_PID=""
    fi
    ui::terminal_controls_supported && printf "\r\033[K"
}

cocid::is_valid() {
    [[ "$1" =~ ^[0-9]{5}$ ]]
}

# Resolve the Chain of Custody ID. Priority:
#   1. --cocid (set by parse_args)
#   2. tscrub_cocid= on the kernel command line (autonuke — no prompt)
#   3. the interactive prompt
cocid::detect() {
    local param

    if [[ -n "${COCID:-}" ]]; then
        return 0
    fi

    param="$(tr ' ' '\n' < /proc/cmdline 2>/dev/null | sed -nE 's/^tscrub_cocid=//p' | head -n 1)"
    if [[ -n "$param" ]]; then
        param="${param#\"}"
        param="${param%\"}"
        COCID="$(printf "%s" "$param" | xargs)"
        return 0
    fi

    ui::coc_prompt
}

ui::coc_prompt() {
    ui::cursor_show

    local cols rows pad prompt prompt_row
    cols="$(table::detect_terminal_width)"
    rows="$(table::detect_terminal_height)"
    prompt="Enter Chain of Custody ID (exactly 5 digits): "
    pad=$(( (cols - ${#prompt}) / 2 ))
    (( pad < 0 )) && pad=0
    prompt_row=$(( rows / 2 ))

    if [[ -t 1 ]]; then
        clear
    else
        pad=0
    fi

    while :; do
        # Centre the prompt in the middle of the screen; clear the line so a
        # previous (invalid) attempt doesn't leave residue behind.
        [[ -t 1 ]] && printf "\033[%d;1H\033[K" "$prompt_row"
        printf "%*s%s" "$pad" "" "$prompt"
        if ! read -r COCID < /dev/tty 2>/dev/null; then
            printf "\n%*s[!] No interactive terminal — cannot prompt for COCID.\n" "$pad" "" >&2
            exit 1
        fi

        # Trim whitespace before validation
        COCID="$(printf "%s" "$COCID" | xargs)"

        if cocid::is_valid "$COCID"; then
            export COCID
            ui::cursor_hide
            printf "\n"
            return
        fi

        [[ -t 1 ]] && printf "\033[%d;1H\033[K" "$((prompt_row + 1))"
        printf "%*s%s" "$pad" "" "[!] Invalid COCID. It must be exactly 5 digits (00000-99999), blank is not allowed."
    done
}

ui::show_finish_green() {
    local msg="${1:-}"
    [[ -t 1 ]] || return 0
    [[ -n "${TERM:-}" ]] || return 0
    [[ "${TERM:-}" != "dumb" ]] || return 0

    # Red theme if any drive did not complete successfully, green otherwise.
    if ui::any_drive_failed; then
        UI_COMPLETE_THEME=2
    else
        UI_COMPLETE_THEME=1
    fi
    UI_INPLACE=0
    FINISH_MSG="$msg"
    ui::paint_finish
}

# Amber finish: the wipe completed but no report destination succeeded (USB,
# dashboard and/or network all failed). Distinct from green (at least one
# destination delivered, or nothing failed) and red (a drive failed/blocked).
ui::show_finish_orange() {
    local msg="${1:-}"
    [[ -t 1 ]] || return 0
    [[ -n "${TERM:-}" ]] || return 0
    [[ "${TERM:-}" != "dumb" ]] || return 0

    UI_COMPLETE_THEME=3
    UI_INPLACE=0
    FINISH_MSG="$msg"
    ui::paint_finish
}

# Full finish-screen paint: theme + table + the post-erasure footer (result
# message, per-drive guidance, skipped count, report-delivery summary).
# Idempotent — called once by ui::show_finish_* and again by the triage loop
# whenever the MDM cell changes, so the completion screen stays live instead
# of freezing after the last full render.
ui::paint_finish() {
    table::render
    [[ -n "${FINISH_MSG:-}" ]] && printf "\033[K%s%s\n" "$TABLE_INDENT" "$FINISH_MSG"
    ui::print_drive_guidance
    if [[ "$(report::skipped_count)" -gt 0 ]]; then
        printf "\033[K%sWiped %s of %d drive(s); %d skipped (not selected).\n" \
            "$TABLE_INDENT" "$(report::selected_count)" "${#devices[@]}" "$(report::skipped_count)"
    fi
    report::print_summary
}

# Returns success (0) if at least one drive ended in a non-success state
# (anything other than COMPLETED or DRY-RUN).
ui::any_drive_failed() {
    local dev
    for dev in "${devices[@]}"; do
        case "${devrow[$dev.status]}" in
            COMPLETED|DRY-RUN|SKIPPED) ;;
            *) return 0 ;;
        esac
    done
    return 1
}

# Prints one actionable line per drive that did not complete, so a recoverable
# condition (Block SID, frozen) is not mistaken for a hardware fault. Called
# after the finish screen is painted, so \033[K fills each line with the active
# background colour.
ui::print_drive_guidance() {
    local dev status blink_on="" blink_off=""
    if ui::terminal_controls_supported; then
        blink_on=$'\033[5m'   # ANSI blink (slow)
        blink_off=$'\033[25m'
    fi
    for dev in "${devices[@]}"; do
        status="${devrow[$dev.status]:-}"
        case "$status" in
            BLOCKED)
                printf "\033[K%s${blink_on}%s: blocked by firmware (Block SID / access-rights lockdown) — clear Block SID or hard-disk security in BIOS, or move the drive to another machine, then re-run.${blink_off}\n" "$TABLE_INDENT" "$dev"
                ;;
            FROZEN)
                printf "\033[K%s${blink_on}%s: frozen by the host BIOS — power-cycle (or suspend/resume) and re-run.${blink_off}\n" "$TABLE_INDENT" "$dev"
                ;;
            FAILED)
                printf "\033[K%s${blink_on}%s: sanitisation failed — inspect the drive and the log for details.${blink_off}\n" "$TABLE_INDENT" "$dev"
                ;;
        esac
    done
}



