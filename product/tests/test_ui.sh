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
t::assert_eq "Done" "$(ui::eta_text_for d1 0)" "eta COMPLETED -> Done"
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

t::summary
