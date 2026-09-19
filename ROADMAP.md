# tScrub Roadmap

Future plans for the project, kept here so they survive between sessions. Current
state as of v1.3 (remote licence fetching + baked-in customer licences).

## 1. Self-built Buildroot appliance image

The biggest remaining UX gap: today the product ships as `tscrub.sh`, and users
must build/boot Linux themselves. Plan is a **ready-to-boot image built on our own
Buildroot** (not reselling ShredOS — just matching its drive-operation capabilities).

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
| `lftp` | optional FTP report upload | optional |
| `smartmontools` | pre/post-wipe SMART capture for value assessment (`smart::capture_*`) | yes |
| `sg3_utils` | SAS ops (not called by tScrub) | optional |

sedutil-cli is **also embedded** in `tscrub.sh` as `SEDUTIL_PAYLOAD_B64`
(extracted by `device::install_sedutil`) so tScrub can unlock drives itself. The
image should also ship `sedutil-cli` as a shell utility for manual OPAL/SED work
(unlock, PSID revert, LockingRange inspect). It is **not in upstream Buildroot**,
so either add a custom `package/sedutil` (build from
github.com/Drive-Trust-Alliance/sedutil) or extract tScrub's embedded copy to
`/usr/sbin/sedutil-cli` in the overlay — the latter requires no build at all.

### Kernel config areas (match ShredOS's drive ops)

- NVMe: `CONFIG_BLK_DEV_NVME` (+ multipath if needed)
- SATA/AHCI: `CONFIG_ATA`, `CONFIG_SATA_AHCI`, libata passthrough (hdparm ATA erase)
- SCSI/SAS: `CONFIG_SCSI`, `CONFIG_BLK_DEV_SD`, HBA drivers (`mpt3sas`, `megaraid_sas`, `aacraid`, `hpsa`, `smartpqi`)
- USB storage: `CONFIG_USB_STORAGE`, `CONFIG_USB_UAS`
- sysfs (`/sys/block`) — used by `device::discover`
- DRM — needed so `rtcwake -m mem` reliably wakes the display for the ATA freeze cycle

### Open decision: nwipe

`product/src/32_device_scsi.sh` uses nwipe for SCSI/SAS drives that don't support
firmware sanitise/format. Options:

- [ ] Keep nwipe (GPL-2.0, small) as one more bundled package
- [ ] Replace `device::exec_scsi_nwipe` with a `dd`/`blkdiscard` zero pass and drop nwipe

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

- [ ] `tscrub_defconfig` (kernel + package selections above)
- [ ] `board/tscrub/` overlay: `/usr/bin/tscrub.sh` + an `inittab` that boots straight into it
- [ ] post-build hook to embed `build/tscrub.sh` (community or `make build-customer`)
- [ ] build + test on NVMe / SATA / SAS / USB hardware

### Licensing notes

- Buildroot is GPL-2.0-or-later; bundle GPL packages as **separate programs**
  (aggregation) so `tscrub.sh` keeps its own licence.
- `tscrub.sh` currently has **no licence file** — decide one before distributing an image.

## 2. Related simplification backlog

- [ ] Zero-touch kernel-cmdline config: `tscrub_cocid=` + `tscrub_autoconfirm=` for PXE fleets (`tscrub_upload=` is done)
- [ ] Auto-upload reports → auto Certificate of Destruction (machine-facing `/api/certify` ingestion)
- [ ] Billing (Stripe) + automatic licence issuance on payment webhook *(parked)*
- [ ] Repo-root README and "boot and wipe in 60 seconds" onboarding

## 3. Self-serve platform (accounts → licence → app → dashboard → certs)

Target user journey:

1. Visitor creates an account on tscrub.com and is issued a licence (even free).
2. They download the appliance (`tscrub.sh`) plus their `.lic`.
3. They configure the appliance one of two ways:
   - **Network** — the appliance uploads the finished report to tScrub (or an FTP drop).
   - **USB** — the appliance writes the erasure + SMART data to a USB drive.
4. The licence is required at boot at all times (free or paid). Free licences get
   **unsigned** reports/certificates and **no QR code**; paid licences get
   digitally signed reports + a signed certificate + QR code.
5. Back on the dashboard, uploading the report files stores the full CSV data in
   MySQL and displays it per drive (including SMART). Certificates are
   downloadable per licence tier.

### Gaps to close

- [x] Accounts, login, dashboard, admin (MySQL-backed) — **done**.
- [x] Self-serve licence issuance (free/payg/team/enterprise) — **done**.
- [x] Licence enforced at boot on every build — **done**.
- [x] Certificate generation with Annex A (devices) + Annex B (SMART) — **done**.
- [x] **Tiered licence features** — free licence does NOT sign reports and does
      NOT emit a QR code; paid licences do. Done:
      - `product/src/50_report.sh` `license::verify` includes `tier` in the signed
        message; `license::apply` only sets `REPORT_KEY` when a report key is
        present (paid tiers). Free licences carry no `key`.
      - `marketing/server/certify.php` looks up the user's licence tier and gates
        the PDF digital signature + QR code (and wording) on `tier != free`.
      - `issue_licence.py` / `issue_license.sh` omit `key` for the free tier.
- [x] **Machine ingestion endpoint** — token-authenticated `POST /api/reports`
      (per-user `api_tokens`, managed in the dashboard) stores reports (no PDF)
      straight to the user's dashboard — **done**.
- [x] **USB output mode** — `report::detect_output` resolves the write location:
      `--output` / `tscrub_output=` override, else the first writable removable
      FAT32/vfat partition (the boot stick), else `/` (RAM) with a warning.
- [x] **Per-drive persistence** — full CSV rows (device, method, serial, final
      status, SMART pre/post) are stored in `certificate_drives` — **done**.
- [x] **Dashboard drill-down** — `/api/certs/{id}` returns per-drive + SMART; the
      dashboard renders a drill-down table — **done**.
- [x] **Network-mode config in the product** — `tscrub_upload=https://tscrub.com/api/reports`
      + `tscrub_api_token=…` pushes reports straight to the dashboard (curl
      multipart + `X-Api-Token`), alongside the existing FTP `shredos_output=`.
- [ ] **Billing gate** — payg/team/enterprise licences are currently self-serve
      with no payment; wire Stripe before relying on tier differences for revenue.
      *(parked — no ETA; DB backups are covered by Proxmox Backup Server)*
