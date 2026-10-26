#!/usr/bin/env bash
# Durable run journal (50_report.sh): a run that DIES must still leave a record.
#
# The signed CSV/JSON set only exists once a run reaches its end, so a run that
# crashed (a fatal bash arithmetic error, an OOM kill, a pulled stick) used to
# leave nothing at all on disk — the log, the drive table and the progress all
# lived in RAM. These tests pin the append-only journal and the EXIT-trap path
# that make the last thing that happened survive the machine.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"
REPORT_DIR="$tmpdir/"
REPORT_USB_MNT=""
COCID="12345"
SCRIPT_VERSION="v-test"
SYS_SERIAL="SYSSN123"
SYS_UUID="UUID-1"
OPERATOR_NAME="tester"

declare -A devrow
devices=(sda sdb)
devrow[sda.selected]=1
devrow[sdb.selected]=0
devrow[sda.serial]="AAA111"
devrow[sda.method]="Zero fill"
devrow[sda.class]="CLEAR"
devrow[sda.cert]="SANITISATION"

# --- no destination yet: a silent no-op, never an error ----------------------
REPORT_JOURNAL=""
t::check "journal: no destination configured is a silent no-op" 'report::journal RUN x'

# --- opening a run ----------------------------------------------------------
report::journal_open
j="$REPORT_JOURNAL"
t::check "journal: lives beside the report, under the COCID" \
    '[[ "$j" == *"/reports/12345/run-journal.txt" ]]'
t::check "journal: the file exists after open" '[[ -f "$j" ]]'
t::check "journal: records the run header" 'grep -q "RUN .*started v-test cocid=12345" "$j"'
t::check "journal: records the host identity" 'grep -q "HOST .*serial=SYSSN123" "$j"'
t::check "journal: records the drive to be erased, with its method" \
    'grep -q "DRIVE *sda serial=AAA111 method=\"Zero fill\"" "$j"'
t::check "journal: an unselected drive is not listed" '! grep -q "DRIVE sdb" "$j"'
t::check "journal: records how many drives are queued" 'grep -q "COUNT *1 drive(s) queued" "$j"'

# --- a run in progress is marked active -------------------------------------
report::journal_begin
t::assert_eq "1" "$RUN_ACTIVE" "journal: a started run is marked active"

# --- abort: the whole point of the exercise ---------------------------------
devrow[sda.status]="RUNNING"
devrow[sda.progress_pct]="08"
report::abort_record 1
t::assert_eq "0" "$RUN_ACTIVE" "abort: the run is no longer active afterwards"
t::check "abort: records the exit code" 'grep -q "ABORTED exit=1" "$j"'
t::check "abort: names the drive and what it was doing" \
    'grep -q "DRIVE *sda status=RUNNING progress=08" "$j"'
t::check "abort: warns the media may be part-erased" 'grep -q "NOTE .*part-erased" "$j"'

# A second call (or any call after the run closed) must add nothing: the abort
# record is idempotent so an EXIT trap firing twice cannot duplicate it.
before="$(wc -l < "$j")"
report::abort_record 1
t::assert_eq "$before" "$(wc -l < "$j")" "abort: idempotent when no run is active"

# --- a clean end closes the run --------------------------------------------
report::journal_begin
devrow[sda.status]="COMPLETED"
report::journal DRIVE "sda status=COMPLETED method=\"Zero fill\""
report::journal_end "report=tScrub_12345_x.csv save=ok"
t::check "end: records the report file that was written" \
    'grep -q "END .*report=tScrub_12345_x.csv" "$j"'
t::assert_eq "0" "$RUN_ACTIVE" "end: the run is closed"
aborted_before="$(grep -c ABORTED "$j")"
report::abort_record 0
t::assert_eq "$aborted_before" "$(grep -c ABORTED "$j")" \
    "end: a clean close leaves no abort note (even if the trap fires)"

# --- device::emit_status journals terminal outcomes only --------------------
exec 3>/dev/null
report::journal_begin
devrow[sda.status]="COMPLETED"
device::emit_status sda COMPLETED
t::check "emit_status: a terminal outcome is journaled as it happens" \
    'grep -q "DRIVE *sda status=COMPLETED" "$j"'

before="$(wc -l < "$j")"
devrow[sda.status]="RUNNING"
device::emit_status sda RUNNING
device::emit_status sda "43%"
t::assert_eq "$before" "$(wc -l < "$j")" \
    "emit_status: live progress is NOT journaled (no per-tick disk writes)"
exec 3>&-
RUN_ACTIVE=0

rm -rf "$tmpdir"
t::summary
