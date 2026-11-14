# =============================================================================
# TAPE (LTO) ERASURE
# =============================================================================
# LTO tapes are erased with the drive's firmware LONG ERASE (SCSI ERASE with
# the LONG bit set), the same mechanism commercial LTO-erasure products run:
# the drive overwrites the entire tape in its own firmware, which destroys the
# data regardless of encryption state. Short erase (ERASE from the current
# position, LONG=0) is NOT a sanitisation and is never issued. Verification is
# a FULL read-back: a long-erased tape is blank, so reading from BOT must yield
# 0 bytes before BLANK CHECK — any byte read means data survived and the row
# must FAIL, never COMPLETED.
#
# The drive is spoken to over two device classes:
#   /dev/sgN  — SCSI generic: START/LOAD, TEST UNIT READY, MAM reads
#               (sg_read_attr). This comes FIRST: after power-on an LTO drive
#               answers 02/04 "logical unit not ready, initializing command
#               required" and the st driver's open() blocks ~2 min waiting for
#               readiness (bench-verified 2026-11-12), so EVERY mt call must be
#               gated on readiness probed through the sg node.
#   /dev/nstN — the st tape device (non-rewind): rewind + long erase
#               (`mt erase 1` — a NONZERO MTERASE count sets the ERASE LONG
#               bit, see drivers/scsi/st.c) + offline (eject).
#
# The worker runs in a subshell, so per-drive evidence travels the fd-3 IPC
# channel like STATUS/RECOVERY: TAPESERIAL (the MAM medium serial — the thing
# being erased — replaces the drive-level serial on the report row) and
# TAPEEVID (the blank read-back byte count).

# The sg node behind a tape device. Real sysfs shape (bench-verified):
#   /sys/class/scsi_tape/st0/device  -> ../../../0:0:0:0
#   /sys/class/scsi_generic/sg0      -> .../0:0:0:0/scsi_generic/sg0
# so the node is the scsi_generic entry whose target path contains the tape's
# SCSI device name (0:0:0:0). The old "device/generic contains sg0" assumption
# was wrong: that dir holds only the standard kobject files (dev/device/power/…)
# — the node NAME lives in the scsi_generic class dir, not under the device.
tape::sg_device() {   # <stdev> -> e.g. st0 -> sg0
    local dev scsi sg
    dev="$1"
    scsi="$(readlink "${SYS_TAPE_DIR:-/sys/class/scsi_tape}/$dev/device" 2>/dev/null)"
    scsi="${scsi##*/}"       # e.g. 0:0:0:0
    [[ -n "$scsi" ]] || return 0
    for sg in "${SYS_SG_DIR:-/sys/class/scsi_generic}"/sg[0-9]*; do
        [[ -e "$sg" ]] || continue
        case "$(readlink "$sg" 2>/dev/null)" in
            */"$scsi"/*) printf '%s' "${sg##*/}"; return 0 ;;
        esac
    done
    return 0
}

# SCSI density code -> human LTO generation. Even code = writable generation,
# +1 = its WORM variant; an unknown code yields "" (never invent a label).
tape::density_label() {   # <hex byte>
    local hex="${1:-}" label=""
    [[ "$hex" =~ ^[0-9A-Fa-f]{2}$ ]] || return 0
    case "${hex^^}" in
        40) label="LTO-1" ;; 41) label="LTO-1 WORM" ;;
        42) label="LTO-2" ;; 43) label="LTO-2 WORM" ;;
        44) label="LTO-3" ;; 45) label="LTO-3 WORM" ;;
        46) label="LTO-4" ;; 47) label="LTO-4 WORM" ;;
        58) label="LTO-5" ;; 59) label="LTO-5 WORM" ;;
        5A) label="LTO-6" ;; 5B) label="LTO-6 WORM" ;;
        5C) label="LTO-7" ;; 5D) label="LTO-7 WORM" ;;
        5E) label="LTO-8" ;; 5F) label="LTO-8 WORM" ;;
        60) label="LTO-9" ;; 61) label="LTO-9 WORM" ;;
    esac
    printf '%s' "$label"
}

# Estimated long-erase duration in minutes. The firmware long erase reports no
# progress (REQUEST SENSE answers Good with no progress descriptor, and MAM's
# remaining-capacity field does not decrement during the erase), so the ETA is
# a static estimate in the same class as ATA word-89: the medium capacity
# divided by an empirical erase rate, rounded up to the minute. The rate is
# ~0.9x the generation's native data rate because a long erase runs slightly
# slower than a data write — LTO-4 is calibrated from six bench erasures
# (800 GB in ~123 min ≈ 109 MB/s). The countdown is approximate: a slow erase
# can outrun it, and the UI then flips to elapsed (see ui::eta_text_for).
# <capacity_mib> <density_hex> -> minutes, or "".
tape::erase_eta_mins() {
    local cap_mib="${1:-}" dens="${2:-}" rate bytes secs
    [[ "$cap_mib" =~ ^[0-9]+$ ]] || return 0
    case "${dens^^}" in
        40|41) rate="${TAPE_ERASE_RATE_LTO1:-14}" ;;
        42|43) rate="${TAPE_ERASE_RATE_LTO2:-36}" ;;
        44|45) rate="${TAPE_ERASE_RATE_LTO3:-72}" ;;
        46|47) rate="${TAPE_ERASE_RATE_LTO4:-109}" ;;
        58|59) rate="${TAPE_ERASE_RATE_LTO5:-126}" ;;
        5A|5B) rate="${TAPE_ERASE_RATE_LTO6:-144}" ;;
        5C|5D) rate="${TAPE_ERASE_RATE_LTO7:-270}" ;;
        5E|5F) rate="${TAPE_ERASE_RATE_LTO8:-324}" ;;
        60|61) rate="${TAPE_ERASE_RATE_LTO9:-360}" ;;
        *) return 0 ;;
    esac
    if [[ ! "$rate" =~ ^[0-9]+$ ]] || (( rate <= 0 )); then
        return 0
    fi
    # MiB -> bytes, then seconds at rate MB/s, rounded UP, then minutes UP.
    bytes=$(( cap_mib * 1048576 ))
    secs=$(( bytes / (rate * 1000000) ))
    (( bytes % (rate * 1000000) )) && secs=$(( secs + 1 ))
    printf '%d' "$(( (secs + 59) / 60 ))"
}

# The raw TapeAlert flags bitmap from the MAM dump (flag N = bit N-1) as a
# decimal integer, or "" when the field is absent or unparseable. Accepts both
# decimal and 0x-prefixed hex, so it is future-proof across LTO generations.
tape::tapealert_value() {   # <mam>
    local mam="${1:-}" raw val
    raw="$(sed -n 's/^[[:space:]]*TapeAlert flags:[[:space:]]*\([0-9A-Fa-f][0-9A-Fa-fx]*\).*/\1/p' <<<"$mam" | head -n1)"
    [[ -n "$raw" ]] || return 0
    raw="${raw,,}"
    if [[ "$raw" == 0x* ]]; then
        raw="${raw#0x}"
        [[ "$raw" =~ ^[0-9a-f]+$ ]] || return 0
        val=$(( 16#$raw ))
    elif [[ "$raw" =~ ^[0-9]+$ ]]; then
        val=$(( 10#$raw ))
    else
        return 0
    fi
    printf '%d' "$val"
}

# TapeAlert temperature state for the TUI TEMP cell. This drive generation has
# NO numeric temperature (the SCSI Temperature log page 0x0D is absent from its
# supported-pages list — bench-verified), so the cell carries the binary
# TapeAlert flag 0x24 "drive temperature above recommended range" instead.
# Returns OVER / NORM, or "" when the flags value is absent or unparseable.
tape::temp_state() {   # <mam>
    local mam="${1:-}" val
    val="$(tape::tapealert_value "$mam")"
    [[ "$val" =~ ^[0-9]+$ ]] || return 0
    if (( val & (1 << 35) )); then
        printf 'OVER'
    else
        printf 'NORM'
    fi
}

# Media-life verdict for the report: TapeAlert flag 0x07 "media life" (the
# cartridge is past its useful life) and flag 0x13 "nearing media life".
# Expired wins when both are set; "" when the flags value is unreadable.
tape::media_life() {   # <mam>
    local mam="${1:-}" val
    val="$(tape::tapealert_value "$mam")"
    [[ "$val" =~ ^[0-9]+$ ]] || return 0
    if (( val & (1 << 6) )); then    # flag 0x07 = bit 6
        printf 'expired'
    elif (( val & (1 << 18) )); then # flag 0x13 = bit 18
        printf 'nearing'
    else
        printf 'ok'
    fi
}

# MAM attribute dump for the loaded medium (overridable seam for tests).
tape::mam() {   # <sgdev>
    sg_read_attr --lvn=0 "/dev/$1" 2>/dev/null
}

# Read the tape back from BOT and report the byte count. A blank (erased)
# tape yields "0 bytes copied" before BLANK CHECK. Overridable as a shell
# function in tests (the real one reads /dev/nstN).
tape::readback() {   # <nstdev> -> bytes read, or "" when unreadable
    local out
    out="$(dd if="/dev/$1" bs=1M of=/dev/null 2>&1 || true)"
    sed -nE 's/^([0-9]+) bytes .*/\1/p' <<<"$out" | head -n1
}

# TEST UNIT READY through the sg node: 0 = ready, 1 = not ready. The st
# driver would block on open() while the drive is not ready, so readiness is
# probed HERE first and every mt call is gated on it.
tape::ready() {   # <sgdev>
    local sg="$1"
    sg_raw -r 6 "/dev/$sg" 00 00 00 00 00 00 2>&1 | grep -q "Good"
}

# Persist a tape failure method the same way device::emit_status persists the
# STATUS: the fd-3 METHOD line can be lost when a SIGCHLD cuts the parent's
# read short, so the file survives the worker subshell and
# device::normalize_outcome folds it back in for an honest report row.
tape::emit_fail() {   # <dev> <method>
    local dev="$1" method="$2"
    echo "$dev METHOD $method" >&3
    mkdir -p /tmp/tscrub-device-status 2>/dev/null
    printf '%s\n' "$method" > "/tmp/tscrub-device-status/$dev.method" 2>/dev/null || true
}

# Background watcher for the TEMP cell: while the long erase runs, poll the
# drive's MAM TapeAlert flags and emit TAPEALERT OVER when flag 0x24 (drive
# temperature) turns on. READ ATTRIBUTE (MAM) stays serviced during the erase
# even though LOG SENSE is not. The loop self-terminates if the worker dies
# (kill -0 on the worker pid) and is otherwise killed by the parent when the
# erase command returns.
tape::temp_poll() {   # <dev> <sgdev> <worker_pid>
    local dev="$1" sg="$2" worker_pid="$3" state
    while kill -0 "$worker_pid" 2>/dev/null; do
        state="$(tape::temp_state "$(tape::mam "$sg" 2>/dev/null || true)" 2>/dev/null || true)"
        if [[ "$state" == "OVER" ]]; then
            echo "$dev TAPEALERT OVER" >&3
            echo "$dev LOG drive temperature exceeded during long erase" >&3
            break
        fi
        sleep "${TS_TAPE_TEMP_POLL_SECS:-30}"
    done
}

device::exec_tape() {
    local dev="$1"
    local sg nst tries mam dens serial cap_mib eta_mins bytes _mam_fail _poll_pid

    sg="$(tape::sg_device "$dev")"
    if [[ -z "$sg" ]]; then
        tape::emit_fail "$dev" "No sg node for tape — check the drive's cabling"
        device::emit_status "$dev" FAILED
        return
    fi
    nst="n$dev"    # st0 -> nst0 (the non-rewind variant)

    # Initialise the drive through the sg node. Before this LOAD the drive
    # answers 02/04 and every mt call would block in the st driver's open().
    echo "$dev LOG initialising tape drive ($dev via $sg)" >&3
    sg_raw "/dev/$sg" 1b 00 00 00 01 00 >/dev/null 2>&5

    tries=0
    while (( tries < ${TS_TAPE_READY_TRIES:-6} )); do
        tape::ready "$sg" && break
        tries=$(( tries + 1 ))
        sleep "${TS_TAPE_READY_POLL_SECS:-10}"
    done
    if (( tries >= ${TS_TAPE_READY_TRIES:-6} )); then
        tape::emit_fail "$dev" "Tape drive not ready — power-cycle and re-run"
        device::emit_status "$dev" FAILED
        return
    fi

    # Evidence: the medium identity comes from MAM, not from the drive. A MAM
    # read that returns nothing means no cartridge is loaded (or the drive
    # cannot read it) — an honest failure, never a blind erase of /dev/st0.
    mam="$(tape::mam "$sg" 2>/dev/null || true)"
    if [[ -z "$mam" ]]; then
        tape::emit_fail "$dev" "No tape loaded — load a tape and re-run"
        device::emit_status "$dev" FAILED
        return
    fi
    dens="$(sed -n 's/^[[:space:]]*Format density code:[[:space:]]*0x\([0-9A-Fa-f][0-9A-Fa-f]\).*/\1/p' <<<"$mam" | head -n1)"
    serial="$(sed -n 's/^[[:space:]]*Medium serial number:[[:space:]]*//p' <<<"$mam" | head -n1 | tr -d ' \000-\037\177')"
    # Odd density code = WORM cartridge. WORM media rejects overwrite/erase by
    # design — report physical destruction, never retry-erase into a fake pass.
    if [[ -n "$dens" ]] && [[ "$dens" =~ ^[0-9A-Fa-f][13579bdfBDF]$ ]]; then
        tape::emit_fail "$dev" "WORM media — physical destruction required"
        device::emit_status "$dev" FAILED
        return
    fi
    [[ -n "$serial" ]] && echo "$dev TAPESERIAL $serial" >&3
    echo "$dev LOG tape loaded: $(tape::density_label "$dens")${serial:+ (medium $serial)}" >&3

    # ETA estimate: capacity / native data rate (see tape::erase_eta_mins).
    # Emitted so the TUI can count it down; the parent stamps wipe_start on it.
    cap_mib="$(sed -n 's/^[[:space:]]*Maximum capacity in partition \[MiB\]:[[:space:]]*\([0-9][0-9]*\).*/\1/p' <<<"$mam" | head -n1)"
    eta_mins="$(tape::erase_eta_mins "$cap_mib" "$dens" 2>/dev/null || true)"
    if [[ "$eta_mins" =~ ^[0-9]+$ ]] && (( eta_mins > 0 )); then
        echo "$dev TAPEETA $eta_mins" >&3
        echo "$dev LOG long erase ETA ~${eta_mins}min (capacity / native data rate estimate)" >&3
    fi

    if ! mt -f "/dev/$nst" rewind >/dev/null 2>&5; then
        tape::emit_fail "$dev" "Long erase failed — tape rewind failed"
        device::emit_status "$dev" FAILED
        return
    fi

    # The long erase runs inside the drive firmware and can take hours; the
    # host sees no I/O in flight meanwhile (same class as ATA security erase).
    # `mt erase 1` = MTERASE with a nonzero count = the ERASE LONG bit, per
    # drivers/scsi/st.c. The st driver applies its long timeout (hours) to it.
    echo "$dev LOG starting LTO long erase — the whole tape is overwritten in the drive (takes hours)" >&3

    # While the erase runs, watch the drive's TapeAlert flags so an
    # over-temperature shows in the TEMP cell the moment it happens (the drive
    # aborts the erase itself with "specified temperature exceeded", and READ
    # ATTRIBUTE stays serviced even though LOG SENSE is not).
    tape::temp_poll "$dev" "$sg" "$$" &
    _poll_pid=$!

    if ! mt -f "/dev/$nst" erase 1 >/dev/null 2>&5; then
        kill "$_poll_pid" 2>/dev/null || true
        wait "$_poll_pid" 2>/dev/null || true
        # The drive aborts on over-temperature; re-read MAM now it is idle and
        # name the real reason rather than the generic "Sanitisation failed".
        _mam_fail="$(tape::mam "$sg" 2>/dev/null || true)"
        if [[ "$(tape::temp_state "$_mam_fail")" == "OVER" ]]; then
            tape::emit_fail "$dev" "Long erase failed — drive temperature exceeded"
            echo "$dev LOG the drive aborted the long erase after overheating — let it cool and retry" >&3
        else
            tape::emit_fail "$dev" "Long erase failed"
        fi
        device::emit_status "$dev" FAILED
        return
    fi
    kill "$_poll_pid" 2>/dev/null || true
    wait "$_poll_pid" 2>/dev/null || true

    # Verification: rewind, then read back. Blank = 0 bytes.
    mt -f "/dev/$nst" rewind >/dev/null 2>&5 || true
    bytes="$(tape::readback "$nst" 2>/dev/null || true)"
    echo "$dev TAPEEVID ${bytes:-unknown}" >&3
    if [[ -z "$bytes" ]] || [[ ! "$bytes" =~ ^[0-9]+$ ]]; then
        tape::emit_fail "$dev" "Long erase verify failed — tape unreadable"
        device::emit_status "$dev" FAILED
        return
    fi
    if (( bytes > 0 )); then
        tape::emit_fail "$dev" "Long erase verify failed — $bytes bytes of data remain"
        device::emit_status "$dev" FAILED
        return
    fi

    # Hand the cartridge back to the operator (rewind + unload; best effort —
    # the erasure is already verified).
    mt -f "/dev/$nst" offline >/dev/null 2>&5 || true
    echo "$dev LOG long erase complete — blank read-back verified (0 bytes)" >&3
    device::emit_status "$dev" COMPLETED
}
