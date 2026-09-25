#!/usr/bin/env bash
# 06 — the report is uploaded to a local FTP server (CSV + manifest + signature).
# Upload config is injected via the TSCRUB_NET_* environment (report::upload_net
# reads TSCRUB_NET_PROTO/HOST/PATH/USER/PASS directly).
set -euo pipefail
source "$(dirname "$0")/../lib.sh"

IP="$TEST_IP"
VMID="$(next_vmid)"
FTP_DIR="$IMAGE_DIR/ftp"
FTP_TS_DIR="$FTP_DIR/tscrub"
trap 'vm_destroy "$VMID"; pkill -f "pyftpdlib" 2>/dev/null || true' EXIT

# Local FTP server on the Proxmox host (single user tscrub/testpass).
mkdir -p "$FTP_TS_DIR"
FTP_HOST="$(ip -4 -o addr show "$BRIDGE" | awk '{print $4}' | cut -d/ -f1 | head -n1)"
python3 -m pyftpdlib -w -u "$FTP_USER" -P "$FTP_PASS" -p "$FTP_PORT" -d "$FTP_DIR" >/dev/null 2>&1 &
sleep 1

debian_vm_create "$VMID" tscrub-ftp "$IP"
disk_sata "$VMID" 0 2
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"
vm_push_files "$IP"

printf '12345\nC\n' | ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -tt \
    "$VM_USER@$IP" "cd /tmp/tscrub && sudo env TSCRUB_NET_PROTO=ftp TSCRUB_NET_HOST=$FTP_HOST TSCRUB_NET_PATH=tscrub TSCRUB_NET_USER=$FTP_USER TSCRUB_NET_PASS=$FTP_PASS ./tscrub.sh --license /tmp/tscrub/test.lic --output /tmp/tscrub/out 2>&1" >/dev/null

n="$(find "$FTP_TS_DIR" -maxdepth 1 -type f | wc -l)"
[[ "$n" -ge 3 ]] && echo "PASS: $n report files uploaded to FTP" || { echo "FAIL: expected >=3 files, got $n" >&2; exit 1; }
find "$FTP_TS_DIR" -maxdepth 1 -type f -printf '%f\n'
