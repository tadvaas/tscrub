#!/usr/bin/env bash
# 03 — NVMe + SATA + SCSI wiped in parallel; all three recorded.
set -euo pipefail
source "$(dirname "$0")/../lib.sh"

IP="$TEST_IP"
VMID="$(next_vmid)"
trap 'vm_destroy "$VMID"' EXIT

debian_vm_create "$VMID" tscrub-mixed "$IP"
disk_nvme "$VMID" 0 2
disk_sata "$VMID" 0 2
disk_scsi "$VMID" 1 2
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"

vm_run_tscrub "$IP" 12345 --license /tmp/tscrub/test.lic --output /tmp/tscrub/out >/dev/null

csv="$(vm_ssh "$IP" 'cat /tmp/tscrub/out/tScrub_*.csv')"
assert_contains "$csv" "nvme0n1" "NVMe drive recorded"
assert_contains "$csv" "sda"     "SATA drive recorded"
assert_contains "$csv" "sdb"     "SCSI drive recorded"

rows="$(vm_ssh "$IP" 'tail -n +2 /tmp/tscrub/out/tScrub_*.csv | grep -c .')"
[[ "$rows" == "3" ]] && echo "PASS: 3 drive rows in report" || { echo "FAIL: expected 3 rows, got $rows" >&2; exit 1; }
