# 10 — HP EliteBook 830 G5 SPI unlock SOP

The precise bench procedure for the dominant fleet model (**91 locked units**). This is
the "method 2" from `09` — dump the BIOS flash, patch the password out **in place**,
write it back. Developed to preserve serial / UUID / MAC / FeatureByte / Windows key.

## Goal & scope

- Remove the BIOS **administrator password** from an HP EliteBook 830 G5 by reflashing
  the SPI flash.
- Preserve the unit's identity so it can be resold cleanly: system serial, UUID,
  FeatureByte/Build ID, MAC address, and the MSDM Windows product key.
- Assume ownership is documented (see `01`).

> **2026 update — two SPI chips, and the password may be in the 16 MB one.**
> The 830 G5 carries **U365 (`25Q256JV`, 32 MB)** and **U366 (`25Q128JV`, 16 MB)** SPI
> flash. Badcaps reports the BIOS password is in the **16 MB** dump and that
> **RCUnlocker** is applied to it. Dump **both**, try RCUnlocker on the 16 MB first,
> then the 32 MB. Do not assume the 32 MB dump is the only one.
>
> **Counter-report: RCUnlocker is not guaranteed.** A Bios-Fix (2021) user dumped and
> reflashed **both** the `25Q256JV` and `25Q128JV` with "the unlock tool" and the
> password remained. Treat RCUnlocker as **BIOS-revision specific**. If both dumps fail,
> try `schorschii/HPUnlocker.py` on each, then UEFITool/NVRAM surgery. Only then
> consider the **Sure Start EC pin-2 short** on the 16 MB chip, and only on a
> sacrificial unit with a current-limited PSU and a boardview. See
> `24-technique-deep-dive.md` §1–§2.

> **Optional pre-step: 830 G5 Sure Start EC pin-2 short [VERIFY].** If you want to try
> the pin-short before SPI work, do it on **one sacrificial 830 G5**, after dumping both
> chips twice so you can restore the EC if it bricks. Target the **16 MB EC chip**
> (`U366`/`UC6`, `25Q128JV`/`25Q128JVSN`), not the 32 MB main. Pin 2 is usually
> `DO`/`IO1`, not ground. Use the live variant: cable plugged in, short pin 2 to ground,
> press power, wait for the beeps, power down, unplug, drain, plug back in. Give it 3–4
> tries. **It is not confirmed on the 830 G5**; the closest laptop success is a ProBook
> 650 G8. If it fails or bricks, restore the original EC dump and continue with the SPI
> SOP below. Do not repeat on other units until the first result is repeatable.

## Equipment (build list)

| Item | Notes |
|---|---|
| CH341A or T48 programmer | T48 preferred; CH341A is the budget backup |
| **1.8 V adapter** | confirm chip voltage first; keep it regardless |
| **WSON8 pogo-pin probe (8x6)** | in-circuit read/write on the WSON8 pads; primary path |
| WSON8 socket **or** hot-air rework + flux | desolder if the pogo read is unstable |
| SOIC8 test clip | only for SOP8 boards; **does not fit WSON8** |
| `flashrom` (Linux, ≥1.7; v1.8 current 2026) | `ch341a_spi` programmer |
| Bench PSU + croc clips | to power the board only if it won't read unpowered |
| USB microscope / loupe | read chip markings |
| Insulation tape | isolate the clip from surrounding components |

## Step 0 — record identity (before touching anything)

Capture these from the F1 BIOS screen + the case sticker + the tScrub diagnostics
report (the report already has most of it):

- System **serial** (`5CG8403V62`)
- **UUID** (`systemuuid` in the report payload)
- **FeatureByte / Build ID** (F1 screen — HP uses it for parts lookup)
- **MAC address** (sticker / `ip link` in tScrub)
- **Windows key** — presence only (the MSDM ACPI table is inside the flash; you must
  not alter it). Check `cat /sys/firmware/acpi/tables/MSDM` in tScrub.

Write these into the unit record (serial → identity values). You'll diff against them
after the flash.

## Step 1 — locate the chip

- Remove battery + AC.
- Find the **two** SPI NOR chips near the EC/PCH. On the 830 G5 the main BIOS is
  **U365**: Winbond `25Q256` (32 MB, 3.3 V) in a **WSON8 8x6** package. The second is
  the **EC BIOS**, refdes **U366 or UC6**: 16 MB `25Q128JV` / `25Q128JVSN`. Parts
  listings describe the 16 MB `UC6` chip as the "EC BIOS"; a few board revisions are
  reported with an 8 MB EC chip instead, so confirm the marking/size. Both are WSON8 on
  the boards we have seen (no legs — use a WSON8 pogo probe or hot air + socket, **not a
  SOIC8 clip**). Confirm the actual part + package on **your** unit — board revisions
  differ. See `21-hardware-bom.md` and `24-technique-deep-dive.md` §2.

## Step 2 — dump BOTH chips (back up twice each)

Dump each chip twice and hash each pair. Use separate filenames so a patch can never
land on the wrong chip:

```bash
# 32 MB main (U365)
flashrom -p ch341a_spi -r 830g5_32mb_orig1.bin
flashrom -p ch341a_spi -r 830g5_32mb_orig2.bin
sha256sum 830g5_32mb_orig1.bin 830g5_32mb_orig2.bin

# 16 MB second chip (U366)
flashrom -p ch341a_spi -r 830g5_16mb_orig1.bin
flashrom -p ch341a_spi -r 830g5_16mb_orig2.bin
sha256sum 830g5_16mb_orig1.bin 830g5_16mb_orig2.bin
```

If a pair differs, the pogo/clip contact is poor — re-seat (clean the pins, check
pin-1 orientation) and redo. **Never proceed on a single dump of either chip.**

## Step 3 — patch (in-place, preferred)

Patch **the unit's own dump**, never flash another unit's image (that would clone the
other unit's serial/MAC/key and may not boot).

**Try the 16 MB dump first.** Badcaps reports the 830 G5 password is cleared by
running **RCUnlocker** on the **16 MB** dump. The long "HP Elitebook 830 G5 Admin Bios
Unlock" thread has independent 2023–2025 confirmations; one user read the chip with a
**BeeProg+** and one says they "can't run 32 MB files", so RCUnlocker is a 16 MB-class
tool. If that is your revision, you may not need to touch the 32 MB main flash at all.
Note the 16 MB chip may be marked `25Q128JVSN` and its refdes may be **U366 or UC6** —
go by the marking/size, not the silkscreen label alone.

**Open-source cross-checks for the 16 MB dump:**

- `R3n5k1/dellpwn scan` — looks for `HpPassphraseStructureVariable` and prints active
  SHA-1 entries. `clear-hp-password` deactivates them.
- `libv/hp_bios_passwd_erase` — looks for `$VSS`, `UserCred` or 2018
  `NvramActiveRegn`. It is verified on 650 G4 / 840 G5.
- **Our existing 32 MB dump matches neither tool** (0 `HpPassphraseStructureVariable`,
  0 `$VSS`/`UserCred`, and the 4 `NvramActiveRegn` blocks use a different subheader).
  Do not patch the 32 MB dump with `hp_erase`. See
  `25-github-sweep-and-dump-tests.md` for the scan results.

If the 16 MB tools do not apply, fall back to UEFITool on the 32 MB dump.

Three ways, in order of preference:

1. **Diff-learned patch (best).** Apply the byte patch you previously derived for this
   exact model + BIOS revision (see Step 6). Minimal, preserves everything else.
2. **UEFITool variable clear.** Open `830g5_32mb_orig1.bin` (or the 16 MB dump) in
   UEFITool, find the setup / NVRAM variable holding the administrator password, and
   clear it (write a blank/cleared value). Re-insert. This keeps serial/UUID/MAC/key
   untouched by construction.
3. **Clean HP image + identity re-inject (fallback).** Flash HP's official BIOS image,
   then restore serial/UUID/FeatureByte/MAC/MSDM from the original dump. Only if 1–2
   aren't viable; verify every identity field after.

> Do **not** hand-guess password byte offsets. Use a diff-learned patch or UEFITool.

### Why patching works — hashing & signing, not encryption

The flash is a container of regions: **descriptor** (layout map), **GBE** (MAC),
**ME** (signed Intel Management Engine — never modify), and the **BIOS/UEFI** region.
The BIOS region is **compressed** (LZMA), **not encrypted** — `UEFITool` decompresses
and edits it in place.

The admin password is stored **hashed** inside an NVRAM/setup variable. You don't
decrypt or crack it — you **overwrite the stored hash with the "unset" state** (or
replace the region with a known-clean copy). No cryptography is involved.

The real constraint is **signing/integrity** (Intel Boot Guard, HP Sure Start, Secure
Boot) — *verification*, not encryption. Mitigate by patching only NVRAM data and
leaving the boot block + ME/descriptor regions untouched; if the platform flags the
image, recover via HP BIOS recovery (Win+B) and re-inject identity.

## Step 4 — write + verify

```bash
# write only the chip you actually patched (16 MB or 32 MB)
flashrom -p ch341a_spi -w 830g5_16mb_patched.bin
flashrom -p ch341a_spi -v 830g5_16mb_patched.bin
```

## Step 5 — power test + identity check

Reassemble (battery + AC), boot, and confirm:

1. **No password prompt** — F10 enters setup without a password.
2. **Serial / UUID / FeatureByte** — match the Step 0 record (`/sys/class/dmi/id/product_serial`,
   `/sys/class/dmi/id/product_uuid`).
3. **MAC** — matches the sticker (`ip link show`).
4. **Windows key** — `/sys/firmware/acpi/tables/MSDM` still present and byte-identical
   to a pre-flash capture.
5. **TPM / Secure Boot** still detected (tScrub report shows `tpm=2.0`).

Only mark the unit "done" when all five pass. Then hand to the erasure line.

## Step 6 — develop the patch once, SOP the rest

- On the **first** unit, capture **both** dumps (`830g5_32mb_orig1.bin` and
  `830g5_16mb_orig1.bin`) and the patched chip, then save the minimal diff as the
  model patch (tied to BIOS revision — see the fleet's revision spread in `06`).
- Optionally bootstrap with the **buy-one-unlock-and-diff** trick (`09`): pay for one
  patch, diff it, replicate free.
- Store a library: `bios-unlock/patches/<model>/<revision>/` with `orig.sha256`,
  `patch.diff`, and notes.
- Subsequent units: dump → apply known patch → reflash → verify. ~10 min/unit.

## Risks & gotchas

| Risk | Mitigation |
|---|---|
| Wrong voltage (1.8 V vs 3.3 V) kills chip | Confirm part marking; use the adapter |
| Bad clip contact → corrupt dump | Double dump + SHA-256 match before patching |
| Cloned identity (serial/MAC/key) | In-place patch only; verify Step 5 fields |
| **HP Sure Start** trips on a modified BIOS | Patch minimally (don't touch the boot block); if it complains, use HP BIOS recovery (Win+B) with a clean image + re-inject identity. Test on the sacrificial unit. |
| In-circuit clip back-powers the board | Isolate with tape; prefer unpowered read |
| Wrong chip patched (32 MB vs 16 MB) | Keep the dumps separate; label files; patch one chip at a time and boot-test |
| Pogo pressure damages pads/components | Low pressure, support the board, magnification; desolder if the pads look worn |
| T48 + pogo reads unstable | Slow SPI clock to ~8 MHz; keep Check-ID and Verify-after-Read on |

First action: build the bench, then run Steps 0–5 on `5CG8403V62` as the sacrificial
unit to establish the model patch.
