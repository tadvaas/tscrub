#!/usr/bin/env bash
# Tests SMART capture (pre/post) parsing for ATA and NVMe devices.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

# --- ATA (SATA HDD/SSD) -----------------------------------------------------
export FAKE_SMART_MODE=pass
export FAKE_SMART_TEMP=36 FAKE_SMART_POH=12345 FAKE_SMART_CYCLES=55
export FAKE_SMART_REALLOC=2 FAKE_SMART_LIFE_REMAIN=95 FAKE_SMART_LBA_WRITTEN=123456789

devices=(sda nvme0n1)
devrow=()
smart::capture_all pre

t::assert_eq "PASS"   "${devrow[sda.smart]}"   "ATA health PASS"
t::assert_eq "36"     "${devrow[sda.temp]}"    "ATA temperature"
t::assert_eq "12345"  "${devrow[sda.poh]}"     "ATA power-on hours"
t::assert_eq "55"     "${devrow[sda.cycles]}"  "ATA power cycles"
t::assert_eq "2"      "${devrow[sda.realloc]}" "ATA reallocated sectors"
t::assert_eq "5"      "${devrow[sda.pct_used]}" "ATA percent used (100-95)"
t::assert_eq "0.06"   "${devrow[sda.tbw]}"     "ATA TBW (123456789*512/1e12)"

# --- NVMe -------------------------------------------------------------------
t::assert_eq "PASS"  "${devrow[nvme0n1.smart]}"    "NVMe health PASS"
t::assert_eq "41"    "${devrow[nvme0n1.temp]}"     "NVMe temperature"
t::assert_eq "12"    "${devrow[nvme0n1.pct_used]}" "NVMe percentage used"
t::assert_eq "100"   "${devrow[nvme0n1.spare]}"    "NVMe available spare"
t::assert_eq "8765"  "${devrow[nvme0n1.poh]}"      "NVMe power-on hours"
t::assert_eq "55"    "${devrow[nvme0n1.cycles]}"   "NVMe power cycles"
t::assert_eq "5.06"  "${devrow[nvme0n1.tbw]}"      "NVMe TBW (9876543*512000/1e12)"

# --- Post phase uses a distinct prefix --------------------------------------
smart::capture_all post
t::assert_eq "PASS" "${devrow[sda.smart_post]}" "ATA post health prefixed"
t::assert_eq "36"   "${devrow[sda.temp_post]}"  "ATA post temp prefixed"
t::assert_eq "41"   "${devrow[nvme0n1.temp_post]}" "NVMe post temp prefixed"

# --- Unsupported / failing drives degrade gracefully ------------------------
export FAKE_SMART_MODE=unsupported
devices=(sda)
devrow=()
smart::capture_all pre
t::assert_eq "UNSUP" "${devrow[sda.smart]}" "unsupported drive -> UNSUP"
t::assert_eq ""      "${devrow[sda.temp]}"  "unsupported drive -> no temp"

export FAKE_SMART_MODE=fail
devrow=()
smart::capture_all pre
t::assert_eq "FAIL" "${devrow[sda.smart]}" "failing drive -> FAIL"
t::assert_eq "36"   "${devrow[sda.temp]}"  "failing drive still records temp"

# --- A hung/dead drive must not stall capture ---------------------------------
export FAKE_SMART_MODE=hang
SMART_TIMEOUT=2
devices=(sda)
devrow=()
_start=$(date +%s)
smart::capture_all pre
_elapsed=$(( $(date +%s) - _start ))
t::check "hung smartctl times out (<5s)" '[[ $_elapsed -le 5 ]]'
t::assert_eq "" "${devrow[sda.smart]}" "hung drive leaves fields empty"

t::summary
