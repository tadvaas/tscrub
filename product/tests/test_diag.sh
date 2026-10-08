#!/usr/bin/env bash
# Hardware diagnostics suite: verdict recording, JSON/summary serialisation,
# the automatic tier (diag::run), and the --diag opt-in flag.
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

# --- ram() with a fixture ---
tmp="$(mktemp -d)"
printf 'MemTotal:        8123456 kB\n' > "$tmp/meminfo"
diag::_init
DIAG_MEMINFO_FILE="$tmp/meminfo" diag::ram
t::assert_contains "$DIAG_RESULTS" "ram=PASS:memory recognised: 8 GB" "ram: recognises total"
DIAG_MEMINFO_FILE="$tmp/nonexistent" diag::ram
t::assert_contains "$DIAG_RESULTS" "ram=N/A" "ram: N/A when absent"
rm -rf "$tmp"

# --- network() with a carrier-up fixture ---
tmp="$(mktemp -d)"
mkdir -p "$tmp/eth0/device"
printf '1\n' > "$tmp/eth0/carrier"
diag::_init
DIAG_NET_DIR="$tmp" diag::network
t::assert_contains "$DIAG_RESULTS" "network=PASS" "network: carrier up"
rm -rf "$tmp"
diag::_init
DIAG_NET_DIR="$(mktemp -d)" diag::network
t::assert_contains "$DIAG_RESULTS" "network=N/A" "network: N/A when no NIC"

# --- automatic tier (diag::run) ---
export FAKE_SMART_SELFTEST="Completed without error       00%"
export FAKE_NVME_SELFTEST_RESULT="0"
diag::_init
diag::run
t::assert_eq 7 "$DIAG_RUN" "run: 7 automatic tests"
t::assert_contains "$DIAG_RESULTS" "cpu=PASS" "run: cpu PASS"
t::assert_contains "$DIAG_RESULTS" "storage=PASS" "run: storage PASS (fake smartctl)"
t::assert_contains "$DIAG_RESULTS" "webcam=" "run: webcam recorded"

# --- opt-in flag parsing ---
DIAG=0
parse_args --diag
t::check "flag: --diag enables" '[[ "$DIAG" -eq 1 ]]'

DIAG=1
parse_args --diag=0
t::check "flag: --diag=0 disables" '[[ "$DIAG" -eq 0 ]]'

# --- guided tier (headless-safe logic) ---
# The harness runs in a terminal, so force the guided tier down its
# non-interactive path — otherwise diag::guided would render + prompt on /dev/tty.
diag::wash() { :; }
ui::terminal_controls_supported() { return 1; }

diag::_init
diag::prompt() { printf 'pass\n'; }
diag::display
t::assert_contains "$DIAG_RESULTS" "display=PASS" "guided: pass -> PASS"

diag::_init
diag::prompt() { printf 'fail\n'; }
diag::keyboard
t::assert_contains "$DIAG_RESULTS" "keyboard=FAIL" "guided: fail -> FAIL"

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

# --- guided suite (headless: 13 tests, guided ones SKIP) ---
export FAKE_SMART_SELFTEST="Completed without error       00%"
export FAKE_NVME_SELFTEST_RESULT="0"
diag::_init
diag::prompt() { printf 'skip\n'; }
diag::guided
t::assert_eq 13 "$DIAG_RUN" "guided: 13 tests recorded"
t::assert_contains "$DIAG_RESULTS" "display=SKIP" "guided: headless display SKIP"
t::assert_contains "$DIAG_RESULTS" "mic=UNSUP" "guided: headless mic UNSUP"

t::summary
