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

# --- ui::wave_cell ---
t::assert_eq "[ ###   ]" "$(ui::wave_cell 0)"     "wave frame 0 (9 cols)"
t::assert_eq "[  ###  ]" "$(ui::wave_cell 1)"     "wave frame 1"
t::assert_eq "[ ##  # ]" "$(ui::wave_cell 4)"     "wave frame 4"
t::assert_eq "[ #  ## ]" "$(ui::wave_cell 8)"     "wave frame 8 wraps head/tail"
t::assert_eq "[ ##  # ]" "$(ui::wave_cell 9)"     "wave wraps to 4 at frame 9"
t::assert_eq "[ ###   ]" "$(ui::wave_cell 100)"   "wave large frame folds mod width"
t::assert_eq "RUNNING"  "$(ui::wave_cell 0 3)"   "wave guard: narrow cell -> RUNNING"
t::assert_eq "RUNNING"  "$(ui::wave_cell 0 2)"   "wave guard: narrow cell -> RUNNING"

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
devrow[d1.eta_sec]="720"
t::assert_eq "~12m0s" "$(ui::eta_text_for d1 160)" "eta RUNNING eta_sec fallback (NVMe/nwipe)"
devrow[d1.eta_sec]=""
devrow[d1.status]="RUNNING"
devrow[d1.wipe_start]="100"
t::assert_eq "+1m0s" "$(ui::eta_text_for d1 160)" "eta indeterminate -> elapsed"

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
t::check "compute_layout sets a layout fingerprint" '[[ -n "$UI_LAYOUT_FP" ]]'

# --- table::row_text renders the wipe wave for RUNNING ---
devices=(d1)
devrow=()
devrow[d1.device]="nvme0n1"; devrow[d1.model]="ModelOne"; devrow[d1.serial]="SN12345"
devrow[d1.size]="1TB"; devrow[d1.bus]="NVMe"; devrow[d1.type]="SSD"
devrow[d1.smart]="OK"; devrow[d1.temp]="40"; devrow[d1.class]="NVM"
devrow[d1.method]="Sanitize"; devrow[d1.status]="RUNNING"; devrow[d1.eta_mins]=""
UI_TABLE_LABELS=(MODEL SERIAL SIZE BUS TYPE SMART TEMP DEVICE CLASS METHOD STATUS ETA)
UI_TABLE_WIDTHS=(12 8 5 5 4 5 4 8 8 8 9 5)
UI_COMPLETE_THEME=0
SELECT_MODE=0; SELECT_CURSOR=""
UI_WAVE_FRAME=0
row="$(table::row_text d1 0)"
t::assert_contains "$row" "[ ###   ]" "row_text RUNNING shows the bracketed bar"
t::check "row_text RUNNING drops the word" '[[ "$row" != *RUNNING* ]]'
# The wave is plain ASCII (# + brackets + spaces), so it survives the C locale
# without byte-truncation and stays exactly 9 columns.
LC_ALL=C row="$(table::row_text d1 0)"
t::assert_contains "$row" "[ ###   ]" "row_text wave not truncated (C locale)"
devrow[d1.status]="42%"
row="$(table::row_text d1 0)"
t::assert_contains "$row" "42%" "row_text keeps the NN% value"
t::check "row_text NN% shows no wave" '[[ "$row" != *#* ]]'

# --- delta repaint: ui::state_key / ui::changed_rows / ui::layout_changed ---
devices=(d1 d2)
devrow=()
devrow[d1.status]="PLANNED"; devrow[d1.class]="NVM"; devrow[d1.method]="Sanitize"
devrow[d1.temp]="40"; devrow[d1.smart]="OK"; devrow[d1.model]="M1"; devrow[d1.serial]="S1"
devrow[d1.selected]=0; devrow[d1.eta_mins]=""
devrow[d2.status]="PLANNED"; devrow[d2.class]="ATA"; devrow[d2.method]="Secure Erase"
devrow[d2.temp]="-"; devrow[d2.smart]="-"; devrow[d2.model]="M2"; devrow[d2.serial]="S2"
devrow[d2.selected]=0; devrow[d2.eta_mins]=""

k1="$(ui::state_key d1)"
devrow[d1.status]="42%"
t::check "state_key changes on status flip" '[[ "$(ui::state_key d1)" != "$k1" ]]'
devrow[d1.status]="PLANNED"
t::check "state_key stable when nothing changed" '[[ "$(ui::state_key d1)" == "$k1" ]]'

ui_last_key=()
for d in "${devices[@]}"; do ui_last_key["$d"]="$(ui::state_key "$d")"; done
devrow[d1.status]="RUNNING"
t::assert_eq "d1" "$(ui::changed_rows)" "changed_rows returns flipped drive"
devrow[d1.status]="PLANNED"
t::assert_eq "" "$(ui::changed_rows)" "changed_rows empty when unchanged"

UI_LAYOUT_FP="x"; UI_LAYOUT_FP_CACHED="x"
UI_THEME_LAST=0; UI_COMPLETE_THEME=0
UI_DEV_COUNT_LAST=2; UI_MODE_LAST="0:"
SELECT_MODE=0; SELECT_CURSOR=""
t::check "layout_changed false when all match" '! ui::layout_changed'
UI_LAYOUT_FP_CACHED="y"
t::check "layout_changed true on layout change" 'ui::layout_changed'
UI_LAYOUT_FP_CACHED="x"; UI_THEME_LAST=4
t::check "layout_changed true on theme change" 'ui::layout_changed'
UI_THEME_LAST=0; UI_MODE_LAST="1:"
t::check "layout_changed true on selection-mode change" 'ui::layout_changed'
UI_MODE_LAST="0:"; UI_DEV_COUNT_LAST=3
t::check "layout_changed true on device-count change" 'ui::layout_changed'

t::summary
