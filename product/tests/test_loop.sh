#!/usr/bin/env bash
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

devices=(nvme0n1 sda)

reset_devices() {
    devrow=()
    local d
    for d in "${devices[@]}"; do
        devrow[$d.status]="PLANNED"
        devrow[$d.eta_mins]=""
        devrow[$d.wipe_start]=""
    done
}

# --- Test 1: EOF break (writers close, cat exits, read returns EOF) ---
reset_devices
ipc::open
UI_INPLACE=0
( echo "nvme0n1 STATUS COMPLETED" >&3
  echo "sda STATUS FAILED" >&3 ) &
exec 3>&-
exec {UI[1]}>&-
start=$(date +%s)
ui::loop >/dev/null 2>&1
elapsed=$(( $(date +%s) - start ))
t::check "EOF: loop exits quickly" '(( elapsed < 10 ))'
t::check "EOF: nvme COMPLETED" '[[ ${devrow[nvme0n1.status]} == "COMPLETED" ]]'
t::check "EOF: sda FAILED" '[[ ${devrow[sda.status]} == "FAILED" ]]'

# --- Test 2: terminal states without EOF (leaked writer holds fd 3 open) ---
reset_devices
ipc::open
UI_INPLACE=0
( echo "nvme0n1 STATUS COMPLETED" >&3
  echo "sda STATUS BLOCKED" >&3
  sleep 30 ) &
writer=$!
exec 3>&-
exec {UI[1]}>&-
start=$(date +%s)
ui::loop >/dev/null 2>&1
elapsed=$(( $(date +%s) - start ))
kill "$writer" 2>/dev/null
t::check "timeout-break: exits quickly (<10s) even without EOF" '(( elapsed < 10 ))'
t::check "timeout-break: nvme COMPLETED" '[[ ${devrow[nvme0n1.status]} == "COMPLETED" ]]'
t::check "timeout-break: sda BLOCKED" '[[ ${devrow[sda.status]} == "BLOCKED" ]]'

# --- Test 3: wipe_start recorded on first RUNNING ---
reset_devices
ipc::open
UI_INPLACE=0
devrow[nvme0n1.eta_mins]="10"
( echo "nvme0n1 STATUS RUNNING" >&3
  sleep 2
  echo "nvme0n1 STATUS COMPLETED" >&3 ) &
exec 3>&-
exec {UI[1]}>&-
ui::loop >/dev/null 2>&1
t::check "wipe_start recorded on RUNNING" '[[ -n "${devrow[nvme0n1.wipe_start]}" ]]'
t::check "wipe_start is numeric epoch" '[[ "${devrow[nvme0n1.wipe_start]}" =~ ^[0-9]+$ ]]'

t::summary
