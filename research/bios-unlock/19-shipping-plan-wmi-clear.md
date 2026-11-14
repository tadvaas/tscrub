# 19 — Shipping plan: clearing an HP BIOS Setup Password from the appliance

Status: **implemented, not yet released** (2026-10-10). Plan-first per
`roadmap/product.md`; the code exists and is tested, and the hardware round trip
is partially done — see §G.

| piece | where |
|---|---|
| request frame encoder (pure C, byte-exact) | `product/board/shredos/modules/hp_biospw/hp_biospw_frame.h` |
| the narrow kernel module | `product/board/shredos/modules/hp_biospw/hp_biospw.c` |
| build into the rootfs overlay | `product/board/shredos/modules/build-into-overlay.sh` |
| the clear strategy (Layer 3, runs first on HP) | `product/src/38_bios_unlock.sh` |
| tests | `product/tests/test_bios_unlock.sh` (15 WMI cases), `product/tests/test_biospw_frame.sh` (13 byte-exact checks) |
| on-hardware round trip | `product/board/shredos/modules/hp_biospw/bench-round-trip.sh` |
Evidence base: docs 15–18, in particular the live result in
[`18-live-wmi-probing-results.md`](18-live-wmi-probing-results.md) §CONFIRMED.

## What we can now claim

On **2026-10-10**, on a bench unit whose Setup Password the operator knew
(`192.168.0.171`, HP EliteBook 830 G5 `5CG851960W`), the appliance's Linux sent
this frame and the BIOS cleared the password — confirmed by POST no longer asking
for it after a reboot:

```
[Setup Password]  [<utf-16/>]  [<utf-16/>hpinvent]
      name        new value      current password
        └──────────── 3 length-prefixed UTF-16 elements ────────────┘
```

carried by `wmi_evaluate_method("1F4C91EB-DC5C-460b-951D-C7CB9B4B8D5E", 0, 1,
&buffer, &out)` — the HP **SetBiosSetting** method, the same call Windows makes.

That is the first time an HP BIOS password has been removed from Linux on this
fleet, and it contradicts (correctly, and with evidence) the "HP ❌ cannot"
conclusion in doc 15.

## Why the existing sysfs path cannot be used

Established by live measurement, not inference:

1. **These HP machines expose no password object at all.** On `.163`/`.164`/
   `.165`/`.171` there is no `type=password` attribute, no `is_enabled`, and no
   `current_password`/`new_password` anywhere under
   `/sys/class/firmware-attributes`. So `38_bios_unlock.sh`'s discovery finds
   nothing to write — the current verdict (`no_interface` / `no_clear_path`) is
   *correct*.
2. **The driver's frame is malformed.** After the password was cleared — when no
   admin password is required at all — a sysfs write still failed with
   `hp_bioscfg: Returned error 0x4, "Invalid command type"`, while our raw frame
   succeeded. `hp_calculate_security_buffer("")` returns 4 bytes and
   `hp_populate_security_buffer()` writes 20 (`"<utf-16/>"`), so the buffer the
   driver hands the BIOS is short. This is an upstream bug worth reporting on its
   own (doc 16 §6–7).
3. **Nothing in the driver ever sends the password.** `new_password` is written
   and never consumed.

⇒ the clear has to be carried by **our own frame**, not by the driver's
password sysfs.

## Implementation

### A. A narrow kernel module (recommended, and the smallest possible surface)

`board/shredos/modules/hp_biospw/hp_biospw.c` — deliberately **not** a general
WMI-invocation tool like the research `hpraw`:

* one proc file, **write-only**, mode `0600`;
* the GUID, instance (0) and method id (1) are **hardcoded** — no parameters, so
  it cannot be used to call arbitrary methods;
* input is a fixed line format the shell helper writes, e.g.
  `name\0value\0credential` (or base64), parsed in the kernel into the
  length-prefixed element frame; element sizes validated (max 3 elements, each
  ≤ 512 bytes, total ≤ 4096) so a malformed frame cannot reach the BIOS;
* the password is **never** printed by the module (no `pr_info` of the buffer) and
  the buffer is `kfree`d;
* the call is synchronous and its status is returned to userspace (read back on
  the same open fd);
* ships in the image under `/lib/modules/6.18.0/extra/`, `insmod`ed on demand by
  the unlock flow and `rmmod`ed after (leaving it loaded is fine too, but see the
  unloading gotcha below).

Module signing is **not** enforced on this image — the research module loaded with
only `module verification failed: signature and/or required key missing - tainting
kernel` (verified live), so no signing step is required. `CONFIG_MODULES=y`,
`MODULE_UNLOAD=y`, `MODVERSIONS=y` and `Module.symvers` are all present.

Build integration: build the `.ko` against `output/build/linux-6.18` with the
Buildroot cross toolchain and install it from the overlay, the same way
`tscrub.sh` is installed today. (An alternative is a Buildroot package, but the
overlay route is one more `make` line and no Buildroot plumbing.)

### B. The shell side, in `product/src/38_bios_unlock.sh`

Keep the existing queue/transport (base64 password, TTL, purge-on-supersede) and
add a **second strategy** after the sysfs one:

```
bios_unlock::clear
  1. try sysfs (Dell/Lenovo)                     # unchanged
  2. if no password interface → try WMI (HP)     # new
```

The WMI strategy:

1. gate on the interface existing:
   `/sys/bus/wmi/devices/1F4C91EB-DC5C-460b-951D-C7CB9B4B8D5E*` present, and
   `insmod /lib/modules/6.18.0/extra/hp_biospw.ko` succeeds;
2. build `[Setup Password][<utf-16/>][<utf-16/><password>]` — note **both** the
   empty new value and the credential carry the `"<utf-16/>"` encoding prefix
   (doc 18 §CORRECTION);
3. send it, read the status back;
4. **corroborate** the result (see below) before reporting success;
5. `rmmod`, purge the password from memory as the existing flow already does;
6. report a verdict + detail to `/api/bios/unlock/result`.

### C. Verification — what "worked" is allowed to mean

This is the part that must be got right, because the measurement surprised us:
**a status of `0x00` does not prove a value was stored.** Writing
`[Ownership Tag][value]` returns `0x00` yet the tag reads back unchanged. So:

* **`0x00` on the clear frame means "the firmware processed the clear"**, and the
  verdict wording must say exactly that, not "the password is gone";
* **corroborate with the authorization flip**: with a password set, a write to a
  secure setting *without* a credential is refused (`0x06`); once the password is
  gone the same write is accepted (`0x00`). That flip was observed immediately
  after the clear and held on repeat. This is a real, reboot-free signal — probe
  it before and after the clear and require the transition;
* **the second, independent signal is confirmed and already wired in**: SMBIOS
  Type 24 "Hardware Security" → `Administrator Password Status`, which is exactly
  what our detection Layer 3 reads. Measured after the clear (doc 18 §INDEPENDENTLY
  CONFIRMED): `.171` `Disabled` versus `.164`/`.163` (still locked) `Enabled`. So on
  a cleared machine `BIOS_PASSWORD_STATUS` flips to `UNLOCKED` with method
  `dmidecode: type 24` on the **next boot**, with no new code — and that also proves
  the clear was persisted to NVRAM. 

So the pair is: **same boot** → the authorization flip (`0x06` → `0x00` on a
no-credential write to a secure setting); **next boot** → `dmidecode -t 24` says
`Disabled`. Require the first before reporting, and let the second appear
naturally in the report.

### D. Risks and gotchas

| risk | handling |
|---|---|
| **HP BIOS lockout mode** after repeated password attempts (HP's own docs: "can be cleared by rebooting") | the v1.11.45 rule already holds — never auto-retry a password. The WMI strategy sends the password **once** and reports. |
| reporting an unverified clear (a compliance problem — the certificate is a legal-ish artefact) | the corroboration step (C) is mandatory; the verdict wording must not over-claim. |
| password handling | never log it, never put it in a shell trace (`set -x` is forbidden around it), purge as the existing flow does, `0600` on the module interface. |
| module unload quirk | `rmmod` left `lsmod` showing `hpraw 12288 -2` with `/sys/module/.../holders` missing after a `/proc` write in the same shell line. Load/unload once per unlock, not chained with a write, and treat a stuck entry as cosmetic (a reboot clears it). |
| model coverage | proven on 830 G5 only. `.164` (820 G3) and `.163` (ZBook Fury G8) accept the *same* frame shape (their `Setup Password` behaves identically — doc 18 set 4) but their passwords are unknown, so the clear itself is unproven there. Gate the feature on the WMI GUID, not on a model list. |
| slot coverage | only the `Setup Password` slot has been exercised. `Power-On Password` is a plausible second slot with the same mechanism; verify before claiming it. HP Sure Admin/SPM is a **different** mechanism (`<BEAM/>`, signed blobs) and is out of scope (`SPM is_enabled=0` on our units). |

### E. Tests

* **Unit (Mac, no hardware):** byte-exact assertions on the frame builder
  (`[name][value][credential]`, `<u16 byteLen><UTF-16LE>` per element, empty string
  = `02 00 00 00`), the status→verdict mapping, and the gate logic. Extend
  `product/tests/test_bios_unlock.sh` (the existing suite is the model).
* **Server/dashboard:** extend `unlock_verdict()` in `marketing/server/bios_unlock.php`
  for the new detail wordings and add cases to
  `research/bios-unlock/test_unlock_verdict.php`.
* **On hardware (bench checklist, one unit):** (1) `dmidecode -t 24` and
  `BIOS_PASSWORD_STATUS` before; (2) stage an unlock from the dashboard with the
  *correct* password; (3) status `0x00` and the auth-flip corroboration;
  (4) reboot → POST must not ask for the password and F10 must open;
  (5) `dmidecode -t 24` after; (6) confirm the dashboard shows the new verdict and
  the report/manifest carries it.

### F. Open questions

1. ~~Does SMBIOS Type 24 flip after a clear?~~ **Answered: yes.** `.171` reads
   `Administrator Password Status: Disabled` after the clear; `.164`/`.163` (still
   locked) read `Enabled`. It reflects POST, so it confirms on the next boot, and
   our detection Layer 3 already consumes it. (`.165` did not answer telnet in
   time to be included in the comparison; re-check when convenient.)
2. Does the same frame clear `Power-On Password`?
3. Should we also support **changing** (rather than clearing) a password? The frame
   supports it — same shape with a non-empty new value, which doc 18 showed is
   accepted. Useful when a unit must be re-passworded before resale.
4. Worth reporting upstream? Two `hp-bioscfg` bugs are now backed by live
   evidence: the `hp_populate_security_buffer()` size mismatch (0x04 from the
   driver even with no password set) and the inert `new_password` attribute.
5. Does the *report/certificate* have a surface where "BIOS password removed"
   should appear (it currently records `BIOSLock` + `BIOSLockMethod`)? That is a
   product/compliance decision, not a research one — and per the certificate work
   it must be phrased so it cannot over-claim.
