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

- [ ] **Diagnostics & refurb grading** — collapse SMART pre/post capture into a
      drive grade (A/B/C) + resale health report (power-on hours, TBW,
      reallocated sectors) for ITAD resale. (§6)
- [x] **Windows key (DPK) injection into NVRAM** — shipped v1.11.1: write a new
      key into the OA3 UEFI variable (the BIOS derives the MSDM table from it at
      boot — no SPI flash, no table assembly, no checksum). Field-verified on HP:
      the key lives in `HP_OA3-<GUID>` in plaintext and is replaced in place; the
      worker is vendor-agnostic (locates the variable by content). Notes in
      `research/dpk-injection/oa3-uefi-injection.md`.
  - [ ] **Unlock locked OA3** — `HP_OA3_LOCK=1` is a one-way firmware lock that
        rejects `SetVariable` with `EFI_SECURITY_VIOLATION`; most shipped HP
        units are factory-locked. Investigate whether a BIOS reset / "restore
        factory keys" clears it, and whether HP exposes any reset path from
        Linux (the test units have no `firmware-attributes` / `hp-wmi`).
  - [ ] **Fresh-table injection** — a machine with no embedded key needs the
        vendor variable name/GUID + structure to create it from scratch (only HP
        mapped so far).
  - [ ] **Dell/Lenovo verification** — confirm the key sits contiguously in a
        UEFI variable on other vendors (content-scan is vendor-agnostic but
        unverified beyond HP).

## 3. Robustness & ops

- [ ] **Remote BIOS unlock robustness** (§8)
  - [ ] JSON-safe password handling — replace the `sed` extraction of
        `id`/`password` in `38_bios_unlock.sh` with a real JSON decode (or
        reject `"`/`\`/control chars server-side).
  - [ ] Dispatched TTL + requeue — a `dispatched` command whose result POST
        fails must not stay `dispatched` forever.
  - [ ] Purge password residue — purge `password_enc` on supersede + an
        unclaimed-command TTL.
  - [ ] Explicit slot targeting — prefer setup/`AdminPassword` over
        power-on/`SystemPassword`; re-verify after clearing.
  - [ ] Confirm the clear took effect — re-read the slot (wrong password vs
        non-writable attribute are indistinguishable today).
  - [ ] `uuid` in the claim (fall back to serial-only).
  - [ ] `curl -k` TLS clock-skew fallback (mirror `report::upload_http`).
  - [ ] Enforce the result state machine server-side (`dispatched` → result).
  > Reality: writable password attributes exist only on Dell
  > (`dell-wmi-sysman`), Lenovo (`think_lmi`) and HP (`hp-wmi`); most vendors
  > report `unsupported`. In-house SPI bench research lives in
  > `research/bios-unlock/`.
- [ ] **Appliance ops polish** (§5) — serial console (`CONFIG_SERIAL_8250`),
      `CONFIG_VIRTIO_NET` for faster VM testing, quiet `sedutil-cli` SG_IO noise
      on QEMU disks.
- [ ] **Organisations & seats** (§5) — lightweight workspace layer (email-invite
      + role, shared certs/COCID history) so one Team licence covers several
      operators. No SSO/portal.

## 4. Long tail (pick up as milestone room allows)

- [ ] Fleet wipe job queue — dashboard queues a wipe per serial; appliance polls.
      (§9.3)
- [ ] Machine-readable certificates — signed JSON-LD beside the PDF for
      AI/automated auditors. (§6)
- [ ] Multi-pass software overwrite — `--method <standard>` mapped to nwipe
      patterns (DoD 5220.22-M, Gutmann, HMG IS5, PRNG). (§9.2, §11.1)
- [ ] eMMC/MMC media — add `mmcblk*` to discovery, erase via `blkdiscard`.
      (§11.1)
- [ ] Hardware-diagnostics test suite — turn capture into tests (RAM stress,
      battery, input, display, audio/mic, webcam, USB, network, fingerprint).
      (§11.2)
- [ ] Reporting polish — custom fields, signature images, barcode scan + label
      printing. (§11.4)
