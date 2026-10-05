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
      → **Implemented (2026-10-05), awaiting release** — `device::scsi_sanitize_supported`
      + `device::exec_scsi_sanitize` in `product/src/32_device_scsi.sh`;
      plan `research/sas-sanitize/README.md`; tests `product/tests/test_scsi_sanitize.sh`.
- [ ] **SED (OPAL) unlock + erase** — add an OPAL path alongside NVMe/ATA:
      `sedutil-cli --query` → PSID revert when there's no LBA unlock, and surface
      the 3-state SED status as an explicit method. (§9.1)
- [ ] **RAID dismantling** — detect members in `gather_info` (`mdadm -E`
      superblocks, Intel VMD / `mpt3sas` controllers), mark "RAID member —
      dismantle in controller BIOS" on the triage screen, record a per-drive RAID
      flag. Never auto-break an array. (§9.1, §11.1)

## 2. Hardware capture → intelligence

- [ ] **Diagnostics & refurb grading** — collapse SMART pre/post capture into a
      drive grade (A/B/C) + resale health report (power-on hours, TBW,
      reallocated sectors) for ITAD resale. (§6)

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
