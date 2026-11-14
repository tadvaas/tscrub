# 09 — Running an in-house unlock service (what the eBay sellers do)

The "BIOS unlock" services on eBay/eBay-adjacent marketplaces are almost never doing
anything vendor-blessed. They fall into three buckets, and once you see which bucket
your fleet is in, the path is obvious.

## What unlock services actually do

| # | Method | Applies to | What it is |
|---|---|---|---|
| 1 | **Hash computation** | Dell (service tag), Fujitsu-Siemens, old HP Insyde i-code | Run reverse-engineered algorithms locally, sell the resulting password. Pure software. |
| 2 | **SPI dump → patch → reflash** | No-hash models: Lenovo SVP, newer HP **incl. your 830 G5**, Fujitsu UEFI | Read the BIOS flash chip, clear the password variable (or write a clean image while re-injecting serial/UUID/MAC), write it back. Hardware + skill. |
| 3 | **EC reflash / chip swap** | ThinkPad SVP stored in the EC | Same idea, but the EC/KBC chip carries the password. |

The "**send me your BIOS dump and £15**" sellers are method 2: *you* dump, *they* patch,
*you* reflash — or you mail the board in. The value they hold is a **library of
patched/clean images per model** and knowing *which bytes* to clear. That is learnable.

## Map to our fleet

- **830 G5 (91) + G6/G8/G10/650 G9/Fury G8 + Fujitsu E736** → method 2 (SPI). On the
  830 G5, the 16 MB chip is the **EC BIOS**; try RCUnlocker on that dump first.
- **820 G3 / ProBook 650 G2-G4 / ZBook 15 G3** → maybe method 1 (i-code, [VERIFY]),
  otherwise SPI.
- **Lenovo (9)** → method 2 or 3; the 2020 T14/X13 store the SVP in the **MEC1663 EC**.

Net: the whole fleet is solved by building **one** capability — the SPI bench — which is
exactly what the sellers run. No hash, no vendor, no bureaucracy.

## Bootstrap trick: buy one unlock, diff the patch

For a new model, the cheapest way to learn the patch is:

1. Dump the unit's flash yourself (CH341A + `flashrom`).
2. Pay for **one** unlock from a service; they return a patched dump.
3. `diff` their patched dump against your original → the exact bytes changed.
4. Verify the diff is minimal and that serial / UUID / MAC / ME region are preserved.
5. Replicate that patch across the rest of the model **for free**.

This converts a per-unit fee into a one-time, model-level engineering cost.

## In-house bench build (primary plan)

- **Tool:** CH341A/T48 + **WSON8 pogo probe (8x6/5x6)** or SOIC8 clip + 1.8 V
  adapter, `flashrom` on any Linux box (a tScrub-derived bench image works — see §5
  of `07`). The fleet is WSON8-first; see `21-hardware-bom.md` for the full BOM.
- **Per model (once, on a sacrificial unit):** dump twice + `sha256` match → patch
  (UEFITool variable clear, known offset, or the diff-learned patch) → reflash +
  verify → boot test → confirm no password and identity intact.
- **Keep a library:** `original/` and `patched/` dumps per model + BIOS revision, so the
  next unit is just dump → apply known patch → reflash.

## Plan

| Tier | Path | Notes |
|---|---|---|
| **Primary** | In-house SPI bench | Non-bureaucratic, scales to 165 locked units; ~£450–600 starter bench (see `21`). |
| **Fallback / future** | HP SMC (vendor) | Needs proof-of-ownership paperwork; use if a unit resists SPI or HP opens a bulk programme. |
| **Validate** | One 820 G3 i-code test | If the 2016-era units still show the hash, those ~16 units are free (method 1). |

Next concrete step: build the bench and develop the **830 G5 patch** once (optionally
seeded by the buy-one-diff-one trick), then SOP the rest.
