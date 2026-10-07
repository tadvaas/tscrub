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
t::check "baseline discovers nvme0n1 + sda" '(( ${#devices[@]} == 2 ))'
hotplug::baseline
t::check "snapshot contains nvme0n1" 'grep -qx nvme0n1 <<<"$HOTPLUG_SNAPSHOT"'
t::check "snapshot excludes loop/sr/usb" \
    '[[ "$HOTPLUG_SNAPSHOT" != *"loop0"* && "$HOTPLUG_SNAPSHOT" != *"sr0"* && "$HOTPLUG_SNAPSHOT" != *"sdb"* ]]'

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

# --- USB-attached newcomer is ignored ---------------------------------------
mkdir -p "$tmpdir/block/sdd/queue" "$tmpdir/block/sdd/device"
printf 'sata\n' > "$tmpdir/block/sdd/device/transport"
printf '0\n' > "$tmpdir/block/sdd/queue/rotational"
FAKE_USB_DEVICES="sdb sdd"
t::check "USB newcomer not treated as a change" '! hotplug::poll'

rm -rf "$tmpdir"
t::summary
