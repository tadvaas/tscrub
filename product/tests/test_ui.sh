#!/usr/bin/env bash
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

# --- ui::format_runtime ---
START_TS=1000
t::assert_eq "00:00:10" "$(ui::format_runtime 1010)" "format_runtime 10s"
t::assert_eq "01:00:00" "$(ui::format_runtime 4600)" "format_runtime 1h"
t::assert_eq "00:00:00" "$(ui::format_runtime 500)" "format_runtime clamps negative"
START_TS=""
t::assert_eq "00:00:00" "$(ui::format_runtime 999)" "format_runtime no START_TS"
START_TS="abc"
t::assert_eq "00:00:00" "$(ui::format_runtime 999)" "format_runtime invalid START_TS"

# --- ui::spinner ---
UI_SPINNER_FRAME=0
t::assert_eq "|" "$(ui::spinner)" "spinner frame 0"
UI_SPINNER_FRAME=1
t::assert_eq "/" "$(ui::spinner)" "spinner frame 1"
UI_SPINNER_FRAME=2
t::assert_eq "-" "$(ui::spinner)" "spinner frame 2"
UI_SPINNER_FRAME=3
t::assert_eq "\\" "$(ui::spinner)" "spinner frame 3"
UI_SPINNER_FRAME=4
t::assert_eq "|" "$(ui::spinner)" "spinner wraps to 0"

# --- ui::eta_text_for ---
devices=(d1)
devrow=()
devrow[d1.eta_mins]=""
devrow[d1.wipe_start]=""
devrow[d1.status]="PLANNED"

devrow[d1.eta_mins]="90"
t::assert_eq "~1h30m" "$(ui::eta_text_for d1 0)" "eta PLANNED 90m -> ~1h30m"
devrow[d1.eta_mins]="5"
t::assert_eq "~5min" "$(ui::eta_text_for d1 0)" "eta PLANNED 5m -> ~5min"
devrow[d1.eta_mins]=""
t::assert_eq "N/A" "$(ui::eta_text_for d1 0)" "eta PLANNED no eta -> N/A"

devrow[d1.status]="COMPLETED"
t::assert_eq "--" "$(ui::eta_text_for d1 0)" "eta COMPLETED -> --"
devrow[d1.status]="FAILED"
t::assert_eq "--" "$(ui::eta_text_for d1 0)" "eta FAILED -> --"
devrow[d1.status]="BLOCKED"
t::assert_eq "--" "$(ui::eta_text_for d1 0)" "eta BLOCKED -> --"
devrow[d1.status]="FROZEN"
t::assert_eq "--" "$(ui::eta_text_for d1 0)" "eta FROZEN -> --"

devrow[d1.status]="RUNNING"
devrow[d1.eta_mins]="10"
devrow[d1.wipe_start]="100"
t::assert_eq "~9m0s" "$(ui::eta_text_for d1 160)" "eta RUNNING countdown"
devrow[d1.status]="42%"
t::assert_eq "~9m0s" "$(ui::eta_text_for d1 160)" "eta percentage countdown"
devrow[d1.wipe_start]=""
t::assert_eq "~10min" "$(ui::eta_text_for d1 160)" "eta RUNNING no wipe_start -> static"
devrow[d1.eta_mins]=""
t::assert_eq "N/A" "$(ui::eta_text_for d1 160)" "eta RUNNING no eta -> N/A"

# --- ui::all_drives_terminal / ui::any_drive_failed ---
devices=(d1 d2)
devrow=()
devrow[d1.status]="COMPLETED"
devrow[d2.status]="BLOCKED"
t::check "all_drives_terminal (COMPLETED+BLOCKED)" 'ui::all_drives_terminal'
devrow[d2.status]="RUNNING"
t::check "NOT terminal when one RUNNING" '! ui::all_drives_terminal'

devrow[d1.status]="COMPLETED"
devrow[d2.status]="BLOCKED"
t::check "any_drive_failed (BLOCKED counts as failed)" 'ui::any_drive_failed'
devrow[d2.status]="COMPLETED"
t::check "no failure when all completed" '! ui::any_drive_failed'
devrow[d1.status]="DRY-RUN"
t::check "DRY-RUN not a failure" '! ui::any_drive_failed'

# --- table::compute_layout (responsive widths) ---
# The full render must never exceed the terminal width (indent included), and
# the ETA in-place update column must stay inside the terminal too.
has_label() { [[ " ${UI_TABLE_LABELS[*]} " == *" $1 "* ]]; }

COLUMNS=186; table::compute_layout
t::check "layout 186 fits" '(( ${#UI_TABLE_INDENT} + UI_TABLE_MAIN_W <= 186 ))'
t::check "layout 186 ETA fits" '(( UI_ETA_COL - 1 + UI_ETA_W <= 186 ))'
t::check "layout 186 has 12 cols" '(( ${#UI_TABLE_WIDTHS[@]} == 12 ))'
t::check "layout 186 keeps CLASS, drops CERT" 'has_label CLASS && ! has_label CERT'
t::check "layout 186 margins + fill" '(( ${#UI_TABLE_INDENT} == 2 && UI_TABLE_MAIN_W == 182 ))'
COLUMNS=160; table::compute_layout
t::check "layout 160 fits" '(( ${#UI_TABLE_INDENT} + UI_TABLE_MAIN_W <= 160 ))'
t::check "layout 160 has 12 cols" '(( ${#UI_TABLE_WIDTHS[@]} == 12 ))'
t::check "layout 160 even (panels align)" '(( UI_TABLE_MAIN_W % 2 == 0 ))'
t::check "layout 160 margins + fill" '(( ${#UI_TABLE_INDENT} == 2 && UI_TABLE_MAIN_W == 156 ))'
COLUMNS=120; table::compute_layout
t::check "layout 120 fits" '(( ${#UI_TABLE_INDENT} + UI_TABLE_MAIN_W <= 120 ))'
t::check "layout 120 has 11 cols (METHOD dropped)" '(( ${#UI_TABLE_WIDTHS[@]} == 11 ))'
t::check "layout 120 keeps CLASS" 'has_label CLASS'
t::check "layout 120 margins + fill" '(( ${#UI_TABLE_INDENT} == 2 && UI_TABLE_MAIN_W == 116 ))'
COLUMNS=100; table::compute_layout
t::check "layout 100 fits" '(( ${#UI_TABLE_INDENT} + UI_TABLE_MAIN_W <= 100 ))'
t::check "layout 100 has 11 cols" '(( ${#UI_TABLE_WIDTHS[@]} == 11 ))'
t::check "layout 100 even (panels align)" '(( UI_TABLE_MAIN_W % 2 == 0 ))'
t::check "layout 100 margins + fill" '(( ${#UI_TABLE_INDENT} == 2 && UI_TABLE_MAIN_W == 96 ))'
COLUMNS=80; table::compute_layout
t::check "layout 80 fits" '(( ${#UI_TABLE_INDENT} + UI_TABLE_MAIN_W <= 80 ))'
t::check "layout 80 ETA fits" '(( UI_ETA_COL - 1 + UI_ETA_W <= 80 ))'
t::check "layout 80 has 9 cols" '(( ${#UI_TABLE_WIDTHS[@]} == 9 ))'
t::check "layout 80 keeps CLASS" 'has_label CLASS'
t::check "layout 80 margins + fill" '(( ${#UI_TABLE_INDENT} == 2 && UI_TABLE_MAIN_W == 76 ))'
COLUMNS=220; table::compute_layout
t::check "layout 220 margins + fill (12 cols)" '(( ${#UI_TABLE_INDENT} == 2 && UI_TABLE_MAIN_W == 216 && ${#UI_TABLE_WIDTHS[@]} == 12 ))'
COLUMNS=199; table::compute_layout
t::check "layout 199 margin + rounds to even" '(( ${#UI_TABLE_INDENT} == 2 && UI_TABLE_MAIN_W == 194 && UI_TABLE_MAIN_W <= 199 ))'

t::summary
