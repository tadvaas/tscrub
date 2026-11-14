# 01 — Overview, lock taxonomy, and legal framing

## What "BIOS locked" actually means

"BIOS lock" is an umbrella for several different mechanisms. Diagnosing *which* one a
unit has determines the fix, so do this before anything else.

| Lock | What it protects | Where stored | Typical fix |
|---|---|---|---|
| **Setup / Administrator password** | Entering BIOS setup | UEFI NVRAM (SPI flash) | sysfs clear, master pw, or SPI reflash |
| **Power-on / System password** | Booting at all | UEFI NVRAM (SPI flash) | CMOS/service mode or SPI reflash |
| **HDD / SSD password (ATA security)** | The drive itself | Drive firmware (security mode) | Drive-level unlock (`hdparm` master, PSID, or replace drive) |
| **Computrace / Absolute** | Anti-theft persistence module | Platform module + UEFI | Vendor disable (needs account) or neutralise module |
| **TCG Block SID** | SED / Opal drives | Drive + firmware | PSID revert (already handled by tScrub as `BLOCKED`) |
| **Windows Autopilot / MDM** | OOBE provisioning | Microsoft tenant | Already handled by tScrub MDM check (`35_mdm.sh` + server) |
| **Apple EFI firmware password** | macOS boot / target disk mode | Platform NVRAM | **Apple only** (proof of purchase) |
| **Apple Activation Lock** | iCloud account lock | Apple servers | **Apple only** (proof of purchase) |

Key point: **HDD/SSD passwords, Block SID, MDM and Apple Activation Lock are NOT
fixed by a BIOS reflash.** They are separate systems. A firmware SPI reflash only
addresses the UEFI setup/power-on password row.

## The ITAD legal framing

This work is routine, legitimate ITAD salvage **provided ownership is documented**:

- **Ownership transfer.** Keep the donation / purchase / trade-in paperwork that
  transfers title to your company. This is the document that makes the work
  defensible and that several vendors demand.
- **Right to repair.** Unlocking firmware on hardware you own is legal in most
  jurisdictions. Keep it scoped to owned hardware only.
- **Vendor unlock programs.** Dell and HP will issue unlock codes / perform unlocks for
  a registered owner (often needs proof of ownership + sometimes a fee). Apple unlocks
  firmware/activation locks with a **proof-of-purchase** (invoice with serial). Lenovo
  does not issue master passwords — that's why SPI/EC work exists for ThinkPads.
- **Data protection.** The unlock only restores access to the *firmware*. Data
  sanitisation is still required before resale — that's tScrub's job *after* the
  unlock. The unlock itself does not read user data.
- **E-waste / WEEE framing.** Salvaging a locked-but-working laptop keeps it out of
  landfill. Where compliance teams ask "why unlock at all", the answer is: an
  unlocked device can be graded, wiped and resold; a locked one cannot be re-provisioned
  by the buyer.

## Evidence to keep with every unit

- Proof of ownership (invoice / donation deed / asset transfer).
- Serial number + service tag + board model.
- The tScrub diagnostics report (which already records `BIOSLock` + method + serials).
- After unlock: a fresh tScrub erasure report + certificate of destruction, so the
  full lifecycle (locked → unlocked → sanitised) is auditable.

## Risk register (summary)

| Risk | Severity | Mitigation |
|---|---|---|
| Bricked board from bad SPI flash | High | Double dump + SHA-256 verify; never flash unknown image |
| Wrong-voltage programmer kills chip | High | Always confirm 1.8 V vs 3.3 V before clipping |
| Cloned serial/MAC from another unit's dump | Medium | Patch own dump, don't cross-flash |
| Warranty voided | Low (decommissioned fleet) | Note in unit record |
| Legal challenge | Low (owned hardware) | Retain proof-of-ownership in CoC |

Next: [`02-non-invasive-methods.md`](02-non-invasive-methods.md).
