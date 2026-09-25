#!/usr/bin/env bash
# 05 — --dry-run writes a DRY-RUN report and does not wipe.
set -euo pipefail
source "$(dirname "$0")/../lib.sh"

IP="$TEST_IP"
VMID="$(next_vmid)"
trap 'vm_destroy "$VMID"' EXIT

debian_vm_create "$VMID" tscrub-dry "$IP"
disk_sata "$VMID" 0 2
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"

vm_run_tscrub "$IP" 12345 --dry-run --license /tmp/tscrub/test.lic --output /tmp/tscrub/out >/dev/null

csv="$(vm_ssh "$IP" 'cat /tmp/tscrub/out/tScrub_*.csv')"
assert_contains "$csv" "sda"       "drive still discovered in dry-run"
assert_contains "$csv" "DRY-RUN"   "report marked DRY-RUN"
assert_not_contains "$csv" "COMPLETED" "no drive reported COMPLETED in dry-run"
