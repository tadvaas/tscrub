# 06 — Fleet plan (from the dashboard DB)

Pulled **2026-10-03** from the production DB for `tom@tfix.co.uk` (user id 6).
Source: `reports` table, `report_type='diagnostics'`, deduped by serial.

> ## ⚠️ UPDATE 2026-10-23 — the 830 G5 has two SPI chips
>
> U365 is the 32 MB main BIOS (`25Q256JV`). **U366/UC6 is the 16 MB EC BIOS**
> (`25Q128JV` / `25Q128JVSN`), and the HP password store is in the EC firmware.
> Badcaps 2023–2025 posts consistently run **RCUnlocker on the 16 MB EC dump**; one
> user read it with a BeeProg+. A 2021 Bios-Fix report reflashed both chips and the
> password remained, so treat RCUnlocker as BIOS-revision specific and test both.
> This changes the 830 G5 effort from "one 32 MB image per revision" to **"dump both
> chips, patch the EC dump first, verify identity"**. See `24-technique-deep-dive.md`,
> `22-2026-trends.md` and `10-hp-830g5-spi-sop.md`.

> ## ⚠️ UPDATE 2026-10-10 — the revision spread changes this plan
>
> Re-measured against the current dashboard (**177 enrolled, 165 locked**). The fleet has
> grown, and crucially it is **far more BIOS-revision-fragmented than this doc assumed**:
>
> | Model | Locked | Distinct BIOS revisions | Largest single revision |
> |---|---|---|---|
> | EliteBook **830 G5** | **91** | **14** | 38 × `Q78 01.04.00` (42%) |
> | EliteBook 820 G3 | 28 | 9 | 8 × `01.13` (29%) |
> | ProBook 650 G4 | 15 | 5 | 10 × `01.02.05` (67%) |
> | ProBook 650 G2 | 10 | 4 | 4 × `01.13` (40%) |
> | Lenovo (20WL/20U4/20T3) | 10 | ~1 per unit | — |
>
> **830 G5 revision spread:** `01.04.00` ×38 · `01.03.00` ×22 · `01.06.00` ×8 ·
> `01.01.07` ×5 · `01.07.00` ×3 · `01.08.01` ×3 · `01.28.00`/`01.20.00`/`01.31.00`/
> `01.26.00` ×2 each · `01.29.01`/`01.24.00`/`01.25.00`/`01.27.00` ×1 each.
>
> **What this means**
>
> 1. **"One patch covers the G5 fleet" is wrong.** An NVRAM-only patch reaches 42% of the
>    91 units; three patches reach 75%; the remaining 23 units are spread over **11 more
>    revisions** (1–3 units each). NVRAM patching is up to **14 development cycles**, not
>    one. The "priority 1: one proven procedure covers 33 units" line below is superseded.
> 2. **The existing dump** (`hp elitebook 830 g5 main.bin`, `Q78 01.01.07`) covers **5 of
>    91 units — 5.5%**. That is exactly the mismatch `11` flagged.
> 3. The revision-independent alternative is **vendor clean image + identity re-inject**
>    (`04` §3 option 1, `10` §3 option 3): one procedure for every revision, at the cost of
>    rewriting signed code (Sure Start risk) and re-injecting HP_MUD — and the
>    ME/descriptor region must match the board. **Prototype this before committing to 14
>    NVRAM patches.**
> 4. It materially strengthens the case for the software routes first: the client's
>    per-serial passwords (`research/Siemens/bios-passwords-needed.csv`) cost **zero** bench
>    time, and **HP SMC** trades 91 SPI jobs for one support request.
>
> Caveat: the two figures that still gate a firm estimate are the **package** (SOP8 vs
> WSON8 — clip vs hot-air per unit) and whether the board reads **in-circuit** unpowered.
> Both are physical and must be measured on the sacrificial unit.

## Headline numbers

| Metric | Value |
|---|---|
| Distinct devices | **65** |
| LOCKED | **61** |
| UNLOCKED | 4 (already free — straight to grading/sanitisation) |
| Lock method | 61 × `SMBIOS Type 24 (Administrator Password Status)` |

Every lock is the **same class**: a lost BIOS **setup/admin password**. No power-on
passwords, no HDD passwords, no Computrace/Block-SID locks in this fleet (those would
show different methods). That means the fix is uniform: **recover or clear the admin
password**, and the highest-value target is clear.

## Model distribution

| Model | Units | Locked | Recommended path |
|---|---|---|---|
| **HP EliteBook 830 G5** | **35** | 33 | **HP hash generator (primary)** → vendor unlock → SPI |
| HP EliteBook 820 G3 | 9 | 8 | HP hash generator |
| HP ProBook 650 G2 | 4 | 4 | HP hash generator |
| HP ProBook 650 G4 | 2 | 2 | HP hash generator |
| HP ZBook 15 G3 | 1 | 1 | HP hash generator |
| HP ZBook Fury 15.6 G8 | 2 | 2 | HP hash generator (verify) |
| HP EliteBook 650 G9 | 1 | 1 | HP hash generator (verify) |
| HP EliteBook 830 G6 | 1 | 1 | HP hash generator (verify) |
| HP EliteBook 830 G8 | 1 | 1 | **verify hash is shown; else SPI/vendor** |
| HP EliteBook 830 G10 | 1 | 1 | **verify hash is shown; else SPI/vendor** |
| Lenovo (MTM 20WL) | 2 | 2 | Service mode → EEPROM/SPI |
| Lenovo (MTM 20U4) | 2 | 2 | Service mode → EEPROM/SPI |
| Lenovo (MTM 20T3) | 1 | 1 | Service mode → EEPROM/SPI |
| Lenovo (MTM 20U4) | 1 | 0 | unlocked |
| Fujitsu LIFEBOOK E736 | 1 | 1 | SPI |
| Fujitsu Siemens S6410 | 1 | 1 | SPI |

## Priority plan

1. **HP EliteBook 830 G5 (33 locked)** — do this first. One proven procedure covers
   33 units; this is where the money is. See the flow below.
2. **Other HP EliteBook/ProBook/ZBook (820 G3, 650 G2/G4, ZBook 15 G3, 830 G6, 650 G9,
   Fury G8)** — same HP hash path. The 820 G3 / ProBook / ZBook 15 G3 are the same
   generation as the 830 G5 and will behave the same.
3. **HP G8/G10 (2 units)** — newer HP BIOS may no longer display the unlock hash after
   3 fails. Verify on one unit; if the hash is not shown, route to HP's owner-unlock
   program or SPI.
4. **Lenovo ThinkPads (9 units, MTM 20T3 / 20WL / 20U4)** — no master password; the
   2020 T14/X13 store the SVP in the **MEC1663 EC**.
   Service mode if the model exposes it, otherwise EEPROM/SPI reflash.
5. **Fujitsu (2 units)** — SPI reflash.

## HP EliteBook 830 G5 — the batch flow

1. Power on; at the password prompt enter a wrong password **three times**.
2. The BIOS shows a code in the form `i 78634292` (a "disabled system" hash).
3. Enter that code into a HP master-password generator (bios-pw.org HP tab) →
   master password.
4. Enter the generated master password to get into setup.
5. In **Security → Set Administrator Password**, set (or clear) the admin password;
   save and exit.
6. Reboot once to confirm no password prompt, then run the normal tScrub grade +
   erasure flow.

### Batch optimisations
- Do steps 1–5 at a bench with a fixed keyboard + note template, then hand the unit to
  the erasure line.
- Record **serial → generated password → cleared?** per unit so a failed generation can
  be retried or escalated (hash typing is error-prone; type the hash slowly).

### When the hash path fails
- Wrong hash (typo) → re-read carefully; hashes are case/format sensitive.
- Generator returns "not found" → escalate to HP owner-unlock (needs service tag +
  proof of ownership) or SPI reflash.

## Lenovo MTM note

The `system` field for the Lenovo units is the **machine-type-model** string (e.g.
`20T3S68M12`), not a marketing name. Look the MTM up for the exact board/service-mode
details — `20T3` = ThinkPad T14 Gen 1, `20WL` = ThinkPad P-series, `20U4` = ThinkPad
X13 Gen 1 (verify each against the unit's serial before opening).

## Recording outcomes

Propose logging per-unit outcome alongside the erasure report so the whole fleet is
auditable end-to-end (locked → method → unlocked → sanitised). Until a dashboard field
exists, a simple sheet keyed by serial is enough.

Related docs: [`02-non-invasive-methods.md`](02-non-invasive-methods.md) (HP hash +
generator), [`04-spi-flash-reflash.md`](04-spi-flash-reflash.md) (Lenovo/Fujitsu),
[`05-vendor-matrix.md`](05-vendor-matrix.md).
