# 24 — Technique deep dive: Sure Start EC, 830 G5 chip map, Lenovo MEC1663

**Status:** second Google/Reddit/YouTube pass · 2026-10-23
**Scope:** the specific techniques and failure modes behind the new leads, and the
revised per-model test plan.
**Related:** `22-2026-trends.md` (first trends pass), `23-reddit-sweep.md` (Reddit
threads), `21-hardware-bom.md` (bench BOM), `10-hp-830g5-spi-sop.md` (830 G5 SOP).

## 1. HP Sure Start EC pin-short — the full picture

### 1.1 Where it came from

The original write-up is Rhys Goodwin's blog post **"Elitedesk 800 G6 Mini BIOS
Password Removal"** (`blog.rhysgoodwin.com/hp-elite-800-bios-password-removal/`,
now 404). The content survives in the GitHub issue comments:
`github.com/RhysGoodwin/rhysgoodwin.github.io/issues/4`.

The key sentence from the blog:

> "pin 2 of the GigaDevice flash chip. I believe this is the 16MB flash chip used by
> the Sure Start embedded controller, it's not the main BIOS."

So this is **not** an SPI programmer technique. It is a deliberate corruption of the
**16 MB Sure Start EC flash** so that the firmware treats the stored security data as
invalid and resets it to defaults.

### 1.2 The chip

- 16 MB SPI flash, usually **GigaDevice** or **Winbond `25Q128JVSQ`**.
- On the EliteDesk 800 G6 Mini/SFF it is a separate chip from the main BIOS.
- The same 16 MB class appears in the 830 G5 as **U366/UC6 (`25Q128JV` / `25Q128JVSN`),
  which parts listings call the "EC BIOS"** (see §2). That is the reason this method is
  worth investigating for our fleet.
- **Caveat:** the confirmed desktop successes are on GigaDevice/Winbond 16 MB chips with
  a known pinout. No one has reported the pin-short working on an EliteBook 830 G5. The
  chip vendor and board layout differ, so this remains a hypothesis, not a procedure.

### 1.3 Two variants of the procedure

**Cold variant (original blog):**

1. Power off, unplug the PSU, remove the battery if present.
2. Short pin 2 of the 16 MB Sure Start EC flash to ground.
3. Plug in power, power on. The board may flash red and beep.
4. Power off, unplug, hold the power button ~15 s to drain.
5. Power on. Password should be gone.

**Live variant (most successful reports):**

1. Leave the power cord plugged in.
2. Short pin 2 to ground.
3. Press the power button and let the board beep/flash until it stops.
4. Power down with the button, unplug, drain the flea power, plug back in.
5. Power on. May need 3–4 attempts.

The live variant is the one repeatedly reported as working on the 800 G6 Mini/SFF and
800 G4 Mini. The cold variant worked less often.

### 1.4 Confirmed successes and failures

| Model | Result | Notes |
|---|---|---|
| HP EliteDesk 800 G6 Mini / SFF | **Works** | Original blog; multiple confirmations |
| HP EliteDesk 800 G4 Mini (65 W) | **Works** | `25Q128JVSIQ`; live variant; sometimes leaves "HP Endpoint Security Controller Problem" |
| HP ProBook 805 G6 | **Works** (Reddit) | Same pin position; password cleared. One report of a 15 s "HP Endpoint Security Controller Problem" splash timer after; a BIOS update did not fix it |
| HP ProDesk 600 G6 | **Works** (Reddit) | Confirmed by commenter |
| HP ProBook 650 G8 | **Works** (Reddit) | r/laptops user shorted the pin, took **3 tries**, BIOS access restored. Cable plugged in during the procedure |
| HP laptop with `GD25B256DYIG` | **Works** (Reddit) | User says "not the bios one", so the second/EC chip. Pin 2 to the USB port shield with tweezers; admin password was gone. Exact model not stated |
| HP Elite Mini 600 G9 | **Not confirmed / fails** | Multiple reports of no success |
| HP EliteDesk 800 G9 / 800 G9M | **Fails** | Reports of no success |
| HP ProDesk 400 G6 Mini | **Fails** | No GigaDevice/16 MB Sure Start chip present |
| HP EliteBook 840 G10 | **No confirmed success** | The r/laptops OP's 2023 unit; HP support said replace the motherboard |
| HP EliteBook 840 G6 / 830 G5 | **Unverified** | The key open question for us |

### 1.5 Risks

- **Bricking.** At least one GitHub commenter says the PC was bricked after trying the
  "Sure Start chip" variant. Another says the jumper wire heated up and smelled of
  burning when connected to a 2-pin Winbond chip.
- **HP Endpoint Security Controller Problem.** Several successful password clears left
  the machine showing this error or a 15 s splash timer. One r/laptops repairer warns
  that on newer HP, after reflashing the main chip you often get the Endpoint Security
  error; the workaround is to hex-edit the serial/identity area in the dump to force
  manufacturing mode, then re-enter the serial. That is a second, separate rework.
- **Wrong pin.** Pin 1 is marked by a dot/notch. On a standard 25-series WSON8, pin 2 is
  usually `DO`/`IO1`, **not ground** — the desktop method deliberately pulls that signal
  low during boot. On a different board or chip, pin 2 may be a different signal. Confirm
  from the schematic; never copy the pin number blindly.
- **No boardview, no attempt.** We need the 830 G5 boardview/schematic to identify the
  16 MB chip and pin 1 before touching it.

### 1.6 Our position

- **Do not try on a customer unit.**
- The ProBook 650 G8 success is the closest laptop data point we have, and the
  `GD25B256DYIG` report shows it can work on a second/EC chip, not just a desktop.
  That justifies one sacrificial 830 G5 test, not a fleet-wide procedure.
- Put it on the sacrificial-unit list for one 830 G5 or 830 G6, with a current-limited
  PSU and the boardview.
- If it works, it may be faster and cheaper than a WSON8 pogo reflash. If it fails, we
  have lost bench time on a unit that was already locked.
- Record the exact chip marking, pin 1 orientation, and result in the survey worksheet.

## 2. The 830 G5 chip map — and the conflicting reports

The 830 G5 carries:

| RefDes | Part | Size | Role |
|---|---|---|---|
| **U365** | `25Q256JV` | 32 MB | Main BIOS |
| **U366 / UC6** | `25Q128JV` / `25Q128JVSN` | 16 MB | **EC BIOS** — parts listings describe the 16 MB `UC6` chip as the "EC BIOS"; this is where RCUnlocker is applied |

> Board revisions vary. One VinaFix post mentions an **8 MB** `U366` on a different
> 830 G5 board, and the refdes can be `U366` or `UC6`. Confirm the actual size and
> marking on your unit before assuming the 16 MB part.

**What we know:**

- Badcaps (2022): a reply told a user whose 32 MB patch did not clear the password to
  **"use the RCUnlocker tool on the 16 MB bios"**. The user later confirmed the BIOS
  was unlocked.
- Badcaps (2023, 2024, 2025): multiple independent users in the long
  **"HP Elitebook 830 G5 Admin Bios Unlock"** thread (started 2021-09-25, 139 replies)
  say the same thing: **RCUnlocker on the 16 MB chip**. One 2024 post reads the 16 MB
  chip with a **BeeProg+** and drags the dump into RCUnlocker. One 2023 post says they
  "can't run 32 MB files", which is consistent with RCUnlocker being a 16 MB-class tool.
- The 16 MB chip is marked `25Q128JVSN` on at least one 830 G5 board, with refdes
  **UC6** (not U366). Refdes varies by board revision; go by the marking and size.
- Bios-Fix (2021): a user **dumped and reflashed both** the `25Q256JV` and `25Q128JV`
  with "the unlock tool", and the password remained. They did the chips separately and
  power-cycled between.
- Badcaps (2021-2026): the main thread shows a lot of trial and error, including units
  that no longer power on. Some users succeed, some do not.
- **Why the 16 MB chip is the one that matters:** on the 830 G5 it is the **EC BIOS**
  (`UC6`), and the HP password store lives in the EC firmware. The 32 MB `U365` is the
  main BIOS. That is consistent with the Sure Start EC pin-short lead on the desktop
  models: both are attacking the EC's 16 MB store, not the main BIOS.

**Interpretation:** the 2023–2025 evidence is stronger than the single 2021 failure:
the **16 MB chip is the primary target**. But the result is still **tool-version and
BIOS-revision dependent**, so do not assume one tool on one chip is enough. Use this
test matrix on a sacrificial 830 G5:

1. Dump both chips twice; hashes must match.
2. Try **RCUnlocker on the 16 MB dump**. Reflash only the 16 MB chip. Boot test.
3. If still locked, try **RCUnlocker on the 32 MB dump**. Reflash only the 32 MB chip.
4. If still locked, try **`schorschii/HPUnlocker.py`** on each dump (it patches the
   password bytes to `FF`). Test one chip at a time.
5. If still locked, try the **Sure Start EC pin-2 short** on the 16 MB chip (last
   resort; sacrificial unit only).
6. If all fail, fall back to UEFITool/NVRAM surgery or the buy-one-patch-and-diff
   approach.

After every step, verify serial / UUID / MAC / MSDM and bootability. Keep the original
dumps and a patch log.

**What our 32 MB dump showed (2026-10-23):** it has **0**
`HpPassphraseStructureVariable` markers, 0 `$VSS`/`UserCred`, and 4
`NvramActiveRegn` blocks that do **not** match the `H_FbPolicy`/`L_MpmPolcy` layout
`hp_erase` expects. Both `dellpwn` and `hp_erase` therefore skip it. That is strong
evidence the password is in the **16 MB EC BIOS**, not the 32 MB main dump. Full scan
results and the revised open-source-first test matrix are in
`25-github-sweep-and-dump-tests.md`.

**Programmer choice is not the bottleneck.** One 2024 user read the 16 MB chip with a
BeeProg+, but a T48 or CH341A reads the same part. Spend the budget on a proper WSON8
pogo probe, a hot-air station and a WSON8 socket instead of an expensive programmer.

## 3. Lenovo T14 / X13 Gen 1 — the MEC1663 EC

The 2020 ThinkPads in the fleet (`20T3` T14 Gen 1, `20U4` X13 Gen 1, `20WL` P-series)
store the supervisor password (SVP) in the **MEC1663 embedded controller**, not only in
the main SPI flash. That is why a main-flash-only patch can leave the password in place.

**Techniques seen in the wild:**

| Technique | What it involves | Risk / notes |
|---|---|---|
| **Lenovo Auto-Patcher** | Patch the main BIOS dump to strip the SVP, reflash | Public mirror: `suds-mcduff/lenovo-bios-autopatcher`. Supports Lenovo **up to 8th gen**; our T14/X13 Gen 1 are **10th gen**, so likely **out of scope**. May not clear the EC copy |
| **SVOD + MEC1663 pinout** | Read/patch the MEC1663 EC via its JTAG/ISP pins | Badcaps has a "T14s - MEC-1663 - JTAG - Pinout" thread; needs an SVOD or similar programmer and fine soldering |
| **JTAG pin short** | Short pins 4, 8 or 10 on the JTAG port at boot | Reported on r/thinkpad; very model-specific and risky |
| **EC replacement** | Replace the MEC1663 | Only for high volume; not worth it for 9 units |

**YouTube guides:**

- **Laptop Repair Zone** — "How to Reset & Unlock Lenovo T14 Gen 1 BIOS Supervisor
  Password" (3:43, 11.1k views, ~5 months old).
- **M._.x._.P** — "LENOVO T14 Gen1 NM C931 MEC1663 Bios Password..." (2:09, 15.6k
  views) — describes the EC chip route.
- **M._.x._.P** — "Bios Password Removal LENOVO T14s X13 Mec1663 NM-..." (2:03,
  13.4k views) — pinouts and file modification with SVOD.
- **MoKernel** — "How I Bypassed a BIOS Password on a ThinkPad (Full Tutorial)"
  (5:06, 31.2k views, ~11 months old) — dump-and-patch workflow.
- **derronecks official** — "HOW TO USE LENOVO AUTOPATCHER..." (8:39, 13.6k views,
  ~1 year old).

**Our position:** the Auto-Patcher's README says it covers Lenovo machines **up to the
8th generation**. Our T14/X13 Gen 1 are **10th gen** (Comet Lake / Ryzen 4000), so do
not assume it works. Check the script's model support on one sacrificial unit only; if
it refuses or the SVP survives, go straight to the MEC1663 EC route or outsource. At 9
units, do not build EC rework capability; outsource or swap the board. Revisit only if
Lenovo volume grows.

## 4. WSON8 pogo — best practices from the second pass

The second pass reinforced the following:

1. **Use a proper WSON8 pogo probe, not a cheap clip.** Badcaps (Jan 2026) on CH341A
   SOP8 in-circuit clips: "It's absolutely abysmal, you have to hold the clip to the
   board really tightly to get it to read, and then stay there the entire time."
2. **Measure VCC on pin 8 before connecting.** A Badcaps Dell 7430 thread: "You should
   have 3.3 V on pin 8 of UC2. The BIOS chip is a WSON8 6x8. and a cheap pogo pin
   clip..." The voltage is the first thing to confirm.
3. **Do not assume 3.3 V.** Libreboot's SPI guide: "Do NOT assume that the voltage is
   3.3 V... lots of modern flash chips today are 1.8 V." The ASRock/Dell 1.8 V examples
   are real.
4. **T48 + pogo: slow the SPI clock.** Use ~8 MHz, keep Check-ID and
   Verify-after-Read on, and compare checksums across multiple reads.
5. **Low pressure and board support.** The Dell 5530 thread shows pads and nearby
   components damaged by pogo pressure. Support the board underneath and stop if the
   pads look worn.
6. **If the read is unstable, desolder.** A proper WSON8-to-DIP8 socket is more
   reliable than fighting a pogo probe.

## 5. Tooling notes

| Tool | What it is | Status |
|---|---|---|
| **RCUnlocker** | HP dump patcher used by Badcaps/repair techs | Reported to work on the 830 G5 16 MB dump; not universal |
| **HPUnlocker.py** (`schorschii` gist) | Python script that patches HP UEFI/BIOS password bytes to `FF` | Free; last active 2026; users report it is the same class as RCUnlocker and does not work on every model |
| **`libv/hp_bios_passwd_erase`** | Open-source C tool for `$VSS`, `UserCred`, 2018 `NvramActiveRegn` | Verified on 650 G4 / 840 G5; **not** on our 32 MB 830 G5 dump (layout mismatch) |
| **`R3n5k1/dellpwn`** | Open-source Rust tool; HP `HpPassphraseStructureVariable` SHA-1 scan and NVAR deactivation | Not triggered on our 32 MB dump (0 markers); test on the 16 MB EC dump |
| **HPUnlocker** (Badcaps) | Older HP unlock utility | Still referenced; mixed results |
| **Lenovo Auto-Patcher** | Patches ThinkPad BIOS dumps to strip SVP | Community tool; README says up to **8th gen**, so T14/X13 Gen 1 (10th gen) may be out of scope; check the EC copy too |
| **SVOD** | Programmer used for Lenovo EC/MEC1663 work | Specialist tool; not needed for the HP fleet |
| **flashrom 1.7/1.8** | Linux dump/write/verify | Use v1.7 or newer |

## 6. Revised per-model plan

| Model | First attempt | Second attempt | Last resort |
|---|---|---|---|
| **830 G5 (91)** | Dump both chips; RCUnlocker on 16 MB | RCUnlocker/HPUnlocker.py on 32 MB; UEFITool NVRAM | Sure Start EC pin-2 short (sacrificial only) |
| **820 G3 (28)** | Dump main + EC; try HPUnlocker/RCUnlocker on dumps | Manual NVRAM patch | Desolder/reflash; EC rework only if needed |
| **650 G4 (15)** | `libv/hp_bios_passwd_erase` on the dump (author verified 650 G4) | RCUnlocker/HPUnlocker.py | Sure Start EC pin short only if the chip is identified |
| **650 G2 (10)** | Confirm SOP8 vs WSON8; dump/patch | Same HP tools | SPI reflash |
| **Lenovo T14/X13 (9)** | Check Lenovo Auto-Patcher support (README: up to 8th gen; ours are 10th gen) on one sacrificial unit | SVOD/MEC1663 pinout or JTAG pin short | Outsource / board swap |
| **Newer HP (G6/G8/G10/Fury G8/650 G9)** | Confirm 1.8 V vs 3.3 V; dump both chips | RCUnlocker/HPUnlocker.py | Sure Start EC pin short (sacrificial only) |
| **Fujitsu (2)** | SOIC8 clip; dump/patch | SPI reflash | — |

## 7. Open questions

- Does the Sure Start EC pin-2 short work on the **830 G5 / 830 G6**, and which chip is
  the Sure Start EC flash on those boards?
- Does RCUnlocker clear the 830 G5 password on the **16 MB** dump on our BIOS revisions,
  or was the Badcaps success a different revision?
- Why did the Bios-Fix user fail on both chips in 2021? Tool version, BIOS revision, or
  a third store?
- Does `libv/hp_bios_passwd_erase` clear the 650 G4 on our revisions?
- Does the Lenovo Auto-Patcher support 10th-gen T14/X13 at all, or does every unit need
  MEC1663 work?
- Are the newer HP models 1.8 V or 3.3 V?

## 8. Sources

- Rhys Goodwin, "Elitedesk 800 G6 Mini BIOS Password Removal" (blog now 404; mirrored in
  `github.com/RhysGoodwin/rhysgoodwin.github.io/issues/4`).
- r/Hewlett_Packard "BYPASS HP BIOS PASSWORD:" (`/comments/1tdx6ka/`).
- r/laptops "BIOS locked HP Elitebook - Laptop otherwise works fine."
  (`/comments/1th7s07/`): ProBook 650 G8 pin-short success (3 tries), `GD25B256DYIG`
  second-chip success, EliteBook 840 G10 failure, and the Endpoint
  Security/manufacturing-mode warning.
- Badcaps "Help with HP 830 G5 bios" (RCUnlocker on the 16 MB dump; `W25Q256FV@WSON8
  8X6`).
- Badcaps "HP Elitebook 830 G5 Admin Bios Unlock" (started 2021-09-25; 139 replies;
  2023–2025 posts confirm RCUnlocker on the 16 MB chip; one uses a BeeProg+).
- Badcaps "Hp elitebook 840 g5 bios password" (thread is actually an 830 G5; "Use rc
  unlocker for 16 MB chip"; 2023).
- PicClick / parts listings for the 830 G5: "EC bios (UC6) — 128 Mbit, 16 MB, 3 V".
- VinaFix "HP EliteBook 1030 G1 BIOS..." (mentions an 830 G5 board with a 32 MB BIOS
  and an 8 MB `U366`; board revisions vary).
- Bios-Fix "unlock bios password hp elitebook 830 g5" (2021; both chips reflashed,
  password remained).
- Badcaps "T14s - MEC-1663 - JTAG - Pinout - Bios Password Removal".
- r/thinkpad "[GUIDE] ThinkPad BIOS: Reading, Patching, and Flashing".
- YouTube: Laptop Repair Zone (T14 Gen 1), M._.x._.P (MEC1663), MoKernel (ThinkPad
  bypass), derronecks official (Lenovo Auto-Patcher).
- Badcaps "Dell Latitude 7430 Dead after BIOS reflash" (3.3 V on pin 8; cheap pogo).
- Badcaps "CH341A SOP8 in-circuit test clips, any good ones?" (Jan 2026).
- Libreboot SPI guide (do not assume 3.3 V).
- `gist.github.com/schorschii/HPUnlocker.py` (HP UEFI/BIOS password patch script).
