#!/usr/bin/env bash
# 07 — E2E customer: mixed-device wipe, report pushed to the tScrub dashboard
# (tscrub.com /api/reports) with the customer's API token.
set -euo pipefail
source "$(dirname "$0")/../lib.sh"

IP="$TEST_IP"
VMID="$(next_vmid)"
E2E_TOKEN="${E2E_API_TOKEN:?export E2E_API_TOKEN first}"
REPORTS="$IMAGE_DIR/e2e-reports-dashboard"
mkdir -p "$REPORTS"
trap 'vm_destroy "$VMID"' EXIT

debian_vm_create "$VMID" tscrub-e2e-dash "$IP"
disk_nvme "$VMID" 0 2
disk_sata "$VMID" 0 2
disk_scsi "$VMID" 1 2
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"

vm_ssh "$IP" 'mkdir -p /tmp/tscrub/out' >/dev/null 2>&1 || true
scp -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
    "$IMAGE_DIR/tscrub.sh" /var/lib/vz/tscrub-test/e2e-test.lic "$VM_USER@$IP:/tmp/tscrub/" >/dev/null

printf '67890\nC\n' | ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -tt \
    "$VM_USER@$IP" "cd /tmp/tscrub && sudo env TSCRUB_UPLOAD_URL=https://tscrub.com/api/reports TSCRUB_API_TOKEN=$E2E_TOKEN ./tscrub.sh --license /tmp/tscrub/e2e-test.lic --output /tmp/tscrub/out 2>&1" \
    | tee "$REPORTS/run.log"

grep -qE "Synced to tScrub dashboard" "$REPORTS/run.log" \
    && echo "PASS: dashboard upload confirmed" || { echo "FAIL: dashboard upload" >&2; exit 1; }

scp -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
    "$VM_USER@$IP:/tmp/tscrub/out/tScrub_*" "$REPORTS/" >/dev/null 2>&1 || true
echo "--- report files ---"
ls -la "$REPORTS"
