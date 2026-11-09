#!/usr/bin/env bash
# RAID dismantling (detection only): per-drive raid flag + triage warning line.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

declare -Ag raid bus

# --- unit: md superblock member ---------------------------------------------
export FAKE_MDADM_MEMBER=yes
device::raid_detect sda
t::assert_eq "md" "${raid[sda]}" "md superblock -> raid=md"
unset FAKE_MDADM_MEMBER

# --- unit: no signal -> none -------------------------------------------------
device::raid_detect sda
t::assert_eq "none" "${raid[sda]}" "no signal -> raid=none"

# --- unit: NVMe + VMD -> vmd -------------------------------------------------
SYS_VMD=1
device::raid_detect nvme0n1
t::assert_eq "vmd" "${raid[nvme0n1]}" "NVMe + VMD -> raid=vmd"
unset SYS_VMD

# --- unit: RAID HBA present -> hba ------------------------------------------
SYS_RAID_HBA=1
device::raid_detect sda
t::assert_eq "hba" "${raid[sda]}" "RAID HBA -> raid=hba"
unset SYS_RAID_HBA

# --- discovery populates the per-drive flag (VMD path) ----------------------
SYS_VMD=1
export FAKE_NVME_MODE=crypto
export FAKE_HDPARM_MODE=enhanced
export FAKE_USB_DEVICES="sdb"
devices=()
device::discover >/dev/null 2>&1
t::assert_eq "vmd" "${raid[nvme0n1]}" "discover: NVMe + VMD -> raid=vmd"
t::assert_eq "none" "${raid[sda]}" "discover: SATA + no signal -> raid=none"
unset SYS_VMD

# --- platform detection: name pattern + the SAS3008/3108 driver check -------
system::raid_hba_present && _rc=0 || _rc=1
t::assert_eq "1" "$_rc" "no RAID names in lspci -> no RAID HBA"

export FAKE_LSPCI_RAID_HBA=1
system::raid_hba_present && _rc=0 || _rc=1
t::assert_eq "0" "$_rc" "Smart Array in lspci -> RAID HBA present"
unset FAKE_LSPCI_RAID_HBA

_tmpdir="$(mktemp -d)"
mkdir -p "$_tmpdir/drivers/mpt3sas"
export FAKE_LSPCI_SAS3008=1
TS_RAID_DRIVERS="$_tmpdir/drivers" system::raid_hba_present && _rc=0 || _rc=1
t::assert_eq "1" "$_rc" "SAS3008 behind mpt3sas (IT mode) -> no RAID HBA"

mkdir -p "$_tmpdir/drivers/megaraid_sas"
touch "$_tmpdir/drivers/megaraid_sas/01:00.0"
TS_RAID_DRIVERS="$_tmpdir/drivers" system::raid_hba_present && _rc=0 || _rc=1
t::assert_eq "0" "$_rc" "SAS3008 bound to megaraid_sas (IR mode) -> RAID HBA"
unset FAKE_LSPCI_SAS3008 TS_RAID_DRIVERS
rm -rf "$_tmpdir"

# --- triage warning line ----------------------------------------------------
raid[nvme0n1]="vmd"
raid[sda]="md"
devices=(nvme0n1 sda)
TABLE_INDENT="  "
out="$(table::print_raid_warning)"
t::assert_contains "$out" "RAID member(s)" "warning mentions RAID members"
t::assert_contains "$out" "nvme0n1 (vmd)" "warning names the vmd drive"
t::assert_contains "$out" "sda (md)" "warning names the md drive"

# --- no warning when nothing is a RAID member -------------------------------
raid[nvme0n1]="none"
raid[sda]="none"
out="$(table::print_raid_warning)"
t::assert_eq "" "$out" "no warning when no RAID members"

# --- hba is a host-level hint, never "RAID member(s)" -----------------------
raid[sda]="hba"
devices=(sda)
out="$(table::print_raid_warning)"
t::assert_not_contains "$out" "RAID member(s)" "hba-only warning does not claim membership"
t::assert_contains "$out" "RAID-capable HBA present" "hba-only warning names the host-level hint"

# --- mixed: members line AND the host hint ---------------------------------
raid[nvme0n1]="hba"
raid[sda]="md"
devices=(nvme0n1 sda)
out="$(table::print_raid_warning)"
t::assert_contains "$out" "RAID member(s)" "mixed warning keeps the members line"
t::assert_contains "$out" "sda (md)" "mixed warning names the md drive"
t::assert_contains "$out" "RAID-capable HBA present" "mixed warning keeps the host hint"

t::summary
