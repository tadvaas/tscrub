# T140: PERC H330 → HBA330 crossflash (IT mode)

Converts the Dell PERC H330 (LSI SAS3008 "Fury", MegaRAID-only, PCI ID
`1000:005f`) into an HBA330 IT-mode HBA, so every drive passes through and
tScrub gets native ATA SECURITY ERASE / SAS Sanitize instead of Clear-only
overwrites. One-way in practice; the card then runs `mpt3sas` (already built
into the tScrub kernel).

**Why this machine needed it.** The T140's H330 firmware stopped answering
driver commands (megaraid_sas DCMD timeouts → `Failed from megasas_init_fw
6553`, no scsi_host, no disks), and even healthy it is RAID-only
(`Secure JBOD support: No`), so unconfigured drives stay hidden.

## Kit

Ready-to-write FreeDOS image with all tools + the official Dell firmware:

- LAN: `http://192.168.0.6:8080/bench/h330-flash/FD14LITE.img` (33,554,432 B)
- Contents: `megacli.exe`, `megarec3.exe`, `sas3flsh.exe`, `dos4gw.exe`,
  `DOS32A.EXE`, `smc3108.rom` (temp ROM), `sbrempty.bin`, **`HBA330.fw`** =
  Dell DUP `SAS_HBA330_ADP_SF.fw` v16.17.01.00 A08 (SHA-256
  `2be9c8b2542c84de08a307898ed71d2342f28e201ba0bb3ccc4fe269e8073a58`, verified
  against Dell's published value), `mptx64.rom` = Dell `mptx64-sas3-sb.rom`,
  plus step scripts `0_INFO.BAT` … `4_SASADD.BAT` and `README.TXT`.
- Flash tools are the community-standard LSI DOS tools (recorded sizes in the
  build log); the FIRMWARE is the verified official Dell image — never use a
  random `.fw` from a zip when Dell publishes the real one.

## Write the USB

On a Mac: `diskutil list` to find the stick, then
`sudo dd if=FD14LITE.img of=/dev/rdiskX bs=1m`. On Linux: `dd ... of=/dev/sdX`.

## Before flashing (on the T140)

1. **Flea-power drain**: power off, unplug AC, hold power 20 s, replug.
2. F2 Setup: **Boot Mode = BIOS/Legacy** (the DOS tools will not run under
   UEFI); disable Virtualization Technology (iDRAC quirk during flash).
3. **Pull the SAS drive caddies** from the bays.
4. F11 one-time boot → the USB.

## Flash sequence (FreeDOS prompt; if `setup.bat` starts, exit to DOS)

Run in order, **rebooting where noted**:

| Step | Command | Then |
|---|---|---|
| 0 | `0_INFO.BAT` | saves `H330INFO.TXT`, then greps Product Name / FW / **SAS address** onto the screen |
| 1 | `1_TEMPROM.BAT` | **reboot**; card BIOS stalls 3–10 min, then "Baseport not responding. No adapter available" |
| 2 | `2_ERASE.BAT` | **reboot** |
| 3 | `3_FLASH.BAT` | flashes `HBA330.fw` + BIOS ROM; no reboot needed after |
| 4 | `4_SASADD.BAT` | prints the `sasadd` command — type it with YOUR address, then `sas3flsh -list` to verify `Firmware: IT` |

No pipes or editing needed anywhere. The live image has `grep`/`more`/`edit`
on PATH (`find` is not installed — that is the one FreeDOS quirk to avoid).
To read the full dump: `more < H330INFO.TXT` or `edit H330INFO.TXT`.

## Verify (appliance side)

```sh
lspci -nn | grep 1000            # 1000:0097 (SAS3008, IT)
dmesg | grep -i mpt3sas          # controller ready, phy link up
cat /proc/partitions             # every drive present
```

Then tScrub classifies the disks on the SAS bus with full erase passthrough.

**Verified on the bench T140 (2026-11-09):** `lspci` → `1000:0097`,
`mpt3sas` up with `sas_addr(0x5f402700e584a300)`, phys(8), port enable
SUCCESS. Both bay drives on `host0` in `/sys/block` (Toshiba AL13SXL300N +
AL14SXL30EN, 300 GB SAS) — the flash sequence in this runbook is
end-to-end confirmed, including the 3–10 min POST stall after the temp ROM
and the empty-flash reboot with no RAID BIOS at all.

**Caveat (bench-proven 2026-11-11): SATA drives in the bays get Clear-only,
not native ATA security erase.** The HBA exposes bay drives as SCSI devices
(host0 = mpt3sas), and `hdparm`'s SECURITY ERASE through the mpt3sas SATL
fails at the transport level: the F4 reaches the drive (dmesg shows the
drive enter the erase state — reads answer "Security conflict in translated
device") but the passthrough completion comes back broken, hdparm falls back
to the legacy `HDIO_DRIVE_TASKFILE` ioctl (EINVAL on any SCSI device) and
reports "The running kernel lacks CONFIG_IDE_TASK_IOCTL support" — a
misleading message. Unlock/set-pass (same SG_IO ATA16 path) work fine; only
the long-running ERASE is broken. Two bench runs failed this way (WD10JPVX +
HGST HTS541010 in the bays, ~17–20 s stall per erase attempt, then FAILED;
drives left enabled/not-locked with the temp password). Erases that DID work
were drives on the onboard SATA ports (host1+ = ahci), where hdparm's
taskfile path is native. SAS bay drives are unaffected (SCSI sanitize path).
Fix: a SATL security-erase path in tScrub (ATA16 F4 via sg_raw + busy-poll
with an ETA budget), tracked in `roadmap/product.md`.

**UPDATE 2026-11-11 16:13 (v1.12.6 bench): after a clean power-cycle the
normal hdparm erase ENGAGED and ran on both bay drives** (F4 accepted,
hdparm blocking in the SG_IO for the full 202-min erase). So the failures
above were **state-dependent** — the drives were carrying leftover
temp-password/conflict state from repeated failed attempts, and TUI reads
aborted the passthrough. The SATL fallback (v1.12.6) stays as the safety
net for that state; do not repeat the "HBA cannot carry the erase" claim
unqualified.

## Notes

- **iDRAC fan response**: after the flash iDRAC may no longer see drive
  temperatures and can hold fans ~30 % (PCI3018 in the lifecycle log). On the
  bench that is acceptable; if not, disable ThirdPartyPCIFanResponse
  (`ipmitool raw 0x30 0xce 0 0x16 5 0 0 0 5 0 1 0 0`) — fohdeesha's guide has
  the full recipe.
- **Rollback**: Dell's PERC H330 RAID firmware DUP restores RAID mode if ever
  needed.
- The original symptom (H330 firmware DCMD-hanging) can also be fixed by a
  plain firmware update; if the card still refuses after the drain, do that
  first — flashing a hung card bricks it.
- `boot.ipxe` still carries the temporary `tScrub T140 (efi=noruntime)` entry
  from the earlier misdiagnosis — remove when convenient.
