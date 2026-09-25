# Report output — where wipe reports are written and why

Every tScrub run produces a three-file report set — `<report>.csv` (human-readable,
one row per drive), `<report>.csv.sig` (base64 Ed25519 signature), and
`<report>.json` (manifest with SHA-256, signing key, and drive summary). This
document describes how tScrub picks the destination, the two ways the boot stick
can be laid out, and the safeguards that make the report survive a reboot.

## Resolution order (`report::detect_output`)

1. **Explicit path** — `--output /path` / `tscrub_output=/path` (must already exist
   and be writable).
2. **The licence volume** — the same partition the `.lic` was found on
   (`LICENSE_USB_DEV`, recorded by `license::detect_usb`). In practice this is the
   path that matters: **wherever the customer drops their licence, the report
   follows.**
3. **FAT/exFAT scan** — `fdisk -l` is scanned for `exfat|fat16|fat32` partitions
   (no removable-flag assumption — many UEFI boards report the stick as a fixed
   disk), preferring the partition carrying `boot/version.txt` (the appended
   `TSCRUB-USB` partition), then the first writable FAT volume. If `fdisk` is
   absent, `lsblk` (fstype `vfat|exfat`) is used instead.
4. **RAM** — `/` as a last resort, with a warning.

The scan deliberately never matches NTFS: the grep targets `exfat|fat16|fat32`
(partition type) or `vfat|exfat` (probed fstype), so an internal Windows drive is
never a candidate.

## Folder layout

Reports are grouped under `reports/<COCID>/` beneath the chosen destination:

```
reports/
└── 11111/
    ├── tScrub_11111_20260922T161328Z.csv
    ├── tScrub_11111_20260922T161328Z.json
    └── tScrub_11111_20260922T161328Z.csv.sig
```

`COCID` is validated to exactly 5 digits (`^[0-9]{5}$`), so it is a safe directory
name. If the folder cannot be created (disk full / read-only), tScrub falls back to
the destination root.

## Durability (why the report is never lost on reboot)

FAT writes are buffered in the page cache; a hard power-off drops them. After
writing the report (and uploading, if configured), `report::sync_out` runs `sync`
and unmounts the USB partition **before** the reboot/shutdown menu is shown. This
mirrors ShredOS, whose `archive_log.sh` unmounts its archive drive after copying.

## Two ways to write the stick

| Tool | Resulting layout | Where the `.lic` + reports go |
|---|---|---|
| `dd` / Etcher / Rufus **DD mode** | hybrid ISO: `TSCRUB` (read-only boot image) + `TSCRUB-USB` (writable FAT16, labelled) | the `TSCRUB-USB` volume |
| Rufus **ISO mode** | a single writable FAT32 partition | that single partition |

Rufus ISO mode flattens the hybrid ISO into one partition (it drops the appended
`TSCRUB-USB` partition and the `boot/version.txt` marker). That is why the
**licence volume is preferred** over the `boot/version.txt` marker — the marker
only exists on a `dd`-written stick.

## Preconfigured via `tscrub.conf`

Instead of editing GRUB or the kernel command line, drop a `tscrub.conf`
(`KEY=VALUE`, one per line) next to the `.lic` on the stick. `config::load_usb`
scans FAT/ISO9660 volumes for it at boot (before licence and COCID resolution)
and fills in only what isn't already set — CLI flags always win. Keys mirror the
kernel parameters:

```
tscrub_upload=https://tscrub.com/api/reports   # dashboard push
tscrub_api_token=<64-hex token>
tscrub_cocid=12345                             # unattended run (no prompt)
tscrub_license_url=http://host/license.key     # licence over the LAN
tscrub_output=/mnt/usb                         # local path
# or: tscrub_output=ftp:host:path:user:pass
# or: tscrub_output=sftp:host:path:user:pass   # colon-safe password
```

- `tscrub_upload` + `tscrub_api_token` → `report::upload_http` pushes to the
  dashboard.
- `tscrub_output=ftp:/sftp:` → parsed into `TSCRUB_NET_*` exactly like
  `report::parse_output` (the last field absorbs extra colons, so passwords may
  contain `:`); `tscrub_output=/path` → `REPORT_OUTPUT`.
- `tscrub_cocid` enables autonuke (skips the COC prompt).
- `tscrub_license_url` sets `LICENSE_URL` before `license::detect` runs.

Because the baked GRUB carries no `tscrub_*` params, values read from the file
survive `report::parse_upload`/`report::parse_output` (those only override when
the kernel command line has the same key).

## History (the bugs this resolves)

- **v1.4.17** — the scan required `lsblk`'s `RM=1` (removable) flag; many UEFI
  boards report the stick as a fixed disk, so no partition was found and reports
  went to RAM. Switched to a `fdisk` scan that ignores the removable flag.
- **v1.4.18** — reports were written but lost on reboot (no `sync`/unmount). Added
  `report::sync_out`.
- **v1.4.19** — the writable partition was unlabelled and the message printed a
  misleading `/tmp` mountpoint. Labelled it `TSCRUB-USB` and made the message say
  "the USB stick".
- **v1.4.20** — Rufus ISO-mode users put the licence on a single partition with no
  `boot/version.txt` marker. Reports now go to the licence volume first.
- **v1.4.21** — reports grouped under `reports/<COCID>/`.

## Verification

`tests/proxmox/scenarios/11_report_to_usb.sh` (dd-style layout with the
`boot/version.txt` marker) and `12_report_to_rufus.sh` (single-partition Rufus
layout, no marker) boot a QEMU VM with the USB attached as `removable=off`
(reproducing the fixed-disk behaviour), run `tscrub.sh --dry-run` with no
`--output`, then re-mount the USB image and assert the `.csv`/`.sig`/`.json` are
present next to `license.key`.
