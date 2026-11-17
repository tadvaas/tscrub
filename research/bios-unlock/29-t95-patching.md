# 29 — T95 (ZBook Fury 15.6 G8): module map, V2 probe, and code-patch route

**CORRECTION (same day):** the CND2048Y34 dump this doc is based on came from an
unlocked unit. The analysis still describes the T95/S92 credential driver
family (same FFS GUIDs); the real locked unit is CND14004KD (S92, ZBook Fury 15
G7) — see **doc 30**.

**Date:** 2026-11-17
**Goal:** flash-only unlock of the 32 MB main SPI chip on the T95/21WW platform
(CND2048Y34, BIOS 01.07). KEEPBB is dead here — the EC mirror machinery is in
every T95 release since launch (doc 27) — so this doc maps the mirror modules
and stages the two remaining flash routes: the V2 store probe (built) and a
code patch of `CredentialServicesSmm` (mapped, not yet built).

## 0. Tools (installed this session)

- `UEFIExtract NE A76` (universal mac) at `/Users/user/tools/uefi/UEFIExtract`
  (LongSoft A76). The win64 builds bundle `UEFIReplace.exe` — run that on the
  bench for module swaps (mac build has no UEFIReplace).
- `.venv` now has `pefile` + `capstone` for module disassembly.
- Softpaq handling: `curl ftp.hp.com/...spXXXXX.exe` → `7z x` → `T95_XXYYZZ00.bin`.
  The `.bin` = 0x320-byte header + full 16 MB BIOS region (verified against the
  unit's 26 FVH positions, constant +0x320 shift; boot-block FVH at 0xFFE348).

## 1. Dump layout (CND2048Y34)

- 32 MB W25Q256FV. Bottom 16 MB = descriptor+ME (keep). Top 16 MB = BIOS region.
- Auth var store sig `782cf3aa…` at 12 locations; credential entries: **only**
  `UserCred` (4544 B live @0x1949D10, GUID f66687ff-8cf3-4a19-b4ac-f5f0b78e4d18)
  and `SmcLog` (191 B). No AuthNextCredential* / Beam* vars in the store
  (unlike the 830 G5, T95 keeps those in code, not NVRAM).
- Boot block: `_FVH` at 0x1FFE028, reset vector `EB CE` — same geometry as the
  830 G5 (Sure Start hashes this 8 KB only, per doc 28).

## 2. Module map (UEFIExtract, unit 01.07 region)

Region → FFS `5473C07A-3DCB-4DCA-BD6F-1E9689E7349A` → sections `9E21FD93…` →
`EE4E5898…` → **compressed Volume image** (decompresses to 33,345,536 bytes =
inner FV, dumped at `…/1 Volume image section/body.bin`; `unc_data.bin` is the
raw decompressed blob). ~750 FFS files inside.

Credential-relevant modules (index = UEFIExtract dir number):

| idx | FFS GUID | type | size | markers |
|---|---|---|---|---|
| **123** | **`8367013A-B7AB-4410-BB85-2F5CFAEFA27D`** | SMM_CORE | 35,970 | UserCred UTF-16 + GUID, SMC GUID |
| 125 | `E9425CF9-1E28-43CD-9ED1-C6D1F7D55BA9` | SMM_CORE | 20,594 | AuthNextCredentialId ×2 (== 830 G5 FFS 122 GUID `42E95CF9…`) |
| 525 | `6D33944A-EC75-4855-A54D-809C75241F6C` | SMM | 172,750 | SmcInProgress ×2 |
| 86 | `9AF6BED6-4C56-35D4-AC8D-D78EF854A840` | SMM | 55,962 | SmcInProgress ×2 |
| 94 | `A476E016-0A73-496D-AA4D-6A3FB134846F` | SMM | 31,882 | SmcInProgress ×2 |
| 53 | `4D3BBDC1-449D-607E-6E05-06A7DB127078` | SMM | 22,646 | SmcInProgress ×2 |
| 90 | `79B41D9A-420C-E135-5D19-4582C6F9E6EF` | SMM_CORE | 11,402 | BeamLocalUser1, AdminPW, BeamRemoteUser, SMC GUID |
| 89 | `D84BA0ED-FB3D-4476-9460-2DEC96CF3523` | SMM | 20,086 | BeamLocalUser1, BeamRemoteUser |
| 98 | `245A01F4-BB8E-4F7C-91EE-E72E14E3B57E` | SMM_CORE | 14,454 | AdminPW, BeamRemoteUser, SMC GUID |
| 99 | `C16EBDDA-DE41-4741-8A46-81A5D9688567` | SMM_CORE | 10,414 | BeamLocalUser1, AdminPW, BeamRemoteUser |
| 578 | `EDF904D7-4109-1562-DAB9-0083159E882B` | SMM | 20,634 | AdminPW ×2 |
| 276 | `6E7E47DD-C898-ED47-B59A-7F80A622B214` | FREEFORM | 3,224,594 | ASCII UserCred (setup/auth UI resources) |

**`CredentialServicesSmm` is the SAME driver as the 830 G5's** (FFS GUID
`8367013A-B7AB-4410-BB85-2F5CFAEFA27D` identical) — the EC credential sync /
re-injection engine.

## 3. CredentialServicesSmm internals (module 123, PE at 0x1000/.data 0x7000)

- `.data` layout: **GUID table @0x7020** = [UserCred GUID
  `F66687FF-8CF3-4A19-B4AC-F5F0B78E4D18`, SMC protocol GUID
  `FD4E7631-3558-4C24-ADD3-C47EBEAB9267` @0x7030, `ED32D533-99E6-4209-9CC0-
  2D72CDD998A7` @0x70A0, `6CE73976-119C-4591-A501-8AE71D6CCAD5` @0x7100];
  descriptor/name structs 0x7190–0x74C0; **UTF-16 "UserCred" @0x74E0**.
- Function-pointer table at `.data 0x7288` (30+ slots → code 0x16D8–0x3E90) —
  this is the installed protocol vtable (other modules call CredentialServicesSmm
  through it, so wrapper callers live in OTHER modules).
- **0x6350** = GetVariable wrapper: `call [protocol]` with r9d=0xB (NV|BS|RT),
  name from `0x60c4` (dynamic name builder) or static @0x82F0.
- **0x6430** = SetVariable wrapper: identical but `call [protocol+8]`
  (SMM variable protocol Get=+0, Set=+8).
- **Credential sync/commit handler ~0x5FA0–0x62F0** (mirror of the 830 G5's
  0x52cc): flag gate `0x61b8` → SMC named-value read via `0x3628` (descriptor
  @0x8110, name @0x8150) → `call 0x210c` (size/data arg) → second SMC read →
  `LocateProtocol` ([rax+0xD0], GUID blob @0x7DC0) → `call [located]` = commit
  to the EC. A second arm (0x6201) does GetVariable/SetVariable with a **zero
  GUID** (0x7C80, HP internal namespace) then the same SMC commit.
- `0x60C4` = name builder; `0x65DC`/`0x667C` = Char16 helpers (the 0x667C one
  inserts `0x46` 'F' — AuthNextCredentialId-style name generation).
- Beam/credential handlers cluster at 0x688D–0x6BCC (refs to UserCred GUID and
  SMC GUID, protocol located via `[rax+0xD0]` through the SMM system table GOT
  @0x83E0). `0x698C` = SMM pointer-range validator; `0x6B44` = comm dispatch
  (calls `[rax+0x38]`/`[+0x40]`/`[+0x48]` = handler registration/begin/end
  pattern).
- Module 123 does NOT reference its own FFS GUID — on T95 the SMM comm
  registration differs from the 830 G5 (`{comm GUID, 8367013A…}` blob absent),
  so the restore opcode arrives via the installed protocol vtable @0x7288
  from a caller module (candidates: 525/86/94 — SmcInProgress cluster).
- Disassembly saved: `BINs/t95-modules/mod123_CredentialServicesSmm.asm` +
  `mod123.pe`. (radare2 was tried; its PE mapping is off-by-2 on this image —
  use capstone + pefile as above.)

### Still to trace (next session)

1. The boot-time **restore** (EC read → SetVariable UserCred) runs through the
   vtable @0x7288 / SMM comm from another module (525/86/94/90/98/99) — trace
   which slot is the SetVariable wrapper's caller and which comm opcode
   triggers it. The sync/commit handler above is the SET direction; the
   restore is its mirror image. Dump+disasm the candidate modules the same
   way (UEFIExtract paths under `0 A881D567…/`).
2. Patch candidates (in order of preference):
   a. **skip the restore**: NOP the SetVariable call inside the restore path;
   b. **restore becomes clear**: repoint the restore's data to the empty
      AdminPW record, driving the EC SET path (0x52cc-equivalent) so the EC
      mirror is CLEARED by our flash instead of restored;
   c. patch the SmcInProgress gate byte.
3. Mechanics: decompress-module-patch-recompress inside the compressed inner
   FV via `UEFIReplace.exe` (win64, run on the bench), then splice back into
   the region 0x1000000..0x1FFE000 keeping the unit's boot block. Validate
   output by re-running UEFIExtract and marker-scanning.

### UEFIReplace mechanics (for the bench, Windows)

1. On the bench: extract `UEFIReplace.exe` from the UEFITool NE A76 win64 zip
   (LongSoft release A76).
2. `UEFIExtract CND2048Y34_BIOS_region.rgn all` → the FFS file to replace is
   `…/3 5473C07A…/…/1 Volume image section/0 A881D567…/123 0142/…`
   (module index 123; the body is
   `123 0142/1 PE32 image section/body.bin`).
3. Patch `body.bin` (PE) with the chosen byte patch (offsets from this doc),
   keep the file length identical (NOP padding) so no FFS resizing is needed.
4. `UEFIReplace region.rgn 8367013A-B7AB-4410-BB85-2F5CFAEFA27D <patched.pe>`
   — UEFIReplace handles the compressed-section rewrap if the GUID identifies
   the FFS uniquely. If it refuses nested compression, fall back: replace the
   whole inner-FV containing file (`EE4E5898-3914-4259-9D6E-DC7BD79403CF`) by
   rebuilding the 33 MB inner FV (decompress `unc_data.bin`, swap the FFS
   entry, recompress with the original algorithm, fix outer section sizes).
5. Verify: `UEFIExtract` the patched region again → dump module 123 → confirm
   the patch bytes; then splice region into the full 32 MB image
   (`0x1000000..0x1FFE000` from patched region + unit's own boot block
   `0x1FFE000..0x2000000` + unit's bottom 16 MB).
6. md5 the output and flash via T48. Keep the original dump for revert.

## 4. V2 store probe (BUILT — ready for bench)

`unlock_t95.py` (macOS, mirrors `unlock_usercred.py`):
`BINs/W25Q256FV_SOIC8-CND2048Y34-UNLOCKED-V2.BIN`
md5 `8068161676ca7f63b8e6908b3231df1b` — the single live UserCred entry
(state 0x3F → 0xFD) deleted; 0 live UserCred after patch (asserted).

Bench protocol (matches the 830 G5 V2 experiment):
1. Flash the image, boot once into Setup (or let it sit at POST ~1 min for the
   EC sync), power off.
2. Re-read the chip: **if UserCred is byte-identical restored → re-injection
   confirmed on T95** (expected). If it stays 0xFD → V2 works and this whole
   platform is solved by a store patch.
3. Either way the original dump restores the unit.

Expected verdict: re-injection (mirror present in all T95 code). The
experiment is still worth one flash — it pins the behaviour to data instead of
inference, and if V2 DOES stick, the fleet gets a zero-hardware recipe.

## 5. Status

- Donor regions from official capsules: generated, geometry verified (doc 27).
- KEEPBB: **inapplicable** (mirror in all T95 releases; doc 27).
- V2 probe: built, awaiting bench.
- Code patch: module identified and disassembled; restore-caller tracing and
  UEFIReplace swap are the remaining steps.
- Non-flash fallbacks (unchanged): fleet password `wb8c43mw`; HP halt-code
  generator; EC dump + dellpwn/hashcat -m 130.
