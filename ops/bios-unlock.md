# Remote BIOS unlock — how it works, and how to diagnose it

The dashboard stages a BIOS admin/setup password for a serial; the appliance
polls `GET /api/bios/unlock/pending`, writes the clear, and POSTs the result to
`/api/bios/unlock/result`. Appliance side = `product/src/38_bios_unlock.sh`
(write) + `product/src/36_bios.sh` (detection); server side =
`marketing/server/bios_unlock.php`.

## 1. The write surface: the kernel's `firmware-attributes` class

Linux exposes removable BIOS passwords through one sysfs class:

```
/sys/class/firmware-attributes/<driver>/attributes/<attr>/current_password   (WO)
/sys/class/firmware-attributes/<driver>/attributes/<attr>/new_password       (WO)
/sys/class/firmware-attributes/<driver>/attributes/<attr>/is_password_set    (RO)
```

To clear: write the **current** password to `current_password`, then an **empty**
value to `new_password`. Re-read `is_password_set` (or `is_enabled`) to confirm.

Vendor differences:

| driver | vendor | password objects live under | "still set" signal | can clear? |
|---|---|---|---|---|
| `dell-wmi-sysman` | Dell | `attributes/` | `is_password_set` | yes |
| `think-lmi` | Lenovo | `attributes/` | `is_password_set` | yes |
| `hp-bioscfg` | HP | **`authentication/`** | **`is_enabled`** | **no** |

`hp-bioscfg`'s `authentication/` objects (`Setup Password` = role `bios-admin`,
`Power-On Password` = role `power-on`, `SPM`) accept writes but only *cache* the
value: `current_password` is used as an auth token for changing other BIOS
settings, there is no password-reset WMI call, and `new_password_store()` passes
`is_current=true` (same as `current_password`) so the new value is never applied.
Verified live on an 830 G5: writes return `0` and `is_enabled` stays `1`.
**HP unlock is therefore pre-boot only** (HP SMC with proof of ownership, or an
SPI reflash — see `research/bios-unlock/`).

## 2. Known trap: the class device can be orphaned (kernel ordering)

`hp-bioscfg` and `dell-wmi-sysman` are linked **before**
`firmware_attributes_class.o` in `drivers/platform/x86/Makefile` and both use
`module_init` (= `device_initcall`), so initcall order follows link order and the
driver's `device_create(&firmware_attributes_class, …)` runs *before* the class is
registered. `class_to_subsys()` returns `NULL`, the kobject is added to
`/sys/devices` instead, and the class directory looks **empty** even though the
interface exists.

Symptoms: `/sys/class/firmware-attributes/` is empty, but the populated tree
exists at `/sys/devices/<driver>` (e.g. `/sys/devices/hp-bioscfg/`).

Diagnose on an appliance:

```sh
ls -la /sys/class/firmware-attributes/
find /sys/devices -maxdepth 3 -name '*bioscfg*' -o -name '*sysman*'
readlink -f /sys/bus/wmi/devices/5FB7F034-2C63-45E9-BE91-3D44E2C707E4-*/driver
```

**Fixed** by the `board/shredos/patches/linux/0001-firmware-attributes-class-register-early.patch`
Buildroot patch (kernel commit `33bef223` on the build host): the object is moved
to the top of `drivers/platform/x86/Makefile`, so the class is always registered
first. Only Lenovo (`lenovo/`, linked after the class) worked before this.
`bios_unlock::clear` also scans orphaned trees (`BIOS_FA_ORPHAN_GLOB`, default
`/sys/devices/*`) so unpatched kernels/devices still work.

## 3. Reading the result

`bios_unlock::clear` sets `BIOS_UNLOCK_RESULT` / `BIOS_UNLOCK_DETAIL`:

| result | meaning |
|---|---|
| `cleared` | the slot re-read as *not set* after the write |
| `failed` | write accepted but the slot still reads as set (wrong password, or the firmware has no reset path — HP) |
| `unsupported` | no writable attribute was found; the detail says whether the kernel published no interface at all or the interface is read-only |

Sanity checks on an appliance (no release needed):

```sh
cd /tmp && curl -kfsS -o ts.sh https://tscrub.com/downloads/tscrub.sh
n=$(grep -n '^parse_args ' ts.sh | head -n1 | cut -d: -f1)
head -n $((n-1)) ts.sh > lib.sh
/usr/bin/env bash -c '. /tmp/lib.sh >/dev/null 2>&1; bios_unlock::_fa_devices; \
  bios_unlock::clear hpinvent; echo "$BIOS_UNLOCK_RESULT / $BIOS_UNLOCK_DETAIL"'
```

Unit tests: `product/tests/test_bios_unlock.sh` (Dell sysfs, HP `authentication/`
+ `is_enabled`, orphaned tree, read-only interface, no-interface, slot priority,
re-verify, JSON parsing).

## 4. Telling "wrong password" apart from "this firmware cannot clear"

A clear that does not succeed arrives at the dashboard as `failed`, but the two
cases need opposite action — one is retried with a different password, the other
can never succeed from Linux. So the **server** reduces `result` + `detail` to a
single `verdict` (`marketing/server/bios_unlock.php` → `unlock_verdict()`), which
`GET /api/bios/unlock` returns alongside the detail and
`dashboard/devices.html` renders. The wording lives server-side on purpose:
one place owns the operator-facing vocabulary, and it works for appliances
already in the field (v1.11.44's wording is ambiguous and contains the words
"wrong password", so the no-clear-path family is matched first).

| verdict | how it is produced | dashboard | retry offered |
|---|---|---|---|
| `cleared` | the slot re-read as not set | green *Cleared* | — |
| `wrong_password` | a **rejected write**: `write error: Permission denied` (Dell `map_wmi_error(3)` → `-EACCES`) | red *Wrong BIOS password* | yes |
| `policy` | `write error: Invalid argument` | red *Rejected by the firmware password policy* | yes |
| `no_clear_path` | writes **accepted** (`BIOS_UNLOCK_WRITE_ERR` empty) but the flag is unchanged — or the detail says *no clear path / no reset path from Linux* | amber *Cannot be cleared from Linux* | **no** |
| `not_supported` | `write error: Operation not supported` | amber *Firmware cannot set or clear passwords* | no |
| `needs_privilege` | `write error: Operation not permitted` | amber *Not permitted* | no |
| `read_only` | the `open()` failed — `sh: <path>: Permission denied`, i.e. no write path | grey *Password interface is read-only* | no |
| `no_interface` | no attribute at all (class dir empty, no orphaned tree) | grey *No BIOS password interface* | no |
| `failed` | anything not classified above | red *Clear failed* | yes |

Anything that stops matching degrades to `failed` with the detail shown verbatim,
so no information is lost — but if you reword `_write_error_reason()` in
`product/src/38_bios_unlock.sh`, keep this classifier in step. An appliance may
also send an explicit `verdict` field, which always wins.

Harness: `research/bios-unlock/test_unlock_verdict.php` (21 assertions over the
real v1.11.44 **and** v1.11.45 wordings — run it on the server beside
`bios_unlock.php`). It is `research/`-local (gitignored), like the other server
smoke tests.

> Note: `detail` is `VARCHAR(512)`. The v1.11.45 no-clear-path explanation is
> 253 characters — it only just fitted the original `VARCHAR(255)`, and with an
> absolute sysfs path it is 284 and was truncated mid-sentence.
