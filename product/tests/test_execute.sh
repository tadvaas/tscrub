#!/usr/bin/env bash
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

declare -A capability bus
OUT="$(mktemp)"
tmp="$(mktemp -d)"

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

# Block SID (0x4286) with automatic PSID policy -> PSID revert is the purge
: > "$OUT"
devices=(nvme0n1)
declare -A serial
capability[nvme0n1]="CAP_NVME_PURGE_CRYPTO"
devrow[nvme0n1.class]="PURGE"
devrow[nvme0n1.capability]="CAP_NVME_PURGE_CRYPTO"
serial[nvme0n1]="SED-SERIAL-1"
PSID_MAP=()
PSID_MAP[SED-SERIAL-1]="ABCDEF1234567890ABCDEF1234567890"
export TS_BLOCK_SID_POLICY=psid
export FAKE_NVME_SANITIZE_RC=1
export FAKE_NVME_SANITIZE_OUT="NVMe status 0x4286: Access Denied"
export FAKE_PSID_RC=0
exec 3>"$OUT"
device::execute nvme0n1
exec 3>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "nvme0n1 STATUS COMPLETED" "Block SID + psid policy -> PSID revert COMPLETED"
t::assert_eq "PSID Revert (Purge)" "${devrow[nvme0n1.method]}" "Block SID + psid -> method recorded"
t::assert_eq "PURGE" "${devrow[nvme0n1.class]}" "Block SID + psid -> class PURGE"
t::assert_eq "yes" "${devrow[nvme0n1.psid_revert]}" "Block SID + psid -> psid_revert=yes"
unset TS_BLOCK_SID_POLICY FAKE_NVME_SANITIZE_RC FAKE_NVME_SANITIZE_OUT FAKE_PSID_RC

# Block SID with psid policy but no PSID in the map -> BLOCKED (unchanged)
: > "$OUT"
devices=(nvme0n1)
capability[nvme0n1]="CAP_NVME_PURGE_CRYPTO"
devrow[nvme0n1.class]="PURGE"
devrow[nvme0n1.capability]="CAP_NVME_PURGE_CRYPTO"
serial[nvme0n1]="SED-SERIAL-1"
PSID_MAP=()
export TS_BLOCK_SID_POLICY=psid
export FAKE_NVME_SANITIZE_RC=1
export FAKE_NVME_SANITIZE_OUT="NVMe status 0x4286: Access Denied"
exec 3>"$OUT"
device::execute nvme0n1
exec 3>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "nvme0n1 STATUS BLOCKED" "Block SID + psid policy, no PSID -> BLOCKED"
unset TS_BLOCK_SID_POLICY FAKE_NVME_SANITIZE_RC FAKE_NVME_SANITIZE_OUT

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

# NVMe sanitize fails once (generic firmware error) then the FLR retry succeeds
: > "$OUT"
devices=(nvme0n1)
capability[nvme0n1]="CAP_NVME_PURGE_CRYPTO"
devrow[nvme0n1.class]="PURGE"
devrow[nvme0n1.capability]="CAP_NVME_PURGE_CRYPTO"
printf '1\n0\n' > "$tmp/rcseq"
export FAKE_NVME_SANITIZE_RC_SEQ="$tmp/rcseq"
export FAKE_NVME_SANITIZE_OUT="NVMe status 0x1: Invalid Field in CDB"
export FAKE_NVME_SSTAT=0x1
exec 3>"$OUT"
device::execute nvme0n1
exec 3>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "nvme0n1 STATUS COMPLETED" "retry: second purge succeeds -> COMPLETED"
t::assert_contains "$captured" "retrying once" "retry: announces the FLR retry"
unset FAKE_NVME_SANITIZE_RC_SEQ FAKE_NVME_SANITIZE_OUT

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

# normalize_outcome: non-completed drives must not keep optimistic class/cert/method
devices=(nvme0n1 sda)
devrow=()
devrow[nvme0n1.status]="BLOCKED"
devrow[nvme0n1.class]="PURGE"
devrow[nvme0n1.cert]="DESTRUCTION"
devrow[nvme0n1.method]="NVMe Crypto Purge"
devrow[sda.status]="FAILED"
devrow[sda.class]="PURGE"
devrow[sda.cert]="DESTRUCTION"
devrow[sda.method]="Enhanced Erase"
device::normalize_outcome
t::assert_eq "BLOCKED" "${devrow[nvme0n1.class]}" "blocked class -> BLOCKED"
t::assert_eq "NOT SANITISED" "${devrow[nvme0n1.cert]}" "blocked cert -> NOT SANITISED"
t::assert_eq "Blocked by firmware (Block SID)" "${devrow[nvme0n1.method]}" "blocked method honest"
t::assert_eq "FAILED" "${devrow[sda.class]}" "failed class -> FAILED"
t::assert_eq "NOT SANITISED" "${devrow[sda.cert]}" "failed cert -> NOT SANITISED"
t::assert_eq "Sanitisation failed" "${devrow[sda.method]}" "failed method honest"

# COMPLETED drives are left untouched
devices=(nvme0n1)
devrow=()
devrow[nvme0n1.status]="COMPLETED"
devrow[nvme0n1.class]="PURGE"
devrow[nvme0n1.cert]="DESTRUCTION"
devrow[nvme0n1.method]="NVMe Crypto Purge"
device::normalize_outcome
t::assert_eq "PURGE" "${devrow[nvme0n1.class]}" "completed class kept"
t::assert_eq "DESTRUCTION" "${devrow[nvme0n1.cert]}" "completed cert kept"
t::assert_eq "NVMe Crypto Purge" "${devrow[nvme0n1.method]}" "completed method kept"

# FROZEN drives are left untouched (already honest)
devices=(sda)
devrow=()
devrow[sda.status]="FROZEN"
devrow[sda.class]="FROZEN"
devrow[sda.cert]="PHYS_DESTR"
devrow[sda.method]="Frozen Drive"
device::normalize_outcome
t::assert_eq "FROZEN" "${devrow[sda.class]}" "frozen class kept"
t::assert_eq "PHYS_DESTR" "${devrow[sda.cert]}" "frozen cert kept"
t::assert_eq "Frozen Drive" "${devrow[sda.method]}" "frozen method kept"

# DRY-RUN drives are normalised (nothing was wiped)
devices=(sda)
devrow=()
devrow[sda.status]="DRY-RUN"
devrow[sda.class]="PURGE"
devrow[sda.cert]="DESTRUCTION"
devrow[sda.method]="NVMe Crypto Purge"
device::normalize_outcome
t::assert_eq "DRY-RUN" "${devrow[sda.class]}" "dry-run class -> DRY-RUN"
t::assert_eq "NOT SANITISED" "${devrow[sda.cert]}" "dry-run cert -> NOT SANITISED"
t::assert_eq "Dry run — no sanitisation performed" "${devrow[sda.method]}" "dry-run method honest"

rm -f "$OUT"
rm -rf "$tmp"
t::summary
