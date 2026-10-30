#!/usr/bin/env bash
# A machine with nothing to erase (research/no-media-ux/README.md).
#
# The behaviour being pinned: discovery finding nothing must be a STATE, not an
# `exit 1`. Exiting makes the appliance's getty respawn tScrub, which wipes the
# reason off the screen and re-runs boot — the loop the operator reported, and
# which used to re-ask for a COCID that could not be used for anything.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "== media_state classifies what discovery found =="
# Pure read of the discovery state, which is why these need no block fixtures.
devices=()
devrow=()
t::assert_eq "none" "$(device::media_state)" "no devices at all -> none"

devices=(sda)
devrow=()
devrow[sda.selectable]=1
t::assert_eq "ok" "$(device::media_state)" "a selectable drive -> ok"

devrow[sda.selectable]=0
t::assert_eq "all_protected" "$(device::media_state)" \
    "devices present but none selectable -> all_protected (NOT 'none')"

devices=(sda sdb)
devrow[sdb.selectable]=1
t::assert_eq "ok" "$(device::media_state)" \
    "one protected drive beside one selectable drive is still ok"

echo
echo "== an empty discovery really produces 'none' =="
# The whole point: device::discover returns 1 and the caller must branch, not die.
empty="$TMP/empty"
mkdir -p "$empty"
: > "$TMP/mounts"
: > "$TMP/cmdline"
# Discovery and the classification must run in the SAME shell: devices/devrow are
# globals, so a subshell's discovery would not be visible to the assertion.
_saved_bb="${SYS_BLOCK_DIR:-}"
SYS_BLOCK_DIR="$empty"
PROC_MOUNTS_FILE="$TMP/mounts"
CMDLINE_FILE="$TMP/cmdline"
if device::discover; then
    t::check "discover fails on an empty tree" 'false'
else
    t::check "discover fails on an empty tree" 'true'
fi
t::assert_eq "none" "$(device::media_state)" \
    "and reports none, which is what the no-media screen branches on"
SYS_BLOCK_DIR="$_saved_bb"

echo
echo "== the report gate's trap: an EMPTY device set looks 'complete' =="
# ui::all_drives_terminal succeeds when there is nothing to iterate, so a
# zero-device run would pass the gate and mint a certificate for nothing. The
# guard in erasure::run exists because of this; assert the trap itself so the
# guard can never be removed as "redundant".
devices=()
devrow=()
t::check "all_drives_terminal is TRUE for zero devices (hence the guard)" \
    'ui::all_drives_terminal'
t::assert_eq "0" "$(report::selected_count)" \
    "and nothing is selected, which is what the guard checks"
t::check "the report gate refuses to certify an empty run" '! report::has_targets'
devrow[sda.selected]=1
devices=(sda)
t::check "but a selected drive passes the gate" 'report::has_targets'
devices=()
devrow=()

echo
echo "== the COCID is resolved from config, never prompted, at boot =="
# cocid::resolve_supplied must NOT reach the prompt: on a headless boot the
# prompt exits 1, and on a driveless box it is a question with no usable answer.
CMDLINE_FILE="$TMP/cmdline"
export CMDLINE_FILE
printf 'console=tty3 tscrub_cocid=54321\n' > "$CMDLINE_FILE"
COCID=""
cocid::resolve_supplied
t::assert_eq "54321" "$COCID" "a cmdline COCID is picked up"

printf 'console=tty3\n' > "$CMDLINE_FILE"
COCID=""
cocid::resolve_supplied
t::assert_eq "" "$COCID" "with none supplied it stays empty (and does not prompt)"

COCID="11111"
cocid::resolve_supplied
t::assert_eq "11111" "$COCID" "--cocid wins over the cmdline"

COCID="12345"
t::assert_eq "0" "$(cocid::ensure >/dev/null 2>&1; echo $?)" \
    "cocid::ensure returns success for an already-valid COCID without prompting"

echo
echo "== the journal records 'nothing to wipe' =="
REPORT_JOURNAL="$TMP/run-journal.txt"
: > "$REPORT_JOURNAL"
report::journal NOTE "nothing to wipe - no drive was selected for erasure"
report::journal_end "nothing-to-wipe (no drive selected)"
t::check "the journal says nothing was wiped" 'grep -q "nothing to wipe" "$REPORT_JOURNAL"'
t::check "and closes with a nothing-to-wipe END" 'grep -q "END.*nothing-to-wipe" "$REPORT_JOURNAL"'
t::check "which is distinguishable from an abort or a normal end" \
    '! grep -q "ABORTED\|END .*report=" "$REPORT_JOURNAL"'

t::summary
