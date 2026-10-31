#!/usr/bin/env bash
# A machine with nothing to erase (research/no-media-ux/README.md).
#
# The behaviour being pinned: discovery finding nothing must be a STATE, not an
# `exit 1`. Exiting makes the appliance's getty respawn tScrub, which wipes the
# reason off the screen and re-runs boot — the loop the operator reported. The
# COCID is still resolved at boot (it identifies the session, and files the
# diagnostics snapshot), and it is cached for the rest of that boot so a respawn
# does not ask for the same job's ID again.
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
echo "== the COCID is resolved at boot, from config or this boot's answer =="
# The COCID identifies the SESSION (device registration + diagnostics snapshot +
# run journal), so it is resolved before discovery. resolve_supplied never
# prompts: it reads --cocid, then tscrub_cocid=, then the answer a previous
# process cached earlier in this same boot.
CMDLINE_FILE="$TMP/cmdline"
export CMDLINE_FILE
COCID_CACHE_FILE="$TMP/cocid-cache"
export COCID_CACHE_FILE
rm -f "$COCID_CACHE_FILE"
printf 'console=tty3 tscrub_cocid=54321\n' > "$CMDLINE_FILE"
COCID=""
cocid::resolve_supplied
t::assert_eq "54321" "$COCID" "a cmdline COCID is picked up"
t::assert_eq "54321" "$(cat "$COCID_CACHE_FILE" 2>/dev/null)" \
    "and remembered so a respawn need not ask again"

printf 'console=tty3\n' > "$CMDLINE_FILE"
rm -f "$COCID_CACHE_FILE"
COCID=""
cocid::resolve_supplied
t::assert_eq "" "$COCID" \
    "with nothing supplied and no cached answer it stays empty (and does not prompt)"

printf '54321\n' > "$COCID_CACHE_FILE"
COCID=""
cocid::resolve_supplied
t::assert_eq "54321" "$COCID" \
    "a getty respawn reuses this boot's answer"

printf '54321\n' > "$COCID_CACHE_FILE"
COCID="22222"
cocid::resolve_supplied
t::assert_eq "22222" "$COCID" "an in-memory answer still wins over the cache"

printf 'not-a-cocid\n' > "$COCID_CACHE_FILE"
COCID=""
cocid::resolve_supplied
t::assert_eq "" "$COCID" \
    "a junk cache is ignored, so the operator is asked again rather than run unattributed"
rm -f "$COCID_CACHE_FILE"

COCID="11111"
cocid::resolve_supplied
t::assert_eq "11111" "$COCID" "--cocid wins over the cmdline"

COCID="12345"
t::assert_eq "0" "$(cocid::ensure >/dev/null 2>&1; echo $?)" \
    "cocid::ensure returns success for an already-valid COCID without prompting"

rm -f "$COCID_CACHE_FILE"

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

echo
echo "== the no-media boot still publishes an intake snapshot =="
# Without this the asset exists only as a transient heartbeat: the Devices tab
# has no row to record "no data-bearing media" against, and the certificate
# cannot account for the asset. The push must be on ENTRY, before the terminal
# check that can exit headless.
no_media_fn="$(awk '/^triage::no_media_run\(\)/,/^}/' "$ROOT_DIR/src/40_table.sh")"
t::check "no_media_run pushes the registration" \
    'printf "%s" "$no_media_fn" | grep -q "register::push"'
t::check "the push happens before the headless exit" \
    '[[ "$(printf "%s" "$no_media_fn" | grep -n "register::push" | head -1 | cut -d: -f1)" -lt "$(printf "%s" "$no_media_fn" | grep -n "terminal_controls_supported" | head -1 | cut -d: -f1)" ]]'

SYS_SERIAL="ASSET-1"; SYS_UUID="uuid-1"; COCID="12345"
SYS_MEDIA_STATE="none"; DISCOVERY_NOTICE="No supported drives found."
SYS_SERIAL_SOURCE="dmi"; devices=(); devrow=()
body="$(register::json_body)"
t::check "the snapshot states no media" \
    'printf "%s" "$body" | grep -q "\"media_state\":\"none\""'
t::check "it carries the discovery reason" \
    'printf "%s" "$body" | grep -q "No supported drives found."'
t::check "it identifies the serial's source" \
    'printf "%s" "$body" | grep -q "\"serial_source\":\"dmi\""'
t::check "an empty drive list is still valid JSON" \
    'printf "%s" "$body" | grep -q "\"drives\":\[\]"'

t::summary
