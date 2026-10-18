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
# A vendor UEFI-variable path used to exist as an opt-in fallback for firmware
# with no TPM PPI sysfs; it was removed — a vendor efivar write must be
# positively identified and is never guessed automatically. The read-only
# device::ppi_probe(_list) scan remains as a diagnostic.

PPI_TPM_SYSFS="${PPI_TPM_SYSFS:-/sys/class/tpm}"
PPI_EFIVARS_DIR="${PPI_EFIVARS_DIR:-/sys/firmware/efi/efivars}"

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

# First readable TPM PPI response file (the last pre-OS operation's answer), or
# empty + rc 1 when absent. Per the kernel ABI (sysfs-class-tpm) the content is
# "<op> <status>: <text>", e.g. "97 0: Success". This is the loop-guard signal:
# a firmware answer here does NOT mean the Block SID gate was actually cleared
# (Lenovo firmware answers "Success" while the drive stays blocked), so it must
# only ever be used to suppress a redundant re-request, never to claim success.
device::ppi_response_file() {
    local f
    for f in "$PPI_TPM_SYSFS"/tpm*/ppi/response; do
        [[ -r "$f" ]] || continue
        printf '%s' "$f"
        return 0
    done
    return 1
}

# Last pre-OS response, trimmed. Empty when the interface is absent.
device::ppi_response() {
    local f
    f="$(device::ppi_response_file)" || return 1
    tr -d '\r\n' < "$f" 2>/dev/null
}

# Pending PPI request value ("0"/empty = none), or empty + rc 1 when absent.
device::ppi_pending() {
    local f v
    for f in "$PPI_TPM_SYSFS"/tpm*/ppi/request; do
        [[ -r "$f" ]] || continue
        v="$(tr -d '\r\n' < "$f" 2>/dev/null)"
        printf '%s' "${v:-0}"
        return 0
    done
    return 1
}

# Has this PPI operation already been handed to the firmware? True when the
# pending request is still this op (staged, not yet answered) OR the last
# response already carries this op (the pre-OS environment answered it). This
# is the one-shot loop guard: a confirmed-but-still-blocked round-trip must NOT
# stage the request again — the drive stays BLOCKED with the BIOS-toggle hint.
device::ppi_already_requested() {
    local kind="${1:-disable_block_sid}" op pending resp
    op="$(device::ppi_op_value "$kind")" || return 1
    pending="$(device::ppi_pending 2>/dev/null || true)"
    if [[ -n "$pending" && "$pending" != "0" ]]; then
        [[ "$pending" == "$op" ]] && return 0
    fi
    resp="$(device::ppi_response 2>/dev/null || true)"
    [[ "$resp" =~ ^[[:space:]]*"$op"([[:space:]]|$) ]] && return 0
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

# Stage the PPI request. Returns 0 when a request was written (a reboot is
# expected); non-zero falls back to BIOS guidance (device::lock_hint). The only
# automatic channel is the spec-conformant TPM PPI sysfs (ACPI _DSM); a vendor
# efivar write is never guessed.
device::ppi_request() {
    local kind="${1:-disable_block_sid}" op path
    op="$(device::ppi_op_value "$kind")" || {
        echo "PPI unavailable: unknown operation '$kind'" >&5
        return 1
    }

    # Spec-conformant ACPI _DSM via the TPM PPI sysfs.
    if path="$(device::ppi_sysfs_request)"; then
        # Idempotent: a request already carrying this op is left as-is (the
        # loop guard reads the pending value; a rewrite is harmless but noisy).
        if [[ "$(tr -d '\r\n' < "$path" 2>/dev/null)" == "$op" ]]; then
            echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] ACTION: PPI_REQUEST $kind already staged (op $op via $path)" >> "$LOG_FILE"
            return 0
        fi
        if printf '%s\n' "$op" > "$path" 2>/dev/null; then
            echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] ACTION: PPI_REQUEST $kind (op $op via $path)" >> "$LOG_FILE"
            return 0
        fi
        echo "PPI request failed: cannot write $path" >&5
        return 1
    fi

    echo "PPI unavailable: no TPM PPI sysfs interface — $kind falls back to BIOS guidance" >&5
    return 1
}

# Post-reboot check: has the Block SID gate been cleared? There is no reliable
# read-only probe in nvme-cli 2.15 / sedutil 1.20 (sedutil's FC_BLOCKSIDAUTH
# feature read is newer), so the ONLY trustworthy signal is the re-erase itself
# succeeding. This function exists so callers don't invent one: it returns 2
# (unknown) and defers to the re-erase succeeding.
device::block_sid_cleared() {
    return 2
}
