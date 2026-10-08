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

# --- state -------------------------------------------------------------------
DIAG_ENTRIES=()      # ordered "test|verdict|detail" list
declare -Ag DIAG_LOOKUP   # id -> "verdict|detail" for the renderer
DIAG_RESULTS=""      # flat "; "-joined "test=verdict:detail" (log/human)
DIAG_RUN=0 DIAG_PASS=0 DIAG_FAIL=0 DIAG_SKIP=0 DIAG_UNSUP=0 DIAG_NA=0
DIAG_ORDER=(cpu ram storage network battery peripherals webcam display keyboard touchpad usb speaker mic)
DIAG_GUIDED_TIMEOUT_SECS="${DIAG_GUIDED_TIMEOUT_SECS:-15}"
DIAG_MIC_PEAK_THRESHOLD="${DIAG_MIC_PEAK_THRESHOLD:-400}"
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
        diag::record ram PASS "memory recognised: $(( (kb + 524288) / 1048576 )) GB"
    else
        diag::record ram N/A "no memory info"
    fi
}

# Storage short self-test, aggregated across every discovered drive. Wraps the
# existing selftest::storage_* modules (legacy devrow[selftest_run] kept).
diag::storage() {
    local dev v n=0 any_pass=0 any_fail=0 any_other=0
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
            PASS) any_pass=1 ;;
            FAIL) any_fail=1 ;;
            *)    any_other=1 ;;
        esac
    done
    if [[ "$n" -eq 0 ]]; then
        diag::record storage N/A "no drives discovered"
    elif [[ "$any_fail" -eq 1 ]]; then
        diag::record storage FAIL "a drive short self-test failed"
    elif [[ "$any_pass" -eq 1 && "$any_other" -eq 0 ]]; then
        diag::record storage PASS "all drives passed short self-test"
    elif [[ "$any_pass" -eq 1 ]]; then
        diag::record storage SKIP "some drives inconclusive"
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
        diag::record network PASS "link up on a hardware NIC"
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
        diag::record peripherals PASS "${SYS_PERIPHERALS}"
    else
        diag::record peripherals N/A "no peripheral capture"
    fi
}

# Webcam: functional frame-grab is deferred (no uvcvideo in the image), so this
# is presence-only from the static USB/peripherals capture.
diag::webcam() {
    if [[ "${SYS_USB_LIST:-}" == *amera* || "${SYS_PERIPHERALS:-}" == *ebcam* ]]; then
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
    if ! ui::terminal_controls_supported; then
        printf 'skip\n'
        return 0
    fi
    printf '%s  [Y=pass / N=fail / S=skip]\n' "$label" > /dev/tty 2>/dev/null || true
    IFS= read -rsn1 -t "$DIAG_GUIDED_TIMEOUT_SECS" key < /dev/tty 2>/dev/null
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

diag::display()  { diag::wash; diag::_guided display "Display colour wash — any dead pixels?" "operator PASS"; }
diag::keyboard() { diag::_guided keyboard "Press every key, then Y/N/S" "operator PASS"; }
diag::touchpad() { diag::_guided touchpad "Move the pointer / touch the screen, then Y/N/S" "operator PASS"; }
diag::usb()      { diag::_guided usb "Plug a stick into each USB port, then Y/N/S" "operator PASS"; }
diag::speaker() {
    if ! command -v speaker-test >/dev/null 2>&1; then
        diag::record speaker UNSUP "no ALSA tools"
        return 0
    fi
    # Play a 1 kHz tone while the operator listens, then confirm.
    speaker-test -t sine -f 1000 -l 1 >/dev/null 2>&1 &
    local pid=$!
    diag::_guided speaker "Tone playing — heard it? Y/N/S" "tone confirmed · operator PASS"
    kill "$pid" 2>/dev/null || true
}
# Peak sample amplitude (16-bit signed LE) — the "is it silent" signal for the
# mic test. Pure and unit-testable.
diag::mic_peak() {
    local f="$1"
    od -An -td2 "$f" 2>/dev/null | awk '{for(i=1;i<=NF;i++){v=$i;if(v<0)v=-v;if(v>m)m=v}}END{print m+0}'
}
diag::mic() {
    if ! command -v arecord >/dev/null 2>&1; then
        diag::record mic UNSUP "no ALSA tools"
        return 0
    fi
    if ui::terminal_controls_supported; then
        printf 'Speak into the microphone...\n' > /dev/tty 2>/dev/null || true
    fi
    arecord -q -d 3 -t raw -f S16_LE -r 16000 /tmp/tscrub-mic.raw 2>/dev/null
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
    local id="$1" rec verdict detail esc="" reset=""
    rec="${DIAG_LOOKUP[$id]:-}"
    if [[ -n "$rec" ]]; then
        verdict="${rec%%|*}"
        detail="${rec#*|}"
    else
        verdict="…"
        detail="pending"
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
    local id="$1" idx=0 o
    [[ "$DIAG_HEADER_ROW" -gt 0 ]] || return 0
    [[ -t 1 ]] || return 0
    for o in "${DIAG_ORDER[@]}"; do
        [[ "$o" == "$id" ]] && break
        idx=$((idx+1))
    done
    printf "\033[%d;1H\033[K%s  %s" "$((DIAG_HEADER_ROW + 3 + idx))" "$TABLE_INDENT" "$(diag::entry_line "$id")"
}

# Guided suite: automatic tier, then the operator tests. Paints the middle band
# over the triage screen (panels stay), walks the guided tests with live
# repaint, then shows the summary. The caller re-pushes the snapshot so the
# dashboard sees the full results.
diag::guided() {
    diag::_init
    diag::cpu
    diag::ram
    diag::storage
    diag::network
    diag::battery
    diag::peripherals
    diag::webcam

    if ui::terminal_controls_supported; then
        ui::cursor_hide
        UI_COMPLETE_THEME=4
        table::render
        diag::render
    fi

    diag::display;  diag::paint_row display
    diag::keyboard; diag::paint_row keyboard
    diag::touchpad; diag::paint_row touchpad
    diag::usb;      diag::paint_row usb
    diag::speaker;  diag::paint_row speaker
    diag::mic;      diag::paint_row mic

    if ui::terminal_controls_supported; then
        printf '\n%s\n' "$(diag::summary)" > /dev/tty 2>/dev/null || true
        printf 'press any key to return to triage...' > /dev/tty 2>/dev/null || true
        read -rsn1 < /dev/tty 2>/dev/null || true
    fi

    return 0
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
