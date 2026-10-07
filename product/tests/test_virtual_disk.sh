#!/usr/bin/env bash
# Quiet sedutil SG_IO noise on QEMU/virtual disks: discovery skips the OPAL
# probe for hypervisor/emulated drives (they can never be SEDs) and marks them
# NA instead, while real drives still get probed.

source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

# --- helper classification ---
t::check "QEMU HARDDISK classified virtual" 'device::is_virtual_disk "QEMU HARDDISK" "QM00001"'
t::check "VMware virtual disk classified virtual" 'device::is_virtual_disk "VMware Virtual disk" ""'
t::check "VBOX HARDDISK classified virtual" 'device::is_virtual_disk "VBOX HARDDISK" ""'
t::check "FAKE DISK not virtual" '! device::is_virtual_disk "FAKE DISK" "S123456789"'
t::check "Samsung SSD not virtual" '! device::is_virtual_disk "Samsung SSD 870 EVO" ""'

# --- discovery: QEMU sda skips the probe ---
export FAKE_HDPARM_MODE=enhanced
export FAKE_NVME_MODE=crypto
export FAKE_HDPARM_MODEL="QEMU HARDDISK"
export FAKE_HDPARM_SERIAL="QM00001"
export FAKE_SEDUTIL_LOG="$(mktemp)"
export FAKE_USB_DEVICES="sdb"

device::discover

t::assert_eq "NA" "${opal_locked[sda]:-}" "QEMU sda marked NA (no SED)"
t::check "sedutil never probed QEMU sda" '! grep -q "/dev/sda" "$FAKE_SEDUTIL_LOG"'
rm -f "$FAKE_SEDUTIL_LOG"

# --- discovery: a real drive still gets probed ---
unset FAKE_HDPARM_MODEL FAKE_HDPARM_SERIAL
export FAKE_SEDUTIL_LOCKED=0
export FAKE_SEDUTIL_LOG="$(mktemp)"

device::discover

t::assert_eq "NO" "${opal_locked[sda]:-}" "real sda still probed (not locked)"
t::check "sedutil probed real sda" 'grep -q "/dev/sda" "$FAKE_SEDUTIL_LOG"'
rm -f "$FAKE_SEDUTIL_LOG"

t::summary
