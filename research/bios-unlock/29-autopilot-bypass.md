# 29 — Autopilot bypass via main-flash edits (JYH experiment)

Status: **image patched, bench runbook staged — flash NOT yet applied**
Date: 2026-11-17
Grounding: `research/autopilot/mutation-matrix/state.json` (final_model) —
fully proven by 30+ live probes on 2 devices.

## 1. The empirical model (what the matcher uses)

Microsoft's Autopilot import match accepts EITHER fingerprint lane:

| Lane | Records | Kill method |
|---|---|---|
| A | SMBIOS **UUID (12)** + **MAC (8)**, exact | change UUID (or MAC) — UUID is a record in `HP_MUD` in the 32 MB image |
| B | TPM **EkPub (25)** alone | **make it absent** — with TPM disabled, Windows' hash contains no EkPub record, so Lane B cannot match the tenant's registered EK |

Everything else (serial, ODID, ProductKeyId, disk, SMBIOS strings, posted
serialNumber) has NO role — proven.

**Therefore the bypass requires BOTH:**
1. changed UUID (kills Lane A), and
2. TPM disabled at OOBE (kills Lane B by omission).

## 2. What was patched

Base: `W25Q256FV_SOIC8-5CG8528JYH-AfterKEEPBBPatch.BIN` (latest known-good,
BIOS 01.31, password removed, HP_OA3 preserved).
Change: SMBIOS UUID in `HP_MUD` @ `0x1B04000` (single occurrence):
`E11BFC78-CA1F-243A-D052-53D01CE81EFE` → `E11BFC78-CA1F-243A-D052-53D01CE81EFF`
(last byte XOR 1; the probe data shows the match is EXACT, any change kills
Lane A).

Output: `BINs/W25Q256FV_SOIC8-5CG8528JYH-AUTOPILOT-UUID.BIN`
- size: 33,554,432 (no Xgpro trailer)
- md5: `97e6056ee60b72a9d78caff3e3e00db0`
- verified: zero occurrences of the old UUID remain, exactly one new one.

## 3. Bench procedure (operator, Xgcu T48 + WSON8 pogo)

1. **Flash** the patched BIN (33,554,432 bytes, no trailer).
2. **Boot → F10 setup → Security → TPM Embedded Security** → set TPM device
   per the chosen variant:

   | Variant | Setting | Lane B state | Durability |
   |---|---|---|---|
   | A (preferred) | `TPM Device = PTT` (Intel Platform Trust Technology) | EK comes from PTT — different key than the registered dTPM EK | **survives TPM re-enable**; Windows 11 has a TPM |
   | B (simplest) | `TPM = Hidden/Disabled` | EkPub record absent from hash | **breaks if TPM is ever re-enabled** (EK is burned in; even TPM Clear keeps it) |

   Save+exit.
3. **PXE boot the hashreport WinPE** (menu item `hashreport`, §26.7 in
   `research/autopilot/autopilot-report.md`). Capture the 4K hash.
4. **Check the captured hash** (decoder: `oa3hash.py decode <b64>`):
   - type **12 (UUID) must be the NEW value** `…E81EFF` — Lane A dead,
   - type **25 (EkPub)**: variant A → present but **different modulus**;
     variant B → **absent**. Either means Lane B dead.
   If either check fails, stop and re-dump — do not proceed to step 5.
5. **Submit** via `POST /api/mdm/hash` (`X-Ingest-Key`) with the unit's real
   serial `5CG8528JYH`, then dashboard **Re-check**.
   - **Expected: `unlocked`.** That is the bypass, empirically.
6. **Re-dump the 32 MB chip** and grep the UUID:
   - still `…E81EFF` → EC does NOT restore the UUID; recipe is durable.
   - back to `…E81EFE` → **the EC restored it** (EC-side mirror) → proceed to §5.

## 3b. Re-enable scenario (why variant A matters)

If the TPM is re-enabled in variant B, the burned-in dTPM EK reappears in the
hash and Lane B matches again → the device re-locks at the next OOBE. The UUID
patch does not help then (the match is either lane). Variant A is immune:
the PTT EK is a different key, so Lane B stays dead even with a working TPM.
Also note: TPM Clear never changes the EK, so variant B has no recovery knob.

## 4. Interpretation table

| Step-5 verdict | Meaning |
|---|---|
| `unlocked` | BYPASS WORKS — changed UUID + TPM disabled un-matches the device |
| `locked_other` | one lane still matches: re-check step 4 (EkPub absent? UUID new?) |
| `hash_invalid` | capture/upload problem, not a verdict |

## 5. Fallback if the EC restores the UUID

The EC holds its own copy (QN800 W25Q128JV, Sure Start + credential mirror,
doc `28-ec-credential-protocol.md`). Next lever, in order:
1. Find the canonical UUID copy in the EC flash and patch it there (re-flash
   both chips: patched EC first, then patched main).
2. If the EC recomputes rather than stores it: disable the EC→BIOS
   `HP_MUD` sync (CredentialServicesSmm set-handler, doc 28) by patching the
   SMC call in the EC image — heavier surgery.
3. If both fail: use the MAC (GbE region of the main flash) as the Lane-A kill
   instead — same experiment, MAC patch instead of UUID.

## 6. Safety notes

- The base image is the known-good KEEPBB keep-store build: password removed,
  HP_OA3 Windows key intact, BIOS 01.31. Only 1 byte changed.
- Windows will NOT auto-activate against the changed UUID's old key binding —
  the OA3 key stays in the MSDM table; activation is unaffected by the UUID.
- After the test, re-flash the unpatched `…AfterKEEPBBPatch.BIN` to restore
  the unit's true identity.

## 7. Recording

Append the result to `mutation-matrix/state.json` (`bypass_flash` section) and
this file, then update the roadmap pointer.
