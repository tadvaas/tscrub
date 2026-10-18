# =============================================================================
# TCG STORAGE PHYSICAL PRESENCE INTERFACE (PPI) — disable Block SID auth
# =============================================================================
# A Block-SID-locked drive refuses the firmware Purge commands (NVMe 0x4286 /
# 0x4015). The only Linux-safe fix for the *platform* (BIOS) variant of the
# lock is a TCG Physical Presence Interface request (Blancco's "Workaround 3"):
# stage the "Disable Block SID" operation, reboot, a physically-present operator
# accepts a POST prompt, the firmware clears the gate, and the drive can then be
# Purged.
#
# Spec: TCG PC Client Physical Presence Interface v1.30. Submission is an ACPI
# _DSM call — UUID 3DDDFAA6-361B-4EB4-A424-8D10089D1653, Function 2 ("Submit
# TPM Operation Request to Pre-OS Environment"), Arg3 = Package{Integer op}.
# The kernel exposes this as /sys/class/tpm/tpmN/ppi/request (echo the op value).
# Storage Management operations (spec Table 4):
#     96 = Enable_BlockSIDFunc
#     97 = Disable_BlockSIDFunc
#
# A vendor UEFI-variable path (--ppi-efivar) is kept as a fallback for firmware
# that implements the request as a storage variable rather than the TPM PPI
# sysfs; it is vendor-specific and must only ever be used with a variable the
# operator has POSITIVELY identified (never guessed).

PPI_TPM_SYSFS="${PPI_TPM_SYSFS:-/sys/class/tpm}"
PPI_EFIVARS_DIR="${PPI_EFIVARS_DIR:-/sys/firmware/efi/efivars}"
PPI_RESUME_FILE="${PPI_RESUME_FILE:-tscrub-ppi-resume}"
# Overridable in tests: an explicit marker path beats every auto-discovery.
TS_PPI_RESUME_MARKER="${TS_PPI_RESUME_MARKER:-}"

# Operation name -> PPI operation value (spec Table 4). Unknown name -> rc 1.
device::ppi_op_value() {
    case "${1:-disable_block_sid}" in
        disable_block_sid|block_sid) printf '97' ;;
        enable_block_sid)             printf '96' ;;
        *)                            return 1 ;;
    esac
}

# First writable TPM PPI request file, if the kernel exposes the ACPI _DSM
# interface (spec-conformant). Empty + rc 1 when absent.
device::ppi_sysfs_request() {
    local f
    for f in "$PPI_TPM_SYSFS"/tpm*/ppi/request; do
        [[ -e "$f" ]] || continue
        [[ -w "$f" ]] || continue
        printf '%s' "$f"
        return 0
    done
    return 1
}

# TPM PPI interface version, or empty when the interface is absent.
device::ppi_version() {
    local f
    for f in "$PPI_TPM_SYSFS"/tpm*/ppi/version; do
        [[ -e "$f" ]] || continue
        cat "$f" 2>/dev/null
        return 0
    done
    return 1
}

# Read-only candidate scan (--ppi-probe). Reports the two submission channels:
#   (1) the TPM PPI sysfs interface (spec-conformant ACPI _DSM), if present,
#   (2) efivars whose name hints at a storage/PPI/SID variable (vendor fallback).
# Never writes.
device::ppi_probe() {
    local req ver
    if req="$(device::ppi_sysfs_request)"; then
        ver="$(device::ppi_version 2>/dev/null || true)"
        printf 'PPI sysfs: %s (version %s)\n' "$req" "${ver:-?}"
    else
        printf 'PPI sysfs: not available\n'
    fi
    device::ppi_probe_list
    return 0
}

# efivar candidate scan (the vendor UEFI-variable fallback). Read-only.
device::ppi_probe_list() {
    if [[ ! -d "${PPI_EFIVARS_DIR}" ]]; then
        echo "efivarfs not mounted at ${PPI_EFIVARS_DIR}"
        return 0
    fi
    ls "${PPI_EFIVARS_DIR}" 2>/dev/null | grep -iE 'ppi|sid|opal|storage' || true
}

# Effective PPI mode: off (default) | on | auto. Numeric 1/0 are accepted as
# on/off aliases for backwards compatibility (tests, older cmdline values).
device::ppi_mode() {
    case "${TS_PPI:-off}" in
        auto)          printf 'auto' ;;
        1|on|yes|true) printf 'on' ;;
        *)             printf 'off' ;;
    esac
}

# Stage the PPI request. Returns 0 when a request was written (a reboot is
# expected); non-zero falls back to BIOS guidance (device::lock_hint).
# Primary = TPM PPI sysfs (ACPI _DSM); fallback = vendor UEFI variable.
# on   = sysfs first, vendor efivar (--ppi-efivar) as fallback.
# auto = sysfs only — never guesses a vendor efivar.
device::ppi_request() {
    local kind="${1:-disable_block_sid}" op path mode
    mode="$(device::ppi_mode)"
    [[ "$mode" != "off" ]] || return 1
    op="$(device::ppi_op_value "$kind")" || {
        echo "PPI unavailable: unknown operation '$kind'" >&5
        return 1
    }

    # 1) Spec-conformant ACPI _DSM via the TPM PPI sysfs.
    if path="$(device::ppi_sysfs_request)"; then
        if printf '%s\n' "$op" > "$path" 2>/dev/null; then
            echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] ACTION: PPI_REQUEST $kind (op $op via $path)" >> "$LOG_FILE"
            return 0
        fi
        echo "PPI request failed: cannot write $path" >&5
        return 1
    fi

    # 2) Vendor UEFI-variable fallback (--ppi-efivar, positively identified).
    #    auto mode is sysfs-only: it must never guess a vendor efivar.
    if [[ "$mode" == "auto" ]]; then
        echo "PPI unavailable: no TPM PPI sysfs and --ppi=auto never writes a vendor efivar — $kind falls back to BIOS guidance" >&5
        return 1
    fi
    path="${PPI_EFIVARS_DIR}/${TS_PPI_EFIVAR:-}"
    if [[ -z "${TS_PPI_EFIVAR:-}" || ! -e "$path" ]]; then
        echo "PPI unavailable: no TPM PPI sysfs and no PPI variable identified (set --ppi-efivar) — $kind falls back to BIOS guidance" >&5
        return 1
    fi
    # Scoped NVRAM write: remount efivarfs rw for this one variable, write the
    # operation value byte, remount ro. Never leave efivarfs mounted rw.
    mount -o remount,rw "${PPI_EFIVARS_DIR}" 2>/dev/null \
        || { echo "PPI request failed: cannot remount efivarfs rw" >&5; return 1; }
    if ! printf '%s' "$op" > "$path" 2>/dev/null; then
        mount -o remount,ro "${PPI_EFIVARS_DIR}" 2>/dev/null
        echo "PPI request failed: cannot write ${TS_PPI_EFIVAR}" >&5
        return 1
    fi
    mount -o remount,ro "${PPI_EFIVARS_DIR}" 2>/dev/null
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] ACTION: PPI_REQUEST $kind (op $op via efivar ${TS_PPI_EFIVAR})" >> "$LOG_FILE"
    return 0
}

# Post-reboot check: has the Block SID gate been cleared? There is no reliable
# read-only probe in nvme-cli 2.15 / sedutil 1.20 (sedutil's FC_BLOCKSIDAUTH
# feature read is newer), so the ONLY trustworthy signal is the re-erase itself
# succeeding. This function exists so callers don't invent one: it returns 2
# (unknown) and defers to the resumed erase's outcome.
device::block_sid_cleared() {
    return 2
}

# -----------------------------------------------------------------------------
# Resume marker — persist the target serial list across the PPI reboot so the
# next boot can auto-resume the wipe of exactly the previously-BLOCKED drives.
# -----------------------------------------------------------------------------

# Where the marker lives. Persistent = the report USB (only survives a reboot);
# /tmp is RAM and is a same-session convenience. Tests override the path.
device::ppi_resume_marker_path() {
    if [[ -n "${TS_PPI_RESUME_MARKER:-}" ]]; then
        printf '%s' "$TS_PPI_RESUME_MARKER"
        return 0
    fi
    if [[ -n "${REPORT_USB_MNT:-}" && -d "$REPORT_USB_MNT" ]]; then
        printf '%s/%s' "$REPORT_USB_MNT" "$PPI_RESUME_FILE"
        return 0
    fi
    printf '%s/%s' "/tmp" "$PPI_RESUME_FILE"
}

# Write the marker (OP + COCID + the serials that were BLOCKED this cycle).
# Returns 0 when written; 1 when no marker could be persisted (the PPI request
# itself is still staged — only auto-resume is lost).
device::ppi_resume_marker_write() {
    local f written=0
    f="$(device::ppi_resume_marker_path)" || return 1
    {
        printf 'OP=%s\n' "${TS_PPI_OP:-disable_block_sid}"
        printf 'COCID=%s\n' "${COCID:-}"
        printf 'STAMP=%s\n' "$(date -u +%Y%m%dT%H%M%SZ)"
        for dev in "${devices[@]}"; do
            [[ "${devrow[$dev.block_sid]:-no}" == "yes" ]] || continue
            printf 'SERIAL=%s\n' "${serial[$dev]:-}"
            written=1
        done
    } > "$f" 2>/dev/null || return 1
    [[ "$written" -eq 1 ]] || { rm -f "$f" 2>/dev/null; return 1; }
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] ACTION: PPI_RESUME_MARKER ($f)" >> "$LOG_FILE"
    return 0
}

device::ppi_resume_marker_consume() {
    local f
    f="$(device::ppi_resume_marker_path)" || return 1
    rm -f "$f" 2>/dev/null
    return 0
}

# Read the marker's SERIAL= lines (a NEWLINE-free list). Empty on absence.
device::ppi_resume_marker_read() {
    local f
    f="$(device::ppi_resume_marker_path)" || return 1
    [[ -f "$f" ]] || return 1
    sed -nE 's/^SERIAL=//p' "$f" 2>/dev/null
    return 0
}

# Apply a resume marker at boot (opt-in: --ppi-resume). Filters devices[] down
# to the marked serials so the wipe only touches the previously-BLOCKED drives,
# marks them ppi_resumed=yes, and consumes the marker. Returns 0 when at least
# one marked serial matched (caller then treats this as an autonuke resume).
device::ppi_resume_apply() {
    local want dev keep=() s matched
    want="$(device::ppi_resume_marker_read)" || return 1
    [[ -n "$want" ]] || return 1

    for dev in "${devices[@]}"; do
        while IFS= read -r s; do
            [[ -z "$s" ]] && continue
            if [[ "${serial[$dev]:-}" == "$s" ]]; then
                keep+=("$dev")
                devrow["$dev.ppi_resumed"]="yes"
                matched=1
                break
            fi
        done <<<"$want"
    done

    [[ "${matched:-0}" -eq 1 ]] || { device::ppi_resume_marker_consume; return 1; }
    devices=("${keep[@]}")
    device::ppi_resume_marker_consume
    printf "%sPPI resume: wiping %d drive(s) whose Block SID was disabled at POST.\n" "$TABLE_INDENT" "${#keep[@]}"
    return 0
}
