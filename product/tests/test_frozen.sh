#!/usr/bin/env bash
# Tests for the drive-freeze / unfreeze loop: 30_device.sh device::unfreeze.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

declare -A bus devrow
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
# Run directly (not in $()) so the devrow freeze_state/flr_attempts writes land
# in this shell — command substitution would isolate them in a subshell.
outf="$tmp/out1"
device::unfreeze >"$outf" 2>/dev/null
out="$(cat "$outf")"
t::assert_contains "$out" "sda: frozen — suspending to clear the freeze lock (attempt 1/5)" \
    "frozen: one clear suspend line with attempt count"
t::assert_contains "$out" "sda: not frozen" "frozen: confirms success after unfreeze"
t::check "frozen: no separate 'unfreezing' line" '[[ "$out" != *"unfreezing"* ]]'
t::check "frozen: exactly two lines" '[[ "$(printf "%s" "$out" | grep -c .)" == "2" ]]'
t::assert_eq "removed" "${devrow[sda.freeze_state]}" "frozen: freeze_state removed"
t::assert_eq "1" "${devrow[sda.flr_attempts]}" "frozen: one flr attempt recorded"

# --- a drive that was never frozen stays quiet -------------------------------
FAKE_HDPARM_MODE=clear
devrow=()
outf="$tmp/out2"
device::unfreeze >"$outf" 2>/dev/null
out="$(cat "$outf")"
t::assert_eq "" "$out" "frozen: never-frozen drive prints nothing"

# --- non-SATA drives are skipped silently -----------------------------------
devices=(nvme0n1)
outf="$tmp/out3"
device::unfreeze >"$outf" 2>/dev/null
out="$(cat "$outf")"
t::assert_eq "" "$out" "frozen: non-SATA drive is skipped"

# --- TS_FLR_MODE=disabled skips FLR entirely --------------------------------
devices=(sda)
FAKE_HDPARM_MODE=frozen
rm -f "$FAKE_HDPARM_UNFREEZE_FILE"
TS_FLR_MODE=disabled
outf="$tmp/out4"
device::unfreeze >"$outf" 2>/dev/null
out="$(cat "$outf")"
t::assert_eq "" "$out" "frozen: disabled mode prints nothing"
TS_FLR_MODE=auto

# --- a drive that stays frozen records freeze_state=stuck (autonuke) --------
rm -f "$FAKE_HDPARM_UNFREEZE_FILE"
FAKE_RTCWAKE_FLAG=""                      # suspend does nothing: lock never clears
FAKE_HDPARM_UNFREEZE_FILE="$tmp/never"    # marker never touched
AUTONUKE=1
outf="$tmp/out5"
device::unfreeze >"$outf" 2>/dev/null
out="$(cat "$outf")"
t::assert_contains "$out" "still frozen — giving up (autonuke)" "frozen: autonuke gives up"
t::assert_eq "stuck" "${devrow[sda.freeze_state]}" "frozen: freeze_state stuck"
t::assert_eq "5" "${devrow[sda.flr_attempts]}" "frozen: 5 attempts recorded"
AUTONUKE=0

rm -rf "$tmp"
t::summary
