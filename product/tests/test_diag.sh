#!/usr/bin/env bash
# Hardware diagnostics suite (guided): verdict recording, JSON/summary
# serialisation, the guided tier (Shift+D), and the --diag opt-in flag.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

export FAKE_NVME_MODE=crypto
export FAKE_HDPARM_MODE=enhanced
export FAKE_USB_DEVICES="sdb"

device::install_sedutil >/dev/null 2>&1
device::discover

# --- record(): vocabulary + detail cleaning ---
diag::_init
diag::record cpu PASS "sum-of-squares ok"
diag::record ram FAIL "bad,  memory"
diag::record webcam UNSUP "no uvcvideo"
t::assert_eq 3 "$DIAG_RUN" "record: 3 entries"
t::assert_contains "$DIAG_RESULTS" "cpu=PASS:sum-of-squares ok" "record: PASS detail kept"
t::assert_contains "$DIAG_RESULTS" "ram=FAIL:bad memory" "record: comma stripped"
t::assert_contains "$DIAG_RESULTS" "webcam=UNSUP" "record: UNSUP recorded"

# --- json() serialises entries in order ---
t::assert_eq '[{"test":"cpu","verdict":"PASS","detail":"sum-of-squares ok"},{"test":"ram","verdict":"FAIL","detail":"bad memory"},{"test":"webcam","verdict":"UNSUP","detail":"no uvcvideo"}]' \
    "$(diag::json)" "json: serialises in order"

# --- summary() counts ---
t::assert_eq "3 run, 1 passed, 1 failed, 0 skipped, 1 unsupported" \
    "$(diag::summary)" "summary: counts"

# --- automatic tier (diag::run): no automatic tests remain ---
diag::_init
diag::run
t::assert_eq 0 "$DIAG_RUN" "run: 0 automatic tests (guided-only suite)"

# --- opt-in flag parsing ---
DIAG=0
parse_args --diag
t::check "flag: --diag enables" '[[ "$DIAG" -eq 1 ]]'

DIAG=1
parse_args --diag=0
t::check "flag: --diag=0 disables" '[[ "$DIAG" -eq 0 ]]'

# --- real prompt: headless detection probes the console, not stdout ---
# (regression: diag::_guided calls diag::prompt via command substitution, where
# stdout is a pipe — the old `[[ -t 1 ]]` check returned "headless" on the
# console and every guided test skipped without prompting)
DIAG_TTY_FILE="/nonexistent/console"
t::assert_eq "skip" "$(diag::prompt 'Q?')" "prompt: headless (no console) -> skip"
unset DIAG_TTY_FILE

# --- guided tier (headless-safe logic) ---
# The harness runs in a terminal, so force the guided tier down its
# non-interactive path — otherwise diag::guided would render + prompt on /dev/tty.
diag::wash() { :; }
diag::_instruct() { :; }
ui::terminal_controls_supported() { return 1; }

diag::_init
diag::prompt() { printf 'pass\n'; }
diag::display
t::assert_contains "$DIAG_RESULTS" "display=PASS" "guided: pass -> PASS"

# keyboard: keypresses are the test, not the verdict — consume them until Y/N/S.
tmp="$(mktemp -d)"
printf 'abcY' > "$tmp/kbd"
DIAG_TTY_FILE="$tmp/kbd"
diag::_init
diag::keyboard
t::assert_contains "$DIAG_RESULTS" "keyboard=PASS" "keyboard: keys ignored, Y -> PASS"

printf '' > "$tmp/kbd_empty"
DIAG_TTY_FILE="$tmp/kbd_empty"
diag::_init
diag::keyboard
t::assert_contains "$DIAG_RESULTS" "keyboard=SKIP" "keyboard: no input -> SKIP"

DIAG_TTY_FILE="/nonexistent/console"
diag::_init
diag::keyboard
t::assert_contains "$DIAG_RESULTS" "keyboard=SKIP" "keyboard: headless -> SKIP"
unset DIAG_TTY_FILE
rm -rf "$tmp"

# touchpad: find the input device, then detect real movement events
tmp="$(mktemp -d)"
printf 'I: Bus=0011\nN: Name="AlpsPS/2 ALPS GlidePoint"\nH: Handlers=mouse1 event9\n' > "$tmp/input"
DIAG_INPUT_DEVICES_FILE="$tmp/input"
t::assert_eq "9" "$(diag::_touchpad_device)" "touchpad: finds GlidePoint event device"
printf 'I: Bus=0019\nN: Name="AT Translated Set 2 keyboard"\nH: Handlers=kbd event6\n' > "$tmp/input"
t::assert_eq "" "$(diag::_touchpad_device)" "touchpad: empty when no touchpad"

printf '' > "$tmp/tty"
DIAG_TTY_FILE="$tmp/tty"
diag::_touchpad_device() { printf '9\n'; }
diag::_motion_events() { printf '5\n'; }
diag::_init
diag::touchpad
t::assert_contains "$DIAG_RESULTS" "touchpad=PASS:movement detected (5 events)" "touchpad: movement -> PASS"

diag::_motion_events() { printf '0\n'; }
diag::_init
diag::touchpad
t::assert_contains "$DIAG_RESULTS" "touchpad=FAIL:no touchpad movement detected" "touchpad: idle -> FAIL"

diag::_touchpad_device() { printf '\n'; }
diag::_init
diag::touchpad
t::assert_contains "$DIAG_RESULTS" "touchpad=SKIP:no touchpad detected" "touchpad: no device -> SKIP"
unset DIAG_TTY_FILE
rm -rf "$tmp"

# speaker/mic: UNSUP without ALSA tools; mic_peak is pure and testable
diag::_init
diag::speaker
t::assert_contains "$DIAG_RESULTS" "speaker=UNSUP" "speaker: UNSUP without ALSA"

diag::_init
diag::mic
t::assert_contains "$DIAG_RESULTS" "mic=UNSUP" "mic: UNSUP without ALSA"

tmp="$(mktemp -d)"
printf '\x00\x00\xff\x7f' > "$tmp/loud.raw"
t::assert_eq "32767" "$(diag::mic_peak "$tmp/loud.raw")" "mic_peak: detects 32767"
printf '\x00\x00\x00\x00' > "$tmp/silent.raw"
t::assert_eq "0" "$(diag::mic_peak "$tmp/silent.raw")" "mic_peak: silence is 0"
rm -rf "$tmp"

# mic_unmute: unmutes + boosts capture/mic controls (best-effort)
tmp="$(mktemp -d)"
export FAKE_AMIXER_LOG="$tmp/amixer.log"
diag::mic_unmute
unset FAKE_AMIXER_LOG
t::assert_contains "$(cat "$tmp/amixer.log")" "Capture cap" "mic_unmute: caps Capture"
t::assert_contains "$(cat "$tmp/amixer.log")" "Mic Boost 100%" "mic_unmute: boosts Mic Boost"
rm -rf "$tmp"

# speaker_unmute: unmutes + raises playback controls (best-effort)
tmp="$(mktemp -d)"
export FAKE_AMIXER_LOG="$tmp/amixer.log"
diag::speaker_unmute
unset FAKE_AMIXER_LOG
t::assert_contains "$(cat "$tmp/amixer.log")" "Master unmute" "speaker_unmute: unmutes Master"
t::assert_contains "$(cat "$tmp/amixer.log")" "Speaker 100%" "speaker_unmute: raises Speaker"
t::assert_contains "$(cat "$tmp/amixer.log")" "PCM 100%" "speaker_unmute: raises PCM"
rm -rf "$tmp"

# speaker_unmute: honours a reduced volume percent
tmp="$(mktemp -d)"
export FAKE_AMIXER_LOG="$tmp/amixer.log"
diag::speaker_unmute 50
unset FAKE_AMIXER_LOG
t::assert_contains "$(cat "$tmp/amixer.log")" "Master 50%" "speaker_unmute: Master at requested 50%"
t::assert_contains "$(cat "$tmp/amixer.log")" "Speaker 50%" "speaker_unmute: Speaker at requested 50%"
rm -rf "$tmp"

# --- guided suite (headless: 6 tests, guided ones SKIP) ---
diag::_init
diag::prompt() { printf 'skip\n'; }
DIAG_TTY_FILE="/nonexistent/console"
diag::guided
unset DIAG_TTY_FILE
t::assert_eq 6 "$DIAG_RUN" "guided: 6 tests recorded"
t::assert_contains "$DIAG_RESULTS" "display=SKIP" "guided: headless display SKIP"
t::assert_contains "$DIAG_RESULTS" "mic=UNSUP" "guided: headless mic UNSUP"

# --- usb(): live device detection + verdict keys ---
# _usb_device_count: counts non-hub USB devices (idVendor present, 1d6b root
# hubs excluded) — the "is something plugged in" signal the test polls.
tmp="$(mktemp -d)"
mkdir -p "$tmp/1-1" "$tmp/1-2" "$tmp/empty"
printf '1d6b\n' > "$tmp/1-1/idVendor"   # Linux Foundation root hub — excluded
printf '0781\n' > "$tmp/1-2/idVendor"   # SanDisk stick
DIAG_USB_DIR="$tmp"
t::assert_eq "1" "$(diag::_usb_device_count)" "usb: counts non-hub devices"
DIAG_USB_DIR="$tmp/empty"
t::assert_eq "0" "$(diag::_usb_device_count)" "usb: 0 when no devices"
rm -rf "$tmp"

# interactive usb(): polls for a newly-plugged device (count increments), shows
# "Device detected" feedback, and honours the Y/N/S verdict keys.
tmp="$(mktemp -d)"
printf 'y' > "$tmp/usb"
printf '0' > "$tmp/counter"
diag::_usb_device_count() {
    local c; c="$(cat "$tmp/counter" 2>/dev/null || printf '0')"
    c=$((c+1)); printf '%d' "$c" > "$tmp/counter"; printf '%d' "$c"
}
diag::_instruct() { printf '%s\n' "$1" >> "$tmp/instr"; }
DIAG_TTY_FILE="$tmp/usb"
diag::_init
diag::usb
unset DIAG_TTY_FILE
t::assert_contains "$DIAG_RESULTS" "usb=PASS" "usb: Y -> PASS"
t::assert_contains "$(cat "$tmp/instr")" "Device detected" "usb: shows device-detection feedback"
rm -rf "$tmp"

# usb headless: no console -> SKIP
DIAG_TTY_FILE="/nonexistent/console"
diag::_init
diag::usb
unset DIAG_TTY_FILE
t::assert_contains "$DIAG_RESULTS" "usb=SKIP" "usb: headless -> SKIP"

t::summary
