#!/usr/bin/env bash
# 04 — tscrub.sh without a licence exits non-zero with a clear error.
set -euo pipefail
source "$(dirname "$0")/../lib.sh"

IP="$TEST_IP"
VMID="$(next_vmid)"
trap 'vm_destroy "$VMID"' EXIT

debian_vm_create "$VMID" tscrub-nolic "$IP"
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"
vm_push_files "$IP"

out="$(vm_ssh "$IP" 'cd /tmp/tscrub && sudo ./tscrub.sh --output /tmp/tscrub/out 2>&1; echo EXIT=$?')"

assert_contains "$out" "No valid licence found" "licence required"
assert_contains "$out" "EXIT=1" "exits non-zero without a licence"
