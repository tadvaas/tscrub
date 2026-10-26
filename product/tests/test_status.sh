#!/usr/bin/env bash
# Tests for the erasure-status module (37_presence.sh): the state file the
# heartbeat reads to show "Wiping / Complete / Failed" on the dashboard.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"
STATUS_STATE_FILE="$tmpdir/state"
export STATUS_STATE_FILE

declare -A devrow
devices=(sda sdb)
devrow[sda.selected]=1
devrow[sdb.selected]=1

# A fresh boot has no state.
status::clear
t::assert_eq "" "$(status::field phase)" "status: clear -> empty phase"

# start: both drives selected, none finished.
status::erase_start
t::assert_eq "wiping" "$(status::field phase)" "status: start -> wiping"
t::assert_eq "2" "$(status::field drives_total)" "status: start -> total 2"
t::assert_eq "0" "$(status::field drives_done)" "status: start -> done 0"

# one drive completes, one still planned: still wiping, 1 done.
devrow[sda.status]="COMPLETED"
status::drive_terminal
t::assert_eq "wiping" "$(status::field phase)" "status: mid-wipe still wiping"
t::assert_eq "1" "$(status::field drives_done)" "status: one drive done"

# second drive fails: every drive terminal + a failure -> phase failed.
devrow[sdb.status]="FAILED"
devrow[sdb.serial]="S3Z8NB0K123"
status::drive_terminal
t::assert_eq "failed" "$(status::field phase)" "status: any failure -> failed"
t::assert_eq "1" "$(status::field drives_failed)" "status: failed count 1"
# ...and the state NAMES it. A bare count sent the operator hunting a bench of
# identical units for the one that broke.
t::assert_eq "S3Z8NB0K123 (erase failed)" "$(status::field failed_devices)" \
    "status: failed drive named with its reason"

# A drive whose serial could not be read is named by its kernel name, never
# "N/A failed".
devrow[sdb.serial]="N/A"
devrow[sdb.status]="STALLED"
status::drive_terminal
t::assert_eq "sdb (sanitize stalled)" "$(status::field failed_devices)" \
    "status: unreadable serial falls back to the device node"

# Two failures are both named, in device order.
devrow[sda.status]="BLOCKED"
devrow[sda.serial]="AAA111"
status::drive_terminal
t::assert_eq "AAA111 (blocked by system firmware); sdb (sanitize stalled)" \
    "$(status::field failed_devices)" "status: every failed drive is named"

# all complete -> done.
devrow[sdb.status]="COMPLETED"
devrow[sda.status]="COMPLETED"
status::drive_terminal
t::assert_eq "done" "$(status::field phase)" "status: all complete -> done"
t::assert_eq "0" "$(status::field drives_failed)" "status: failed count back to 0"
t::assert_eq "" "$(status::field failed_devices)" \
    "status: a clean run clears the named failures"

# a worker that died without reporting is normalised to UNKNOWN -> failed.
devrow[sdb.status]="UNKNOWN"
status::drive_terminal
t::assert_eq "failed" "$(status::field phase)" "status: UNKNOWN -> failed"

# unselected drives are not part of the wipe total.
devrow[sdb.selected]=0
devrow[sda.status]="COMPLETED"
status::drive_terminal
t::assert_eq "done" "$(status::field phase)" "status: unselected drive ignored"
t::assert_eq "1" "$(status::field drives_total)" "status: total counts selected only"

rm -rf "$tmpdir"
t::summary
