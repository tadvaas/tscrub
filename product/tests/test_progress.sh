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
device::exec_scsi_nwipe sda
exec 3>&-
exec 5>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "sda STATUS 45%" "nwipe % parsed -> STATUS 45%"
t::assert_contains "$captured" "sda ETA 754" "nwipe eta 00:12:34 -> 754s"
t::assert_contains "$captured" "sda STATUS COMPLETED" "nwipe success -> COMPLETED"

t::summary
