#!/usr/bin/env bash
# Triage-screen overwrite-standard picker.
#
# Covers the two axes (`m` = standard, `p` = rounds), their wrap, seeding from a
# configured value, the arithmetic the legend and the CSV both depend on, and
# the legend itself at several terminal widths (it is CENTRED, so a long line
# would wrap into the drive table).
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

# Width is measured, so stub the detector: relying on $COLUMNS is only true
# when stdout is piped (detect_terminal_width prefers tput on a real terminal).
STUB_W=186
table::detect_terminal_width() { printf '%s' "$STUB_W"; }
table::detect_terminal_height() { printf '%s' 40; }

reset_choice() {
    TS_NWIPE_METHOD=""
    TS_NWIPE_PASSES=""
    TS_NWIPE_METHOD_SET=0
    TS_NWIPE_PASSES_SET=0
}

# --- the standard list (single source of truth for the cycle order) ---------
t::assert_eq "zero random dod dodshort gutmann is5enh" "$(nwipe::methods)" \
    "nwipe::methods lists the six standards in cycle order"

for m in $(nwipe::methods); do
    s="$(nwipe::method_short "$m")"
    t::check "short label '$s' is <= 12 chars" '[[ "${#s}" -le 12 ]]'
done

t::assert_eq "DoD Short" "$(nwipe::method_short dodshort)" "short label: dodshort"
t::assert_eq "PRNG" "$(nwipe::method_short random)" "short label: random"
t::assert_eq "Zero" "$(nwipe::method_short '')" "short label: empty defaults to Zero"

# --- effective value: configured, else the engine default -------------------
reset_choice
t::assert_eq "zero" "$(triage::method)" "unset standard shows the default (zero)"
t::assert_eq "1" "$(triage::passes)" "unset passes shows the default (1)"
t::assert_eq "1" "$(triage::total_passes)" "default = 1 pattern pass"

TS_NWIPE_METHOD=dodshort
TS_NWIPE_PASSES=3
t::assert_eq "dodshort" "$(triage::method)" "configured standard is picked up"
t::assert_eq "3" "$(triage::passes)" "configured passes are picked up"
t::assert_eq "9" "$(triage::total_passes)" "dodshort x3 = 9 pattern passes"

# Junk in the globals must never reach the engine or the legend.
TS_NWIPE_METHOD=quick
t::assert_eq "zero" "$(triage::method)" "an unrecognised standard falls back to zero"
TS_NWIPE_METHOD=dod
for bad in 0 99 abc ""; do
    TS_NWIPE_PASSES="$bad"
    t::assert_eq "1" "$(triage::passes)" "passes '$bad' falls back to 1"
done

# --- pattern-pass arithmetic (the number the legend and the CSV both show) ---
t::assert_eq "1"  "$( ( TS_NWIPE_METHOD=zero;     TS_NWIPE_PASSES=1; triage::total_passes ) )" "zero x1"
t::assert_eq "4"  "$( ( TS_NWIPE_METHOD=zero;     TS_NWIPE_PASSES=4; triage::total_passes ) )" "zero x4"
t::assert_eq "28" "$( ( TS_NWIPE_METHOD=dod;      TS_NWIPE_PASSES=4; triage::total_passes ) )" "dod x4"
t::assert_eq "12" "$( ( TS_NWIPE_METHOD=dodshort; TS_NWIPE_PASSES=4; triage::total_passes ) )" "dodshort x4"
t::assert_eq "140" "$( ( TS_NWIPE_METHOD=gutmann; TS_NWIPE_PASSES=4; triage::total_passes ) )" "gutmann x4"
t::assert_eq "12" "$( ( TS_NWIPE_METHOD=is5enh;   TS_NWIPE_PASSES=4; triage::total_passes ) )" "is5enh x4"

# --- cycling the standard ---------------------------------------------------
reset_choice
order=""
for i in 1 2 3 4 5 6; do
    triage::cycle_method
    order+="${TS_NWIPE_METHOD} "
done
t::assert_eq "random dod dodshort gutmann is5enh zero " "$order" \
    "m cycles the six standards and wraps to zero"
t::assert_eq "1" "${TS_NWIPE_METHOD_SET:-0}" "cycling marks the standard as chosen"
t::assert_eq "" "${TS_NWIPE_PASSES:-}" "cycling the standard leaves passes alone"
t::assert_eq "zero" "$TS_NWIPE_METHOD" "cycle ends back on zero"

# Starting from a configured standard continues from there, not from zero.
TS_NWIPE_METHOD=gutmann
triage::cycle_method
t::assert_eq "is5enh" "$TS_NWIPE_METHOD" "cycle continues from the configured standard"
triage::cycle_method
t::assert_eq "zero" "$TS_NWIPE_METHOD" "cycle wraps from is5enh to zero"

# --- cycling the repeat count ----------------------------------------------
reset_choice
ladder=""
for i in 1 2 3 4 5 6 7 8; do
    triage::cycle_passes
    ladder+="${TS_NWIPE_PASSES} "
done
t::assert_eq "2 3 4 5 6 8 10 1 " "$ladder" "p walks the ladder and wraps to 1"
t::assert_eq "1" "${TS_NWIPE_PASSES_SET:-0}" "cycling marks the passes as chosen"
t::assert_eq "" "${TS_NWIPE_METHOD:-}" "cycling passes leaves the standard alone"

TS_NWIPE_PASSES=5
triage::cycle_passes
t::assert_eq "6" "$TS_NWIPE_PASSES" "on-ladder value steps up"

# Off-ladder configured value (a scripted --passes 32) must not be weakened.
TS_NWIPE_PASSES=32
triage::cycle_passes
t::assert_eq "1" "$TS_NWIPE_PASSES" "off-ladder value with nothing above wraps to 1"

# --- the legend the operator actually reads ---------------------------------
reset_choice
STUB_W=186
legend="$(triage::legend)"
t::assert_contains "$legend" "Overwrite fallback (not firmware erase)" \
    "wide legend says the choice is fallback-only"
t::assert_contains "$legend" "Zero x1 = 1 pass" "wide legend names the default standard"
t::assert_contains "$legend" "m=standard p=rounds" "wide legend documents both keys"
t::assert_contains "$legend" "T=erase" "wide legend keeps the erase key"
t::check "wide legend fits the terminal" '[[ "${#legend}" -le 186 ]]'

STUB_W=120
mid="$(triage::legend)"
t::assert_contains "$mid" "Overwrite: " "mid legend uses the short prefix"
t::assert_contains "$mid" "Zero x1 = 1 pass" "mid legend still names the standard"
t::check "mid legend drops the long caveat" '[[ "${#mid}" -le 120 ]]'

STUB_W=80
short="$(triage::legend)"
t::check "compact legend fits an 80-column console" '[[ "${#short}" -le 80 ]]'
t::assert_contains "$short" "Overwrite" "compact legend still labels the setting"
t::assert_contains "$short" "Zero x1" "compact legend still names the standard"

for w in 200 160 120 100 80 60; do
    STUB_W="$w"
    l="$(triage::legend)"
    stripped="$(printf '%s' "$l" | LC_ALL=C tr -d '\000-\177')"
    t::check "legend is ASCII only at ${w} cols" '[[ -z "$stripped" ]]'
    t::check "legend never exceeds ${w} cols" '[[ "${#l}" -le "$w" ]]'
done

# The legend follows the picker (that is its whole job).
reset_choice
STUB_W=186
triage::cycle_method
triage::cycle_passes
picked="$(triage::legend)"
t::assert_contains "$picked" "PRNG x2 = 2 passes" "legend reflects the picked standard and rounds"
t::assert_contains "$picked" "PRNG" "legend shows the new standard, not the old one"

# --- the choice reaches the engine's preview and the selection screen --------
reset_choice
t::assert_eq "nwipe Zero" "$(device::capability_label CAP_SCSI_NWIPE)" \
    "SCSI fallback preview names the default standard (no stale 'Quick')"
TS_NWIPE_METHOD=dodshort
t::assert_eq "nwipe DoD Short" "$(device::capability_label CAP_SCSI_NWIPE)" \
    "SCSI fallback preview follows the configured standard"
prev="$(device::capability_label CAP_SCSI_NWIPE)"
t::check "fallback preview '$prev' is <= 20 chars" '[[ "${#prev}" -le 20 ]]'

devices=(sda sdb)
STUB_W=186
sel="$(select::legend)"
t::assert_contains "$sel" "Overwrite: DoD Short x1" "selection legend shows the chosen standard"
STUB_W=60
sel_narrow="$(select::legend)"
t::check "narrow selection legend drops the standard" '[[ "$sel_narrow" != *"Overwrite"* ]]'
t::assert_contains "$sel_narrow" "Sel: 0/2" "narrow selection legend keeps the count"

# --- no terminal, no painting ----------------------------------------------
reset_choice
out="$(STUB_W=80; triage::paint_legend)"
t::assert_eq "" "$out" "paint_legend writes nothing without a tty"

t::summary
