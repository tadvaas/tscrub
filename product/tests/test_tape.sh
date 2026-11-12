#!/usr/bin/env bash
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src
export VERIFY_MODE=none

# --- discovery (char-device class, not /sys/block) --------------------------
_tdir="$(mktemp -d)"
mkdir -p "$_tdir/st0/device/generic"
printf 'IBM     '      > "$_tdir/st0/device/vendor"
printf 'ULTRIUM-HH4   ' > "$_tdir/st0/device/model"
printf '97F1'          > "$_tdir/st0/device/rev"
touch "$_tdir/st0/device/generic/sg0"
ln -s st0 "$_tdir/st0a"    # mode alias — must NOT become a second device

export SYS_TAPE_DIR="$_tdir"
export FAKE_USB_DEVICES=""
export FAKE_HDPARM_MODE=sas
export FAKE_SG_VPD_SERIAL="SN-DRIVE-1"

devices=()
device::discover

t::check "tape st0 discovered" '[[ " ${devices[*]} " == *" st0 "* ]]'
t::check "st0a alias is NOT a second device" '[[ " ${devices[*]} " != *" st0a "* ]]'
t::assert_eq "TAPE"  "${bus[st0]:-}"   "tape bus"
t::assert_eq "TAPE"  "${type[st0]:-}"  "tape type placeholder"
t::assert_eq "IBM ULTRIUM-HH4" "${model[st0]:-}" "tape model from sysfs vendor+model"
t::assert_eq "97F1"  "${firmware[st0]:-}" "tape firmware from sysfs rev"
t::assert_eq "N/A"   "${size[st0]:-}"  "tape size N/A"
t::assert_eq "SN-DRIVE-1" "${serial[st0]:-}" "tape drive serial from sg_vpd VPD 0x80"
unset FAKE_SG_VPD_SERIAL

# --- capability + type (MAM density) ----------------------------------------
declare -A capability type
devices=(st0)

unset FAKE_SGREADATTR_OUT
capability=()
device::detect
t::assert_eq "CAP_TAPE_LONG_ERASE" "${capability[st0]:-}" "no MAM -> still erasable (worker re-checks medium)"

export FAKE_SGREADATTR_OUT=$'Format density code: 0x46\nMedium serial number: 3100602003'
capability=(); type=()
device::detect
t::assert_eq "CAP_TAPE_LONG_ERASE" "${capability[st0]:-}" "LTO-4 RW -> long erase"
t::assert_eq "LTO-4" "${type[st0]:-}" "density 0x46 -> LTO-4"

export FAKE_SGREADATTR_OUT=$'Format density code: 0x47\nMedium serial number: 3100602003'
capability=(); type=()
device::detect
t::assert_eq "CAP_TAPE_WORM" "${capability[st0]:-}" "odd density -> WORM"
t::assert_eq "LTO-4 WORM" "${type[st0]:-}" "0x47 -> LTO-4 WORM"

export FAKE_SGREADATTR_OUT=$'Format density code: 0x5A'
capability=(); type=()
device::detect
t::assert_eq "CAP_TAPE_LONG_ERASE" "${capability[st0]:-}" "LTO-6 RW -> long erase"
t::assert_eq "LTO-6" "${type[st0]:-}" "0x5A -> LTO-6"
unset FAKE_SGREADATTR_OUT

# --- classification + label --------------------------------------------------
devrow=()
capability[st0]="CAP_TAPE_LONG_ERASE"
device::classify st0
t::assert_eq "PURGE"        "${devrow[st0.class]:-}"  "tape class PURGE"
t::assert_eq "DESTRUCTION"  "${devrow[st0.cert]:-}"   "tape cert DESTRUCTION"
t::assert_eq "LTO Long Erase" "${devrow[st0.method]:-}" "tape method"
t::assert_eq "Tape Purge (LTO Long Erase)" "$(device::capability_label CAP_TAPE_LONG_ERASE)" "tape capability label"

devrow=()
capability[st0]="CAP_TAPE_WORM"
device::classify st0
t::assert_eq "FAILED"       "${devrow[st0.class]:-}"  "WORM class FAILED"
t::assert_eq "PHYS_DESTR"   "${devrow[st0.cert]:-}"   "WORM cert PHYS_DESTR"
t::assert_eq "WORM media — physical destruction required" "${devrow[st0.method]:-}" "WORM method"

# --- IPC evidence mapping ----------------------------------------------------
devrow=()
ui::apply_outcome st0 TAPESERIAL 3100602003
t::assert_eq "3100602003" "${devrow[st0.serial]:-}" "TAPESERIAL replaces the report serial"
devrow=()
ui::apply_outcome st0 TAPEEVID 0
t::assert_eq "passed"      "${devrow[st0.verify_result]:-}"  "0 bytes -> verify passed"
t::assert_eq "blank-read"  "${devrow[st0.verify_technique]:-}" "tape technique blank-read"
devrow=()
ui::apply_outcome st0 TAPEEVID 1048576
t::assert_eq "failed"      "${devrow[st0.verify_result]:-}"  "data remains -> verify failed"

# --- worker ----------------------------------------------------------------
run_tape() {   # -> captured fd-3 output
    local OUT
    OUT="$(mktemp)"
    exec 3>"$OUT"
    device::exec_tape st0
    exec 3>&-
    cat "$OUT"
}

_ok_mam=$'Format density code: 0x46\nMedium serial number: 3100602003'
declare -A bus devrow
devices=(st0)
bus[st0]="TAPE"
devrow[st0.class]="PURGE"
devrow[st0.capability]="CAP_TAPE_LONG_ERASE"
devrow[st0.selected]=1
export TS_TAPE_READY_POLL_SECS=0
export FAKE_SGREADATTR_OUT="$_ok_mam"
MTLOG="$(mktemp)"
export FAKE_MT_LOG="$MTLOG"
tape::readback() { echo 0; }   # seam: a blank tape reads back 0 bytes

captured="$(run_tape)"
t::assert_contains "$captured" "st0 TAPESERIAL 3100602003" "worker emits MAM medium serial"
t::assert_contains "$captured" "st0 TAPEEVID 0"             "worker emits blank read-back evidence"
t::assert_contains "$captured" "st0 STATUS COMPLETED"       "long erase -> COMPLETED"
mtlog="$(cat "$MTLOG")"
t::assert_contains "$mtlog" "rewind"  "worker rewinds before erase"
t::assert_contains "$mtlog" "erase 1" "worker issues LONG erase (nonzero count)"
t::assert_contains "$mtlog" "offline" "worker ejects the tape at the end"

# erase failure -> honest FAILED
: > "$MTLOG"
export FAKE_MT_ERASE_RC=1
captured="$(run_tape)"
t::assert_contains "$captured" "st0 STATUS FAILED"          "erase rc!=0 -> FAILED"
t::assert_contains "$captured" "st0 METHOD Long erase failed" "erase failure method"
unset FAKE_MT_ERASE_RC

# data survives -> FAILED, never COMPLETED
tape::readback() { echo 1048576; }
captured="$(run_tape)"
t::assert_contains "$captured" "st0 STATUS FAILED"                  "surviving data -> FAILED"
t::assert_contains "$captured" "1048576 bytes of data remain"       "honest surviving-byte method"
t::check "data does NOT emit COMPLETED" '[[ "$captured" != *"st0 STATUS COMPLETED"* ]]'
unset -f tape::readback

# no tape loaded -> honest FAILED
export FAKE_SGREADATTR_OUT=""
captured="$(run_tape)"
t::assert_contains "$captured" "st0 METHOD No tape loaded" "empty MAM -> load-a-tape failure"
unset FAKE_SGREADATTR_OUT

# WORM cartridge -> honest FAILED (belt-and-braces re-check in the worker)
export FAKE_SGREADATTR_OUT=$'Format density code: 0x47\nMedium serial number: 3100602003'
captured="$(run_tape)"
t::assert_contains "$captured" "st0 METHOD WORM media — physical destruction required" "worker re-checks WORM"
unset FAKE_SGREADATTR_OUT

# drive never ready -> honest FAILED
export FAKE_SGREADATTR_OUT="$_ok_mam"
export TS_TAPE_READY_TRIES=2
printf '0\n' > "$_tdir/turseq"
export FAKE_SGRAW_TUR_SEQ="$_tdir/turseq"
captured="$(run_tape)"
t::assert_contains "$captured" "st0 METHOD Tape drive not ready" "not-ready drive -> honest FAILED"
t::assert_contains "$captured" "st0 STATUS FAILED" "not-ready drive -> FAILED"
unset FAKE_SGREADATTR_OUT FAKE_SGRAW_TUR_SEQ TS_TAPE_READY_TRIES

# --- execute dispatch + sentinel-plant skip --------------------------------
export VERIFY_MODE=sampled
unset VERIFY_STATE_DIR
export FAKE_SGREADATTR_OUT="$_ok_mam"
export TS_TAPE_READY_POLL_SECS=0
tape::readback() { echo 0; }
: > "$MTLOG"
OUT="$(mktemp)"
exec 3>"$OUT"
device::execute st0
exec 3>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "st0 STATUS RUNNING"  "tape dispatch emits RUNNING"
t::assert_contains "$captured" "st0 TAPEEVID"        "execute reaches exec_tape"
t::check "tape is never sentinel-planted" '[[ "$captured" != *"VERIFYPLANT"* ]]'
unset FAKE_SGREADATTR_OUT

# --- format guard: a COMPLETED tape must not be partitioned ----------------
devrow=()
devrow[st0.selected]=1
devrow[st0.status]="COMPLETED"
devrow[st0.capability]="CAP_TAPE_LONG_ERASE"
_touched="$_tdir/wipe-called"
format::_wipe_signature() { touch "$_touched"; return 0; }
format::format_drive st0
t::assert_eq "n/a" "${devrow[st0.format_result]:-}" "tape format_result n/a"
t::check "tape is never signature-wiped" '[[ ! -e "$_touched" ]]'
unset -f format::_wipe_signature

rm -rf "$_tdir"
t::summary
