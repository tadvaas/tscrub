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

# --- Interrupted-run recovery: locked with OUR temp password ---------------
export FAKE_HDPARM_MODE=locked
_state="$(mktemp)"
export FAKE_HDPARM_STATE_FILE="$_state"
declare -A bus
bus[sda]="SATA"
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS COMPLETED" "locked with our password: unlock + erase -> COMPLETED"

# --- Locked with an UNKNOWN password + CHANGED master revision --------------
# (a non-factory master): no blank attempts, honest physical-destruction.
printf '' > "$_state"
export FAKE_HDPARM_UNLOCK_RC=1
export FAKE_HDPARM_MREV=1234
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "ATA security locked (password unknown)" "unknown password logged honestly"
t::assert_not_contains "$captured" "blank master" "changed revision: no blank-master attempt"
t::assert_eq "ata-password" "${devrow[sda.lock_kind]:-}" "lock_kind records the ATA password lock"
devices=(sda)
devrow[sda.status]="FAILED"
device::normalize_outcome
t::assert_eq "ATA locked — physical destruction required" "${devrow[sda.method]:-}" "honest method line for an ATA password lock"
unset FAKE_HDPARM_UNLOCK_RC FAKE_HDPARM_MODE FAKE_HDPARM_STATE_FILE FAKE_HDPARM_MREV
rm -f "$_state"

# --- Factory-blank master recovery: High level (blank master unlocks) -------
export FAKE_HDPARM_MODE=locked
_state="$(mktemp)"
export FAKE_HDPARM_STATE_FILE="$_state"
export FAKE_HDPARM_UNLOCK_RC=1
export FAKE_HDPARM_MREV=65534 FAKE_HDPARM_LEVEL=high
declare -A bus
bus[sda]="SATA"
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS COMPLETED" "blank master high: unlock + erase -> COMPLETED"
t::assert_contains "$captured" "unlocked via blank master password" "blank master high: log says how"

# --- Factory-blank master recovery: Maximum level (direct master erase) -----
printf '' > "$_state"
export FAKE_HDPARM_LEVEL=maximum
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS COMPLETED" "blank master maximum: direct master erase -> COMPLETED"
t::assert_contains "$captured" "erased via blank master password" "blank master maximum: log says how"

# --- ABSENT revision line (Intel SSDs omit it once security is enabled) -----
# An absent field must still get the blank-master attempt: the failed attempts
# are clean aborts, and the Intel SSDSC2KB48 on the bench proved a drive that
# hides the field is still blank-master erasable.
printf '' > "$_state"
unset FAKE_HDPARM_MREV
export FAKE_HDPARM_LEVEL=high
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS COMPLETED" "absent revision line: blank master still attempted -> COMPLETED"
t::assert_contains "$captured" "unlocked via blank master password" "absent revision line: log says how"
unset FAKE_HDPARM_UNLOCK_RC FAKE_HDPARM_MODE FAKE_HDPARM_STATE_FILE FAKE_HDPARM_MREV FAKE_HDPARM_LEVEL
rm -f "$_state"

# --- Expired security count: honest power-cycle outcome, no attempts ---------
export FAKE_HDPARM_MODE=locked
_state="$(mktemp)"
export FAKE_HDPARM_STATE_FILE="$_state"
export FAKE_HDPARM_EXPIRED=1
declare -A bus
bus[sda]="SATA"
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "security count expired" "expired count: logged as power-cycle, not physical destruction"
t::assert_not_contains "$captured" "unlocked via" "expired count: no unlock attempts burned"
t::assert_eq "ata-expired" "${devrow[sda.lock_kind]:-}" "expired count: lock_kind recorded"
devices=(sda)
devrow[sda.status]="FAILED"
device::normalize_outcome
t::assert_eq "ATA security count expired — power-cycle the drive and retry" "${devrow[sda.method]:-}" "expired count: honest method line"
unset FAKE_HDPARM_EXPIRED FAKE_HDPARM_MODE FAKE_HDPARM_STATE_FILE
rm -f "$_state"

t::summary
