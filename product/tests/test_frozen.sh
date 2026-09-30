#!/usr/bin/env bash
# Tests for the drive-freeze / unfreeze loop: 30_device.sh device::frozen.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

declare -A bus
devices=(sda)
bus[sda]="SATA"

tmp="$(mktemp -d)"
FAKE_HDPARM_UNFREEZE_FILE="$tmp/unfreeze"
FAKE_RTCWAKE_FLAG="$tmp/unfreeze"   # fake rtcwake touches this, fake hdparm reads it
export FAKE_HDPARM_UNFREEZE_FILE FAKE_RTCWAKE_FLAG FAKE_HDPARM_MODE
# The loop sleeps between attempts; no-op it so the test runs instantly.
sleep() { :; }

# --- frozen, then unfreezes on the first suspend/resume ---------------------
FAKE_HDPARM_MODE=frozen
rm -f "$FAKE_HDPARM_UNFREEZE_FILE"
out="$(device::frozen 2>/dev/null)"
t::assert_contains "$out" "sda: frozen — suspending to clear the freeze lock (attempt 1/5)" \
    "frozen: one clear suspend line with attempt count"
t::assert_contains "$out" "sda: not frozen" "frozen: confirms success after unfreeze"
t::check "frozen: no separate 'unfreezing' line" '[[ "$out" != *"unfreezing"* ]]'
t::check "frozen: exactly two lines" '[[ "$(printf "%s" "$out" | grep -c .)" == "2" ]]'

# --- a drive that was never frozen stays quiet -------------------------------
FAKE_HDPARM_MODE=clear
out="$(device::frozen 2>/dev/null)"
t::assert_eq "" "$out" "frozen: never-frozen drive prints nothing"

# --- non-SATA drives are skipped silently -----------------------------------
devices=(nvme0n1)
out="$(device::frozen 2>/dev/null)"
t::assert_eq "" "$out" "frozen: non-SATA drive is skipped"

rm -rf "$tmp"
t::summary
