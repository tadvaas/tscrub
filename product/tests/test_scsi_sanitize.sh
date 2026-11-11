#!/usr/bin/env bash
# SAS/SCSI firmware sanitise: support probe, classification, execution, and
# the unsupported -> nwipe fallback.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src
export VERIFY_MODE=none   # device::execute tests must not run the real plant

declare -A capability bus devrow

# The pump sleeps SG_SANITIZE_POLL_SECONDS between samples; keep the tests
# instant.
export SG_SANITIZE_POLL_SECONDS=0

# --- support probe ----------------------------------------------------------
export FAKE_SG_OPCODES_SUPPORTED=yes
t::check "probe supported (current sg_opcodes wording)" 'device::scsi_sanitize_supported sda'
unset FAKE_SG_OPCODES_SUPPORTED
export FAKE_SG_OPCODES_LEGACY=yes
t::check "probe supported (legacy sg_opcodes wording)" 'device::scsi_sanitize_supported sda'
unset FAKE_SG_OPCODES_LEGACY
export FAKE_SG_OPCODES_SUPPORTED=no
t::check "probe unsupported (NOT supported wording)" '! device::scsi_sanitize_supported sda'
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

# --- execute: live progress from the drive's sense data ---------------------
# The sequence drives the pump: one progress sample, then completion.
OUT="$(mktemp)"
seq="$(mktemp)"
printf 'pct:40.28\ndone\n' > "$seq"
export FAKE_SG_SANITIZE_RC=0
export FAKE_SG_REQUESTS_SEQ_FILE="$seq"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"; rm -f "$OUT" "$seq"
t::assert_contains "$captured" "sda STATUS 40%" "sanitize progress -> STATUS %"
t::assert_contains "$captured" "sda PHASE WRITE" "sanitize progress -> PHASE WRITE"
t::assert_contains "$captured" "sda STATUS COMPLETED" "progress pump still ends COMPLETED"
unset FAKE_SG_REQUESTS_SEQ_FILE FAKE_SG_SANITIZE_RC

# --- execute: sanitize accepted, then fails in the drive --------------------
OUT="$(mktemp)"
seq="$(mktemp)"
printf 'fail\n' > "$seq"
export FAKE_SG_SANITIZE_RC=0
export FAKE_SG_REQUESTS_SEQ_FILE="$seq"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"; rm -f "$OUT" "$seq"
t::assert_contains "$captured" "sda STATUS FAILED" "drive-side sanitize failure -> FAILED"
unset FAKE_SG_REQUESTS_SEQ_FILE FAKE_SG_SANITIZE_RC

# --- ETA from two progress samples ------------------------------------------
t::assert_eq "60" "$(scsi::eta_remaining 10 0 40 30)" "eta: 30pct in 30s -> 60s left"
t::check "eta refuses flat progress" '! scsi::eta_remaining 10 0 10 30'
t::check "eta refuses no elapsed time" '! scsi::eta_remaining 10 30 40 30'

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
t::assert_contains "$captured" "sda METHOD Zero fill" "fallback emits the standard label over IPC"
t::assert_contains "$captured" "sda STANDARD zero" "fallback emits STANDARD over IPC"
t::assert_contains "$captured" "sda PASSES 1" "fallback emits PASSES over IPC"
t::assert_contains "$captured" "sda ERASER nwipe 0.41" "fallback emits the engine+version"
t::assert_contains "$captured" "sda CLASS CLEAR" "fallback emits CLASS over IPC"
t::assert_contains "$captured" "sda CERT SANITISATION" "fallback emits CERT over IPC"
unset FAKE_SG_SANITIZE_RC FAKE_SG_SANITIZE_OUT FAKE_NWIPE_RC

t::summary
