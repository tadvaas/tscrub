# Product — working backlog

Former `ROADMAP.md` §5/§6/§8/§9/§11 folded in below (master retired
2026-10-07 — see git history). Order = highest impact first. Tick items off as
they ship and note the release that carries each.

## 1. Erasure depth & verification (biggest competitor gaps)

- [x] **Post-erasure verification (sampled read-back)** — prove unrecoverability
      rather than assert it. After the firmware erase, re-read N random LBAs
      (`dd` + `sha256`) and compare against expected; full pass opt-in. Record
      `verify=<none|sampled|full>` + result in the report. (§9.2, §11.1)
      → **Shipped v1.10.15** (2026-10-04). Plan: `research/verify-readback/README.md`
      (sentinel-based known-data verification; `product/src/43_verify.sh`).
- [x] **HPA/DCO removal & erasure** — before wiping, reset HPA to native size
      (`hdparm -N p<max>`) and clear DCO (`hdparm --dco-restore`), then re-read
      to confirm both are gone; record a before/after pair. (§9.2, §11.1)
      → **Shipped v1.10.16** (2026-10-04). Plan: `research/hpa-dco/README.md`
      (BitRaser's `_HPA_*` is HP-Array RAID erase via hpacucli, NOT ATA HPA —
      a red herring; enhanced/sanitize already erase hidden areas at firmware
      level, so removal is only required for normal secure erase).
- [x] **SAS firmware sanitise** — add `device::sas_sanitize` that tries
      `sg_sanitize --overwrite --zero` first (bundle `sg3_utils` — Buildroot
      already ships it), falling back to nwipe only when unsupported. (§11.1)
      → **Shipped v1.10.17** (2026-10-05). Plan: `research/sas-sanitize/README.md`
      (`device::scsi_sanitize_supported` + `device::exec_scsi_sanitize` in
      `product/src/32_device_scsi.sh`; tests `product/tests/test_scsi_sanitize.sh`).
- [x] **SED (OPAL) unlock + erase** — add an OPAL path alongside NVMe/ATA:
      `sedutil-cli --query` → PSID revert when there's no LBA unlock, and surface
      the 3-state SED status as an explicit method. (§9.1)
      → **Shipped v1.10.18** (2026-10-05). Plan: `research/sed-opal/README.md`
      (`device::exec_opal` in `product/src/44_device_sed.sh`; tests
      `product/tests/test_sed.sh`).
- [x] **RAID dismantling** — detect members in `gather_info` (`mdadm -E`
      superblocks, Intel VMD / `mpt3sas` controllers), mark "RAID member —
      dismantle in controller BIOS" on the triage screen, record a per-drive RAID
      flag. Never auto-break an array. (§9.1, §11.1)
      → **Shipped v1.10.19** (2026-10-05). Plan: `research/raid-dismantling/README.md`
      (`device::raid_detect` in `product/src/45_device_raid.sh`; tests
      `product/tests/test_raid.sh`). (Controller-level erase stays §11.1.)

## 2. Hardware capture → intelligence

- [x] **Diagnostics & refurb grading** — collapse SMART pre/post capture into a
      drive grade (A/B/C/D/?) + per-drive resale health report (power-on hours,
      TBW, reallocated sectors), plus an operator inbound device grade
      (I-A–I-F) stored on each diagnostics report. (§6)
      → **Shipped 2026-10-06/07**: `grading.php` drive rubric (D on SMART
      FAIL/realloc≥5/used≥90%/spare<10%; C/B/A otherwise) with the per-drive
      grade surfaced in the Drives list, modal and SMART CSV; device inbound
      grade picker (I-A High refurb potential … I-F Faulty/dead) in the Devices
      tab, shown on the latest report in the main row and on each diagnostics
      report row; grade attached to the diagnostics PDF. Plan:
      `research/refurb-grading/README.md` (Backblaze-threshold drive rubric).
- [x] **Windows key (DPK) injection into NVRAM** — shipped v1.11.1: write a new
      key into the OA3 UEFI variable (the BIOS derives the MSDM table from it at
      boot — no SPI flash, no table assembly, no checksum). Field-verified on HP:
      the key lives in `HP_OA3-<GUID>` in plaintext and is replaced in place; the
      worker is vendor-agnostic (locates the variable by content). Notes in
      `research/dpk-injection/oa3-uefi-injection.md`.
      → **Removed in v1.11.2** (2026-10-05): firmware re-injection is only
      possible on blank service boards — not a standard ITAD workflow — so the
      appliance worker, API endpoints and dashboard card were removed; the
      dashboard still *surfaces* the embedded key. The two sub-items below are
      therefore cancelled.
  - [x] **Unlock locked OA3** — resolved: `HP_OA3_LOCK` is a **permanent one-way
        commit flag** set by HP factory/service tools (DMI Utility / MPM /
        NbDmiKit) after the key is programmed. Once set, the MSDM table + DMI
        are hardware read-only and HP's own tools refuse re-injection
        ("OA3 Key already committed"). A BIOS factory reset does **not** clear
        it. Only a blank/uncommitted service board (`HP_OA3_LOCK=0`) is
        firmware-injectable; committed boards use OS-level activation
        (`PID.txt` / `unattend.xml` / `slmgr`).
  - [x] ~~**Fresh-table injection**~~ — a blank/uncommitted board
        (`HP_OA3_LOCK=0`) with no key needs the vendor variable name/GUID +
        structure to create it from scratch. **Cancelled (2026-10-06)** with the
        v1.11.2 removal of product-key injection — blank-board injection is not
        a standard ITAD workflow.
  - [x] ~~**Dell/Lenovo verification**~~ — confirm the key sits contiguously in
        a UEFI variable on other vendors (content-scan is vendor-agnostic but
        unverified beyond HP). **Cancelled (2026-10-06)** with the v1.11.2
        removal of product-key injection.

## 3. Robustness & ops

- [x] **Remote BIOS unlock robustness** (§8)
  → **Shipped v1.11.3** (2026-10-05) — all 8 items below in
  `38_bios_unlock.sh` + `bios_unlock.php`/`api.php`; plan
  `research/bios-unlock/13-remote-unlock-robustness.md`; tests
  `product/tests/test_bios_unlock.sh` (18 tests).
  - [x] JSON-safe password handling — base64 `password_b64` field (legacy kept).
  - [x] Dispatched TTL + requeue (10 min) + result-POST curl retries.
  - [x] Purge password residue — supersede clears `password_enc`; 7-day stale sweep.
  - [x] Explicit slot targeting — setup/AdminPassword > power-on/SystemPassword.
  - [x] Confirm the clear took effect — re-read the slot; "still set" → failed.
  - [x] `uuid` in the claim (fall back to serial-only when staged uuid empty).
  - [x] `curl -k` TLS clock-skew fallback (mirror `report::upload_http`).
  - [x] Enforce the result state machine server-side (`dispatched` → result).
  > Reality: a kernel `firmware-attributes` password interface exists only on
  > Dell (`dell-wmi-sysman`) and Lenovo (`think_lmi`). HP (`hp-bioscfg`) publishes
  > no password reset at all and its `new_password` attribute is inert, so an HP
  > clear needs the dedicated WMI transport — which shipped in v1.11.46 (see
  > §4.6). Most vendors report `unsupported`. In-house SPI bench research lives in
  > `research/bios-unlock/`.
- [x] **Appliance ops polish** (§5) — serial console (`CONFIG_SERIAL_8250`),
      `CONFIG_VIRTIO_NET` for faster VM testing, quiet `sedutil-cli` SG_IO noise
      on QEMU disks.
      → **Shipped v1.11.17** (2026-10-07): `board/shredos/kernel-defconfig` gains
      `CONFIG_SERIAL_8250`/`CONFIG_SERIAL_8250_CONSOLE`/`CONFIG_SERIAL_8250_PCI` +
      `CONFIG_VIRTIO_NET`; boot menus pass `console=ttyS0,115200` alongside
      `console=tty3`. Discovery skips the OPAL `--query` probe on
      hypervisor/emulated disks (QEMU/VMware/VirtualBox/Hyper-V/Xen/virtio) so
      sedutil's SG_IO noise (and traps) on QEMU disks is gone.
- [x] **Eliminate table UI full-screen reflow** — today `table::render`
      (`product/src/40_table.sh`) starts with `clear`/`\033[2J\033[H` and
      reprints the whole screen (both info panels, column header, every device
      row, footer) from scratch, and `ui::loop` calls it on every worker
      `STATUS` transition and whenever the MDM verdict label flips — so the
      screen visibly redraws each time a drive changes state. Fix: render once,
      then diff-and-repaint — cache the last-painted status/class/method/temp/
      ETA per drive plus the last Runtime-panel values, and on change repaint
      only the changed cells in place using the absolute-cursor technique
      `ui::tick_inplace`/`select::paint_row` already use (`\0337`,
      `\033[%d;%dH\033[K`, `\0338`); keep a full `table::render` for terminal
      resize, device-set change or theme switch. Optional: move the UI into the
      alternate screen buffer (`\033[?1049h`) so the initial clear never scrolls
      scrollback.
      → **Shipped v1.11.7** (2026-10-05): `table::render` now paints once and
      seeds a per-drive state cache plus a layout/theme/mode fingerprint;
      `ui::repaint_changed` repaints only the changed rows in place (absolute
      cursor + clear-to-end-of-line) and falls back to a full render only on
      terminal resize, theme, device-set or selection-mode change, with a
      `SIGWINCH` trap and `tput`-first size detection. **v1.11.8** removed the
      full-screen blank on same-theme transitions (clear only on first paint or
      theme change). The optional alternate-screen-buffer move was not done.
      → Plan: `research/table-ui-reflow/README.md` (btop-style render-once +
      diff-and-repaint; nWipe's "clear only on init/resize" principle).
- [x] **Organisations & seats** (§5) — shipped 2026-10-05 (server + dashboard,
      no appliance change): `organisations` / `organisation_members` /
      `organisation_invites` tables, `org.php` scope layer (writes keep
      `user_id`, reads resolve to `org_member_ids`), org-aware tier, email-invite
      + owner/admin/member roles, soft-remove, seat limits (free 2 / payg 10 /
      team 50 / enterprise 100), Account-page Organisation card — and **device
      credits pool per organisation** (`credit_events.organisation_id`; the
      wallet is keyed by org id so membership changes never move it; a user's
      personal credits + history transfer into the org on create/join). Plan:
      `research/organisations-seats/README.md`; tests
      `research/organisations-seats/test_org_php.php` (48 checks).

## 4. Backlog

Long tail — pick up as milestone room allows. Folded in from the former
`ROADMAP.md` §6 / §8 / §9 / §11 (retired 2026-10-07); the `(§x.y)` refs below
point back to that master's section numbering. Competitive-gap sources:
`research/bitraser/README.md` (teardown), `research/blancco/*.md` (product
sheets), `research/dsecuretech/README.md`. Items already shipped are ticked
with the release that carried them (per `CHANGELOG.md`).

- [x] Fleet wipe job queue — dashboard queues a wipe per serial; appliance polls.
      (§9.3)
      → **Shipped 2026-10-07 (v1.11.19)**: wipe jobs are durable (pending 7 days,
      dispatched→requeued after 10 min), claims are serial+uuid aware, appliance
      result POST retries 3×, and the dashboard surfaces Queued/Expired states
      (offline erase staging enabled).
- [x] **Machine-readable certificates** — signed JSON-LD beside the PDF for
      AI/automated auditors. (§6)
      → **Shipped 2026-10-06**: every certificate is also issued as a signed
      JSON-LD document at `/verify?cert=…&format=jsonld` (detached Ed25519 over
      JCS canonical bytes, RFC 3161 timestamp; public key at
      `/.well-known/tscrub-cert-key.json`; dashboard "JSON" download link).
      Plan: `research/machine-readable-certs/README.md`.
- [x] **Certificate states how the data was destroyed** — keep the marketable
      "Certificate of Destruction" title, but show *how*: page 1 gains a
      `Technique` line + a `Media disposition` line, the banner claims "data
      destroyed" (not media), and Annex A opens with a DESTRUCTION METHOD SUMMARY
      (per method: label, NIST level, technique, device count). The signed JSON-LD
      twin carries the same evidence. (§9.3)
      → **Shipped 2026-10-10** (server + site only; no appliance change). Shared
      vocabulary in `marketing/server/cert_terms.php` so the PDF and JSON-LD can
      never disagree. Plan: `research/cert-destruction-evidence/README.md`.

### 4.1 Erasure depth (§9.2, §11.1)

- [ ] Multi-pass software overwrite — `--method <standard>` mapped to nwipe
      patterns (DoD 5220.22-M, Gutmann, HMG IS5, PRNG), exposed on the triage
      screen with a `Standard` column in `report::csv`. (§9.2, §11.1)
- [ ] Configurable write passes + custom algorithms — `--passes N` +
      `--pattern <zero|one|random|hex>` mapped to nwipe custom patterns; record
      a `Custom` method in the report. (§11.1)
- [ ] IEEE 2883-2022 Purge incl. TPM clearing — `tpm::clear` (`tpm2_clear -c
      platform` / PPI request at next boot) plus an explicit "IEEE 2883 Purge"
      method = disk erase + TPM clear, recorded on the certificate. (§11.1)
- [ ] RAID controller physical-drive erase — bundle `megacli`/`hpacucli`, add a
      `device::raid_erase` path mapping a drive to its controller erase command,
      gated behind the "never auto-break an array" rule. (§11.1)
- [ ] NVMe namespace delete/recreate — guarded `nvme ns` helpers behind an
      explicit "manage namespaces" toggle; low priority (format/sanitise already
      cover it). (§11.1)
- [ ] eMMC/MMC media — add `mmcblk*` to `device::discover` (currently excluded),
      erase via `blkdiscard` (no ATA/NVMe sanitise available). (§11.1)
- [ ] Reformat SATA/SAS drives after erasure — leave a drive in a clean,
      re-partitioned state (Blancco ships this; auditors sometimes expect a
      blank partition table rather than an unformatted disk). (§11.1)
- [ ] Resume an interrupted erasure — re-enter and continue a wipe that lost
      power or was aborted without re-consuming a licence (Blancco). Today a
      worker death normalises to `UNKNOWN`, no resume. (§11.1)

### 4.2 Media & platform coverage (§9.1, §6)

- [ ] Mac (Intel + Apple Silicon) erase — pull-the-drive workflow + docs, not a
      Mac boot; declare Apple Silicon's Recovery-only erase on `compare.html`.
      (§9.1)
- [ ] Chromebook erase — dev mode / SeaBIOS boot → wipe eMMC/NVMe; capture
      ChromeOS GBB/VPD enrolment flags. (§9.1)
- [ ] Broader media coverage — USB/SD targets and RAID/FC/iSCSI volumes (the
      long tail beyond eMMC). (§6)
- [ ] Legacy IDE/PATA — declared gap: state "SATA and newer" explicitly on
      `compare.html` (not planned). (§9.4)

### 4.3 Verification & trust (§6, §9.2, §9.4)

- [x] Canary self-audit on every wipe — write an unforgeable sentinel to random
      LBAs before erasing, verify it's gone after, record the result. (§6)
      → **Shipped v1.10.15** (2026-10-04): per-run sentinel planted at 5 fixed
      positions (0/25/50/75/tail), read back post-wipe and sha256-compared;
      modes `none|sampled|full`, recorded as `Verify/VerifySectors/VerifyResult`
      (see §1). Remaining nuance: positions are fixed, not random-LBA.
- [ ] Offline / air-gapped verification — self-contained certificate QR
      (embedded signature + key) so a phone verifies with zero network. (§6)
- [ ] Overwrite-pattern verification + hexviewer — verify software-overwrite
      patterns were actually written (read-back compare) and add a hexviewer
      visual check for compliance (Blancco). Distinct from the sentinel check
      above — this audits the overwrite itself, not just the final state. (§9.2)
- [x] IEEE 2883 awareness — audit `compliance.html`/`docs.html`; map methods to
      IEEE 2883 terminology or state the NIST 800-88 target explicitly. (§9.2)
      → **Shipped**: compliance copy states the NIST 800-88 target explicitly
      and disclaims certification (never claims IEEE 2883).
- [ ] Third-party testing / certification — prepare a certification submission
      + application runbook as `ops/certification.md`; never claim what we don't
      hold. (§9.4)

### 4.4 Fleet & integrations (§6, §9.3)

- [ ] Fleet Management Console — multi-site dashboard, role-based access,
      remote licence/job control, full admin audit trail. (§6)
- [ ] Integration API + webhooks — documented public API + events
      (`certificate.ready`, `report.uploaded`). (§6)
- [ ] ITSM/ERP integrations — OpenAPI doc + webhooks, then one reference
      ServiceNow inbound-webhook script. (§9.3)
- [ ] Customized erasure report — account-level report template (logo, extra
      header fields, column visibility) applied in `render_cert.php`. (§9.3)
- [ ] Customer-customized ISO — `make branded-iso CUSTOMER=…` target in the
      build fork + a one-page how-to. (§9.3)
- [ ] Report search & export API + embed reports on the drive — public
      search/export over stored reports, and the ability to write the report
      onto the wiped drive for fast offline audit (Blancco). (§9.3)
- [ ] Out-of-band deployment — boot/erase via iLO / iDRAC / Cisco UCS / Intel
      AMT (Blancco deploys this way); today only ISO + PXE bzImage. (§9.3)
- [ ] USB Creator tool — mass-produce configured boot sticks (Blancco's creator
      does up to 10 at once). (§9.3)
- [ ] Offline / on-prem licensing — air-gapped fleets need a Lock-Key-style
      dongle (BitRaser) or a self-hosted licence server (BitRaser "Network"
      mode); today licences are `.lic` files only. (§9.3)
- [ ] WLAN + 802.1x appliance networking — the image has no Wi-Fi
      (`CONFIG_CFG80211` unset, no `wpa_supplicant`), so Wi-Fi-only sites and
      802.1x-port-authenticated networks can't upload reports (Blancco/BitRaser
      support both). (§9.3)

### 4.5 Inventory & MDM (§6)

- [x] Richer machine inventory — full SMBIOS, `lspci -nn`, `lsusb`, network +
      MACs, DIMM details, UEFI Secure Boot status, and BIOS-lock-derived flags;
      stable CSV columns (name-based parser) + full inventory in report JSON.
      (§6)
      → **Shipped**: `hardware::` captures a raw SMBIOS dump, full PCI list
      (`lspci -nn`), USB devices, per-NIC name/MAC/operstate/driver, per-DIMM
      size/mfr/type/form-factor/speed/part#/serial, Secure Boot state
      (mokutil + efivars fallback) and BIOS-lock flags; the JSON inventory and
      diagnostics report render them (blobs stay JSON-only, out of the CSV).
      → **v1.11.49** made the Secure Boot half real: the appliance never mounted
      `efivarfs`, so the efivars fallback found **0** variables on every machine
      and Secure Boot reported `N/A` fleet-wide (the other probe, `mokutil`, is
      not in the image). `system::gather_info` now mounts it **read-only**, and
      the field reports `Enabled`/`Disabled`.
      → **v1.11.44 + v1.11.50** deepened the BIOS-lock half — see §4.6.
- [ ] MDM / enrolment-lock detection — Apple DEP/Activation Lock, ChromeOS
      GBB/VPD, CompuTrace; record `mdm_locked`/`enrollment` per device. (§6)

### 4.6 BIOS unlock (§8)

- [ ] Offline unlock-only boot flag — a lightweight "unlock-only" boot so an
      operator can rescue a locked BIOS without running a wipe. (§8)
- [x] **BIOS-lock detection depth** — the detection cascade (firmware-attributes
      sysfs → legacy vendor sysfs → SMBIOS Type 24 → UNKNOWN) was blind at both
      ends: the kernel's `firmware-attributes` class directory was empty on the
      appliance, and any firmware that publishes no Type 24 table landed in
      `UNKNOWN` with no explanation. (§8)
      → **Shipped v1.11.44** (2026-10-10): a kernel patch registers the
      `firmware-attributes` class before its users, so `hp-bioscfg` /
      `dell-wmi-sysman` are no longer orphaned at `/sys/devices/<driver>` with no
      `/sys/class/firmware-attributes` symlink; `bios_unlock::clear` also scans
      orphaned device trees and HP's `authentication/` objects.
      → **Shipped v1.11.50** (2026-10-10): a new **final** cascade layer reads the
      HP `AdminPW` credential record from the UEFI variable
      `UserCred-f66687ff-8cf3-4a19-b4ac-f5f0b78e4d18` — UTF-16LE, present exactly
      while an admin/setup password is set, and an `ff ff ff ff` unset sentinel
      once cleared. Found **without touching a BIOS** by diffing two identical
      EliteBook 830 G5 units across their entire UEFI variable set, then
      corroborated **8/8** against SMBIOS Type 24 from an independent source. It is
      consulted **last**, so it can never contradict a stronger layer and changes
      no verdict on the current fleet — the value is coverage for HP business
      firmware that omits the Type 24 table. The consumer Insyde platform has no
      `UserCred` variable at all, so the `UNKNOWN` class is **unchanged and still
      open, though no longer *unexplained* — see v1.11.51 below. Beware the
      size: the 830 G5 reads 3536 locked / 4544 unlocked, but the locked ZBook
      also reads 4544 — size is not the signal, the record is.
      → **Shipped v1.11.51** (2026-10-10): an `UNKNOWN` verdict now says *why*.
      `36_bios.sh` distinguishes "this firmware publishes no password state"
      (interfaces present — e.g. hp-bioscfg — but none publishes a password) from
      "a source existed but could not be read", and records it in
      `BIOSLockMethod`: `.170` reports
      `NONE (no password state published; sources present: firmware-attributes)`
      while the Intel NUC (`.175`) reports `NONE (no password interface
      published)` — the two `UNKNOWN` machines are finally distinguishable. It
      reaches the CSV, the manifest and the diagnostics PDF, and the dashboard
      badge tooltip. **No verdict changes.** It also fixed a dead guard:
      `dmidecode -t 24` exits 0 and prints a three-line header even with **no**
      Type 24 record (measured on `.170`: 67 bytes, 0 `Password Status` lines), so
      presence now comes from a `Password Status` line and a *refusing* dmidecode
      is reported as unreadable rather than as "publishes nothing".
      → Plan: `research/bios-unlock/20-insyde-setup-password-state.md` (§6 P3).
- [x] **HP BIOS password clear over WMI** — clear a Setup Password from Linux when
      the operator supplies it. The sysfs path cannot do it on HP: these machines
      expose **no** password object at all, and the driver's own request frame is
      malformed (its `new_password` attribute is inert). **Proven on hardware
      2026-10-10**: the three-element frame
      `[Setup Password][<utf-16/>][<utf-16/><current password>]` sent through
      `WMBS` method 1 cleared the Setup Password on `192.168.0.171` (EliteBook 830
      G5) — confirmed by POST no longer asking for it. (§8)
      → **Shipped v1.11.46** (2026-10-10): a deliberately narrow, **write-only**
      kernel module (`hp_biospw` — hardcoded GUID and method id, one root-only
      interface, validated element sizes, the password never logged) carries the
      frame, built into the image by `build_tscrub.sh` via
      `board/shredos/modules/build-into-overlay.sh` — no kernel patch, no kernel
      rebuild — with a second strategy in `38_bios_unlock.sh` and a **mandatory
      corroboration step** (success is reported only when a privileged write the
      firmware refused beforehand is then accepted). Field-proven on an EliteBook
      830 G5: cleared from Linux, confirmed gone after a reboot, and independently
      by the firmware's own Type 24 reporting `Disabled`.
      → **v1.11.47** fixed the gate: the GUID literal had been retyped by hand
      from a wrapped read and carried a wrong final digit, so every HP machine
      **skipped** the WMI layer and the sysfs path falsely reported `cleared`
      while the BIOS still demanded the password. The gate now matches
      case-insensitively on the GUID's first field, sysfs can no longer over-claim,
      and the tests use the real device name so either mistake fails the build.
      → **v1.11.48** folded the clear into the single `device_commands` queue.
      → Plan: `research/bios-unlock/19-shipping-plan-wmi-clear.md`;
      evidence: `research/bios-unlock/18-live-wmi-probing-results.md`.

### 4.7 Hardware diagnostics (§11.2)

- [x] Hardware-diagnostics test suite — RAM stress, battery, input, display,
      audio/mic, webcam, USB, network, fingerprint, CMOS/accelerometer.
      (§11.2) → Plan: `research/hardware-diagnostics/plan.md` (tiered diag:: suite;
      Shift+D triage flow; JSON-only results).
      → **Shipped v1.11.27** (2026-10-08) as a two-tier suite, then reshaped by a
      long iteration — **v1.11.40 removed the automatic tier entirely**, so the
      suite today is **guided-only, 6 tests**: display, keyboard, touchpad, USB,
      speaker and microphone, entered with `Shift+D` on triage (`--diag` /
      `tscrub_diag=1` still parse but run nothing automatically; the headless
      fallback runs all 6 so unattended boots still record them). `--selftest`
      (CPU + per-drive SMART/NVMe short self-test) is unchanged. Along the way
      v1.11.29/30 fixed the storage self-test stall and the muted-mic false-FAIL,
      and v1.11.31–v1.11.42 rebuilt the operator flow: two-phase keyboard test,
      a real touchpad **movement** test, I2C-HID touchpad detection (the ZBook
      Fury's Elan pad), live USB detection, a continuous + quieter speaker tone,
      storage self-test moved last, and prompt alignment. ALSA was added so the
      speaker plays a real 1 kHz tone and the mic records + auto-scores a
      capture. Surfaced as `diagnostics` + `diagnostics_summary` in the report
      and Devices tab. **Note the tests the removed automatic tier covered (CPU,
      RAM, storage, network, battery, peripherals, webcam) no longer run at all.**
- [ ] Diagnostics breadth vs Blancco/BitRaser — the suite is deliberately narrow
      today (6 guided tests). **Battery (incl. a discharge test), RAM/CPU
      burn-in, network, webcam and the storage self-test** are not in the guided
      flow at all since v1.11.40 removed the automatic tier, and
      **fingerprint, touchscreen, Bluetooth, CMOS/RTC, accelerometer/angle
      sensor, optical drive and BIOS-logo** tests were never built. (§11.2)

### 4.8 Autopilot robustness (§11.3)

- [ ] Per-device hash cache — persist the hardware hash by serial/uuid; recompute
      only when identity fields change. (§11.3)
- [ ] Rotating / cloud-issued Azure creds — rotate secret on abuse signal +
      optional short-TTL app credential (documented in `ops/`). (§11.3)

### 4.9 Reporting polish (§11.4)

- [ ] Captured signature images — optional PNG signature upload rendered by
      `render_cert.php`; text attestation stays the default. (§11.4)
- [ ] Arbitrary custom fields — `--field name=value` / `tscrub.conf` carried
      through to CSV + certificate. (§11.4)
- [ ] Barcode scan + label printing — barcode/asset-id input, dashboard "print
      label" (TCPDF), validate barcode against the device record. (§11.4)
- [ ] i18n + keyboard layout + network proxy — locale string table +
      `tscrub_proxy=` passthrough; defer until a non-UK ask. (§11.4)
- [ ] Accessibility (Section 508-style) — audio prompts / text-to-speech for
      visually-impaired operators (Blancco advertises 508 support). (§11.4)

### 4.10 Drive-side niceties (§11.1)

- [ ] Drive LED "locate" — `sg_ses`/enclosure-services blink the selected
      drive's LED. (§11.1)
- [ ] Drive-side niceties — SMR detection flag, SED crypto-erase progress %,
      ATA Device Unlock Password utility, BitLocker volume detection. (§11.1)

### 4.11 Hot-plug & discovery

- [x] Hot-plug drive detection — `device::discover` runs once at boot, so a
      drive connected after tScrub starts is invisible until reboot. Watch for
      hot-plug (udev monitor, or a periodic re-scan of `/dev/sd*`/`nvme*`/
      `mmcblk*`) and re-run discovery so newly connected drives appear in the
      triage/wipe flow and the diagnostics report without a reboot.
      → **Shipped v1.11.21** (2026-10-07): a `/sys/block` poll (devtmpfs image,
      no udev) diffs the device set; on change `device::rediscover` re-scans,
      probes only the newcomer (capability + pre-wipe SMART), re-renders with a
      `[+]/[-]` notice and re-pushes the diagnostics snapshot. Gated to the idle
      triage screen (never mid-wipe); USB-attached drives stay excluded.
      Plan: `research/hot-plug-drive-detection/plan.md`.

## 5. Dashboard & scale

- [x] **Devices tab SQL-side pagination** — `load_devices()` currently
      JSON-decodes every report payload on every load; denormalize a small
      summary (`device_key`/`summary_json`/`search_text`) onto `reports` and
      switch to a two-phase read (metadata over distinct devices + detail for
      the page only), keeping search/sort/API byte-identical.
      → **Shipped 2026-10-08** (commit `004653f`, server + dashboard): `reports`
      gains `device_key`/`summary_json`/`drive_serials` + the
      `idx_reports_user_type_{ts,key}` indexes (self-healing in
      `reports_ensure_schema()`); `load_devices($id, $query)` folds
      mdm/remote/presence + filter/sort/pagination internally and `/api/devices`
      is now a thin wrapper. Page-1 93ms → 53ms — most of the cost was the
      idempotent `*_ensure_schema` DDL re-running on the read path, now guarded
      by `db_table_exists()`. Plan: `research/devices-pagination/README.md`.
- [x] **Drives tab SQL-side pagination** — same treatment for `load_drives()`
      (erasure payloads are fully decoded today). Defer until devices is proven.
      → **Shipped 2026-10-08** (same commit): `load_drives($id, $query)` with a
      `drives_paged()` fast path for the default (no-search) view; search/export
      keep the full-decode path so results stay byte-identical.
      → Plan: same `research/devices-pagination/README.md` (see open questions).
- [x] **Per-device Log + Chain of Custody on the record** — one reverse-
      chronological list of every recorded event for a machine (report
      submissions, remote commands and their outcomes, Autopilot checks and hash
      captures, registrations, drive results, issued certificates) with labelled
      record references and View/PDF on report rows, replacing the row's Reports
      expansion (which was a second list of the same submissions). Diagnostics
      submissions now carry the appliance's CoC, and a remote command's outcome
      is stamped with the CoC of the session that *executed* it — so a remote
      erase or BIOS clear is attributable next to the report sharing its CoC.
      → **Shipped v1.11.48** (2026-10-10; server + dashboard + appliance).
- [ ] **Drives pager loading indicator** — the Drives tab clears its rows before
      the next page arrives, so a page change flashes empty instead of showing a
      spinner the way the Devices tab does.
