# 07 — Password-removal techniques (model-specific)

How to actually remove the passwords on **your** fleet (see `06-fleet-plan.md`).
Ordered non-invasive → invasive. Confidence tags: **[CONFIRMED]** = widely proven,
**[LIKELY]** = proven on close relatives, **[VERIFY]** = test on one sacrificial unit.

## 0. Grounding + self-hosting the generator

- Canonical generator: **bios-pw.org**. It computes Dell / HP / Insyde / Fujitsu-Siemens
  master passwords from the on-screen code/serial.
- The algorithms are **open source**: `github.com/bacher09/pwgen-for-bios`.
  **Self-host it** — for an ITAD line you do not want unit serials/hashes leaving the
  building, and a local copy also works offline on the bench.
- What the BIOS shows you maps to a generator "type":
  | BIOS shows | Generator type |
  |---|---|
  | `i 12345678` (Insyde H2O) | Insyde H20 (generic) — 8 decimal digits |
  | HP legacy `12345` | Hewlett-Packard — 5 decimal digits |
  | Fujitsu-Siemens codes | Fujitsu-Siemens (5-digit / 8-hex / 5×4-hex / 5×4-dec) |
  | Dell service tag + suffix | Dell |

---

## 1. HP EliteBook/ProBook — the "i-code" hash method  **[VERIFY per model]**

> **2026-10-03 update:** the EliteBook **830 G5 does NOT show the hash** — verified on
> `5CG8403V62` (BIOS `Q78 Ver. 01.03.00`): 3 wrong attempts → "System Locked / press
> power button", no code. Route the 830 G5 fleet (91 units) to **§2 SMC** or **§5 SPI**.
> The method below may still work on the older **820 G3 / ProBook 650 G2-G4 / ZBook 15
> G3** (2016-era Insyde) — **verify one unit before batch**.

### Per-unit procedure (Insyde i-code — e.g. 820 G3)

1. Power on. At **"Enter Administrator Password"**, type a wrong password and press
   Enter — **three times**.
2. After the 3rd failure the BIOS shows **"System Disabled"** and a code like
   `i 78634292`. The code is `i` + space + **8 digits**. Write it down verbatim.
3. In your (self-hosted) generator: select **Insyde H20 (generic)**, enter the **8
   digits** (with or without the `i ` prefix — the site accepts the full code), click
   **Get password**.
4. Reboot the unit, enter the returned master password at the prompt.
5. Enter setup (F10) → **Security → Administrator Password**: clear it (or set a
   known one first, save, then clear). Save & Exit.
6. Reboot to confirm no prompt → hand to the tScrub grade + erasure line.

### Batch flow (bench)

- Fixed bench keyboard + a printed capture sheet (serial, code, master pw, cleared?).
- Type the code slowly — the leading `i` and digit order both matter; a typo returns a
  wrong master password.
- Batch: enter 3 wrong passwords on unit A, then B, then C (the wrong-password step is
  the slow part — pipeline it while you compute A's master pw).
- End state must be **no password**, not a known one — otherwise the buyer inherits
  your master.

### Gotchas

- Some units show the code only after a specific keypress (e.g. pressing **Enter** on
  an empty prompt first, or F2/F10). If no code appears after 3 fails, try one more
  wrong entry or check the model-specific note.
- A few BIOS revisions hide the code entirely → fall to §2 (SMC) or §5 (SPI).

---

## 2. HP — SMC path (no-hash models)  **[VERIFY]**

EliteBook 830 G5 (35 — no hash shown), 830 G6 (1), 830 G8 (1), 830 G10 (1),
650 G9 (1), ZBook Fury G8 (2).

- **830 G5 is already confirmed no-hash** (see §1). The G6/G8/G10/G9/Fury G8 may still
  show the i-code on some revisions — test one unit first.
- If no code: two options.
  1. **HP SMC path (official).** HP issues a signed **SMC.bin** (Secure Management
     Command) tied to the unit's serial/UUID; it's applied via the HP BIOS
     Configuration Utility from a FAT32 USB and resets the admin password. Requires
     **proof of ownership** — which you have from the transfer paperwork. Batch these
     as a support request rather than bench work.
  2. **SPI reflash** (§5) — patch the password out of the dumped image.

---

## 3. Lenovo ThinkPad — no master password  **[VERIFY per board]**

MTM `20T3` (T14 Gen 1), `20U4` (X13 Gen 1), `20WL` (P-series): **9 units**.

Lenovo issues **no master password**, so this is the bench track. On these 2020 units
the supervisor password (SVP) is stored in the **MEC1663 embedded controller**, not just
in the main SPI flash. A main-flash-only patch can leave the password in place.

In order of preference:

1. **BIOS dump + Lenovo Auto-Patcher.** Dump the main flash (Winbond W25Q256, WSON8 8x6)
   with a CH341A/T48 + `flashrom`, run the community **"Lenovo BIOS Auto-Patcher"**
   (public mirror: `suds-mcduff/lenovo-bios-autopatcher`) to strip SVP, reflash.
   **Caveat:** the author says it supports Lenovo **up to 8th gen**; our T14/X13 Gen 1
   are **10th gen**, so it may refuse. It also **may not clear the MEC1663 copy**.
2. **Service mode / JTAG pin short.** r/thinkpad reports finding the JTAG port and
   shorting pins **4, 8 or 10** at boot. Badcaps has a "T14s - MEC-1663 - JTAG - Pinout"
   thread. This is model-specific and risky; confirm the pinout for the exact board
   before shorting anything.
3. **SVOD + MEC1663 pinout.** Read/patch the MEC1663 EC via its JTAG/ISP pins with an
   SVOD or similar programmer. This is the reliable EC route, but needs fine soldering
   and a specialist tool.
4. **EC replacement.** Some revisions hold SVP only in the EC; the fix is to reflash
   or replace the EC chip.

**Scale call:** at 9 units, do not build MEC1663 rework capability. Check the
Auto-Patcher's model support first: its README says **up to 8th gen**, so it likely
refuses our **10th-gen** T14/X13 Gen 1. If it refuses or the SVP survives, outsource
those units or swap the board. Full detail and the YouTube guides are in
`24-technique-deep-dive.md` §3.

> Community tools are third-party and run on *your* dump — vet them, run them offline,
> and back up the image twice before patching.

---

## 4. Fujitsu / Fujitsu-Siemens  **[LIKELY generator for FS codes]**

LIFEBOOK E736 (1), Siemens LIFEBOOK S6410 (1).

- **S6410 (legacy, ~2007):** first try CMOS clear / jumper (§03). If the BIOS shows a
  Fujitsu-Siemens code after failed attempts, feed it to the generator's
  Fujitsu-Siemens tab (5-digit / 8-hex / 5×4-hex / 5×4-dec formats).
- **E736 (UEFI, 2016):** if the BIOS displays a Fujitsu-Siemens code → generator;
  otherwise **SPI reflash** (§5). Fujitsu has no self-serve master-password portal.

---

## 5. Worked SPI example (shared fallback)  **[CONFIRMED workflow]**

Use when §1–§4 fail. Full safety notes in `04-spi-flash-reflash.md`.

```bash
# identify + dump twice (CH341A; confirm 1.8V vs 3.3V first)
flashrom -p ch341a_spi -r dump1.bin
flashrom -p ch341a_spi -r dump2.bin
sha256sum dump1.bin dump2.bin        # MUST match

# patch the password OUT of the unit's own dump (never clone another unit's image):
#   - UEFITool: locate the NVRAM/setup variable store, clear the password variable
#   - or Lenovo Auto-Patcher (Lenovo SVP) / HP clean-variable patch
#   - keep serial / MAC / UUID / ME region from dump1.bin

flashrom -p ch341a_spi -w patched.bin
flashrom -p ch341a_spi -v patched.bin   # verify
```

Expected chips: HP 830 G5 → Winbond W25Q256 (32 MB, **WSON8 8x6**); HP 820 G3/650 G4 →
W25Q128 (16 MB, WSON8) + an EC chip; Lenovo T14/X13 Gen 1 → W25Q256 (WSON8 8x6);
Fujitsu E736 → Winbond/Macronix SOP8. Identify from the markings before
clipping — a SOIC8 clip does not fit WSON8 (see `21-hardware-bom.md`).

---

## 6. Record & hand-off

For every unit, log: **serial → method → outcome → erasure report id**, so the
locked→unlocked→sanitised trail is auditable. After unlocking, run the normal tScrub
grade + erasure flow so the sale evidence chain is complete.
