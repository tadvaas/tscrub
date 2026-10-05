# tScrub Roadmap

Future plans for the project, kept here so they survive between sessions. Current
state as of **v1.10.14** (2026-10-03): triage-first boot, hardware grading, the
Windows Autopilot MDM check (standalone 4K-hash, billing-gated, WinPE removed),
remote power + remote erasure, the live erasure indicator, signed/attributed
reports, dashboard, certificates, customer-as-certifier, and Stripe PAYG billing
are all shipped.

## Now — product first, then monetisation, then marketing (2026-10-04)

Bias: ship a production-trustworthy product before spending on acquisition. Each
item cross-references the detailed backlog section it lives in. Working copies to
work off: `roadmap/product.md`, `roadmap/monetisation.md`, `roadmap/marketing.md`.

**1. Product — make it trustworthy and feature-complete first**

Erasure depth & verification (the biggest competitive gaps):
- Post-erasure verification (sampled read-back) — prove unrecoverability rather
  than assert it. (§9.2, §11.1)
- HPA/DCO removal & erasure — reset to native size + clear DCO before wiping,
  record a before/after pair. (§9.2, §11.1)
- SAS firmware sanitise — `sg_sanitize --overwrite --zero` before falling back to
  nwipe. (§11.1)
- SED (OPAL) unlock + erase — PSID-revert / crypto-erase path alongside the
  NVMe/ATA paths (sedutil-cli already ships). (§9.1)
- RAID dismantling — detect members, mark "dismantle in controller BIOS", never
  auto-break an array. (§9.1)

Hardware capture → intelligence:
- Diagnostics & refurb grading — collapse SMART capture into a drive grade
  (A/B/C) + resale report. (§6)

Robustness & ops:
- Remote BIOS unlock robustness — JSON-safe passwords, dispatched TTL/requeue,
  purge-on-supersede, explicit slot targeting, confirm the clear took effect.
  (§8)
- Appliance ops polish — serial console (`CONFIG_SERIAL_8250`), `virtio-net` for
  faster VM testing, quiet `sedutil-cli` SG_IO noise. (§5)
- Organisations & seats — one Team licence covers several operators. (§5)

**2. Monetisation — turn the product into revenue**
- Stripe key rotation — `sk_live` + webhook secret were pasted into chat and must
  be rotated before anything else. (§5)
- Subscription billing — wire the reserved `subscriptions` table to Stripe
  recurring prices (Team £99/mo → 100 erasures, Enterprise £399/mo → 500) so the
  "contact us" plans become self-serve. Biggest single revenue unlock. (§5)
- Pooled licence allocation + non-expiring PAYG credits — org admins issue
  sub-tokens against a pooled licence; credits stop expiring. (§9.3)

**3. Marketing — once the product is solid and money flows**
- Marketing analytics + Search Console — Plausible/GA4 + GSC so the programme is
  measurable. (§4.1, §5)
- Directory & association listings — ADISA/NAID/G2/Capterra/SourceForge +
  awesome-lists; real backlinks in an afternoon. (§4.3)
- On-page gap closure — FAQPage JSON-LD, title/meta rewrite, the "wipe an SSD
  from BIOS" coverage page. (§4.6)
- Citation-magnet tools — compliance checker, erasure cost/carbon estimator,
  device refurb grader. (§4.4)

**4. Long tail** — the rest of §6/§9/§11 (fleet wipe job queue, machine-readable
certificates, multi-pass overwrite, eMMC, hardware-diagnostics test suite,
reporting polish) as milestone room allows.

> Deliberately rejected (unchanged): auto-cert on upload and auto-licence-delivery
> — certificate generation and licence download stay behind dashboard login.

## 0. Triage-first conversion — IMPLEMENTED (2026-10-01) — released as v1.10.0

The appliance is now a **triage tool first, erasure tool second** (see
`triage-conversion.md` for the full plan + TODO):

- Boot connects to the network, submits the diagnostics report, and starts a
  **long-lived online heartbeat** (presence) plus the remote **BIOS-unlock** poll
  and the opt-in MDM check. These run for the whole session and are never killed
  by an erasure — the device stays online through and after a wipe.
- The default screen is a **triage screen** (live timer, LAN IP, MDM/BIOS lock
  status, drive inventory). Erasure only starts on **Shift+T**, returns
  seamlessly to triage afterwards, and is repeatable within the session.
- The elapsed timer starts **after COCID entry**; supplying a COCID via
  `--cocid` / `tscrub_cocid=` / `tscrub.conf` **no longer implies autonuke** —
  autonuke is now explicit (`--autonuke` / `tscrub_autonuke=1`).
- The post-erasure prompt (Reboot/Shutdown/Continue/Run-again) and the RERUN
  loop are removed; reboot/shutdown are disabled during erasure and only offered
  on the triage screen (R/S).

## 1. Self-built Buildroot appliance image — SHIPPED

**Done (2026-09-19):** a ready-to-boot hybrid ISO (BIOS + UEFI) is built on the
Buildroot fork (`~/shredos.x86_64` on the project server) and published as a
versioned download on the Download page. It boots tScrub as the sole
program (inittab tty1 → `tscrub_launcher`), bundles curl + CA certs for HTTPS
report upload, and ships `sedutil-cli` built in.

**Shipped since (through v1.4.34):** late-link DHCP re-request, restored Realtek
and Broadcom NIC firmware, a per-destination report-delivery summary, the amber
"wiped but not delivered" finish screen, the diagnostics snapshot, a TLS
clock-skew fallback for uploads, and the customer-as-certifier certificate model.
The physical hardware boot test (NVMe / SATA / SAS / USB) is also complete.
The package/kernel analysis below is kept for reference.

### Package set (what `tscrub.sh` actually calls)

| Package | Purpose | Required |
|---|---|---|
| `nvme-cli` | NVMe format/sanitise, `nvme id-ctrl` | yes |
| `hdparm` | ATA security-erase/sanitise, identify | yes |
| `nwipe` | SCSI software-erase fallback (`device::exec_scsi_nwipe`) | open decision |
| `dmidecode` | SMBIOS system info for reports | yes |
| `pciutils` (`lspci`) | hardware inventory | yes |
| `util-linux` (`lscpu`, `blockdev`, `rtcwake`) | CPU info, block queries, freeze-cycle wake | yes |
| `ncurses` (`tput`, `clear`) | terminal UI | yes |
| `openssl` (with Ed25519) | licence verify + report signing | yes |
| `sedutil-cli` | OPAL/SED unlock, PSID revert, Block SID detect | yes |
| `lftp` | FTP report upload (legacy `shredos_output=ftp:`) | optional |
| `curl` (TLS via OpenSSL) | dashboard push (`tscrub_upload=`) over HTTPS | yes (network push) |
| `ca-certificates` | TLS trust store for HTTPS uploads | yes (with curl) |
| `smartmontools` | SMART capture (pre/post wipe, `smartctl`) | yes |
| `sg3_utils` | SAS ops (not called by tScrub) | optional |

sedutil **is** in upstream Buildroot (`package/sedutil`, v1.20.0); the image
enables `BR2_PACKAGE_SEDUTIL=y` so `sedutil-cli` is compiled into the rootfs at
`/usr/sbin/sedutil-cli` — used by `device::install_sedutil` via PATH and available
as a shell utility for manual OPAL/SED work. For the image, `tscrub.sh` is built
**slim** (`make build-slim`, `SKIP_SEDUTIL_PAYLOAD=1`, no embedded payload);
`sedutil-cli` ships in the rootfs instead. tScrub is now distributed **only** as
the appliance image (bzImage/ISO) — the standalone script download is retired.

**HTTPS upload note:** stock ShredOS ships no TLS-capable `curl` and no CA
bundle, so the token-authenticated dashboard push (`tscrub_upload=https://…` +
`tscrub_api_token=…`) **no-ops on stock ShredOS** and falls back to USB/FTP. The
tScrub appliance image must therefore bundle `curl` (built against OpenSSL) +
`ca-certificates` (Buildroot `BR2_PACKAGE_CURL` + `BR2_PACKAGE_CA_CERTIFICATES`)
so reports can be pushed to `https://tscrub.com/api/reports` straight from the
appliance (shipped — the image bundles `curl` + `ca-certificates`).

### Kernel config areas (match ShredOS's drive ops)

- NVMe: `CONFIG_BLK_DEV_NVME` (+ multipath if needed)
- SATA/AHCI: `CONFIG_ATA`, `CONFIG_SATA_AHCI`, libata passthrough (hdparm ATA erase)
- SCSI/SAS: `CONFIG_SCSI`, `CONFIG_BLK_DEV_SD`, HBA drivers (`mpt3sas`, `megaraid_sas`, `aacraid`, `hpsa`, `smartpqi`)
- USB storage: `CONFIG_USB_STORAGE`, `CONFIG_USB_UAS`
- sysfs (`/sys/block`) — used by `device::discover`
- DRM — needed so `rtcwake -m mem` reliably wakes the display for the ATA freeze cycle

### nwipe (resolved: keep)

`product/src/32_device_scsi.sh` uses nwipe for SCSI/SAS drives that don't support
firmware sanitise/format.

- [x] Keep nwipe (GPL-2.0, small) as one more bundled package
- [x] ~~Replace `device::exec_scsi_nwipe` with a `dd`/`blkdiscard` zero pass and drop nwipe~~ — not chosen

### Build host

- Build on the project server: `ssh oxwet@192.168.0.6` (SSH key already added).
- Buildroot runs as the non-root `oxwet` user; the one-time host prerequisites
  (`build-essential`, `cpio`, `dosfstools`, `mtools`, `libncurses-dev`,
  `libssl-dev`, `git`, `unzip`, …) need a one-off `sudo apt install` — confirm
  oxwet has sudo, or have whoever does run that step.
- Needs ~8 GB RAM and several GB of free disk (ShredOS tree + downloaded sources).
- Keep the ShredOS `output/` directory on the server so overlay-only tScrub
  changes rebuild in minutes rather than a full cold build.

### Build steps

- [x] `configs/tscrub_defconfig` — copy of `shredos_iso_extra_defconfig` plus
  `BR2_PACKAGE_OPENSSL`, `BR2_PACKAGE_LIBCURL_CURL`, `BR2_PACKAGE_LIBCURL_OPENSSL`,
  `BR2_PACKAGE_CA_CERTIFICATES` (curl binary + TLS + CA bundle for HTTPS upload).
- [x] Overlay: `board/shredos/fsoverlay/usr/bin/tscrub.sh` (embedded `build/tscrub.sh`)
  + `usr/bin/tscrub_launcher`; inittab tty1 now boots `tscrub_launcher` instead of
  `nwipe_launcher` (tScrub = sole boot program). nwipe stays bundled (SCSI fallback).
- [x] `build_tscrub.sh` (`make tscrub_defconfig && make`).
- [x] Host deps installed; builds run (`build-essential file wget gzip bzip2 perl cpio unzip rsync bc python3 libelf-dev libssl-dev`).
      NOTE: `libelf-dev` is required by the kernel's `objtool` (`gelf.h`) — the
      build fails at `linux 6.18` without it. Re-run `make tscrub_defconfig` after
      any `configs/tscrub_defconfig` edit to regenerate `.config` before `make`.
- [x] Build + test on NVMe / SATA / SAS / USB hardware.

Implementation detail: we work directly in the ShredOS **fork** clone at
`oxwet@192.168.0.6:~/shredos.x86_64` (uncommitted until pushed to a fork repo).
Base is `shredos_iso_extra_defconfig` — hybrid ISO with an appended writable
`extra.vfat` (FAT16) partition, which is where reports and the licence live on
the boot stick. The licence can be dropped on that partition, baked in via
`make build-customer LIC=…`, or fetched at boot (`tscrub_license_url=`).

### Licensing notes

- Buildroot is GPL-2.0-or-later; bundle GPL packages as **separate programs**
  (aggregation) so `tscrub.sh` keeps its own licence.
- `tscrub.sh` is GPL-3.0-or-later (see the root `LICENSE` file); bundled
  components keep their own licences.

## 2. Related simplification backlog

- [x] Zero-touch config for PXE fleets (`tscrub_cocid=`, `tscrub_upload=`, and a
  `tscrub.conf` on the stick) — shipped
- [x] Auto-upload reports → auto Certificate of Destruction — **rejected** (deliberate:
  keep certificate generation on the dashboard so users log in). Reports stay
  first-class; certificates are generated on demand via `POST /api/certs`.
- [x] Billing (Stripe) + automatic licence issuance on payment webhook — shipped
  (webhook credits the wallet and auto-issues a `payg` licence)
- [x] Repo-root README + onboarding (the `/docs` page now covers the full
  account → licence → boot → report → certificate journey)
- [x] Licence-on-USB handling: prefer the highest tier (`enterprise` > `team` >
  `payg` > `free`) and warn when multiple `.lic` files are present — shipped in
  v1.4.53
- [x] Auto-licence-delivery — **rejected** (deliberate: users log in to download
  their licence; no presigned URL / auto-fetch).
- [x] Show licence info (customer / tier / expiry) in the TUI Runtime panel —
  shipped in v1.4.36
- [x] Standardise the licence filename to `.lic` — only `*.lic` is auto-detected on
  the USB (and `/etc/tscrub/license.lic` is the compiled default); `license.key` is
  retired so product code, tests and docs agree

## 3. Recently done (2026-09)

- Appliance releases through **v1.4.54** — BOM-tolerant `tscrub.conf`, blue TUI
  theme, full-width device table, sticky footer with brand/version line, and
  British-Time dashboard timestamps.
- **Standalone retirement** — tScrub now ships only inside the appliance image
  (bzImage/ISO); the "run the script directly" distribution is gone, and the
  marketing/docs copy no longer teaches manual `tscrub` invocation or `tscrub verify`.
- **Verification is automatic** — reports verify on dashboard upload; certificates
  verify via their QR code.
- Dashboard reports UX: loading spinner, one-line rows, 10-per-page pagination.
- Privacy: removed the public Updates/release-notes page; signing key moved behind
  login (`GET /api/signing-key`).
- Nav: "Compare" moved from the main menu to the Resources hub.
- Homepage terminal hero rebuilt to mirror the real tScrub TUI.

## 4. Distribution & growth — the runbook applied

The strategic layer. The product is built, and the **capture layer** (llms.txt,
sitemap, robots, Article/FAQ schema, the Resources content programme, and the
citation-magnet tools) is largely done — that is the cheap 80%. What is missing is
the **authority layer** (links, citations, launches), which the Distribution &
Growth runbook calls the hard 20% that actually produces clicks. This section is
that runbook mapped to tScrub's specifics, in execution order.

**Money terms** (the queries everything here is optimised for): *data destruction
certificate, certificate of destruction, disk sanitisation software, NIST 800-88
erasure, how to securely erase NVMe/SSD, DBAN alternative, ITAD software, data
erasure software, chain of custody report, GDPR data erasure, HIPAA data
disposal*.

**Gap types** (Phase 3): **A** = authority (impressions but position > 15) ·
**B** = snippet/CTR (position ≤ 15) · **C** = coverage (no page exists) ·
**D** = AI-invisible (absent from LLM answers). The fix differs per gap; the most
common failure is applying the wrong fix (building more content for an authority
problem).

Note on positioning: tScrub competes **with** Blancco/BitRaser/KillDisk, so the
runbook's "partner ecosystem" listings do not apply literally — authority here
comes from ITAD/erasure associations, software directories, and open-source
lists, not from joining a competitor's partner programme.

### 4.1 Phase 0 — one-time setup

- [ ] Verify `tscrub.com` in Google Search Console (Domain property).
- [ ] Add privacy-friendly analytics (Plausible preferred — no cookie banner) to
      every public page.
- [ ] Wire conversion events: `signup` (register), `purchase` (Stripe
      `checkout.session.completed` / `payment_intent.succeeded`), `activation`
      (first report upload → first certificate). The last two already fire
      server-side; the job is surfacing them to the analytics tool.
- [ ] Add the AI-crawler allowlist to `robots.txt` (GPTBot, ClaudeBot,
      PerplexityBot, Google-Extended, CCBot, anthropic-ai). `robots.txt` already
      blocks the auth pages and declares the sitemap; `llms.txt`, `llms-full.txt`
      and `sitemap.xml` are done and maintained.
- [ ] Save a dated baseline (4.2) before acting.

**Gate:** no 4.3+ work until GSC + analytics + events all fire. This item is
shared with the v1.5 milestone checklist ("Marketing analytics + Search
Console").

### 4.2 Phase 2 — baseline & monitoring

- [ ] Pull the last 28 days of GSC queries; bucket money terms into the A/B/C/D
      gap table.
- [ ] Run an AI-visibility pass (cited / mentioned / absent per money term).
- [ ] Record the baseline row: `date | clicks | impressions | avg CTR | # money
      terms page 1 | # money terms cited by AI | # referring domains`.
- [ ] Save the trend history (append, never overwrite).

### 4.3 Phase 7.1 — directory & association listings (do first, faceless)

Forms, not relationships — real backlinks from authoritative domains in an
afternoon.

- [ ] ITAD / erasure associations: ADISA, NAID (i-SIGMA), IASME, BSIA.
- [ ] Software directories: G2, Capterra, SourceForge, AlternativeTo, Slant.
- [ ] Open-source lists: awesome-devsecops, awesome data-protection /
      incident-response lists on GitHub (the repo is already public and GPL-3.0).
- [ ] Any marketplace/vendor directory that lists erasure tooling.

### 4.4 Phase 5 — tool building (citation magnets)

Already shipped and pitchable: the verifiable **Certificate of Destruction** +
QR (report-generator pattern), the **NIST 800-88 / wipe-methods / standards**
reference pages (definitive-answer pattern), **open, inspectable** source, and
the **signed report manifest**. Build the following to close AI-invisibility (D)
and earn links:

- [ ] **Compliance checker** — "which erasure standard applies to me?" maps
      GDPR / HIPAA / ISO 27001 / NIST 800-88 to Clear/Purge/Destroy (decision
      support → links).
- [ ] **Erasure cost estimator / carbon calculator** — per-device environmental
      + labour cost of destruction (numeric output → citable).
- [ ] **Device value / refurb grader** — SMART-driven resale grade (overlaps the
      north-star "refurb grading" idea; promote it when scheduled).

Each tool ships at a clean URL, is added to `llms.txt` + `sitemap.xml`, and is
**pitched** (4.5) — a tool only becomes a citation magnet once someone cites it.

### 4.5 Phase 7.2 / 7.3 — outreach (the loop that must not die)

- [ ] Build a target list (ITAD / data-destruction journalists, data-protection
      and compliance writers, MSP and ITAD blogs) with a named person + verified
      email per target.
- [ ] Automate personalisation — the LLM reads the target's recent coverage and
      writes the first line; a human approves, then sends from the business
      email. Never let the human step become "research from scratch".
- [ ] Track pipeline state: `researched → sent → replied → linked`.
- [ ] Pitch angle: **open, inspectable, verifiable erasure** — the customer
      certifies their own destruction, evidenced by tScrub.

### 4.6 Phase 6 — on-page gap closure

Apply the five-layer checklist (crawl/index, entity, on-page, LLM layer,
internal links) to every gap-closing page. Known quick wins:

- [ ] Add `FAQPage` JSON-LD that mirrors the visible FAQ (not currently present).
- [ ] Add a 2–3 sentence direct-answer paragraph under each H1 ("What is X?").
- [ ] Rewrite `<title>` + meta description on top pages to match the exact money
      query.
- [ ] Link money pages ↔ decision guides ↔ proof/tool in both directions.
- [ ] **"Wipe an SSD from BIOS" coverage page** — high-volume money-term query
      with no page (D-Secure ranks on it). Port the vendor menu table
      (Dell/HP/Lenovo/ASUS paths) + the frozen-drive fix into a
      `resources/wipe-ssd-from-bios.html` that ends on the tScrub comparison
      (verification + signed certificate), mirroring `ssd-erase.html`.

### 4.7 Phase 8 — paid (Google Ads), gated

- [ ] Only after organic + conversion tracking are healthy (events fire
      end-to-end before any budget is set).
- [ ] One campaign per audience (consumer vs commercial/ITAD), separate keywords
      and negative lists.
- [ ] Review at 6–8 weeks against CPA; CTR-high-but-zero-conversions → fix the
      landing page and tracking, do not raise budget.

### 4.8 Phase 9 — cadence

- [ ] Weekly (15 min): pull the GSC trend, run the AI-visibility pass, send 1–2
      pitches from the approved batch, fill one directory/association form.
- [ ] Monthly (1 h): re-read the gap analysis, build one page or tool for the top
      cluster, run one data-asset pitch, `build && deploy`.
- [ ] Quarterly (half day): regenerate any PDF/report asset, review referring
      domains gained vs target, decide whether authority is sufficient to turn
      on ads.

### 4.9 Guardrails (never break)

- Verified claims only — no invented clients, stats, case studies, or
  certifications.
- No fake reviews/testimonials; schema and copy match reality.
- Never claim a certification we don't hold (ADISA, R2, NAID, etc.) or on-site
  destruction when it's off-site.
- Personalise every outreach email — the LLM drafts, a human sends.
- Honesty filter: tScrub marks timing/does the job — never over-claim outcomes.

## 5. Next milestone — "Paid plans for real" (v1.5)

Theme: turn the advertised Team/Enterprise plans into self-serve, multi-user
subscriptions, and close the last trust/coverage gaps.

- [ ] Stripe key rotation — rotate `sk_live` + webhook secret, update
      `~/webs/tscrub-form/config.json` (security prerequisite, ~0 product code).
- [ ] Subscription billing (Team £99/mo · Enterprise £399/mo) — wire the reserved
      `subscriptions` table to Stripe recurring prices; the webhook credits the
      monthly 100/500 erasures and sets the `team`/`enterprise` tier automatically
      (today these plans are "contact us" only).
- [x] Organisations & seats — a lightweight workspace layer (email-invite + role,
      shared certs/COCID history) so a Team licence covers several operators.
      No SSO/portal. Shipped 2026-10-05 (server + dashboard).
- [x] SAS/SCSI erase-path proof — exercise the `nwipe` SCSI fallback on real SAS
      hardware and add a Proxmox SCSI scenario (the last untested wipe path).
- [ ] Appliance ops polish — serial console (`CONFIG_SERIAL_8250`), `virtio-net`
      for faster VM testing, quiet `sedutil-cli` SG_IO noise on QEMU disks.
- [ ] Marketing analytics + Search Console — add a privacy-friendly analytics tag
      (GA4 or Plausible) across the site and verify Google Search Console
      ownership, so the SEO/Resources content program can be measured (traffic,
      impressions, indexing, 404s).

Deliberately out of scope: auto-cert and auto-licence-delivery (rejected —
dashboard login is intentional).

## 6. Ideas / north-star (speculative)

Not scheduled — evaluated when a milestone has room.

- [ ] Machine-readable certificates for AI/automated auditors — ship each
      certificate as signed JSON-LD alongside the PDF so compliance platforms,
      procurement bots, and LLM auditors can ingest and validate it without a
      human.
- [ ] Fleet Management Console — multi-site dashboard, role-based access, remote
      licence/job control, full admin audit trail (grows the per-user dashboard
      into an operations console).
- [ ] Forensic erasure verification — post-wipe re-read of sampled blocks, recorded
      in the signed report, proving unrecoverability rather than asserting it.
- [ ] Diagnostics & refurb grading — collapse the SMART pre/post capture into a
      drive grade (A/B/C) + resale health report (power-on hours, TBW, reallocated
      sectors) for ITAD resale.
- [ ] Integration API + webhooks — a documented public API and events
      (`certificate.ready`, `report.uploaded`) so ITAD/ERP/ITSM systems can push
      assets in and pull certificates out programmatically.
- [ ] Broader media coverage — USB/SD/eMMC targets and RAID/FC/iSCSI volumes, the
      long tail of media that still needs a certificate.
- [ ] Offline / air-gapped verification — make the certificate QR self-contained
      (embedded signature + key) so a phone verifies it with zero network,
      removing the cloud objection for defence/classified environments.
- [ ] Canary self-audit on every wipe — write an unforgeable sentinel pattern to
      random LBAs before erasing, then verify the sentinels are gone after and
      record the result in the report, so each certificate proves the tool
      destroyed its own planted data.
- [ ] Richer machine inventory — extend `gather_info` / `report::csv` to collect
      more hardware so the report doubles as an asset record: full SMBIOS tables
      (`dmidecode`), PCI device list (`lspci -nn`), USB devices (`lsusb`), network
      interfaces + MACs, DIMM details, and firmware state (BIOS vendor/version/date
      already captured; add UEFI Secure Boot status via `mokutil`/`efibootmgr` and
      a "BIOS lockdown suspected" flag). BIOS-password detection has **no reliable
      userspace API** — the honest signal is its *effects* (frozen drives, NVMe
      Block SID `0x4286`/`0x4015`, refusal to write boot entries), so derive the
      flag from those rather than attempting a direct read. Keep the CSV column
      set stable (add new columns by name so the server's name-based parser keeps
      working) and put the full inventory in the report JSON/manifest.
- [ ] MDM / enrolment-lock detection — flag devices bound to a device-management
      platform **before** wiping so ITAD can price, reclaim, or release them.
      **Windows Autopilot is now solved** (validated 2026-09-28): a pure-Linux
      base hash (UUID + serial) checked through the dashboard's Graph probe — no
      original OS needed; see §7 for the integration plan. Remaining north-star
      targets: Apple DEP/Activation Lock (`profiles status -type enrollment` on a
      booted macOS, or a serial-based activation-lock status lookup), ChromeOS
      enterprise enrolment (firmware GBB flags + VPD
      `check_enrollment`/`block_devmode`), and persistent firmware agents such as
      CompuTrace (DMI OEM strings). Reality check: tScrub boots Linux and erases
      storage, so only firmware-level signals (ChromeOS GBB/VPD, CompuTrace) and
      offline hive parses work without the original OS; Apple detection still
      needs a live OS or a network serial lookup (third-party endpoint,
      ToS-dependent). Record the result per device as an `mdm_locked` /
      `enrollment` field in the report JSON/manifest.

## 7. Windows Autopilot MDM check — SHIPPED (v1.10.5–v1.10.14)

**Shipped and live-verified end-to-end against a real tenant.** The verdict model:
a pure-Linux OAv3 "4K" hardware hash is accepted by Microsoft Graph and yields
`806 ZtdDeviceAlreadyAssigned` = enrolled in this tenant,
`806 ZtdDeviceAssignedToOtherTenant` = enrolled elsewhere, `complete code 0` =
unenrolled, `802 InvalidZtdHardwareHash` = malformed hash. The Graph import is
async (`POST` 201 "unknown" → poll `state` until complete/error).

What shipped since the original plan:
- **Full 4K hash from the appliance** (v1.10.8–v1.10.10): SMBIOS identity fields,
  the TPM 2.0 EK modulus + descriptor, the TPM 1.2 `tpm_caps` descriptor, and the
  OA3 ProductKeyId (hash type 24) derived from the MSDM product key — all
  byte-identical to a WinPE `oa3tool` capture. The server can also build the hash
  from a diagnostics report alone (`mdm_report_hash()` in `mdm.php`), so no
  Windows PE capture is needed.
- **WinPE removed from the ISO** (v1.10.12): the Autopilot Capture payload and its
  boot menu are gone; the ISO boots straight into tScrub (165 MB).
- **Billing + gating** (v1.10.5): one credit per live Graph probe; free tier
  excluded; zero-balance paid accounts refused.
- **Continuous polling** (v1.10.11/v1.10.12): the MDM worker retries boot-time
  network/POST and polls `/api/mdm/status` for the whole session.
- **Live-verified verdict matrix**: TPM 2.0 and TPM 1.2, locked and unlocked.

**Architecture (kept):** the appliance never holds Azure credentials. It posts
serial + UUID (or the full hardware fields); the dashboard holds the tenant/app
secret server-side and runs the Graph probe, returning the verdict.

- [x] Server: port the Graph probe to PHP (`POST /api/mdm/autopilot` in
      `api.php`, creds in `config.json`, reuse the token-auth + reports plumbing)
      and cache verdicts by (serial, UUID). Returns
      `{verdict: unlocked|locked_this|locked_other}`.
- [x] Appliance: `product/src/35_mdm.sh` — the MDM worker + UI state. Runs as a
      background job exactly like the per-drive wipe workers, feeding the UI over
      the existing IPC channel (`fd 3` → `ui::loop`). Lifecycle in `fn_main`:
      after `system::gather_info` + `config::load_usb`, set `MDM_STATUS=CHECKING`
      and (only if a dashboard URL + API token are configured) launch
      `mdm::detect &` so it runs alongside `device::discover`/SMART capture. The
      worker extracts `SYS_UUID` + `SYS_SERIAL` (add `SYS_UUID` to
      `system::gather_info` — new `dmidecode -s system-uuid` /
      `/sys/class/dmi/id/product_uuid` capture), POSTs `{serial, uuid}` to
      `${DASH_URL}/api/mdm/autopilot` with the token (`curl --max-time`-bounded),
      maps the JSON verdict, and writes `mdm STATUS <value>` to fd 3. The
      appliance never holds an Azure secret — it only sends serial+uuid and
      receives a verdict.
- [x] UI: add an `MDM:` row to the Runtime panel (`40_table.sh`, right-hand
      table next to Elapsed/COCID/Licence/Tier/Expiry). `ui::loop` special-cases
      `_dev == "mdm"` → updates `MDM_STATUS` and repaints the row in-place (like
      the elapsed tick). States + colours: `Checking…` (spinner),
      `Unlocked` (green), `Locked` (red), `Offline` (amber), `Skipped` (grey, no
      dashboard/identifiers configured). Verdict wording per §24-D2
      (`Unlocked`/`Locked`).
- [x] Report: add `enrollment`/`mdm_locked` to `report::csv` + the JSON manifest
      (the server parser is name-based, so add columns by name).
- [x] Validate `--collect` on one real machine end-to-end (a VM can't exercise a
      real disk/NIC); run the C1–C5 register/unregister test matrix.
- [x] (Optional) full hash: boot the capture ISO on physical hardware to grab
      reference type 7/8 encodings for byte-exact reporting / re-enrollment.

Remaining (north-star, §6): Apple DEP/Activation Lock and ChromeOS
enrolment — different endpoints, ToS review.

## 8. Remote BIOS unlock — robustness backlog

Shipped as a first cut (v1.7.0): the dashboard stages a clear → the appliance
pulls it (`GET /api/bios/unlock/pending`), clears via `firmware_attributes` /
`hp-wmi`, and reports back. It works end-to-end but has known gaps to close
before it is production-trustworthy:

- [x] Appliance JSON parsing corrupts passwords containing `"`, `\`, or control
      chars — replaced the `sed` extraction with a JSON-safe base64 field
      (`password_b64`; legacy `password` kept for the transition).
- [x] No retry/requeue on lost results — added a 10-minute `dispatched` TTL +
      requeue, plus `curl --retry` on the result POST.
- [x] Password residue — supersede now clears `password_enc`; added a 7-day
      stale-pending sweep.
- [ ] Offline staging needs a full tScrub boot — add a lightweight
      "unlock-only" boot/flag so an operator can rescue a locked BIOS without
      running a wipe.
- [x] Slot selection is a first-match heuristic, not the wipe-time slot — now
      prefers setup/`AdminPassword` over power-on/`SystemPassword` and
      re-verifies after clearing.
- [x] Weak error signal — re-read the slot after the clear; "still set" reports
      a distinct `failed` detail (wrong password or read-only).
- [x] Serial-only claim — `unlock_claim` now matches `uuid` too (serial-only
      fallback when the staged uuid is empty).
- [x] No TLS clock-skew fallback — added a `curl -k` retry for RTC-skewed
      appliances (mirror `report::upload_http`).
- [x] Result state machine not enforced server-side — `unlock_report` now
      requires the row be `dispatched` before accepting a result.
- [ ] Coverage reality — writable password attributes exist only on Dell
      (`dell-wmi-sysman`), Lenovo (`think_lmi`) and HP (`hp-wmi`); most other
      vendors report `unsupported`, and power-on passwords are usually not
      clearable via this path.

## 9. BitRaser-vs-Blancco feature gaps — backlog

Source: `research/BitRaser vs Blancco Comparison.pdf` (Stellar, Rev3/02_2026,
26-row feature sheet; BitRaser V3 = 26, Blancco V7 = 23). Every row where tScrub
trails is queued here with the shortest credible path to parity. Items already
covered by §5/§6/§8 are cross-referenced, not duplicated. Guardrail: keep the
comparison page honest — never claim a feature or certification we don't hold.

### 9.1 Multi-platform & media

- [ ] **Mac (Intel + Apple Silicon) erase** — ship it as a pull-the-drive
      workflow, not a Mac boot: document removing the SSD and wiping it in a
      caddy on any tScrub host. Apple Silicon internal storage is encrypted and
      only wipeable through macOS Recovery → *Erase All Content & Settings*
      (Apple's own tool) — out of scope for a Linux boot; declare this on
      `compare.html` so the gap is explicit rather than silent.
- [ ] **Chromebook erase** — most ChromeOS devices can boot Linux via
      developer mode / SeaBIOS. Add a Chromebook section to `docs.html`
      (enable dev mode → boot tScrub USB → wipe eMMC/NVMe) and capture ChromeOS
      GBB/VPD enrolment flags (overlaps §6 MDM/enrolment-lock detection).
- [ ] **RAID dismantling** — detect RAID members in `gather_info` (`mdadm -E`
      superblocks, Intel VMD / `mpt3sas` controllers) and mark them "RAID
      member — dismantle in controller BIOS" on the triage screen; record a
      per-drive RAID flag in the report. Never auto-break an array.
- [ ] **SED (OPAL) unlock + erase** — `sedutil-cli` already ships in the image;
      add an OPAL path alongside the NVMe/ATA paths: `--query` → PSID revert
      (`--yesIreallywanttoERASEALLmydatausingthePSID`) when there's no LBA
      unlock, and surface the existing 3-state SED status as an explicit method.

### 9.2 Erasure methods & verification

- [ ] **Global standards catalogue** — nwipe already implements a catalogue of
      overwrite patterns (DoD 5220.22-M, Gutmann, HMG IS5, PRNG, zero, verify).
      Expose `--method <standard>` on the triage screen, map standard→nwipe
      pattern for the SCSI fallback, and add a `Standard` column to
      `report::csv` (the server parser is name-based, so it picks the column up
      automatically).
- [ ] **IEEE 2883 awareness** — competitors (D-Secure) advertise IEEE 2883
      alongside NIST 800-88 / DoD. tScrub's compliance copy only cites NIST
      800-88 Rev 1 / NCSC / UK GDPR. Audit `compliance.html` + `docs.html` and
      either map our methods to IEEE 2883 terminology or state the NIST 800-88
      target explicitly — never claim a standard we don't verify.
- [ ] **Random & Total verification** — after the firmware erase, run a
      read-back pass (sampled blocks via `dd`+`sha256` by default, full when the
      operator opts in) and record verification type + result. Start sampled so
      8 TB mechanical runs stay sane.
- [ ] **HexViewer-equivalent** — a full interactive hex viewer is overkill; ship
      the audit-relevant subset: a `verify --sample N` mode that re-reads N
      random LBAs post-wipe and reports raw bytes vs expected. Fold into §6
      "Forensic erasure verification" when that ships.
- [ ] **HPA/DCO removal & erasure** — before wiping, reset HPA to native size
      (`hdparm -N p<max>`) and clear DCO (`hdparm --dco-restore`), then re-read
      to confirm both are gone. Detection is already captured as CSV columns;
      only the removal step + a before/after pair in the report is missing.

### 9.3 Enterprise ITAD ecosystem

- [ ] **Fleet / network erasure** — extend the BIOS-unlock request/result
      pattern (§8) into a wipe job queue: dashboard queues a job per serial →
      appliance polls `GET /api/jobs/pending` → runs an autonuke → posts the
      report back. Cross-ref §6 "Fleet Management Console".
- [ ] **ITSM/ERP integrations** — first document the REST API (OpenAPI) and add
      webhooks (`report.uploaded`, `certificate.ready`); then ship one reference
      ServiceNow inbound-webhook script. Cross-ref §6 "Integration API +
      webhooks". Native Makor/RazorERP connectors are partner work, not code
      here.
- [ ] **Pooled licence allocation** — add a seat/pool concept to licences: an
      org admin (see §5 "Organisations & seats") issues sub-tokens against a
      pooled `team`/`enterprise` licence with a per-pool usage counter.
- [ ] **Customized erasure report** — account-level report template (logo, extra
      header fields, column visibility) stored on the dashboard and applied in
      `render_cert.php` at generation time; the CSV stays fixed for
      machine-readability.
- [ ] **Customer-customized ISO** — a `make branded-iso CUSTOMER=…` target in
      the build fork that bakes a customer logo + GRUB title (already possible
      via the open build); ship a one-page how-to rather than a UI.
- [ ] **Non-expiring licences** — add a "perpetual-until-activated" flag for
      paid licences (activation = first report upload) alongside the current
      dated expiry, and make PAYG credits non-expiring.

### 9.4 Trust, certification & support

- [ ] **Third-party testing / approval** (the "NIST & DHS tested & approved",
      ADISA row) — not a code task: prepare a certification submission (the
      erasure engine + signed-report/QR evidence chain are already certifiable)
      and write the application runbook as `ops/certification.md`. Guardrail:
      never claim a certification we don't hold.
- [ ] **Free technical support** — publish a tiered support SLA on the pricing
      page and link it from the dashboard; Team/Enterprise get named support,
      free tier gets docs/FAQ/community.
- [ ] **Legacy IDE/PATA** — not planned (pre-2005 hardware); state "SATA and
      newer" explicitly on `compare.html` so the gap is declared.
- [ ] (Not actionable) **"30-year data recovery company"** — a brand/trust
      claim, not a feature; answer it with the open-source + verifiable-evidence
      story instead of trying to match vendor pedigree.

## 10. PXE image serving — move tScrub + Hashreport to .6 (2026-10-02, done)

Today the PXE server (`.26`) serves the tScrub bzImage, the WinPE media and the
Autopilot hashreport payload, while the ISO is built and published from `.6`.
Consolidate the two tScrub-owned PXE images onto `.6` so it is the single source
of truth, leaving `.26` as the iPXE/TFTP bootstrap only.

**Status: done 2026-10-02** — payloads moved to `.6` (`~/webs/lan/ipxe/…`),
`.26` boot.ipxe `tScrub`/`hashreport` entries repointed to `.6` (hard IP),
deploy flow repointed off `.26` (see Remaining below).

### Scope

Move **only** these two PXE entries' payloads from `.26` → `.6` (~790 MB total;
everything else — Windows-install `winpem*`, `shredos_0.4x`, `clonezilla`,
`hirens`, `tails`, `ubuntu` — stays on `.26`):

- **tScrub** — `tScrub/boot/bzImage` (signed) + `tScrub/tom-vance-2027-09-25.lic`.
- **hashreport** — `winpe/wimboot`, `winpe/media/{bootmgr,bootmgfw.efi}`,
  `winpe/media/Boot/{BCD,boot.sdi}`,
  `winpe/media/sources/boot_inc_drivers.wim` (786 MB), and the whole `autopilot/`
  dir (7 files: hashreport.ini/vbs, winpe.jpg, oa3tool.exe, PCPKsp.dll, OA3.cfg,
  input.xml).

### Serving on .6

- New docroot `~/webs/lan/` — outside `~/webs/tscrub/` so `npm run deploy`
  (`rsync --delete`) can never wipe it.
- New nginx server block `lan.conf`: `listen 8080 default_server` +
  `autoindex on` (HTML directory index). Staged at `~/lan.conf`; the user applies
  the sudo step (`cp` into `/etc/nginx/sites-available/` + `ln -s` into
  `sites-enabled/` + `nginx -t && systemctl reload nginx`).

### boot.ipxe changes (on .26)

- The DHCP/iPXE entry point stays on `.26` (`~/tftp/autoexec.ipxe` chains to
  `http://192.168.0.26/boot.ipxe`); the full menu (Windows install, ShredOS, …)
  remains there.
- Only the `tScrub` and `hashreport` entries are repointed, with hard IPs:
  - `tScrub` = `shim http://192.168.0.6:8080/ipxe/shim.efi` +
    `kernel http://192.168.0.6:8080/ipxe/tscrub/bzImage` +
    `tscrub_license_url=http://192.168.0.6:8080/ipxe/tscrub/tom-vance-2027-09-25.lic`.
  - `hashreport` = `wimboot` + `media/…` + the `autopilot/` payload, all under
    `http://192.168.0.6:8080/ipxe/winpe/`. The hashreport POST still targets
    `https://tscrub.com/api/mdm/hash` (unchanged).

### Deployment-flow changes (done)

- bzImage: sign on `.6` (`sbsign`) → copy to `~/webs/lan/ipxe/tscrub/bzImage`
  (Mac relay to `.26` dropped). Pending: move `ipxe-sb/vendor.{key,crt}` from
  `.26` to `.6`.
- `product/.config` now deploys the script to `.6`
  (`TSCRUB_DEPLOY_HOST=192.168.0.6`, docroot `~/webs/lan`, URL `192.168.0.6:8080`)
  — no more uploads to `.26`.
- WIM re-bake (only when the payload changes): `apt install wimtools` on `.6`,
  bake there, publish to `~/webs/lan/ipxe/winpe/media/sources/boot_inc_drivers.wim`
  and the ISO overlay `board/shredos/winpe/sources/boot.wim`.
- `ops/deploy.md` updated.

### Remaining

- None. ShredOS pre-wipe staleness on `.26` accepted; `ipxe-sb` keys moved to
  `.6` (`sbsign` runs on `.6`).

## 11. BitRaser BDAD teardown — gaps (2026-10-04)

Source: `research/bitraser/README.md` — reverse-engineered **BitRaser Drive
Eraser && Diagnostics v3.0.1.1** (Cloud/Network/Offline ISOs). BitRaser is the
direct commercial competitor; the items below are what it ships that tScrub does
not. Items already queued in §9 are cross-referenced, not duplicated. Guardrail:
keep `compare.html` honest — don't claim a feature until it ships. (For context,
tScrub is already **ahead** on Autopilot hash completeness — we derive TPM EK +
ODID, which BitRaser's Linux hash omits — and at parity on core
NVMe/SATA/SED erasure + certificate generation.)

### 11.1 Erasure depth

- [ ] **Post-erasure verification (known-data read-back)** — BitRaser writes a
      known pattern and reads it back to prove destruction; tScrub only asserts
      command completion + SMART. Strategy: sampled-block read-back first
      (`dd` + `sha256` over N random LBAs), full pass opt-in, record
      `verify=<none|sampled|full>` + result in the report. (Queued in §9.2
      "Random & Total verification".)
- [ ] **RAID controller physical-drive erase** — BitRaser erases at the
      controller level (`hpacucli ctrl slot=X pd Y modify erase
      erasepattern=… forced`, `megacli`); tScrub only *detects* RAID hosts.
      Strategy: bundle `megacli`/`hpacucli` in the image (redistributable), add
      a `device::raid_erase` path mapping a drive to its controller erase
      command, gated behind the existing "never auto-break an array" rule (§9.1).
- [ ] **IEEE 2883-2022 Purge incl. TPM clearing** — BitRaser gates the method
      on TPM presence and clears the chip (`TPM2_Clear` via physical-presence +
      reboot) so chip-resident data is removed too. Strategy: add `tpm::clear`
      (`tpm2_clear -c platform`, fallback to
      `/sys/class/tpm/tpm0/ppi/request` + firmware confirmation at next boot),
      expose an explicit "IEEE 2883 Purge" method = disk-erase + TPM-clear, and
      record both on the certificate. (Note §9.2 "IEEE 2883 awareness" is
      copy-only; this is the mechanism.)
- [ ] **Multi-pass software overwrite (DoD 5220.22-M, Gutmann, HMG IS5, PRNG)** —
      BitRaser offers these as first-class methods. (Queued in §9.2 "Global
      standards catalogue": `--method <standard>` mapped to nwipe patterns.)
- [ ] **HPA/DCO region erasure** — BitRaser erases the HPA; tScrub only detects
      it. (Queued in §9.2 "HPA/DCO removal & erasure".)
- [ ] **eMMC/MMC media** — BitRaser lists MMC as supported. Strategy: add
      `mmcblk*` to `device::discover` (currently excluded) and erase via
      `blkdiscard`, since eMMC usually lacks ATA/NVMe sanitise.
- [ ] **SAS firmware sanitise** — BitRaser uses `sg_sanitize --overwrite --zero`
      (sg3_utils) for SAS drives; tScrub falls back to nwipe software overwrite.
      Strategy: add a `device::sas_sanitize` path that tries `sg_sanitize
      --overwrite --zero` first, falling back to nwipe only when unsupported
      (bundle `sg3_utils` — upstream Buildroot already ships it).
- [ ] **Configurable write passes + custom erase algorithms** — BitRaser exposes
      `Write Passes:` and user-defined patterns (`AlgoType`, `iCustomAlgoCount`).
      Strategy: accept `--passes N` + `--pattern <zero|one|random|hex>` and map
      to nwipe's custom-pattern support; record a `Custom` method in the report.
- [ ] **NVMe namespace delete/recreate** — BitRaser runs `nvme delete-ns` /
      `create-ns` (namespace management). Strategy: add guarded `nvme ns`
      helpers behind an explicit "manage namespaces" toggle; low priority for
      pure erasure (already covered by format/sanitise).
- [ ] **Drive LED "locate"** — BitRaser blinks enclosure LEDs to physically
      locate a drive in a rack (`Locate Drives`). Strategy: use `sg_ses` /
      enclosure-services to set the locate LED for the selected drive;
      server-fleet nicety.
- [ ] **Drive-side niceties** (one small pass): SMR detection (flag
      shingled/host-managed drives in the report), SED crypto-erase progress %,
      an ATA **Device Unlock Password** utility (unlock password-protected ATA
      drives before erasing), and **BitLocker volume detection** (mark encrypted
      volumes on the triage screen, like BitRaser's "BitLocker Found").

### 11.2 Hardware diagnostics — turn capture into tests (biggest gap)

BitRaser's `_bpcdt/p_*` is an interactive test suite; tScrub only *captures*
static hardware (EDID, battery, DIMMs, CPU spec) plus two opt-in self-tests (CPU
sum-of-squares, drive self-test). Strategy: add `selftest::*` modules that write
to a result file and are surfaced in the diagnostics report (`render_diag.php`):

- [ ] **RAM stress** — `memtester` (tiny) or `stress-ng --vm` for a fixed
      duration; PASS on no errors.
- [ ] **Battery test** — charge/discharge delta over a short window via
      `/sys/class/power_supply/BAT*` (we already read Wh/health); report
      capacity drop/min as a health signal.
- [ ] **Input tests** (keyboard/mouse/touchscreen) — `evtest`/`libinput
      debug-events` + a TUI prompt "press every key / move the mouse / touch the
      screen"; record which events were seen.
- [ ] **Display pixel test** — full-screen solid-colour frames on the
      framebuffer; operator eyeballs dead pixels and taps pass/fail.
- [ ] **Audio / mic loopback** — `aplay` a tone + `arecord` a clip, check the
      recorded level (alsa-utils already bundled); PASS on non-silent capture.
- [ ] **Webcam** — `v4l2-ctl`/`ffplay -f v4l2 -i /dev/video0` frame grab +
      operator confirm.
- [ ] **USB ports** — udev monitor + `usbtest` (BitRaser's "USB Ports Checked");
      report which ports see a device plug/unplug during the test window.
- [ ] **Network** (WiFi/Bluetooth/Ethernet) — extend the existing
      `network::ensure`/`unblock-wifi` with `iw scan` / `bluetoothctl scan on` /
      `ip link` probes reporting link + device present.
- [ ] **Fingerprint** — `fprintd-list` detection; report present/absent (skip
      enrolment).
- [ ] **CMOS / motherboard / accelerometer** — read `/sys/class/dmi` +
      `/sys/bus/iio` and report presence (matches capture we already do).

### 11.3 Autopilot robustness (minor)

- [ ] **Per-device hash cache** — BitRaser persists the machine hash
      (`Hash Exis on Disk`) so it isn't recomputed each boot. Strategy: cache the
      hardware hash by serial/uuid next to the existing report-id cache; recompute
      only when identity fields change.
- [ ] **Rotating / cloud-issued Azure creds** — BitRaser fetches a fresh
      client_secret per run (`GetAutopilotKey`). Strategy: the server already has
      a multi-tenant pool; add a "rotate secret on abuse signal" + optional
      short-TTL app credential, documented in `ops/` (not code-blocking).

### 11.4 Reporting polish (minor)

- [ ] **Captured signature images** — BitRaser embeds a drawn eraser/validator
      signature image; tScrub uses typed name/date text blocks. Strategy: add an
      optional signature-image upload to the certificate flow (PNG via the
      dashboard) rendered by `render_cert.php` alongside the existing text
      attestation; keep text as the default so nothing regresses.
- [ ] **Arbitrary custom fields** — BitRaser adds operator-defined name/value
      fields (up to ~19) to the report (`CustomFieldPanel`). Strategy: allow
      `--field name=value` / `tscrub.conf` custom fields, carry them through to
      the CSV + certificate; tScrub currently has fixed identity fields only.
- [ ] **Barcode scan + label printing** — BitRaser validates asset barcodes
      (`/api/ValidateBarcode`) and prints asset labels (`csfullLabel`,
      wxPrinter). Strategy: accept a barcode/asset-id input, add a
      "print label" action to the dashboard (server-side PDF label via TCPDF),
      and validate barcodes against the device record.
- [ ] **i18n + keyboard layout + network proxy** — BitRaser offers language +
      keyboard-layout selection (`Keybord Language`, `CurrentLanguage`) and
      proxy-aware networking. Strategy (low priority): add a language/locale
      string table and a `tscrub_proxy=` config passthrough; defer until there
      is a non-UK customer ask.

> Not gaps (verified, so future sweeps don't re-raise them): BitRaser's
> **virtual keyboard** (`wxVKGridView`) is an on-screen input for its X11 kiosk —
> tScrub's console TUI doesn't need one. BitRaser's rescue image ships an **OPAL
> Pre-Boot Authentication** (`linuxpba`) to *unlock* a SED at boot; tScrub's
> PSID-revert path is the erasure-appropriate equivalent. BitRaser's report
> `DigitalIdentifier` = tScrub's `digital_identifier`; remote wipe/power and
> parallel multi-drive wipe are already shipped in v1.10.13/v1.10.14.
