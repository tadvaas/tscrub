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

## Notes / cautions

- Everything is grounded in the 01.27 dump; offsets are per-module VAs
  (`.text` VMA 0x1000, `.data` VMA 0x7000 in mod_19).
- An EFI app cannot call the SMM protocol directly; it must go through
  `EFI_MM_COMMUNICATION_PROTOCOL` (`MpService`/comm buffer). The comm GUID +
  buffer format must be recovered from the handler registration code.
- This is complementary to the EC-dump route: both answer the same question,
  one by code, one by data.
