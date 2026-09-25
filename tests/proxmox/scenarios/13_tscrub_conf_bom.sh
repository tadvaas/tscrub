#!/usr/bin/env bash
# 13 — tscrub.conf with a UTF-8 BOM on the FIRST line must still load.
# Regression: v1.4.36 skipped the BOM-prefixed `tscrub_upload` key (the BOM
# glued itself to the key name), so only later lines (tscrub_cocid) parsed and
# the dashboard upload was silently unconfigured. v1.4.37 strips the BOM.
#
# Verifies end-to-end: with a BOM-prefixed tscrub.conf + license.key on a
# single FAT32 USB (Rufus-style, removable=off), the report uploads to a local
# receiver using the URL+token from the file, and the debug snapshot records
# the loaded keys.
set -euo pipefail
source "$(dirname "$0")/../lib.sh"

IP="$TEST_IP"
VMID="$(next_vmid)"
USB_IMG="$IMAGE_DIR/vm-$VMID-usb.img"
REPORTS="$IMAGE_DIR/e2e-tscrub-conf"
RECEIVER_OUT="/root/tscrub-tests/upload-out"
RECEIVER_LOG="/root/tscrub-tests/receiver.log"
RECEIVER_PID=""
mkdir -p "$REPORTS"

cleanup_usb() {
    umount /tmp/tscrub-usbmnt 2>/dev/null || true
    [[ -n "$LOOP" ]] && losetup -d "$LOOP" 2>/dev/null || true
}
cleanup_all() {
    [[ -n "$RECEIVER_PID" ]] && kill "$RECEIVER_PID" 2>/dev/null || true
    cleanup_usb
    vm_destroy "$VMID"
    rm -f "$USB_IMG"
}
trap cleanup_all EXIT

# ---- USB image: license.key + BOM-prefixed tscrub.conf ---------------------
dd if=/dev/zero of="$USB_IMG" bs=1M count=64 status=none
printf 'start=2048, type=c\n' | sfdisk -q "$USB_IMG"
LOOP="$(losetup -Pf --show "$USB_IMG")"
mkfs.vfat -F 32 "${LOOP}p1" >/dev/null
mkdir -p /tmp/tscrub-usbmnt
mount -o rw "${LOOP}p1" /tmp/tscrub-usbmnt
cp "$TEST_LICENCE" /tmp/tscrub-usbmnt/license.key
# BOM (EF BB BF) before the FIRST key only — the exact failure mode.
printf '\xef\xbb\xbftscrub_upload=http://192.168.0.85:8080/reports\ntscrub_api_token=test-token-123\ntscrub_cocid=13579\n' \
    > /tmp/tscrub-usbmnt/tscrub.conf
umount /tmp/tscrub-usbmnt
losetup -d "$LOOP"
LOOP=""

# ---- local upload receiver -------------------------------------------------
mkdir -p "$RECEIVER_OUT"
rm -f "$RECEIVER_OUT"/* 2>/dev/null || true
: > "$RECEIVER_LOG"
nohup python3 /root/tscrub-tests/upload_receiver.py > "$RECEIVER_LOG" 2>&1 &
RECEIVER_PID=$!
sleep 1

# ---- VM --------------------------------------------------------------------
debian_vm_create "$VMID" tscrub-conf-bom "$IP"
disk_sata "$VMID" 0 2
qm set "$VMID" -args "-drive file=$USB_IMG,if=none,id=usb0,format=raw -device qemu-xhci,id=xhci -device usb-storage,drive=usb0,bus=xhci.0,removable=off"
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"
vm_ssh "$IP" 'sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq curl >/dev/null' || true

vm_ssh "$IP" 'mkdir -p /tmp/tscrub/out' >/dev/null 2>&1 || true
scp -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
    "$IMAGE_DIR/tscrub.sh" "$VM_USER@$IP:/tmp/tscrub/" >/dev/null

# COCID comes from tscrub.conf (no prompt); feed "C" for the post-run prompt.
printf 'C\n' | ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -tt \
    "$VM_USER@$IP" "cd /tmp/tscrub && sudo ./tscrub.sh 2>&1" \
    | tee "$REPORTS/run.log"

# ---- assertions ------------------------------------------------------------
# 1. the receiver saw the upload (URL+token came from the BOM'd tscrub.conf).
if grep -q "POST /reports" "$RECEIVER_LOG"; then
    echo "PASS: upload reached the local receiver (BOM'd tscrub.conf loaded)"
else
    echo "FAIL: no upload received — tscrub.conf not applied" >&2
    echo "--- receiver.log ---"; cat "$RECEIVER_LOG"
    echo "--- run.log tail ---"; tail -n 40 "$REPORTS/run.log"
    exit 1
fi

# 2. the debug snapshot records the loaded keys (debug-file-only diagnostics).
LOOP="$(losetup -Pf --show "$USB_IMG")"
mkdir -p /tmp/tscrub-usbmnt
mount -o ro "${LOOP}p1" /tmp/tscrub-usbmnt
dbg="$(find /tmp/tscrub-usbmnt -maxdepth 1 -name 'tScrub_debug_*.txt' | head -1)"
if [[ -n "$dbg" ]]; then
    cp "$dbg" "$REPORTS/"
    if grep -q "tscrub_upload: set" "$dbg" && grep -q "tscrub_cocid: set" "$dbg"; then
        echo "PASS: debug snapshot records tscrub_upload + tscrub_cocid as set"
    else
        echo "FAIL: debug snapshot missing expected config keys" >&2
        grep -A8 "tscrub.conf (on-USB" "$dbg" || true
    fi
else
    echo "WARN: no debug snapshot found on the USB"
fi
umount /tmp/tscrub-usbmnt
losetup -d "$LOOP"
LOOP=""

echo "--- upload-out ---"
ls -la "$RECEIVER_OUT"
echo "--- run.log tail ---"
tail -n 20 "$REPORTS/run.log"
