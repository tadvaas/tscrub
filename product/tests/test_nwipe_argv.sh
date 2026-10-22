#!/usr/bin/env bash
# The argv tScrub hands to nwipe.
#
# WHY THIS EXISTS: nothing asserted the command line before. The suite proved
# what tScrub RECORDS (OverwriteStandard, passes, engine) but never what it
# ASKS NWIPE TO DO — and the recorded values are tScrub's own variables, so a
# wrong or dropped argument would still look right in the report. That is the
# same blind spot that let the coverage sampler sit inert for two releases.
#
# The fake nwipe appends its own argv to $FAKE_NWIPE_LOG, so these assertions
# read the real command line the production function builds — the stub is the
# external binary, never the function under test.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

ARGV="$TMP/argv.log"
STATUS="$TMP/status"
DETAIL="$TMP/detail"

# Run device::exec_nwipe with a clean environment and capture:
#   $ARGV   - every nwipe invocation, one per line (--version, --help, the run)
#   $STATUS - the fd-3 records that feed the TUI and the CSV
#   $DETAIL - the fd-5 detail log
run_nwipe() {  # [method] [passes] [dev]
    : > "$ARGV"; : > "$STATUS"; : > "$DETAIL"
    export FAKE_NWIPE_LOG="$ARGV"
    TS_NWIPE_METHOD="${1:-zero}"
    TS_NWIPE_PASSES="${2:-1}"
    device::exec_nwipe "${3:-sda}" 3>"$STATUS" 5>"$DETAIL" 2>/dev/null || true
}

# The last invocation in the log is the wipe (the first two are --version and
# --help). Asserting against it, rather than the file, keeps the probe probes
# from contaminating the assertion.
wipe_argv() { tail -n 1 "$ARGV"; }

echo "== 1. the default command line (no flags configured) =="
run_nwipe zero 1 sda
argv="$(wipe_argv)"
t::assert_contains "$argv" "--autonuke" "default argv passes --autonuke"
t::assert_contains "$argv" "--method=zero" "default argv names the zero method"
t::assert_contains "$argv" "--nogui" "default argv passes --nogui (offered by --help)"
t::assert_contains "$argv" "--logfile" "default argv passes a --logfile so progress can be followed"
t::assert_contains "$argv" "/dev/sda" "default argv ends at the block device"
t::check "default argv does NOT pass --rounds (nwipe's own default stands)" \
    '[[ "$argv" != *"--rounds"* ]]'

echo
echo "== 2. a configured standard and round count =="
run_nwipe dodshort 2 sdb
argv="$(wipe_argv)"
t::assert_contains "$argv" "--method=dodshort" "dodshort is passed as --method=dodshort"
t::assert_contains "$argv" "--rounds=2" "two rounds are passed as --rounds=2"
t::assert_contains "$argv" "/dev/sdb" "the requested device is used"
t::assert_contains "$(cat "$STATUS")" "STANDARD dodshort" "STANDARD record names the method for the CSV"
t::assert_contains "$(cat "$STATUS")" "METHOD DoD Short" "METHOD record carries the human label"
t::assert_contains "$(cat "$STATUS")" "PASSES 6" "PASSES is rounds x patterns (2 x 3)"
t::assert_contains "$(cat "$STATUS")" "ERASER nwipe 0.41" "ERASER records the engine version"

echo
echo "== 3. one round is never expressed as --rounds=1 =="
run_nwipe dodshort 1 sdc
argv="$(wipe_argv)"
t::assert_contains "$argv" "--method=dodshort" "single-round run still names the method"
t::check "single-round run omits --rounds entirely" '[[ "$argv" != *"--rounds"* ]]'
t::assert_contains "$(cat "$STATUS")" "PASSES 3" "single-round PASSES is the pattern count alone"

echo
echo "== 4. out-of-range round counts are clamped, not passed through =="
for bad in 0 65 abc; do
    run_nwipe zero "$bad" sdd
    t::check "passes='$bad' is clamped to one round (no --rounds)" \
        '[[ "$(wipe_argv)" != *"--rounds"* ]]'
done

echo
echo "== 5. an unknown standard falls back to zero and says so =="
run_nwipe bogus 1 sde
argv="$(wipe_argv)"
t::assert_contains "$argv" "--method=zero" "unknown method falls back to --method=zero"
t::assert_contains "$(cat "$STATUS")" "unknown software-overwrite method 'bogus'" \
    "the fallback is reported rather than silent"
t::assert_contains "$(cat "$STATUS")" "STANDARD zero" "the recorded standard matches what was asked for"

echo
echo "== 6. nwipe's own log overrides the static pass table =="
: > "$ARGV"; : > "$STATUS"; : > "$DETAIL"
FAKE_NWIPE_LOG="$ARGV" FAKE_NWIPE_ROUNDS=4 FAKE_NWIPE_PASS_TOTAL=3 \
    TS_NWIPE_METHOD=dodshort TS_NWIPE_PASSES=2 \
    device::exec_nwipe sdf 3>"$STATUS" 5>"$DETAIL" 2>/dev/null || true
t::assert_contains "$(cat "$STATUS")" "PASSES 6" "the static figure is recorded before the run"
t::assert_contains "$(cat "$STATUS")" "PASSES 12" \
    "the engine's own round/pass line corrects the record (4 x 3)"

echo
echo "== 7. a failed engine is reported as failed, not as done =="
: > "$ARGV"; : > "$STATUS"; : > "$DETAIL"
FAKE_NWIPE_LOG="$ARGV" FAKE_NWIPE_RC=1 \
    TS_NWIPE_METHOD=zero TS_NWIPE_PASSES=1 \
    device::exec_nwipe sdg 3>"$STATUS" 5>"$DETAIL" 2>/dev/null || true
t::assert_contains "$(cat "$STATUS")" "STATUS FAILED" "a non-zero engine exit reports FAILED"
t::assert_contains "$(cat "$STATUS")" "manual physical destruction required" \
    "the failure tells the operator what to do next"
t::check "a failed run never reports COMPLETED" \
    '[[ "$(cat "$STATUS")" != *"STATUS COMPLETED"* ]]'

echo
echo "== 8. --nogui is only passed when the engine advertises it =="
# Shadow the fake binary with a function: this is the one case the shipped fake
# cannot express, because it always advertises --nogui.
nwipe() {
    case "$1" in
        --version) printf 'nwipe version 0.41\n'; return 0 ;;
        --help)    printf 'usage: nwipe [options]\n'; return 0 ;;   # no --nogui
        *)         [[ -n "${FAKE_NWIPE_LOG:-}" ]] && printf '%s\n' "$*" >> "$FAKE_NWIPE_LOG"
                   return 0 ;;
    esac
}
: > "$ARGV"; : > "$STATUS"; : > "$DETAIL"
FAKE_NWIPE_LOG="$ARGV" TS_NWIPE_METHOD=zero TS_NWIPE_PASSES=1 \
    device::exec_nwipe sdh 3>"$STATUS" 5>"$DETAIL" 2>/dev/null || true
t::check "an engine without --nogui is not given it" \
    '[[ "$(wipe_argv)" != *"--nogui"* ]]'
t::assert_contains "$(wipe_argv)" "--autonuke" "the rest of the argv is unchanged"
unset -f nwipe

echo
echo "== 9. the engine version probe uses the option the engine implements =="
# MEASURED DEFECT (2026-10-22): nwipe v0.41 declares --version in its long-option
# table but its case-0 handler never matches it, so --version prints NOTHING and
# every report recorded "nwipe" instead of "nwipe 0.41". The fake now mirrors that
# (it answers -V only), so this assertion fails if the probe regresses.
run_nwipe zero 1 sdi
first="$(head -n 1 "$ARGV")"
t::assert_eq "-V" "$first" "the version probe asks for -V (the implemented option)"
t::assert_contains "$(cat "$STATUS")" "ERASER nwipe 0.41" \
    "the engine version reaches the record as a result"
t::check "the probe does not rely on --version alone" \
    '[[ "$(nwipe::version)" == "0.41" ]]'

# Fallback for a build that only answers --version.
nwipe() {
    case "$1" in
        -V)        return 22 ;;
        --version) printf 'nwipe version 9.99\n'; return 0 ;;
        --help)    printf 'usage: --nogui\n'; return 0 ;;
        *)         return 0 ;;
    esac
}
t::assert_eq "9.99" "$(nwipe::version)" "a build that answers only --version still yields its version"
unset -f nwipe

t::summary
