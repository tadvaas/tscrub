#!/usr/bin/env bash
# HPA/DCO hidden-area removal: detection, reset ordering, and the honest
# removed|firmware-erased|none|failed result vocabulary.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

declare -A bus devrow
bus[sda]="SATA"
devrow=()
# fd 3 is the worker -> UI IPC channel; the reset fn logs over it. Open it so
# the LOG lines don't spam "Bad file descriptor" on macOS (no real worker).
exec 3>/dev/null

TMP="$(mktemp -d)"
LOG="$TMP/hdparm.log"
HPA_STATE="$TMP/hpa.state"
DCO_STATE="$TMP/dco.state"

reset_env() {
    devrow[sda.hpa_result]=""
    devrow[sda.dco_result]=""
    : > "$LOG"
    rm -f "$HPA_STATE" "$DCO_STATE"
    unset FAKE_HPA_NATIVE FAKE_HPA_STATE FAKE_HPA_CUR FAKE_HPA_SET_RC \
          FAKE_DCO_PRESENT FAKE_DCO_RESTORE_RC HPA_MODE DRY_RUN
    export FAKE_HDPARM_LOG="$LOG" FAKE_HPA_STATE_FILE="$HPA_STATE" \
           FAKE_DCO_STATE_FILE="$DCO_STATE"
}

# 1. none path: no HPA, no DCO -> none/none, no commands issued.
reset_env
devrow[sda.capability]="CAP_ATA_CLEAR"
device::reset_hpa_dco sda
t::assert_eq "none" "${devrow[sda.hpa_result]}" "no HPA -> hpa_result none"
t::assert_eq "none" "${devrow[sda.dco_result]}" "no DCO -> dco_result none"
t::check "none path issues no commands" '[[ ! -s "$LOG" ]]'

# 2. HPA enabled + DCO present -> dco-restore then -N p<native>, both removed.
reset_env
devrow[sda.capability]="CAP_ATA_CLEAR"
export FAKE_HPA_NATIVE=123456789 FAKE_HPA_STATE=enabled FAKE_DCO_PRESENT=1
device::reset_hpa_dco sda
t::assert_eq "removed" "${devrow[sda.hpa_result]}" "HPA removed"
t::assert_eq "removed" "${devrow[sda.dco_result]}" "DCO removed"
t::assert_eq "--dco-restore
-N p123456789" "$(cat "$LOG")" "dco-restore before -N p<native>"

# 3. HPA enabled, no DCO -> only -N p<native>, hpa removed, dco none.
reset_env
devrow[sda.capability]="CAP_ATA_CLEAR"
export FAKE_HPA_NATIVE=99999 FAKE_HPA_STATE=enabled
device::reset_hpa_dco sda
t::assert_eq "removed" "${devrow[sda.hpa_result]}" "HPA removed (no DCO)"
t::assert_eq "none" "${devrow[sda.dco_result]}" "dco none"
t::assert_eq "-N p99999" "$(cat "$LOG")" "no --dco-restore issued"

# 4. HPA reset fails on CAP_ATA_CLEAR -> failed (data may remain).
reset_env
devrow[sda.capability]="CAP_ATA_CLEAR"
export FAKE_HPA_NATIVE=123456789 FAKE_HPA_STATE=enabled FAKE_HPA_SET_RC=1
device::reset_hpa_dco sda
t::assert_eq "failed" "${devrow[sda.hpa_result]}" "CLEAR + reset fail -> failed"

# 5. HPA reset fails on CAP_ATA_PURGE_ENHANCED -> firmware-erased (data safe).
reset_env
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
export FAKE_HPA_NATIVE=123456789 FAKE_HPA_STATE=enabled FAKE_HPA_SET_RC=1
device::reset_hpa_dco sda
t::assert_eq "firmware-erased" "${devrow[sda.hpa_result]}" "ENHANCED + reset fail -> firmware-erased"

# 6. DCO restore fails on CAP_ATA_CLEAR -> dco failed, hpa still removed.
reset_env
devrow[sda.capability]="CAP_ATA_CLEAR"
export FAKE_HPA_NATIVE=123456789 FAKE_HPA_STATE=enabled FAKE_DCO_PRESENT=1 FAKE_DCO_RESTORE_RC=1
device::reset_hpa_dco sda
t::assert_eq "removed" "${devrow[sda.hpa_result]}" "HPA still removed"
t::assert_eq "failed" "${devrow[sda.dco_result]}" "CLEAR + DCO restore fail -> failed"

# 7. --hpa off -> no work, results left unset (CSV default n/a).
reset_env
devrow[sda.capability]="CAP_ATA_CLEAR"
export FAKE_HPA_NATIVE=123456789 FAKE_HPA_STATE=enabled FAKE_DCO_PRESENT=1
HPA_MODE=off
device::reset_hpa_dco sda
t::assert_eq "" "${devrow[sda.hpa_result]}" "--hpa off leaves hpa_result unset"
t::check "--hpa off issues no commands" '[[ ! -s "$LOG" ]]'

# 8. DRY_RUN -> no work.
reset_env
devrow[sda.capability]="CAP_ATA_CLEAR"
export FAKE_HPA_NATIVE=123456789 FAKE_HPA_STATE=enabled
DRY_RUN=1
device::reset_hpa_dco sda
t::check "DRY_RUN issues no commands" '[[ ! -s "$LOG" ]]'

# 9. Non-ATA bus -> no work.
reset_env
devrow[sda.capability]="CAP_ATA_CLEAR"
export FAKE_HPA_NATIVE=123456789 FAKE_HPA_STATE=enabled
bus[sda]="SCSI"
device::reset_hpa_dco sda
t::check "SCSI bus issues no commands" '[[ ! -s "$LOG" ]]'

rm -rf "$TMP"
t::summary
