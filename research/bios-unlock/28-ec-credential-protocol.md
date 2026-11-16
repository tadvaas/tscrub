# 28 — EC credential protocol: reversing the BIOS↔EC password channel

**Date:** 2026-11-16
**Goal:** find the EC command the Setup "clear password" flow uses, so we can
invoke it without Setup access (UEFI shell app or KEEPBB image patch) and make
the EC drop its credential mirror on BIOS 01.27+ units.

## Module map (root DXE FV @0x1624000, from JYH 01.27 dump)

Extracted with `uefi_firmware` (AutoParser on the FV at 0x1636000, full
decompression). All files in `BINs/jyh-modules/` (gitignored). FFS index →
role, FFS GUID:

| FFS | Module | FFS GUID | Evidence |
|---|---|---|---|
| 117 | BIOS admin authentication app (Setup/UI side) | `b1dac9bd-132e-4f9f-b2ca-14fdc65bd661` | 13-language "BIOS Admin Failed Authentication" strings, `BiosUpdateCmdSts`, `HP_TOOLS` |
| **120** | **`CredentialServicesSmm`** | **`8367013a-b7ab-4410-bb85-2f5cfaefa27d`** | `UserCred`, `AdminPW`, `BeamLocalUser1-4`, `BeamRemoteUser`, `SmcInProgress`, `Clear password jumper`, `HP_SecurityOptions` |
| 122 | AuthNextCredential provider | `42e95cf9-1e28-43cd-9ed1-c6d1f7d55ba9` | `AuthNextCredentialId`, `AuthNextCredentialNumber_`, `AuthNextProvider` |
| **130** | **`SmcSmm`** (SMC = EC bridge SMM driver) | **`a45687b1-18fb-4ceb-867c-c654dc7b9ce3`** | `AdminPW`, `BeamLocalUser1`, `BeamRemoteUser`, `HP_MUD`, `SmcLog` |
| 174 | `HP_SureStartSetup` (1.1 MB) | `a0a3fec9-fe9d-4ce7-8db4-9c54f3f1789e` | Sure Start strings |

## Protocol chain observed in `CredentialServicesSmm` (mod_19)

- Calls `LocateProtocol` (via the SMM system table at 0x7760, offset +0xd0)
  with GUID **`fd4e7631-3558-4c24-add3-c47ebeab9267`** (the SMC protocol).
- Calls the located protocol at offset 0 with args:
  - rcx = descriptor `{0x4, …0, 0x1, …0, "CredentialServicesSmm"}` @0x74A8
  - rdx = UTF-16 `SmcInProgress` @0x7710
  - r8  = GUID `fb3b9ece-4aba-4933-b49d-b4d67d892351` @0x7070
  - r9  = 0; stack args: output byte + qword 1
- So the EC sync is a named-value exchange ("SmcInProgress", presumably also
  "UserCred"/"AdminPW"/"BeamLocalUser*") through the SMC protocol — matching
  the boot-time credential re-injection we observe in afterflash dumps.
- **No `in`/`out` port I/O exists in ANY extracted DXE module** (scanned all PE
  files for 0x62/0x66 access) → the actual EC hardware access is one layer
  lower than SmcSmm (SMM-only mechanism, likely chipset mailbox / trap).

## Other GUIDs seen in CredentialServicesSmm data

- `8b91743a-12e3-480c-9d8e-8bbc634b4d7b` @0x7190
- `fb3b9ece-4aba-4933-b49d-b4d67d892351` @0x7070 (passed to SMC call above)

## Next milestones (in order)

1. Trace the three `UserCred` xrefs in `CredentialServicesSmm`
   (`0x18d9`, `0x1991`, `0x4013`) — find the delete/set path and the exact SMC
   call arguments used for the credential (name, data, opcode).
2. Map `SmcSmm`'s protocol function table and find where the EC mailbox is
   really driven (MMIO base, command encoding).
3. Determine the SMM invocation channel: `EFI_MM_COMMUNICATION` with the
   driver FFS GUID `8367013a-b7ab-4410-bb85-2f5cfaefa27d` is the usual EDK2
   pattern for reaching SMM handlers from ring 0 (a UEFI shell app).
4. Build the exploit vehicle: (a) UEFI shell app that locates the SMC protocol
   in SMM and clears the credential, or (b) patch `CredentialServicesSmm` /
   `SmcSmm` inside a **KEEPBB** image (requires KEEPBB to boot first, i.e.
   Sure Start only hashes the boot block).

## Credential SET path — fully mapped (2026-11-16)

Traced in `CredentialServicesSmm` (mod_19, VAs as before):

- `0x1870` = internal **set-UserCred**: `SmmSetVariable("UserCred",
  f66687ff-8cf3-4a19-b4ac-f5f0b78e4d18, Attr=7, Size=0x11C0/4544, Buf@0x7920)`
  (protocol call at `[r10+0x10]`, name @0x74E0, GUID @0x7200). 12 callers.
- `0x191c` = internal **get-UserCred** (`[r10]` = SmmGetVariable).
- `0x30d0` = SMC-busy gate (`SmcInProgress` flag @0x7730 checked everywhere).
- `0x52cc` = the **credential set handler** (input = credential record struct):
  1. `LocateProtocol(34f87330-8f1b-c963-e38e-35af08b3404f)` @0x7130 — this data
     blob is `{comm GUID, 8367013a-b7ab-4410-bb85-2f5cfaefa27d}` → the SMM
     communication channel record for this driver.
  2. `0x36ac` = find-credential-by-name; names seen: `BeamRemoteUser`
     @0x7528, `BeamLocalUser1` @0x7508, `AdminPW` @0x74F8.
  3. **`0x2214` = the EC/SMC credential WRITE** — called with
     `(foundData, size+8, r9+0x16, r9+0xe, opcode=2)`.
  4. `LocateProtocol(b7e6cc0e-6132-4fd8-8992-026727351d81)` @0x71C0 → `call [p](0)`
     = **commit/flush** to the EC.
- `0x63f8` = SmcInProgress query (SMC protocol `fd4e7631-3558-4c24-add3-c47ebeab9267`
  Fn0 with `{descriptor "CredentialServicesSmm", L"SmcInProgress", GUID
  fb3b9ece-4aba-4933-b49d-b4d67d892351, 0, &byte, &flag}`).
- `0x613c`/`0x7220` path: `LocateProtocol(cabf469a-4e65-34fc-5b48-fbbd993c681e)`
  + `call [p+0x48]` — a second check before write.

So a "clear password" = credential set with EMPTY data via the 0x52cc path
(with the AdminPW record), which drives the EC write (0x2214) + commit. The
re-injection we see at boot is the mirror image of this: EC read → 0x1870.

**DXE modules are COMPRESSED on-chip (2026-11-16):** the ASCII names
"CredentialServicesSmm" / "SmcSmm" / "UserCred" do NOT exist anywhere in the
raw chip image — every module body sits in compressed FFS sections. Patching
JYH's own 01.27 code therefore requires decompress → patch → recompress with
FFS/FV header fixes = UEFIReplace (`brew install uefitool` provides it). Two
rollback gates to keep separate: (a) the HP updater's capsule check (irrelevant
— we flash via T48) and (b) the EC/Sure Start version floor (EC-enforced,
version-based — KEEPBB does NOT bypass it; only the boot-block hash gate is
addressed). If VIDEO-KEEPBB boots, that single experiment proves BOTH no floor
and hash scope = boot block; if it black-screens, the two gates cannot be
distinguished without the EC dump.

## Boot block size (Sure Start hash scope)

The top FV at `0x1FFE000` (starts with the x86 reset vector `EB CE` in its
header region) has `FvLength = 0x2000` (8 KB) — the SEC boot block is tiny.
KEEPBB preserved the top 0x20000 (128 KB), so **if Sure Start hashes only the
boot block, the KEEPBB variants already satisfy it** with 16× margin.

## RESULT (2026-11-16) — KEEPBB worked on JYH

The reversal's goal is achieved by a different route: old code under JYH's
boot block (see doc 27). The EC credential mirror becomes irrelevant because
the old BIOS neither validates against it nor re-injects it. This CONFIRMS the
gate analysis: Sure Start hash scope = boot block only, and no version floor.
Remaining uses for this reversal: (a) a patched-01.27-code variant that keeps
identity + Windows key (better fleet recipe) and (b) the EC protocol map for
the EC-dump route if ever needed.

## Exploit vehicles (updated ranking)

1. **KEEPBB done — worked.**
2. **Patched 01.27 code** (identity-preserving fleet recipe): decompress →
   patch `CredentialServicesSmm` → recompress via UEFIReplace; only worth it
   if the donor-store variant loses the Windows key.
3. **UEFI shell app via `EFI_MM_COMMUNICATION`** keyed on
   `8367013a-b7ab-4410-bb85-2f5cfaefa27d` — needs the SMM handler opcode table
   recovered from the handler registration in mod_19.
4. EC dump route (unchanged, highest certainty, needs 6×5 pogo).

## Notes / cautions

- Everything is grounded in the 01.27 dump; offsets are per-module VAs
  (`.text` VMA 0x1000, `.data` VMA 0x7000 in mod_19).
- An EFI app cannot call the SMM protocol directly; it must go through
  `EFI_MM_COMMUNICATION_PROTOCOL` (`MpService`/comm buffer). The comm GUID +
  buffer format must be recovered from the handler registration code.
- This is complementary to the EC-dump route: both answer the same question,
  one by code, one by data.
