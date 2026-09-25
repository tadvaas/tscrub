#!/usr/bin/env bash
# 08 — E2E customer: mixed-device wipe, report uploaded to a local FTP server
# (stands in for the customer's writable USB stick / network share).
set -euo pipefail
source "$(dirname "$0")/../lib.sh"

IP="$TEST_IP"
VMID="$(next_vmid)"
FTP_DIR="$IMAGE_DIR/e2e-pretend-usb"
FTP_TS_DIR="$FTP_DIR/tscrub"
REPORTS="$IMAGE_DIR/e2e-reports-ftp"
mkdir -p "$FTP_TS_DIR" "$REPORTS"
trap 'vm_destroy "$VMID"; pkill -f "pyftpdlib" 2>/dev/null || true' EXIT

FTP_HOST="$(ip -4 -o addr show "$BRIDGE" | awk '{print $4}' | cut -d/ -f1 | head -n1)"
python3 -m pyftpdlib -w -u "$FTP_USER" -P "$FTP_PASS" -p "$FTP_PORT" -d "$FTP_DIR" >/dev/null 2>&1 &
sleep 1

debian_vm_create "$VMID" tscrub-e2e-ftp "$IP"
disk_nvme "$VMID" 0 2
disk_sata "$VMID" 0 2
disk_scsi "$VMID" 1 2
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"

vm_ssh "$IP" 'mkdir -p /tmp/tscrub/out' >/dev/null 2>&1 || true
scp -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
    "$IMAGE_DIR/tscrub.sh" /var/lib/vz/tscrub-test/e2e-test.lic "$VM_USER@$IP:/tmp/tscrub/" >/dev/null

printf '54321\nC\n' | ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -tt \
    "$VM_USER@$IP" "cd /tmp/tscrub && sudo env TSCRUB_NET_PROTO=ftp TSCRUB_NET_HOST=$FTP_HOST TSCRUB_NET_PATH=tscrub TSCRUB_NET_USER=$FTP_USER TSCRUB_NET_PASS=$FTP_PASS ./tscrub.sh --license /tmp/tscrub/e2e-test.lic --output /tmp/tscrub/out 2>&1" \
    | tee "$REPORTS/run.log"

n="$(find "$FTP_TS_DIR" -maxdepth 1 -type f | wc -l)"
[[ "$n" -ge 3 ]] && echo "PASS: $n report files uploaded to pretend-USB FTP" || { echo "FAIL: expected >=3, got $n" >&2; exit 1; }
cp "$FTP_TS_DIR"/* "$REPORTS/" 2>/dev/null || true
echo "--- uploaded files ---"
ls -la "$REPORTS"
