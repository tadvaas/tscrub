# 18 — Live WMI probing: what the HP interface actually answers

Research date 2026-10-10, on `.165` (EliteBook 830 G5, `5CG8528HBN`, v1.11.45),
cross-checked on `.164` (820 G3) and `.163` (ZBook Fury G8).

Doc 16 reverse-engineered the wire format from the firmware's AML. This document
reports what happened when we **stopped inferring and started calling the method
ourselves** — with our own buffers, our own method ids, and the firmware's own
status codes as the answer.

## The three sentences that matter

1. **Our framing is correct.** A deliberately malformed buffer comes back `0x04`
   and a four-element buffer comes back `0x05` — exactly the two rejections the
   AML's parser is written to produce — so our bytes are reaching (and being
   parsed by) the firmware's own element parser.
2. **A real setting name returns `0x06`; "Setup Password" returns `0x05`.** With
   identically-shaped 2- and 3-element buffers, a name the BIOS knows
   (`Ownership Tag`) gives the driver's own error `0x06`, while `Setup Password`
   gives `0x05`. The *count* is valid in both cases, so the discriminator is the
   **name**. See the CORRECTION at the end: the name *is* recognised — adding the
   credential element moves it to `0x06` like any other secure setting.
3. ~~**There are no password objects on this hardware.**~~ **CORRECTED — this was
   wrong, and the mistake is worth recording.** The password objects *do* exist:
   `<class>/hp-bioscfg/authentication/Setup Password/` holds
   `current_password`, `new_password` and `is_enabled`. My searches said
   otherwise because `find` does not follow symlinks, and
   `/sys/class/firmware-attributes/hp-bioscfg` **is** a symlink — so nothing
   below it was ever searched. The driver enumerates the slot fine; what it
   cannot do is make the firmware act on it (doc 15, and the live false positive
   below). Verify with `ls /sys/class/firmware-attributes/hp-bioscfg/authentication/`
   rather than `find`.

## The harness: `hpraw`

`research/bios-unlock/hpraw/` is a ~120-line out-of-tree module that replicates
the driver's exact call and lets userspace supply the buffer:

```c
status = wmi_evaluate_method("1F4C91EB-DC5C-460b-951D-C7CB9B4B8D5E",
                             instance, method_id, &input, &output);
```

That GUID and that argument order are byte-for-byte what `hp_bioscfg` uses
(`biosattr-interface.c:253`), so anything we observe is *the same code path* the
product would take — minus the driver's own bookkeeping.

Loading it needed no reboot and no kernel change:

```
make -C ~/shredos.x86_64/output/build/linux-6.18 M=$PWD ARCH=x86_64 \
     CROSS_COMPILE=$HOME/shredos.x86_64/output/host/bin/x86_64-buildroot-linux-gnu- modules
insmod /tmp/hpraw.ko
echo 2 > /sys/module/hpraw/parameters/method_id
cat some_buffer.bin > /proc/hpraw   # one call per write
cat /proc/hpraw                     # the firmware's status
```

The module built first time and loaded cleanly (`vermagic: 6.18.0 SMP preempt
mod_unload modversions`), which also confirms something useful for the future:
**the appliance can load modules**, so the `acpi_call` route in doc 17 is
available if we ever want arbitrary ACPI method invocation.

Buffer builders: `mkprobes.py` (probe set 1) and `mkprobes2.py` (probe set 2),
which emit the exact `<u16 byteLen><UTF-16>` elements of doc 16.

## Probe results

Set 1 — the credential framings the Linux driver never sends. Name used:
`Setup Password`.

| probe | method | shape | status |
|---|---|---|---|
| P0 | 1 (WSBS) | `[Setup Password]` (1 element) | `0x05` |
| P1 | 1 | `[Setup Password][][<utf-16/>hpinvent]` (driver's shape) | `0x05` |
| P2 | 1 | `[Setup Password][][hpinvent]` (no encoding prefix) | `0x05` |
| P3 | 1 | `[Setup Password][hpinvent]` (password in value slot) | `0x05` |
| P4 | 1 | `[Setup Password][][]` (empty credential) | `0x05` |
| P5 | 2 (WSSD) | `[Setup Password][]` | `0x00` |
| P6 | 1 | `[Setup Password][hpinvent][hpinvent]` | `0x05` |
| P7 | 1 | `[Setup Password][][<utf-16/>]` | `0x05` |

Set 2 — calibration (does the parser see our buffer?) and a **real** setting.

| probe | method | shape | status |
|---|---|---|---|
| T1 | 1 | length field `0x0100` with 4 bytes behind it | **`0x04`** |
| T2 | 1 | `[Ownership Tag][a][b][c]` (4 elements, max is 3) | **`0x05`** |
| T3 | 1 | `[Ownership Tag][tscrub-test]` (2 elements) | **`0x06`** |
| T4 | 1 | `[Ownership Tag][tscrub-test][]` (3 elements) | **`0x06`** |
| T5 | 2 | `[Ownership Tag][tscrub-test]` | `0x00` |
| T6 | 2 | `[Ownership Tag][]` (restore) | `0x00` |
| T7 | 2 | `[No Such Setting Here][x]` — **bogus name** | `0x00` |

T5/T6 changed nothing: after dropping and re-binding the WMI device (to force a
fresh read from the BIOS) `Ownership Tag` still read back empty.

## Corrected status-code semantics

Doc 16 read `0x05` as "the AML's element-count check" and `0x06` as "the BIOS
refused the access". The live data says the codes have **two sources each** and
must be read together:

| code | source | meaning |
|---|---|---|
| `0x04` | AML `WSTB`/`SHBE` | malformed framing — length field runs past the data. Returned **before** the mailbox. |
| `0x05` | AML `WSTB` **or the BIOS** | either the element count is outside `[min,max]`, **or** the setting name is unknown to the BIOS. |
| `0x06` | the BIOS (via `PUWS`) | a *known* setting was refused. |
| `0x00` | the BIOS (via `PUWS`) | accepted — but see the method-2 dead end below; it is **not** proof of a change. |

The two `0x05` sources are separable because `[name][value]` is 2 elements —
valid for WSBS's `WSTB(…, 0x02, 0x03)` — so a `0x05` for a 2-element buffer
cannot be the count check. It is the BIOS saying "I don't have a setting called
that".

Note `WMBS` sets `Local7 = One` before dispatch, and `WSBS`'s last act is
`Local7 = PUWS` — the *BIOS's* mailbox status. So on the WSBS path the returned
number is the firmware's own answer, not an AML verdict.

## Three dead ends, with the evidence

**1. Method 2 (`WSSD`, the no-credential setter) is not usable.** It returns
`0x00` for a real setting, for an empty value, *and for a name that does not
exist*. A status that is identical for a bogus name carries no information, and
the value genuinely did not change (verified by re-binding and re-reading).
Doc 17's hope that `WSSD` could prove the path end-to-end is **wrong** — it
proves nothing. `WSSD` is also called with `WSTB(…, 0x02, 0x02)` (exactly two
elements), so it cannot express name/value/credential at all.

**2. (Superseded — see §CORRECTION: the name *is* known, and the frame is
complete.)** What set 2 really showed is that the password is not reachable
through **sysfs**: the firmware exposes no password *object* on any of the three
machines — no password-type attribute, no `is_enabled`, no
`current_password`/`new_password` — so the driver's `hp_get_password_instance_for_type`
`(SETUP_PASSWD)` has nothing to find, and every secure setting write from Linux is
necessarily *unauthenticated*. But the *setting* is still addressable over WMI by
name, which is what the CORRECTION goes on to show.

**3. The legacy `SECU` interface has no password command.** `hp_bioscfg`'s other
path (`hp_wmi_perform_query`) sends `{signature = "SECU", command, commandtype,
datasize, data}` to the old PV device (`5FB7F034…`) with `method_id` encoding the
output size (1..5 → 4/128/1024/4096+ bytes, per `hp_encode_outsize_for_pvsz`),
and the AML dispatches it through `HVWC`: `1 → VRBC` (read), `2 → VWBC` (write),
`0x20006 → FBCD` (SureStart). Its command set (`enum hp_wmi_commandtype` in
`hp-wmi.c`) is **entirely hardware queries** — display, HDD temperature,
wireless, battery, BIOS version, hotkeys, POST codes, thermal profiles. There is
no BIOS-setting or password command in it, so this second interface is not a
route either.

## The one hook still unexplored

The AML defines a third method, **`WMUI`** (doc 16 §5, line 2298 of the
disassembly), which nothing in Linux calls:

```asl
Method (WMUI, 3, Serialized)
{
    Local7 = One
    If ((Arg1 == One))
    {
        Acquire (PUMX, 0xFFFF)
        Local7 = WSTB (Arg0, TBUF, 0x06, 0x06)   // exactly SIX elements
        PUWB = TBUF
        If ((Local7 == Zero)) { PUWS = 0x04; Local7 = PUWS }   // no GSWS handshake
        Release (PUMX)
    }
    Return (Local7)
}
```

Two things make it interesting and awkward at the same time: it wants **six**
elements (nothing else in the interface does), and it reads its buffer from
**`Arg0`** — where the kernel's `wmidev_evaluate_method()` always puts the
*instance*. So `wmi_evaluate_method()` can never drive it; reaching it needs a
method call whose first argument is a buffer, i.e. a direct
`acpi_evaluate_object()` on the device handle (feasible by extending `hpraw`) or
`acpi_call`. Its missing handshake and the immediate `PUWS` read suggest it is
vestigial firmware, so the expected value is low — but it is the only path left
that is not already excluded.

## Why the password objects are absent

Worth stating plainly, because it reframes the whole feature: this is a
**firmware capability** gap, not a Linux driver bug.

- `hp-bioscfg` enumerates password objects by querying
  `HP_WMI_BIOS_PASSWORD_GUID` (`322F2028-…`) — see `bioscfg.c:1017`.
- On these machines that query yields nothing: no password attributes, no
  `is_enabled`, no `current_password`/`new_password`.
- With no password instance, the credential element `hp_set_attribute()` builds
  is empty, so every write to a secure setting is refused (`0x06`) no matter what
  password the operator supplies — the password is never transmitted.
- The BIOS additionally does not recognise the name `Setup Password`, so even a
  hand-built frame cannot address the password as a setting.

The Windows side is consistent with this: HP's `Clear-HPBIOSSetupPassword` and
its support matrix are limited to models whose firmware publishes the password
through the BIOS-settings interface. On hardware that does not, HP's own tooling
has nothing to talk to either. This supersedes doc 15's "HP ❌" with a *reason*.

## What this means for the product

- The appliance's dashboard verdict for these machines is **correct and should
  stay**: there is no reset path to offer, and retrying cannot help.
- The verdict vocabulary is worth one refinement: the two distinguishable cases
  are "the firmware publishes no password object at all" (`no_interface`,
  detected by the absence of `current_password`/`new_password`) versus "published
  but refused" (`no_clear_path`). Our classifier already has both; the dashboard
  hint should not imply the password was *accepted* by the firmware, because on
  this generation it is never sent.
- The genuinely fixable driver bugs (doc 16 §6–7: the inert `new_password`
  attribute, `is_current=true`, and the empty-credential heap overflow in
  `hp_populate_security_buffer()`) remain worth reporting upstream — they matter
  on hardware that *does* publish password objects, but they cannot help here.

## Reproducing any of this

```sh
# 1. build + load the harness (build host)
ssh oxwet@192.168.0.6 'cd ~/hpraw && make -C ~/shredos.x86_64/output/build/linux-6.18 \
    M=$PWD ARCH=x86_64 \
    CROSS_COMPILE=$HOME/shredos.x86_64/output/host/bin/x86_64-buildroot-linux-gnu- modules'
# 2. run a probe set (repo root)
python3 research/bios-unlock/hpraw/mkprobes2.py > /tmp/p.sh
python3 research/autopilot/telnet_cmd.py --sleep 75 192.168.0.165 "$(cat /tmp/p.sh)"
```

`dmesg` on the appliance logs every call
(`hpraw: instance=0 method_id=1 len=70 -> status 0x5 (5)`).

**Safety note:** the probes only ever wrote to `Ownership Tag` (restored) and to
names the BIOS rejects. No secure setting was modified on any machine.

## CORRECTION — probe sets 3 and 4: the name *is* known, and the clear is expressible

Everything above about the harness, the framing and the `0x04`/`0x05`/`0x06`
mechanics stands. One conclusion does not: **"this BIOS does not know a setting
called `Setup Password`" is wrong.** Two further sets, run the same day, replaced
it with something far more useful.

### Set 3 — name sweep (eleven candidate names, `[name][""]`, method 1)

| name sent | status |
|---|---|
| **`Setup Password`** | **`0x05`** |
| `Administrator Password`, `BIOS Administrator Password`, `Admin Password`, `Power-On Password`, `Power On Password`, `System Password`, `Boot Password`, `DriveLock Master Password`, `DriveLock Password`, `Sure Admin Password` | `0x04` |

Ten unrelated names give one answer and `Setup Password` gives another. A BIOS
that did not recognise the name would answer identically for all eleven — so the
name **is** recognised. Read against the driver's own table, `0x04` is
"invalid command type" (no such setting) and `0x05` is "invalid command value".

### Set 4 — completing the frame changes the answer

| frame | status |
|---|---|
| `[Ownership Tag][tscrub-test]` — a known secure setting | `0x06` |
| `[Setup Password][<utf-16/>hpinvent]` — 2 elements, **no credential** | `0x05` |
| `[Setup Password][<utf-16/>hpinvent][<utf-16/>hpinvent]` — 3 elements | **`0x06`** |
| `[Setup Password][<utf-16/>]` — 2 elements, no credential | `0x05` |
| `[Setup Password][<utf-16/>][<utf-16/>hpinvent]` — 3 elements | **`0x06`** |
| `[Setup Password][<utf-16/>tscrub1234][<utf-16/>hpinvent]` — 3 elements | **`0x06`** |

Adding the credential element moves `Setup Password` from `0x05` to `0x06` — the
same code a known secure setting produces. And rows 5 and 6 show **the value does
not matter**: an *empty* new password (`[<utf-16/>]`) is treated exactly like a
non-empty one. A clear is expressible.

### Where the request now stands

| element of the request | state |
|---|---|
| wire framing | **proven correct** — `0x04` only for genuinely malformed input, and the driver's byte layout reproduced exactly |
| setting name | **correct and recognised** (set 3) |
| frame shape (2 vs 3 elements) | **understood and complete** (set 4) |
| new value (empty or not) | **accepted** — both reach `0x06` (set 4) |
| mailbox + `GSWS` handshake | **working** — the BIOS returns real status codes, not a stub |
| **the credential** | **refused** — every complete frame carrying `hpinvent` returns `0x06` |

That leaves exactly one unknown: **is the credential the real Setup Password?**
The decisive experiment is now precise and cheap — on a unit where we *set* the
password ourselves at F10 (call it `tscrub1234`):

```
1. re-affirm:  [Setup Password] [<utf-16/>tscrub1234] [<utf-16/>tscrub1234]   expect 0x00
2. clear:      [Setup Password] [<utf-16/>]           [<utf-16/>tscrub1234]   expect 0x00
3. verify:     [Ownership Tag] [tscrub-test]     was 0x06 a moment ago, expect 0x00
```

`0x00` on steps 1 and 2 would settle it **positively**: an HP BIOS password
cleared from Linux given a known password. That is precisely the case the
question was about. If step 1 returns `0x06` with a password we *know* is
correct, then the credential grammar rather than the password is wrong — and the
error space is now small enough to iterate on, because every other element of the
request is pinned down.

Step 3 is the verification that needs no reboot: while a Setup Password is set, a
write to any secure setting is refused (`0x06`); once the password is gone, the
same write is accepted. That converts "the call succeeded" into "the password is
actually gone".

The product consequence is better than doc 17 assumed: the clear does **not**
depend on the driver's password sysfs (which these machines do not expose at all).
A module of the `hpraw` kind — about 120 lines — can carry the frame, and the
operator-supplied password is the only input it needs.

## CONFIRMED on `192.168.0.171` — an HP BIOS password cleared from Linux

Host: **HP EliteBook 830 G5, serial `5CG851960W`** (`.171`), v1.11.45, with the
Setup Password **confirmed by the operator to be `hpinvent`**. Every call below is
method 1 on GUID `1F4C91EB…` through the `hpraw` module; the kernel log
(`dmesg`) supplied each status code.

| # | frame | status | reading |
|---|---|---|---|
| C0 | `[Ownership Tag][tscrub-171]` — no credential | `0x06` | refused: an admin password is required |
| B1 | `[Ownership Tag][tscrub-171][<utf-16/>hpinvent]` | `0x00` | **credential accepted** — `hpinvent` *is* the Setup Password, and the `<utf-16/>` credential encoding is right |
| X1 | `[Setup Password][<utf-16/>][<utf-16/>hpinvent]` | `0x00` | **the clear** |
| C2 | `[Ownership Tag][tscrub-ok]` — no credential | `0x00` | the request C0 refused is now accepted |
| C3 | `[Ownership Tag][tscrub-171]` again, no credential | `0x00` | still accepted — the refusal is gone, not a fluke |
| X1′ | the same clear frame again, after the clear | `0x05` | its credential now matches nothing → **no password left** |

The clear frame, exactly — three elements, name, empty new password, current
password:

```
[Setup Password]  [<utf-16/>]  [<utf-16/>hpinvent]
      name        new value     current password
```

### Why this is a real clear and not a silent no-op

Two independent signals, neither of which can be explained by the password still
being set:

1. **The authorization requirement disappeared.** A secure write with no
   credential went `0x06` → `0x00`, and stayed there on a repeat. On `.165` the
   identical request returned `0x06` on every attempt through a long session, so
   this is not a "first call of the session" quirk.
2. **The clear frame stopped being accepted.** With a password set, name + empty
   value + correct credential returned `0x00`; afterwards the same frame returns
   `0x05`, i.e. its credential no longer authenticates against anything.

If the value had instead been stored literally (i.e. the password became the
text `<utf-16/>`), the machine would still demand an admin password and C2/C3
would still be refused. They are not.

### Honest caveat — `0x00` is not proof that a value was stored

Writing `[Ownership Tag][value]` returns `0x00` yet the tag reads back **empty**,
even after dropping and re-binding the WMI device to force a fresh read from the
BIOS — with the value bare, with the `<utf-16/>` prefix, and when restoring.
So `0x00` means *"the firmware processed the request"*, not *"the value is now
stored"*. For the password, the corroboration above is what carries the
conclusion.

**Final confirmation is a reboot:** POST should no longer ask for the Setup
Password and F10 should open without one. That is the operator's step, and it is
the ground truth.

### ✅ CONFIRMED BY REBOOT

The operator rebooted `192.168.0.171` and reported that **the Setup Password is
gone** — POST no longer asks for it. The clear is therefore proven end to end on
real hardware: an HP BIOS Setup Password was removed from Linux by the appliance,
using the operator-supplied password, with a frame that is exactly three
length-prefixed UTF-16 elements.

### ✅ INDEPENDENTLY CONFIRMED by the BIOS's own SMBIOS reporting

After the reboot, the firmware reports the password state itself. Read with
`dmidecode -t 24` ("Hardware Security"), the same source our detection Layer 3
already uses:

| host | model | status | `Administrator Password Status` |
|---|---|---|---|
| `.171` `5CG851960W` | 830 G5 | **cleared by us** | **`Disabled`** |
| `.164` `5CG8130JYG` | 820 G3 | still locked | `Enabled` |
| `.163` `CND23801Q4` | ZBook Fury G8 | still locked | `Enabled` |

That gives three things at once:

* **A third, independent confirmation** of the clear — from the BIOS, not from the
  call whose status we were reading.
* **The `0x00` caveat is resolved in practice.** SMBIOS is generated at POST from
  NVRAM state, so `Disabled` proves the clear was **persisted**, not merely a
  session effect. (The earlier "a `0x00` status does not prove a value was stored"
  finding stands for arbitrary settings, but not for this one.)
* **The appliance already reports it.** `product/src/36_bios.sh` Layer 3 reads
  exactly this field, so a cleared machine reports `BIOS_PASSWORD_STATUS=UNLOCKED`
  with method `dmidecode: type 24` — no new code, and the report/manifest carries
  the evidence. Note it reflects **POST**, so it confirms on the *next* boot; the
  same-boot signal remains the authorization flip (`0x06` → `0x00`).

Shipping plan: [`19-shipping-plan-wmi-clear.md`](19-shipping-plan-wmi-clear.md).

## Two more findings from the shipping implementation (2026-10-10, `.171`)

Both came out of running the *product* code path on real hardware, not a probe.

### The sysfs verdict can be a FALSE POSITIVE — measured, not theorised

`bios_unlock::clear` uses the sysfs password path first (Layers 1-2). With a
password genuinely set, it wrote `authentication/Setup Password/current_password`
and `new_password`, its verification (`is_enabled`) said the password was gone,
and it reported **`cleared`** — while the firmware still refused privileged
writes (`0x06`) and kept demanding the password. The driver caches attribute
values from probe time, so a password set out of band (as ours was, over WMI)
makes `is_enabled` lie.

Fixed in the product by **running the WMI layer first wherever its interface
exists**: the sysfs verdict is now only reached when the WMI transport is
unavailable, i.e. when it is the best signal available. This is exactly the
"`0x00` does not prove a value was stored" lesson, one layer up.

### Setting a password needs the credential element present-but-empty

Setting a password on a machine that has none (`[Setup Password][<utf-16/>new]`)
returns **`0x05`**; adding a third element that is the driver's encoding of
*empty* (`[Setup Password][<utf-16/>new][<utf-16/>]`) returns **`0x00`**, and the
next probe then refuses (`0x06`) — the password is really set. So an element that
is present and empty is not the same as an absent element, in both directions:
the same rule that makes the clear frame three elements.

### Incidental finding: the sysfs path is broken *independently* of the password

After the clear — with **no** admin password required at all — the driver's own
sysfs write (`echo x > attributes/Ownership Tag/current_value`) still failed with
`hp_bioscfg: Returned error 0x4, "Invalid command type"`, while our raw frame
succeeded. That matches the size-accounting bug found in doc 16:
`hp_calculate_security_buffer("")` returns 4 bytes while
`hp_populate_security_buffer()` writes 20 (`"<utf-16/>"`), so the driver's frame
is malformed before it ever reaches the BIOS. The password was never the whole
story — the driver's own request builder is also broken.
