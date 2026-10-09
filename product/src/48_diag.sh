#!/usr/bin/env bash

# =============================================================================
# HARDWARE DIAGNOSTICS SUITE — turn boot-time hardware capture into a small
# suite of PASS/FAIL tests whose verdicts land in the diagnostics snapshot
# (JSON "diagnostics" array + "diagnostics_summary" string).
#
# Opt-in (--diag / tscrub_diag=1). Tier 1 (automatic) runs unattended at boot;
# Tier 2 (guided: display/keyboard/touchpad/USB/speaker/mic) runs interactively
# from the triage screen (Shift+D) and is stubbed here until Phase B.
#
# Verdict vocabulary: PASS | FAIL | SKIP | UNSUP | N/A. Results are recorded
# through diag::record into DIAG_ENTRIES (ordered list) and serialised by
# diag::json / diag::summary. Webcam is presence-only (uvcvideo deferred); RAM
# is a "memory recognised" check, not a stress (memtester declined).
# =============================================================================

# --- test-overridable sources (mirror 46_hardware.sh / battery::capture) -----
DIAG_MEMINFO_FILE="${DIAG_MEMINFO_FILE:-/proc/meminfo}"
DIAG_BAT_DIR="${DIAG_BAT_DIR:-/sys/class/power_supply}"
DIAG_NET_DIR="${DIAG_NET_DIR:-/sys/class/net}"
DIAG_ASOUND_CARDS_FILE="${DIAG_ASOUND_CARDS_FILE:-/proc/asound/cards}"
DIAG_VIDEO_GLOB="${DIAG_VIDEO_GLOB:-/dev/video*}"
DIAG_USB_DIR="${DIAG_USB_DIR:-/sys/bus/usb/devices}"

# --- state -------------------------------------------------------------------
DIAG_ENTRIES=()      # ordered "test|verdict|detail" list
declare -Ag DIAG_LOOKUP   # id -> "verdict|detail" for the renderer
DIAG_RESULTS=""      # flat "; "-joined "test=verdict:detail" (log/human)
DIAG_RUN=0 DIAG_PASS=0 DIAG_FAIL=0 DIAG_SKIP=0 DIAG_UNSUP=0 DIAG_NA=0
DIAG_ORDER=(cpu ram storage network battery peripherals webcam display keyboard touchpad usb speaker mic)
DIAG_GUIDED_TIMEOUT_SECS="${DIAG_GUIDED_TIMEOUT_SECS:-15}"
DIAG_MIC_PEAK_THRESHOLD="${DIAG_MIC_PEAK_THRESHOLD:-400}"
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

# --- Tier 1 (automatic) -----------------------------------------------------

diag::cpu() {
    selftest::cpu
    if [[ "$SELFTEST_CPU" == "PASS" ]]; then
        diag::record cpu PASS "sum-of-squares ok"
    else
        diag::record cpu FAIL "sum-of-squares mismatch"
    fi
}

# Memory-recognised, not a stress (memtester declined): total RAM from
# /proc/meminfo (kB) rounded to GB.
diag::ram() {
    local kb
    kb="$(awk '/^MemTotal:/ {print $2}' "$DIAG_MEMINFO_FILE" 2>/dev/null | head -n 1)"
    if [[ -n "$kb" ]] && [[ "$kb" =~ ^[0-9]+$ ]]; then
        # Round to the nearest power-of-2 GB, matching the panel's SYS_RAM_GB
        # (MemTotal excludes hardware-reserved memory, so a "16 GB" DIMM set
        # reports ~15.4 GB — the two surfaces must agree).
        diag::record ram PASS "memory recognised: $(awk -v kb="$kb" 'BEGIN {
            val = (kb * 1024) / 1000000000
            p = 1; while (p * 2 < val) p *= 2
            if (val - p >= p * 2 - val) p = p * 2
            printf "%d", p
        }') GB"
    else
        diag::record ram N/A "no memory info"
    fi
}

# Storage short self-test, aggregated across every discovered drive. Wraps the
# existing selftest::storage_* modules (legacy devrow[selftest_run] kept).
diag::storage() {
    local dev v n=0 any_pass=0 any_fail=0 any_unknown=0 any_unsup=0
    for dev in "${devices[@]}"; do
        n=$((n + 1))
        if [[ "$dev" == nvme* ]]; then
            selftest::storage_nvme "$dev"
        elif [[ "$dev" == sd* ]]; then
            selftest::storage_ata "$dev"
        else
            devrow["$dev.selftest_run"]="UNSUP"
        fi
        v="${devrow[$dev.selftest_run]:-UNSUP}"
        case "$v" in
            PASS)    any_pass=1 ;;
            FAIL)    any_fail=1 ;;
            UNKNOWN) any_unknown=1 ;;
            *)       any_unsup=1 ;;
        esac
    done
    if [[ "$n" -eq 0 ]]; then
        diag::record storage N/A "no drives discovered"
    elif [[ "$any_fail" -eq 1 ]]; then
        diag::record storage FAIL "a drive short self-test failed"
    elif [[ "$any_pass" -eq 1 && "$any_unknown" -eq 0 && "$any_unsup" -eq 0 ]]; then
        diag::record storage PASS "all drives passed short self-test"
    elif [[ "$any_pass" -eq 1 ]]; then
        diag::record storage SKIP "some drives inconclusive"
    elif [[ "$any_unknown" -eq 1 ]]; then
        diag::record storage SKIP "short self-test inconclusive"
    else
        diag::record storage UNSUP "no storage self-test tooling"
    fi
}

# Link probe: a hardware-backed NIC with carrier. No carrier is ambiguous
# (cable/sink unplugged vs dead port), so it is SKIP rather than FAIL.
diag::network() {
    local d name found=0 carrier=0
    for d in "$DIAG_NET_DIR"/*/; do
        [[ -e "${d}device" ]] || continue
        name="${d%/}"; name="${name##*/}"
        case "$name" in lo|sit*|tun*|tap*|veth*|br*|bond*|docker*|virbr*|vlan*|gre*|ip6tnl*) continue ;; esac
        found=1
        [[ "$(cat "${d}carrier" 2>/dev/null)" == "1" ]] && carrier=1
    done
    if [[ "$found" -eq 0 ]]; then
        diag::record network N/A "no hardware NIC"
    elif [[ "$carrier" -eq 1 ]]; then
        # Carrier alone isn't a usable link — require an IPv4 default route.
        if command -v ip >/dev/null 2>&1 && ip route 2>/dev/null | grep -q '^default'; then
            diag::record network PASS "link up · default route present"
        else
            diag::record network SKIP "link up · no IPv4 route"
        fi
    else
        diag::record network SKIP "no link — cable/sink not connected"
    fi
}

# Battery presence + the already-captured health string. A full charge/discharge
# drain test is hours long and out of scope at boot.
diag::battery() {
    local bat found=0
    for bat in "$DIAG_BAT_DIR"/BAT*; do
        [[ -d "$bat" ]] || continue
        found=1
        break
    done
    if [[ "$found" -eq 0 ]]; then
        diag::record battery N/A "no battery"
    elif [[ -n "${SYS_BATTERY:-}" ]]; then
        diag::record battery PASS "${SYS_BATTERY}"
    else
        diag::record battery PASS "battery present"
    fi
}

# Peripheral presence (fingerprint/CMOS/accelerometer/…) from the static capture.
diag::peripherals() {
    if [[ -n "${SYS_PERIPHERALS:-}" ]] && [[ "${SYS_PERIPHERALS:-}" != "N/A" ]]; then
        # "webcam:1" means a peripheral is actually present; an all-zero
        # capture (webcam:0; touchscreen:0; …) is "none detected", not PASS.
        if [[ "${SYS_PERIPHERALS:-}" == *":1"* ]]; then
            diag::record peripherals PASS "${SYS_PERIPHERALS}"
        else
            diag::record peripherals N/A "no peripherals detected"
        fi
    else
        diag::record peripherals N/A "no peripheral capture"
    fi
}

# Webcam: functional frame-grab is deferred (no uvcvideo in the image), so this
# is presence-only from the static USB/peripherals capture.
diag::webcam() {
    # Match the VALUE (webcam:1), not the key — SYS_PERIPHERALS always carries
    # a "webcam:N" field, so matching "webcam" reported a camera on machines
    # with none (webcam:0).
    if [[ "${SYS_USB_LIST:-}" == *amera* || "${SYS_PERIPHERALS:-}" == *webcam:1* ]]; then
        diag::record webcam UNSUP "camera present · functional test pending"
    else
        diag::record webcam N/A "no camera"
    fi
}

# --- Tier 2 (guided) --------------------------------------------------------
diag::mode_of() {
    case "$1" in
        cpu|ram|storage|network|battery|peripherals|webcam) printf 'auto' ;;
        *) printf 'guided' ;;
    esac
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
    printf '%s  [Y=pass / N=fail / S=skip]\n' "$label" > "$DIAG_TTY_FILE" 2>/dev/null || true
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
    # Print an operator instruction on a clear line below the test list. Used by
    # the keyboard test, which runs directly (stdout is the console) rather than
    # inside diag::_guided's command substitution.
    if (( DIAG_HEADER_ROW > 0 )); then
        printf '\033[%d;1H\033[K%s' "$((DIAG_HEADER_ROW + 18))" "${1:-}"
    else
        printf '%s\n' "${1:-}"
    fi
}
diag::keyboard() {
    local key count=0
    if ! { : < "$DIAG_TTY_FILE"; } 2>/dev/null; then
        diag::record keyboard SKIP "operator skipped"
        return 0
    fi
    # Pressing every key IS the test — consume each keypress without mistaking
    # it for the verdict; only Y/N/S ends the test.
    diag::_instruct "Press every key — received: $count — then Y=pass / N=fail / S=skip"
    # Redirect on the LOOP (not the read) so the file/terminal is opened once
    # and each read advances the position — a `< file` on the read reopens it
    # every iteration (and on a regular file that re-reads the first byte).
    while IFS= read -rsn1 -t "$DIAG_GUIDED_TIMEOUT_SECS" key 2>/dev/null; do
        case "$key" in
            y|Y) diag::record keyboard PASS "operator PASS ($count keys)" ; return 0 ;;
            n|N) diag::record keyboard FAIL "operator reported failure" ; return 0 ;;
            s|S) diag::record keyboard SKIP "operator skipped" ; return 0 ;;
            *) count=$((count+1)); diag::_instruct "Press every key — received: $count — then Y=pass / N=fail / S=skip" ;;
        esac
    done < "$DIAG_TTY_FILE"
    diag::record keyboard SKIP "operator skipped"
}
diag::touchpad() { diag::_guided touchpad "Move the pointer / touch the screen" "operator PASS"; }
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
    local key n prev waited=0
    if ! { : < "$DIAG_TTY_FILE"; } 2>/dev/null; then
        diag::record usb SKIP "operator skipped"
        return 0
    fi
    exec {DIAG_USB_FD}<"$DIAG_TTY_FILE" 2>/dev/null \
        || { diag::record usb SKIP "operator skipped"; return 0; }
    prev="$(diag::_usb_device_count)"
    diag::_instruct "Plug a stick into each USB port — devices: $prev — then Y=pass / N=fail / S=skip"
    while (( waited < DIAG_GUIDED_TIMEOUT_SECS )); do
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
        fi
        waited=$((waited+1))
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
    # and raise the playback path before sounding the tone.
    diag::speaker_unmute
    # Play a 1 kHz tone while the operator listens, then confirm.
    speaker-test -t sine -f 1000 -l 1 >/dev/null 2>&1 &
    local pid=$!
    # Detach the tone from the job table so bash doesn't print a "Killed"
    # notification on the console when we stop it.
    disown "$pid" 2>/dev/null || true
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
# (ALSA comes up with Master muted at 0%) actually emits the speaker tone.
# No-op when amixer is absent; never fails the test on its own. Playback
# switches use the `unmute` verb (the capture `cap` verb is rejected here).
diag::speaker_unmute() {
    command -v amixer >/dev/null 2>&1 || return 0
    local ctl
    while IFS= read -r ctl; do
        [[ -n "$ctl" ]] || continue
        amixer -q sset "$ctl" unmute 2>/dev/null || true
        amixer -q sset "$ctl" 100% 2>/dev/null || true
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

# Run the whole suite with live row repaint. The fast automatic tier runs first,
# then the operator (guided) tests — so the operator never waits on the slow
# storage short self-test before the guided tests become available. Storage runs
# LAST and is flagged "running" before it starts so the console never looks
# frozen. Used by the interactive start key and diag::guided's headless fallback.
diag::_run_suite() {
    diag::cpu;         diag::paint_row cpu
    diag::ram;         diag::paint_row ram
    diag::network;     diag::paint_row network
    diag::battery;     diag::paint_row battery
    diag::peripherals; diag::paint_row peripherals
    diag::webcam;      diag::paint_row webcam
    diag::display;     diag::paint_row display
    diag::keyboard;    diag::paint_row keyboard
    diag::touchpad;    diag::paint_row touchpad
    diag::usb;         diag::paint_row usb
    diag::speaker;     diag::paint_row speaker
    diag::mic;         diag::paint_row mic
    diag::paint_row storage "..." "running short self-tests..."
    diag::storage;     diag::paint_row storage
}

# Guided suite (Shift+D from the triage screen): repaint the middle band as the
# diagnostics screen IMMEDIATELY and notify the operator, but do not run any
# tests until the operator presses Shift+D again (Esc/q returns to triage). The
# caller re-pushes the snapshot so the dashboard sees the results.
diag::guided() {
    diag::_init

    # Headless: nothing to notify or confirm — run the full suite directly so
    # unattended boots still record all 13 tests.
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
# Automatic tier: run everything that needs no operator, record the rest as a
# full suite. Called at boot when --diag / tscrub_diag=1 is set, BEFORE the
# background registration so the results land in the diagnostics snapshot.
diag::run() {
    diag::_init
    diag::cpu
    diag::ram
    diag::storage
    diag::network
    diag::battery
    diag::peripherals
    diag::webcam
}
