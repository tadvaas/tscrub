# Block SID: PPI disable runbook (fleet)

A drive that ends `BLOCKED` (NVMe `0x4286` / `0x4015`) is locked by the
platform's TCG Block SID gate — no Linux command can purge it. The fix is a TCG
**Physical Presence Interface (PPI)** request: stage "Disable Block SID", reboot,
accept the firmware prompt at POST, then wipe again. This is Blancco's
"Workaround 3".

Appliance side: `product/src/44_device_ppi.sh`; submit is the ACPI `_DSM` call
exposed as `/sys/class/tpm/tpmN/ppi/request` (operation **97** =
`Disable_BlockSIDFunc`, 96 = Enable). Report columns: `PPICleared`,
`PPIResumed`.

## Modes

| Mode | Channel | Never touches |
|---|---|---|
| `--ppi` (or `--ppi=on`) | TPM PPI sysfs first, then `--ppi-efivar` fallback | — |
| `--ppi=auto` | **TPM PPI sysfs only** | the vendor efivar |

`auto` is the safe fleet default: it never guesses or writes a vendor UEFI
variable. Use `on` + `--ppi-efivar=<name>` only for firmware that implements
Block SID as a storage efivar with **no** TPM PPI sysfs.

## Step 0 — pick the channel (once per machine model)

```bash
tscrub --ppi-probe
```

- If it prints `PPI sysfs: /sys/class/tpm/tpm0/ppi/request (version 1.3)` → use
  `--ppi=auto` (`tscrub_ppi=auto`).
- If it prints `PPI sysfs: not available` and lists candidate efivars → you must
  identify the exact variable for this model and use `--ppi` +
  `--ppi-efivar=<name>` (`tscrub_ppi=on tscrub_ppi_efivar=<name>`). **Do not
  guess** — a wrong efivar stages nothing useful.

## Fleet PXE config (`boot.ipxe`)

For machines that expose the TPM PPI sysfs:

```
kernel ${base-url}/tscrub/bzImage console=tty3 loglevel=3 tscrub_ppi=auto
```

Add `tscrub_ppi_resume=1` **only** if the machine has a report USB mounted
(see the marker caveat below):

```
kernel ${base-url}/tscrub/bzImage console=tty3 loglevel=3 tscrub_ppi=auto tscrub_ppi_resume=1
```

For a machine that needs the efivar channel:

```
kernel ${base-url}/tscrub/bzImage console=tty3 loglevel=3 tscrub_ppi=on tscrub_ppi_efivar=<NAME>
```

## Operator procedure (auto mode)

1. Boot. The run wipes what it can; the Block-SID drive ends `BLOCKED`.
2. tScrub stages the PPI request and **reboots itself**.
3. At POST the firmware asks **"Disable Block SID authentication?" — accept it**
   (this is the physical-presence step; it cannot be automated).
4. After the reboot, the Block SID gate is clear. Wipe again:
   - with a marker + `--ppi-resume`, it auto-resumes the marked drives;
   - otherwise select the drive in the triage screen and start it (or re-run
     `--autonuke`).
5. Confirm the report shows the drive `COMPLETED` with `PPICleared=yes`.

## Marker caveat (important for PXE)

`--ppi-resume` persists the Block-SID serial list to a marker file so the next
boot can auto-resume only those drives. The marker's location, in priority
order, is: `TS_PPI_RESUME_MARKER` → the report USB (`$REPORT_USB_MNT`) →
`/tmp`.

`/tmp` is RAM and is **lost on the reboot**, so on a pure-PXE machine with **no
USB stick** the marker does not survive and auto-resume will not trigger — the
operator must select the drive again after the POST prompt. A USB stick (or
`TS_PPI_RESUME_MARKER` pointing at persistent storage) makes resume fully
hands-off. (Improving cross-reboot persistence for USB-less PXE fleets is a
known gap — see `research/block-sid-ppi/plan.md`.)

## Verification

- Report CSV: drive `FinalStatus=COMPLETED`, `PPICleared=yes`,
  `PPIResumed=yes`.
- If the drive still reports `BLOCKED` on the next run, the POST prompt was
  declined (or the firmware does not expose the TPM PPI sysfs and the efivar was
  wrong) — re-check with `--ppi-probe`.
- A drive that ends `STALLED` with "power-cycle the drive" is a **different**
  problem (a wedged NVMe sanitize, e.g. an interrupted earlier run) — disconnect
  drive power 10–30 s and re-run; PPI does not apply.
