#!/usr/bin/env bash
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

export FAKE_NVME_MODE=crypto
export FAKE_HDPARM_MODE=enhanced
export FAKE_USB_DEVICES="sdb"

device::discover

t::check "discover found 3 rows (nvme0n1, sda, usb sdb)" '(( ${#devices[@]} == 3 ))'
t::check "nvme0n1 present" '[[ "${devices[*]}" == *"nvme0n1"* ]]'
t::check "sda present" '[[ "${devices[*]}" == *"sda"* ]]'
# USB is no longer silently dropped: it is LISTED so the operator can see it,
# and made unselectable so it cannot be erased. The exclusion that used to live
# here is now the selectable flag — see test_usb_protect.sh.
t::check "sdb (usb) is listed" '[[ "${devices[*]}" == *"sdb"* ]]'
t::assert_eq "USB" "${bus[sdb]:-}" "the usb row says BUS=USB"
# P2: an unclaimed USB target IS selectable (it is a legitimate wipe target).
# The protected case — the appliance's own boot stick — is covered in
# test_usb_protect.sh, which is where the exclusion now lives.
t::assert_eq "1" "${devrow[sdb.selectable]:-missing}" "the usb row can be selected"
t::assert_eq "1" "${devrow[sda.selectable]:-missing}" "a native row can be selected"
t::assert_eq "NVMe" "${bus[nvme0n1]}" "nvme bus"
t::assert_eq "SSD" "${type[nvme0n1]}" "nvme type"
t::assert_eq "SN-NVME-1" "${serial[nvme0n1]}" "nvme serial"
t::assert_eq "NVMe Fake Model" "${model[nvme0n1]}" "nvme model"
t::assert_eq "SATA" "${bus[sda]}" "sda bus"
t::assert_eq "HDD" "${type[sda]}" "sda type (rotational=1)"
t::assert_eq "NO" "${opal_locked[sda]}" "sda opal not locked"
t::assert_eq "1953525168" "${sectors[sda]}" "sda total 512-byte sectors"
t::assert_eq "512" "${secsize[sda]}" "sda logical sector size"
t::assert_eq "N/A" "${hpa[sda]}" "sda HPA state N/A (no hdparm -N output)"
t::assert_eq "Doesn't exist" "${dco[sda]}" "sda DCO not present"
t::assert_eq "1953525168" "${sectors[nvme0n1]}" "nvme total 512-byte sectors"
t::assert_eq "512" "${secsize[nvme0n1]}" "nvme logical sector size"
t::assert_eq "N/A" "${hpa[nvme0n1]}" "nvme HPA N/A"

# --- SAS serial via sg_vpd (SAS devices have no sysfs serial file) ---------
export FAKE_SG_VPD_SERIAL="SN-SAS-1"
serial=()
device::identity_fallback sda "$SYS_BLOCK_DIR"
t::assert_eq "SN-SAS-1" "${serial[sda]:-}" "SAS serial comes from sg_vpd VPD 0x80"
unset FAKE_SG_VPD_SERIAL
serial=()
device::identity_fallback sda "$SYS_BLOCK_DIR"
t::assert_eq "" "${serial[sda]:-}" "no VPD serial -> serial stays empty"

# --- SATA behind SAS (SAT): kernel says SCSI, IDENTIFY proves ATA ----------
_sat_tmp="$(mktemp -d)"
mkdir -p "$_sat_tmp/block/sdq/queue" "$_sat_tmp/block/sdq/device"
printf '1' > "$_sat_tmp/block/sdq/queue/rotational"
# No transport file -> the readlink fallback cannot see ata/sas -> bus=SCSI,
# which is exactly the real mpt3sas+SATA shape on the bench. (The fake
# realpath expects a /block/ path segment, so the tmpdir mirrors the real
# sysfs layout.)
export SYS_BLOCK_DIR="$_sat_tmp/block"
export FAKE_USB_DEVICES=""
export FAKE_HDPARM_MODE=enhanced
devices=(); unset bus
device::discover
device::detect
t::assert_eq "SATA" "${bus[sdq]:-}" "SATA behind SAS reclassified by IDENTIFY"
t::assert_eq "CAP_ATA_PURGE_ENHANCED" "${capability[sdq]:-}" "SAT SATA gets the ATA purge path"

export FAKE_HDPARM_MODE=sas
devices=(); unset bus
device::discover
device::detect
t::assert_eq "SCSI" "${bus[sdq]:-}" "true SAS stays SCSI"
t::assert_eq "CAP_SCSI_NWIPE" "${capability[sdq]:-}" "true SAS keeps the SCSI fallback"
unset SYS_BLOCK_DIR FAKE_HDPARM_MODE
export FAKE_HDPARM_MODE=enhanced
rm -rf "$_sat_tmp"

t::summary
