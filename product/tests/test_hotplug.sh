#!/usr/bin/env bash
# Tests for hot-plug drive detection: 47_hotplug.sh + device::rediscover.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

# Use a throwaway copy of the block tree so tests can add/remove fake devices
# without touching the shared fixtures.
tmpdir="$(mktemp -d)"
cp -r "$FIXTURES/sys/block" "$tmpdir/block"
export SYS_BLOCK_DIR="$tmpdir/block"
export FAKE_USB_DEVICES="sdb"      # sdb is a USB-attached drive (excluded)
export FAKE_NVME_MODE=crypto
export FAKE_HDPARM_MODE=enhanced

# --- snapshot mirrors discovery filters -------------------------------------
device::discover
t::check "baseline discovers nvme0n1 + sda + usb sdb" '(( ${#devices[@]} == 3 ))'
# USB is a listed row now, so a stick arriving or leaving IS a hot-plug event.
hotplug::baseline
t::check "snapshot contains nvme0n1" 'grep -qx nvme0n1 <<<"$HOTPLUG_SNAPSHOT"'
t::check "snapshot excludes loop/sr" \
    '[[ "$HOTPLUG_SNAPSHOT" != *"loop0"* && "$HOTPLUG_SNAPSHOT" != *"sr0"* ]]'
# The USB stick IS on screen, so it must be in the snapshot — otherwise a stick
# that arrives or leaves is invisible to hot-plug and never rescanned.
t::check "snapshot includes the usb target sdb" 'grep -qx sdb <<<"$HOTPLUG_SNAPSHOT"'

# --- a USB stick arriving after boot is a hot-plug event ---------------------
mkdir -p "$tmpdir/block/sde/queue" "$tmpdir/block/sde/device"
printf 'usb\n' > "$tmpdir/block/sde/device/transport"
printf '1\n' > "$tmpdir/block/sde/queue/rotational"
t::check "poll detects a newly plugged-in USB stick" 'hotplug::poll'
t::assert_eq "sde" "$(printf '%s' "${HOTPLUG_ADDED}" | tr -d '\n')" \
    "a USB stick plugged in after boot is added, not ignored"
rm -rf "$tmpdir/block/sde"
hotplug::baseline

# --- unchanged -> poll returns 1 --------------------------------------------
t::check "poll returns 1 when unchanged" '! hotplug::poll'

# --- added drive ------------------------------------------------------------
mkdir -p "$tmpdir/block/sdc/queue" "$tmpdir/block/sdc/device"
printf 'sata\n' > "$tmpdir/block/sdc/device/transport"
printf '1\n' > "$tmpdir/block/sdc/queue/rotational"

t::check "poll detects the added drive" 'hotplug::poll'
t::assert_eq "sdc" "$(printf '%s' "${HOTPLUG_ADDED}" | tr -d '\n')" "added list is sdc"
t::check "no removed drives" '[[ -z "${HOTPLUG_REMOVED}" ]]'

# Mark an existing row so we can prove rediscover leaves it alone.
devrow["sda.status"]="COMPLETED"
device::rediscover

t::check "rediscover adds sdc to devices" '[[ "${devices[*]}" == *"sdc"* ]]'
t::check "rediscover keeps existing drives" '[[ "${devices[*]}" == *"sda"* && "${devices[*]}" == *"nvme0n1"* ]]'
t::assert_eq "SATA" "${bus[sdc]}" "sdc bus"
t::assert_eq "HDD" "${type[sdc]}" "sdc type (rotational=1)"
t::assert_eq "CAP_ATA_PURGE_ENHANCED" "${capability[sdc]}" "sdc capability"
t::assert_eq "PURGE" "${devrow[sdc.class]}" "sdc classified"
t::assert_eq "PLANNED" "${devrow[sdc.status]}" "sdc row status planned"
t::assert_eq "COMPLETED" "${devrow[sda.status]}" "existing row status untouched"
hotplug::baseline

# --- removed drive ----------------------------------------------------------
rm -rf "$tmpdir/block/sda"
t::check "poll detects the removed drive" 'hotplug::poll'
t::assert_eq "sda" "$(printf '%s' "${HOTPLUG_REMOVED}" | tr -d '\n')" "removed list is sda"

device::rediscover
t::check "rediscover drops the removed drive" '[[ "${devices[*]}" != *"sda"* ]]'
t::check "rediscover keeps nvme0n1 and sdc" '[[ "${devices[*]}" == *"nvme0n1"* && "${devices[*]}" == *"sdc"* ]]'
hotplug::baseline

# --- a USB-attached newcomer IS a change now -------------------------------
# It used to be ignored, when USB drives were never offered. P2 made them
# selectable, so ignoring one means a stick plugged in at the bench never
# appears on screen.
mkdir -p "$tmpdir/block/sdd/queue" "$tmpdir/block/sdd/device"
printf 'sata\n' > "$tmpdir/block/sdd/device/transport"
printf '0\n' > "$tmpdir/block/sdd/queue/rotational"
FAKE_USB_DEVICES="sdb sdd"
t::check "a USB-attached newcomer is a change" 'hotplug::poll'
t::assert_eq "sdd" "$(printf '%s' "${HOTPLUG_ADDED}" | tr -d '\n')" \
    "and it is named in the added list"

rm -rf "$tmpdir"
t::summary
