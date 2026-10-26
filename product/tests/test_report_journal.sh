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
# --- a dead destination must still leave a journal --------------------------
# Bench case: the ESD-USB stick tScrub had mounted answered EIO on every block
# ("FAT-fs: Directory bread failed"), so mkdir failed and the old code silently
# wrote the journal into the same broken volume — this run left NOTHING. A journal
# in RAM still records what happened while the machine is up.
ro="$tmpdir/ro"
mkdir -p "$ro"
chmod 555 "$ro"
REPORT_DIR="$ro/"
REPORT_USB_MNT=""
REPORT_JOURNAL_FALLBACK="$tmpdir/ram"
report::journal_open
t::check "an unwritable destination falls back to a writable one" '[[ -f "$REPORT_JOURNAL" ]]'
t::check "and the fallback journal names the run" 'grep -q "RUN .*started" "$REPORT_JOURNAL"'
t::check "and says the record is not durable" 'grep -q "NOT survive a power cut" "$REPORT_JOURNAL"'
chmod 755 "$ro"
REPORT_DIR="$tmpdir/"
unset REPORT_JOURNAL_FALLBACK

# --- media pulled mid-run: the journal moves itself -------------------------
# Bench case: the DataTraveler holding the report mount was unplugged three
# minutes into a run. Its mount survived with no device under it, so every write
# returned EIO and the rest of the record was lost.
dir_dead="$tmpdir/dead"
mkdir -p "$dir_dead"
REPORT_JOURNAL="$dir_dead/run-journal.txt"
: > "$REPORT_JOURNAL"
rm -f "$REPORT_JOURNAL"          # the file goes with the media
chmod 555 "$dir_dead"            # ... and the directory cannot be written any more
REPORT_JOURNAL_FALLBACK="$tmpdir/moved"
COCID="99999"
report::journal DRIVE "sda status=COMPLETED"
t::check "the record survives the media disappearing" \
    'grep -q "sda status=COMPLETED" "$REPORT_JOURNAL"'
t::check "and says it moved, and why" 'grep -q "journal moved to" "$REPORT_JOURNAL"'
t::check "the moved journal is no longer in the dead directory" \
    '[[ "$REPORT_JOURNAL" != "$dir_dead"* ]]'
chmod 755 "$dir_dead"
COCID="12345"
REPORT_JOURNAL_FALLBACK="$tmpdir/"
report::journal_open
unset REPORT_JOURNAL_FALLBACK

# --- the session exit path ------------------------------------------------
# session::on_exit is what every EXIT trap calls. On the bench the abort record
# was silently lost because fn_main armed its own cursor/teardown trap, which
# REPLACED the one armed in the entrypoint. These two checks pin the wiring.
report::journal_begin
devrow[sda.status]="RUNNING"
devrow[sda.progress_pct]="35"
session::on_exit 143
t::check "session::on_exit writes the abort record" 'grep -q "ABORTED exit=143" "$j"'
t::assert_eq "0" "$RUN_ACTIVE" "session::on_exit closes the run"
t::check "no other EXIT trap can replace the abort hook" \
    '[[ "$(grep -rhoE "trap .+ EXIT" "$ROOT_DIR"/src/*.sh | grep -vc "session::on_exit")" == "0" ]]'

t::summary
