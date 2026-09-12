#!/usr/bin/env bash
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

declare -A capability ata_erase_time ata_enhanced_time type
devices=(d1)

capability[d1]="CAP_NVME_PURGE_CRYPTO"
device::classify d1
t::check "NVMe crypto -> PURGE" '[[ ${devrow[d1.class]} == "PURGE" ]]'
t::check "NVMe crypto -> DESTRUCTION" '[[ ${devrow[d1.cert]} == "DESTRUCTION" ]]'
t::check "NVMe crypto -> Secure Erase" '[[ ${devrow[d1.method]} == "Secure Erase" ]]'

capability[d1]="CAP_ATA_PURGE_ENHANCED"
ata_enhanced_time[d1]="90"
device::classify d1
t::check "ATA enhanced -> PURGE" '[[ ${devrow[d1.class]} == "PURGE" ]]'
t::check "ATA enhanced eta 90" '[[ ${devrow[d1.eta_mins]} == "90" ]]'

capability[d1]="CAP_ATA_CLEAR"
type[d1]="SSD"
ata_erase_time[d1]="120"
device::classify d1
t::check "ATA clear SSD -> CLEAR" '[[ ${devrow[d1.class]} == "CLEAR" ]]'
t::check "ATA clear SSD -> SANITISATION" '[[ ${devrow[d1.cert]} == "SANITISATION" ]]'
t::check "ATA clear SSD eta 120" '[[ ${devrow[d1.eta_mins]} == "120" ]]'

type[d1]="HDD"
device::classify d1
t::check "ATA clear HDD -> PURGE (overwrite)" '[[ ${devrow[d1.class]} == "PURGE" ]]'
t::check "ATA clear HDD -> HDD Overwrite" '[[ ${devrow[d1.method]} == "HDD Overwrite" ]]'

capability[d1]="CAP_ATA_FROZEN"
device::classify d1
t::check "ATA frozen -> class FROZEN (not FAILED)" '[[ ${devrow[d1.class]} == "FROZEN" ]]'
t::check "ATA frozen -> PHYS_DESTR" '[[ ${devrow[d1.cert]} == "PHYS_DESTR" ]]'

capability[d1]="CAP_NONE"
device::classify d1
t::check "CAP_NONE -> FAILED" '[[ ${devrow[d1.class]} == "FAILED" ]]'
t::check "CAP_NONE -> PHYS_DESTR" '[[ ${devrow[d1.cert]} == "PHYS_DESTR" ]]'

t::summary
