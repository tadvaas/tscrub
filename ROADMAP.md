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
| `lftp` | optional FTP report upload | optional |
| `smartmontools` | diagnostics (not called by tScrub) | optional |
| `sg3_utils` | SAS ops (not called by tScrub) | optional |

sedutil for OPAL is **already embedded** in `tscrub.sh` as `SEDUTIL_PAYLOAD_B64`
(extracted by `device::install_sedutil`); a separate sedutil package is optional
for manual OPAL recovery in a shell.

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

- [ ] Zero-touch kernel-cmdline config (`tscrub_cocid=`, `tscrub_autoconfirm=`, `tscrub_upload=`) for PXE fleets
- [ ] Auto-upload reports → auto Certificate of Destruction (machine-facing `/api/certify` ingestion)
- [ ] Billing (Stripe) + automatic licence issuance on payment webhook
- [ ] Repo-root README and "boot and wipe in 60 seconds" onboarding
