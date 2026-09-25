#!/usr/bin/env bash
# 09 — corporate customer: the paid .lic sits at the root of a removable FAT32
# "boot USB", tScrub finds it via license::detect_usb (no --license flag), and
# the USB stick is excluded from the wipe target list (USB block devices are
# filtered out of device discovery).
set -euo pipefail
source "$(dirname "$0")/../lib.sh"

IP="$TEST_IP"
VMID="$(next_vmid)"
USB_IMG="$IMAGE_DIR/vm-$VMID-usb.img"
REPORTS="$IMAGE_DIR/e2e-usb-root"
mkdir -p "$REPORTS"
trap 'vm_destroy "$VMID"; rm -f "$USB_IMG"' EXIT

# Build a 64 MB image with a single FAT32 partition holding license.key at its
# root (the licence a customer drops onto the boot stick).
dd if=/dev/zero of="$USB_IMG" bs=1M count=64 status=none
printf 'start=2048, type=c\n' | sfdisk -q "$USB_IMG"
LOOP="$(losetup -Pf --show "$USB_IMG")"
trap 'umount /tmp/tscrub-usbmnt 2>/dev/null || true; losetup -d "$LOOP" 2>/dev/null || true; vm_destroy "$VMID"; rm -f "$USB_IMG"' EXIT
mkfs.vfat -F 32 "${LOOP}p1" >/dev/null
mkdir -p /tmp/tscrub-usbmnt
mount -o rw "${LOOP}p1" /tmp/tscrub-usbmnt
cp /var/lib/vz/tscrub-test/e2e-test.lic /tmp/tscrub-usbmnt/license.key
umount /tmp/tscrub-usbmnt
losetup -d "$LOOP"

debian_vm_create "$VMID" tscrub-usb-root "$IP"
disk_sata "$VMID" 0 2
# Attach the FAT image as a removable USB storage device. `removable=on` sets
# the SCSI RMB bit so the guest (and license::detect_usb) sees RM=1.
qm set "$VMID" -args "-drive file=$USB_IMG,if=none,id=usb0,format=raw -device qemu-xhci,id=xhci -device usb-storage,drive=usb0,bus=xhci.0,removable=on"
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"

# Push only the script — NO licence. The licence must be found on the USB root.
vm_ssh "$IP" 'mkdir -p /tmp/tscrub/out' >/dev/null 2>&1 || true
scp -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
    "$IMAGE_DIR/tscrub.sh" "$VM_USER@$IP:/tmp/tscrub/" >/dev/null

printf '13579\nC\n' | ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -tt \
    "$VM_USER@$IP" "cd /tmp/tscrub && sudo ./tscrub.sh --dry-run --output /tmp/tscrub/out 2>&1" \
    | tee "$REPORTS/run.log"

grep -q "Licence valid — signed reports enabled" "$REPORTS/run.log" \
    && echo "PASS: paid licence found on boot USB root" || { echo "FAIL: USB-root licence not detected" >&2; exit 1; }

scp -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
    "$VM_USER@$IP:/tmp/tscrub/out/tScrub_*" "$REPORTS/" >/dev/null 2>&1 || true

csv="$(cat "$REPORTS"/tScrub_*.csv)"
rows="$(tail -n +2 <<<"$csv" | grep -c .)"
if [[ "$rows" == "1" ]] && grep -qF "sda" <<<"$csv" && ! grep -qF "sdb" <<<"$csv"; then
    echo "PASS: only the SATA disk is a wipe target (USB stick excluded)"
else
    echo "FAIL: expected 1 wipe target (sda), USB not excluded (rows=$rows)" >&2
    exit 1
fi
