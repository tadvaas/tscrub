#!/usr/bin/env bash
# Regression: a SATA drive that supports the Security Mode feature set but does
# NOT advertise enhanced erase NOR an "Nmin for SECURITY ERASE UNIT" timing line
# (word 89 = 0, e.g. old Seagate Momentus) must still be classified
# CAP_ATA_CLEAR and erase successfully — not CAP_NONE -> FAILED.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src
export VERIFY_MODE=none   # the plant runs only in the dedicated ordering test
# File-wide SATL-poll fast path: any test that reaches the fallback fails fast
# through the io-probe gate instead of sleeping the production poll interval.
export TS_SATL_ERASE_POLL_SECS=0 TS_SATL_ERASE_GRACE_SECS=0

# Test seam for the SATL "still serving I/O" gate: fails while
# SATL_IO_BUSY_FILE holds a counter >0 (decrementing the file), succeeds
# otherwise — mirrors a busy drive answering reads with a security conflict.
# (The real probe is a dd sector read; smartctl cannot be the signal — its
# exit code encodes SMART health, not I/O responsiveness, which disabled the
# gate on the bench WD/HGST drives.) Defined file-wide so ANY test that
# reaches the SATL fallback fails fast through the gate instead of polling.
device::ata_satl_io_probe() {
    if [[ -n "${SATL_IO_BUSY_FILE:-}" && -f "$SATL_IO_BUSY_FILE" ]]; then
        local n
        n="$(<"$SATL_IO_BUSY_FILE")"
        if [[ "${n:-0}" -gt 0 ]]; then
            printf '%s\n' "$((n-1))" > "$SATL_IO_BUSY_FILE"
            return 1
        fi
    fi
    return 0
}

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

# --- A FAILED erase must NOT clear the password ------------------------------
# The drive has to stay security-enabled so the next power cycle offers a
# retry; clearing after a failure would leave an unerased drive unlocked.
export FAKE_HDPARM_MODE=locked
_state="$(mktemp)"
export FAKE_HDPARM_STATE_FILE="$_state"
export FAKE_HDPARM_USERPW=Xyz123
TS_ATA_PASSWORD="Xyz123"
ATA_CALLS=()
hdparm() {
    if [[ "$1" == "-I" ]]; then
        command hdparm "$@"   # delegate identify to the real fake binary
        return
    fi
    case "$3" in
        --security-unlock)
            if [[ "$2" == "u" && "${4:-}" == "Xyz123" ]]; then
                ATA_CALLS+=("unlock-op")
                printf 'unlocked\n' > "$FAKE_HDPARM_STATE_FILE"
                return 0
            fi
            ATA_CALLS+=("unlock-p")
            return 2
            ;;
        --security-erase|--security-erase-enhanced)
            ATA_CALLS+=("erase")
            return 1
            ;;
        --security-disable)
            ATA_CALLS+=("disable")
            return 0
            ;;
        --security-set-pass)
            ATA_CALLS+=("setpass")
            return 1
            ;;
    esac
    return 0
}
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
t::assert_contains "$captured" "sda STATUS FAILED" "failed erase: drive reported FAILED"
t::check "failed erase: password is NOT cleared" '[[ "${ATA_CALLS[*]}" == "unlock-p unlock-op erase" ]]'
unset -f hdparm
unset TS_ATA_PASSWORD FAKE_HDPARM_USERPW FAKE_HDPARM_MODE FAKE_HDPARM_STATE_FILE
rm -f "$_state"

# --- hdparm unlock returns 0 WITHOUT unlocking (bench-proven lie) ------------
# Bench-proven: hdparm exits 0 on an already-unlocked drive even with a wrong
# password, so the rc cannot be trusted — the lock state is verified via
# identify. A stale rc=0 must NOT be treated as an unlock.
export FAKE_HDPARM_MODE=locked
_state="$(mktemp)"
export FAKE_HDPARM_STATE_FILE="$_state"
export FAKE_HDPARM_MREV=1234 FAKE_HDPARM_LEVEL=high
export FAKE_HDPARM_USERPW=Xyz123
TS_ATA_PASSWORD="Xyz123"
ATA_CALLS=()
hdparm() {
    if [[ "$1" == "-I" ]]; then
        command hdparm "$@"
        return
    fi
    case "$3" in
        --security-unlock)
            if [[ "$2" == "m" ]]; then
                ATA_CALLS+=("m-unlock")
                return 2
            fi
            if [[ "$2" == "u" && "${4:-}" == "Xyz123" ]]; then
                ATA_CALLS+=("unlock-op")
                return 0   # hdparm LIED: rc=0 but the drive stays locked
            fi
            ATA_CALLS+=("unlock-p")
            return 2
            ;;
        --security-erase|--security-erase-enhanced)
            ATA_CALLS+=("erase")
            return 1
            ;;
        --security-set-pass)
            ATA_CALLS+=("setpass")
            return 1
            ;;
    esac
    return 0
}
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
t::assert_contains "$captured" "operator password not accepted" "unlock rc=0 but still locked: treated as not accepted"
t::assert_contains "$captured" "ATA security locked (password unknown)" "unlock rc=0 but still locked: honest physical-destruction outcome"
t::check "unlock rc=0 but still locked: no erase attempt after the lie" '[[ "${ATA_CALLS[*]}" == "unlock-p unlock-op m-unlock erase erase" ]]'
unset -f hdparm
unset TS_ATA_PASSWORD FAKE_HDPARM_USERPW FAKE_HDPARM_MODE FAKE_HDPARM_STATE_FILE FAKE_HDPARM_MREV FAKE_HDPARM_LEVEL
rm -f "$_state"

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

# --- ACS-4 Restricted Sanitize Overrides Security (RSOS) --------------------
# A locked drive with the RSOS capability is sanitized with NO password: a
# sanitize op (FAILURE MODE bit = 0) is accepted while locked, and a success
# invalidates the user password + clears SECURITY ENABLED. Detection is the
# Security log page 06h QWord 48..55 bit 7 (no IDENTIFY copy in ACS-4 r14).
export FAKE_HDPARM_MODE=locked
_state="$(mktemp)"
export FAKE_HDPARM_STATE_FILE="$_state"
export FAKE_HDPARM_UNLOCK_RC=1
export FAKE_HDPARM_MREV=1234 FAKE_HDPARM_LEVEL=high
export FAKE_HDPARM_SANITIZE=crypto
export FAKE_SMARTCTL_RSOS=1
_sglog="$(mktemp)"
export FAKE_SGRAW_LOG="$_sglog"
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
t::assert_contains "$captured" "sda STATUS COMPLETED" "RSOS: locked drive sanitized with no password -> COMPLETED"
t::assert_contains "$captured" "erased via restricted sanitize" "RSOS: log says how"
t::assert_eq "restricted-sanitize" "${devrow[sda.recovery]:-}" "RSOS: recovery recorded as restricted-sanitize"
t::assert_contains "$captured" "sda METHOD Sanitize Crypto Erase" "RSOS: method channel carries the operation name"
t::check "RSOS: crypto sanitize issued via the SATL passthrough" 'grep -q "85 06 20 00 00 11" "$_sglog"'

# --- RSOS unsupported: the honest outcome is unchanged ----------------------
printf '' > "$_state"
export FAKE_SMARTCTL_RSOS=0
printf '' > "$_sglog"
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "ATA security locked (password unknown)" "RSOS unsupported: honest physical-destruction outcome"
t::check "RSOS unsupported: no sanitize command issued" '[[ ! -s "$_sglog" ]]'

# --- RSOS supported but the sanitize is rejected: honest outcome ------------
printf '' > "$_state"
export FAKE_SMARTCTL_RSOS=1
export FAKE_SGRAW_SANITIZE_RC=1
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "restricted sanitize rejected" "RSOS rejected: logged"
t::assert_contains "$captured" "ATA security locked (password unknown)" "RSOS rejected: honest physical-destruction outcome"
t::assert_eq "ata-password" "${devrow[sda.lock_kind]:-}" "RSOS rejected: lock_kind still records the ATA password lock"
unset FAKE_SGRAW_SANITIZE_RC

# --- RSOS busy, then completes: the poll loop works --------------------------
printf '' > "$_state"
_busy="$(mktemp)"
printf '2\n' > "$_busy"
export FAKE_SGRAW_BUSY_FILE="$_busy"
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS COMPLETED" "RSOS busy then complete: poll loop -> COMPLETED"
t::assert_eq "restricted-sanitize" "${devrow[sda.recovery]:-}" "RSOS busy then complete: recovery recorded"
unset FAKE_SGRAW_BUSY_FILE
rm -f "$_busy"

# --- Expired counter + RSOS: the sanitize still works (no counter use) ------
printf '' > "$_state"
export FAKE_HDPARM_EXPIRED=1
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS COMPLETED" "expired + RSOS: sanitized despite the expired counter"
t::assert_eq "restricted-sanitize" "${devrow[sda.recovery]:-}" "expired + RSOS: recovery recorded as restricted-sanitize"
unset FAKE_HDPARM_EXPIRED FAKE_SMARTCTL_RSOS FAKE_HDPARM_UNLOCK_RC FAKE_HDPARM_MODE FAKE_HDPARM_STATE_FILE FAKE_HDPARM_MREV FAKE_HDPARM_LEVEL FAKE_HDPARM_SANITIZE FAKE_SGRAW_LOG
rm -f "$_state" "$_sglog"

# --- SATL security erase: hdparm's erase fails through the HBA transport -----
# (the bench-proven mpt3sas signature), the sg_raw ATA16 path engages, the
# drive stays busy (identify reports enabled, sector reads refused) then
# reports not enabled -> COMPLETED.
export FAKE_HDPARM_MODE=enhanced
export FAKE_HDPARM_ERASE_RC=1
_sglog="$(mktemp)"
export FAKE_SGRAW_LOG="$_sglog"
# 5: exec_ata itself reads identify 3x before the helper polls (expired,
# was_locked, was_enabled) — 5 leaves 2 enabled polls for the helper.
_busy_h="$(mktemp)"; printf '5\n' > "$_busy_h"
export FAKE_HDPARM_SATL_BUSY_FILE="$_busy_h"
_busy_io="$(mktemp)"; printf '2\n' > "$_busy_io"
export SATL_IO_BUSY_FILE="$_busy_io"
export TS_SATL_ERASE_POLL_SECS=0 TS_SATL_ERASE_GRACE_SECS=0 TS_SATL_ERASE_BUDGET_SECS=60
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
t::assert_contains "$captured" "sda STATUS COMPLETED" "SATL erase: engaged and completed"
t::assert_contains "$captured" "SATL security erase completed" "SATL erase: completion logged"
t::check "SATL erase: prepare (f3) and erase (f4) CDBs issued" 'grep -q "f3" "$_sglog" && grep -q "f4" "$_sglog"'

# --- SATL erase: the drive never engages (enabled + still serving I/O) -------
printf '' > "$_sglog"
_busy_h2="$(mktemp)"; printf '999\n' > "$_busy_h2"
export FAKE_HDPARM_SATL_BUSY_FILE="$_busy_h2"
unset SATL_IO_BUSY_FILE
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS FAILED" "SATL erase not engaged: honest FAILED"
t::assert_contains "$captured" "did not engage (drive still serves I/O)" "SATL erase not engaged: reason logged"

# --- SATL erase: already-disabled drive is never reported COMPLETED ----------
printf '' > "$_sglog"
export FAKE_HDPARM_ERASE_RC=1
unset FAKE_HDPARM_SATL_BUSY_FILE
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "sda STATUS FAILED" "SATL erase never-enabled: honest FAILED"
t::assert_contains "$captured" "did not engage (never enabled)" "SATL erase never-enabled: reason logged"

# --- SATL erase: prepare rejected -> the erase command is still attempted ---
printf '' > "$_sglog"
export FAKE_SGRAW_PREPARE_RC=1
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "SATL erase prepare rejected (continuing)" "SATL prepare rejected: logged, continues"
t::assert_contains "$captured" "sda STATUS FAILED" "SATL prepare rejected: honest FAILED"
t::check "SATL prepare rejected: the erase CDB is still attempted" 'grep -q "f4" "$_sglog"'
unset FAKE_SGRAW_PREPARE_RC

# --- SATL erase: busy the whole budget -> timed out --------------------------
printf '' > "$_sglog"
_busy_h3="$(mktemp)"; printf '999\n' > "$_busy_h3"
export FAKE_HDPARM_SATL_BUSY_FILE="$_busy_h3"
_busy_io2="$(mktemp)"; printf '999\n' > "$_busy_io2"
export SATL_IO_BUSY_FILE="$_busy_io2"
export TS_SATL_ERASE_POLL_SECS=1 TS_SATL_ERASE_BUDGET_SECS=2
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "SATL security erase timed out" "SATL timeout: logged"
t::assert_contains "$captured" "sda STATUS FAILED" "SATL timeout: honest FAILED"

# --- SATL erase: default budget still fails fast when nothing engaged --------
# No budget seam: the budget comes from the drive's identify estimate (or the
# 12 h fallback) — the io-probe gate must still fire, not a 12-hour timeout.
printf '' > "$_sglog"
unset TS_SATL_ERASE_POLL_SECS TS_SATL_ERASE_GRACE_SECS TS_SATL_ERASE_BUDGET_SECS SATL_IO_BUSY_FILE
_busy_h4="$(mktemp)"; printf '999\n' > "$_busy_h4"
export FAKE_HDPARM_SATL_BUSY_FILE="$_busy_h4"
export TS_SATL_ERASE_POLL_SECS=0 TS_SATL_ERASE_GRACE_SECS=0
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
t::assert_contains "$captured" "did not engage (drive still serves I/O)" "SATL default budget: io-probe gate fires"
t::assert_not_contains "$captured" "timed out" "SATL default budget: no false timeout"
unset FAKE_HDPARM_ERASE_RC FAKE_HDPARM_SATL_BUSY_FILE FAKE_SGRAW_LOG TS_SATL_ERASE_POLL_SECS TS_SATL_ERASE_GRACE_SECS TS_SATL_ERASE_BUDGET_SECS
rm -f "$_sglog" "$_busy_h" "$_busy_io" "$_busy_h2" "$_busy_h3" "$_busy_io2" "$_busy_h4"

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

# --- worker ordering: STATUS RUNNING precedes the sentinel plant -----------
# The plant moved into the erasure worker (after the RUNNING emit) so a drive
# slow to accept writes can never stall the RUNNING paint. Pin the order and
# the VERIFYPLANT channel count end-to-end through device::execute.
export VERIFY_MODE=sampled
verify::_total_sectors() { echo 1000000; }
verify::_write_sector() { return 0; }
verify::_flush() { return 0; }
verify::plant_init
export FAKE_HDPARM_MODE=enhanced
declare -A bus
bus[sda]="SATA"
devrow=()
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
devrow[sda.class]="PURGE"
devrow[sda.selected]=1
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute sda
exec 3>&-
captured="$(cat "$OUT")"
rm -f "$OUT"
_run="$(printf '%s\n' "$captured" | grep -n "STATUS RUNNING" | head -1 | cut -d: -f1)"
_plant="$(printf '%s\n' "$captured" | grep -n "preparing verification sentinels" | head -1 | cut -d: -f1)"
t::check "RUNNING is emitted before the sentinel plant" "[[ -n \"$_run\" && -n \"$_plant\" && $_run -lt $_plant ]]"
t::assert_contains "$captured" "sda VERIFYPLANT 40" "worker reports the planted sector count"
unset -f verify::_total_sectors verify::_write_sector verify::_flush
unset VERIFY_MODE

t::summary
