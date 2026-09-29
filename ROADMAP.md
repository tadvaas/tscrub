# tScrub Roadmap

Future plans for the project, kept here so they survive between sessions. Current
state as of v1.4.54 (appliance image shipped; free reports self-signed; dashboard
upload, certificates, customer-as-certifier, and Stripe billing/PAYG licences all
shipped).

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
- [ ] Organisations & seats — a lightweight workspace layer (email-invite + role,
      shared certs/COCID history) so a Team licence covers several operators.
      No SSO/portal.
- [ ] SAS/SCSI erase-path proof — exercise the `nwipe` SCSI fallback on real SAS
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

## 7. Windows Autopilot MDM check — validated, integrate

**Done (2026-09-28):** the "is this device Autopilot-enrolled?" check is fully
researched and LIVE-TESTED against a real tenant. A pure-Linux **base hash**
(UUID + serial + manufacturer + product — NO TPM/ODUID) is accepted by Microsoft
Graph and yields the correct verdict: `806 ZtdDeviceAlreadyAssigned` = enrolled in
this tenant, `806 ZtdDeviceAssignedToOtherTenant` = enrolled elsewhere, `complete
code 0` = unenrolled, `802 InvalidZtdHardwareHash` = malformed hash. The Graph
import is async (`POST` 201 "unknown" → poll `state` until complete/error).
Prototype: `research/autopilot/autopilot_status.py` (Graph client) +
`research/autopilot/oa3hash.py`
(hash builder; defaults to the safe base fields — `--full` is opt-in and currently
rejected `802` because types 7/8 disk/MAC are unvalidated). Full detail:
`research/autopilot/autopilot-report.md` §25. **Detailed, ripple-aware build plan:**
`research/autopilot/autopilot-build-plan.md` (phases, file-by-file, and 15 gotchas).

**Architecture (decided):** the appliance never holds Azure credentials. It
collects UUID + serial and posts them to the dashboard; the dashboard holds the
tenant/app secret server-side and runs the Graph probe, returning the verdict.

- [ ] Server: port the Graph probe to PHP (`POST /api/mdm/autopilot` in
      `api.php`, creds in `config.json`, reuse the token-auth + reports plumbing)
      and cache verdicts by (serial, UUID). Returns
      `{verdict: unlocked|locked_this|locked_other}`.
- [ ] Appliance: `product/src/35_mdm.sh` — the MDM worker + UI state. Runs as a
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
- [ ] UI: add an `MDM:` row to the Runtime panel (`40_table.sh`, right-hand
      table next to Elapsed/COCID/Licence/Tier/Expiry). `ui::loop` special-cases
      `_dev == "mdm"` → updates `MDM_STATUS` and repaints the row in-place (like
      the elapsed tick). States + colours: `Checking…` (spinner),
      `Unlocked` (green), `Locked` (red), `Offline` (amber), `Skipped` (grey, no
      dashboard/identifiers configured). Verdict wording per §24-D2
      (`Unlocked`/`Locked`).
- [ ] Report: add `enrollment`/`mdm_locked` to `report::csv` + the JSON manifest
      (the server parser is name-based, so add columns by name).
- [ ] Validate `--collect` on one real machine end-to-end (a VM can't exercise a
      real disk/NIC); run the C1–C5 register/unregister test matrix.
- [ ] (Optional) full hash: boot the capture ISO on physical hardware to grab
      reference type 7/8 encodings for byte-exact reporting / re-enrollment.

Out of scope until the above ships: Apple DEP/Activation Lock and ChromeOS
enrolment (still §6 north-star — different endpoints, ToS review).
