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
t::assert_eq "" "${devrow[sda.recovery]:-}" "clear_notime: no recovery recorded (drive was never locked)"

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
t::assert_eq "temp-password" "${devrow[sda.recovery]:-}" "locked with our password: recovery recorded as temp-password"

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
t::assert_eq "" "${devrow[sda.recovery]:-}" "unknown password: no recovery recorded"
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
t::assert_eq "blank-master" "${devrow[sda.recovery]:-}" "blank master high: recovery recorded as blank-master"

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
t::assert_eq "blank-master" "${devrow[sda.recovery]:-}" "blank master maximum: recovery recorded as blank-master"

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

# --- Operator-supplied password: correct password, erase runs, no leak -------
export FAKE_HDPARM_MODE=locked
_state="$(mktemp)"
export FAKE_HDPARM_STATE_FILE="$_state"
export FAKE_HDPARM_MREV=65534 FAKE_HDPARM_LEVEL=high
export FAKE_HDPARM_USERPW=Xyz123
TS_ATA_PASSWORD="Xyz123"
_log="$(mktemp)"
export FAKE_HDPARM_LOG="$_log"
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
t::assert_contains "$captured" "sda STATUS COMPLETED" "operator password: unlock + erase -> COMPLETED"
t::assert_contains "$captured" "unlocked via operator password" "operator password: log says how"
t::assert_eq "operator-password" "${devrow[sda.recovery]:-}" "operator password: recovery recorded as operator-password"
t::assert_contains "$captured" "sda RECOVERY operator-password" "operator password: RECOVERY travels the fd-3 outcome channel"
t::assert_not_contains "$captured" "Xyz123" "operator password: the value never reaches the status/log stream"
t::check "operator password: no set-pass (security already enabled with that password)" '[[ "$(<"$_log")" != *"setpass"* ]]'
t::check "operator password: unlock and erase both ran" '[[ "$(<"$_log")" == *"unlock"* && "$(<"$_log")" == *"erase"* ]]'
t::check "operator password: unlock was attempted exactly once" '[[ "$(grep -c unlock "$_log")" == "1" ]]'
unset TS_ATA_PASSWORD FAKE_HDPARM_USERPW FAKE_HDPARM_LOG FAKE_HDPARM_MODE FAKE_HDPARM_STATE_FILE FAKE_HDPARM_MREV FAKE_HDPARM_LEVEL
rm -f "$_state" "$_log"

# --- Operator-supplied password: WRONG password falls through honestly -------
export FAKE_HDPARM_MODE=locked
_state="$(mktemp)"
export FAKE_HDPARM_STATE_FILE="$_state"
export FAKE_HDPARM_MREV=1234 FAKE_HDPARM_LEVEL=high
export FAKE_HDPARM_USERPW=Xyz123
TS_ATA_PASSWORD="WrongGuess"
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
t::assert_contains "$captured" "operator password not accepted" "wrong operator password: logged as not accepted"
t::assert_contains "$captured" "ATA security locked (password unknown)" "wrong operator password: honest physical-destruction outcome"
t::assert_contains "$captured" "sda LOCKKIND ata-password" "wrong operator password: LOCKKIND travels the fd-3 outcome channel"
t::assert_not_contains "$captured" "WrongGuess" "wrong operator password: the value never reaches the status/log stream"
t::assert_eq "ata-password" "${devrow[sda.lock_kind]:-}" "wrong operator password: lock_kind still records the ATA password lock"
t::assert_eq "" "${devrow[sda.recovery]:-}" "wrong operator password: no recovery recorded"
unset TS_ATA_PASSWORD FAKE_HDPARM_USERPW FAKE_HDPARM_MODE FAKE_HDPARM_STATE_FILE FAKE_HDPARM_MREV FAKE_HDPARM_LEVEL
rm -f "$_state"

# --- Security enabled but NOT locked (the soft-reboot state) -----------------
# BENCH-PROVEN Intel semantics: an UNLOCKED drive ACCEPTS a new password via
# SECURITY SET PASSWORD without knowing the old one — the normal set-pass path
# replaces the unknown password and the erase then runs with ours. Two variants
# below: the real-drive case (set-pass succeeds -> recovery recorded as
# set-password) and the fallback (set-pass fails, e.g. a Maximum-level drive
# where the level cannot be lowered -> the operator's password erases directly).
export FAKE_HDPARM_MODE=locked
_state="$(mktemp)"
printf 'unlocked\n' > "$_state"
export FAKE_HDPARM_STATE_FILE="$_state"
export FAKE_HDPARM_USERPW=Xyz123
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
t::assert_contains "$captured" "sda STATUS COMPLETED" "enabled-unlocked: set-pass replaces the password -> COMPLETED"
t::assert_eq "set-password" "${devrow[sda.recovery]:-}" "enabled-unlocked: recovery recorded as set-password"
t::assert_contains "$captured" "sda RECOVERY set-password" "enabled-unlocked: RECOVERY travels the fd-3 outcome channel"

# Fallback: set-pass FAILS on the enabled-unlocked drive (Maximum-level or I/O
# error). With the operator's password it is erased DIRECTLY while unlocked;
# without it, the outcome is honest.
printf 'unlocked\n' > "$_state"
export FAKE_HDPARM_SETPASS_RC=1
TS_ATA_PASSWORD="Xyz123"
_log="$(mktemp)"
export FAKE_HDPARM_LOG="$_log"
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS COMPLETED" "enabled-unlocked + operator password: direct erase -> COMPLETED"
t::assert_eq "operator-password" "${devrow[sda.recovery]:-}" "enabled-unlocked + operator password: recovery recorded as operator-password"
t::assert_not_contains "$captured" "Xyz123" "enabled-unlocked + operator password: the value never reaches the status/log stream"

# Same state WITHOUT the operator password: honest physical-destruction.
printf 'unlocked\n' > "$_state"
unset TS_ATA_PASSWORD
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "ATA security enabled with unknown password" "enabled-unlocked, no operator password: honest outcome logged"
t::assert_eq "ata-enabled" "${devrow[sda.lock_kind]:-}" "enabled-unlocked, no operator password: lock_kind records ata-enabled"
t::assert_eq "" "${devrow[sda.recovery]:-}" "enabled-unlocked, no operator password: no recovery recorded"
devices=(sda)
devrow[sda.status]="FAILED"
device::normalize_outcome
t::assert_eq "ATA security enabled — physical destruction required" "${devrow[sda.method]:-}" "enabled-unlocked, no operator password: honest method line"
unset TS_ATA_PASSWORD FAKE_HDPARM_USERPW FAKE_HDPARM_LOG FAKE_HDPARM_SETPASS_RC FAKE_HDPARM_MODE FAKE_HDPARM_STATE_FILE
rm -f "$_state" "$_log"

# --- The UI applies the channel lines to the PARENT devrow (the bench gap) ---
# Worker devrow writes are lost; ui::apply_outcome is what makes RECOVERY /
# LOCKKIND reach the report. Pin both cases directly.
devrow=()
ui::apply_outcome sda RECOVERY operator-password
ui::apply_outcome sda LOCKKIND ata-password
t::assert_eq "operator-password" "${devrow[sda.recovery]:-}" "apply_outcome: RECOVERY applied to the parent devrow"
t::assert_eq "ata-password" "${devrow[sda.lock_kind]:-}" "apply_outcome: LOCKKIND applied to the parent devrow"

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
