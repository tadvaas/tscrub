# tScrub Roadmap

Future plans for the project, kept here so they survive between sessions. Current
state as of v1.4.34 (appliance image shipped; free reports self-signed; dashboard
upload, certificates, and the customer-as-certifier model all shipped).

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
Only the physical hardware boot test (NVMe / SATA / SAS / USB) remains.
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
**slim** (`make build-slim`, `SKIP_SEDUTIL_PAYLOAD=1`, no embedded payload); the
Download-page script keeps the embedded `SEDUTIL_PAYLOAD_B64` so bare-Linux users
can unlock drives without installing sedutil.

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
- [ ] Build + test on NVMe / SATA / SAS / USB hardware.

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
- [ ] Auto-upload reports → auto Certificate of Destruction (machine-facing `/api/certify` ingestion)
- [ ] Billing (Stripe) + automatic licence issuance on payment webhook
- [x] Repo-root README and "boot and wipe in 60 seconds" onboarding (getting-started page) — shipped
- [ ] Licence-on-USB handling: when multiple `.lic` files are present, prefer the highest tier and/or warn — today the alphabetically-first file wins, so a stray `free.lic` can silently downgrade a paid customer's evidence
- [ ] Auto-licence-delivery: presigned per-user licence URL (`/api/licence/<secret>`) + a dashboard "download `tscrub.conf`" so the appliance fetches its current licence at boot — removes the manual `.lic` reinstall on upgrade
- [ ] Show licence info (customer / tier / expiry) in the TUI Runtime panel
