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

    UI_COMPLETE_THEME=1
    UI_INPLACE=0
    table::render
}

