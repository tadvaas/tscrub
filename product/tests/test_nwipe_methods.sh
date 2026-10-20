#!/usr/bin/env bash
# Selectable software-overwrite standard: name/label/pass mapping, the argv
# handed to nwipe, and the provenance emitted over the worker IPC pipe.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

# Keep the suite fast, but not instantaneous: a no-op sleep makes the progress
# pump race nwipe's startup and SIGUSR1 can land before its trap is installed.
sleep() { command sleep 0.2; }

declare -A devrow

# --- name / label / pattern table -------------------------------------------
for m in zero random dod dodshort gutmann is5enh; do
    t::check "method '$m' is valid" "nwipe::method_valid '$m'"
done
t::check "empty method rejected" '! nwipe::method_valid ""'
t::check "unknown method rejected" '! nwipe::method_valid quick'
t::check "nwipe alias rejected (not our vocabulary)" '! nwipe::method_valid dod3pass'

t::assert_eq "zero" "$(nwipe::method_arg zero)" "arg: zero"
t::assert_eq "random" "$(nwipe::method_arg random)" "arg: random"
t::assert_eq "dod522022m" "$(nwipe::method_arg dod)" "arg: dod"
t::assert_eq "dodshort" "$(nwipe::method_arg dodshort)" "arg: dodshort"
t::assert_eq "gutmann" "$(nwipe::method_arg gutmann)" "arg: gutmann"
t::assert_eq "is5enh" "$(nwipe::method_arg is5enh)" "arg: is5enh"

t::assert_eq "Zero fill" "$(nwipe::method_label zero)" "label: zero"
t::assert_eq "DoD 5220.22-M" "$(nwipe::method_label dod)" "label: dod"
t::assert_eq "HMG IS5 Enhanced" "$(nwipe::method_label is5enh)" "label: is5enh"
t::check "labels fit the 20-column TUI" '
    ok=1
    for m in zero random dod dodshort gutmann is5enh; do
        l="$(nwipe::method_label "$m")"
        (( ${#l} <= 20 )) || ok=0
    done
    (( ok ))'

t::assert_eq "1" "$(nwipe::method_patterns zero)" "patterns: zero"
t::assert_eq "1" "$(nwipe::method_patterns random)" "patterns: random"
t::assert_eq "7" "$(nwipe::method_patterns dod)" "patterns: dod"
t::assert_eq "3" "$(nwipe::method_patterns dodshort)" "patterns: dodshort"
t::assert_eq "35" "$(nwipe::method_patterns gutmann)" "patterns: gutmann"
t::assert_eq "3" "$(nwipe::method_patterns is5enh)" "patterns: is5enh"

t::assert_eq "0.41" "$(nwipe::version)" "engine version parsed"
t::assert_eq "nwipe 0.41" "$(nwipe::eraser)" "engine+version label"

# --- parse_args / cmdline validation ---------------------------------------
t::check "--method sets the standard" '( TS_NWIPE_METHOD=""; TS_NWIPE_METHOD_SET=0; parse_args --method dodshort >/dev/null 2>&1; [[ "$TS_NWIPE_METHOD" == dodshort && "$TS_NWIPE_METHOD_SET" == 1 ]] )'
t::check "--method= sets the standard" '( TS_NWIPE_METHOD=""; parse_args --method=gutmann >/dev/null 2>&1; [[ "$TS_NWIPE_METHOD" == gutmann ]] )'
t::check "--passes sets the repetition count" '( TS_NWIPE_PASSES=""; parse_args --passes 3 >/dev/null 2>&1; [[ "$TS_NWIPE_PASSES" == 3 ]] )'
t::check "--passes accepts a leading zero" '( TS_NWIPE_PASSES=""; parse_args --passes 08 >/dev/null 2>&1; (( 10#$TS_NWIPE_PASSES == 8 )) )'
t::check "invalid --method is refused" '! ( parse_args --method bogus ) >/dev/null 2>&1'
t::check "--passes 0 is refused" '! ( parse_args --passes 0 ) >/dev/null 2>&1'
t::check "--passes 65 is refused" '! ( parse_args --passes 65 ) >/dev/null 2>&1'

# --- exec_nwipe: default is byte-identical to the old behaviour -------------
run_nwipe() {
    local out
    out="$(mktemp)"
    exec 3>"$out"
    device::exec_nwipe sda
    exec 3>&-
    cat "$out"
    rm -f "$out"
}

export FAKE_NWIPE_LOG="$(mktemp)"
export FAKE_NWIPE_RC=0
unset TS_NWIPE_METHOD TS_NWIPE_PASSES

captured="$(run_nwipe)"
argv="$(cat "$FAKE_NWIPE_LOG")"
t::assert_contains "$captured" "sda METHOD Zero fill" "default: METHOD label"
t::assert_contains "$captured" "sda STANDARD zero" "default: STANDARD slug"
t::assert_contains "$captured" "sda PASSES 1" "default: PASSES 1"
t::assert_contains "$captured" "sda ERASER nwipe 0.41" "default: engine"
t::assert_contains "$argv" "--method=zero" "default: argv uses zero"
t::check "default: argv has no --rounds" '! grep -q -- "--rounds" "$FAKE_NWIPE_LOG"'

# --- exec_nwipe: selected standard + repetitions ----------------------------
: > "$FAKE_NWIPE_LOG"
export TS_NWIPE_METHOD=dodshort TS_NWIPE_PASSES=2
export FAKE_NWIPE_ROUNDS=2 FAKE_NWIPE_PASS_TOTAL=3

captured="$(run_nwipe)"
argv="$(cat "$FAKE_NWIPE_LOG")"
t::assert_contains "$captured" "sda METHOD DoD Short" "selected: METHOD label"
t::assert_contains "$captured" "sda STANDARD dodshort" "selected: STANDARD slug"
t::assert_contains "$captured" "sda PASSES 6" "selected: rounds x patterns"
t::assert_contains "$captured" "sda ERASER nwipe 0.41" "selected: engine"
t::assert_contains "$argv" "--method=dodshort" "selected: argv method"
t::assert_contains "$argv" "--rounds=2" "selected: argv rounds"

# --- nwipe's own log is authoritative when it disagrees ---------------------
: > "$FAKE_NWIPE_LOG"
export TS_NWIPE_METHOD=dod TS_NWIPE_PASSES=1
export FAKE_NWIPE_ROUNDS=1 FAKE_NWIPE_PASS_TOTAL=5
captured="$(run_nwipe)"
t::assert_eq "5" "$(printf '%s\n' "$captured" | awk '$2=="PASSES"{v=$3} END{print v}')" \
    "log-reported pass count overrides the static table"

# --- defensive handling (startup validation should catch these first) -------
: > "$FAKE_NWIPE_LOG"
export TS_NWIPE_METHOD=bogus TS_NWIPE_PASSES=0
captured="$(run_nwipe)"
t::assert_contains "$captured" "sda METHOD Zero fill" "invalid method degrades to zero"
t::assert_contains "$captured" "unknown software-overwrite method" "invalid method is logged"
t::assert_contains "$captured" "sda PASSES 1" "invalid pass count degrades to 1"

unset TS_NWIPE_METHOD TS_NWIPE_PASSES FAKE_NWIPE_ROUNDS FAKE_NWIPE_PASS_TOTAL
unset FAKE_NWIPE_RC
rm -f "$FAKE_NWIPE_LOG"

t::summary
