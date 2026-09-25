#!/usr/bin/env bash
# End-to-end smoke test of the non-interactive dry-run flow:
# install_sedutil -> discover -> handle_locks -> frozen -> detect -> build ->
# execute (workers) -> ui::loop.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

export FAKE_NVME_MODE=crypto
export FAKE_HDPARM_MODE=enhanced
export FAKE_NVME_SSTAT=0x1
export FAKE_USB_DEVICES="sdb"

DRY_RUN=1
START_TS=$(date +%s)

device::install_sedutil
device::discover
device::handle_locks

ipc::open
device::frozen >/dev/null 2>&1
device::detect
table::build
table::render >/dev/null 2>&1

pids=()
for dev in "${devices[@]}"; do
    device::execute "$dev" &
    pids+=($!)
done
exec 3>&-
exec {UI[1]}>&-

ui::loop >/dev/null 2>&1

for pid in "${pids[@]}"; do
    wait "$pid" 2>/dev/null || true
done

t::check "smoke: 2 devices" '(( ${#devices[@]} == 2 ))'
t::check "smoke: nvme0n1 COMPLETED" '[[ ${devrow[nvme0n1.status]} == "COMPLETED" ]]'
t::check "smoke: nvme0n1 PURGE" '[[ ${devrow[nvme0n1.class]} == "PURGE" ]]'
t::check "smoke: sda COMPLETED" '[[ ${devrow[sda.status]} == "COMPLETED" ]]'
t::check "smoke: sda PURGE" '[[ ${devrow[sda.class]} == "PURGE" ]]'

# --- cocid via CLI flag enables non-interactive (autonuke) mode ---
COCID=""
NON_INTERACTIVE=0
parse_args --cocid 12345
t::check "cocid: --cocid sets COCID" '[[ "$COCID" == "12345" ]]'
t::check "cocid: --cocid enables non-interactive" '[[ "$NON_INTERACTIVE" -eq 1 ]]'

t::summary
