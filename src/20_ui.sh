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

ui::coc_prompt() {
    ui::cursor_show
    printf "\n"

    while :; do
        printf "%sEnter Chain of Custody ID (exactly 5 digits): " "$TABLE_INDENT"
        read -r COCID < /dev/tty

        # Trim whitespace before validation
        COCID="$(printf "%s" "$COCID" | xargs)"

        if cocid::is_valid "$COCID"; then
            export COCID
            ui::cursor_hide
            return
        fi

        printf "%s[!] Invalid COCID. It must be exactly 5 digits (00000-99999), blank is not allowed.\n" "$TABLE_INDENT"
    done
}

ui::show_finish_green() {
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

# Blocking decision prompt shown after sanitization completes.
# Holds the terminal so the caller (e.g. ShredOS) does not paint over the
# final report. Offers Reboot / Shutdown / Continue.
ui::post_run_prompt() {
    local key theme=""

    ui::cursor_show

    # Match the finish theme so the prompt blends into the previously painted
    # completion screen (green on success, red if any drive failed). \033[K
    # paints each line's full width with the chosen background.
    if ui::terminal_controls_supported; then
        if [[ "${UI_COMPLETE_THEME:-1}" -eq 2 ]]; then
            theme="\033[0;41;37m"
        else
            theme="\033[0;42;30m"
        fi
    fi

    while :; do
        printf "\n${theme}\033[K%s\033[1m[R]\033[22m Reboot    \033[1m[S]\033[22m Shutdown    \033[1m[C]\033[22m Continue (start nwipe)    \033[1m[A]\033[22m Run tScrub again\n" "$TABLE_INDENT"
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

