#!/usr/bin/env bash
# Drive-selection (triage) helpers: toggle/all/none/count/chosen, the SKIPPED
# status, and the autonuke flags.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

export FAKE_NVME_MODE=crypto
export FAKE_HDPARM_MODE=enhanced
export FAKE_USB_DEVICES="sdb"

device::install_sedutil >/dev/null 2>&1
device::discover
device::handle_locks >/dev/null 2>&1
device::detect
table::build

# --- selection helpers ---
select::none
t::check "select: none by default" '[[ "$(select::count)" == "0" ]]'

select::toggle sda
t::check "select: toggle selects sda" '[[ "${devrow[sda.selected]}" == "1" ]]'
t::check "select: count is 1 after toggle" '[[ "$(select::count)" == "1" ]]'

select::toggle sda
t::check "select: toggle again deselects" '[[ "${devrow[sda.selected]}" == "0" ]]'

select::all
t::check "select: select all" '[[ "$(select::count)" == "2" ]]'
t::assert_contains "$(select::chosen)" "nvme0n1" "select: chosen has nvme0n1"
t::assert_contains "$(select::chosen)" "sda" "select: chosen has sda"

# --- SKIPPED is terminal and normalised honestly ---
for dev in "${devices[@]}"; do devrow["$dev.selected"]=1; done
devrow[sda.status]="SKIPPED"
devrow[nvme0n1.status]="COMPLETED"
t::check "select: SKIPPED is terminal" 'ui::all_drives_terminal'

device::normalize_outcome
t::check "select: SKIPPED normalises class" '[[ "${devrow[sda.class]}" == "SKIPPED" ]]'
t::check "select: SKIPPED normalises cert" '[[ "${devrow[sda.cert]}" == "NOT SANITISED" ]]'
t::check "select: SKIPPED normalises method" '[[ "${devrow[sda.method]}" == "Not selected" ]]'
t::check "select: skipped count is 1" '[[ "$(report::skipped_count)" == "1" ]]'
t::check "select: selected count is 2" '[[ "$(report::selected_count)" == "2" ]]'

# --- autonuke flags ---
COCID=""; NON_INTERACTIVE=0; AUTONUKE=0
parse_args --autonuke
t::check "autonuke: --autonuke sets AUTONUKE" '[[ "$AUTONUKE" -eq 1 ]]'

AUTONUKE=0; NON_INTERACTIVE=0; COCID=""
parse_args --cocid 12345
t::check "autonuke: --cocid sets COCID (no longer implies NON_INTERACTIVE)" '[[ "$COCID" == "12345" && "$NON_INTERACTIVE" -eq 0 ]]'

# --- registration JSON shape ---
t::assert_contains "$(register::json)" '"serial":"' "register: json has serial"
t::assert_contains "$(register::json)" '"drives":[' "register: json has drives"
t::assert_contains "$(register::json)" '"bios_lock":"' "register: json has bios_lock"

# --- selection legend keys ---
legend="$(select::legend)"
t::assert_contains "$legend" "T=start" "legend: start is Shift+T"
t::check "legend: no restart key during erasure" '[[ "$legend" != *"R=restart"* ]]'
t::check "legend: no shutdown key during erasure" '[[ "$legend" != *"S=shutdown"* ]]'
t::check "legend: S no longer means start" '[[ "$legend" != *"S=start"* ]]'

# --- selection power actions (Shift+R restart, Shift+S shutdown) ---
reboot() { FAKE_REBOOT=1; }
poweroff() { FAKE_POWEROFF=1; }

SELECT_MODE=1; SELECT_CURSOR="sda"
select::reboot >/dev/null
rc=$?
t::check "select: Shift+R aborts selection" '[[ "$rc" == "1" ]]'
t::check "select: Shift+R invokes reboot" '[[ "$FAKE_REBOOT" == "1" ]]'
t::check "select: Shift+R clears SELECT_MODE" '[[ "$SELECT_MODE" == "0" ]]'
t::check "select: Shift+R clears SELECT_CURSOR" '[[ -z "$SELECT_CURSOR" ]]'

SELECT_MODE=1; SELECT_CURSOR="sda"
select::shutdown >/dev/null
rc=$?
t::check "select: Shift+S aborts selection" '[[ "$rc" == "1" ]]'
t::check "select: Shift+S invokes poweroff" '[[ "$FAKE_POWEROFF" == "1" ]]'
t::check "select: Shift+S clears SELECT_CURSOR" '[[ -z "$SELECT_CURSOR" ]]'

t::summary
