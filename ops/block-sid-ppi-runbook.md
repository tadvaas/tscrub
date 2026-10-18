# Block SID: PPI disable runbook (fleet)

A drive that ends `BLOCKED` (NVMe `0x4286` / `0x4015`) is locked by the
platform's TCG Block SID gate — no Linux command can purge it. The fix is a TCG
**Physical Presence Interface (PPI)** request: stage "Disable Block SID", reboot,
accept the firmware prompt at POST, then wipe again. This is Blancco's
"Workaround 3".

Appliance side: `product/src/44_device_ppi.sh`; submit is the ACPI `_DSM` call
exposed as `/sys/class/tpm/tpmN/ppi/request` (operation **97** =
`Disable_BlockSIDFunc`). Report column: `PPIRequested`.

## Automation (no flags)

Block SID handling is fully automatic — no kernel params or CLI flags:

1. A **locked SED** (`Locked = Y`) is PSID-reverted automatically from the
   `psids.conf` serial→PSID map (the boot USB, or `tscrub_psids_url` /
   `tscrub_psids` in `tscrub.conf`). No map entry → physical destruction.
2. A drive that ends `BLOCKED` (NVMe `0x4286` / `0x4015` — the platform Block
   SID gate) is cleared via a TCG **PPI request**: tScrub stages
   "Disable Block SID" (TPM PPI sysfs, op 97) and reboots itself.

The only automatic channel is the spec-conformant TPM PPI sysfs. A vendor
UEFI-variable write is never guessed.

## Operator procedure

1. Boot. The run wipes what it can; the Block-SID drive ends `BLOCKED`.
2. tScrub stages the PPI request and **reboots itself**.
3. At POST the firmware asks **"Disable Block SID authentication?" — accept it**
   (the physical-presence step; it cannot be automated).
4. The machine re-boots (via PXE with `tscrub_autonuke=1`, or a re-run) and
   re-wipes; the now-cleared drive erases and reports `COMPLETED`.
5. If the drive is **still** `BLOCKED` on the next run, tScrub does not loop —
   it reports `BLOCKED` with the clear-Block-SID BIOS hint (Security → Storage
   Device Block SID). On this hardware the firmware answered the PPI request
   with "Success" while leaving the gate up, so the manual BIOS toggle is the
   only remaining fix.

## Verification

- Report CSV: the drive reports `FinalStatus=COMPLETED` after the re-wipe. The
  `PPIRequested` column records that a PPI request was staged.
- `PPICleared` is no longer claimed by the appliance (it has no cross-reboot
  memory); the dashboard can infer "cleared" from a prior `BLOCKED` + now
  `COMPLETED` report for the same serial.
- A drive that ends `STALLED` with "power-cycle the drive" is a **different**
  problem (a wedged NVMe sanitize, e.g. an interrupted earlier run) — disconnect
  drive power 10–30 s and re-run; PPI does not apply.
