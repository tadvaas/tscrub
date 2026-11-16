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

**EC restore — retracted (analysis correction):** the post-boot re-read
(`W25Q256FV_SOIC8-5CG832347D-REREAD.BIN`) shows the BIOS rebuilt `UserCred` as a
**fresh EMPTY credential structure** — header + `AdminPW` name + a 10-slot table
(markers every 456 B), with **no password hash carried over** (the earlier
"byte-identical block" claim was zero-padding + the `AdminPW` name only). So the
main-BIOS copy is clean, yet the Setup prompt persists -> **the admin credential
on this unit is held EC-side** and the BIOS re-creates an empty main-BIOS record
that is simply unused. `AdminAtPowerOnAuthPolicy` presence is **NOT a reliable
predictor**: new dumps of SBT602/SCT601 units (5CG8528JYH, 5CG91229SG) also carry
it (value 1), and those trains have unlocked fine before. **Test matrix (updated 2026-11-16):**

| Serial | BuildId | BIOS (Q78) | Result |
|---|---|---|---|
| 5CG8403V0S | SBT601 | 01.03.00 (2018) | ✓ V2 |
| 5CG8528HKL | SBT602 | 01.04.00 (2018) | ✓ V1 + BIOS update |
| 5CG91229SG | SCT601 | 01.06.00 (2019) | ✓ V2, "finishing BIOS update" bar on first boot |
| 5CG8528JYH | SBT602 | 01.27.00 (2023) | ✗ prompt persists — credential restored byte-identical |
| 5CG832347D | SAT601 | 01.28.00 (2024) | ✗ prompt persists (EC-side, empty skeleton rebuilt) |
| 5CG8528GQT | SBT602 | 01.31.00 (2025) | ✗ prompt persists — credential RESTORED byte-identical |

The train rule is **broken**: GQT is `17WWCSBT602` — the "good" train — and the
EC re-injected the credential. The axis that correlates is the **BIOS vintage**:
2018-2019 revisions (≤01.06) clear; 2023-2025 revisions (01.27/01.28/01.31) hold
the credential EC-side. `AdminAtPowerOnAuthPolicy` is NOT a predictor. For any
unit where the prompt returns, send the post-boot re-read. EC routes unchanged:
Sure Start pin-2 short (doc 24) or desolder QN800 + patch the 16 MB EC dump.

**GQT afterflash analysis (2026-11-16, `BINs/W25Q256FV_SOIC8-5cg8528gqt*`):**
the V2 patch was verifiably ON the chip at boot — the post-boot re-read shows
the variable store recompacted (`UserCred` moved 0x1B00EAC → 0x1B0AA7C,
`AdminAtPowerOnAuthPolicy` 0x1B00A40 → 0x1B009C4, deleted entry gone). Yet the
BIOS rebuilt a LIVE `UserCred` whose 4,544-byte body is **byte-for-byte
identical** to the original (all 180 non-zero bytes in the same positions, 0
diffs) — the real credential hash, not the empty skeleton SAT601 rebuilds.
Searches for the credential header/slot bytes across all 32 MiB find **no
second copy** outside the variable store, and no `AuthNextCredential*` staging
vars exist. Conclusion: the 01.31 BIOS re-injects the password from the **EC**
(the same EC→BIOS backup mechanism that preserves the password across normal
BIOS updates). **JYH afterflash (01.27.00, 2023) shows the identical signature** —
rebuilt UserCred byte-for-byte equal to the original, no second copy in the
image, no `AuthNextCredential*` vars — so the EC mirror exists from BIOS 01.27
at the latest. **V5 tested on JYH (2026-11-16):
the EC DID honour the request** — first boot showed "request to reset security
settings" and a TPM reset prompt (both accepted) — so the
`ClearSecurityOperation` channel works, but **the admin password still
returned**: the EC credential mirror survives the clear-security flow.
**V5 is therefore retired** — the tools emit the **V2 recipe** (UserCred +
`AuthNextCredential*` only) and write `-UNLOCKED-V2.BIN` again. Definitive next
step: dump the EC `QN800` (16 MB W25Q128JV) via pogo, locate the mirror there,
patch it, and reflash both chips. Send a post-V5 re-read of the main chip too
(UserCred state, `ClearSecurityOperation` byte) for the record.

**BIOS downgrade experiment (queued 2026-11-16):** flashing an old BIOS is the
cheapest way around the EC re-injection — the ≤01.06 BIOS code does not restore
`UserCred` from the EC. HP's own updater blocks rollbacks (rollback prevention;
the floor is EC-enforced, no floor string visible in the image), but the
programmer does not care. Image:
`BINs/W25Q256FV_SOIC8-5cg8528jyh-DOWNGRADE-0106.BIN` (md5
`4f7a8a439d533aa11b96b9ac3a5ea38c`) = JYH bottom 16 MB (descriptor + ME,
identity, GbE) + 91229SG `-UNLOCKED-V2` top 16 MB (BIOS 01.06.00, `UserCred`
already 0xFD, donor MUD present). Expected first boot: MUD recovery screen —
press Restore to pull JYH identity from the EC. If the EC enforces a rollback
floor the unit will halt with a Sure Start error — recover by reflashing the
original JYH dump (non-destructive). Outcomes: (a) boots 01.06.00 with no
password = downgrade is the fleet fix for 01.27+ units; (b) boots but prompt
persists = EC enforces independently of the main BIOS = EC dump route.

**Downgrade RESULT (2026-11-16): BLACK SCREEN, no POST.** The splice was
structurally correct (doc 11: BIOS region = top 16 MB, same recipe the
`bios_region.bin` extraction uses) and the donor region is known-good code
(91229SG boots it) — so the EC halted the platform before video init:
Sure Start hash / rollback-floor rejection of the 01.06 code. Any full-code
swap to older BIOS will hit this unless the EC cooperates (only a real HP
update flow registers new code with Sure Start). Recovery: reflash the
original JYH dump (`W25Q256FV_SOIC8-5cg8528jyh.BIN`) — unit returns to its
locked state. Downgrade route suspended; the EC dump (`QN800`, 16 MB) is now
required for BOTH open problems: the credential mirror and the Sure Start
state (hash records / rollback floor).

**KEEPBB downgrade — WORKS (2026-11-16, JYH):** flashed
`W25Q256FV_SOIC8-5cg8528jyh-DOWNGRADE-VIDEO-KEEPBB.BIN` (JYH bottom 16 MB +
video donor region under JYH's own boot block). The unit BOOTED, showed the
"finishing BIOS update" progress bar, rebooted — **the admin password is
GONE.** This single experiment proves: (1) Sure Start hashes ONLY the boot
block (code below 0x1FFE000 is freely modifiable), (2) no EC version floor
blocks 01.01-era code. The fleet recipe for BIOS 01.27+ units: KEEPBB splice
= unit's bottom 16 MB + unit's top 128 KB + donor region (blank store) in
between; first boot runs MUD recovery from the EC, password does not return.
**BIOS update AFTER unlock is SAFE (verified 2026-11-16, photos IMG_1458-1460):**
JYH was updated 01.09.01 → **01.31.00** via the official Network BIOS Update;
the update completed and the unit entered Setup with NO password prompt, serial
`5CG8528JYH` + SKU + UUID intact. The earlier caution ("avoid BIOS updates") is
RETRACTED — the official update flow preserves the empty credential state (and
the EC mirror does not resurrect it). Fleet recipe is now: KEEPBB splice →
optional update to latest → done. Open checks: serial/UUID restored, Windows
activation (donor region has no `HP_OA3`/MSDM key for this unit),
second-boot behaviour.

**After-KEEPBB re-read analysed (`W25Q256FV_SOIC8-5CG8528JYH-AfterKEEPBBPatch.BIN`):**
identity intact (`HP_MUD` 5CG8528JYH + `BuildId` 17WWCSBT602 restored from the
EC), `UserCred` rebuilt as an EMPTY skeleton (3536 B, 140 non-zero bytes at
slot markers only, NO AdminPW record) — password gone. **The donor code is
Q78 01.09.01** (photo evidence: Setup reported 01.09.01 before the update; its
`LastCheckedBiosVerForBatteryRecall` reads 0x09; the
`01.01.07` string in the donor's ME region was the donor unit's PRE-update
version — stale). Store re-initialised to 294 entries (75,199 bytes changed
vs the flashed file, all in the store + FV housekeeping). **Caveat: `HP_OA3`
is now all zeros** — the Windows key was in the unit's own store and did not
survive the blank-store swap (the EC does not hold it). The key IS recoverable
from the pre-flash dump (JYH: `PH89H-NPDRT-P4CP9-72FFW-TQ722`). Fleet
procedure: extract each unit's `HP_OA3` key before flashing, re-inject after
unlock via OA3 tooling.

**OA3 key restored by store patch (2026-11-16):**
`W25Q256FV_SOIC8-5CG8528JYH-AfterKEEPBB-OA3.BIN` (md5 `144d3439…`) = the
after-KEEPBB dump with JYH's own 49-byte `HP_OA3` payload (from the pre-flash
dump) written into the live variable — 32 bytes changed, `UserCred` still an
empty skeleton. Flash this instead of OA3 tooling: same unlocked state, and
the MSDM/`HP_OA3` key is back so Windows activates with the unit's own key.

**What the KEEPBB unlock wipes (store diff, 2026-11-16):** the donor region's
blank store is re-initialised at first boot, so everything that lived only in
the BIOS NVRAM is reset. Survives (EC-synced or outside the store): serial /
SKU / UUID (`HP_MUD`), `BuildId`, **feature byte** (`FactoryConfig` — verified
byte-equal), MAC (GbE region kept), ME/AMT identity (bottom 16 MB kept).
Wiped: `HP_OA3`/MSDM Windows key (recoverable from the pre-flash dump),
ALL Setup settings (factory defaults), custom boot entries, Secure Boot extra
keys (`MokList`, `SbatLevel`, `RevocationList`, `Kernel_*SiStatus` — defaults
rebuilt), `HpBiosAuditLog` (cleared — a plus), `UnlockID`/`UnlockIDCopy`,
`HP_TAGS` (blank), `BornOnDate` (00/00/0000, cosmetic), TPM state
(`PhysicalPresence*` gone — TPM effectively cleared; BitLocker keys die,
irrelevant for ITAD wipe). Fleet action items: re-inject `HP_OA3`, set Asset
Tag if required — everything else is acceptable or beneficial for ITAD.

**Keep-OA3 variant (2026-11-16, SAT601 unit 5CG832347D):** two images built.
(A) `W25Q256FV_SOIC8-5cg832347d-DOWNGRADE-KEEPBB.BIN` (md5 `14584a5d…`) = the
proven blank-store recipe. (B) `W25Q256FV_SOIC8-5cg832347d-KEEPBB-KEEPSTORE.BIN`
(md5 `d4c60489…`) = same splice but the donor region carries the UNIT'S OWN
store with every `UserCred` copy flipped 0xFD (three copies: 0x1B024D8,
0x1B0B840, 0x1B0CB14) and `AuthNextCredential*` 0xFD — so `HP_OA3`
(`TNQYQ-4X2TT-BCM8G-WGW8G-TQ722`), `HP_MUD` (5CG832347D) and all settings ride
along in ONE flash, no MUD screen expected. B is experimental (a 01.28-format
store read by 01.09 code — worst case it reinitialises and behaves like A);
flash B first, fall back to A + OA3 re-inject if anything misbehaves.

**Region file & boot-block-preserving variants (2026-11-16):**
`Region_BIOS_BIOS_region.rgn` is the video image's 16 MB top half — code FVs +
NVRAM store area + GbE tail; it does NOT contain the descriptor/ME (bottom half).
The video donor unit was on **Q78 Ver. 01.01.07** (string in its ME-region
metadata). Its store is blanked (the video's wipe), and its FV layout matches
JYH's (SBT602) better than the 91229SG donor's. Historical note: on HKL
(01.04.00, 2018 EC) the region swap BOOTED (MUD mismatch screen → Restore →
later BIOS-update-to-latest finished it, password stayed gone) — the 2018-era
EC did not gate code changes. JYH (01.27) black-screens the same swap, so the
EC code-acceptance gate shares the same BIOS-vintage boundary as the credential
mirror. Since
the EC gate appears keyed to the boot block (top FV at 0x1FFE000; V2/V5 which
only touch the store boot fine), two variants keep JYH's own boot block and swap
only the FVs below: `W25Q256FV_SOIC8-5cg8528jyh-DOWNGRADE-0106-KEEPBB.BIN`
(md5 `f3b1469b…`, 91229SG code, FV layout mismatch) and
`W25Q256FV_SOIC8-5cg8528jyh-DOWNGRADE-VIDEO-KEEPBB.BIN` (md5 `9cac6021…`, video
code, layout match, store already blank → MUD recovery expected on boot). Both
are low-probability: if Sure Start hashes beyond the boot block they
black-screen the same way (recover = reflash V5). If either boots, JYH's 01.27
boot block may reject the older FVs with an on-screen version error.

**UserCred structure — the blob is NOT the hash (2026-11-16):** byte-diffing
blobs across units shows `UserCred` holds credential REGISTRATION records, not
the password hash: WTX's blob literally contains the Windows account
`defaultuser0`, the SID `S-1-5-21-224614338-12078856-3342784731-1001` and the
computer name `DESKTOP-RHTFF08`; HKL's `AdminPW` record has no hash-like bytes
(JYH vs GQT blobs are byte-identical, HKL vs V0S too). The hash itself is
EC-side — so the main dump cannot be cracked; the EC dump (or an official flow
that clears the EC) is required.

**EC interface & factory channel (2026-11-16):** OEMs do NOT write the EC flash
directly from OS tools — the BIOS firmware is the master writer over the EC
mailbox and syncs MUD + the credential mirror at boot. Factory provisioning
works by setting NVRAM command vars that the BIOS executes at next boot:
`HP_FactoryControlCommand` (4 B, value 0 in JYH) and `HP_LabProductionModeVar`
(2 B, `00 01`) — both present in every dump. The Windows key is NOT EC-side:
it lives in the main BIOS as `HP_OA3` (49 B, visible key text) + `HP_OA3_LOCK`=1
and the MSDM ACPI table. Untried EC-trick candidates: (a) set
`HP_FactoryControlCommand` / `HP_LabProductionModeVar` to candidate opcodes in
the patched image — the BIOS may run a factory security/credential clear WITH
EC cooperation; (b) disassemble the DXE/SMM credential driver in the dump we
already hold to find the EC command the Setup "clear password" flow uses, then
invoke it from a UEFI shell app or a KEEPBB-patched image.

**"Finishing BIOS update" progress bar on first boot — investigated (2026-11-16).**
91229SG's pre-flash dump carried NO staged update state: `BiosUpdateCmdSts`,
`FwUpdateCmdSts`, `HP_FwUpdate`, `HP_FlashTargetVersion` are absent on 01.06,
`HP_FactoryControlCommand` = 0, `BiosInstallDate` = 2019-03-19 (factory). The
patch changed exactly one byte, so the bar was triggered by the `UserCred`
deletion itself: the first boot runs a variable-store reclaim (offsets move in
the afterflash dumps) plus a security-state sync. It is NOT a BIOS reset — on
JYH the before/after pair shows only `MTC` (monotonic counter) changed besides
`UserCred`. The bar's text does not exist anywhere in the 32 MB main image
(ASCII/UTF-16 scans + decompressed FV scan of 01.06: 44 blobs, 37 MB, no
"finishing"/"progress"/"BIOS update" UI strings) — it is EC/Sure
Start-rendered. Likely the same first-boot sync that re-injects the credential
on 01.27+ units. Benign; the PS1 prints the operator note.

**Fleet note (unit 2, 5CG8403V0S):** same procedure, `UserCred` sits at a
different offset (`0x1B0058C`) — always locate it by walking the store for the
UTF-16 name rather than hardcoding. 289 entries, exactly 1 byte changed
(`0x1B0058E` → `0xFD`), MUD intact. Unit 1's MUD loop was also resolved by a
BIOS update to latest (update did not re-add the password), so BIOS-update is
the fallback if the MUD screen ever persists.

**SAT601 BIOS revision gotcha (unit 5CG832347D, BuildId `17WWCSAT601`):**
deleting `UserCred` alone did NOT clear the password. That revision keeps a
staged credential in `AuthNextCredentialId` (8 B) + `AuthNextCredentialNumber_1`
(1,260 B, binary) and re-applies it on boot, resurrecting the password.
Working units (`17WWCSBT602`) have no `AuthNext*` variables. **V3 recipe: also
flip every live `AuthNextCredential*` entry to `0xFD`** — 3 bytes changed on
this unit, confirmed in `W25Q256FV@SOIC8-5cg832347d-UNLOCKED-V3.BIN`. Both
`unlock_usercred.py` and `Unlock-Bios.ps1` now do this automatically.

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

## Windows patching tool

`Unlock-Bios.ps1` (this folder) is the Windows-native, zero-dependency port of
`unlock_usercred.py`:

```powershell
powershell -ExecutionPolicy Bypass -File .\Unlock-Bios.ps1 -Bin .\W25Q256FV@SOIC8-XXX.BIN
```

It strips the 272-byte Xgpro trailer, validates the header, locates `UserCred`
dynamically (offset varies per unit), flips its state byte, deletes staged
`AuthNextCredential*` variables, verifies exactly N bytes changed, prints the
unit serial read from `HP_MUD`, and writes `<input>-UNLOCKED-V2.BIN`. Both
tools also print the **BIOS version + release date**
(`LastCheckedBiosVerForBatteryRecall` byte 0 + a Q78 release table) and warn
when the BIOS is 01.27+ (EC credential mirror). Acceptance test on the bench
PC — the MD5 of the
output for the two known dumps must equal:

```
W25Q256FV@SOIC8-5CG8528HKL.BIN -> d292236faef559bbae082a9283e7cd81
W25Q256FV@SOIC8-5CG8403V0S.BIN -> 873309c68ca93a1b5e574b74ae9ced81
```

Writing back stays in Xgpro (Erase -> Blank Check -> Program -> Verify).

## Verification notes

- Store header, sizes and GUIDs verified with `uefi_firmware` FV parsing; the
  FV map of both images is identical (7 FVs in the BIOS region, same offsets).
- Marker `NvramActiveRegn` appears 4× in **both** files (inside compressed
  BIOS modules, not in the variable store) — it is a red herring for patching.

## Runtime deletion experiment — CONFIRMED DEAD (2026-11-16, unit 5CG832347D)

Tested deleting `UserCred` from a running system (tScrub netboot env, telnet
shell, efivarfs) instead of offline patching:

- `UserCred-f66687ff-8cf3-4a19-b4ac-f5f0b78e4d18` is exposed read-only with the
  kernel **immutable** flag; the appliance also mounts efivarfs `ro`.
- After `mount -o remount,rw` + `chattr -i` (both succeed), both `rm` (delete =
  SetVariable size 0) and writing back the identical data fail with
  **EINVAL / "Invalid argument"**.
- Conclusion: **HP firmware rejects every runtime SetVariable on `UserCred`**
  (delete and write). The variable is firmware-protected, so no runtime route
  exists — the offline state-byte flip via external programmer is the only
  method. The unit was left untouched (all writes failed), no restore needed.
- Side note: the tScrub appliance mounts efivarfs `ro` deliberately; the
  remount was transient (netboot RAM image).
