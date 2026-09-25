#!/usr/bin/env bash
# 01 — a single NVMe disk is discovered, wiped, and reported.
set -euo pipefail
source "$(dirname "$0")/../lib.sh"

IP="$TEST_IP"
VMID="$(next_vmid)"
trap 'vm_destroy "$VMID"' EXIT

debian_vm_create "$VMID" tscrub-nvme "$IP"
disk_nvme "$VMID" 0 2
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"

vm_run_tscrub "$IP" 12345 --license /tmp/tscrub/test.lic --output /tmp/tscrub/out >/dev/null

csv="$(vm_ssh "$IP" 'cat /tmp/tscrub/out/tScrub_*.csv')"
assert_contains "$csv" "nvme0n1" "NVMe drive discovered and recorded"
assert_contains "$csv" "NVMe" "NVMe bus recorded"

vm_ssh "$IP" 'python3 -c "import json,glob; json.load(open(glob.glob(\"/tmp/tscrub/out/tScrub_*.json\")[0]))"' \
    && echo "PASS: manifest is valid JSON" || { echo "FAIL: manifest JSON" >&2; exit 1; }

echo "--- report rows ---"
vm_ssh "$IP" 'cat /tmp/tscrub/out/tScrub_*.csv'
