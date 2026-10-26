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
t::check "the reason names the boot medium" '[[ "${devrow[sdb.protect]:-}" == *"boot medium"* ]]'
t::check "the notice explains the row"      '[[ "$DISCOVERY_NOTICE" == *"sdb"* ]]'

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

t::summary
