# 15 — Removing a BIOS password when the password IS known

> **⚠️ Corrected by [16-wmi-wire-format-and-status-codes.md](16-wmi-wire-format-and-status-codes.md).**
> The "HP ❌ cannot from Linux" verdict below is too strong. Disassembling the
> firmware's own ACPI AML shows the Linux driver frames the request *correctly*,
> that the BIOS's `0x06` on this path is HP's **access-refused** status, and that
> the only missing piece is a small driver patch to expose the password write.
> Read §4 and §6 below with §1–§7 of doc 16.

Research date 2026-10-10, on the live bench fleet (v1.11.45):

| host | serial | model | generation |
|---|---|---|---|
| 192.168.0.165 | 5CG8528HBN | HP EliteBook 830 G5 | 2018 |
| 192.168.0.164 | 5CG8130JYG | HP EliteBook 820 G3 | 2016 |
| 192.168.0.163 | CND23801Q4 | HP ZBook Fury 15.6 G8 | 2021 |

The question: **if the operator supplies the correct existing password, can tScrub
remove the BIOS password?** Answer differs completely by vendor.

## TL;DR

| vendor | driver | clear with a known password from Linux? | where the evidence is |
|---|---|---|---|
| Dell | `dell-wmi-sysman` | **Yes** — write `current_password`, then `new_password` (empty) | §2 |
| Lenovo | `think-lmi` | **Yes** — same pattern; an empty new password is explicitly allowed to clear | §3 |
| **HP** | `hp-bioscfg` | **No** — and not because of the password | §4 |

For HP the vendor-supported route is HP's own tooling (§6), which is documented to
run in WinPE.

## 1. What a clear actually needs

Every one of these drivers does the same two things:

1. **Stage the operator's password** as the "current password" — it is only ever
   used as an *authentication token* in the next call.
2. **Issue a set command** authenticated by that token, with an **empty new
   password**. That is what "clear" means at the firmware level.

So a clear needs (a) a sysfs entry point that issues a call at all, and (b) a
firmware that accepts it. Dell and Lenovo have both. HP has neither.

## 2. Dell — works (`dell-wmi-sysman/passobj-attributes.c`)

```c
static ssize_t current_password_store(...)          /* just caches */
        memcpy(target, buf, length);                /* current_admin_password */

static ssize_t new_password_store(...)
        p = memchr(buf_cp, '\n', count);
        if (p != NULL) *p = '\0';                   /* strip the newline */
        ...
        ret = set_new_password(kobj->name, buf_cp); /* <-- issues the WMI call */
```

The write to `new_password` **immediately issues the set command**, so an empty
value clears the password. This is exactly why v1.11.45 changed tScrub to write a
single `\n` rather than a 0-byte payload: a 0-byte write can be dropped before it
reaches `new_password_store`, and a Dell/Lenovo clear may never have reached the
firmware at all.

## 3. Lenovo — works (`lenovo/think-lmi.c`)

```c
static ssize_t new_password_store(...)
        if (!capable(CAP_SYS_ADMIN)) return -EPERM;
        if (!tlmi_priv.can_set_bios_password) return -EOPNOTSUPP;
        new_pwd = kstrdup_and_replace(buf, '\n', '\0', GFP_KERNEL);
        ...
        pwdlen = strlen(new_pwd);
        /* pwdlen == 0 is allowed to clear the password */      <-- explicit
        ...
        ret = tlmi_simple_call(LENOVO_SET_BIOS_PASSWORD_GUID, auth_str);
```

The comment in the driver is explicit, and the call is issued in the store. Same
shape as Dell — a `\n` becomes an empty new password and clears it.

## 4. HP — cannot be done from Linux, and the password is not the reason

Four independent findings, all verified on the live units.

### 4.1 There is no sysfs entry point for the password at all

```
$ ls /sys/class/firmware-attributes/hp-bioscfg/authentication/'Setup Password'/
current_password  is_enabled  mechanism        new_password
encodings         max_password_length  min_password_length  role
```

There is **no `current_value`** on a password object. Dell/Lenovo expose the
password as a *setting* whose `current_value` can be written; HP exposes only
`current_password` / `new_password` / `is_enabled` / `role` / `mechanism` /
`encodings` / min / max. So nothing an operator can write commits a password
change.

### 4.2 `new_password` is a dead end in 6.18 — and even upstream has no commit

```c
static ssize_t new_password_store(...)
{
        return store_password_instance(kobj, buf, count, true);   /* true = is_current */
}
```

Both `current_password_store` and `new_password_store` pass `is_current = true`,
so writing `new_password` merely **overwrites the cached current password**.
`.new_password` is never populated, and nothing consumes it.

Upstream master *has* fixed the flag (`store_password_instance(..., false)`) — but
it still exposes no `current_value` on the password object, so upstream also has
no way to commit a password change from userspace. The `new_password` attribute is
incomplete upstream, not just in our tree.

### 4.3 The only send path exists, but the firmware rejects it

`ATTRIBUTE_PROPERTY_STORE(current_value, …)` (bioscfg.h) generates every setting
write, and it ends in:

```c
        if (!ret)
                ret = hp_set_attribute(kobj->name, attr_value);
        ...
        hp_clear_all_credentials();     /* wipes current_password after EVERY write */
```

`hp_set_attribute()` (biosattr-interface.c) picks up the `SETUP_PASSWD` instance,
builds `NAME + VALUE + security-buffer(current_password)` and calls
`hp_wmi_set_bios_setting()` on `HP_WMI_SET_BIOS_SETTING_GUID` — which is exactly
the call HP's own tooling makes to change the password (setting "Setup Password").
On our three units, that call is rejected by the BIOS:

| what was written | auth token | result |
|---|---|---|
| a setting's own value | none cached | `EINVAL` + `hp_bioscfg: Returned error 0x4, "Invalid command type"` |
| a setting's own value | a **wrong** password | `EINVAL` + `0x6, "Invalid command parameter"` |
| a setting's own value | **`hpinvent`** | `EINVAL` + `0x6, "Invalid command parameter"` |
| a **real** change (`Enable`→`Disable`) | `hpinvent` | `EINVAL` + `0x6` — and the value did **not** change |

Tested across **8 settings of all three types** (string, enumeration, integer) on
a **fresh boot with `hpinvent` as the very first password-authenticated action** —
8/8 failed with `0x6`. So it is not setting-specific, not value-specific, and not
"no-op writes are rejected". The firmware refuses the set command outright.

### 4.4 The password is never even sent

Writing `current_password` produces **no kernel log line at all** — no WMI call is
issued; the driver only caches the value (§4.2). Combined with §4.1 there is no
path by which an operator-supplied password reaches the firmware on HP.

`INVALID_BIOS_AUTH` ("Invalid BIOS administrator password") never appears in the
log either. Caveat: on the 830 G5 the error code does change when the token is
*absent* (`0x4` with an empty security buffer vs `0x6` with a non-empty one), so
the firmware does look at the parameter block — but a wrong password and
`hpinvent` are indistinguishable, and a correct password plus a legitimate value
should have succeeded. **We cannot prove from Linux alone whether `hpinvent` is
the right password** — see §7 for the experiment that would settle it.

### 4.5 The errno is useless on HP — read `dmesg`

`hp_wmi_error_and_message()` maps `INVALID_CMD_TYPE` (0x4),
`INVALID_CMD_PARAM` (0x6) **and** `INVALID_BIOS_AUTH` all to `-EINVAL`. Only the
kernel message distinguishes them, and the appliance runs as root so it can read
it. (Same conclusion as `ops/bios-unlock.md` §4.)

### 4.6 Not an encoding mistake

The password object reports `encodings = utf-16`, and `hp_set_attribute` does
UTF-16 encode the token (`hp_ascii_to_utf16_unicode`). So the failure is not a
plaintext-vs-UTF-16 problem on our side.

## 5. ⚠️ HP BIOS lockout — a safety issue for us

HP's own cmdlet documentation states, for both `Set-HPBIOSSetupPassword` and
`Clear-HPBIOSSetupPassword`:

> *"Multiple attempts to change the password with an incorrect existing password
> may trigger BIOS lockout mode, which can be cleared by rebooting the system."*

This bit me during this investigation: my first three-way control test tried a
deliberate wrong password *immediately before* trying `hpinvent` in the same boot,
which may have biased the result (a reboot was needed to get a clean test).

Product implications:

- A "Retry" button that an operator can hit repeatedly with a wrong password can
  put the machine into BIOS lockout (recoverable only by a reboot). The v1.11.45
  verdict work already removes Retry for `no_clear_path`/`not_supported`, which is
  the right default.
- Any future automated retry must be bounded and must not loop.

## 6. The vendor-supported routes for HP (recommended)

With a known password, HP provides supported tooling that does the clear. This is
the path to automate, not the Linux sysfs one.

**HP CMSL (Client Management Script Library), PowerShell:**

```powershell
Get-HPBIOSSetupPasswordIsSet                     # is a password set?
Clear-HPBIOSSetupPassword -Password 'currentpassword'
# setting one:  Set-HPBIOSSetupPassword -NewPassword 'new' -Password 'old'
# Power-On slot: Clear-HPBIOSPowerOnPassword -Password 'currentpassword'
```

`Clear-HPBIOSSetupPassword` takes exactly one thing — the **existing** password —
which is precisely our case. It is documented alongside
`Get-HPBIOSSettingValue` / `Set-HPBIOSSettingValue`, the same family as the
`root\hp\instrumentedBIOS` `SetBIOSSetting(Name, Value, Password)` interface.

**CMSL is documented to run in WinPE** (HP's "Notes-on-WinPE-usage"): build the PE
image with the WMI, .NET/WinFX, PowerShell, Scripting, StorageWMI, Dism and
SecureBoot optional components, inject the HP WinPE driver pack, then use the
library. Restrictions HP calls out: 32-bit WinPE is not supported, SoftPaq
functionality does not work in WinPE, and "functionality that depends on
identifying the current operating system may not work".

That matters to us because **tScrub already has a WinPE boot path** (`board/shredos/winpe/`
plus the PXE `hashreport` entry used for Autopilot hash capture). A second WinPE
task — "clear the BIOS password with the operator's password" — would run the same
way: reliably, on the same PXE/ISO flow, using HP's supported tool.

**BCU (BiosConfigUtility) is the other vendor CLI** (same supported family,
`/cpwdfile` supplies the current password and an empty new-password file clears
it). We could not fetch HP's BCU guide to quote exact flags — verify against the
current HP document before building on it.

**Manual fallback:** with the known password, any technician can clear it in
~15 seconds at F10 → Security → Setup Password (enter current, leave new blank).
No custom tooling and no risk — the product's value here is *automation*, not
capability.

**HP Sure Admin / SPM:** if a machine uses Sure Platform Manager instead of a
classic setup password, the mechanism is different again (signed KEK/SK via
`HPWMI_SECUREPLATFORM_*`). The `SPM` authentication object exists on our units but
reports `is_enabled=0`, so they are on the classic password path.

## 7. Next experiments (in value order)

1. **Settle "is `hpinvent` correct?" with vendor tooling** — boot the existing
   WinPE on the 830 G5 and run `Clear-HPBIOSSetupPassword -Password 'hpinvent'`.
   - It clears → `hpinvent` is correct, the hardware supports it, and only the
     Linux path is broken → then the WinPE route is the product feature.
   - It fails → `hpinvent` is not the password on that unit, which also explains
     our `0x6`. (HP's lockout note means the machine must be **rebooted** before
     such a test, and it must be the first attempt.)
2. **Do NOT spend a kernel rebuild on HP.** A driver patch could only call the
   same `HP_WMI_SET_BIOS_SETTING_GUID` (`hp_set_attribute("Setup Password", "")`)
   that the firmware already refuses for every setting (§4.3). The fix is
   firmware/tooling-side, so the win is in using HP's tool.
3. **Add a lockout warning to the dashboard** near the BIOS-unlock card (§5), and
   keep Retry hidden for non-retryable verdicts.
4. **Verify the Dell and Lenovo clears on real hardware** when units are
   available — that is where a known password *should* already work from tScrub,
   with the v1.11.45 `\n` fix.

## 8. Open questions

- Does `HP_WMI_SET_BIOS_SETTING_GUID` work on *any* HP generation from Linux?
  All three units we have (2016/2018/2021) refuse it. Upstream `hp-bioscfg`
  presumably targets newer platforms — untested here.
- Does HP's Windows provider (`root\hp\instrumentedBIOS`) use the same ACPI WMI
  command as the Linux driver, or HP's own mechanism? If the latter, the Linux
  driver may never work on these models regardless of patches.
- Is the BIOS lockout counter per-boot or persistent in NVRAM? HP says a reboot
  clears it; worth confirming so we can document recovery accurately.
