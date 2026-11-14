#!/usr/bin/env bash
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src
export VERIFY_MODE=none

# The fake readlink cannot simulate symlink-target resolution, so the sg-node
# mapping is stubbed: st0 -> sg0 (real resolution is bench-verified).
# FAKE_TAPE_NO_SG=1 exercises the "no sg node" cabling failure.
tape::sg_device() { [[ "${FAKE_TAPE_NO_SG:-}" == "1" ]] && return 0; [[ "$1" == "st0" ]] && echo "sg0"; }

# --- discovery (char-device class, not /sys/block) --------------------------
_tdir="$(mktemp -d)"
mkdir -p "$_tdir/st0/device"
printf 'IBM     '      > "$_tdir/st0/device/vendor"
printf 'ULTRIUM-HH4   ' > "$_tdir/st0/device/model"
printf '97F1'          > "$_tdir/st0/device/rev"
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
declare -A capability type serial size
devices=(st0)

unset FAKE_SGREADATTR_OUT
capability=()
device::detect
t::assert_eq "CAP_TAPE_LONG_ERASE" "${capability[st0]:-}" "no MAM -> still erasable (worker re-checks medium)"

export FAKE_SGREADATTR_OUT=$'Format density code: 0x46\nMedium serial number: 3100602003\nMaximum capacity in partition [MiB]: 762939'
capability=(); type=(); serial=(); size=()
device::detect
t::assert_eq "CAP_TAPE_LONG_ERASE" "${capability[st0]:-}" "LTO-4 RW -> long erase"
t::assert_eq "LTO-4" "${type[st0]:-}" "density 0x46 -> LTO-4"
t::assert_eq "800 GB" "${size[st0]:-}" "MAM capacity -> 800 GB"
t::assert_eq "3100602003" "${serial[st0]:-}" "MAM medium serial fills the report serial"

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

# --- TapeAlert temperature state (TEMP cell for tape) ------------------------
t::assert_eq "NORM" "$(tape::temp_state $'TapeAlert flags: 0')" "flags clear -> NORM"
t::assert_eq "OVER" "$(tape::temp_state $'TapeAlert flags: 34359738368')" "flag 0x24 (bit 35) -> OVER"
t::assert_eq "OVER" "$(tape::temp_state $'TapeAlert flags: 0x800000000')" "hex 0x800000000 -> OVER"
t::assert_eq "NORM" "$(tape::temp_state $'TapeAlert flags: 0x10')" "hex 0x10 (flag 5) -> NORM"
t::assert_eq ""     "$(tape::temp_state $'Format density code: 0x46')" "no flags line -> unknown"

devrow=()
devices=(st0)
export FAKE_SGREADATTR_OUT=$'Format density code: 0x46\nTapeAlert flags: 0'
capability=(); type=(); serial=(); size=()
device::detect
t::assert_eq "NORM" "${devrow[st0.tape_temp]:-}" "detect: flags clear -> TEMP NORM"
export FAKE_SGREADATTR_OUT=$'Format density code: 0x46\nTapeAlert flags: 34359738368'
capability=(); type=(); serial=(); size=()
device::detect
t::assert_eq "OVER" "${devrow[st0.tape_temp]:-}" "detect: flag 0x24 -> TEMP OVER"
unset FAKE_SGREADATTR_OUT

# --- tape::media_life + wear counters (the SMART analogue for the report) ---
t::assert_eq "ok"      "$(tape::media_life $'TapeAlert flags: 0')" "media life: clear -> ok"
t::assert_eq "nearing" "$(tape::media_life $'TapeAlert flags: 262144')" "media life: flag 0x13 -> nearing"
t::assert_eq "expired" "$(tape::media_life $'TapeAlert flags: 64')" "media life: flag 0x07 -> expired"
t::assert_eq "expired" "$(tape::media_life $'TapeAlert flags: 262208')" "media life: both flags -> expired wins"

devrow=()
devices=(st0)
export FAKE_SGREADATTR_OUT=$'Format density code: 0x46\nTapeAlert flags: 0\nLoad count: 108\nTotal MiB written in medium life: 45962838\nMedium manufacture date: 20100602'
capability=(); type=(); serial=(); size=()
device::detect
t::assert_eq "ok"         "${devrow[st0.tape_media_life]:-}" "detect: media life ok"
t::assert_eq "108"        "${devrow[st0.tape_loads]:-}"      "detect: load count"
t::assert_eq "45962838"   "${devrow[st0.tape_written_mib]:-}" "detect: total MiB written"
t::assert_eq "2010-06-02" "${devrow[st0.tape_manufactured]:-}" "detect: manufacture date formatted"
unset FAKE_SGREADATTR_OUT

# --- tape::emit_fail + tape::temp_poll --------------------------------------
OUT="$(mktemp)"; exec 3>"$OUT"
tape::emit_fail st0 "Long erase failed — tape rewind failed"
exec 3>&-
t::assert_contains "$(cat "$OUT")" "st0 METHOD Long erase failed — tape rewind failed" "emit_fail writes the fd-3 line"
t::assert_eq "Long erase failed — tape rewind failed" "$(cat /tmp/tscrub-device-status/st0.method 2>/dev/null)" "emit_fail persists the method file"

OUT="$(mktemp)"; exec 3>"$OUT"
export FAKE_SGREADATTR_OUT=$'TapeAlert flags: 34359738368'
tape::temp_poll st0 sg0 $$
exec 3>&-
captured="$(cat "$OUT")"
t::assert_contains "$captured" "st0 TAPEALERT OVER" "watcher emits TAPEALERT when flag 0x24 turns on"
t::assert_contains "$captured" "drive temperature exceeded during long erase" "watcher logs the over-temp"
unset FAKE_SGREADATTR_OUT

# --- normalize_outcome keeps the tape's specific failure method --------------
devices=(st0)
declare -A bus devrow
bus[st0]="TAPE"
devrow[st0.status]="FAILED"
devrow[st0.method]="LTO Long Erase"
printf 'Long erase failed — drive temperature exceeded\n' > /tmp/tscrub-device-status/st0.method
device::normalize_outcome
t::assert_eq "Long erase failed — drive temperature exceeded" "${devrow[st0.method]:-}" "tape method preserved from the persisted file"

rm -f /tmp/tscrub-device-status/st0.method
devrow=()
devrow[st0.status]="FAILED"
devrow[st0.method]="LTO Long Erase"
device::normalize_outcome
t::assert_eq "Sanitisation failed" "${devrow[st0.method]:-}" "tape fallback when no method was persisted"

# --- ETA estimate (tape::erase_eta_mins) ------------------------------------
t::assert_eq "123" "$(tape::erase_eta_mins 762939 46)" "LTO-4 800 GB @109 MB/s -> 123 min"
t::assert_eq "123" "$(tape::erase_eta_mins 762939 47)" "WORM density shares the rate table"
t::assert_eq "56"  "$(TAPE_ERASE_RATE_LTO4=240 tape::erase_eta_mins 762939 46)" "rate override halves the estimate"
t::assert_eq ""    "$(tape::erase_eta_mins 762939 ZZ)" "unknown density -> no estimate"
t::assert_eq ""    "$(tape::erase_eta_mins nope 46)"   "non-numeric capacity -> no estimate"

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

devrow=()
ui::apply_outcome st0 TAPELIFE nearing
t::assert_eq "nearing"    "${devrow[st0.tape_media_life]:-}" "TAPELIFE sets the media-life verdict"
ui::apply_outcome st0 TAPELOADS 108
t::assert_eq "108"        "${devrow[st0.tape_loads]:-}"      "TAPELOADS sets the load count"
ui::apply_outcome st0 TAPEWRITTEN 45962838
t::assert_eq "45962838"   "${devrow[st0.tape_written_mib]:-}" "TAPEWRITTEN sets the written MiB"
ui::apply_outcome st0 TAPEMFG 2010-06-02
t::assert_eq "2010-06-02" "${devrow[st0.tape_manufactured]:-}" "TAPEMFG sets the manufacture date"
ui::apply_outcome st0 TAPEALERT NORM
t::assert_eq "NORM"       "${devrow[st0.tape_temp]:-}"       "TAPEALERT NORM sets the temp cell"
ui::apply_outcome st0 TAPEALERT OVER
t::assert_eq "OVER"       "${devrow[st0.tape_temp]:-}"       "TAPEALERT OVER sets the temp cell"

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
tape::temp_poll() { :; }       # seam: the watcher is unit-tested separately

captured="$(run_tape)"
t::assert_contains "$captured" "st0 TAPESERIAL 3100602003" "worker emits MAM medium serial"
t::assert_contains "$captured" "st0 TAPEEVID 0"             "worker emits blank read-back evidence"
t::assert_contains "$captured" "st0 STATUS COMPLETED"       "long erase -> COMPLETED"
mtlog="$(cat "$MTLOG")"
t::assert_contains "$mtlog" "rewind"  "worker rewinds before erase"
t::assert_contains "$mtlog" "erase 1" "worker issues LONG erase (nonzero count)"
t::assert_contains "$mtlog" "offline" "worker ejects the tape at the end"

# ETA estimate emitted when MAM reports a capacity (static, word-89 class)
export FAKE_SGREADATTR_OUT=$'Format density code: 0x46\nMedium serial number: 3100602003\nMaximum capacity in partition [MiB]: 762939'
: > "$MTLOG"
captured="$(run_tape)"
t::assert_contains "$captured" "st0 TAPEETA 123"              "worker emits long-erase ETA estimate"
t::assert_contains "$captured" "long erase ETA ~123min"        "worker logs the ETA estimate"
export FAKE_SGREADATTR_OUT="$_ok_mam"

# Tape health emitted over IPC, so a tape loaded after boot still reports its
# media-life + wear counters and seeds the TEMP cell.
export FAKE_SGREADATTR_OUT=$'Format density code: 0x46\nMedium serial number: 3100602003\nTapeAlert flags: 0\nLoad count: 108\nTotal MiB written in medium life: 45962838\nMedium manufacture date: 20100602'
: > "$MTLOG"
captured="$(run_tape)"
t::assert_contains "$captured" "st0 TAPELIFE ok"            "worker emits the media-life verdict"
t::assert_contains "$captured" "st0 TAPELOADS 108"          "worker emits the load count"
t::assert_contains "$captured" "st0 TAPEWRITTEN 45962838"   "worker emits the total MiB written"
t::assert_contains "$captured" "st0 TAPEMFG 2010-06-02"     "worker emits the manufacture date"
t::assert_contains "$captured" "st0 TAPEALERT NORM"         "worker seeds the TEMP cell NORM"
export FAKE_SGREADATTR_OUT="$_ok_mam"

# erase failure -> honest FAILED
: > "$MTLOG"
export FAKE_MT_ERASE_RC=1
captured="$(run_tape)"
t::assert_contains "$captured" "st0 STATUS FAILED"          "erase rc!=0 -> FAILED"
t::assert_contains "$captured" "st0 METHOD Long erase failed" "erase failure method"
t::assert_eq "Long erase failed" "$(cat /tmp/tscrub-device-status/st0.method 2>/dev/null)" "failure method persisted beside the status file"
unset FAKE_MT_ERASE_RC

# drive aborted by over-temperature -> the reason is named, not generic
: > "$MTLOG"
export FAKE_MT_ERASE_RC=1
export FAKE_SGREADATTR_OUT=$'Format density code: 0x46\nMedium serial number: 3100602003\nTapeAlert flags: 34359738368'
captured="$(run_tape)"
t::assert_contains "$captured" "st0 METHOD Long erase failed — drive temperature exceeded" "over-temp abort named in the method"
t::assert_contains "$captured" "let it cool and retry" "cool-and-retry advice logged"
t::assert_eq "Long erase failed — drive temperature exceeded" "$(cat /tmp/tscrub-device-status/st0.method 2>/dev/null)" "over-temp method persisted"
export FAKE_SGREADATTR_OUT="$_ok_mam"
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

# no sg node (cabling/driver problem) -> honest FAILED
export FAKE_SGREADATTR_OUT="$_ok_mam"
export FAKE_TAPE_NO_SG=1
captured="$(run_tape)"
t::assert_contains "$captured" "st0 METHOD No sg node for tape" "no sg node -> honest FAILED"
t::assert_contains "$captured" "st0 STATUS FAILED" "no sg node -> FAILED"
unset FAKE_SGREADATTR_OUT FAKE_TAPE_NO_SG

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
