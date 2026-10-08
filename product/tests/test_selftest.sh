#!/usr/bin/env bash
# Hardware self-tests: deterministic CPU arithmetic check and storage short
# self-test verdicts (ATA + NVMe), plus the run() orchestration.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

export FAKE_NVME_MODE=crypto
export FAKE_HDPARM_MODE=enhanced
export FAKE_USB_DEVICES="sdb"

device::install_sedutil >/dev/null 2>&1
device::discover

# --- CPU self-test ---
selftest::cpu
t::assert_eq "PASS" "$SELFTEST_CPU" "selftest: cpu arithmetic check passes"

# --- ATA storage self-test: PASS ---
export FAKE_SMART_SELFTEST="Completed without error       00%"
selftest::storage_ata sda
t::assert_eq "PASS" "${devrow[sda.selftest_run]}" "selftest: ata storage PASS"

# --- ATA storage self-test: FAIL ---
export FAKE_SMART_SELFTEST="Completed: read failure       40%"
selftest::storage_ata sda
t::assert_eq "FAIL" "${devrow[sda.selftest_run]}" "selftest: ata storage FAIL"

# --- ATA storage self-test: unknown ---
export FAKE_SMART_SELFTEST="Aborted by host               -0%"
selftest::storage_ata sda
t::assert_eq "UNKNOWN" "${devrow[sda.selftest_run]}" "selftest: ata storage UNKNOWN"

# --- NVMe storage self-test: PASS (Operation Result 0) ---
export FAKE_NVME_SELFTEST_RC=0
export FAKE_NVME_SELFTEST_RESULT="0"
selftest::storage_nvme nvme0n1
t::assert_eq "PASS" "${devrow[nvme0n1.selftest_run]}" "selftest: nvme storage PASS"

# --- NVMe storage self-test: FAIL (non-zero Operation Result) ---
export FAKE_NVME_SELFTEST_RESULT="5"
selftest::storage_nvme nvme0n1
t::assert_eq "FAIL" "${devrow[nvme0n1.selftest_run]}" "selftest: nvme storage FAIL"

# --- NVMe storage self-test: UNKNOWN (aborted/timeout, no valid result) ---
export FAKE_NVME_SELFTEST_RC=1
export FAKE_NVME_SELFTEST_RESULT="f"
selftest::storage_nvme nvme0n1
t::assert_eq "UNKNOWN" "${devrow[nvme0n1.selftest_run]}" "selftest: nvme storage UNKNOWN"

# --- orchestration ---
export FAKE_SMART_SELFTEST="Completed without error       00%"
export FAKE_NVME_SELFTEST_RC=0
export FAKE_NVME_SELFTEST_RESULT="0"
selftest::run
t::assert_eq "PASS" "$SELFTEST_CPU" "selftest: run sets CPU verdict"
t::assert_eq "PASS" "${devrow[sda.selftest_run]}" "selftest: run sets sda verdict"
t::assert_eq "PASS" "${devrow[nvme0n1.selftest_run]}" "selftest: run sets nvme verdict"

# --- opt-in flag parsing ---
SELFTEST=0
parse_args --selftest
t::check "selftest: --selftest enables" '[[ "$SELFTEST" -eq 1 ]]'

SELFTEST=1
parse_args --selftest=0
t::check "selftest: --selftest=0 disables" '[[ "$SELFTEST" -eq 0 ]]'

t::summary
