# 11 — 830 G5 BIOS dump anatomy

Analysis of `hp elitebook 830 g5 main.bin` (33,554,432 bytes = **32 MB**, SHA-256
`612874ce…`). This is the first real dump to reverse, so the map below is ground truth.

## Chip & layout

- **32 MB = Winbond W25Q256** (not W25Q128 as first assumed).
- Valid Intel flash descriptor at `0x10` (`5A A5 F0 0F`), region base table at `0x40`.

| Region | Offset | Size | Notes |
|---|---|---|---|
| Descriptor | `0x000000` | 4 KB | layout + flash permissions |
| GBE | `0x001000` | 8 KB | all `FF` in this dump |
| **ME** | `0x003000` | ~16 MB | `$FPT` partition table at `0x3000` |
| **BIOS** | `0x01000000` | **16 MB** | firmware code + NVRAM + HP identity |

## BIOS vendor — **AMI Aptio**, not Insyde

- The firmware volume is compressed, so no plaintext `Aptio`/`InsydeH2O` string appears,
  but the code at `0x1BD5880+` is x86/Thumb machine code, and the password-verify debug
  strings are AMI-style.
- This confirms **why the 830 G5 shows no Insyde "i-code"** — it's an AMI platform, so
  the Insyde hash generator never applied. (Empirically we already knew this.)

## Password is **hashed**, stored in NVRAM

Debug strings in the BIOS region confirm the scheme:

```
@0x1BCF770  "PasswordId:%Xh"
@0x1BCF780  "Hash validated. PPI:%Xh  PasswordId:%Xh"
```

The admin password is a **hash** inside the AMI **"Setup" NVRAM variable** — you don't
crack it, you clear the field (see §10 SOP). The variable lives in the BIOS-region
NVRAM stores.

## Identity (preserve this!) — HP MUD area

UTF-16LE strings around `0x1C4F800`:

```
@0x1C4F84C  "HP_MUD"
@0x1C4F85C  "BuildId"
@0x1C4F86C  "FactoryConfig"
@0x1C4F87C  "FactoryConfigFlags"
```

This is HP's **Machine Unique Data** region — serial / UUID / FeatureByte / BuildId.
The serial is **not** stored as plaintext (obfuscated here), which is normal. When
patching, leave this region alone or re-inject it verbatim.

## Version note

The dump's BIOS version string is **`Q78 Ver. 01.01.07`** (at `0x174B6D`/`0x17558C`),
while the DB recorded `5CG8403V62` as `01.03.00`. So this dump is either from a
different unit (one of the 3 on `01.01.07`) or the DB snapshot was stale. **Confirm the
unit + revision before developing the patch** — the patch is revision-specific.

## 2026-10-23 marker scan

A later scan of the same dump found:

- **0** `HpPassphraseStructureVariable` markers (so `dellpwn`'s HP hash extraction
  does not trigger).
- **0** `$VSS`, **0** `UserCred`.
- **4** `NvramActiveRegn` blocks (first at `0x1BE6ED7`), 4 `H_FbPolicy`, 4 `L_MpmPolcy`.
- **0** plaintext `Hewlett-Packard` / `HP UEFI Secure Boot` because the BIOS volume is
  compressed. `hp_erase` therefore rejects the dump and the extracted BIOS region.

The `NvramActiveRegn` bytes at `+0x37` are binary, not the `H_FbPolicy`/`L_MpmPolcy`
subheader `hp_erase` expects, so it would skip them even if the sanity check were
bypassed. This points the next test at the **16 MB EC BIOS** (`U366`/`UC6`), not this
32 MB main dump. See `25-github-sweep-and-dump-tests.md`.

## Next steps

1. Extract the BIOS region for UEFITool (16 MB, 4 KB blocks):
   `dd if="hp elitebook 830 g5 main.bin" of=bios_region.bin bs=4096 skip=4096 count=4096`
2. Open in **UEFITool** → NVRAM → **"Setup"** variable → clear the admin-password field
   (or diff against a clean same-revision dump via the buy-one-diff-one trick, §09).
3. After patching, verify serial / UUID / FeatureByte / MAC / MSDM key are unchanged
   (§10 Step 5).
