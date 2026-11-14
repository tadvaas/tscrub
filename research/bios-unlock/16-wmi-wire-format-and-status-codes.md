# 16 — The HP WMI wire format, and what `0x06` really means

Research date 2026-10-10. Follows `15-clearing-with-a-known-password.md`, which
concluded "HP cannot be done from Linux". **This document corrects that.**

Method: the bench 830 G5 exposes its ACPI tables decompressed under
`/sys/firmware/acpi/tables/`; all 44 tables (440 KB) were pulled off the live
appliance (base64 over the telnet session, `extract_acpi.py`) and disassembled
with `iasl -d`. The HP WMI BIOS implementation lives in the **DSDT** as
`Device (_SB.WMIB)`, and its AML is the authoritative description of what the
firmware expects — no guesswork and no Windows needed.

## 1. The shape of the interface

The device implements a **Query/Set pair per attribute type**:

| query | set | type code (`QPUW`) | sysfs object |
|---|---|---|---|
| `WQST` | `WDST` | `0` | string |
| `WQIN` | `WDIN` | `1` | integer |
| `WQEN` | `WDEN` | `2` | enumeration |
| `WQOL` | `WDLI` | `3` | ordered list |
| **`WQPW`** | **`WDPA`** | **`4`** | **password** |
| `WQBU` | `WDBU` | `5` | buffer |

and two MOF-invoked methods (`3` args = `instance, method_id, input`):

```asl
Method (WMBS, 3, Serialized)                 // the "BS" object = 1F4C91EB…
{
    If (Arg1 == One)       Local7 = WSBS (Arg2)   // method_id 1 → SetBiosSetting
    ElseIf (Arg1 == 0x02)  Local7 = WSSD (Arg2)   // method_id 2 → SetSettingData (no credential)
    ElseIf (Arg1 == 0x03)  Local7 = WFTE (Arg2)   // method_id 3 → stub, returns 0x04
    Return (Local7)
}
```

**The Linux driver calls exactly this.** `hp_wmi_set_bios_setting()` does
`wmi_evaluate_method(HP_WMI_SET_BIOS_SETTING_GUID, 0, 1, …)` → instance 0,
method_id 1 → `WSBS`. Same GUID, same method, same mailbox as HP's Windows
tooling. There is no separate OS-only mechanism.

## 2. What `WSBS` does with the buffer

```asl
Method (WSBS, 1, Serialized)
{
    Acquire (PUMX, 0xFFFF)
    Local7 = WSTB (Arg0, TBUF, 0x02, 0x03)   // parse the input; require 2..3 elements
    PUWB = TBUF                              // hand it to the BIOS mailbox (80 KB @ 0x97F68000)
    If ((Local7 == Zero))
    {
        BSNS (Arg0)
        PUWS = 0x04
        GSWS (0x02F3)                        // mailbox handshake + execute
        Local7 = PUWS                        // ← the BIOS's status comes back here
        If ((Local7 == Zero))      GBME (Zero)
        ElseIf ((Local7 == 0x06))  GBME (One)
    }
    Release (PUMX)
    Return (Local7)
}
```

`GSWS` is just a handshake: wait for `APMS`, set `APME`, write the command word
`SSWP = Arg0`, stall, wait again. The real work happens in the BIOS/EC behind the
mailbox, so the AML does not interpret the password — but it *does* define the
framing and it *does* interpret the status.

## 3. The exact input framing

`WSTB(input, outbuf, min=2, max=3)` is the parser:

```asl
Local7 = SHBE (0x30, One, Arg1, RefOf (Local2))     // header: 0x30, 0x82, u16 size
While ((Local7 == Zero) && (Local0 < Local1))
{
    Local5 = Arg0[Local0] | (Arg0[Local0+1] << 8)   // u16 element length (bytes)
    Local0 += 2
    Local7 = SHBE (0x1E, Local5 + 2, Arg1, …)       // element tag 0x1E + size word
    …copy Local5 bytes…, then append 0x00, 0x00     // UTF-16 NUL terminator
    Local3++                                        // count elements
}
If ((Local3 >= Arg2) && (Local3 <= Arg3))           // must be 2..3
     BSIZ = (Local2 - 0x04)                         // else 0x05
```

So the buffer is a **sequence of length-prefixed elements**:

```
[<u16 len1><name UTF-16>] [<u16 len2><value UTF-16>] [<u16 len3><credential UTF-16>]
```

and the driver builds precisely this — `hp_calculate_string_buffer()`'s own
comment says *"u16 length field + one UTF16 char for each input char"*, and
`hp_set_attribute()` writes name, value, then a credential element:

```c
/* spmobj-attributes.c */
size = sizeof(u16) + authlen * sizeof(u16);
if (!strstarts(authentication, BEAM_PREFIX))
        size += strlen(UTF_PREFIX) * sizeof(u16);        /* UTF_PREFIX = "<utf-16/>" */
…
strprefix = kasprintf(GFP_KERNEL, "%s%s", UTF_PREFIX, authentication);
auth = hp_ascii_to_utf16_unicode(auth, strprefix);
```

**So the credential element is `"<utf-16/>" + password`, UTF-16 encoded** — not the
bare password. The Linux driver's framing is byte-for-byte what the firmware's
parser wants.

## 4. Status codes — and HP's own reading of `0x06`

From the AML:

| code | where it comes from | meaning |
|---|---|---|
| `0x00` | `PUWS` from the BIOS | success → firmware raises `GBME(Zero)` = "BIOS Configuration Change" |
| `0x04` | `WSTB`/`SHBE` internally | malformed/undersized buffer — returned **before** the mailbox is touched |
| `0x05` | `WSTB` element-count check | wrong number of elements (outside 2..3) |
| **`0x06`** | **the BIOS, via the mailbox** | **`WSBS` maps it to `GBME(One)`, which raises the firmware's own event:** |

```asl
Name (CBWE, Package (0x02) {
    Package (0x05) { "BIOS Configuration Change",   "BIOS Settings", 0x04, 0x05, 0x02 },
    Package (0x05) { "BIOS Configuration Security",
                     "An attempt has been made to Access BIOS features unsuccessfully",
                     0x04, 0x0A, 0x06 } })
```

i.e. **on this path HP's own firmware treats `0x06` as a failed *access* — a
security/authorization refusal, not a syntax error.** (`hp_bioscfg`'s own table
instead labels 6 "Invalid command parameter"; the AML's *use* of the code is the
more specific evidence. See §7 for how to settle it.)

## 5. Re-reading the live measurements

| what we sent | driver buffer | result | explanation |
|---|---|---|---|
| a setting's own value, **no password cached** | credential element = 4 bytes | `0x04` | `WSTB` rejected the buffer as malformed — see the driver bug in §6 |
| a setting's own value, **any non-empty password** | 3 well-formed elements | **`0x06`** | framing accepted, mailbox executed, **the BIOS refused the credential** |
| a **real** change (`Enable`→`Disable`) | same | `0x06` | same — and the value did not change |
| writing `current_password` | — | rc=0, **no WMI call** | the driver only caches it |

**Conclusion: the Linux path is not broken. The BIOS is refusing the password we
supply.** `hpinvent` is, on this evidence, *not* the correct Setup Password on
these units.

## 6. Side finding: a real heap-overflow bug in the driver

```c
size_t hp_calculate_security_buffer(const char *authentication) {
        if (!authentication)             return sizeof(u16) * 2;   /* 4 bytes */
        authlen = strlen(authentication);
        if (!authlen)                    return sizeof(u16) * 2;   /* 4 bytes */
        …
}
```
but `hp_populate_security_buffer()` then builds `"<utf-16/>" + authentication`
(9 extra characters = 18 bytes) and writes it — **20 bytes into a 4-byte
allowance** when no password is cached. `hp_set_attribute()` allocates
`buffer_size + 1`, so this writes 16 bytes past the end of a kmalloc'd buffer.
It explains the `0x04` case exactly, and is worth reporting upstream.

## 7. What this means for the product

**"Windows can do it, so UNIX can too" is correct** — it is the same ACPI method,
on the same GUID, through the same BIOS mailbox. The framing our driver builds is
correct. Two things still stand between tScrub and an HP clear:

**(a) There is no entry point for the password. [fixable, small]**
`hp_set_attribute()` *is* the clear primitive — HP's documented
`SetBIOSSetting("Setup Password", "", "<current>")` is
`hp_set_attribute("Setup Password", "")`, i.e. three elements: name, empty value,
`"<utf-16/>" + current`. But the password object exposes no `current_value` (it
only has `current_password`/`new_password`/…), and `new_password_store()` passes
`is_current = true`, so a sysfs write never reaches `hp_set_attribute()`.
A ~5-line kernel patch — make `new_password_store()` call
`hp_set_attribute(kobj->name, new_pw)` (upstream already sets `is_current=false`
but still never commits) — would give tScrub a working clear where the credential
is accepted. **This is now worth doing**: the patch is tiny, it is the same call
Windows makes, and it is testable.

**(b) The credential must be correct. [not fixable by us]**
`0x06` says the BIOS refused ours. Note HP's lockout warning (§15): retrying a
wrong password can lock the BIOS until reboot, so this must never be looped.

## 8. How to settle it definitively

1. **Apply the patch in (a)** and write `""` to `new_password` with a *known-good*
   password on a unit whose password we actually know. Expect `0x00` and
   `is_enabled` flipping to `0`.
   - We do not have such a unit today: all three bench HPs have a password set and
     `hpinvent` is refused. **Getting one unit's real password (or a unit with a
     password we set ourselves at F10) would close this completely.**
2. **Or** run HP's own tool on the same unit (`Clear-HPBIOSSetupPassword -Password
   'hpinvent'` from WinPE): if HP's tool *also* fails, that independently confirms
   the password is wrong rather than the Linux path.
3. Cross-check the `0x06` semantics by watching whether the firmware raises the
   `BIOS Configuration Security` event (the `GBME(One)` path) — visible via the
   WMI event interface after a refused write.

## 9. Caveats

- The DSDT analysed is from the **830 G5** ROM dump (`hp elitebook 830 g5 main.bin`,
  BIOS Q78 01.01.07) — the same model as the `.165` bench unit, but possibly a
  different BIOS revision. The framing rules are corroborated independently by the
  Linux driver's own comments, so they are unlikely to be revision-specific.
- `0x06` is *used* by HP's `WSBS` as a security refusal, but `hp_bioscfg`'s status
  table calls it "Invalid command parameter". Until a unit with a known-correct
  password is tested, treat "the credential was refused" as the strong leading
  interpretation rather than proven fact.
- The password set path for the **Sure Admin / SPM** mechanism is different again
  (signed `<BEAM/>` blobs rather than `<utf-16/>` plaintext). Our units report
  `SPM is_enabled=0`, so they are on the plaintext path.
