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

t::summary
