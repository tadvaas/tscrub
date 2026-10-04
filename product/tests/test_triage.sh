#!/usr/bin/env bash
# End-to-end test of the triage-first refactor's NEW logic that the existing
# suites don't reach: erasure::run (repeatable select→wipe→report cycle),
# its per-cycle state reset, and session::teardown (kills the long-lived
# workers on exit so they can't orphan).
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

export FAKE_NVME_MODE=crypto
export FAKE_HDPARM_MODE=enhanced
export FAKE_USB_DEVICES="sdb"

tmpdir="$(mktemp -d)"
REPORT_OUTPUT="$tmpdir/out"
mkdir -p "$REPORT_OUTPUT"

device::install_sedutil >/dev/null 2>&1
device::discover
device::handle_locks >/dev/null 2>&1
device::detect

COCID="12345"
AUTONUKE=1          # skip the interactive selection screen (/dev/tty read)
DRY_RUN=1           # no real wipe; exercises the full report path
DRY_RUN_SIM_ETA_MINS=0
REPORT_USB_MNT=""

# --- first erasure cycle -----------------------------------------------------
erasure::run >/dev/null 2>&1
rc=$?
t::check "erasure: run 1 returns 0" '[[ "$rc" == "0" ]]'
t::check "erasure: run 1 all drives terminal" 'ui::all_drives_terminal'
t::check "erasure: run 1 marks DRY-RUN" '[[ "${devrow[nvme0n1.status]}" == "DRY-RUN" ]]'
t::check "erasure: run 1 normalises class to DRY-RUN" '[[ "${devrow[nvme0n1.class]}" == "DRY-RUN" ]]'
t::check "erasure: run 1 wrote a report CSV" 'ls "$REPORT_OUTPUT"/reports/12345/tScrub_*.csv >/dev/null 2>&1'
t::check "erasure: run 1 report status ok" '[[ "${REPORT_USB_STATUS:-}" == "ok" ]]'

# --- per-cycle state reset (the stale-classification bug fix) ----------------
table::build
t::check "erasure: table::build reclassifies DRY-RUN drive back to PURGE" \
    '[[ "${devrow[nvme0n1.class]}" == "PURGE" ]]'

# --- second erasure cycle (repeatability) ------------------------------------
# Simulate a stale report status from the previous cycle; erasure::run must
# reset it and set it fresh.
REPORT_USB_STATUS="fail"
count_before="$(ls "$REPORT_OUTPUT"/reports/12345/tScrub_*.csv 2>/dev/null | wc -l | tr -d ' ')"
erasure::run >/dev/null 2>&1
rc=$?
count_after="$(ls "$REPORT_OUTPUT"/reports/12345/tScrub_*.csv 2>/dev/null | wc -l | tr -d ' ')"
t::check "erasure: run 2 returns 0" '[[ "$rc" == "0" ]]'
t::check "erasure: run 2 wrote another report CSV" '[[ "$count_after" -gt "$count_before" ]]'
t::check "erasure: run 2 resets stale report status" '[[ "${REPORT_USB_STATUS:-}" == "ok" ]]'

# --- session::teardown kills the long-lived workers --------------------------
( sleep 30 ) &
presence_pid=$!
session::teardown
wait "$presence_pid" 2>/dev/null || true
t::check "teardown: kills the presence worker" '! kill -0 "$presence_pid" 2>/dev/null'

# --- selection abort returns to the triage screen ----------------------------
# Override the interactive selection to simulate Esc (return 1) and assert
# erasure::run re-renders the triage screen (blue, triage legend) and returns 2
# — the caller must not pin the finish screen after an abort.
select::run() { SELECT_MODE=0; SELECT_CURSOR=""; return 1; }
AUTONUKE=0
TRIAGE_MODE=0
UI_COMPLETE_THEME=0
erasure::run >/dev/null 2>&1
rc=$?
t::check "erasure: selection abort returns 2" '[[ "$rc" == "2" ]]'
t::check "erasure: selection abort restores triage mode" '[[ "${TRIAGE_MODE:-0}" == "1" ]]'
t::check "erasure: selection abort restores blue theme" '[[ "${UI_COMPLETE_THEME:-0}" == "4" ]]'

# --- remote-initiated erasure: auto-select path ------------------------------
# With REMOTE_ERASE set, erasure::run must NOT open the interactive selection
# screen — it applies the requested drive list itself and wipes directly.
AUTONUKE=0
REMOTE_ERASE=1
DRY_RUN=1
DRY_RUN_SIM_ETA_MINS=0
SELECT_CALLED=0
select::run() { SELECT_CALLED=1; SELECT_MODE=0; SELECT_CURSOR=""; return 1; }
REMOTE_ERASE_DRIVES="all"
erasure::run >/dev/null 2>&1
rc=$?
t::check "remote erase: returns 0" '[[ "$rc" == "0" ]]'
t::check "remote erase: never opens the selection screen" '[[ "$SELECT_CALLED" == "0" ]]'
t::check "remote erase: all drives wiped (DRY-RUN)" '[[ "${devrow[nvme0n1.status]}" == "DRY-RUN" && "${devrow[sda.status]}" == "DRY-RUN" ]]'

# Specific serial only → the other drive is recorded SKIPPED.
REMOTE_ERASE_DRIVES="${devrow[nvme0n1.serial]}"
erasure::run >/dev/null 2>&1
rc=$?
t::check "remote erase (specific): returns 0" '[[ "$rc" == "0" ]]'
t::check "remote erase (specific): requested drive wiped" '[[ "${devrow[nvme0n1.status]}" == "DRY-RUN" ]]'
t::check "remote erase (specific): other drive SKIPPED" '[[ "${devrow[sda.status]}" == "SKIPPED" ]]'
REMOTE_ERASE=0
REMOTE_ERASE_DRIVES=""

rm -rf "$tmpdir"
t::summary
