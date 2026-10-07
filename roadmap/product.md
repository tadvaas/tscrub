# Product — working backlog

Detail lives in `ROADMAP.md` §5 (appliance ops), §8 (BIOS unlock), §9
(BitRaser-vs-Blancco gaps), §11 (BitRaser BDAD teardown). Order = highest impact
first. Tick items off as they ship and note the release that carries each.

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
  > Reality: writable password attributes exist only on Dell
  > (`dell-wmi-sysman`), Lenovo (`think_lmi`) and HP (`hp-wmi`); most vendors
  > report `unsupported`. In-house SPI bench research lives in
  > `research/bios-unlock/`.
- [ ] **Appliance ops polish** (§5) — serial console (`CONFIG_SERIAL_8250`),
      `CONFIG_VIRTIO_NET` for faster VM testing, quiet `sedutil-cli` SG_IO noise
      on QEMU disks.
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

## 4. Long tail (pick up as milestone room allows)

- [ ] Fleet wipe job queue — dashboard queues a wipe per serial; appliance polls.
      (§9.3)
- [x] **Machine-readable certificates** — signed JSON-LD beside the PDF for
      AI/automated auditors. (§6)
      → **Shipped 2026-10-06**: every certificate is also issued as a signed
      JSON-LD document at `/verify?cert=…&format=jsonld` (detached Ed25519 over
      JCS canonical bytes, RFC 3161 timestamp; public key at
      `/.well-known/tscrub-cert-key.json`; dashboard "JSON" download link).
      Plan: `research/machine-readable-certs/README.md`.
- [ ] Multi-pass software overwrite — `--method <standard>` mapped to nwipe
      patterns (DoD 5220.22-M, Gutmann, HMG IS5, PRNG). (§9.2, §11.1)
- [ ] eMMC/MMC media — add `mmcblk*` to discovery, erase via `blkdiscard`.
      (§11.1)
- [ ] Hardware-diagnostics test suite — turn capture into tests (RAM stress,
      battery, input, display, audio/mic, webcam, USB, network, fingerprint).
      (§11.2)
- [ ] Reporting polish — custom fields, signature images, barcode scan + label
      printing. (§11.4)
