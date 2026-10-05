#!/usr/bin/env bash
# SED (OPAL) unlock + crypto-erase: capability fallback, classification, and
# the exec_opal crypto-erase path (success / revertTPer failure / owned drive).
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

declare -A capability bus opal_locked devrow

# --- detection: unlocked SED with no ATA erase -> OPAL crypto-erase ---------
devices=(sda)
bus[sda]="SATA"
opal_locked[sda]="NO"
export FAKE_HDPARM_MODE=none
device::detect
t::assert_eq "CAP_OPAL_CRYPTO" "${capability[sda]}" "unlocked SED w/o ATA erase -> CAP_OPAL_CRYPTO"

# --- detection: locked SED stays CAP_NONE (phys destruction) ----------------
devices=(sda)
opal_locked[sda]="YES"
device::detect
t::assert_eq "CAP_NONE" "${capability[sda]}" "locked SED -> CAP_NONE"

# --- detection: unlocked SED with ATA enhanced keeps the ATA path ------------
devices=(sda)
opal_locked[sda]="NO"
export FAKE_HDPARM_MODE=enhanced
device::detect
t::assert_eq "CAP_ATA_PURGE_ENHANCED" "${capability[sda]}" "unlocked SED + enhanced erase -> ATA path (not OPAL)"
unset FAKE_HDPARM_MODE

# --- classify ---------------------------------------------------------------
capability[sda]="CAP_OPAL_CRYPTO"
device::classify sda
t::assert_eq "PURGE" "${devrow[sda.class]}" "classify: OPAL -> PURGE"
t::assert_eq "DESTRUCTION" "${devrow[sda.cert]}" "classify: OPAL -> DESTRUCTION"
t::assert_eq "OPAL Crypto Erase" "${devrow[sda.method]}" "classify: OPAL method"

# --- execute: success -------------------------------------------------------
devices=(sda)
devrow=()
devrow[sda.capability]="CAP_OPAL_CRYPTO"
devrow[sda.class]="PURGE"
export FAKE_SEDUTIL_REVERT_RC=0
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"; rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS COMPLETED" "OPAL crypto-erase -> COMPLETED"

# --- execute: revertTPer fails -> FAILED ------------------------------------
OUT="$(mktemp)"
export FAKE_SEDUTIL_REVERT_RC=1
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"; rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS FAILED" "OPAL revertTPer failure -> FAILED"
unset FAKE_SEDUTIL_REVERT_RC

# --- execute: owned drive (initialSetup fails) -> FAILED ---------------------
OUT="$(mktemp)"
export FAKE_SEDUTIL_INITIALSETUP_RC=1
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"; rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS FAILED" "OPAL initialSetup failure -> FAILED"
unset FAKE_SEDUTIL_INITIALSETUP_RC

t::summary
