#!/usr/bin/env bash
# Tests for TCG Storage PPI "disable Block SID" (product/src/44_device_ppi.sh):
# operation mapping, the spec-conformant TPM PPI sysfs submission (op 97), the
# read-back loop guard, and the honest-unknown cleared check. The request is
# fully automatic (always on, sysfs-only) — no opt-in flags.
#
# The sysfs write is NVRAM-mutating in production, so every assertion that
# touches it runs against a fake PPI_TPM_SYSFS tree — never the real sysfs.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

declare -A serial devrow
LOG_FILE="$tmp/ppi.log"
TABLE_INDENT=""
devices=()

# --- operation mapping ------------------------------------------------------
t::assert_eq "97" "$(device::ppi_op_value disable_block_sid)" "ppi: op value 97"
t::assert_eq "96" "$(device::ppi_op_value enable_block_sid)"  "ppi: op value 96"
t::assert_eq "97" "$(device::ppi_op_value block_sid)"         "ppi: block_sid is an alias"
t::check "ppi: unknown op fails" '! device::ppi_op_value bogus'

# --- primary path: TPM PPI sysfs (always on, sysfs-only) --------------------
PPI_TPM_SYSFS="$tmp/ppisys"
mkdir -p "$tmp/ppisys/tpm0/ppi"
printf '1.3\n' > "$tmp/ppisys/tpm0/ppi/version"
req="$tmp/ppisys/tpm0/ppi/request"
: > "$req"

t::check "ppi: sysfs submit succeeds" 'device::ppi_request disable_block_sid'
t::assert_eq "97" "$(cat "$req" 2>/dev/null)" "ppi: sysfs request wrote op 97"
t::assert_eq "1.3" "$(device::ppi_version)" "ppi: version read"

# --- no TPM PPI sysfs -> rc 1 (BIOS guidance; no efivar guessing) -----------
PPI_TPM_SYSFS="$tmp/empty"
mkdir -p "$tmp/empty"
t::check "ppi: no channel fails" '! device::ppi_request disable_block_sid'

# --- probe (read-only) ------------------------------------------------------
printf '1.3\n' > "$tmp/ppisys/tpm0/ppi/version"
printf '' > "$tmp/ppisys/tpm0/ppi/request"
PPI_TPM_SYSFS="$tmp/ppisys"
probe_out="$(device::ppi_probe)"
t::assert_contains "$probe_out" "PPI sysfs:" "ppi: probe reports the sysfs channel"

# --- read-back: pending + response drive the one-shot loop guard -------------
PPI_TPM_SYSFS="$tmp/ppisys"
mkdir -p "$tmp/ppisys/tpm0/ppi"
printf '1.3\n' > "$tmp/ppisys/tpm0/ppi/version"
printf '0\n' > "$tmp/ppisys/tpm0/ppi/request"
printf '97 0: Success\n' > "$tmp/ppisys/tpm0/ppi/response"

t::assert_eq "0" "$(device::ppi_pending)" "ppi: pending reads 0 when nothing staged"
t::assert_eq "97 0: Success" "$(device::ppi_response)" "ppi: response read-back"

# response already carries the op -> already requested (the loop case: request
# consumed, firmware answered, drive still blocked)
t::check "ppi: already-requested true from response" 'device::ppi_already_requested disable_block_sid'

# a different answered op -> not this op
printf '96 0: Success\n' > "$tmp/ppisys/tpm0/ppi/response"
t::check "ppi: already-requested false for a different answered op" '! device::ppi_already_requested disable_block_sid'

# pending request carries the op -> already requested
printf '97\n' > "$tmp/ppisys/tpm0/ppi/request"
: > "$tmp/ppisys/tpm0/ppi/response"
t::check "ppi: already-requested true from pending op" 'device::ppi_already_requested disable_block_sid'

# nothing staged, no answer -> not already requested
printf '0\n' > "$tmp/ppisys/tpm0/ppi/request"
t::check "ppi: already-requested false when nothing staged or answered" '! device::ppi_already_requested disable_block_sid'

# idempotent submit: a request already carrying the op is not rewritten
printf '97\n' > "$tmp/ppisys/tpm0/ppi/request"
t::check "ppi: submit is idempotent when op already pending" 'device::ppi_request disable_block_sid'
t::assert_eq "97" "$(tr -d '\r\n' < "$tmp/ppisys/tpm0/ppi/request")" "ppi: pending op left intact"

# --- block_sid_cleared is honest-unknown -------------------------------------
device::block_sid_cleared
t::assert_eq "2" "$?" "ppi: block_sid_cleared returns unknown (2)"

t::summary
