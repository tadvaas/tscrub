#!/usr/bin/env bash
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

declare -A capability bus
OUT="$(mktemp)"

# ATA frozen -> STATUS FROZEN (regression: previously short-circuited to FAILED)
devices=(sda)
capability[sda]="CAP_ATA_FROZEN"
bus[sda]="SATA"
devrow=()
devrow[sda.class]="FROZEN"
devrow[sda.capability]="CAP_ATA_FROZEN"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "sda STATUS FROZEN" "frozen emits FROZEN"
t::check "frozen does NOT emit FAILED" '[[ "$captured" != *"sda STATUS FAILED"* ]]'

# ATA enhanced erase success
: > "$OUT"
capability[sda]="CAP_ATA_PURGE_ENHANCED"
devrow[sda.class]="PURGE"
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
export FAKE_HDPARM_MODE=enhanced
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "sda STATUS COMPLETED" "ATA enhanced erase -> COMPLETED"

# NVMe sanitize rejected with 0x4286 -> BLOCKED (not FAILED)
: > "$OUT"
devices=(nvme0n1)
capability[nvme0n1]="CAP_NVME_PURGE_CRYPTO"
devrow[nvme0n1.class]="PURGE"
devrow[nvme0n1.capability]="CAP_NVME_PURGE_CRYPTO"
export FAKE_NVME_SANITIZE_RC=1
export FAKE_NVME_SANITIZE_OUT="NVMe status 0x4286: Access Denied"
exec 3>"$OUT"
device::execute nvme0n1
exec 3>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "nvme0n1 STATUS BLOCKED" "0x4286 -> BLOCKED"
t::check "0x4286 does NOT emit FAILED" '[[ "$captured" != *"nvme0n1 STATUS FAILED"* ]]'

# NVMe sanitize rejected with 0x4015 ("Operation Denied") -> BLOCKED (not FAILED)
: > "$OUT"
export FAKE_NVME_SANITIZE_RC=1
export FAKE_NVME_SANITIZE_OUT="NVMe status: Operation Denied: The command was denied due to lack of access rights(0x4015)"
exec 3>"$OUT"
device::execute nvme0n1
exec 3>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "nvme0n1 STATUS BLOCKED" "0x4015 -> BLOCKED"
t::check "0x4015 does NOT emit FAILED" '[[ "$captured" != *"nvme0n1 STATUS FAILED"* ]]'
unset FAKE_NVME_SANITIZE_OUT

# NVMe crypto sanitize success (monitor sees SSTAT=0x1)
: > "$OUT"
export FAKE_NVME_SANITIZE_RC=0
export FAKE_NVME_SSTAT=0x1
unset FAKE_NVME_SANITIZE_OUT
exec 3>"$OUT"
device::execute nvme0n1
exec 3>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "nvme0n1 STATUS COMPLETED" "NVMe crypto -> COMPLETED"

# SCSI (non-ATA) nwipe success
: > "$OUT"
devices=(sda)
capability[sda]="CAP_SCSI_NWIPE"
devrow[sda.class]="CLEAR"
devrow[sda.capability]="CAP_SCSI_NWIPE"
export FAKE_NWIPE_RC=0
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "sda STATUS COMPLETED" "SCSI nwipe -> COMPLETED"

# SCSI nwipe failure -> FAILED
: > "$OUT"
export FAKE_NWIPE_RC=1
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "sda STATUS FAILED" "SCSI nwipe failure -> FAILED"
unset FAKE_NWIPE_RC

rm -f "$OUT"
t::summary
