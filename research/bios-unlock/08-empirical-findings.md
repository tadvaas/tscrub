# 08 — Empirical findings (verified on live hardware)

Results from probing the fleet's tScrub appliance directly (telnet root shell).
Each entry is dated and tied to a serial so it's reproducible.

## 2026-10-03 — HP EliteBook 830 G5, serial `5CG8403V62`, kernel 6.18.0

### Lock state
```
Handle 0x000B, DMI type 24, 5 bytes
Hardware Security
        Power-On Password Status: Disabled
        Keyboard Password Status: Disabled
        Administrator Password Status: Enabled      ← LOCKED
        Front Panel Reset Status: Disabled
```

### sysfs password clear — **DEAD on this hardware**

| Check | Result |
|---|---|
| `/sys/class/firmware_attributes` | ❌ does not exist |
| `/sys/devices/platform/hp-wmi` | ❌ does not exist |
| `hp_wmi` module | ❌ `modprobe: FATAL: Module hp_wmi not found` |
| `bios_password` / `new_bios_password` in `/sys` | ❌ none |
| `HPQ6001:00` WMI device | present, but no password attribute + no driver bound |

### Conclusion
- `product/src/38_bios_unlock.sh` `bios_unlock::clear` has **nothing to write to** on
  the HP EliteBook 830 G5 — it returns `unsupported` ("no writable admin-password
  attribute found").
- The **remote sysfs clear is Dell/Lenovo-only** in practice. The HP `hp-wmi` Layer 2
  fallback is dead code on the 6.18 kernel (module not even shipped in the image).
- **HP unlock is pre-boot only**: read the i-code at the BIOS password prompt → generate
  the master password → enter it in setup → clear. Or HP SMC (support, proof of
  ownership), or SPI reflash.

### Implication for the fleet
- ~51 of the 61 locked units are HP Insyde → use the **i-code hash method** (§07-1),
  which is a manual pre-boot step. tScrub's role for HP is **detection + evidence**, not
  clearing.
- The sysfs-clear automation is only relevant to future **Dell/Lenovo** volume.

## 2026-10-03 — HP EliteBook 830 G5 `5CG8403V62`: no hash displayed

At the BIOS password prompt, 3 wrong attempts produce only:

```
System Locked. Press the power button to restart the system.
```

No `i <8 digits>` unlock code appears. BIOS `Q78 Ver. 01.03.00` (2018).

**Conclusion:** the public i-code generator route does **not** apply to the 830 G5.
Options are HP **SMC** (support, proof of ownership) or **SPI reflash**. The i-code
method is downgraded to [VERIFY] for the older 2016-era models (820 G3, ProBook
650 G2-G4, ZBook 15 G3).

## Open question (to verify on the next Dell/Lenovo unit)
- Does `firmware_attributes` `current_password` accept a *master/recovered* password
  (Dell service-tag master), enabling the fully-automated loop there? (Not applicable
  to HP.)

## 2026-10-10 — UPDATE: why there was "nothing to write to" (see doc 14)

The 2026-10-03 conclusion — "`/sys/class/firmware-attributes` does not exist on the
830 G5" — was re-tested on three units and refined:

- The class **directory exists but is empty**; the hp-bioscfg device is **orphaned at
  `/sys/devices/hp-bioscfg/`** because `hp/` (and `dell/`) are linked *before*
  `firmware_attributes_class.o` in `drivers/platform/x86/Makefile` and use the same
  initcall level (upstream v6.18; still true in master). Lenovo (`lenovo/`, linked
  after) was the only vendor that worked.
- HP's password objects are `authentication/Setup Password` (`role=bios-admin`) and
  `authentication/Power-On Password`, with **`is_enabled`** (no `is_password_set`).
- HP still cannot be cleared from Linux: the interface only *authenticates* (the
  current password is an auth token for writing other settings), there is no
  password-reset WMI, `new_password_store()` passes `is_current=true` in v6.18, and
  a blank new password is refused — yet the write still returns success, so
  `is_enabled` is the only tell. Pre-boot (SMC/SPI) remains the HP route.

Full detail: `research/bios-unlock/14-firmware-attributes-interface.md`.
Runbook: `ops/bios-unlock.md`.

