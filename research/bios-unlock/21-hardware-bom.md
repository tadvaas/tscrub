# 21 — SPI reflash bench: fleet-weighted hardware BOM

**Status:** research / procurement plan · written 2026-10-23
**Scope:** the physical bench needed to dump, patch and reflash the BIOS SPI flash on
the locked fleet, based on `fleet-bios.tsv` (174 units, 165 locked).
**Related:** `04-spi-flash-reflash.md` (workflow), `06-fleet-plan.md` (priority),
`10-hp-830g5-spi-sop.md` (first model), `11-830g5-dump-anatomy.md` (dump map),
`22-2026-trends.md` (2025–2026 tool and method changes).

> **2026 toolchain update (2026-10-23):** XGecu **T56 is discontinued**; the **T76**
> replaces it. The **T48** remains the value standard. `flashrom` is at **v1.7/v1.8
> (2026)**. Open-source password tools (`dellpwn`, `8FC8_Patcher`,
> `libv/hp_bios_passwd_erase`, RCUnlocker) have matured; see `22-2026-trends.md`.
> Also: the 830 G5 has a **second 16 MB SPI chip**, and Badcaps reports the password
> is in that dump, cleared with **RCUnlocker**. Dump both chips.

> **Do not desolder every chip.** WSON8 has no legs, but a **WSON8 pogo probe** reads
> it in-circuit. Desoldering both chips on 91 830 G5 units is 182 hot-air reworks and
> a lot of pad risk. Make pogo the production method. Desolder only when the read is
> unstable (two dumps differ, chip ID is unstable) or the EC/PCH is driving the SPI
> bus. Budget for hot air from day one, but do not plan to use it on every unit.

## 1. Fleet-weighted model list

| Model | Units | Locked | Share of locked | Flash evidence | Package | Voltage | Confidence |
|---|---:|---:|---:|---|---|---|---|
| **HP EliteBook 830 G5** | 93 | **91** | **55%** | `W25Q256` 32 MB main (**U365**) **+ 16 MB EC BIOS `25Q128JV` / `25Q128JVSN`** (**U366/UC6**) | **WSON8 8x6** | 3.3 V | Our dump confirms 32 MB; external teardown says WSON8 8x6; Badcaps 2023–2025 reports RCUnlocker on the 16 MB EC dump clears the password |
| **HP EliteBook 820 G3** | 29 | **28** | **17%** | `W25Q128` 16 MB main + `W25Q64`/`MX25L6473F` 8 MB EC | **WSON8 5x6 / 8x6** | 3.3 V | External teardown (Badcaps, 2020) |
| **HP ProBook 650 G4** | 15 | **15** | **9%** | `W25Q128JVPQ` 16 MB main + EC | **WSON8** (confirm) | 3.3 V | External repair listings |
| **HP ProBook 650 G2** | 10 | **10** | **6%** | `MX25L12873F` 16 MB | SOP8 or WSON8 — **survey** | 3.3 V | External repair listings |
| **Lenovo ThinkPad** (20WL / 20T3 / 20U4) | 10 | **9** | **5%** | `W25Q256` 32 MB | WSON8 8x6 (likely) | 3.3 V | External teardown; SVP may be split with EC |
| Lenovo ThinkStation P500 (30A7) | 1 | 1 | <1% | unknown | SOP8/WSON8 — **survey** | 3.3 V? | Desktop board, not a ThinkPad |
| HP ZBook Fury 15 G8 | 2 | 2 | 1% | unknown | likely WSON8 | 1.8 V? — **survey** | — |
| HP EliteBook 830 G6 | 2 | 2 | 1% | unknown | likely WSON8 | 3.3 V? — **survey** | — |
| HP EliteBook 830 G8 | 1 | 1 | <1% | unknown | likely WSON8 | 1.8 V? — **survey** | — |
| HP EliteBook 830 G10 | 1 | 1 | <1% | unknown | likely WSON8 | 1.8 V? — **survey** | — |
| HP EliteBook 650 G9 | 1 | 1 | <1% | unknown | likely WSON8 | **survey** | — |
| HP ZBook 15 G3 | 1 | 1 | <1% | unknown | SOP8/WSON8 — **survey** | 3.3 V? | — |
| HP ProBook 650 G5 | 1 | 1 | <1% | `W25Q256FV` 32 MB main + a second EC chip (likely `W25Q128BV` 16 MB; confirm) | **WSON8 8x6** main; EC package **survey** | 3.3 V likely | External repair listings: dual main + EC; 8th gen Intel. No confirmed 650 G5 pin-short report yet |
| Fujitsu E736 / Siemens S6410 | 2 | 2 | 1% | `Winbond`/`Macronix` (likely) | SOP8 (likely) | 3.3 V | Existing research; confirm |
| Others (Apple, NUC, Z840, ENVY) | 5 | 0 | 0% | — | — | — | Not locked; not this bench |

**What this means**

1. The top four HP models are **144 of 165 locked units (87%)**: 830 G5, 820 G3,
   650 G4, 650 G2. The 830 G5 alone is **91 units (55%)**.
2. The 830 G5 is a **32 MB Winbond `W25Q256` in a WSON8 8x6 package** — no legs, so a
   SOIC8 clip cannot grab it. It also has a **second 16 MB SPI chip** (U366); Badcaps
   reports the password is in the **16 MB** dump and that **RCUnlocker** is applied to
   it. The 820 G3 and 650 G4 are also WSON8, and carry **two** flash chips each (main
   BIOS + EC).
3. A SOIC8-clip-only kit covers only the older SOP8 minority (some 650 G2, Fujitsu,
   some ZBook 15 G3). **The bench must be WSON8-first.**
4. Package and voltage still vary by board revision. **Survey one unit of each top
   model before buying model-specific sockets** (§6).
5. The 830 G5 external evidence is the strongest: a Badcaps thread names
   `W25Q256FV@WSON8 8X6` for the 830 G5, and our own `hp elitebook 830 g5 main.bin`
   is exactly 33,554,432 bytes (32 MB).

> **Correction to `10-hp-830g5-spi-sop.md`:** that doc says "SOP8 (some boards
> WSON8)". The evidence now points the other way: the 830 G5 boards we have seen are
> **WSON8 8x6**. Treat a SOIC8 clip as the exception, not the default.

## 2. What "clipping in" means per package

| Package | In-circuit tool | Desoldered tool | Notes |
|---|---|---|---|
| **SOP8 / SOIC8** (legs on the sides) | SOIC8 test clip (Pomona 5250 208 mil or 5252 150 mil) | SOP8-to-DIP8 adapter + ZIF socket | Easiest; no rework. Cheap clips are the usual failure point. |
| **WSON8** (pads underneath, no legs) | **WSON8 pogo-pin probe** (8x6 or 5x6) held on the pads | Hot air + **WSON8-to-DIP8 socket** | The clip equivalent for WSON8. Alignment and contact are critical. |
| **WSON8, stubborn board** | — | Hot air removal + socket, then reflow | If two dumps differ, or the chip ID is unstable, desolder. |

**Rules that decide the purchase**

- A **SOIC8 clip does not fit a WSON8 chip.** Do not buy a clip-only kit and expect it
  to cover the 830 G5/820 G3/650 G4 fleet.
- A WSON8 pogo probe is a **consumable**: the spring pins bend, and the probe body can
  short adjacent pads. Buy two per size and inspect alignment under magnification.
- **1.8 V vs 3.3 V:** read the part marking, then measure VCC before connecting.
  On Winbond parts, the suffix is the tell: `...JV...` is 3 V, `...JW...` is 1.8 V.
  A 3.3 V programmer on a 1.8 V chip can kill it.
- **Main + EC:** on the 820 G3 and 650 G4, back up **both** chips. The main flash holds
  the BIOS/password; the EC flash is part of the boot path. Do not assume one dump is
  the whole story.
- **In-circuit is not always possible.** The PCH/EC may drive the SPI bus. If the chip
  ID is unstable or the two dumps differ, desolder. Budget for hot air from day one.

## 3. BOM — core bench

Prices are ballpark UK 2026; check current prices. Quantities are for **one bench**
that can process 830 G5 / 820 G3 / 650 G4 / 650 G2 / Fujitsu.

### A. Programmers and adapters

| # | Item | Spec / example | Qty | ~£ each | Why / coverage |
|---|---|---|---:|---:|---|
| 1 | Primary SPI programmer | **XGecu T48** (value) or **T76** (T56 replacement) | 1 | 60–180 | T48 is the community standard; T56 is discontinued (2026), T76 replaces it. Both handle 25-series to 32 MB; 1.8 V adapter optional/extra |
| 2 | Backup programmer | **CH341A** (black PCB) + 1.8 V adapter | 1 | 20 | Cheap backup; `flashrom` compatible; slower and fussier |
| 3 | WSON8 pogo-pin probe | **8x6 mm** | 2 | 25 | 830 G5 (91), 650 G4, Lenovo; in-circuit without desoldering |
| 4 | WSON8 pogo-pin probe | **5x6 mm** | 2 | 25 | 820 G3 main/EC; compact boards |
| 5 | WSON8-to-DIP8 socket | **8x6 mm** | 2 | 10 | Desoldered chips; reliable read/write |
| 6 | WSON8-to-DIP8 socket | **5x6 mm** | 2 | 10 | Desoldered 820 G3 chips |
| 7 | SOIC8 test clip | **208 mil**, Pomona 5250 or quality clone | 2 | 20 | SOP8 models (650 G2, Fujitsu, ZBook 15 G3) |
| 8 | SOIC8 test clip | **150 mil**, Pomona 5252 | 1 | 15 | Narrow SOP8 |
| 9 | SOP8-to-DIP8 adapter | 208 mil | 2 | 5 | Desoldered SOP8 |
| 10 | SOP8-to-DIP8 adapter | 150 mil | 2 | 5 | Desoldered SOP8 |
| 11 | DIP8 ZIF socket | 8-pin | 2 | 5 | Holds the adapters |
| 12 | Spare pogo pins/springs | for the probes | 1 set | 10 | Consumable |

### B. Rework

| # | Item | Spec / example | Qty | ~£ each | Why |
|---|---|---|---:|---:|---|
| 13 | Hot-air rework station | Quick 861DW or Atten ST-862D | 1 | 100–150 | WSON8 removal/reflow; pogo fallback |
| 14 | Fine-tipped soldering iron | if not already owned | 1 | 30–50 | Touch-ups, wick |
| 15 | Hot-air nozzles | small, assorted | 1 set | 10 | Concentrate heat on the chip |
| 16 | Kapton tape | 2 rolls | 2 | 5 | Shield nearby components |
| 17 | No-clean flux gel | 2 syringes | 2 | 8 | Reflow |
| 18 | Lead-free solder paste | SAC305 | 1 | 10 | Reflow; avoid leaded on resale boards |
| 19 | Solder wick | fine | 1 | 5 | Clean pads |
| 20 | Fine ESD tweezers | 2 | 2 | 8 | Place/remove chips |
| 21 | 99% IPA + swabs | 1 | 1 | 10 | Clean flux |
| 22 | Fume extractor | optional but recommended | 1 | 50–80 | Flux fumes |
| 23 | Board preheater | optional | 1 | 60–100 | Thick boards / stubborn WSON8 |
| 24 | Vacuum pen / chip placer | optional | 1 | 15 | Placing WSON8 |

### C. Measurement and safety

| # | Item | Spec / example | Qty | ~£ each | Why |
|---|---|---|---:|---:|---|
| 25 | Bench PSU | 0–30 V, 5 A, current limit | 1 | 70–100 | Power the board during in-circuit work; current limit protects it |
| 26 | Digital multimeter | basic | 1 | 25–40 | Measure VCC, continuity, shorts |
| 27 | Digital calipers | mm | 1 | 15 | Distinguish WSON8 5x6 from 8x6 |
| 28 | USB microscope or stereo microscope | 40–200× | 1 | 40–200 | Read markings, inspect joints and pogo alignment |
| 29 | ESD mat + wrist strap | 1 | 1 | 20 | Protect boards |
| 30 | ESD bags/boxes | 1 pack | 1 | 15 | Store boards and chips |
| 31 | Silicone work mat | 1 | 1 | 10 | Protect the board |

### D. Host and software

| # | Item | Spec / example | Qty | ~£ each | Why |
|---|---|---|---:|---:|---|
| 32 | Bench PC | Windows 10/11 or Linux; existing is fine | 1 | — | NeoProgrammer/AsProgrammer (Windows) or `flashrom` (Linux) |
| 33 | Powered USB hub | 1 | 1 | 15 | Programmer + microscope |
| 34 | Software | `flashrom`, NeoProgrammer/AsProgrammer, UEFITool, HxD/010 Editor | — | free | Dump/write/verify and patch |
| 35 | Dump archive | NAS/share + SHA-256 log | 1 | — | Original and patched images per unit |

**Software to install (2026):**

- `flashrom` **v1.7 (Feb 2026) or v1.8 (Aug 2026)** — Linux-native dump, write and
  verify. Prefer this for scripted, hash-checked workflows.
- **NeoProgrammer / AsProgrammer** — Windows GUI for the CH341A and T48/T76.
- **UEFITool** — inspect and patch UEFI volumes / NVRAM variables.
- **RCUnlocker** — Badcaps HP tool; reported to clear the 830 G5 password on the
  **16 MB** dump.
- **`libv/hp_bios_passwd_erase`** — open-source HP password clear; verified on
  650 G4 / 840 G5 (2018 `NvramActiveRegn`); newer models unsupported.
- **`dellpwn`** — Dell/HP SPI password recovery and clear (CVE-2026-40639); useful
  if Dell volume appears in the fleet.
- **`8FC8_Patcher`** — Dell 8FC8 manufacturing-mode patch; removes the BIOS password
  and keeps settings/BitLocker.

### E. Practice and verification

| # | Item | Spec / example | Qty | ~£ each | Why |
|---|---|---|---:|---:|---|
| 36 | Spare `W25Q256JV` WSON8 chips | 32 MB | 2 | 5 | Test programmer + 8x6 socket before touching a laptop |
| 37 | Spare `W25Q128JV` WSON8 chips | 16 MB | 2 | 4 | Test 5x6/8x6 |
| 38 | Spare `W25Q128JV` SOP8 chips | 16 MB | 2 | 3 | Test the SOIC8 clip |
| 39 | WSON8/SOP8 breakout boards | 2 each | 4 | 5 | Practice and signal probing |

### F. Optional / later

| # | Item | Spec / example | Qty | ~£ each | Why |
|---|---|---|---:|---:|---|
| 40 | RT809H programmer | 1 | 1 | 150–200 | Only if Lenovo EC/eMMC work grows |
| 41 | EC adapters/sockets | as needed | — | 50+ | Lenovo EC reflash; outsource the 9 ThinkPads |
| 42 | Second bench | second programmer + hot air | 1 | 300+ | Only if throughput needs two operators |

## 4. Phasing and budget

| Phase | What | Approx. cost | Gate |
|---|---|---:|---|
| **0 — Survey** | Microscope, multimeter, calipers, ESD; open one unit of each top 5 model and record chip marking/package/voltage | ~£100 | Do this **before** buying model-specific sockets |
| **1 — Starter bench** | T48 or CH341A, WSON8 pogo 8x6 + 5x6, SOIC8 clip, hot air, PSU, consumables, practice chips | ~£450–600 | Covers 830 G5, 820 G3, 650 G4, 650 G2, Fujitsu |
| **2 — Production hardening** | Second programmer, spare probes/sockets, stereo microscope, fume extractor, preheater | ~£300–500 | After ~10 units, when the weak points are known |
| **3 — Lenovo / EC** | RT809H + EC adapters, or outsource the 9 ThinkPads | ~£200–400 | Only if Lenovo volume grows |

**One capable bench: roughly £750–1,100.** Two benches: roughly £1,500–2,000.

## 5. What not to buy

- **A SOIC8-clip-only kit.** It misses the 830 G5/820 G3/650 G4 — the bulk of the fleet.
- **A 5 V-only programmer.** It can damage 3.3 V and 1.8 V chips.
- **A bag of ten cheap SOIC8 clips.** Buy two good ones (Pomona or equivalent).
- **BGA rework gear.** Not needed for the 25-series SPI flash.
- **Apple tools.** The Apple units are not locked and are out of scope.
- **A Dediprog SF600/SF100.** Overkill for 25-series SPI; the T48/CH341A is enough.
- **A new bench laptop.** Any existing Windows or Linux PC works.

## 6. Per-model plan

| Model | First action | Bench path |
|---|---|---|
| **830 G5 (91)** | Open one sacrificial unit; confirm **both** SPI chips (`25Q256JV` 32 MB main + `25Q128JV` 16 MB **EC BIOS**, U366/UC6) and VCC | Pogo 8x6 read twice on **both**; try **RCUnlocker on the 16 MB EC dump first** (2023–2025 Badcaps consensus; one user used a BeeProg+), then the 32 MB main; `schorschii/HPUnlocker.py` as a second patcher. Only if both fail, test the **Sure Start EC pin-2 short** on the 16 MB chip (sacrificial unit only). Hot air + socket if unstable |
| **820 G3 (28)** | Confirm package (5x6 vs 8x6) and locate the EC chip | Back up main + EC; pogo 5x6/8x6; hot air for stubborn units |
| **650 G4 (15)** | Confirm package and locate the EC chip | Same as 820 G3; `libv/hp_bios_passwd_erase` is the first patcher |
| **650 G2 (10)** | Confirm SOP8 vs WSON8 | SOIC8 clip if legs; WSON8 pogo if not |
| **Lenovo ThinkPad (9)** | One sacrificial unit; confirm the SVP is in the **MEC1663 EC**, not just the main flash | Check **Lenovo Auto-Patcher** support first: its README says up to **8th gen**, and our T14/X13 Gen 1 are **10th gen**. If unsupported, go straight to MEC1663/SVOD or outsource; do not assume the main-flash patch clears the EC copy |
| Lenovo ThinkStation P500 (1) | Survey; desktop board | SOP8/WSON8 depending on result |
| **Newer HP (G6/G8/G10/Fury G8/650 G9)** | Confirm voltage first — 1.8 V is possible | 1.8 V adapter mandatory; WSON8 pogo |
| **Fujitsu (2)** | Confirm SOP8 | SOIC8 clip |
| **ZBook 15 G3** | Survey | SOP8 or WSON8 depending on result |
| **ProBook 650 G5 (1)** | Confirm `W25Q256FV` main (WSON8 8x6) and the second EC chip; try the pin-short on the EC only on a sacrificial basis | Pogo 8x6 on main; 5x6/8x6 on EC; RCUnlocker/HPUnlocker; UEFITool |

**ProBook 650 G5 survey notes.** External repair listings and a VinaFix resource
describe the 650 G5 as a **dual-chip** board: `W25Q256FV` 32 MB main in **WSON8 8x6**,
plus a second flash (a 650 G5 resource lists `W25Q128BV`, 16 MB). Treat the second chip
as a **survey item**: confirm its marking, package and voltage before buying a socket.
There is **no confirmed 650 G5 pin-short report** yet. The closest laptop data point is
a **ProBook 650 G8** success (3 tries, cable plugged in), so the pin-short is a lead,
not a procedure. With only one 650 G5 in the fleet, do not build a special process
around it; use the 8x6/5x6 pogo kit you are buying for the 830 G5 and 650 G4.

## 7. Survey worksheet (fill one row per model)

| Model | Serial | Board PN | Chip refdes | Marking | Package | Size | VCC | Main/EC | Pogo read? | Dump1 SHA-256 | Dump2 SHA-256 | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
|  |  |  |  |  |  |  |  |  |  |  |  |  |
|  |  |  |  |  |  |  |  |  |  |  |  |  |
|  |  |  |  |  |  |  |  |  |  |  |  |  |
|  |  |  |  |  |  |  |  |  |  |  |  |  |

**Do not skip this.** The package and voltage determine whether a unit is a 10-minute
clip job, a 20-minute pogo job, or a 30-minute hot-air job. Guessing wrong buys the
wrong adapters and risks a bricked board.

## 8. Verification gate (before touching a customer unit)

- [ ] Programmer detects the chip ID.
- [ ] Two dumps match SHA-256 exactly.
- [ ] VCC measured at the chip and within the part's spec (1.8 V or 3.3 V).
- [ ] A practice chip can be written and verified.
- [ ] Pogo probe read is stable after a gentle wiggle; no adjacent-pad shorts.
- [ ] Board boots after reflash; serial / UUID / MAC / MSDM key unchanged.
- [ ] Original dump archived with its SHA-256.

## 9. Risks and gotchas

| Risk | Mitigation |
|---|---|
| WSON8 pogo probe slips / shorts adjacent pads | Magnification, light pressure, check alignment before power |
| Pogo pressure damages pads or nearby components | Support the board underneath; stop if the pads look worn; desolder instead |
| T48 + pogo reads unstable | Slow the SPI clock to ~8 MHz; keep Check-ID and Verify-after-Read on; compare checksums across reads |
| In-circuit bus contention (PCH/EC drives SPI) | If the ID or dumps are unstable, desolder |
| Back-powering the board through the programmer's VCC | Remove battery + AC; use a current-limited PSU or isolate VCC |
| Wrong voltage (1.8 V vs 3.3 V) | Read the marking; measure VCC; use the 1.8 V adapter |
| CH341A clone drives 5 V on the data lines | Measure VCC and logic levels; use the 3.3 V position; prefer the T48 for production |
| Write-protected chip (SRP/SWP) | Check with `flashrom --wp-status`; clear only if the part allows it |
| Two chips on one board (main + EC) | Back up both; do not assume the password is only in the main flash |
| HP Sure Start / Boot Guard | Patch NVRAM only; never touch ME/descriptor; have a recovery plan |
| Sure Start EC pin-short bricks the board | Sacrificial unit only; boardview + pin 1 confirmed; current-limited PSU; expect **HP Endpoint Security Controller Problem** or a 15 s splash timer even on success |
| RCUnlocker is BIOS-revision specific | Bios-Fix 2021 reported reflashing **both** the 830 G5 `25Q256JV` and `25Q128JV` with the unlock tool and the password remained. Keep the original dumps; try more than one patcher; do not assume one success generalises |
| EC rework is BGA/QFN | Practice first; at 9 Lenovo ThinkPads, outsourcing is cheaper |
| Counterfeit clips/adapters | Buy from reputable suppliers; validate with practice chips |
| ESD damage | Mat, wrist strap, grounded iron |

## 10. Recommendation

1. **Approve Phase 0 and Phase 1 first (~£550–700).** Do not buy a clip-only kit.
2. **Buy WSON8-first:** pogo probes in 8x6 and 5x6, plus hot air and sockets.
3. **Start with one sacrificial 830 G5 and one 820 G3.** They cover the two dominant
   WSON8 footprints and the main+EC case.
4. **Fill the survey worksheet** before buying model-specific sockets for the 650 G2,
   newer HP and Lenovo.
5. **Revisit Phase 2 after ~10 units.** Add a second bench only when the throughput
   target is known.
6. **Outsource Lenovo EC work** unless the volume grows beyond the current 9 ThinkPads.

## 11. Open questions

- Exact package/voltage for the 650 G2, 650 G5, ZBook 15 G3 and newer HP (G6/G8/G10,
  Fury G8, 650 G9).
- Which 830 G5 chip actually holds the password on our revisions: the **16 MB**, the
  **32 MB**, or both? Badcaps says the 16 MB; our 32 MB dump analysis found
  password-related strings. Test both.
- Does the **HP Sure Start EC pin-short** from Reddit work on the 830 G5/G6? See
  `23-reddit-sweep.md` and `24-technique-deep-dive.md` §1. Test only on a sacrificial
  unit with a current-limited PSU.
- Does RCUnlocker clear the 830 G5 on our BIOS revisions, or was the Badcaps success a
  different revision? See `24-technique-deep-dive.md` §2 for the test matrix.
- Does the **16 MB EC dump** contain `HpPassphraseStructureVariable` (so `dellpwn` can
  deactivate it) or the 2018 `NvramActiveRegn` layout (so `hp_erase` can clear it)? Our
  32 MB dump matches neither; see `25-github-sweep-and-dump-tests.md`.
- Is in-circuit WSON8 reading reliable on each model, or does every unit need hot air?
- Which newer HP models use 1.8 V flash?
- Lenovo T14/X13: does the Auto-Patcher support 10th-gen machines at all, and if so,
  does it clear the SVP or leave the MEC1663 EC copy? See `24-technique-deep-dive.md`
  §3.
- Throughput target (units/day) to decide one bench vs two.
