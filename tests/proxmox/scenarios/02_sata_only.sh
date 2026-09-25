#!/usr/bin/env bash
# 02 — a single SATA (AHCI) disk is discovered and classified. QEMU's ide-hd
# device implements no ATA Security feature set, so tScrub correctly flags it
# PHYS_DESTR (physical destruction) rather than wiping it.
set -euo pipefail
source "$(dirname "$0")/../lib.sh"

IP="$TEST_IP"
VMID="$(next_vmid)"
trap 'vm_destroy "$VMID"' EXIT

debian_vm_create "$VMID" tscrub-sata "$IP"
disk_sata "$VMID" 0 2
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"

vm_run_tscrub "$IP" 12345 --license /tmp/tscrub/test.lic --output /tmp/tscrub/out >/dev/null

csv="$(vm_ssh "$IP" 'cat /tmp/tscrub/out/tScrub_*.csv')"
assert_contains "$csv" "sda" "SATA drive discovered"
assert_contains "$csv" "PHYS_DESTR" "secure-erase-incapable SATA classified PHYS_DESTR"
