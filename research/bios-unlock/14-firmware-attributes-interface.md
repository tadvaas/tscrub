# 14 — The firmware-attributes interface, and why HP "Not supported" (2026-10-10)

Live telnet investigation of the three appliances that were online
(`192.168.0.165` 5CG8528HBN 830 G5, `192.168.0.163` CND23801Q4 ZBook Fury G8,
`192.168.0.164` 5CG8130JYG 820 G3) after the dashboard reported *"Not supported
on this machine — no writable admin-password attribute found"* for a correct
password.

## 1. The message was a discovery failure, not a wrong password

`bios_unlock::clear` never got as far as using the password:

| check | result on all three machines |
|---|---|
| `/sys/class/firmware-attributes/` | exists but **empty** |
| `/sys/devices/platform/hp-wmi/bios_password` | absent (node removed upstream in 6.x) |
| `dmidecode -t 24` | `Administrator Password Status: Enabled` (detection still OK) |

## 2. Root cause — the class device is orphaned

`hp-bioscfg` **is** bound (WMI GUID `5FB7F034-2C63-45E9-BE91-3D44E2C707E4`) and
the whole attribute tree exists — at `/sys/devices/hp-bioscfg/`, with **no**
`/sys/class/firmware-attributes/hp-bioscfg` symlink.

`drivers/platform/x86/Makefile` (upstream v6.18; sha256
`50140d740bbe11fb71679a4bf10e268b69f7226923a8562d1fe958431730b058` — byte-identical
to our tree) links `dell/`(L46) and `hp/`(L57) *before*
`firmware_attributes_class.o`(L62), and all three use `module_init`
(= `device_initcall`, same level ⇒ link order decides). So `hp_init()` /
`sysman_init()` run first, `device_create(&firmware_attributes_class, …)` finds
`class_to_subsys() == NULL`, and the kobject lands in `/sys/devices` (the
device kset) with no class link. Only `lenovo/` (L69) is ordered correctly.

Upstream master is unchanged (same Makefile order, same `module_init`), so this
is a latent upstream bug that only bites when everything is built-in (`=y`),
which is exactly our image. **It breaks Dell too** — `dell-wmi-sysman` is in the
same position — so the "Dell/Lenovo" remote clear was in practice Lenovo-only.

Fix: Buildroot patch `board/shredos/patches/linux/0001-firmware-attributes-class-register-early.patch`
(moves the object to the top of the Makefile) + `BR2_GLOBAL_PATCH_DIR="board/shredos/patches"`
in `configs/tscrub_defconfig`. Verified with `make linux-dirclean && make linux-extract && make linux-patch`:
`obj-$(CONFIG_FW_ATTR_CLASS) += firmware_attributes_class.o` is now line 13,
before `dell/`(54), `hp/`(65), `lenovo/`(76). Kernel commit `33bef223` on the
build host.

## 3. HP's layout is different anyway

`/sys/devices/hp-bioscfg/` (and, once the class is fixed,
`/sys/class/firmware-attributes/hp-bioscfg/`):

```
attributes/        ~200 BIOS settings (strings/ints/enums) — includes policy
                   knobs like "At least one number is required in Administrator
                   and User passwords" (no current_password/new_password files)
authentication/
    Setup Password/      role=bios-admin, mechanism=password, is_enabled=1
    Power-On Password/   role=power-on,  is_enabled=0
    SPM/                 role=spm (Sure Admin KEK/SK)
```

Each `authentication/<obj>/` has WO `current_password` + `new_password`, RO
`is_enabled`/`role`/`mechanism`/`min_password_length`/`max_password_length`.
Note there is **no `is_password_set`** — the source names the variable
`is_password_set` but the sysfs file is `is_enabled`.

So even with the class fixed, the old scan (`<driver>/attributes/*`, looking for
`current_password`/`new_password`/`is_password_set`) would miss HP entirely.

## 4. HP cannot clear the password from Linux

- `biosattr-interface.c` `hp_set_attribute()` uses `password_data.current_password`
  (or the SPM `auth_token`) purely as an **auth token** for
  `HP_WMI_SET_BIOS_SETTING_GUID` when writing *other* settings.
- `passwdobj-attributes.c` has **no** password-set WMI call, and in v6.18
  `new_password_store()` passes `is_current=true` — identical to
  `current_password` — so `.new_password` is never populated and nothing consumes
  it (upstream master changed the flag to `false`, plus guarded
  `validate_password_input()` against a 0-length read `buf[length-1]`).
- `validate_password_input()` rejects a value shorter than `min_password_length`
  (4 here), so a blank new password is refused — but `store_password_instance()`
  returns `count` when the error code is non-negative, so **the write still
  reports success to userspace**.

Confirmed live on 5CG8528HBN: `printf hpinvent > …/Setup Password/current_password`
→ rc 0; `printf "" > …/new_password` → rc 0; `printf "\n" > …/new_password` → rc 0;
`is_enabled` remains `1` before and after. No dmesg output.

## 5. What shipped

1. Kernel patch (above) — restores Dell/Lenovo.
2. `product/src/38_bios_unlock.sh` — `bios_unlock::_fa_devices` enumerates the
   class *and* orphaned trees; the scan now covers `attributes/` **and**
   `authentication/`; `_verify_cleared` accepts `is_password_set`,
   **`is_enabled`** or `current_value`; details distinguish "no interface
   published by the kernel" / "read-only interface" / "still set after clear".
3. Tests 18 → 32 in `product/tests/test_bios_unlock.sh`
   (HP `authentication/` + `is_enabled`, orphaned tree, read-only, no-interface).
4. **Release v1.11.45** — the clear itself: `new_password` is a single `\n`
   rather than an empty string (a 0-byte write can be dropped before the driver's
   store, and every driver strips a trailing newline), and a **rejected** write is
   classified from the shell's own stderr errno — `Permission denied` = wrong
   password, `Invalid argument` = password policy, `Operation not supported` =
   the firmware cannot set/clear, `Operation not permitted` = needs
   `CAP_SYS_ADMIN`. Tests 32 → 47.
5. **Dashboard wording** (server + site only, no release needed) — `failed` used
   to read as one generic "Clear failed" with a retry box even when the password
   was right and the firmware simply has no reset path. The server now derives a
   machine-readable `verdict` from result + detail
   (`marketing/server/bios_unlock.php` → `unlock_verdict()`, returned by
   `GET /api/bios/unlock`) and the Devices modal renders *Wrong BIOS password* /
   *Rejected by the firmware password policy* / *Cannot be cleared from Linux* /
   *Firmware cannot set or clear passwords* / *Password interface is read-only* /
   *No BIOS password interface*, offering a retry only where a different password
   could help. `bios_unlock.detail` widened 255 → 512 — the v1.11.45 explanation
   is 253 characters, and 284 with an absolute sysfs path, so it was being
   clipped mid-sentence. Rows resolved earlier are classified on read, so the
   ambiguous v1.11.44 wording ("… wrong password, or this firmware exposes no
   reset path from Linux") now correctly resolves to *no clear path* rather than
   *wrong password*. See `ops/bios-unlock.md` §4.

Live result with the new code (both machines), instead of "unsupported":

```
DEVICES: /sys/devices/hp-bioscfg
RESULT:  failed
DETAIL:  password still set after clear
         (sysfs: hp-bioscfg/authentication/Setup Password) — wrong password,
         or this firmware exposes no reset path from Linux
```

## 6. Live re-verification on the rebooted fleet (2026-10-10, v1.11.45)

Four bench HP units were rebooted onto v1.11.45 and re-tested end to end.

| host | model | attrs | script | `is_enabled` | staged clear | verdict |
|---|---|---|---|---|---|---|
| .165 5CG8528HBN | EliteBook 830 G5 | 226 | v1.11.45 | 1 | claimed 3 s / reported 2 s | `no_clear_path` |
| .163 CND23801Q4 | ZBook Fury G8 | 290 | v1.11.45 | 1 | — | same path |
| .164 5CG8130JYG | EliteBook 820 G3 | 193 | v1.11.45 | 1 | claimed 1 s / reported 1 s | `no_clear_path` |
| .170 CND2370DFJ | — | — | **v1.11.43** | — | — | not rebooted, mid-erase |

The kernel patch is confirmed on all three rebooted units:
`/sys/class/firmware-attributes/hp-bioscfg` →
`/sys/devices/virtual/firmware-attributes/hp-bioscfg`, and the orphan
`/sys/devices/hp-bioscfg` is gone.

### The password is never sent to the BIOS at all

Attributing each sysfs write to kernel activity (diffing `dmesg` around it):

| write | exit | kernel / WMI activity |
|---|---|---|
| `authentication/Setup Password/current_password` = anything | 0 | **none** — the driver only caches it, no WMI call |
| `authentication/Setup Password/new_password` = `\n` | 0 | **none** — nothing is sent (§4, the 6.18 `is_current=true` bug) |
| `attributes/<setting>/current_value` = its own current value | 1 (`EINVAL`) | **WMI call made** — the BIOS rejects it |

So on HP the operator's password is never evaluated: any value returns success.
`INVALID_BIOS_AUTH` ("Invalid BIOS administrator password") never appears in the
kernel log, which is positive evidence that no password was ever presented —
"wrong password" is not merely untestable from Linux here, it is not the failure.

### The errno is uninformative on HP — the kernel log is the real signal

`hp_wmi_error_and_message()` (bioscfg.c) maps the BIOS status byte to errno, and
the mapping is lossy: `INVALID_CMD_TYPE` (0x4) and `INVALID_CMD_PARAM` (0x6) both
become `-EINVAL`, and so does `INVALID_BIOS_AUTH`. Measured on the fleet by
writing a setting's own current value back:

| host | errno | dmesg |
|---|---|---|
| .165 830 G5 | `EINVAL` | `hp_bioscfg: Returned error 0x6, "Invalid command parameter"` |
| .164 820 G3 | `EINVAL` | `hp_bioscfg: Returned error 0x4, "Invalid command type"` |
| .163 ZBook Fury G8 | `EINVAL` | `hp_bioscfg: Returned error 0x4, "Invalid command type"` |

`INVALID_CMD_TYPE` means "this BIOS does not implement that WMI command type";
`INVALID_CMD_PARAM` means "the parameter block is unacceptable". Neither is an
authentication result. **On HP, `EINVAL` must not be read as wrong-password or
password-policy evidence** — only the kernel message distinguishes the cases.

Consequences:

1. The `no_clear_path` verdict for HP is correct, and the detail already says the
   password cannot be validated there.
2. A stronger, evidence-based message is available without any kernel change: the
   appliance runs as root and can read the BIOS's own reason from `dmesg` — e.g.
   *"the firmware rejected the write: 'Invalid command type' — this BIOS does not
   support changing settings from the OS; no password was evaluated"*.
3. Writing a setting's **own current value** is a safe authentication probe (it
   changes nothing), so a future release could positively verify a password
   wherever the driver does forward writes. It cannot help on HP, because no
   write is forwarded at all.
4. The earlier `errno` matrix is Dell/Lenovo-only: it is only meaningful when the
   driver actually forwards the value to the firmware.

## 7. Still open

- Verify the Dell path on real Dell hardware once the patched kernel ships
  (`/sys/class/firmware-attributes/dell-wmi-sysman/attributes/AdminPassword/`
  should now appear and the clear should re-read `is_password_set=0`).
- HP remains pre-boot only (HP SMC / SPI). A future option is driving
  `HP_WMI_SET_BIOS_SETTING_GUID` directly with an empty "Setup Password" setting
  (what HP's Windows BCU does), but that needs a compiled helper, not sysfs.
