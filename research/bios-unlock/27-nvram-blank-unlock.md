# 27 — NVRAM-blank unlock: the 830 G5 "main BIOS only" method decoded

**Date:** 2026-11-14
**Source material:** F1 COMPUTER YouTube video `jjEv_JWXWNE` ("HP BIOS UNLOCK ||
HP ELITEBOOK 830 G5 BIOS ADMIN PASSWORD RESET", Feb 2024, 11.5k views) and its
Google Drive file `hp elitebook 830 g5 main (1).bin` (32 MiB, in `BINs/`).

## The video's method, decoded

**What the video actually does (procedure observed):** FlyPro v4.56 auto-detects
the W25Q256JV and reads it; the dump is opened in HxD; a second file
`Region_BIOS_BIOS_region.rgn` (16 MiB) is copied whole and pasted-inserted over
**0x1000000–0x1FFFFFFF** of the main dump; chip erased, blank-checked, edited
file programmed. That is the repair-shop **"keep your ME, swap the BIOS region"**
technique:

- `0x000000–0xFFFFFF` = descriptor + ME (+ padding) — **kept from the unit**,
  so ME identity/AMT survive.
- `0x1000000–0x1FFFFFFF` = **BIOS region** (all 7 FVs live here,
  `0x1624000…0x1FFE000`) — replaced by a donor's clean region.

The password removal inside the donor region is a plain **NVRAM variable-store
wipe** (no magic patch):

- The provided file is a *different unit's* BIOS. A raw byte diff against our
  locked `830_G5.bin` shows **40.4 % of bytes differ** (13,565,537 bytes across
  223,970 regions), so a plain diff can never isolate the password. The two
  images share the same FV map though (same offsets, sizes, GUIDs).
- Parsing the UEFI structures (via `uefi_firmware`, installed in `.venv`) finds
  the authenticated variable store (signature `aaf32c78-947b-439a-a180-2e144ec37792`):

  | Store offset (flash) | Size | Locked dump | Video's file |
  |---|---|---|---|
  | `0x01624048` | `0x10FB8` | blank | blank |
  | **`0x01AF6048`** | **`0x25FB8`** | **76,945 non-FF bytes: `Settings` (2,053 B), `PhysicalPresence`, `PhysicalPresenceFlags`** | **28 non-FF bytes (header only), rest `FF`** |
  | `0x01CA5860` | `0x25FB8` | pointer record only | pointer record only |
  | `0x01F01860` | `0x25FB8` | pointer record only | pointer record only |

- **The `Settings` variable is the 2,053-byte AMI/HP setup blob that carries the
  BIOS admin password hash.** Wiping the whole store (not editing inside it)
  forces the BIOS into a clean NVRAM reinitialisation on first boot — the same
  path a factory/recovery reset takes — which clears the password.
- This also explains the top video comment (`@nickmando2329`): their own
  *partial* hex edit got "restored by the EC"; the full store blank in the
  provided file stuck. For this model the password record is in the **main BIOS
  NVRAM**, and a complete store erase wins.

## Patch we produced

Three artifacts in `BINs/`:

1. `Region_BIOS_BIOS_region.rgn` — **16 MiB donor BIOS region** extracted from
   the video's Drive file (`0x1000000–0x1FFFFFFF`). Its store body has **0
   non-FF bytes** — verified factory-blank. This is the exact equivalent of the
   video's paste source.
2. `830_G5.video-splice.bin` — our locked dump with `0x1000000–0x1FFFFFFF`
   replaced by the donor region: **byte-for-byte what the video procedure
   produces** (24.92 % of bytes changed; bottom 16 MB = ours, untouched).
3. `830_G5.unlocked.bin` — the minimal equivalent (below):

`BINs/830_G5.unlocked.bin` (33,554,432 bytes) = our own locked dump with the
store body blanked:

- Offset `0x01AF6048`, keep the 28-byte store header
  (`78 2c f3 aa … b8 5f 02 00 5a fe …`), fill `0x01AF6064 … 0x01B1C000` with `FF`.
- 76,917 bytes changed, all inside the store; the patched store region is
  **byte-identical** to the video's unlocked file.
- **Identity preserved**: serial / UUID / MAC / Windows key live in the DMI /
  ME / GBE areas, not in this store. Nothing else in the image was touched.

## Which file to flash, in order of preference

1. **`830_G5.unlocked.bin`** — smallest change (76,917 bytes), keeps our exact
   BIOS code version → least risk of BIOS/EC version mismatch.
2. If it does not clear, or the EC restores the password: **
   `830_G5.video-splice.bin`** — the video's exact recipe, confirmed working by
   two commenters. Downside: donor BIOS code version + donor factory NVRAM.
3. Last resort: the raw video file itself (adopts donor ME too — restore serial/
   UUID/MAC afterwards).

## The password variable: `UserCred`

Full variable-store parse of the locked dump (241 entries) names the culprit:

- **`UserCred`** — 3,536 B, GUID `f66687ff-8cf3-4a19-b4ac-f5f0…`, attrs 0x07
  (NV+BS+RT, not authenticated). Data starts with UTF-16 **`AdminPW`** — it IS
  the admin-password credential blob. (Earlier greps for ASCII `UserCred` in the
  raw image found 0 because the name is UTF-16.)
- **`HP_MUD`** — 390 B, GUID `20ab3eb1-3c8f-4a4c-b87e-3724…`: UTF-16 serial
  `5CG8528HKL` + product no. `4DU06EC#ABU` — the BIOS copy of the Machine
  Unique Data that the EC compares at POST.
- Also identity/factory: `FactoryConfig` (500 B), `BuildId` (112 B),
  `ElectronicLabel`, `BatteryCTNumber`, `FactoryConfigFlags`, `HP_TAGS`.

**Why Restore beeped:** the full-store wipe deleted `HP_MUD` (and the factory
variables) along with `UserCred`; the EC→BIOS MUD recovery then fails and the
screen loops.

**V2 surgical patch (recommended):** delete ONLY `UserCred` — flip its state
byte (entry at `0x1B003CC`, state at `+2`) from `0x3F` (added) to `0xFD`
(deleted). One byte changes. Identity variables stay `0x3F`, so POST finds
EC MUD == BIOS MUD → no recovery screen; the variable driver treats `UserCred`
as gone → no password. This mirrors what "clear password" in Setup does.

`BINs/CG8528HKL/W25Q256FV@SOIC8-5CG8528HKL-UNLOCKED-V2.BIN` — 32 MiB, 1 byte
changed vs the original read, all 241 entries still walkable, ME untouched.

**Fleet note (unit 2, 5CG8403V0S):** same procedure, `UserCred` sits at a
different offset (`0x1B0058C`) — always locate it by walking the store for the
UTF-16 name rather than hardcoding. 289 entries, exactly 1 byte changed
(`0x1B0058E` → `0xFD`), MUD intact. Unit 1's MUD loop was also resolved by a
BIOS update to latest (update did not re-add the password), so BIOS-update is
the fallback if the MUD screen ever persists.

## Confirmed result (2026-11-14, unit 5CG8528HKL)

`830_G5.unlocked.bin` was programmed (Erase → Blank Check → Program → Verify
via T48/Xgpro, WSON8 pogo). **Password removed — method confirmed working.**

Post-flash first boot shows HP's **"BIOS Identity Data Needs to Be Recovered"**
screen: *"The Embedded Controller MUD data does not match the BIOS MUD data"*
with Restore / Continue.

- **MUD** = Machine Unique Data (serial, product no., UUID, feature byte, build
  ID, SKU) held in TWO copies: EC and BIOS NVRAM. The wipe removed the BIOS
  copy; the EC copy is untouched.
- **Press `Restore`** — the BIOS re-reads MUD from the EC and repopulates NVRAM.
  `Continue` would boot with blank BIOS identity. Verify serial
  (5CG8528HKL) in BIOS → System Information after restore.
- A BIOS update (to latest from hp.com) can follow; it does NOT re-add the
  admin password.

## Flash-back procedure

1. Load the chosen file in Xgpro, program the W25Q256 (main chip) via the
   WSON8 pogo or the T48 ISP socket. The files are exactly chip size, so no
   trailer handling is needed on **write** (the 272-byte `-Configuration-`
   trailer quirk only affects **saves**).
   **Result: password gone. On first boot expect the MUD recovery screen —
   press Restore, verify serial, then optionally BIOS-update.**
2. Verify read-back against the file.
3. Boot. Expect a first-boot NVRAM reinit (date/time prompt or auto-reset), then
   BIOS setup with **no admin password**.
4. Side effects of the wipe: factory-default setup values, boot order, RTC,
   PhysicalPresence — all acceptable for ITAD.

## If it does not stick

If the EC restores the password despite the full-store blank (not seen with this
method, but possible on other revisions):

1. Flash the video's provided file **as-is** (works per two commenters, but
   adopts a foreign serial/UUID/MAC — restore ours afterwards), or
2. Return to the EC route: desolder `QN800` (W25Q128JV, WSON 6×5) and apply the
   same store-blank analysis to the 16 MB EC dump.

## Verification notes

- Store header, sizes and GUIDs verified with `uefi_firmware` FV parsing; the
  FV map of both images is identical (7 FVs in the BIOS region, same offsets).
- Marker `NvramActiveRegn` appears 4× in **both** files (inside compressed
  BIOS modules, not in the variable store) — it is a red herring for patching.
