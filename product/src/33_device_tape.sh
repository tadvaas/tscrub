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

# The sg node behind a tape device (sysfs: .../0:0:0:0/generic/ contains sg0).
tape::sg_device() {   # <stdev> -> e.g. st0 -> sg0
    local dev dir
    dev="$1"
    dir="${SYS_TAPE_DIR:-/sys/class/scsi_tape}/$dev/device/generic"
    ls "$dir" 2>/dev/null | grep -m1 -E '^sg[0-9]+$'
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

device::exec_tape() {
    local dev="$1"
    local sg nst tries mam dens serial bytes

    sg="$(tape::sg_device "$dev")"
    if [[ -z "$sg" ]]; then
        echo "$dev METHOD No sg node for tape — check the drive's cabling" >&3
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
        echo "$dev METHOD Tape drive not ready — power-cycle and re-run" >&3
        device::emit_status "$dev" FAILED
        return
    fi

    # Evidence: the medium identity comes from MAM, not from the drive. A MAM
    # read that returns nothing means no cartridge is loaded (or the drive
    # cannot read it) — an honest failure, never a blind erase of /dev/st0.
    mam="$(tape::mam "$sg" 2>/dev/null || true)"
    if [[ -z "$mam" ]]; then
        echo "$dev METHOD No tape loaded — load a tape and re-run" >&3
        device::emit_status "$dev" FAILED
        return
    fi
    dens="$(sed -n 's/^[[:space:]]*Format density code:[[:space:]]*0x\([0-9A-Fa-f][0-9A-Fa-f]\).*/\1/p' <<<"$mam" | head -n1)"
    serial="$(sed -n 's/^[[:space:]]*Medium serial number:[[:space:]]*//p' <<<"$mam" | head -n1 | tr -d ' \000-\037\177')"
    # Odd density code = WORM cartridge. WORM media rejects overwrite/erase by
    # design — report physical destruction, never retry-erase into a fake pass.
    if [[ -n "$dens" ]] && [[ "$dens" =~ ^[0-9A-Fa-f][13579bdfBDF]$ ]]; then
        echo "$dev METHOD WORM media — physical destruction required" >&3
        device::emit_status "$dev" FAILED
        return
    fi
    [[ -n "$serial" ]] && echo "$dev TAPESERIAL $serial" >&3
    echo "$dev LOG tape loaded: $(tape::density_label "$dens")${serial:+ (medium $serial)}" >&3

    if ! mt -f "/dev/$nst" rewind >/dev/null 2>&5; then
        echo "$dev METHOD Long erase failed — tape rewind failed" >&3
        device::emit_status "$dev" FAILED
        return
    fi

    # The long erase runs inside the drive firmware and can take hours; the
    # host sees no I/O in flight meanwhile (same class as ATA security erase).
    # `mt erase 1` = MTERASE with a nonzero count = the ERASE LONG bit, per
    # drivers/scsi/st.c. The st driver applies its long timeout (hours) to it.
    echo "$dev LOG starting LTO long erase — the whole tape is overwritten in the drive (takes hours)" >&3
    if ! mt -f "/dev/$nst" erase 1 >/dev/null 2>&5; then
        echo "$dev METHOD Long erase failed" >&3
        device::emit_status "$dev" FAILED
        return
    fi

    # Verification: rewind, then read back. Blank = 0 bytes.
    mt -f "/dev/$nst" rewind >/dev/null 2>&5 || true
    bytes="$(tape::readback "$nst" 2>/dev/null || true)"
    echo "$dev TAPEEVID ${bytes:-unknown}" >&3
    if [[ -z "$bytes" ]] || [[ ! "$bytes" =~ ^[0-9]+$ ]]; then
        echo "$dev METHOD Long erase verify failed — tape unreadable" >&3
        device::emit_status "$dev" FAILED
        return
    fi
    if (( bytes > 0 )); then
        echo "$dev METHOD Long erase verify failed — $bytes bytes of data remain" >&3
        device::emit_status "$dev" FAILED
        return
    fi

    # Hand the cartridge back to the operator (rewind + unload; best effort —
    # the erasure is already verified).
    mt -f "/dev/$nst" offline >/dev/null 2>&5 || true
    echo "$dev LOG long erase complete — blank read-back verified (0 bytes)" >&3
    device::emit_status "$dev" COMPLETED
}
