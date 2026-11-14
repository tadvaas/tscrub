# BIOS bench tooling, software & clip-ons — 2026 survey

A November 2026 refresh of the hardware BOM (`21-hardware-bom.md`) and the tool
trends (`22-2026-trends.md`), pulled from live eBay UK search results, AliExpress /
Amazon listings and current roundups. **Conclusion up front: the plan is unchanged —
one XGecu T48 primary, a CH341A as backup, and the free password patchers do the
actual unlock.** This doc pins the current software versions, the clip-on options and
the prices so the BOM can be ordered without re-researching.

## Programmers (recap, with prices)

| Tool | Status Nov 2026 | Price signal |
|---|---|---|
| **XGecu T48** (TL866-3G) | Value standard. 31,000–34,000+ ICs, SPI/NOR/NAND/EMMC/MCU/EPROM, firmware **V12.59** | ~£50–100 (AliExpress / Amazon bundles) |
| **XGecu T76** | USB 3.0, **T56 replacement** (T56 discontinued) | ~£150–250 |
| **XGecu TL866II Plus** | Deprecated predecessor, still sold as "upgraded T48" bundles | ~£40–70 — skip |
| **CH341A** | Ubiquitous budget board; black PCB needs the 5 V→3.3 V data-line mod | **£5–8 kit** (clip + 1.8 V adapter + 150 mil socket) |
| **EZP2023+** | Mid-tier pick now appearing in the 2026 roundups | ~£20–40 |

Live eBay UK data point (`(T48, CH341A) programmer`, 1,882 listings): the price curve
is **1,481 under £50, 314 at £50–100, 58 at £100–150** and a ~30-item tail above £150 —
the CH341A flood at the bottom, T48 in the middle, T76 at the top. Concrete listings:
CH341A "24/25 Series + SOP clip" £7.59 (2,127 sold), bare CH341A £4.99,
CH341A + SOIC8 clip + 1.8 V adapter kits in between.

## Software — programmer applications

- **flashrom** — current stable **v1.8 (Aug 2026)**; v1.7 Feb 2026, v1.6 Jul 2025.
  CH341A and CH341B are both supported **once the crystal mod is done** (a bare
  CH341A has no oscillator). Still the open-source dump/write/verify CLI our docs use.
- **NeoProgrammer** — `GioLangLe/CH341B-NeoProgramer` (also `neoprogrammer.net`).
  The current default GUI for CH341A/B: ships with drivers, cleaner modern UI, good
  chip detection. Best first choice for the bench.
- **AsProgrammer** — `asprogrammer.com`, open source, common build **V2.1.0.13**.
  Broader hardware support (CH341A, CH347, USBasp, FT232H, Arduino) and more control,
  at the cost of a steeper UI. Keep both; they can disagree on rare chips, and having
  two independents is the sanity check.
- **Xgpro** — XGecu's own app, required for the T48/T76, ships with the unit (V12.x).
- Driver/software bundles for CH341A: `YTEC-info/CH341A-Softwares`
  (Windows/Linux/macOS/Android) and
  `mrmitsenf/CH341A-drivers-software-and-documentation` (step-by-step README).
  Note YTEC's warning: the prebuilt `.exe` files in that repo are not trustworthy —
  build from source or get the binaries from the vendor.

## Software — password-removal patchers (this is the actual "unlock")

These patch a **dump**, so they sit on top of whatever programmer app you use:

- **RCUnlocker** — free, Badcaps-origin (Sept 2021), the standard HP tool. **Revision
  specific** — this is the one that has produced both "worked" and "didn't" reports on
  820 G3 / 830 G5 (see docs 24/25 and the Jan 2026 Badcaps 820 G3 thread).
- **dellpwn** — `R3n5k1/dellpwn`. "Recover and reset Dell (and some related HP) BIOS
  passwords from an SPI flash dump." Also scans/deactivates the HP
  `HpPassphraseStructureVariable` NVAR. Our 32 MB 830 G5 dump has **0** such markers,
  so it points at the 16 MB EC dump.
- **HPUnlocker.py** — `schorschii` GitHub gist ("Remove admin passwords from HP
  UEFI/BIOS"). Second HP patcher when RCUnlocker misses.
- **Lenovo Auto-Patcher** — `suds-mcduff/lenovo-bios-autopatcher` (public mirror of
  the Badcaps tool). "Reliably patch BIOS dumps for SVP-locked Lenovo machines **up
  to the 8th generation**" — so our T14/X13 Gen 1 (10th gen) may be refused, and it
  may not clear the MEC1663 EC copy (doc 24 §3).
- **Lenovo (H2O) Bios Unlocker and Locker** — Win-Raid tool; gated on finding the
  `cE!` unicode marker in UEFITool (≥2 hits = patchable).
- **`libv/hp_bios_passwd_erase`** — the open-source HP `$VSS`/`UserCred`/2018
  `NvramActiveRegn` clearer already covered in doc 25; rejected by our compressed
  830 G5 dump.

## Clip-ons / probes / adapters

The fleet is **WSON8-first** (830 G5, 820 G3, 650 G4 are all WSON8), so this is the
part that actually matters.

- **SOIC8/SOP8 test clip** — the classic clip fits **SOP8 only**, not WSON8. Pro:
  **Pomona 5250** 8-pin SOJ/SO clip (CPC Farnell UK, ~£20–25). Budget clones are
  £5–10 but the teeth slip; buy Pomona for the production bench. Only useful for the
  older SOP8 models in the fleet.
- **WSON8 pogo-pin probes** — the workhorse. Available in **8x6 mm** and **5x6 mm**
  pads, either dedicated WSON8 probes or multi-package kits covering
  SOP8/SOIC8/VSOP8/WSON8/QFN8/DFN8 (e.g. "208 mil 5.38 mm + 150 mil 3.8 mm" spring
  pogo adapters). Confirmed current sources: AliExpress ("QFN8 DFN8 WSON8 IC test
  clip probe 5X6 6X8 for CH341A EZP2019 TL866 RT809F"), Amazon UK ("SOP8 WSON8
  Adapter, PCB Chip Programming Probe — supports SOP8, SOIC8, WSON8 8x6, QFN8,
  QDFN8"), 38-3d.co.uk, plus the official T48 SOIC8 test-clip cable. ~£5–15.
- **1.8 V adapter / level shifter** — non-negotiable for post-2015 boards; measure
  the chip's VCC first (pin 8) and pick 1.8 V vs 3.3 V accordingly.
- **WSON8-to-DIP8 socket + hot air** — the fallback when the in-circuit probe is
  unstable: desolder, socket, flash, rework. Budget for it in phase 1 (doc 21).
- **Technique** (unchanged, now echoed by the mrchromebox 16 Sep 2026 unbricking
  guide): hold the WSON8 probe down (rubber bands work), run slow (8 MHz), and
  desolder if reads don't verify.

## What changed vs docs 21/22

- flashrom is now **v1.8** (was "1.7/1.8"); CH341A/B support confirmed via crystal mod.
- **NeoProgrammer** is now clearly the default CH341A GUI over AsProgrammer, and
  **EZP2023+** has joined the "budget vs T48" roundups.
- **WSON8 5x6/6x8 pogo probes are now commodity** on AliExpress/Amazon — the "you have
  to hand-build the probe" worry from the earlier notes is gone.
- T56→T76 and T48-as-standard are confirmed rather than rumoured.
