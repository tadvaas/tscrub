# =============================================================================
# DEVICE RAID (detection only — never auto-break an array)
# =============================================================================
# Detect whether a discovered drive is a RAID member, so the triage screen and
# report can mark it "dismantle in controller BIOS". tScrub NEVER deletes a
# logical drive or breaks an array — this is detection + flagging only. The
# controller-level physical-drive erase (hpacucli/megacli/storcli) is a separate,
# future feature (ROADMAP §11.1).
#
# Signals (recorded in the per-drive `raid` array):
#   md    — Linux md superblock present (definitive software-RAID member).
#   vmd   — NVMe drive behind Intel VMD (RST "RAID mode").
#   hba   — a RAID-capable HBA is present (warning: drive may be a member).
#   none  — no RAID signal.

# Fill raid[$dev] for one discovered drive.
device::raid_detect() {
    local dev="$1"

    # Software RAID — mdadm reads the on-disk superblock (magic 0xa92b4efc).
    if command -v mdadm >/dev/null 2>&1; then
        if mdadm -E "/dev/$dev" 2>/dev/null | grep -q "Magic : a92b4efc"; then
            raid[$dev]="md"
            return
        fi
    fi

    # Intel VMD — an NVMe drive behind a Volume Management Device (RST RAID mode).
    if [[ "$dev" == nvme* && "${SYS_VMD:-}" == "1" ]]; then
        raid[$dev]="vmd"
        return
    fi

    # RAID HBA present (host-level warning, not a per-drive definitive signal —
    # the HBA may be in IT/passthrough mode).
    if [[ "${SYS_RAID_HBA:-}" == "1" ]]; then
        raid[$dev]="hba"
        return
    fi

    raid[$dev]="none"
}
