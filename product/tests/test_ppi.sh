#!/usr/bin/env bash
# PPI "disable block SID authentication": the read-only probe, the candidate
# scan, and the gated request write. The write is NVRAM-mutating, so every
# assertion that touches it runs against a fake efivars tree (PPI_EFIVARS_DIR)
# and a no-op mount(), never the real efivarfs.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

declare -A devrow
LOG_FILE="$(mktemp /tmp/tscrub-ppi-log.XXXXXX)"
TABLE_INDENT="    "
EFIVARS="$(mktemp -d /tmp/tscrub-ppi-efivars.XXXXXX)"
PPI_EFIVARS_DIR="$EFIVARS"
mount() { return 0; }   # no-op the efivarfs remount so the write is inert

VAR="SomeVendorGuid-PpiStorage"

# --- probe: no variable configured -> fail --------------------------------
unset TS_PPI_EFIVAR
t::check "probe: unset TS_PPI_EFIVAR -> fail" "! device::ppi_probe"

# --- probe: configured + present -> prints path ---------------------------
TS_PPI_EFIVAR="$VAR"
printf 'stale' > "$EFIVARS/$VAR"
t::assert_eq "$EFIVARS/$VAR" "$(device::ppi_probe)" "probe returns the efivar path"

# --- probe: configured + absent -> fail -----------------------------------
TS_PPI_EFIVAR="NoSuch-Var"
t::check "probe: absent variable -> fail" "! device::ppi_probe"

# --- probe_list: only PPI/SID/Opal/storage candidates ---------------------
: > "$EFIVARS/SomeGuid-PpiStorage"
: > "$EFIVARS/SomeGuid-SidAuth"
: > "$EFIVARS/SomeGuid-OpalTable"
: > "$EFIVARS/SomeGuid-BootOrder"
out="$(device::ppi_probe_list)"
t::assert_contains "$out" "PpiStorage" "probe_list finds PPI candidates"
t::assert_contains "$out" "SidAuth" "probe_list finds SID candidates"
t::assert_contains "$out" "OpalTable" "probe_list finds Opal candidates"
t::check "probe_list excludes non-matching vars" "! grep -q 'BootOrder' <<<\"\$out\""

# --- request: not opted in -> no write, no truncate -----------------------
TS_PPI=0
TS_PPI_EFIVAR="$VAR"
printf 'stale' > "$EFIVARS/$VAR"
t::check "request: TS_PPI=0 -> refused" "! device::ppi_request block_sid"
t::assert_eq "stale" "$(cat "$EFIVARS/$VAR")" "request: not opted in leaves the var untouched"

# --- request: opted in but no variable -> fall back, no write -------------
TS_PPI=1
unset TS_PPI_EFIVAR
t::check "request: no TS_PPI_EFIVAR -> fall back" "! device::ppi_request block_sid"

# --- request: opted in + present -> writes (truncates) + returns 0 --------
TS_PPI=1
TS_PPI_EFIVAR="$VAR"
printf 'stale' > "$EFIVARS/$VAR"
t::check "request: staged -> returns 0" "device::ppi_request block_sid"
t::assert_eq "" "$(cat "$EFIVARS/$VAR")" "request: writes the PPI variable (empty payload)"

# --- request: absent variable -> fail, no crash ---------------------------
TS_PPI=1
TS_PPI_EFIVAR="NoSuch-Var"
t::check "request: absent variable -> fail" "! device::ppi_request block_sid"

unset TS_PPI TS_PPI_EFIVAR

t::summary
