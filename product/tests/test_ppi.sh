#!/usr/bin/env bash
# Tests for TCG Storage PPI "disable Block SID" (product/src/44_device_ppi.sh):
# operation mapping, spec-conformant TPM PPI sysfs submission (op 97), the
# vendor UEFI-variable fallback, and the resume marker.
#
# The sysfs write and the efivar write are both NVRAM-mutating in production, so
# every assertion that touches them runs against a fake PPI_TPM_SYSFS /
# PPI_EFIVARS_DIR tree and a no-op mount() — never the real efivarfs.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

declare -A serial devrow
LOG_FILE="$tmp/ppi.log"
TABLE_INDENT=""
devices=()
TS_PPI=0
TS_PPI_OP="disable_block_sid"
TS_PPI_EFIVAR=""
TS_PPI_RESUME=0
TS_PPI_RESUME_MARKER=""

# no-op + record the efivarfs remount so the write is inert but observable.
MOUNT_LOG="$tmp/mount.log"
mount() { printf '%s\n' "$*" >> "$MOUNT_LOG"; return 0; }

# --- operation mapping ------------------------------------------------------
t::assert_eq "97" "$(device::ppi_op_value disable_block_sid)" "ppi: op value 97"
t::assert_eq "96" "$(device::ppi_op_value enable_block_sid)"  "ppi: op value 96"
t::assert_eq "97" "$(device::ppi_op_value block_sid)"         "ppi: block_sid is an alias"
t::check "ppi: unknown op fails" '! device::ppi_op_value bogus'

# --- gated off by default ---------------------------------------------------
TS_PPI=0
t::check "ppi: gated off when TS_PPI=0" '! device::ppi_request disable_block_sid'

# --- primary path: TPM PPI sysfs --------------------------------------------
PPI_TPM_SYSFS="$tmp/ppisys"
mkdir -p "$tmp/ppisys/tpm0/ppi"
printf '1.3\n' > "$tmp/ppisys/tpm0/ppi/version"
req="$tmp/ppisys/tpm0/ppi/request"
: > "$req"

TS_PPI=1
t::check "ppi: sysfs submit succeeds" 'device::ppi_request disable_block_sid'
t::assert_eq "97" "$(cat "$req" 2>/dev/null)" "ppi: sysfs request wrote op 97"
t::check "ppi: sysfs submit does not touch efivarfs" '[[ ! -s "$MOUNT_LOG" ]]'
t::assert_eq "1.3" "$(device::ppi_version)" "ppi: version read"

# --- no sysfs, no efivar -> rc 1 --------------------------------------------
PPI_TPM_SYSFS="$tmp/empty"
mkdir -p "$tmp/empty"
TS_PPI_EFIVAR=""
t::check "ppi: no channel fails" '! device::ppi_request disable_block_sid'

# --- fallback: vendor UEFI variable -----------------------------------------
PPI_EFIVARS_DIR="$tmp/efivars"
mkdir -p "$tmp/efivars"
printf 'x' > "$tmp/efivars/StoragePPI"
TS_PPI_EFIVAR="StoragePPI"
: > "$MOUNT_LOG"
t::check "ppi: efivar fallback succeeds" 'device::ppi_request disable_block_sid'
t::assert_eq "97" "$(cat "$tmp/efivars/StoragePPI")" "ppi: efivar wrote op 97"
t::assert_contains "$(cat "$MOUNT_LOG")" "remount,rw" "ppi: efivarfs remounted rw"
t::assert_contains "$(cat "$MOUNT_LOG")" "remount,ro" "ppi: efivarfs remounted ro"

# --- probe (read-only) ------------------------------------------------------
printf '1.3\n' > "$tmp/ppisys/tpm0/ppi/version"
printf '' > "$tmp/ppisys/tpm0/ppi/request"
PPI_TPM_SYSFS="$tmp/ppisys"
probe_out="$(device::ppi_probe)"
t::assert_contains "$probe_out" "PPI sysfs:" "ppi: probe reports the sysfs channel"

# --- block_sid_cleared is honest-unknown -------------------------------------
device::block_sid_cleared
t::assert_eq "2" "$?" "ppi: block_sid_cleared returns unknown (2)"

# --- resume marker ----------------------------------------------------------
TS_PPI_RESUME_MARKER="$tmp/marker"
COCID="12345"
devices=(sda sdb)
serial[sda]="SERIALAAA"
serial[sdb]="SERIALBBB"
devrow[sda.block_sid]="yes"
devrow[sdb.block_sid]="no"

t::check "ppi: marker write succeeds" 'device::ppi_resume_marker_write'
t::assert_contains "$(cat "$TS_PPI_RESUME_MARKER")" "OP=disable_block_sid" "ppi: marker carries OP"
t::assert_contains "$(cat "$TS_PPI_RESUME_MARKER")" "COCID=12345" "ppi: marker carries COCID"
t::assert_contains "$(cat "$TS_PPI_RESUME_MARKER")" "SERIAL=SERIALAAA" "ppi: marker lists the blocked serial"
t::check "ppi: marker omits non-blocked serial" '[[ "$(cat "$TS_PPI_RESUME_MARKER")" != *"SERIALBBB"* ]]'
t::assert_eq "SERIALAAA" "$(device::ppi_resume_marker_read)" "ppi: marker read"

# --- resume apply filters devices[] to the marked serials --------------------
devices=(sda sdb sdc)
serial[sda]="SERIALAAA"; serial[sdb]="SERIALBBB"; serial[sdc]="SERIALCCC"
devrow=()
TS_PPI_RESUME=1
t::check "ppi: resume apply succeeds" 'device::ppi_resume_apply'
t::assert_eq "1" "${#devices[@]}" "ppi: resume filtered to one device"
t::assert_eq "sda" "${devices[0]}" "ppi: resume kept the marked drive"
t::assert_eq "yes" "${devrow[sda.ppi_resumed]}" "ppi: resumed flag set"
t::check "ppi: resume consumes the marker" '[[ ! -e "$TS_PPI_RESUME_MARKER" ]]'

# --- resume apply with no marker -> rc 1 ------------------------------------
devices=(sda)
t::check "ppi: resume apply with no marker fails" '! device::ppi_resume_apply'

t::summary
