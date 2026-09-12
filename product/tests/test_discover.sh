#!/usr/bin/env bash
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

export FAKE_NVME_MODE=crypto
export FAKE_HDPARM_MODE=enhanced
export FAKE_USB_DEVICES="sdb"

device::discover

t::check "discover found 2 devices (nvme0n1, sda)" '(( ${#devices[@]} == 2 ))'
t::check "nvme0n1 present" '[[ "${devices[*]}" == *"nvme0n1"* ]]'
t::check "sda present" '[[ "${devices[*]}" == *"sda"* ]]'
t::check "sdb (usb) excluded" '[[ "${devices[*]}" != *"sdb"* ]]'
t::assert_eq "NVMe" "${bus[nvme0n1]}" "nvme bus"
t::assert_eq "SSD" "${type[nvme0n1]}" "nvme type"
t::assert_eq "SN-NVME-1" "${serial[nvme0n1]}" "nvme serial"
t::assert_eq "NVMe Fake Model" "${model[nvme0n1]}" "nvme model"
t::assert_eq "SATA" "${bus[sda]}" "sda bus"
t::assert_eq "HDD" "${type[sda]}" "sda type (rotational=1)"
t::assert_eq "NO" "${opal_locked[sda]}" "sda opal not locked"

t::summary
