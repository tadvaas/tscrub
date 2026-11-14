# 20 — Detecting the BIOS setup password on Insyde platforms (HP consumer, 2012-era)

**Status:** research complete, no code shipped. Written 2026-10-10 after investigating why
device `CND2370DFJ` (**192.168.0.170**) reports BIOS lock `UNKNOWN`.

**Trigger:** the fleet has 118 machines detected `LOCKED` (all via `SMBIOS Type 24`), but **5
machines report `UNKNOWN`** with method `NONE`. This doc establishes why, and whether any
in-OS detection is possible on that platform.

---

## 1. The machine

| | |
|---|---|
| Model | HP ENVY m6 Notebook PC (consumer, 2012) |
| BIOS | **Insyde** `F.11`, 08/15/2012, ROM 4608 kB (UEFI-capable, HM77 chipset) |
| SMBIOS | **2.7**, 47 structures / 2195 bytes |
| Chipset | HM77 Express (LPC `8086:1e57`) — flashrom `ichspi` would support it |
| Appliance | **v1.11.43** (predates the v1.11.44 firmware-attributes kernel fix) |
| Verdict in report | `bioslock=UNKNOWN`, `bioslockmethod=NONE` → dashboard amber "Unknown" |

The 5 `UNKNOWN` machines are all the same class: **firmware that publishes no SMBIOS Type 24**
— HP ENVY m6, Apple MacPro4,1, Apple iMac9,1, HP Z840, Intel NUC12SN.

## 2. Why it is UNKNOWN (the cascade has no source)

`product/src/36_bios.sh` cascades Layer 1 sysfs → Layer 2 legacy sysfs → Layer 3 SMBIOS Type 24
→ UNKNOWN. Measured on `.170`:

| Layer | Probe | Result |
|---|---|---|
| 1 | `/sys/class/firmware-attributes/` | **empty** (on v1.11.43 the class link is missing — see §3) |
| 2 | `/sys/devices/platform/hp-wmi/bios_password` | **absent** — node removed from upstream `hp-wmi` in 6.x; the platform dir holds only `als/display/dock/hddtemp/modalias/postcode/power/tablet` |
| 3 | `dmidecode -t 24` | **no output at all** (header only), and `24` is absent from the structure-type list (`dmidecode \| grep -o "DMI type [0-9]*," \| sort -u`) — the firmware publishes no Type 24 record. ⚠️ Use *that* test, **not** `grep -c "Type 24"`: dmidecode prints `DMI **type** 24` in lower case, so that count is 0 even on machines that do have it (verified against `.163`/`.164`, both locked) |
| 4 | — | `UNKNOWN` / `NONE` |

`bios_lockdown=0` in the same payload is unrelated (it is derived from SED-locked/frozen drives).

## 3. What the firmware *does* expose (and why it still doesn't answer)

`hp-bioscfg` **binds** on this machine. Pre-v1.11.44 kernels lose the sysfs class link but the
device is still readable at the orphan path `/sys/devices/hp-bioscfg`:

```
/sys/devices/hp-bioscfg/
├── attributes/        6 settings: Internal Network Adapter Boot, Legacy Support,
│                      Manufacturing Programming Mode, Secure Boot, Sure_Start, pending_reboot
└── authentication/    SPM only → is_enabled = 0, role = enhanced-bios-auth
```

Two conclusions:

1. **The modern HP settings interface works here** — the firmware answers `1F4C91EB` (BIOS config)
   well enough for the driver to enumerate six settings. **There is no `Setup Password` object**
   (the business fleet's 2018+ machines do expose `authentication/Setup Password/is_enabled`).
   So Layer 1 cannot report the setup password, with or without the kernel patch.
2. `authentication/` shows only **SPM** (HP Sure Platform Management, role `enhanced-bios-auth`)
   at `is_enabled=0` — that is Sure Admin, not the setup password.

### The HP WMI BIOS *password* GUID is advertised but not implemented

`hp-bioscfg` sizes its password enumeration from
`hp_get_instance_count(HP_WMI_BIOS_PASSWORD_GUID)` where (kernel `hp-bioscfg/bioscfg.h:65`):

```
HP_WMI_BIOS_PASSWORD_GUID = 322F2028-0F84-4901-988E-015176049E2D
```

On `.170` that WMI device **exists**: `object_id = BF`, `instance_count = 1`, `setable = 0`.
But the AML defines **no `WMBF` method** (see the `_WDG` table below), so nothing can call it —
consistent with the driver finding no Setup Password object.

> ⚠️ **Correction to an assumption made mid-investigation:** `2B814318-4BE8-4707-9D84-A190A859B5D0`
> is **not** the password GUID. Its ObjectId is `0xA0 0x00` (high bit set → event-style mapping,
> like the event GUID's `0x80 0x00`), so it is not a method-mapped interface either.

### The AML gives no live password query

`_WDG` (13 mappings, DSDT `Device (WMID)`, `_HID PNP0C14`) — GUID → ObjectId → instances/flags:

| GUID | ObjectId | Method | Implemented? |
|---|---|---|---|
| `5FB7F034-2C63-45E9-BE91-3D44E2C707E4` | `AA` | `WMAA` | ✔ (`→ WHCM(Arg1,Arg2)`) |
| `95F24279-…` (events) | `80 00` | — | event source |
| `2B814318-…` | `A0 00` | — | event-style, no method |
| `05901221-D566-11D1-B2F0-00A0C9062910` | `AB` | `WMAB` | ✗ |
| `1F4C91EB-DC5C-460B-951D-C7CB9B4B8D5E` | `BA` | `WMBA` | ✔ (write-only: Arg1 1/2) |
| `2D114B49-…` | `BC` | `WMBC` | ✗ (4 instances) |
| `988D08E3-…` / `14EA9746-…` | `BD`/`BE` | — | ✗ (0 instances) |
| **`322F2028-…` (BIOS password)** | **`BF`** | **`WMBF`** | **✗ — missing** |
| `8232DE3D-…` | `BG` | — | ✗ |
| `8F1F6436-…` | `BH` | `WMBH` | ✔ (stub, returns `Zero`) |
| `8F1F6435-…` | `BI` | — | ✗ |
| `DF4E63B6-…` | `BJ` | — | ✗ |

`WHCM(method_id, input)` is the HP legacy **`SECU` dispatcher**: input dwords `SNIN`(sig) /
`COMD` / `CMTP` / `DASI`; `SNIN == 0x55434553` ("SECU"); `COMD` 1 = read (`RDCF`), 2 = write
(`WRCF`), `0x00020002` = status/activate/deactivate; `CMTP` indexes the 58-entry size tables
`RCDS`/`WCDS`; results come back through the mailbox globals `HWBF/HWRC/HWCT/HWBC` with
`SSMP = 0xD7`. **There is no password command in that vocabulary.**

The DSDT also contains a *complete* setting descriptor for the password:

```
Method (WQBF) → Package { "Setup Password", " ", "Path", 1, 0, 0, 0, 1, " ", 0, 1, 0x14, "", "", 0, 0 }
Method (WQBC) → reads Name/Value/Path triples from the mailbox (HWBC = 0xFE)
```

…but **nothing calls `WQBC`/`WQBF` in any of the 8 tables** (DSDT + SSDT1-5 + UEFI1-2). It is
dead reference code — as is the 8501-byte `WQAB` blob ("FOMB…"). The `0x14` (20) field is the
setting's max length, and it is the only occurrence of the string "Setup Password" in the AML.

## 4. The one unexplored in-OS channel: UEFI variables

The appliance **never mounts `efivarfs`**, so tScrub currently sees *zero* UEFI variables on any
machine. Mounting it on `.170` (`mount -t efivarfs none /sys/firmware/efi/efivars`) reveals **81**,
including genuine state variables:

| Variable | GUID | Size | Note |
|---|---|---|---|
| `Setup` | `a04a27f4-df00-4d42-b552-39511302113d` | 904 B (900 data) | Insyde H2O setup blob |
| `Custom` | `a04a27f4-…` (same) | 904 B | second Insyde setup copy |
| `HPS` | `30fef3a5-ba29-4767-9f11-7c7fd606ffa6` | 264 B | HP state; records of `0x1d`+29×`FF` (unset) |
| `HPBV`, `HBL`, `HBS`, `HCA`, `HEBT`, `HMUTD`, `HWSIG` | `30fef3a5-…` | 5–25 B | HP platform/vendor state |
| `UnlockIDCopy` | `eaec226f-c9a3-477a-a826-ddc716cdc0e3` | 32 B | = the Autopilot ODUID random seed GUID (cross-checks our MDM research) |
| `AcpiGlobalVariable` | `c020489e-…` | 8 B | too small to hold a password flag |

The setup password state has to live *somewhere*; on Insyde these blobs are the prime candidate.
We cannot read the question-offset layout out of the AML (Insyde's question database is not in
ACPI), so this must be settled **empirically by diffing**.

### ✅ Empirical result (2026-10-10) — the flag was found, on the HP *business* platform

The diff was run without touching a single BIOS setting, by exploiting a **natural A/B pair**:
two identical **EliteBook 830 G5** units, one with an admin password and one without.

| Host | Model | SMBIOS T24 admin password | `UserCred` size | `AdminPW` record |
|---|---|---|---|---|
| `.164` | EliteBook 820 G3 | **Enabled** | 3540 B | **PRESENT** |
| `.163` | ZBook Fury G8 | **Enabled** | 4548 B | **PRESENT** |
| `.172` | EliteBook 830 G5 | **Enabled** | 3540 B | **PRESENT** |
| `.171` | EliteBook 830 G5 | Disabled | 4548 B | absent |
| `.170` | ENVY m6 (consumer Insyde) | *no T24 record* | **no `UserCred` variable at all** | n/a |

**Size is a red herring** (3540 vs 4548 correlates with model/generation, and the locked ZBook and
the unlocked 830 G5 share 4548). The actual discriminator is the **content**:

```
UserCred-f66687ff-8cf3-4a19-b4ac-f5f0b78e4d18
  LOCKED  .172 :  07 00 00 00 | 02 00 00 00 00 00 00 00 02 00 00 00 00 00 00 00
                               41 00 64 00 6d 00 69 00 6e 00 50 00 57 00 …   <- "AdminPW" UTF-16LE
  UNLOCKED .171:  07 00 00 00 | 05 00 00 00 00 00 00 00 ff ff ff ff 00 00 …     <- unset marker, no name
```

`41 00 64 00 6d 00 69 00 6e 00 50 00 57 00` is UTF-16LE for **`AdminPW`**. Where the admin password
is set, `UserCred` carries a credential record literally named `AdminPW`; where it is cleared, the
slot is an `0xFFFFFFFF` unset sentinel and the name is gone. **4/4 machines with a defined state
agree with Type 24, from a completely independent source** (UEFI variable vs SMBIOS table).

Scope limits, stated honestly:

- It is a **record-presence** signal — we detect that a credential named `AdminPW` exists, never the
  password itself (a blob follows the name; it is not decoded and must not be).
- It is **HP-business-only**: the consumer Insyde machine has no `UserCred` variable, so this does
  **not** resolve the `.170`-class `UNKNOWN`. Those still need Type 24 or the pre-boot route (P4).
- Validated against a single negative sample (`.171`). It should ship as an **additional evidence
  layer / cross-check**, consulted *after* the existing cascade, not as a replacement for Type 24.

### Field confirmation (v1.11.50, after the bench restart)

Six machines reported on v1.11.50. The `UserCred` fingerprint now arrives in the payload for every
business HP — but note what the fleet data does **not** show.

| Host | Serial | Model | T24 admin pw | `UserCred` in payload | `AdminPW` record | verdict source |
|---|---|---|---|---|---|---|
| `.163` | `CND23801Q4` | ZBook Fury 15.6 G8 | Enabled | 4544 B | **present** | SMBIOS Type 24 |
| `.164` | `5CG8130JYG` | EliteBook 820 G3 | Enabled | 3536 B | **present** | SMBIOS Type 24 |
| `.172` | `5CG8528HBN` | EliteBook 830 G5 | Enabled | 3536 B | **present** | SMBIOS Type 24 |
| `.171` | `5CG851960W` | EliteBook 830 G5 | Disabled | 4544 B | absent | SMBIOS Type 24 |
| `.170` | `CND2370DFJ` | ENVY m6 (consumer) | *none* | **no variable** | n/a | UNKNOWN |
| `.175` | `MRSN24303547` | **Intel NUC12SNKi72** | *none* | **no variable** | n/a | UNKNOWN |

**Agreement is 4/4 again** — the sample is now 8 machines with a defined state, with no
counter-example. Two things the field run settles:

1. **Layer 3b never fires in production.** Every machine that has a `UserCred` variable already
   resolves through Type 24, so the new layer's `bioslockmethod` never reads `UEFI var UserCred`.
   It is shipped, harmless and *unexercised*: its value is coverage for HP business firmware that
   omits the Type 24 table, not a change to today's fleet. To earn more than that it would have to
   be surfaced as an explicit *second source* (e.g. "Type 24 + UserCred agree"), so that a
   disagreement becomes visible instead of being silently shadowed by the LOCKED-wins cascade.
2. **It cannot rescue the `UNKNOWN` class.** Two of the five `UNKNOWN` machines have now been
   examined directly — the consumer ENVY m6 (`.170`) and the **Intel NUC12SN** (`.175`, one of the
   five named in §1) — and neither publishes a `UserCred` variable at all. That class stays a
   pre-boot problem (§6 P3/P4).

For the record, the fingerprints are `datasize` (file bytes − 4 attribute bytes): the 830 G5 pair
reads **3536 locked / 4544 unlocked**, and the locked 820 G3 reads 3536 — consistent with the
earlier 3540/4548-file-byte measurements. But the locked ZBook reads 4544, which is why size alone
cannot be the detector across platforms. **One same-model pair is not enough to treat size as a
secondary signal — don't.**


Non-ACPI alternative: full SPI read (HM77 + `/dev/mem` present, **but no `flashrom` in the image**,
and `CONFIG_STRICT_DEVMEM` unverified) — only a last resort; it also cannot distinguish "flag"
from "hash" without the same NVRAM layout knowledge.

## 5. What competitors do

| Vendor | BIOS lock detection |
|---|---|
| Blancco (ITAD/Enterprise/ARM sheets) | **none claimed** — BIOS/chassis *inventory*, custom BIOS logo, BIOS clock only |
| BitRaser | Windows/WinPE side prints "Checking BIOS Configuration…" (vendor tooling). Its Linux agent ships **Dell `libsmbios` tools** (`smbios-passwd`, `smbios-token-ctl`, `smbios-state-byte-ctl`, `biosdecode`) — Dell-only semantics; `p_cmos` mentions no password/SMBIOS/password strings |
| D-Secure | no BIOS lock claim |

⇒ **Nobody surfaces "a BIOS setup password is set" from Linux.** It is a differentiator, but the
honest scope is: *where the firmware publishes Type 24 (118/127 machines) we already detect it*;
elsewhere the reliable route is pre-boot / vendor tooling.

## 6. Recommendation (plan-first, cheapest first)

**P0 — free, do first: re-test `.170` on v1.11.48.** Boot it from the current ISO/PXE. The v1.11.44
class patch + v1.11.48 script will read `/sys/class/firmware-attributes/*` properly. Expected
outcome from this research: **still `UNKNOWN`** (no Setup Password object exists), but it removes
the "is it just the old image?" doubt and makes the orphan-path evidence above unnecessary.
Do this before building anything new.

**P1 — one bench unit, the decisive experiment (no code).** On a machine whose BIOS password we can
set and clear at POST (F10), capture on each state:

```sh
mount -t efivarfs none /sys/firmware/efi/efivars
for v in Setup Custom HPS; do
  f=$(ls /sys/firmware/efi/efivars/$v-* 2>/dev/null | head -1)
  [ -n "$f" ] && { echo "== $v"; od -An -tx1 -v "$f"; }
done
```
Diff password-set vs password-cleared. If a stable byte/field flips, we have a detector with no
kernel work. If nothing flips, close this line and go to P2.

**P2 — if P1 finds a flag: ship the detector.** `36_bios.sh` gains a "Layer 1b": mount `efivarfs`
(read-only, idempotent, `mountpoint -q` guarded), read the variable, evaluate the flag, and set
`BIOS_PASSWORD_STATUS` + a method string like `UEFI var Setup (Insyde)`. Report surfaces: CSV
`BIOSLock`/`BIOSLockMethod`, manifest, Runtime panel row, dashboard badge/tooltip. **Bonus that
P1/P2 should also capture:** `hp-bioscfg` enumerates 6 *settings* on this platform, so on consumer
HP we can report `Secure Boot` / `Sure_Start` state from Linux even without a password signal.

> **Status (v1.11.50, 2026-10-10): shipped — as Layer 3b, and on the HP *business* platform, not
> Insyde.** P1 as written (set and clear a password at F10) turned out to be unnecessary: the
> natural A/B pair answered the same question with **no BIOS change on any unit**. The shipped
> layer reads `UserCred-f66687ff-…` for the `AdminPW` record (§4) and is consulted **last** in the
> cascade, so it can never contradict a verdict the stronger layers already reached. It changes no
> verdict on the current fleet — every machine carrying the record already resolves `LOCKED` via
> Type 24 — and its value is coverage (HP business firmware with no Type 24 table) plus
> corroboration from an independent source. The `hp-bioscfg` Secure Boot bonus shipped in v1.11.49.
> `UNKNOWN` for the `.170` class is **still open** (§6 P3/P4).

**P3 — do regardless of P1/P2: make `UNKNOWN` explainable.** Today three different situations all
render as an amber "Unknown". Have `36_bios.sh` distinguish "this firmware publishes no password
state" (no Type 24 record **and** no password object) from "a source existed but was unreadable",
and show that in the dashboard tooltip. Small appliance change + site-only dashboard wording.

> **Status (v1.11.51, 2026-10-10): shipped.** `36_bios.sh` now tracks which password interfaces were
> **present** (`bios::_saw`) and whether any **refused to be read**, and Layer 4 writes the reason
> into `BIOS_DETECTION_METHOD` — e.g. `NONE (no password state published; sources present:
> firmware-attributes)` or `NONE (no verdict; dmidecode is not installed)`. Because the method
> already travels in `BIOSLockMethod`, it reaches the CSV, the manifest and the diagnostics PDF for
> free, and the dashboard's BIOS-lock badge tooltip now shows it (stripping the `NONE (…)` wrapper).
> **No verdict changes** — only the explanation appears. Verified against the two `UNKNOWN` machines:
> `.170` (hp-bioscfg present, 6 attributes, none a password object; no Type 24; no `UserCred`) will
> report `…sources present: firmware-attributes`, while `.175` (the Intel NUC — nothing present at
> all) reports `no password interface published`, so the two are no longer indistinguishable.
>
> Found while implementing it: the SMBIOS presence test was **dead code** — `probe_smbios` guarded on
> non-empty `dmidecode -t 24` output, but dmidecode exits 0 and prints a three-line header even when
> the type does not exist (measured on `.170`: 67 bytes, zero `Password Status` lines). Presence now
> comes from a `Password Status` line, and a *refusing* dmidecode (missing/not root) is reported as
> unreadable rather than as a firmware that publishes nothing.

**P4 — outside Linux, already available:** the existing WinPE/PXE flow with HP's own tooling
(`BiosConfigUtility.exe /GetConfig` — a *set* password makes it demand credentials, which is itself
a detection signal; or CMSL `Get-HPBIOSSetupPasswordIsSet`). Vendor-supported, works on consumer
models, and it is what the competitors rely on.

## 7. Gotchas learned (for whoever picks this up)

- **`efivarfs` is not mounted by the appliance** — 0 variables is the *default* view, not evidence
  of a legacy boot. `.170` was UEFI-booted (`/sys/firmware/efi` present, ACPI `UEFI1/2` tables,
  `efivars` empty until mounted).
- On **pre-v1.11.44** kernels the `firmware-attributes` class link is missing; read the orphan
  `/sys/devices/hp-bioscfg` instead — the attribute files are all there.
- `hp-bioscfg` attribute names contain **spaces** (`Internal Network Adapter Boot`) — never
  `for a in $(ls attributes)`, it word-splits.
- **Do not trust a GUID by shape.** Identify it against the kernel
  (`drivers/platform/x86/hp/hp-bioscfg/bioscfg.h`), not by resemblance to another vendor's GUID.
- `_WDG` ObjectId: ASCII pair → method `WMxx`; high-bit set (`0x80`/`0xA0`) → not a method mapping.
- Pulling tables off a live box: base64 over telnet works (tarball + `B64START`/`B64END`
  sentinels); parse the **last** sentinel pair and verify gzip magic, because the banner echoes the
  command (and therefore the sentinels) back.
- `grep -c` exits 1 on zero matches — it silently kills an `&&` chain (it cut this investigation's
  first command short).
- Artefacts from this investigation: `research/bios-lock/acpi-170/` (DSDT + SSDT1-5 + UEFI1-2 and
  their `.dsl` disassembly) and `research/bios-lock/efivars-170/` (UEFI-variable baseline), both
  pulled from the live machine.

---

## 8. Results log

### P0 — `.170` rebooted onto v1.11.48 (2026-10-10 12:40, operator action)

**Verdict unchanged: `UNKNOWN` / `NONE`** — report `id=989`, `tool=v1.11.48`, uploaded 12:40:04,
confirmed in the live DB. Exactly as predicted. What the reboot *did* change:

- `/sys/class/firmware-attributes/hp-bioscfg` is now a **correct symlink** (the v1.11.44 kernel
  patch works on this machine — before the reboot the class dir was empty and the device was only
  reachable via the orphan `/sys/devices/hp-bioscfg`).
- `authentication/` still holds **only `SPM`** (`is_enabled=0`, `enhanced-bios-auth`) — **no
  Setup Password object**. So the missing object is a property of the firmware, *not* of the old
  image. Layer 1 has nothing to read, on any image.

Side note: the machine's erasure run completed *before* the restart (`id=984`, v1.11.43, 10:53).

### The bench fleet is all EFI-booted — UEFI variables are a live, unused channel

| Host | Appliance | efivars | `Setup-ec87d643-…` | Administrator Password Status (Type 24) |
|---|---|---|---|---|
| `.170` HP ENVY m6 (consumer Insyde) | v1.11.48 | 0 → **81 when mounted** | n/a (Insyde `Setup` = `a04a27f4-…`, 904 B) | — no Type 24 record at all |
| `.163` ZBook Fury G8 | v1.11.48 | 285 | **present, 2433 B** | **Enabled** (locked) |
| `.164` EliteBook 820 G3 | v1.11.48 | 227 | **absent** | **Enabled** (locked) |
| `.171` EliteBook 830 G5 | v1.11.48 | 265 | **absent** | **Disabled** (unlocked) |
| `.165` EliteBook 830 G5 | — | offline at time of survey | — | — |

⇒ `efivarfs` mounts and is richly populated on every machine we have, and tScrub mounts none of it.

### v1.11.49 outcome (2026-10-10, after rebooting 4 machines)

**Secure Boot is fixed in production**: 830 G5 `5CG851960W` = Disabled; 820 G3 `5CG8130JYG`, ZBook
`CND23801Q4` and ENVY `CND2370DFJ` = Enabled — real values where every earlier report said `N/A`.

**The evidence capture did not arrive, and the cause was ours, not the appliance's.**
`reports_lib.php store_diagnostics_report()` builds an explicit **whitelist** payload, so
`efivars` / `efivars_b64` were silently dropped on ingest (no error, no log line). Fixed by adding
both keys, deployed server-side only, verified end-to-end with a throwaway row (`id=995` persisted
the values; row deleted after), and the appliance side confirmed to send them
(`register::json_body` contains `"efivars"`/`"efivars_b64"`, `SYS_EFIVARS` =
`81 vars; Setup:900:…; HPS:260:…`). Machines that booted v1.11.49 before the fix need one more boot
for the payload channel — the live-telnet collection below works any time.

**⚠️ The four-name capture is blind on most of the fleet.** Sweeping the reachable machines:

| Host | Model | Admin PW (Type 24) | efivars | State variables found |
|---|---|---|---|---|
| `.170` | HP ENVY m6 (consumer Insyde) | *no Type 24 record* | 81 | Insyde `Setup`/`Custom` 900 B, `HPS` 260 B, `HPBV` 21 B |
| `.163` | ZBook Fury G8 | **Enabled** | 285 | `Setup-ec87d643-…` 2429 B |
| `.164` | EliteBook 820 G3 | **Enabled** | 227 | **none** of Setup/Custom/HPS/HPBV |
| `.171` | EliteBook 830 G5 | Disabled | 265 | **none** either |
| `.138` / `.142` / `.144` / `.165` | — | — | — | offline during the sweep |

These variables are **model/firmware-specific names**, so a fixed list of four captures nothing on
the business EliteBooks (227–285 variables under other names). A fleet-wide comparison would need
the **full inventory** (names + sizes + sha16), not a name whitelist.

**Still needed for the A/B:** a *same-model* locked/unlocked pair. The only reachable 830 G5 is the
unlocked `.171`; the locked 830 G5 `.165` (`5CG8528HBN`) was offline — bringing one up gives a clean
comparison against `.171` with no BIOS change on any unit.

### ❌ Hypothesis disproved: "a `Setup` variable exists ⇒ a password is set"

`.163` is locked **and** has the variable; `.164` is locked and does **not**; `.171` is unlocked and
does not. Presence tracks something model/firmware-specific (e.g. whether the setup store was ever
written in UEFI form), **not** the password state. Cross-machine comparison is therefore confounded
— which is exactly why P1 must be a **before/after on one machine**, not a fleet comparison.

### Baseline captured for the P1 diff

`research/bios-lock/efivars-170/` — `Setup-a04a27f4-…` (904 B), `Custom-a04a27f4-…` (904 B),
`HPS-30fef3a5-…` (264 B), `HPBV-30fef3a5-…` (25 B), taken from `.170` on v1.11.48.

### Side benefit worth noting

The same missing mount also explains a fleet-wide gap: `secure_boot` reports `N/A` on these
machines, yet `SecureBoot-8be4df61-93ca-11d2-aa0d-00e098032b8c` is present in the variable list. A
single `mount -t efivarfs` in the appliance would let `36_bios.sh` (and the Secure Boot capture)
read real UEFI variables — so P2/P3 have a second, independent payoff.
