#!/usr/bin/env bash
# SAS/SCSI firmware sanitise: support probe, classification, execution, and
# the unsupported -> nwipe fallback.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

declare -A capability bus devrow

# --- support probe ----------------------------------------------------------
export FAKE_SG_OPCODES_SUPPORTED=yes
t::check "probe supported" 'device::scsi_sanitize_supported sda'
export FAKE_SG_OPCODES_SUPPORTED=no
t::check "probe unsupported" '! device::scsi_sanitize_supported sda'
unset FAKE_SG_OPCODES_SUPPORTED

# --- classify ---------------------------------------------------------------
capability[sda]="CAP_SCSI_SANITIZE"
device::classify sda
t::assert_eq "PURGE" "${devrow[sda.class]}" "classify: SAS sanitize -> PURGE"
t::assert_eq "DESTRUCTION" "${devrow[sda.cert]}" "classify: SAS sanitize -> DESTRUCTION"
t::assert_eq "SAS Sanitize" "${devrow[sda.method]}" "classify: SAS sanitize method"

# --- execute: success -------------------------------------------------------
devices=(sda)
bus[sda]="SAS"
devrow=()
devrow[sda.capability]="CAP_SCSI_SANITIZE"
devrow[sda.class]="PURGE"
devrow[sda.cert]="DESTRUCTION"
devrow[sda.method]="SAS Sanitize"
export FAKE_SG_SANITIZE_RC=0
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"; rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS COMPLETED" "sanitize success -> COMPLETED"

# --- execute: hard failure (not an "unsupported" rejection) -----------------
OUT="$(mktemp)"
export FAKE_SG_SANITIZE_RC=1
export FAKE_SG_SANITIZE_OUT="medium error"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"; rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS FAILED" "sanitize hard failure -> FAILED"
unset FAKE_SG_SANITIZE_OUT

# --- execute: unsupported -> nwipe fallback + honest outcome -----------------
OUT="$(mktemp)"
export FAKE_SG_SANITIZE_RC=1
export FAKE_SG_SANITIZE_OUT="Illegal request, invalid field in cdb"
export FAKE_NWIPE_RC=0
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"; rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS COMPLETED" "unsupported sanitize -> nwipe COMPLETED"
t::assert_contains "$captured" "falling back to nwipe" "fallback logged"
t::assert_eq "CLEAR" "${devrow[sda.class]}" "fallback corrects class -> CLEAR"
t::assert_eq "SANITISATION" "${devrow[sda.cert]}" "fallback corrects cert -> SANITISATION"
t::assert_eq "nwipe Quick" "${devrow[sda.method]}" "fallback corrects method -> nwipe Quick"
unset FAKE_SG_SANITIZE_RC FAKE_SG_SANITIZE_OUT FAKE_NWIPE_RC

t::summary
