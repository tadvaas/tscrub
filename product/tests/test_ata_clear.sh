#!/usr/bin/env bash
# Regression: a SATA drive that supports the Security Mode feature set but does
# NOT advertise enhanced erase NOR an "Nmin for SECURITY ERASE UNIT" timing line
# (word 89 = 0, e.g. old Seagate Momentus) must still be classified
# CAP_ATA_CLEAR and erase successfully — not CAP_NONE -> FAILED.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

export FAKE_NVME_MODE=crypto
export FAKE_HDPARM_MODE=clear_notime
export FAKE_USB_DEVICES="sdb"

device::install_sedutil >/dev/null 2>&1
device::discover
device::handle_locks >/dev/null 2>&1
device::detect

t::assert_eq "CAP_ATA_CLEAR" "${capability[sda]}" "clear_notime: capability is CAP_ATA_CLEAR"

device::classify sda
t::assert_eq "CLEAR" "${devrow[sda.class]}" "clear_notime: class CLEAR"
t::assert_eq "ATA Secure Erase" "${devrow[sda.method]}" "clear_notime: method ATA Secure Erase"
t::assert_eq "SANITISATION" "${devrow[sda.cert]}" "clear_notime: cert SANITISATION"

# Full execute path must run the (fake) ATA security erase, not short-circuit.
declare -A bus
bus[sda]="SATA"
devrow[sda.capability]="CAP_ATA_CLEAR"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS COMPLETED" "clear_notime: erase -> COMPLETED"
t::check "clear_notime: erase does NOT emit FAILED" '[[ "$captured" != *"sda STATUS FAILED"* ]]'

# Password cleanup: a plain --security-disable is tried first; when it fails
# (drive ended the erase locked), an unlock is issued and the disable retried.
ATA_CLEAR_CALLS=()
FAKE_DISABLE_FAIL_ONCE=1
hdparm() {
    case "$3" in
        --security-disable)
            ATA_CLEAR_CALLS+=("disable")
            if [[ "${FAKE_DISABLE_FAIL_ONCE:-0}" -eq 1 ]]; then
                FAKE_DISABLE_FAIL_ONCE=0
                return 1
            fi
            return 0
            ;;
        --security-unlock)
            ATA_CLEAR_CALLS+=("unlock")
            return 0
            ;;
    esac
    return 0
}
device::ata_clear_password sda
t::check "ata_clear: unlock retried after failed disable" '[[ "${ATA_CLEAR_CALLS[*]}" == "disable unlock disable" ]]'
unset -f hdparm

t::summary
