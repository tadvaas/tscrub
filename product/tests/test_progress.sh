#!/usr/bin/env bash
# Live wipe progress → dashboard: aggregate % + ETA, and the nwipe progress
# parser that feeds it (SIGUSR1 log lines -> STATUS NN% / ETA seconds).

source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

# --- aggregate percent: (finished*100 + Σ running_pct) / total ---
devices=(sda sdb)
devrow=()
devrow[sda.selected]=1; devrow[sdb.selected]=1
devrow[sda.status]="43%"; devrow[sdb.status]="COMPLETED"
status::recompute_progress
t::assert_eq "71" "$(status::progress_field progress_pct)" "aggregate (43% + done)/2 -> 71"
t::assert_eq "-1" "$(status::progress_field progress_eta_sec)" "no ETA source -> -1"

# --- opaque running drive (no %) -> indeterminate ---
devrow[sda.status]="RUNNING"
status::recompute_progress
t::assert_eq "-1" "$(status::progress_field progress_pct)" "opaque RUNNING drive -> indeterminate"

# --- the LIVE representation: ui::loop keeps the number in progress_pct ---
# A running worker's status word is RUNNING and the percentage lands in
# devrow[DEV.progress_pct]. Requiring the percentage to be in the status word
# made every real job report -1 (indeterminate) while visibly wiping.
devrow[sda.progress_pct]="43"
status::recompute_progress
t::assert_eq "71" "$(status::progress_field progress_pct)" \
    "RUNNING + progress_pct=43 is aggregated, not treated as indeterminate"
unset 'devrow[sda.progress_pct]'

# --- ATA ETA from the timing word (eta_mins*60 - elapsed) ---
devrow[sda.eta_mins]=90
devrow[sda.start_ts]=$(($(ts::now) - 600))
status::recompute_progress
eta="$(status::progress_field progress_eta_sec)"
t::check "ATA ETA ~80min (90min - 10min elapsed)" '[[ "$eta" =~ ^[0-9]+$ ]] && (( eta >= 4790 && eta <= 4800 ))'

# --- NVMe % extrapolation: eta = elapsed * (100 - pct) / pct ---
devices=(sda)
devrow=()
devrow[sda.selected]=1
devrow[sda.status]="50%"
devrow[sda.start_ts]=$(($(ts::now) - 100))
status::recompute_progress
eta="$(status::progress_field progress_eta_sec)"
t::check "NVMe 50% after 100s -> ~100s left" '[[ "$eta" =~ ^[0-9]+$ ]] && (( eta >= 95 && eta <= 105 ))'
t::assert_eq "50" "$(status::progress_field progress_pct)" "single NVMe drive 50% -> aggregate 50"

# --- nwipe progress parse (the --logfile + drain path) ---
LOG="$(mktemp)"
OUT="$(mktemp)"
exec 5>"$LOG"
devices=(sda)
capability[sda]="CAP_SCSI_NWIPE"
devrow=()
devrow[sda.class]="CLEAR"
devrow[sda.capability]="CAP_SCSI_NWIPE"
export FAKE_NWIPE_RC=0 FAKE_NWIPE_PROGRESS=45 FAKE_NWIPE_ETA="00:12:34"
exec 3>"$OUT"
device::exec_nwipe sda
exec 3>&-
exec 5>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "sda STATUS 45%" "nwipe % parsed -> STATUS 45%"
t::assert_contains "$captured" "sda ETA 754" "nwipe eta 00:12:34 -> 754s"
t::assert_contains "$captured" "sda STATUS COMPLETED" "nwipe success -> COMPLETED"

echo
echo "== nwipe ETA parsing: zero-padded fields are DECIMAL, not octal =="
# The real-world failure: a 32 GB stick reported "eta 01:47:09" and the whole
# arithmetic expression aborted with "value too great for base (error token is
# \"09\")" printed over the operator's screen. Any field of 08 or 09 did it.
t::assert_eq "6429" "$(nwipe::eta_seconds ' 42.3% complete, eta 01:47:09')" \
    "01:47:09 -> 6429 s"
t::assert_eq "8" "$(nwipe::eta_seconds 'eta 00:00:08')" "08 seconds -> 8"
t::assert_eq "7749" "$(nwipe::eta_seconds 'eta 02:09:09')" "09 minutes and seconds -> 7749 s"
t::check "no arithmetic noise on stderr" \
    '[[ -z "$(nwipe::eta_seconds "eta 01:47:09" 2>&1 >/dev/null)" ]]'
t::check "a line with no ETA is rejected" '! nwipe::eta_seconds " 42.3% complete" >/dev/null'

echo
echo "== nwipe pass phase is surfaced, and a stale sample is marked =="
# nwipe has no timer: it writes "..., eta HH:MM:SS, [verifying]" only when
# tScrub signals it. The phase tells WRITING from VERIFYING (the percentage
# looks identical), and the "!" says the last sample is older than two pump
# intervals, i.e. the ETA beside it is stale rather than merely wrong.
t::assert_eq "VERIFY" "$(nwipe::phase_token '/dev/sdb: 42.31%, round 1 of 1, pass 1 of 1, eta 01:47:09, [verifying]')" \
    "verifying -> VERIFY"
t::assert_eq "WRITE"  "$(nwipe::phase_token '/dev/sdb: 42.31%, round 1 of 1, pass 1 of 1, eta 01:47:09, >[writing]>')" \
    "writing -> WRITE"
t::assert_eq "SYNC"   "$(nwipe::phase_token '/dev/sdb: 42.31%, round 1 of 1, pass 1 of 1, eta 00:00:09, [syncing]')" \
    "syncing -> SYNC"
t::check "a line with no phase yields nothing" \
    '! nwipe::phase_token "/dev/sdb: 42.31%, round 1 of 1, pass 1 of 1, eta 01:47:09" >/dev/null'
t::check "the phase cell falls back when there is no phase" \
    '! ui::phase_cell "" 1 9 >/dev/null'
t::assert_eq "VERIFY   " "$(ui::phase_cell VERIFY 2 9)"  "fresh sample: phase, no marker"
t::assert_eq "VERIFY!  " "$(ui::phase_cell VERIFY 40 9)" "40s without a sample: marked stale"
t::assert_eq "VERIFY   " "$(ui::phase_cell VERIFY 25 9)" "at exactly 25s it is still fresh"

t::summary
