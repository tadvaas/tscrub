# 05 — Per-vendor cheat sheet

Quick reference for what works per vendor. Always confirm against the exact model's
service manual — generations differ.

| Vendor | Master password? | Service mode? | Typical fix when lost | Notes |
|---|---|---|---|---|
| **Dell** (Latitude/Precision/XPS) | ✅ service-tag based (bios-pw.org) + suffix `-595B` trick on older models | ✅ service-mode jumper (`PSWD`) | sysfs clear → master pw → service mode → SPI | `cctk` for fleet config |
| **HP** (EliteBook/ProBook/ZBook) | ✅ after 3 fails it shows a hash → generator | ✅ clear-password jumper/pads on some | hash generator → sysfs (`hp-wmi`) → SPI | BCU for config |
| **Lenovo** (ThinkPad) | ❌ none | ⚠️ some models (pads/pins) | service mode → **security EEPROM / SPI reflash** | SVP often in a separate EEPROM, not the BIOS flash |
| **Lenovo** (IdeaPad/consumer) | ❌ none | ❌ | SPI reflash | simpler chips, good first reflash candidate |
| **Acer** | ⚠️ some legacy | ✅ `CLRPWD` pads | CMOS/jumper → SPI | consumer boards usually easy to reflash |
| **ASUS** | ⚠️ some legacy | ✅ jumper/pads | CMOS/jumper → SPI | |
| **MSI** | ❌ | ⚠️ | SPI | mostly gaming boards |
| **Toshiba / Dynabook** | ⚠️ some models | ❌ | SPI (or vendor) | |
| **Fujitsu** | ⚠️ some models | ⚠️ | SPI | |
| **Samsung** | ❌ | ⚠️ | SPI | |
| **Apple (Intel)** | ❌ none public | ❌ | **Apple only** (proof of purchase) | SPI reflash is high-risk / T2 complicates it |
| **Apple (M-series)** | ❌ none | ❌ | **Apple only** | Activation Lock / firmware pw are Apple-server bound |

## Route by vendor

- **Dell / HP business:** try master-password generator and vendor support **first** —
  these are often solvable without opening the case, and both run formal unlock
  programs for the owner of record.
- **Lenovo:** no shortcuts — service mode if the model has it, else EEPROM/SPI reflash.
  Budget extra bench time for ThinkPads.
- **Consumer boards (Acer/ASUS/MSI/…):** cheap to reflash, make good first practice
  units for the SPI workflow.
- **Apple:** separate queue — send to Apple with proof-of-purchase, or dispose via an
  Apple-authorized recycler. Do **not** attempt SPI reflash on Apple hardware.

## Suggested batch policy

1. **Dell / HP** → software track (`02`) + vendor unlock program.
2. **Lenovo / consumer** → bench track (`03` → `04`).
3. **Apple** → vendor track only.

This keeps the fastest-moving units flowing and reserves bench time for the ones that
actually need it.
