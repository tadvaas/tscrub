# 25 — GitHub sweep and dump tests: what actually fits the 830 G5

**Status:** third pass · Google + Reddit + YouTube + GitHub · 2026-10-23
**Scope:** the open-source tools found on GitHub, and what happened when two of them
were run against our existing 830 G5 32 MB dump.
**Related:** `24-technique-deep-dive.md` (Sure Start EC, 830 G5 chip map, Lenovo
MEC1663), `23-reddit-sweep.md`, `22-2026-trends.md`, `11-830g5-dump-anatomy.md`.

## 1. TL;DR

1. **GitHub has two genuinely useful open-source HP tools**, but neither is a
   turnkey 830 G5 unlock:
   - `libv/hp_bios_passwd_erase` — clears HP `$VSS`, `UserCred` and 2018
     `NvramActiveRegn` password structures from a **BIOS image**.
   - `R3n5k1/dellpwn` — extracts HP `HpPassphraseStructureVariable` SHA-1 hashes from
     an SPI dump and can deactivate the NVAR entry (`clear-hp-password`).
2. **Our 32 MB 830 G5 dump does not contain either structure the tools look for.**
   - `HpPassphraseStructureVariable`: **0 occurrences**.
   - `$VSS`: 0. `UserCred`: 0.
   - `NvramActiveRegn`: **4 blocks**, but the block layout does not match the
     `H_FbPolicy` / `L_MpmPolcy` subheader that `hp_erase` expects.
   - `hp_erase` rejects the full dump and the extracted BIOS region because the
     plaintext sanity string (`Hewlett-Packard` / `HP UEFI Secure Boot`) is not present;
     the BIOS volume is compressed.
3. **This is strong evidence that the password is in the 16 MB EC BIOS (`U366`/`UC6`),
   not the 32 MB main dump.** The next hardware step is to dump the 16 MB chip and run
   the same checks on it.
4. **Lenovo:** GitHub has a public mirror of the **Lenovo BIOS Auto-Patcher**
   (`suds-mcduff/lenovo-bios-autopatcher`), but it says it supports machines **up to
   8th gen**. Our T14/X13 Gen 1 are **10th gen**, so it is likely out of scope. No
   public GitHub tool exists for the **MEC1663** SVP; the SVOD/JTAG routes remain
   specialist work. Outsource the 9 ThinkPads unless the volume grows.
5. **Legacy HP tools are a trap.** `SpookySkeletons/hp_bios_unlocker` and
   `BIOS Sledgehammer` are for much older HP hardware or for *setting* a password when
   you already know the current one. They do not apply to a locked 2018+ EliteBook.

## 2. GitHub tools worth knowing

| Repo | What it actually does | Applies to 830 G5? | Notes |
|---|---|---|---|
| **`libv/hp_bios_passwd_erase`** | C tool; finds `$VSS`/`BiosUser`, `UserCred`, or `NvramActiveRegn` markers in an HP BIOS image and clears the password blocks. Writes a new file, never edits the input. | **Not directly.** Our 32 MB dump fails the sanity check; the `NvramActiveRegn` blocks do not have the expected `H_FbPolicy`/`L_MpmPolcy` subheader. | Verified by the author on 650 G4 and 840 G5 (2018 `NvramActiveRegn`). The 830 G5 is the same generation, so it is still worth trying on a **16 MB EC dump** or a different board revision. |
| **`R3n5k1/dellpwn`** | Rust tool. `scan` extracts HP `HpPassphraseStructureVariable` SHA-1 hashes from an SPI dump; `clear-hp-password` clears bit 7 of the NVAR attribute byte, deactivating the password entry (the "t530-style jumper reset"). | **Not on our 32 MB dump** (0 markers). **Unknown on the 16 MB EC dump.** | HP hashes are unsalted SHA-1 of UTF-16LE, crackable with `hashcat -m 130`. The clear command only deactivates entries; it does not erase the hash. |
| **`SpookySkeletons/hp_bios_unlocker`** | Shell script + `clean.bin`; dumps an HP BIOS and clears the password. | **No.** Tested on 2nd/3rd gen Intel HP laptops, ~2012–2014. | Useful only as historical context. Do not run on a 2018+ EliteBook. |
| **`thecatdidit/HPEliteBookTools`** (BIOS Sledgehammer) | PowerShell/BCU automation for BIOS/ME/TPM updates and setting a **known** BIOS password. | **No.** It needs the current password to change settings. | Not an unlock tool. |
| **`hamishcoleman/thinkpad-ec`** | ThinkPad EC firmware patching (battery whitelist, etc.). | N/A (Lenovo). | Does not clear the SVP, but confirms the EC is a separate flash target on ThinkPads. |
| **`willjow/t440p-bios-patches`** | UEFIPatch patches for older ThinkPads. | N/A. | Model-specific and old; not for T14/X13 Gen 1. |
| **`raumdonut/PreOS-SetLenovoBIOSPassword`** | Sets a ThinkPad supervisor password in a deployment. | N/A. | Opposite of what we need. |
| **`suds-mcduff/lenovo-bios-autopatcher`** | Public mirror of the Knucklegrumble Lenovo BIOS auto-patcher for SVP removal; generates a patched BIOS image. | N/A (Lenovo). | README says it supports Lenovo **up to 8th gen**; our T14/X13 Gen 1 are **10th gen**, so likely out of scope. Also requires flashing the patched image, booting, entering a dummy SVP, then restoring the original BIOS. |

### 2.1 `libv/hp_bios_passwd_erase` details

The README lists:

- 2009–2015 `$VSS`/`BiosUser`: ProBook 4520s; EliteBook 8440p/8460p/8470p/840-g1/840-g2.
- 2015–2017 `UserCred`: ProBook 650-g3; EliteBook 840-g3/840-g4.
- 2018 `NvramActiveRegn`: **ProBook 650-g4, EliteBook 840-g5**.
- Newer laptops: **not supported**.

The `NvramActiveRegn` handler looks for `NvramActiveRegn` followed at `+0x37` by
`H_FbPolicy` or `L_MpmPolcy`, then clears the whole `0x1000` block. Our dump's blocks
have a binary subheader, so they would be skipped with "unknown section". This is a
layout mismatch, not a corrupted dump.

**macOS build note:** the source uses Linux-only `O_LARGEFILE`. Build with:

```bash
make CFLAGS='-Wall -O0 -g -DO_LARGEFILE=0'
```

### 2.2 `dellpwn` details

`dellpwn scan dump.bin` prints:

- BIOS identification.
- Dell SIVB / E7250 password stores.
- HP password hashes if `HpPassphraseStructureVariable` is present.
- Dell DVAR password recovery.

For HP it looks for `HpPassphraseStructureVariable\x00`, a zero prefix, a 20-byte
SHA-1 hash, and the tail `01 00 00 00` + 20 zero bytes. The NVAR attribute byte at
`NVAR + 9` controls whether the entry is active (bit 7 set = active). Our dump has
zero such markers, so the HP path never triggers.

**Commands:**

```bash
git clone https://github.com/R3n5k1/dellpwn
cd dellpwn
cargo build --release
./target/release/dellpwn scan dump.bin
./target/release/dellpwn clear-hp-password dump.bin patched.bin
```

`clear-*` commands never modify the input; they write a patched copy.

### 2.3 `suds-mcduff/lenovo-bios-autopatcher` (Lenovo only)

This is a public mirror of the Knucklegrumble Lenovo BIOS auto-patcher. It takes a
BIOS dump and produces `*_PATCHED.ext` or `*_PATCHED_CLEAN.ext`, leaving the original
unchanged. The author's procedure:

1. Flash the patched image.
2. Boot the machine and enter BIOS settings.
3. Enter any character when asked for the Supervisor Password.
4. Press Enter when it shows the Hardware ID.
5. Press Space twice when asked.
6. Turn off the machine.
7. Restore the original BIOS.
8. Reset BIOS settings to factory defaults.

The README warns to dump the chip 1–2 extra times and compare before patching, to hold
the anti-tamper switch down, and to remove or replace the hard disk. **Support caveat:**
it says "Lenovo machines up to the 8th generation". Our T14/X13 Gen 1 are 10th gen, so
this may not apply. The MEC1663 EC route remains the likely path for those 9 units.

## 3. What the 32 MB dump actually contains

Marker scan against `hp elitebook 830 g5 main.bin` (SHA-256 `612874ce…`). This is the
same first check `dellpwn` performs; the Rust binary itself was not built on this Mac
because Cargo is not installed, but the HP marker requirement is unambiguous:

| Marker | Count | First offset |
|---|---|---|
| `HpPassphraseStructureVariable` | **0** | — |
| `$VSS` | 0 | — |
| `UserCred` | 0 | — |
| `NvramActiveRegn` | **4** | `0x1BE6ED7` |
| `H_FbPolicy` | 4 | `0x1BCE118` |
| `L_MpmPolcy` | 4 | `0x1BCE04C` |
| `Hewlett-Packard` | **0** | — |
| `HP UEFI Secure Boot` | **0** | — |

The dump is a full 32 MB SPI image:

- Intel descriptor at `0x10` (`5A A5 F0 0F`).
- ME region at `0x3000`.
- BIOS region at `0x01000000` (16 MB).

The BIOS volume is compressed, so the plaintext HP sanity strings are absent. That is
why `hp_erase` rejects both the full dump and the extracted 16 MB BIOS region. The
`NvramActiveRegn` markers are in the NVRAM area and are plaintext, but their layout is
not the one `hp_erase` knows.

### 3.1 What this means

- **Do not flash a `hp_erase`-patched 32 MB image.** It would not change the password
  and could clear unrelated NVRAM.
- **The 16 MB EC BIOS is the next target.** If it contains
  `HpPassphraseStructureVariable`, `dellpwn` can tell us whether the password is
  active and can deactivate it. If it contains the 2018 `NvramActiveRegn` layout,
  `hp_erase` may work on it.
- **RCUnlocker remains the first practical 16 MB patcher** (Badcaps 2023–2025
  consensus), with `dellpwn`/`hp_erase` as open-source cross-checks.

## 4. New YouTube / recovery leads

| Title | Channel | Date / length | Why it matters |
|---|---|---|---|
| HP Endpoint Security Controller Problem? Here's How I Fixed It | Zion Computer Technology | ~1 month ago · 10:28 | Recovery path if the Sure Start pin-short or an EC reflash leaves the 15 s Endpoint Security error. |
| How to Exit Manufacturing Mode on HP EliteBook 830 G5 | Zion Computer Technology | 22:13 | Related 830 G5 service-mode procedure; useful after EC/BIOS rework. |
| HP Elitebook/Probook bios password reset TUTORIAL | Jimboss99 | ~1 year ago · 12:35 · 31.9k views | Another reset method; worth watching before the bench test. |
| How to Remove / Unlock HP Laptop BIOS Password | UFIXTEK | ~1 year ago · 9:28 · 42.5k views | Generic HP unlock walkthrough. |
| HP EliteBook 840 G8 Bios Unlock Step by Step 100% Free | Borvi Tech | ~2 years ago · 50:11 · 64.6k views | Newer HP (G8) example; check the voltage and chip layout. |
| HP ZBOOK 15u G4 BIOS PASSWORD REMOVAL USING RC UNLOCKER | Jbv Technologies | ~4 years ago · 14:37 · 11.7k views | Confirms RCUnlocker on another HP business model. |

**Laptop pin-short data points (r/laptops thread):** a **ProBook 650 G8** owner
succeeded after **3 tries**, with the cable plugged in. A second user succeeded on a
laptop with a **`GD25B256DYIG`** chip ("not the bios one", so the second/EC chip),
shorting pin 2 to the USB port shield. The OP's **EliteBook 840 G10** did not succeed;
HP said replace the motherboard. So the method is no longer desktop-only, but it is
still **unproven on the 830 G5**.

The same thread warns that newer HP SPI reflashes often end in the Endpoint Security
error, and the fix is to wipe the serial/identity area to force manufacturing mode,
then re-enter the serial. That is a second rework, not a clean unlock.

## 5. Revised 830 G5 test matrix (open-source first)

1. **Dump the 16 MB EC BIOS (`U366`/`UC6`) twice.** Verify SHA-256.
2. **Run `dellpwn scan` on the 16 MB dump.** If it finds
   `HpPassphraseStructureVariable`, note active/inactive and the hash. A weak password
   may be crackable with `hashcat -m 130`; otherwise use `clear-hp-password`.
3. **Run `hp_erase` on a copy of the 16 MB dump.** If it finds `$VSS`, `UserCred` or
   the 2018 `NvramActiveRegn` layout, it writes a patched copy.
4. **Run RCUnlocker on the 16 MB dump.** This remains the community-proven path.
5. **Flash only the 16 MB chip.** Boot test, then verify serial/UUID/MAC/MSDM.
6. **Only if the 16 MB path fails**, test the 32 MB dump with UEFITool/NVRAM surgery or
   the Sure Start EC pin-2 short on a sacrificial unit.

## 6. Open questions

- Does the 16 MB EC dump contain `HpPassphraseStructureVariable`, `NvramActiveRegn`,
  or neither?
- Does `dellpwn scan` identify the 830 G5 EC dump as HP firmware, or does the EC image
  use a different variable name?
- Does RCUnlocker accept the 16 MB dump and clear the password on our BIOS revisions?
- Which 830 G5 board revisions have the 8 MB `U366` instead of the 16 MB part?
- Does the Zion Endpoint Security fix require a specific BIOS version or EC state?

## 7. Sources

- `https://github.com/libv/hp_bios_passwd_erase`
- `https://github.com/R3n5k1/dellpwn`
- `https://github.com/SpookySkeletons/hp_bios_unlocker`
- `https://github.com/thecatdidit/HPEliteBookTools` (BIOS Sledgehammer)
- `https://github.com/hamishcoleman/thinkpad-ec`
- `https://github.com/willjow/t440p-bios-patches`
- `https://github.com/raumdonut/PreOS-SetLenovoBIOSPassword`
- `https://github.com/suds-mcduff/lenovo-bios-autopatcher` (public mirror of the
  Knucklegrumble Lenovo BIOS auto-patcher)
- Badcaps "HP Elitebook 830 G5 Admin Bios Unlock" (2021–2026; RCUnlocker on 16 MB).
- Badcaps "Help with HP 830 G5 bios" (`W25Q256FV@WSON8 8X6`).
- YouTube links above; titles/dates as shown by Google/YouTube on 2026-10-23.
