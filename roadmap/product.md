# Product — working backlog

Former `ROADMAP.md` §5/§6/§8/§9/§11 folded in below (master retired
2026-10-07 — see git history). Order = highest impact first. Tick items off as
they ship and note the release that carries each. **Last reconciled with
`CHANGELOG.md` (through v1.11.70) and the source tree on 2026-10-20.**

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
- [x] **Attested destruction for assets tScrub can't reach** — phones, tablets,
      kit that won't POST, and anything with soldered or unsupported media can
      never produce a report, so today they cannot appear on the certificate at
      all (the destroyed-decision is report-driven) and the operator keeps a
      parallel spreadsheet. Add an entry point that doesn't need a report, plus
      the evidence fields (method, reason, date, attester, vendor, witness,
      reference) that make intended destruction documentable — as NIST 800-88
      **Destroy**, kept unmistakably distinct from instrument-verified
      sanitisation on the same certificate. Server + site only. (§9.3)
      → **Shipped v1.11.54** (2026-10-13; hardened v1.11.55): **Add** on the
      Media and Devices pages records a unit with one media row per medium, or a
      standalone destroyed drive; the attester comes from the session, identities
      are unique per job (normalised serial + make + model), date/vendor/witness
      are captured, and removal is the correction path. Every line is marked
      *operator-attested — not instrument-verified*; attested media stay out of
      the verified list, NIST level and Annex A; and a pure-attestation job
      generates its own certificate. **Factory reset** (retained, never summed
      with destroyed) followed in v1.11.56 — see §4.2. Plan:
      `research/manual-destruction/README.md`.

### 4.1 Erasure depth (§9.2, §11.1)

- [x] Multi-pass software overwrite — `--method <standard>` mapped to nwipe
      patterns (DoD 5220.22-M, Gutmann, HMG IS5, PRNG), with a `Standard` column
      in `report::csv`. (§9.2, §11.1)
      → **Shipped v1.11.70** (2026-10-20): `--method zero|random|dod|dodshort|
      gutmann|is5enh` (`tscrub_method=` / `tscrub.conf`), `--passes 1..64`,
      `Standard`/`Passes`/`EraseEngine` in the CSV, manifest, Media drawer,
      certificate Annex A and JSON-LD, and the nwipe engine version recorded.
      Selection is CLI/cmdline/`tscrub.conf` only — the interactive triage
      picker below is still open. Plan:
      `research/multi-pass-overwrite/README.md` (records the keep-nwipe
      decision and the replacement effort estimate).
- [x] Interactive triage-screen standard picker — the six standards were
      reachable only from the CLI, kernel cmdline or `tscrub.conf`; a bench
      operator booting the stock ISO could not choose one. `m` cycles the
      standard, `p` cycles the rounds, the footer legend shows both plus the
      resulting pattern-pass count, and the selection screen repeats the choice
      next to the selected count.
      → **Implemented 2026-10-20, awaiting an appliance release** (it ships with
      the next ISO; the docs text must go out with the image, not before). Plan:
      `research/triage-standard-picker/README.md`.
- [ ] Overwrite-standard end-to-end coverage (outstanding from v1.11.70) —
      **(a) open:** an end-to-end run on a `virtio-scsi` disk (the only cheap
      path that reaches `CAP_SCSI_NWIPE`) asserting the nwipe argv, the
      `"dodshort","2","nwipe 0.41"` CSV row and the manifest keys, plus a
      no-flag control run proving the default argv is unchanged.
      🚫 **The Proxmox host `192.168.0.5` is OFF LIMITS** (operator directive,
      2026-10-21: "too crucial"). Do not run, create or inspect anything there.
      (a) therefore needs a machine the operator nominates, or a local QEMU VM;
      agree the target before writing the scenario.
      → **argv half shipped 2026-10-22** (`product/tests/test_nwipe_argv.sh`,
      **29 checks**, suite now 1202): the test stops the fake `nwipe` binary
      recording its own argv, so it reads the REAL command line
      `device::exec_nwipe` builds. Covers the default argv (`--autonuke`,
      `--method=zero`, `--nogui` only when `--help` advertises it, `--logfile`,
      `/dev/<dev>`, **no** `--rounds`), a configured `dodshort` + 2 rounds
      (`--method=dodshort --rounds=2`, `PASSES 6`, `ERASER nwipe 0.41`),
      one round never expressed as `--rounds=1`, clamp of `0`/`65`/`abc`, the
      unknown-method fallback to `zero` (reported, not silent), the engine's own
      round/pass line overriding the static table (4x3 → `PASSES 12`), and a
      non-zero engine exit reporting FAILED with the operator instruction.
      **Mutation-tested:** `passes > 1` → `>= 1` produces 5 failures; misspelling
      the nwipe method (`dodshrt`) is caught by two assertions.
      **Still open:** the VM end-to-end run — a stub cannot catch "real nwipe
      0.41 rejects our flag spelling", and only a real run shows the CSV row and
      manifest carry what the engine actually did.
      → **VM end-to-end run done 2026-10-22** (Proxmox `.85`, VM 9500,
      `tscrub_method=dodshort tscrub_passes=2`, COCID **95110**, report id
      **1309**): the run **completed in 51 s**, `Verify 1 passed`, and the report
      recorded `standard=dodshort`, `passes=6` (3 patterns × 2 rounds — the only
      way to get 6 from dodshort, so `--rounds=2` demonstrably took effect),
      `method=DoD Short`, `status=COMPLETED`, `verify_technique=sentinel`. The
      triage footer confirmed `DoD Short x2 (6 passes)` before the run.
      **⚠️ It also found a REAL DEFECT the stub could never catch:**
      `erase_engine` came back as **`nwipe`**, not `nwipe 0.41`. The probe used
      `nwipe --version`, which nwipe v0.41 **declares in its long-option table
      but never handles** (`case 0:` in `src/options.c` compares only
      force/autonuke/autopoweroff/…), so it prints nothing. The fake answered
      `--version`, so the suite stayed green. **Fixed** by probing `-V` first
      (the handler that actually prints `"%s version %s"`) with `--version` as
      a fallback, and by making the fake mirror the real binary (answers `-V`,
      exits 22 on `--version`) so a regression fails 4 assertions.
      **Correction to the expectation above:** the report has **no rounds
      column** — it records `Passes` (physical passes) and `EraseEngine`. There
      is nothing to "assert as rounds"; the pass count plus the engine string is
      the provenance. **CONFIRMED on the real engine 2026-10-22:** rebuilt the
      image (build-host commit `290aa92a`) and re-ran the same job — COCID
      **95111**, report id **1311**, `engine=[nwipe 0.41]`, `standard=dodshort`,
      `passes=6`, `COMPLETED`, `verify_technique=sentinel`. Report 1309 recorded
      `engine=[nwipe]`; 1311 records the version. **§4.1(a) is now closed**: the
      argv is asserted by unit test (33 checks, mutation-tested) and the recorded
      outcome is proven end to end on the real engine.
      **(b) shipped 2026-10-21** (`marketing/tests/overwrite_parse_test.php`,
      47 checks) — a committed server-side test for the parser → Annex A →
      JSON-LD chain: `parse_reports()` now delegates to `reports_header_map()`,
      `reports_row_value()` and `reports_drive_provenance()`, so the column
      contract, the `OverwriteStandard`→legacy `Standard` fallback, the
      verification fields and the chain through the certificate annex and
      JSON-LD are all testable from the CLI. The fixture is the REAL header +
      one row written by `report::csv`
      (`marketing/tests/fixtures/report-v1.11.73.csv`, regenerate with
      `product/tests/tools/gen_report_fixture.sh` after any column change), and
      the test also asserts the three helpers are actually CALLED by the
      importer.
- [x] Configurable write passes + custom algorithms — `--passes N` +
      `--pattern <zero|one|random|hex>` mapped to nwipe custom patterns; record
      a `Custom` method in the report. (§11.1)
      → **Partly shipped v1.11.70** (2026-10-20): `--passes N` (1..64) maps to
      nwipe `--rounds` and the report records the resulting pass count.
      Custom hex patterns remain open (the six named standards only).
- [ ] IEEE 2883-2022 Purge incl. TPM clearing — `tpm::clear` (`tpm2_clear -c
      platform` / PPI request at next boot) plus an explicit "IEEE 2883 Purge"
      method = disk erase + TPM clear, recorded on the certificate. (§11.1)
      → **Phase 1 shipped v1.11.73** (2026-10-21): `VerifyTechnique`
      (sentinel / overwrite-sample / ce-exempt) + `VerifyCoveragePct`, opt-in
      `--verify-coverage N`, and the CSV column `Standard` → `OverwriteStandard`
      so the word "standard" is free for the claim. No IEEE wording anywhere —
      see `research/ieee-2883-2022/PLAN-PHASED.md` §12 for the as-built
      deviations. Phases 2–3 (`--standard ieee2883` claim with the gates, then
      copy) remain open. **TPM clearing is an explicit non-goal** (BitRaser's
      twist, not needed for an honest claim) — split it out if it is ever
      wanted on its own.
      → **Phase 2 shipped v1.11.77** (2026-10-22): the opt-in claim, off by
      default. `--standard ieee2883` / `tscrub_standard=`; five gates per drive
      (technique, coverage, verification, completion, media scope) with the
      failing gate recorded in `SanitizationShortfall`, the level (`Clear` /
      `Purge`) in `SanitizationLevel`, and the certificate `Standard` row
      naming the standard only when every scored drive earned it, plus the
      footer caveat "IEEE 2883-2022 methods described, not IEEE certified".
      **Proven three ways**: awarded on the bench (`.13`, 5 drives / 4
      techniques), awarded with levels (certificate
      `COD-618815-RU-UID-1146-ADMJ`, `(Clear, Purge)`), and **refused** in the
      VM (2% sample, passing read-back → NIST + `G2 coverage 2% < 5%`,
      certificate `COD-649912-XS-CDB-0989-LUUK` contains no occurrence of
      "IEEE"). **Phase 2 complete 2026-10-22:** the `/verify` page now states
      the claim too. The rule moved out of `render_cert.php` into
      `server/claim_lib.php` (`cert_claim()`, `cert_claim_label()`,
      `cert_claim_caveat()`, `cert_claim_display()`), so the PDF and the page
      call ONE function instead of two implementations that drift. The page
      reads the per-drive claim from the stored JSON-LD document (it verifies a
      document, not the live table) and renders the row only when the job
      earned it: a refused job states nothing rather than newly asserting
      "NIST". Verified live: `COD-618815` shows `IEEE 2883-2022 (Clear, Purge)`
      plus the caveat; `COD-649912` and `COD-019035` contain the word IEEE zero
      times. Tests: `marketing/tests/claim_surfaces_test.php` (37 checks, incl.
      "the surfaces actually call the rule" and "the caveat is defined exactly
      once") + the rendered-PDF suite `cert_claim_line_test.php` (90).
      → **First hardware run 2026-10-22, gate confirmed, sampler not yet
      exercised** (`192.168.0.95`, HP EliteBook 830 G5, Samsung PM981a
      `MZVLB512HAJQ`, v1.11.73, netboot entry with `tscrub_verify_coverage=5`):
      the drive accepted `NVMe Format`, so nwipe never ran and the record
      correctly carries `VerifyTechnique=sentinel` with an empty
      `VerifyCoveragePct`, and the certificate prints "Sentinel" — the gate that
      stops a percentage being claimed after a firmware erase works on real
      hardware. Certificate `COD-019035-LH-OEA-6893-STSO`, 26 s run, SMART PASS
      before and after.
      → **Sampler fixed and proven, v1.11.75** (2026-10-22). The field test
      exposed two defects that made `--verify-coverage` inert on every drive:
      each range was compared against `/dev/zero` (unbounded, so `cmp` could
      never report equal) and the comparison's exit status was captured from an
      assignment instead of `cmp`. Both fixed; six tests now run the real
      primitives instead of stubbing them (the stubs are why v1.11.73 shipped
      green). Proven in a **virtio-scsi VM** — the only easy route to the nwipe
      path, because USB-attached drives are excluded from discovery:
      `VerifyTechnique=overwrite-sample`, `VerifyCoveragePct=5` on a 2 GB disk
      (100 MB as 20 ranges), and certificate `COD-945244-FY-NIH-2986-BUSI`
      prints "Sampled 5%". This also closes §4.1(a): the virtio-scsi overwrite
      run is now an on-demand test (VM 9500 `tscrub-coverage-e2e` on Proxmox
      `192.168.0.85`).
- [ ] RAID controller physical-drive erase — bundle `megacli`/`hpacucli`, add a
      `device::raid_erase` path mapping a drive to its controller erase command,
      gated behind the "never auto-break an array" rule. (§11.1)
- [ ] NVMe namespace delete/recreate — guarded `nvme ns` helpers behind an
      explicit "manage namespaces" toggle; low priority (format/sanitise already
      cover it). (§11.1)
- [ ] eMMC/MMC media — add `mmcblk*` to `device::discover` (currently excluded),
      erase via `blkdiscard` (no ATA/NVMe sanitise available). (§11.1)
- [x] Reformat SATA/SAS drives after erasure — leave a drive in a clean,
      re-partitioned state (Blancco ships this; auditors sometimes expect a
      blank partition table rather than an unformatted disk). (§11.1)
      → **Shipped v1.11.56** (2026-10-16): automatic post-wipe formatting —
      single partition, exFAT >32 GiB / FAT32 ≤32 GiB, MBR-first, tool-chain
      cascade (sfdisk/sgdisk, dosfstools/busybox, exfatprogs/exfat-utils),
      recorded across CSV/manifest/finish-screen/dashboard/cert/JSON-LD. Plan:
      `research/drive-formatting/README.md`; tests `product/tests/test_format.sh`.
- [x] NVMe software-overwrite fallback — a drive that advertises sanitize/format
      but rejects every firmware erase command (a Samsung PM981a answers
      `0x2001 Invalid Command Opcode` to both Format NVM and Sanitize, with no
      Opal SED) used to end `FAILED` even though it is perfectly writable. A
      single-pass nwipe overwrite is now recorded honestly as `Clear` /
      `nwipe Quick` and scored by the normal post-erasure verification;
      Block-SID refusals (`0x4286`/`0x4015`) are excluded so the PSID/PPI path is
      never masked. `--nvme-software-fallback=auto|off`. (§11.1)
      → **Shipped v1.11.61** (2026-10-17). Plan:
      `research/nvme-software-fallback/README.md`.
- [x] ATA/NVMe freeze-lock removal + Block-SID detection — clear the ATA
      security freeze lock by suspend/resume instead of failing the wipe,
      retry a failed NVMe sanitize after one suspend, and detect/record
      Block-SID lockdown with vendor-tuned finish-screen guidance. (§11.1)
      → **Shipped v1.11.57** (2026-10-16): `device::unfreeze` (was
      `device::frozen`), `--flr=auto|forced|disabled`, NVMe retry-after-suspend
      inside `device::exec_nvme`, `device::lock_hint` vendor hints, three new
      CSV columns `FreezeLock,BlockSID,FLRAttempts`, and a parsed
      `--disable-block-sid` policy flag. Plan:
      `research/freeze-lock-block-sid/plan.md`; tests `product/tests/test_frozen.sh`
      (12) + `test_execute.sh` (27).
      → **Automatic PSID revert — shipped v1.11.58** (2026-10-16): a locked SED
      is PSID-reverted from a `psids.conf` serial→PSID map (v1.11.59 keys success
      on sedutil's own marker; v1.11.61 loads the map from a URL or path as well
      as the boot USB; v1.11.67 re-issues the sanitize after the unlock, because
      a revert on an unprovisioned drive destroys no media key; v1.11.68 stops
      claiming `PSIDRevert=yes` when `Locked=N` + `LockingEnabled=N` proves there
      is no key to destroy; v1.11.69 always uses the map, with no prompt).
- [x] Block SID / SED lock parity — sedutil fallback + TCG lock classification.
      `device::psid_revert` cascades to `--PSIDrevertAdminSP` (BitRaser method 2),
      and a locked SED reports `LockKind=locked` with the honest "TCG locked —
      physical destruction required" method while a purge-gated drive reports
      `LockKind=password` (Blancco's "TCG password" shape) instead of one BLOCKED
      bucket. (§11.1)
      → **Shipped v1.11.58** (2026-10-16), including `PSIDRevert`/`LockKind` on
      the CSV, dashboard, certificate Annex A and JSON-LD; **v1.11.60** fixed the
      Media export whitelist that had been silently dropping them. Plan:
      `research/psid-revert/phase-2-competitor-parity.md`.
- [x] PPI "disable block SID authentication" (the only in-place route for a
      Block-SID-locked NVMe). The request is submitted through the spec-conformant
      TPM PPI sysfs (op 97 = Disable_BlockSIDFunc, TCG PPI v1.30 ACPI `_DSM`), with
      a vendor UEFI-variable fallback, one reboot, and `PPIRequested`/`PPICleared`
      on the report. **v1.11.60** request + reboot; **v1.11.64** op-97 `_DSM`,
      `--ppi-op`, resume marker; **v1.11.65** `--ppi=auto` + per-drive terminal
      status; **v1.11.66** shipped in the image; **v1.11.68** reads `request` /
      `response` back so it cannot reboot forever, and stops faking a PSID revert;
      **v1.11.69** made the path automatic and deprecated every `--ppi*` /
      `tscrub_block_sid*` parameter (old PXE configs still boot).
      ⚠️ Hardware UAT (ThinkPad X13 + Micron MTFDHBA512TDV): firmware acknowledges
      op 97 with `Success` but the **Lenovo platform gate persists** (`0x4015`), so
      the honest routes remain PSID revert (where provisioned) or the BIOS toggle.
      Plan: `research/block-sid-ppi/plan.md`; tests `product/tests/test_ppi.sh`.
- [ ] Resume an interrupted erasure — re-enter and continue a wipe that lost
      power or was aborted without re-consuming a licence (Blancco). Today a
      worker death normalises to `UNKNOWN`, no resume. (§11.1)
- [x] NVMe sanitize stall detection + pre-flight — a drive whose sanitize state
      machine wedges (`SSTAT=0x2` with a frozen `SPROG`) used to read as
      "in progress", burn the full 6 h cap, then fail with no reason; and a run
      started on a drive that was *already* sanitizing issued a doomed command.
      Both are fixed: the state is sanity-checked **before** the wipe is issued
      (fails in seconds with a power-cycle instruction), and during the wipe a
      frozen `(SSTAT, SPROG)` pair for 15 minutes reports `STALLED`. This is an
      *inactivity* detector, not a duration limit, so a slow but progressing wipe
      is never killed; `STALLED` is terminal everywhere it matters (report sweep,
      drive table, presence counters, run completion) and the certificate says
      "Not sanitised — sanitize stalled mid-operation (power-cycle required)".
      (§11.1)
      → **Shipped v1.11.54** (2026-10-13). Plan:
      `research/nvme-stall-detect/README.md`.

### 4.2 Media & platform coverage (§9.1, §6)

- [ ] Mac (Intel + Apple Silicon) erase — pull-the-drive workflow + docs, not a
      Mac boot; declare Apple Silicon's Recovery-only erase on `compare.html`.
      (§9.1) → **Plan**: `research/macos-boot/plan.md` (status: awaiting review;
      no product changes made).
- [ ] Chromebook erase — dev mode / SeaBIOS boot → wipe eMMC/NVMe; capture
      ChromeOS GBB/VPD enrolment flags. (§9.1)
- [ ] Broader media coverage — USB/SD targets and RAID/FC/iSCSI volumes (the
      long tail beyond eMMC). (§6)
      → **Status 2026-10-26:** protection and USB erasure shipped in v1.11.79
      (software overwrite only), verified on hardware: the running stick and a
      second tScrub stick are both refused by label. **Open blocker found on the
      bench:** a run self-terminates after ~7 minutes with nwipe at 8% and no
      report produced, twice in a row, so the abort is tScrub's exit and not the
      media. Next: `bash -x` trace to name the branch. Then fix three defects:
      no artefact when a run ends early, a sanitize-in-progress drive shown as
      FAILED, and a heartbeat that cannot name the failed device. Details in
      `research/usb-target-erase/README.md` (local, gitignored).
      → **Resolved 2026-10-26 (v1.11.80).** The trace named it: **bash octal**.
      nwipe formats progress as `%05.2f`, so the status word is `08%`, and `08`
      is an invalid octal literal whose rejection is *fatal* to a non-interactive
      shell — the whole UI process exited mid-erase and orphaned nwipe. `07` is
      valid octal, which is why it was always ~7 minutes in. Fixed with `10#` at
      the producer, the IPC ingestion point and both aggregator branches (plus
      the NVMe SSTAT/SPROG parse), and the regression test was mutation-checked.
      All three recorded defects are fixed in the same release: an append-only
      synced `run-journal.txt` plus an EXIT/INT/TERM trap means any run that ends
      leaves a durable `ABORTED` record; an `nvme sanitize-log` state of 2 is now
      **waited for** (progress reported, stall detector unchanged) instead of
      being reported as failed; and the heartbeat carries `failed_devices`
      (`"<serial> (<reason>)"`), shown in the dashboard status tooltip.
      → **USB plan written 2026-10-26**: `research/usb-target-erase/README.md`.
      The hard part is not discovery, it is that the appliance is often *running
      from* a USB stick: root, the licence and the report destination can be the
      same stick we are asked to erase, and erasing it destroys the only artefact
      the customer bought. Plan derives a **protected set of whole disks** at
      runtime (root's backing device, `LICENSE_USB_DEV`, any volume holding
      `boot/version.txt`, everything in `/proc/mounts`, `root=`/`live-media=`),
      resolves partition→disk with `PKNAME`, and protects the whole parent disk —
      necessary because a dd'd hybrid ISO keeps its read-only ISO9660 volume and
      its writable `TSCRUB-USB` partition on **different devices of the same
      disk**. Phased: **P1 lists USB rows but does not allow selecting them**
      (safety property proven first, zero risk), P2 adds nwipe/SAT erase, P3
      reviews claim wording (USB stays out of scope for G5/IEEE), P4 optional
      `--retire-boot-media` **deferred** — a mode that destroys its own root can
      only be tested on sacrificial hardware and a bug in the ordering loses the
      report.
      → **P1 shipped 2026-10-26** (unreleased): protected-disk set + USB rows
      visible but unselectable; suite 1235 checks. P2 (actual erase) not started.
- [x] **Factory reset for devices tScrub cannot boot** — switches, routers, IP phones
      and phones reset by the operator with their own facilities and put back into
      service. Recorded through the existing attested path (same modal, same
      `operator-attested — not instrument-verified` qualifier) rather than as a third
      claim class: the outcome is *derived* from the method, so a retained device can
      never be certified as destroyed, and destroyed and retained figures are never
      summed. No declared sanitisation level — a reset is not a verified Purge. (§9.1)
      → **Shipped v1.11.56** (2026-10-16). As built:
      `research/attested-sanitisation/README.md` (superseded design:
      `research/attested-sanitisation/README-superseded-plan.md`)
- [ ] Legacy IDE/PATA — declared gap: state "SATA and newer" explicitly on
      `compare.html` (not planned). (§9.4)

### 4.3 Verification & trust (§6, §9.2, §9.4)

- [x] Canary self-audit on every wipe — write an unforgeable sentinel to random
      LBAs before erasing, verify it's gone after, record the result. (§6)
      → **Shipped v1.10.15** (2026-10-04): per-run sentinel planted at 5 fixed
      positions (0/25/50/75/tail), read back post-wipe and sha256-compared;
      modes `none|sampled|full`, recorded as `Verify/VerifySectors/VerifyResult`
      (see §1). Remaining nuance: positions are fixed, not random-LBA.
- [x] Offline / air-gapped verification — self-contained certificate QR
      (embedded signature + key) so a phone verifies with zero network. (§6)
      → **Shipped v1.11.74** (2026-10-21, server + site; the appliance image is
      unchanged). Plan: `research/offline-verification/README.md`.
      The printed QR is `https://tscrub.com/verify-offline#c=2|<kid>|<cert_id>|
      <issued_at>|<sig>`: a URL so any camera opens the verifier, with the code
      in the fragment so it never reaches the server. The key is **pinned in the
      page** (kid `tscrub-vendor-1`, fingerprint `be81586c…5802`, also published
      at `/.well-known/tscrub-cert-key.json`) and an unknown kid is a rejection.
      A pass means the certificate was issued by us at that time and not altered;
      the page says plainly that it does not prove a drive was erased and offers
      the online record. Service worker keeps it working with no network.
      **Mechanically verified end to end (2026-10-22):** rendered a certificate
      carrying a real marker on the server, rasterised page 1 at 400 dpi,
      decoded the QR with OpenCV — 189 characters, byte-identical to the marker
      that was signed — then verified that exact value on the live page. The same
      check found that changing only the `#c=` fragment of an already-open page
      did nothing; fixed and redeployed.
- [ ] Overwrite-pattern verification + hexviewer — verify software-overwrite
      patterns were actually written (read-back compare) and add a hexviewer
      visual check for compliance (Blancco). Distinct from the sentinel check
      above — this audits the overwrite itself, not just the final state. (§9.2)
- [x] IEEE 2883 awareness — audit `compliance.html`/`docs.html`; map methods to
      IEEE 2883 terminology or state the NIST 800-88 target explicitly. (§9.2)
      → **Shipped**: compliance copy states the NIST 800-88 target explicitly
      and disclaims certification (never claims IEEE 2883).
- [~] Third-party testing / certification — prepare a certification submission
      + application runbook as `ops/certification.md`; never claim what we don't
      hold. (§9.4)
      → **Runbook written** (2026-10-22), **nothing submitted**. `ops/certification.md`
      covers the ADISA PCT/PA schemes (requirements sourced from ADISA's own
      pages), the evidence pack we already hold (frozen build identity from
      `download-manifest.json`, offline licence provisioning, 86-column CSV +
      Ed25519, JSON-LD, offline QR), eight gaps to close first — headed by the
      untested `--verify-coverage` on real hardware — and a claims register of
      what may and may not be said today (no ADISA, no NAID, no ISO 27001, no
      "IEEE 2883 certified"). First submission should be ADISA **PCT** with a
      single narrow claim sentence; **PA** can be NIST-only, which avoids both the
      IEEE PDF purchase and the 5% sampling bar.

### 4.4 Fleet & integrations (§6, §9.3)

- [ ] Fleet Management Console — multi-site dashboard, role-based access,
      remote licence/job control, full admin audit trail. (§6)
- [~] Integration API + webhooks — documented public API + events
      (`certificate.ready`, `report.uploaded`). (§6)
      → **Partial:** the `/api/*` surface exists (session + `X-Api-Token`), but
      `docs.html` documents no API and no webhooks are emitted.
- [ ] ITSM/ERP integrations — OpenAPI doc + webhooks, then one reference
      ServiceNow inbound-webhook script. (§9.3)
- [ ] Customized erasure report — account-level report template (logo, extra
      header fields, column visibility) applied in `render_cert.php`. (§9.3)
- [ ] Customer-customized ISO — `make branded-iso CUSTOMER=…` target in the
      build fork + a one-page how-to. (§9.3)
- [~] Report search & export API + embed reports on the drive — public
      search/export over stored reports, and the ability to write the report
      onto the wiped drive for fast offline audit (Blancco). (§9.3)
      → **Partial:** the dashboard Media export (`GET /api/drives/export`) ships;
      there is no public/API-token search+export and no on-drive report.
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
- [ ] **Self-contained netboot appliance** — one Ubuntu Server x86 router box:
      1 WAN + 7 LAN (discrete Intel NICs), optional 5G M.2, USB-C PD, no Wi-Fi.
      Runs the router, DHCP, TFTP, HTTP and the iPXE chain that boots tScrub;
      every connected machine gets dashboard egress (30 s heartbeat,
      diagnostics and report uploads), with a store-and-forward spool as the
      fallback. Three DHCP modes — router (default), proxyDHCP (coexist with an
      existing DHCP server) and cooperate (their DHCP points option 66/67 at
      us); a second authoritative DHCP on a shared L2 is explicitly
      unsupported. Boot chain and image are unchanged.
      → Plan: `research/netboot-appliance/README.md` (DHCP coexistence
      analysis in §2; open decisions in §12).

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
- [x] **Derived identity for placeholder-serial machines** — QEMU/KVM VMs and
      whitebox boards ship a placeholder SMBIOS serial/UUID (`To Be Filled By
      O.E.M.`, the shared QEMU UUID sentinel). The appliance normalised those to
      `N/A`, which `/api/heartbeat` rejects (`Serial required.`), so the machine
      was invisible in the dashboard while wiping. (§6)
      → plan: **`research/derived-identity/README.md`**.
      → **Shipped v1.11.53**: when the serial is `N/A`, `system::derive_identity`
      mints a stable `WB-<12hex>` from the NIC MACs (disk serials as fallback)
      and, if the UUID is also a placeholder, a synthetic v5-shaped UUID — so
      heartbeat, registration, reports and MDM agree on one identity. Report
      JSON records provenance (`serial_source`, `system_serial_dmi`), and the
      server's `device_key()` skips placeholder values.

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

- [~] Per-device hash cache — persist the hardware hash by serial/uuid; recompute
      only when identity fields change. (§11.3)
      → **Partial:** the hash is persisted unassigned in `mdm_staged_hash`
      (claimed by the first report for that serial+uuid and never consumed), and
      `mdm_cached_verdict` reuses a real verdict for 24 h. There is no
      identity-change invalidation.
- [ ] Rotating / cloud-issued Azure creds — rotate secret on abuse signal +
      optional short-TTL app credential (documented in `ops/`). (§11.3)
      → Note: the multi-tenant probe pool (`autopilot_tenants` + Autopilot health
      gate) shipped 2026-09-30; no rotation runbook or short-TTL credential yet.

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
- [x] **Media pager loading indicator** — a page change flashed empty instead of
      showing a spinner. The Media pager now behaves like the Devices one: both
      buttons disable, a spinner shows, responses are sequence-guarded so two
      quick clicks cannot resolve out of order, and an unchanged payload skips
      the row render while still repainting the sort arrows.
      → Shipped in `[Unreleased]` (2026-10-18) — not yet in a tagged release.
- [x] **Sortable Media headers + Submitted date** — the Media table had no
      sortable headers and `/api/drives` accepted no `sort`/`dir`. Every heading
      except Actions now sorts both ways, server-side and before the slice, with
      **Submitted** first and the default order. Status sorts by
      `drive_status_rank()`, size parses text to GB (`-1` when unrecorded), and
      the tie-break is the serial. Size/Type moved to the Detail drawer but are
      still exported and searchable.
      → Shipped in `[Unreleased]` (2026-10-18) — not yet in a tagged release.
