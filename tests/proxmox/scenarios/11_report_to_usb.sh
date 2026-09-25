#!/usr/bin/env bash
# 11 — report must be written to the boot USB's writable FAT partition and
# PERSIST after the run (report::mount_boot_usb + report::sync_out).
#
# Reproduces the "nothing saved to USB" report on the HP ZBook: the USB is
# attached with removable=OFF so the guest sees RM=0 (fixed disk), and no
# --output flag is passed — so report::detect_output must locate the writable
# partition via the boot/version.txt marker.
set -euo pipefail
source "$(dirname "$0")/../lib.sh"

IP="$TEST_IP"
VMID="$(next_vmid)"
USB_IMG="$IMAGE_DIR/vm-$VMID-usb.img"
REPORTS="$IMAGE_DIR/e2e-report-usb"
mkdir -p "$REPORTS"
trap 'vm_destroy "$VMID"; rm -f "$USB_IMG"' EXIT

# 64 MB image with a single FAT32 partition carrying boot/version.txt (the
# writable-boot marker) and license.key (the .lic a customer drops on the stick).
dd if=/dev/zero of="$USB_IMG" bs=1M count=64 status=none
printf 'start=2048, type=c\n' | sfdisk -q "$USB_IMG"
LOOP="$(losetup -Pf --show "$USB_IMG")"
trap 'umount /tmp/tscrub-usbmnt 2>/dev/null || true; losetup -d "$LOOP" 2>/dev/null || true; vm_destroy "$VMID"; rm -f "$USB_IMG"' EXIT
mkfs.vfat -F 32 "${LOOP}p1" >/dev/null
mkdir -p /tmp/tscrub-usbmnt
mount -o rw "${LOOP}p1" /tmp/tscrub-usbmnt
mkdir -p /tmp/tscrub-usbmnt/boot
echo "v1.4.18" > /tmp/tscrub-usbmnt/boot/version.txt
cp "$TEST_LICENCE" /tmp/tscrub-usbmnt/license.key
umount /tmp/tscrub-usbmnt
losetup -d "$LOOP"

debian_vm_create "$VMID" tscrub-report-usb "$IP"
disk_sata "$VMID" 0 2   # wipe target (sda) so the report has a real drive row
# removable=OFF → the guest reports RM=0, exactly like the ZBook's USB stick.
qm set "$VMID" -args "-drive file=$USB_IMG,if=none,id=usb0,format=raw -device qemu-xhci,id=xhci -device usb-storage,drive=usb0,bus=xhci.0,removable=off"
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"

# Push only the script — no licence, no --output. Both must come from the USB.
vm_ssh "$IP" 'mkdir -p /tmp/tscrub/out' >/dev/null 2>&1 || true
scp -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
    "$IMAGE_DIR/tscrub.sh" "$VM_USER@$IP:/tmp/tscrub/" >/dev/null

printf '24680\nC\n' | ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -tt \
    "$VM_USER@$IP" "cd /tmp/tscrub && sudo ./tscrub.sh --dry-run 2>&1" \
    | tee "$REPORTS/run.log"

# The report should now live ON the USB image (persisted after sync_out
# unmounted it). Re-mount the image and look for the tScrub_*.csv.
LOOP="$(losetup -Pf --show "$USB_IMG")"
mkdir -p /tmp/tscrub-usbmnt
mount -o ro "${LOOP}p1" /tmp/tscrub-usbmnt
echo "=== USB partition contents ==="
ls -la /tmp/tscrub-usbmnt
csv="$(find /tmp/tscrub-usbmnt -maxdepth 1 -name 'tScrub_*.csv' | head -1)"
umount /tmp/tscrub-usbmnt
losetup -d "$LOOP"

if [[ -n "$csv" ]]; then
    echo "PASS: report persisted on the USB writable partition: $(basename "$csv")"
else
    echo "FAIL: no tScrub_*.csv on the USB partition" >&2
    exit 1
fi
