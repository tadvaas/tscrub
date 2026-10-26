#!/usr/bin/env bash
# USB targets versus the appliance's own boot medium (plan P1).
#
# The feature is not "find USB drives"; it is "find USB drives without ever
# offering the one tScrub is running from", because erasing that destroys the
# report — the only artefact the customer bought. These checks assert the
# protection rules and the unselectable invariant, and none of them needs a USB
# stick: the sysfs tree, /proc/mounts and /proc/cmdline all point at fixtures.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# sdb is the USB stick in the fixture tree.
export FAKE_USB_DEVICES="sdb"
unset LICENSE_USB_DEV
export PROC_MOUNTS_FILE="$TMP/mounts"
export CMDLINE_FILE="$TMP/cmdline"
: > "$CMDLINE_FILE"

mounts() { printf '%s\n' "$@" > "$PROC_MOUNTS_FILE"; }

echo "== 1. partition -> disk mapping =="
t::assert_eq "sda"  "$(device::disk_of /dev/sda1)"        "sda1 belongs to sda"
t::assert_eq "sda"  "$(device::disk_of sda)"              "a whole disk maps to itself"
t::assert_eq "nvme0n1" "$(device::disk_of /dev/nvme0n1p2)" "nvme0n1p2 belongs to nvme0n1"
t::assert_eq "mmcblk0" "$(device::disk_of /dev/mmcblk0p1)" "mmcblk0p1 belongs to mmcblk0"

echo
echo "== 2. the protected set =="
mounts "/dev/sda2 / ext4 rw 0 0" "/dev/sdb1 /mnt/stick vfat rw 0 0"
p=" $(device::protected_disks) "
t::check "the root disk is protected"        '[[ "$p" == *" sda "* ]]'
t::check "a mounted USB volume protects its DISK, not just the partition" '[[ "$p" == *" sdb "* ]]'
t::check "the partition name itself is not what we protect" '[[ "$p" != *" sdb1 "* ]]'

echo
echo "== 3. the licence volume protects its disk even when root is elsewhere =="
mounts "/dev/sda2 / ext4 rw 0 0"
LICENSE_USB_DEV="/dev/sdc1"
p=" $(device::protected_disks) "
t::check "LICENSE_USB_DEV protects sdc" '[[ "$p" == *" sdc "* ]]'
unset LICENSE_USB_DEV

echo
echo "== 4. the writable boot partition is found by its marker =="
mkdir -p "$TMP/bootmnt/boot" && : > "$TMP/bootmnt/boot/version.txt"
mounts "/dev/sda2 / ext4 rw 0 0" "/dev/sdd1 $TMP/bootmnt vfat rw 0 0"
p=" $(device::protected_disks) "
t::check "a volume carrying boot/version.txt is protected" '[[ "$p" == *" sdd "* ]]'

echo
echo "== 5. an unresolvable boot mount fails CLOSED =="
rm -rf "$TMP/live" && mkdir -p "$TMP/live/boot" && : > "$TMP/live/boot/version.txt"
mounts "overlay / overlay rw 0 0" "overlay $TMP/live overlay ro 0 0"
device::protected_disks >/dev/null
t::assert_eq "1" "${DEVICE_PROTECT_UNRESOLVED:-0}" \
    "a boot marker on a non-/dev mount sets the unresolved flag"

echo
echo "== 6. discovery lists the USB stick but refuses to make it selectable =="
unset DEVICE_PROTECT_UNRESOLVED
mounts "/dev/sda2 / ext4 rw 0 0" "/dev/sdb1 /mnt/stick vfat rw 0 0"
DISCOVERY_NOTICE=""
device::discover || true
t::check "the USB stick is listed (visibility)" '[[ " ${devices[*]} " == *" sdb "* ]]'
t::check "the native disk is still listed"      '[[ " ${devices[*]} " == *" sda "* ]]'
t::assert_eq "USB" "${bus[sdb]:-}"        "the USB row says BUS=USB"
t::assert_eq "0" "${devrow[sdb.selectable]:-missing}" "the USB row is NOT selectable"
t::assert_eq "1" "${devrow[sda.selectable]:-missing}" "the native row IS selectable"
t::check "the reason describes the mounted volume" '[[ "${devrow[sdb.protect]:-}" == *"mounted volume"* ]]'
t::check "the notice explains the row"      '[[ "$DISCOVERY_NOTICE" == *"sdb"* ]]'

echo
echo "== 6b. the reason says WHICH rule applied =="
# "You cannot erase this" is useless without the reason, and the bench showed
# why: a stick tScrub had mounted for its own report was described as the BOOT
# MEDIUM, which is a different disk and a different decision.
mounts "/dev/sda2 / ext4 rw 0 0" "/dev/sdb1 /mnt/stick vfat rw 0 0"
: > "$CMDLINE_FILE"
LICENSE_USB_DEV=""
REPORT_USB_MNT=""
device::protected_disks >/dev/null
t::assert_eq "has a mounted volume" "${DEVICE_PROTECT_REASON[sdb]:-}" \
    "an ordinary mounted volume is described as such"
t::check "a mounted data stick is NOT called a boot medium" \
    '[[ "${DEVICE_PROTECT_REASON[sdb]:-}" != *"boot medium"* ]]'

# The volume tScrub mounted itself for the report: tScrub's own choice, and the
# operator needs to know that is why the row is refused.
REPORT_USB_MNT="/mnt/stick"
device::protected_disks >/dev/null
t::check "the report volume says it is the report volume" \
    '[[ "${DEVICE_PROTECT_REASON[sdb]:-}" == *"writing this run"* ]]'

# The licence volume.
REPORT_USB_MNT=""
LICENSE_USB_DEV="/dev/sdb1"
device::protected_disks >/dev/null
t::check "the licence volume says it holds the licence" \
    '[[ "${DEVICE_PROTECT_REASON[sdb]:-}" == *"licence"* ]]'
LICENSE_USB_DEV=""

# The boot medium outranks everything else about the same disk.
printf 'root=/dev/sdb2 console=tty3\n' > "$CMDLINE_FILE"
REPORT_USB_MNT="/mnt/stick"
device::protected_disks >/dev/null
t::check "the boot medium wins over a report mount" \
    '[[ "${DEVICE_PROTECT_REASON[sdb]:-}" == *"boot medium"* ]]'
: > "$CMDLINE_FILE"
REPORT_USB_MNT=""

echo
echo "== 7. selection cannot reach it =="
select::none
select::all
t::assert_eq "1" "${devrow[sda.selected]:-0}" "select-all takes the native disk"
t::assert_eq "0" "${devrow[sdb.selected]:-0}" "select-all skips the protected row"
select::toggle sdb
t::assert_eq "0" "${devrow[sdb.selected]:-0}" "space cannot select a protected row"
t::check "every native disk is chosen"                   '[[ "$(select::chosen)" == *"sda "* && "$(select::chosen)" == *"nvme0n1 "* ]]'
t::check "the chosen set never contains the protected row" '[[ "$(select::chosen)" != *"sdb"* ]]' 

echo
echo "== 8. an empty card reader is skipped AND reported =="
export FAKE_BLOCKDEV_SIZE=0
DISCOVERY_NOTICE=""
device::discover || true
t::check "a zero-capacity USB node is not listed" '[[ " ${devices[*]} " != *" sdb "* ]]'
t::check "the absence is reported, not silent"   '[[ "$DISCOVERY_NOTICE" == *"USB media absent"* ]]'
unset FAKE_BLOCKDEV_SIZE

echo
echo "== 9. is_usb_attached agrees for both signals =="
t::check "sdb is USB (path/transport signal)" 'device::is_usb_attached sdb "$SYS_BLOCK_DIR"'
t::check "sda is not USB"                     '! device::is_usb_attached sda "$SYS_BLOCK_DIR"'

echo
echo "== 10. P2: an ordinary USB target IS selectable, and is erased by nwipe =="
unset DEVICE_PROTECT_UNRESOLVED
# Nothing claims sdb any more: root and the licence are elsewhere.
mounts "/dev/sda2 / ext4 rw 0 0"
unset LICENSE_USB_DEV
DISCOVERY_NOTICE=""
device::discover || true
t::assert_eq "1" "${devrow[sdb.selectable]:-missing}" "an unclaimed USB target is selectable"
t::assert_eq ""  "${devrow[sdb.protect]:-}"           "and carries no protection reason"
# The bridge ADVERTISES SANITIZE here. That is the case that matters: a native
# disk would take the firmware path (CAP_SCSI_SANITIZE), but a USB-SATA bridge
# that claims SANITIZE frequently refuses it or drops off the bus mid-erase, so
# we refuse the firmware path for USB and fall back to software overwrite.
export FAKE_SG_OPCODES_SUPPORTED=yes
device::detect
t::assert_eq "CAP_SCSI_NWIPE" "${capability[sdb]:-}" \
    "USB is software-overwritten even when the bridge claims SANITIZE"
unset FAKE_SG_OPCODES_SUPPORTED
select::none
select::all
t::check "select-all now takes the USB stick too" '[[ "$(select::chosen)" == *"sdb"* ]]'
t::assert_eq "SATA" "${bus[sda]:-}" "the native disk keeps its own bus"

echo
echo "== 11. the IEEE media gate still refuses USB =="
t::check "USB/HDD is out of scope for the claim" '! standard::media_in_scope USB HDD'
t::check "SATA/HDD is in scope (control)"        'standard::media_in_scope SATA HDD'

echo
echo "== 12. a USB boot names NO device anywhere (the real appliance case) =="
# Measured on a QEMU USB boot: the live system unpacks the rootfs into RAM and
# unmounts the stick, so /proc/mounts holds only rootfs/tmpfs and the cmdline is
# just BOOT_IMAGE=/boot/bzImage console=... . The boot stick must still be found,
# or it gets offered for erasure.
BTREE="$TMP/boottree"
mkdir -p "$BTREE/sda/sda1" "$BTREE/sda/sda3" "$BTREE/sdb"
: > "$BTREE/sda/sda1/partition"
: > "$BTREE/sda/sda3/partition"
: > "$BTREE/sda/queue"      2>/dev/null || true
export FAKE_BLKID_LABEL_sda1="TSCRUB"
export FAKE_BLKID_LABEL_sda3="TSCRUB-USB"
mounts "rootfs / rootfs rw 0 0" "proc /proc proc rw 0 0"
: > "$CMDLINE_FILE"

p=" $(SYS_BLOCK_DIR="$BTREE" device::protected_disks) "
t::check "the boot stick is protected by its own filesystem label" '[[ "$p" == *" sda "* ]]'
t::check "a plain USB stick is NOT protected"                      '[[ "$p" != *" sdb "* ]]'
SYS_BLOCK_DIR="$BTREE" device::protected_disks >/dev/null
t::assert_eq "0" "${DEVICE_PROTECT_UNRESOLVED:-missing}" \
    "a label means the boot medium WAS identified"

# Nothing identifies anything, and this is not a network boot: fail closed.
unset FAKE_BLKID_LABEL_sda1 FAKE_BLKID_LABEL_sda3
p=" $(SYS_BLOCK_DIR="$BTREE" device::protected_disks) "
SYS_BLOCK_DIR="$BTREE" device::protected_disks >/dev/null
t::assert_eq "1" "${DEVICE_PROTECT_UNRESOLVED:-missing}" \
    "no boot medium and no label => fail closed"
t::check "and no stick can be proven safe, so none is protected by name" '[[ -z "${p// /}" ]]'

# A network boot has no local medium by design, so silence is correct there.
printf 'BOOTIF=01-bc-24-11-98-d9-d1 ip=dhcp\n' > "$CMDLINE_FILE"
SYS_BLOCK_DIR="$BTREE" device::protected_disks >/dev/null
t::assert_eq "0" "${DEVICE_PROTECT_UNRESOLVED:-missing}" "a PXE boot does not fail closed"
export SYS_BLOCK_DIR="$FIXTURES/sys/block"
: > "$CMDLINE_FILE"

t::summary
