# 17 — Out-of-the-box ways to reach the HP BIOS password from Linux

> ⚠️ **Partly superseded — see `18-live-wmi-probing-results.md`.** The capability
> survey and the recommended route (a small module that calls the WMI method
> ourselves) are confirmed and were used. Two hypotheses below were **wrong**,
> and live testing replaced them:
> * **§1 / avenue 1:** method 2 (`WSSD`) is *not* a usable no-credential setter. It
>   returns `0x00` even for a setting name that does not exist, and changes
>   nothing. It proves nothing.
> * **§1 "Why this matters":** `0x05` is not only the AML's element-count check —
>   the BIOS returns it for an *unknown setting name* too, and `0x06` is the
>   refusal of a *known* setting.
> The conclusion after live testing is that this HP generation publishes no
> password object through the BIOS-settings interface at all, so there is no
> setting name to clear — see doc 18.

Research date 2026-10-10. Builds on docs 15 (vendor comparison) and 16 (the wire
format read out of the firmware's AML). Doc 16 established that the Linux driver
frames the request **correctly** and that the BIOS's `0x06` on that path is HP's
*access-refused* status — so the remaining problem is not "Linux can't" but
"nothing in the driver ever *sends* the password, and the credential we have is
refused".

This document is the catalogue of techniques that can get around that, with
what each one needs and where it is blocked.

## What the appliance can actually do

Measured on `.165` (v1.11.45):

| capability | state | consequence |
|---|---|---|
| `CONFIG_MODULES=y`, `CONFIG_MODULE_UNLOAD=y`, `CONFIG_MODVERSIONS=y` | ✅ | **we can build and `insmod` an out-of-tree module** |
| `Module.symvers` in the kernel build dir | ✅ | out-of-tree builds get matching symbol versions |
| `insmod` / `modprobe` / `lsmod`, `/lib/modules/6.18.0` | ✅ | module loading works (none loaded today; everything is built-in) |
| `CONFIG_DEVMEM=y`, `CONFIG_STRICT_DEVMEM=y`, `/dev/mem` root-only | ⚠️ | raw MMIO is possible but limited to non-restricted regions |
| `CONFIG_KALLSYMS=y` | ✅ | kprobes / symbol work is possible |
| WMI bus method files (`/sys/bus/wmi/devices/<guid>/methodN`) | ❌ **absent** | 6.18's `wmi.c` only exposes a read-only `setable` flag; the old `method` file is gone, so **there is no stock userspace path to invoke a WMI method** |
| HP WMI devices present | ✅ 14, incl. `1F4C91EB…` (obj **BS**), `322F2028…` (**PW**), `5FB7F034…` (**PV**, bound to `hp-wmi`) | the interfaces exist; only the plumbing is missing |

## The avenues, ranked

| # | technique | needs | status |
|---|---|---|---|
| 1 | **`acpi_call` module** — invoke `\_SB.WMIB.WMBS` ourselves with an arbitrary buffer | build+load one small out-of-tree module | **ready to try — primary route** |
| 2 | Purpose-built module (same idea, narrower) | build+load a module | ready, more work than #1 |
| 3 | Patch `hp-bioscfg` (`new_password_store` → `hp_set_attribute`) | kernel rebuild (~19 min) | ready, and the *only* path that ships in the product |
| 4 | HP's own tooling (`Clear-HPBIOSSetupPassword`) | WinPE + CMSL | ready (docs 15 §6) |
| 5 | Set a known password at F10, then clear it over the wire | 2 minutes at a machine | ready — **this is the experiment that settles framing vs credential** |
| 6 | `CONFIG_ACPI_CUSTOM_METHOD` (override an AML method so an existing trigger does the work) | kernel config change + rebuild | possible |
| 7 | Write the BIOS mailbox (`PUWB` @ `0x97F68000`) via `/dev/mem` | unrestricted MMIO + handshake registers | risky/blocked (`STRICT_DEVMEM`) |
| 8 | Legacy write path `HVWA → HVWC(2) → VWBC` on the `5FB7F034` (PV) interface | reverse-engineering the legacy command numbers | unexplored |

## 1. `acpi_call` — the primary route

Why it is the right tool: it lets userspace call **any** ACPI method with integer,
string **and buffer** arguments (`echo '<method> {0x..,0x..}' > /proc/acpi/call`).
That gives us three things `hp-bioscfg` cannot:

1. **Call `WMBS` with `method_id` of our choosing.** The driver only ever uses
   method_id 1 (`WSBS`, the *credentialed* setter). The AML also defines
   `method_id 2 → WSSD` (2 elements, **no credential**, mailbox command `0x03F3`)
   and `3 → WFTE` (a stub). Method 2 has no caller anywhere in the kernel.
2. **Send an arbitrary buffer**, so we can vary the element count/content and watch
   the status codes change — i.e. *probe the protocol* instead of guessing.
3. **Bypass the driver's bugs entirely** (`is_current=true`, the 16-byte
   heap overflow with an empty credential — see doc 16 §6).

Harness sketch (once the module is loaded):

```sh
# 2-element, no-credential set of a benign setting, via method_id 2 (WSSD)
buf=$(printf '%s' 'Bluetooth' | ...utf16 with u16 length...; printf ...)
echo "\_SB.WMIB.WMBS 0 2 {$buf}" > /proc/acpi/call
cat /proc/acpi/call            # the firmware's status: 0x00, 0x04, 0x05, 0x06 …

# 3-element, credentialed (same as hp-bioscfg, for comparison)
echo "\_SB.WMIB.WMBS 0 1 {$buf}" > /proc/acpi/call
```

The buffer is the doc-16 format: `<u16 byteLen><UTF-16 text>` repeated —
`[name][value]` for method 2, `[name][value]["<utf-16/>"+password]` for method 1.

### Why this matters even without the right password

`WSSD` (method 2) exists precisely because *some* settings don't need the admin
credential. If one of those returns `0x00` we will have, for the first time:

- **proof the mailbox path works end-to-end from Linux** (status 0, value changed),
- **a validated framing** to compare the password request against,
- and a way to tell "our buffer is malformed" (`0x04`/`0x05`) from "the credential
  was refused" (`0x06`) with confidence rather than inference.

That is the missing link between doc 16's static analysis and a working clear.

## 3. The driver patch (what ships)

The product fix is still doc 16 §7: `new_password_store()` should call
`hp_set_attribute(kobj->name, new_pw)` so an empty write becomes HP's own
`SetBIOSSetting(name, "", currentpassword)` clear. Two facts from this session
make that patch worth writing now:

- **Nothing anywhere consumes `password_data[i].new_password`.** Verified by grep:
  the only references are the store that writes it, the `memset` in
  `clear_passwords()`, and the struct field. Upstream's `is_current=false` fix is
  therefore *also* a dead end on its own — the attribute is decorative in mainline
  too, so this is a genuine upstream gap, not just ours.
- The framing is already correct (doc 16 §3–4), so the patch is the whole
  remaining delta on our side.

## 5. The experiment that settles everything

Set a **password we choose** at F10 on one bench unit (e.g. `tscrub1234`), then
drive the clear over the wire. Two outcomes, both informative:

- **`0x00` and `is_enabled → 0`** → the Linux path works; `hpinvent` was simply the
  wrong password on those units, and doc 15's "HP ❌" is dead.
- **`0x06` with a password we *know* is correct** → the credential is *not* the
  problem and our framing/`UTF_PREFIX` handling is subtly wrong. That would be the
  most valuable negative result available, because doc 16's whole argument rests on
  `0x06` meaning "access refused", and that assumption has a documented tension
  with `hp_bioscfg`'s own table (which calls 6 "Invalid command parameter").

Either way the next step is decided by evidence instead of inference.

## 7. Risky/unexplored, recorded for completeness

- **`/dev/mem` on the BIOS mailbox.** `PUWB` is a field in
  `OperationRegion (… SystemMemory, 0x97F68000 …)` — i.e. RAM the BIOS reserved for
  the WMI mailbox. Writing the buffer there from Linux does **not** help on its own:
  the handshake (`GSWS`: wait `APMS`, set `APME`, write `SSWP`, stall, wait) runs in
  ACPI context, so the method still has to be invoked. `CONFIG_STRICT_DEVMEM=y`
  also restricts what `/dev/mem` will touch. Not a shortcut.
- **The legacy PV interface.** `Device (_SB.WMIV)` is the older HP WMI command
  channel: `WVPI` packs `{signature, command, command_type, data_size, data}` into
  `PVWB` and `HVWA` → `HVWC(command)` dispatches `1 → VRBC` (read block) and
  `2 → VWBC` (write block). If any legacy command number sets a BIOS password, that
  is a second independent route — but the command semantics live in the BIOS, not
  the AML, so this needs the command numbers from elsewhere (HP's legacy docs or a
  Windows trace). Unexplored.
- **Sure Admin / SPM.** A different mechanism (signed `<BEAM/>` blobs). Our units
  report `SPM is_enabled=0`, so plaintext `<utf-16/>` is the right path here.
- **HP's Windows WMI provider.** `root\hp\instrumentedBIOS` +
  `HPBIOS_BIOSSettingInterface.SetBIOSSetting(Name, Value, Password)` maps to the
  same `WMBS/WSBS` we call — HP's provider adds no secret sauce at the ACPI layer,
  which is exactly why "Windows can, so Linux can" holds.

## Status

- Static analysis: **done** (docs 15, 16).
- Capability survey: **done** (this doc).
- Next: build and load `acpi_call`, then run the method-1/method-2 probe (§1) —
  and, in parallel, ask for a **known-good password** (§5) so the clear itself can
  finally be tested end to end.
