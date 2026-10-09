#!/usr/bin/env bash

# =============================================================================
# HARDWARE DIAGNOSTICS SUITE — operator-guided component tests (display,
# keyboard, touchpad, USB, speaker, mic) whose verdicts land in the diagnostics
# snapshot (JSON "diagnostics" array + "diagnostics_summary" string).
#
# Entered from the triage screen with Shift+D. The automatic tier
# (CPU/RAM/storage/network/battery/peripherals/webcam) has been removed — the
# suite is guided-only.
#
# Verdict vocabulary: PASS | FAIL | SKIP | UNSUP | N/A. Results are recorded
# through diag::record into DIAG_ENTRIES (ordered list) and serialised by
# diag::json / diag::summary.
# =============================================================================

# --- test-overridable sources (mirror 46_hardware.sh / battery::capture) -----
DIAG_ASOUND_CARDS_FILE="${DIAG_ASOUND_CARDS_FILE:-/proc/asound/cards}"
DIAG_USB_DIR="${DIAG_USB_DIR:-/sys/bus/usb/devices}"
DIAG_INPUT_DEVICES_FILE="${DIAG_INPUT_DEVICES_FILE:-/proc/bus/input/devices}"

# --- state -------------------------------------------------------------------
DIAG_ENTRIES=()      # ordered "test|verdict|detail" list
declare -Ag DIAG_LOOKUP   # id -> "verdict|detail" for the renderer
DIAG_RESULTS=""      # flat "; "-joined "test=verdict:detail" (log/human)
DIAG_RUN=0 DIAG_PASS=0 DIAG_FAIL=0 DIAG_SKIP=0 DIAG_UNSUP=0 DIAG_NA=0
DIAG_ORDER=(display keyboard touchpad usb speaker mic)
DIAG_GUIDED_TIMEOUT_SECS="${DIAG_GUIDED_TIMEOUT_SECS:-15}"
DIAG_MIC_PEAK_THRESHOLD="${DIAG_MIC_PEAK_THRESHOLD:-400}"
# Playback level for the speaker tone (percent). 100% is uncomfortably loud for
# an operator, so default to a gentler 50.
DIAG_SPEAKER_VOLUME="${DIAG_SPEAKER_VOLUME:-50}"
# Console device the guided prompts talk to (overridable in tests). Defaults to
# the controlling terminal; headless runs have none, so probing it fails fast.
DIAG_TTY_FILE="${DIAG_TTY_FILE:-/dev/tty}"
DIAG_HEADER_ROW=0

# --- helpers ----------------------------------------------------------------
# Collapse whitespace + strip commas/pipes/control chars so a detail string
# never breaks the flat result list or the JSON payload.
diag::_clean() {
    printf '%s' "${1:-}" | tr -d ',|' | tr -d '\000-\037\177' \
        | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/[[:space:]][[:space:]]*/ /g'
}

diag::_init() {
    DIAG_ENTRIES=()
    DIAG_LOOKUP=()
    DIAG_RESULTS=""
    DIAG_HEADER_ROW=0
    DIAG_RUN=0; DIAG_PASS=0; DIAG_FAIL=0; DIAG_SKIP=0; DIAG_UNSUP=0; DIAG_NA=0
}

# Record one test result. Verdict must be one of PASS|FAIL|SKIP|UNSUP|N/A.
diag::record() {
    local test="$1" verdict="$2" detail
    detail="$(diag::_clean "${3:-}")"
    DIAG_ENTRIES+=("${test}|${verdict}|${detail}")
    DIAG_LOOKUP["$test"]="${verdict}|${detail}"
    DIAG_RESULTS="${DIAG_RESULTS}${DIAG_RESULTS:+; }${test}=${verdict}:${detail}"
    case "$verdict" in
        PASS)  DIAG_PASS=$((DIAG_PASS + 1)) ;;
        FAIL)  DIAG_FAIL=$((DIAG_FAIL + 1)) ;;
        SKIP)  DIAG_SKIP=$((DIAG_SKIP + 1)) ;;
        UNSUP) DIAG_UNSUP=$((DIAG_UNSUP + 1)) ;;
        N/A)   DIAG_NA=$((DIAG_NA + 1)) ;;
    esac
    DIAG_RUN=$((DIAG_RUN + 1))
}

# Serialise the recorded results as a JSON array (no trailing newline).
diag::json() {
    local e first=1 test verdict detail rest
    printf '['
    for e in "${DIAG_ENTRIES[@]}"; do
        test="${e%%|*}"
        rest="${e#*|}"
        verdict="${rest%%|*}"
        detail="${rest#*|}"
        [[ "$first" -eq 1 ]] && first=0 || printf ','
        printf '{"test":"%s","verdict":"%s","detail":"%s"}' \
            "$(report::_json_field "$test")" \
            "$(report::_json_field "$verdict")" \
            "$(report::_json_field "$detail")"
    done
    printf ']'
}

# Human-readable summary (ASCII only — travels in the JSON snapshot).
diag::summary() {
    printf '%d run, %d passed, %d failed, %d skipped, %d unsupported' \
        "$DIAG_RUN" "$DIAG_PASS" "$DIAG_FAIL" "$DIAG_SKIP" "$DIAG_UNSUP"
}

# --- Guided tests ------------------------------------------------------------
diag::mode_of() {
    # Every remaining test is operator-guided.
    printf 'guided'
}

# Colour wash for the display test: cycle the console background through a few
# solid colours then restore blue. No-op when stdout isn't a terminal.
diag::wash() {
    [[ -t 1 ]] || return 0
    local c
    for c in 41 42 44 47; do
        printf "\033[0;${c};37m\033[2J\033[H"
        sleep 1
    done
    printf "\033[0;44;37m\033[2J\033[H"
}

# Operator prompt on the console: Y=pass, N=fail, S=skip, anything else/timeout
# -> skip. Headless -> skip. Overridable in tests.
diag::prompt() {
    local label="$1" key
    # Headless detection must probe the console itself, NOT stdout: diag::_guided
    # captures the answer via `ans="$(diag::prompt …)"`, which turns stdout into
    # a pipe — so `[[ -t 1 ]]` (ui::terminal_controls_supported) is FALSE here
    # even on the console, and the prompt was skipped without ever being shown.
    if ! { : < "$DIAG_TTY_FILE"; } 2>/dev/null; then
        printf 'skip\n'
        return 0
    fi
    # Show the instruction on a clear line below the test list (never mid-list).
    if (( DIAG_HEADER_ROW > 0 )); then
        printf '\033[%d;1H\033[K' "$((DIAG_HEADER_ROW + 18))" > "$DIAG_TTY_FILE" 2>/dev/null || true
    fi
    printf '%s%s  [Y=pass / N=fail / S=skip]\n' "${TABLE_INDENT:-}" "$label" > "$DIAG_TTY_FILE" 2>/dev/null || true
    IFS= read -rsn1 -t "$DIAG_GUIDED_TIMEOUT_SECS" key < "$DIAG_TTY_FILE" 2>/dev/null
    case "$key" in
        y|Y) printf 'pass\n' ;;
        n|N) printf 'fail\n' ;;
        s|S) printf 'skip\n' ;;
        *)   printf 'skip\n' ;;
    esac
}

# Run one operator-confirmed test.
diag::_guided() {
    local id="$1" label="$2" pass_detail="${3:-operator PASS}" ans
    ans="$(diag::prompt "$label")"
    case "$ans" in
        pass) diag::record "$id" PASS "$pass_detail" ;;
        fail) diag::record "$id" FAIL "operator reported failure" ;;
        *)    diag::record "$id" SKIP "operator skipped" ;;
    esac
}

diag::display() {
    diag::wash
    # The colour wash blanks the whole screen — restore the panels + test list
    # before asking the operator for a verdict.
    if ui::terminal_controls_supported; then
        table::render
        diag::render
    fi
    diag::_guided display "Display colour wash — any dead pixels?" "operator PASS"
}
diag::_instruct() {
    # Print an operator instruction on a clear line below the test list, at the
    # table's left indent so it lines up with the list above. Used by the
    # keyboard test, which runs directly (stdout is the console) rather than
    # inside diag::_guided's command substitution.
    if (( DIAG_HEADER_ROW > 0 )); then
        printf '\033[%d;1H\033[K%s%s' "$((DIAG_HEADER_ROW + 18))" "${TABLE_INDENT:-}" "${1:-}"
    else
        printf '%s\n' "${1:-}"
    fi
}
# Clear the instruction line (row DIAG_HEADER_ROW + 18) so a stale prompt never
# lingers while a non-interactive test (e.g. the storage self-test) runs.
diag::_instruct_clear() {
    if (( DIAG_HEADER_ROW > 0 )); then
        printf '\033[%d;1H\033[K' "$((DIAG_HEADER_ROW + 18))"
    fi
}
# Append a printable key to the "seen" set (distinct, first-press order). The
# char-by-char compare is deliberate: interpolating $ch into a case/[[ ]] glob
# would treat * ? [ as metacharacters.
diag::_seen_add() {
    local seen="$1" ch="$2" i
    [[ -n "$ch" ]] || { printf '%s' "$seen"; return 0; }
    for (( i=0; i < ${#seen}; i++ )); do
        [[ "${seen:i:1}" == "$ch" ]] && { printf '%s' "$seen"; return 0; }
    done
    printf '%s%s' "$seen" "$ch"
}

# Two-phase keyboard test. Phase 1: the operator mashes keys while a live
# "seen" readout shows the distinct printable keys registered. Y/N/S are just
# ordinary keys here — only a bare Esc ends the mash (Esc followed by [ or O is
# an arrow/function key, whose continuation bytes we drain so they don't leak
# as keypresses). Phase 2: a separate Y/N/S verdict, so the answer is always
# deliberate.
diag::keyboard() {
    local key ch o seen="" special=0 count=0 ans
    if ! { : < "$DIAG_TTY_FILE"; } 2>/dev/null; then
        diag::record keyboard SKIP "operator skipped"
        return 0
    fi
    diag::_instruct "Press every key — seen: (none) — Esc to finish"
    # Open the console once; each read advances the position (a `< file` on the
    # read reopens it every iteration and re-reads the first byte).
    exec {DIAG_KB_FD}<"$DIAG_TTY_FILE" 2>/dev/null \
        || { diag::record keyboard SKIP "operator skipped"; return 0; }
    while IFS= read -rsn1 -t "$DIAG_GUIDED_TIMEOUT_SECS" -u "$DIAG_KB_FD" key 2>/dev/null; do
        if [[ "$key" == $'\x1b' ]]; then
            if IFS= read -rsn1 -t 0.05 -u "$DIAG_KB_FD" ch 2>/dev/null \
               && [[ "$ch" == '[' || "$ch" == 'O' ]]; then
                # Arrow/function key: drain the rest of its escape sequence.
                while IFS= read -rsn1 -t 0.05 -u "$DIAG_KB_FD" ch 2>/dev/null; do :; done
                special=$((special+1))
            else
                break   # bare Esc = finish mashing
            fi
        elif [[ -n "$key" ]]; then
            o="$(printf '%d' "'$key" 2>/dev/null)"
            if [[ -n "$o" ]] && (( o >= 32 && o <= 126 )); then
                seen="$(diag::_seen_add "$seen" "$key")"
            else
                special=$((special+1))
            fi
        else
            # read -n1 swallowed a line terminator (Enter) — still a keypress.
            special=$((special+1))
        fi
        count=$((count+1))
        diag::_instruct "Press every key — seen: ${seen:-(none)} ($count) — Esc to finish"
    done
    exec {DIAG_KB_FD}<&-

    if (( count == 0 )); then
        diag::record keyboard SKIP "no keys registered"
        diag::_instruct_clear
        return 0
    fi
    # Verdict is a separate phase: the operator is no longer mashing keys, so
    # Y/N/S here is deliberate rather than an accidental keypress.
    ans="$(diag::prompt "Keyboard — $count key(s) captured. Working?")"
    diag::_instruct_clear
    case "$ans" in
        pass) diag::record keyboard PASS "operator PASS ($count keys)" ;;
        fail) diag::record keyboard FAIL "operator reported failure" ;;
        *)    diag::record keyboard SKIP "operator skipped" ;;
    esac
}
diag::touchpad() {
    local ev n
    if ! { : < "$DIAG_TTY_FILE"; } 2>/dev/null; then
        diag::record touchpad SKIP "operator skipped"
        return 0
    fi
    ev="$(diag::_touchpad_device)"
    if [[ -z "$ev" ]]; then
        diag::record touchpad SKIP "no touchpad detected"
        return 0
    fi
    if ui::terminal_controls_supported; then
        diag::_instruct "Move your finger across the touchpad..."
    fi
    n="$(diag::_motion_events "/dev/input/event$ev" 5)"
    if (( n > 0 )); then
        diag::record touchpad PASS "movement detected ($n events)"
    else
        diag::record touchpad FAIL "no touchpad movement detected"
    fi
    diag::_instruct_clear
}
# Find the first touchpad/trackpad pointing device in the input table and echo
# its eventN number (no /dev/input/ prefix). Empty when no touchpad is present.
diag::_touchpad_device() {
    awk '
        /^I:/ { in_tp = 0 }
        /^N: Name=/ {
            name = $0; sub(/^N: Name=/, "", name)
            in_tp = (name ~ /[Tt]ouch[Pp]ad|[Gg]lide[Pp]oint|Synaptics|ALPS/)
            next
        }
        in_tp && /^H: Handlers=/ {
            for (i = 2; i <= NF; i++)
                if ($i ~ /^event/) { e = $i; gsub(/[^0-9]/, "", e); print e; exit }
            in_tp = 0
        }
    ' "$DIAG_INPUT_DEVICES_FILE" 2>/dev/null
}
# Count motion/click events (EV_KEY/EV_REL/EV_ABS with a non-zero value) read
# from an input event device for up to $secs seconds. Echoes the count (0 idle).
# Each struct input_event is 24 bytes: type is the low 16 bits of word 5 and the
# signed value is word 6 (little-endian), so `od -td4 -w24` = one event per line.
diag::_motion_events() {
    local dev="$1" secs="${2:-5}"
    command -v timeout >/dev/null 2>&1 || { printf '0\n'; return 0; }
    timeout "$secs" dd bs=24 count=512 if="$dev" 2>/dev/null \
        | od -An -td4 -w24 2>/dev/null \
        | awk '{ t=$5%65536; v=$6; if(v<0)v=-v; if((t==1||t==2||t==3)&&v>0) n++ } END{ print n+0 }'
}
# Count non-hub USB devices (idVendor present, excluding the Linux Foundation
# root hubs 1d6b) — the "is something plugged in" signal for the USB test.
diag::_usb_device_count() {
    local d vid n=0
    for d in "$DIAG_USB_DIR"/*/; do
        [[ -r "${d}idVendor" ]] || continue
        vid="$(tr -d '\n' < "${d}idVendor" 2>/dev/null)"
        [[ "$vid" == "1d6b" ]] && continue
        n=$((n+1))
    done
    printf '%d' "$n"
}
# USB: the operator plugs a stick while we poll for new devices and show live
# feedback, then confirms with Y/N/S. Open the console once (so the position
# advances across reads) while re-checking the device count between keypresses.
diag::usb() {
    local key n prev
    if ! { : < "$DIAG_TTY_FILE"; } 2>/dev/null; then
        diag::record usb SKIP "operator skipped"
        return 0
    fi
    exec {DIAG_USB_FD}<"$DIAG_TTY_FILE" 2>/dev/null \
        || { diag::record usb SKIP "operator skipped"; return 0; }
    prev="$(diag::_usb_device_count)"
    diag::_instruct "Plug a stick into each USB port — devices: $prev — then Y=pass / N=fail / S=skip"
    # Stay on this test until the operator answers Y/N/S (no time limit); poll
    # the device count each second so a freshly-plugged stick is announced.
    while :; do
        n="$(diag::_usb_device_count)"
        if [[ "$n" != "$prev" ]]; then
            diag::_instruct "Device detected — USB devices: $n — plug into each port, then Y=pass / N=fail / S=skip"
            prev="$n"
        fi
        IFS= read -rsn1 -t 1 -u "$DIAG_USB_FD" key 2>/dev/null
        if (( $? == 0 )); then
            case "$key" in
                y|Y) exec {DIAG_USB_FD}<&-; diag::record usb PASS "operator PASS" ; return 0 ;;
                n|N) exec {DIAG_USB_FD}<&-; diag::record usb FAIL "operator reported failure" ; return 0 ;;
                s|S) exec {DIAG_USB_FD}<&-; diag::record usb SKIP "operator skipped" ; return 0 ;;
            esac
        elif [[ ! -t "$DIAG_USB_FD" ]]; then
            # Non-terminal input (test fixture) at EOF — stop polling.
            break
        fi
    done
    exec {DIAG_USB_FD}<&-
    diag::record usb SKIP "operator skipped"
}
diag::speaker() {
    if ! command -v speaker-test >/dev/null 2>&1; then
        diag::record speaker UNSUP "no ALSA tools"
        return 0
    fi
    # A freshly-booted image brings ALSA up muted (Master off at 0%), so unmute
    # and raise the playback path before sounding the tone — at a reduced level
    # so the 1 kHz sine doesn't blast the operator.
    diag::speaker_unmute "$DIAG_SPEAKER_VOLUME"
    # Play a 1 kHz tone continuously while the operator listens, then confirm.
    # -l 0 loops forever (a single loop can be too short to hear on some
    # hardware); we kill it once the operator has answered.
    speaker-test -t sine -f 1000 -l 0 >/dev/null 2>&1 &
    local pid=$!
    # Detach the tone from the job table so bash doesn't print a "Killed"
    # notification on the console when we stop it.
    disown "$pid" 2>/dev/null || true
    sleep 1   # let ALSA open the device so the tone is audible before the prompt
    diag::_guided speaker "Tone playing — heard it?" "tone confirmed · operator PASS"
    kill "$pid" 2>/dev/null || true
    kill -9 "$pid" 2>/dev/null || true
}
# Peak sample amplitude (16-bit signed LE) — the "is it silent" signal for the
# mic test. Pure and unit-testable.
diag::mic_peak() {
    local f="$1"
    od -An -td2 "$f" 2>/dev/null | awk '{for(i=1;i<=NF;i++){v=$i;if(v<0)v=-v;if(v>m)m=v}}END{print m+0}'
}
# Best-effort: unmute and boost the capture input so a freshly-booted image
# (ALSA comes up muted) can actually hear the microphone. No-op when amixer is
# absent; never fails the test on its own. Capture switches use the `cap` verb
# (`unmute`/`on` are rejected by this alsa-utils build).
diag::mic_unmute() {
    command -v amixer >/dev/null 2>&1 || return 0
    local ctl
    while IFS= read -r ctl; do
        [[ -n "$ctl" ]] || continue
        amixer -q sset "$ctl" cap 2>/dev/null || true
        amixer -q sset "$ctl" 100% 2>/dev/null || true
    done < <(amixer scontrols 2>/dev/null | sed -n "s/.*'\(.*\)'.*/\1/p" | grep -iE 'capture|mic')
}

# Best-effort: unmute and raise the playback controls so a freshly-booted image
# (ALSA comes up with Master muted at 0%) actually emits the speaker tone. Takes
# an optional volume percent (default 100). No-op when amixer is absent; never
# fails the test on its own. Playback switches use the `unmute` verb (the
# capture `cap` verb is rejected here).
diag::speaker_unmute() {
    command -v amixer >/dev/null 2>&1 || return 0
    local ctl vol="${1:-100}"
    while IFS= read -r ctl; do
        [[ -n "$ctl" ]] || continue
        amixer -q sset "$ctl" unmute 2>/dev/null || true
        amixer -q sset "$ctl" "${vol}%" 2>/dev/null || true
    done < <(amixer scontrols 2>/dev/null | sed -n "s/.*'\(.*\)'.*/\1/p" | grep -iE 'master|speaker|headphone|pcm')
}

diag::mic() {
    if ! command -v arecord >/dev/null 2>&1; then
        diag::record mic UNSUP "no ALSA tools"
        return 0
    fi
    diag::mic_unmute
    if ui::terminal_controls_supported; then
        diag::_instruct "Speak into the microphone..."
    fi
    # Bound the record so a wedged ALSA device can't hang the suite.
    smart::run 15 arecord -q -d 3 -t raw -f S16_LE -r 16000 /tmp/tscrub-mic.raw 2>/dev/null
    local peak
    peak="$(diag::mic_peak /tmp/tscrub-mic.raw)"
    rm -f /tmp/tscrub-mic.raw
    diag::_instruct_clear
    if [[ -n "$peak" ]] && [[ "$peak" =~ ^[0-9]+$ ]] && (( peak >= DIAG_MIC_PEAK_THRESHOLD )); then
        diag::record mic PASS "speech captured · peak $peak"
    else
        diag::record mic FAIL "capture silent (peak ${peak:-0})"
    fi
}

# One rendered test row (no indent, no newline): name, mode, coloured verdict,
# detail.
diag::entry_line() {
    local id="$1" ov="${2:-}" od="${3:-}" rec verdict detail esc="" reset=""
    if [[ -n "$ov" ]]; then
        verdict="$ov"
        detail="$od"
    else
        rec="${DIAG_LOOKUP[$id]:-}"
        if [[ -n "$rec" ]]; then
            verdict="${rec%%|*}"
            detail="${rec#*|}"
        else
            verdict="..."
            detail="pending"
        fi
    fi
    if [[ -t 1 ]]; then
        case "$verdict" in
            PASS) esc=$'\033[1;32m' ;;
            FAIL) esc=$'\033[1;31m' ;;
            SKIP) esc=$'\033[1;33m' ;;
            *)    esc=$'\033[2m' ;;
        esac
        reset=$'\033[0m'
    fi
    printf '%-14s %-7s %s%-5s%s %s' "$id" "$(diag::mode_of "$id")" "$esc" "$verdict" "$reset" "$detail"
}

# Overwrite the middle band (the drive table) with the diagnostics test list,
# keeping the info panels above. Requires a prior table::render so the panels
# and layout are already on screen. No-op headless.
diag::render() {
    [[ -t 1 ]] || return 0
    local cpu_rows=0 gpu_rows=0 l id
    while IFS= read -r l; do [[ -z "$l" ]] && continue; cpu_rows=$((cpu_rows+1)); done <<< "${SYS_CPU_LIST:-}"
    (( cpu_rows == 0 )) && cpu_rows=1
    while IFS= read -r l; do [[ -z "$l" ]] && continue; gpu_rows=$((gpu_rows+1)); done <<< "${SYS_GPU_LIST:-}"
    (( gpu_rows == 0 )) && gpu_rows=1
    DIAG_HEADER_ROW=$((15 + cpu_rows + gpu_rows))

    printf "\033[%d;1H\033[J" "$DIAG_HEADER_ROW"
    printf "%s%s\n" "$TABLE_INDENT" "$(printf "%*s" "$UI_TABLE_MAIN_W" "" | tr ' ' '-')"
    printf "%s  %-14s %-7s %-5s %s\n" "$TABLE_INDENT" "Test" "Mode" "Result" "Detail"
    printf "%s%s\n" "$TABLE_INDENT" "$(printf "%*s" "$UI_TABLE_MAIN_W" "" | tr ' ' '-')"
    for id in "${DIAG_ORDER[@]}"; do
        printf "%s  %s\n" "$TABLE_INDENT" "$(diag::entry_line "$id")"
    done
    printf "%s%s\n" "$TABLE_INDENT" "$(printf "%*s" "$UI_TABLE_MAIN_W" "" | tr ' ' '-')"
}

# Repaint one test row in place.
diag::paint_row() {
    local id="$1" ov="${2:-}" od="${3:-}" idx=0 o
    [[ "$DIAG_HEADER_ROW" -gt 0 ]] || return 0
    [[ -t 1 ]] || return 0
    for o in "${DIAG_ORDER[@]}"; do
        [[ "$o" == "$id" ]] && break
        idx=$((idx+1))
    done
    printf "\033[%d;1H\033[K%s  %s" "$((DIAG_HEADER_ROW + 3 + idx))" "$TABLE_INDENT" "$(diag::entry_line "$id" "$ov" "$od")"
}

# Run the whole (guided) suite with live row repaint. Used by the interactive
# start key (Shift+D) and diag::guided's headless fallback.
diag::_run_suite() {
    diag::display;     diag::paint_row display
    diag::keyboard;    diag::paint_row keyboard
    diag::touchpad;    diag::paint_row touchpad
    diag::usb;         diag::paint_row usb
    diag::speaker;     diag::paint_row speaker
    diag::mic;         diag::paint_row mic
}

# Guided suite (Shift+D from the triage screen): repaint the middle band as the
# diagnostics screen IMMEDIATELY and notify the operator, but do not run any
# tests until the operator presses Shift+D again (Esc/q returns to triage). The
# caller re-pushes the snapshot so the dashboard sees the results.
diag::guided() {
    diag::_init

    # Headless: nothing to notify or confirm — run the full suite directly so
    # unattended boots still record all 6 tests.
    if ! ui::terminal_controls_supported; then
        diag::_run_suite
        return 0
    fi

    ui::cursor_hide
    UI_COMPLETE_THEME=4
    table::render
    diag::render
    if (( DIAG_HEADER_ROW > 0 )); then
        printf '\033[%d;1H\033[K' "$((DIAG_HEADER_ROW + 18))" > /dev/tty 2>/dev/null || true
    fi
    printf '%s  Diagnostics — %d hardware tests. Press Shift+D to run, Esc to return\n' \
        "$TABLE_INDENT" "${#DIAG_ORDER[@]}" > /dev/tty 2>/dev/null || true

    local key
    while :; do
        IFS= read -rsn1 key < /dev/tty 2>/dev/null || { return 0; }
        case "$key" in
            D|d)
                diag::_run_suite
                # Clear the prompt row before the summary so a stale instruction
                # never lingers below "press any key".
                if (( DIAG_HEADER_ROW > 0 )); then
                    printf '\033[%d;1H\033[K' "$((DIAG_HEADER_ROW + 18))" > /dev/tty 2>/dev/null || true
                fi
                printf '%s  %s\n' "$TABLE_INDENT" "$(diag::summary)" > /dev/tty 2>/dev/null || true
                printf '%s  press any key to return to triage...' "$TABLE_INDENT" > /dev/tty 2>/dev/null || true
                read -rsn1 < /dev/tty 2>/dev/null || true
                return 0
                ;;
            $'\e'|q|Q)
                return 0
                ;;
        esac
    done
}

# --- orchestration ----------------------------------------------------------
# Boot-time entry point (--diag / tscrub_diag=1). No automatic tests remain —
# the suite is guided-only (Shift+D), so this just initialises an empty result
# set. Kept so the boot-time flag continues to parse cleanly.
diag::run() {
    diag::_init
}
