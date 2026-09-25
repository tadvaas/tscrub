#!/usr/bin/env bash
# 12 — Rufus-ISO-mode layout: a SINGLE writable FAT32 partition (no separate
# TSCRUB-USB, no boot/version.txt marker — Rufus flattens the hybrid ISO into
# one partition). The report must land on the SAME volume the licence was found
# on (LICENSE_USB_DEV preference in report::mount_boot_usb).
set -euo pipefail
source "$(dirname "$0")/../lib.sh"

IP="$TEST_IP"
VMID="$(next_vmid)"
USB_IMG="$IMAGE_DIR/vm-$VMID-usb.img"
REPORTS="$IMAGE_DIR/e2e-report-rufus"
mkdir -p "$REPORTS"
trap 'vm_destroy "$VMID"; rm -f "$USB_IMG"' EXIT

# 64 MB image, single FAT32 partition. Rufus puts the .lic + boot files all on
# this one partition; there is no boot/version.txt marker.
dd if=/dev/zero of="$USB_IMG" bs=1M count=64 status=none
printf 'start=2048, type=c\n' | sfdisk -q "$USB_IMG"
LOOP="$(losetup -Pf --show "$USB_IMG")"
trap 'umount /tmp/tscrub-usbmnt 2>/dev/null || true; losetup -d "$LOOP" 2>/dev/null || true; vm_destroy "$VMID"; rm -f "$USB_IMG"' EXIT
mkfs.vfat -F 32 "${LOOP}p1" >/dev/null
mkdir -p /tmp/tscrub-usbmnt
mount -o rw "${LOOP}p1" /tmp/tscrub-usbmnt
mkdir -p /tmp/tscrub-usbmnt/boot
echo "kernel-placeholder" > /tmp/tscrub-usbmnt/boot/bzImage   # boot content, no version.txt
cp "$TEST_LICENCE" /tmp/tscrub-usbmnt/license.key
umount /tmp/tscrub-usbmnt
losetup -d "$LOOP"

debian_vm_create "$VMID" tscrub-report-rufus "$IP"
disk_sata "$VMID" 0 2
qm set "$VMID" -args "-drive file=$USB_IMG,if=none,id=usb0,format=raw -device qemu-xhci,id=xhci -device usb-storage,drive=usb0,bus=xhci.0,removable=off"
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"

vm_ssh "$IP" 'mkdir -p /tmp/tscrub/out' >/dev/null 2>&1 || true
scp -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
    "$IMAGE_DIR/tscrub.sh" "$VM_USER@$IP:/tmp/tscrub/" >/dev/null

printf '24680\nC\n' | ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -tt \
    "$VM_USER@$IP" "cd /tmp/tscrub && sudo ./tscrub.sh --dry-run 2>&1" \
    | tee "$REPORTS/run.log"

LOOP="$(losetup -Pf --show "$USB_IMG")"
mkdir -p /tmp/tscrub-usbmnt
mount -o ro "${LOOP}p1" /tmp/tscrub-usbmnt
echo "=== single-partition contents ==="
ls -la /tmp/tscrub-usbmnt
csv="$(find /tmp/tscrub-usbmnt -maxdepth 1 -name 'tScrub_*.csv' | head -1)"
umount /tmp/tscrub-usbmnt
losetup -d "$LOOP"

if [[ -n "$csv" ]]; then
    echo "PASS: report landed on the same partition as the licence: $(basename "$csv")"
else
    echo "FAIL: no tScrub_*.csv on the single Rufus partition" >&2
    exit 1
fi
