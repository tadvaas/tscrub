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
        NON_INTERACTIVE=1
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
    table::render
    [[ -n "$msg" ]] && printf "\033[K%s%s\n" "$TABLE_INDENT" "$msg"
}

# Amber finish: the wipe completed but the report could not be saved to USB
# and/or uploaded (dashboard/network). Distinct from green (all good) and red
# (a drive failed or was blocked).
ui::show_finish_orange() {
    local msg="${1:-}"
    [[ -t 1 ]] || return 0
    [[ -n "${TERM:-}" ]] || return 0
    [[ "${TERM:-}" != "dumb" ]] || return 0

    UI_COMPLETE_THEME=3
    UI_INPLACE=0
    table::render
    [[ -n "$msg" ]] && printf "\033[K%s%s\n" "$TABLE_INDENT" "$msg"
}

# Returns success (0) if at least one drive ended in a non-success state
# (anything other than COMPLETED or DRY-RUN).
ui::any_drive_failed() {
    local dev
    for dev in "${devices[@]}"; do
        case "${devrow[$dev.status]}" in
            COMPLETED|DRY-RUN) ;;
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

# Blocking decision prompt shown after sanitization completes.
# Holds the terminal so the caller (e.g. the appliance shell) does not paint over the
# final report. Offers Reboot / Shutdown / Continue.
ui::post_run_prompt() {
    local key theme=""

    ui::cursor_show

    # Match the finish theme so the prompt blends into the previously painted
    # completion screen (green on success, red if any drive failed). \033[K
    # paints each line's full width with the chosen background.
    if ui::terminal_controls_supported; then
        case "${UI_COMPLETE_THEME:-1}" in
            2) theme="\033[0;41;37m" ;;   # red: a drive failed/blocked
            3) theme="\033[0;43;30m" ;;   # amber: report save/upload failed
            4) theme="\033[0;44;37m" ;;   # blue: wipe in progress (defensive)
            *) theme="\033[0;42;30m" ;;   # green: all good
        esac
    fi

    while :; do
        printf "\n${theme}\033[K%s\033[1m[R]\033[22m Reboot    \033[1m[S]\033[22m Shutdown    \033[1m[C]\033[22m Continue    \033[1m[A]\033[22m Run tScrub again\n" "$TABLE_INDENT"
        printf "${theme}\033[K%sSelect an option: " "$TABLE_INDENT"

        read -r -n1 key < /dev/tty
        printf "\n"

        case "$key" in
            r|R)
                printf "${theme}\033[K%sRebooting...\n" "$TABLE_INDENT"
                reboot
                return 0
                ;;
            s|S)
                printf "${theme}\033[K%sShutting down...\n" "$TABLE_INDENT"
                poweroff
                return 0
                ;;
            a|A)
                # Reset colors and request another full run of tScrub.
                [[ -n "$theme" ]] && printf "\033[0m"
                printf "%sRestarting tScrub...\n" "$TABLE_INDENT"
                RERUN=1
                return 0
                ;;
            c|C|"")
                # Reset to default colors before handing off to nwipe.
                [[ -n "$theme" ]] && printf "\033[0m"
                return 0
                ;;
            *)
                printf "${theme}\033[K%s[!] Invalid selection. Press R, S, C, or A.\n" "$TABLE_INDENT"
                ;;
        esac
    done
}

