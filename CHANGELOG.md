# Changelog

All notable changes to tScrub are documented here. Releases are checksummed and
signed; the authoritative checksums live in `/downloads/manifest.json`.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses date-based versioning (`v1.x`).

## [v1.11.23] - 2026-10-07

### Added

- **Animated wipe-wave status indicator** — the wipe table's `STATUS` cell no
  longer shows the static `RUNNING` word for a drive doing a firmware erasure
  (which reports no percentage). It now renders a fbcon-safe `█▓▒░` comet that
  sweeps left-to-right and wraps, so an indeterminate erase is visibly "in
  progress" instead of frozen. Rendering-only: the stored status stays
  `RUNNING`, so wipe timing, the progress/ETA heartbeat and reporting are
  unchanged. `NN%` for NVMe/nwipe drives is untouched.

### Release

- Appliance ISO `tscrub-v1.11.23_2025.11_30_x86-64_v0.41_20261007-4264a23b.iso`
  (166 MB) sha256 `38049a0f8d3e2f79a63dfe108cc24d39bdc1775a7a9610fa041eb6b640b30239`.
- Standalone script `tscrub.sh` (v1.11.23) sha256 `88ca230bc735b71f1e7c554a7284fc875e84c2df36643865812a1d8ca0fc7aa7`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `fe8a26fd2957eebac2016b0c95b325069246781ea2da559acfecc64343575aba`.

## [v1.11.22] - 2026-10-07

### Fixed

- **Live wipe ETA frozen on the dashboard** — the heartbeat has always carried
  the aggregate progress percentage and ETA, but those values were only
  recomputed when a wipe worker emitted a `STATUS`/`ETA` line. ATA security
  erases (`hdparm --security-erase[-enhanced]`) emit no mid-wipe progress, so
  the ETA was computed once at `RUNNING` and then froze (e.g. "≈48m left" for
  the whole erase). `ui::loop` now recomputes the aggregate progress every tick
  while a wipe is running, so the countdown keeps moving even for firmware
  erases with no percentage source.

### Release

- Appliance ISO `tscrub-v1.11.22_2025.11_30_x86-64_v0.41_20261007-9daafe3d.iso`
  (166 MB) sha256 `d6fad7a739ed613e3ecac765d1070054e8153489ee4d42a71f370d0353e8767e`.
- Standalone script `tscrub.sh` (v1.11.22) sha256 `22ad8dca8611609c183ae07d821a17f4200149289778971428b67a1e40ba5de7`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `980bf379978e51a43e7c81c29a262502299b1a4382778cb8d584c3ce0beaa0d0`.

## [v1.11.21] - 2026-10-07

### Added

- **Hot-plug drive detection** — a drive connected after tScrub has booted now
  appears on the triage screen without a reboot. A lightweight `/sys/block`
  poll (the image uses devtmpfs, no udev) diffs the device set; on a change it
  re-runs discovery, probes only the newcomer (capability + pre-wipe SMART),
  re-renders the table with a `[+] /dev/sdX — model size` notice, and re-pushes
  the diagnostics snapshot to the dashboard. Gated to the idle triage screen —
  never during a wipe (the wipe set stays fixed) — and USB-attached drives are
  still excluded.

### Release

- Appliance ISO `tscrub-v1.11.21_2025.11_30_x86-64_v0.41_20261007-539378b5.iso`
  (166 MB) sha256 `a3254baa407374f1ea366bd76f37e0a3b385491ecba3f922a26f023934399a68`.
- Standalone script `tscrub.sh` (v1.11.21) sha256 `81e1afa18806e43a1641876464fbf21b2099fff00ce8e6f013ea937a01726311`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `bc30ff92559488f4c03c46928428d6025b78e7544c08599a32f85bbfc5d0c200`.

## [v1.11.20] - 2026-10-07

### Fixed

- **Remote wipe/power poll TLS clock-skew retry** — the wipe-queue poll
  (`remote::poll_and_execute`) and its result POST previously had no `curl -k`
  fallback, so an appliance with a dead RTC battery (wrong system clock) could
  heartbeat, poll BIOS-unlock and MDM, yet silently fail every wipe-queue poll
  with `curl: (60)` and never claim a staged erase. They now retry once without
  TLS verification, mirroring presence/MDM/BIOS-unlock.

### Release

- Appliance ISO `tscrub-v1.11.20_2025.11_30_x86-64_v0.41_20261007-dc877a79.iso`
  (166 MB) sha256 `a0195fb73734de5c532f063556d2993d7afe14e947a83e77c9385798acb981f6`.
- Standalone script `tscrub.sh` (v1.11.20) sha256 `69ff7a9ba995c0141da83a870badedc161526bf0a15cd50d3768b10ea64fafae`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `51b8302cd0c7a2b8bbb39ed9880205ce365df53b174582110a7e242a4e650b30`.

## [v1.11.19] - 2026-10-07

### Changed

- **Fleet wipe job queue** — remote wipe jobs are now durable: a staged erase
  lives 7 days (survives a re-image/boot) and a dispatched wipe whose result
  POST was lost is requeued after 10 minutes instead of expiring. Power
  commands stay online-only (5 min). The appliance retries its result POST 3×;
  the dashboard shows Queued (with an offline hint) and Expired states and
  allows staging an erasure for an offline machine.

### Release

- Appliance ISO `tscrub-v1.11.19_2025.11_30_x86-64_v0.41_20261007-b418c300.iso`
  (166 MB) sha256 `b84b630f7c3fb1c4760fcc0dbd2af040b5be03438412d318aa9239540faae2d4`.
- Standalone script `tscrub.sh` (v1.11.19) sha256 `883c5d4f12a3c3c8236b254e90cf688f87d7c19579cd23755ce3c81fc3712e11`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `44f149364b32dccaae833fed1e42b4ba48c646ae313fe9c8eeb9892d39cc5214`.

## [v1.11.18] - 2026-10-07

### Added

- **Live wipe progress → dashboard** — the heartbeat now carries an aggregate
  `progress_pct` (0–100, or −1 when a firmware erase can't report a number) and
  `progress_eta_sec` while a wipe is running. NVMe uses `SPROG`; SCSI nwipe now
  pumps `SIGUSR1` and parses its `%`/`eta` log line; ATA uses the drive's own
  timing word. The Devices tab shows a fixed-width amber progress bar plus
  "≈Xm left" under the Wiping status.

### Fixed

- **Remote wipe commands no longer expire after 5 minutes** — a staged erase
  was marked `expired` before a rebooting/booting appliance could claim it
  (the device boots faster than the old TTL, but a re-image takes several
  minutes). The pending-command TTL is now 60 minutes; an online device still
  claims within seconds.

### Release

- Appliance ISO `tscrub-v1.11.18_2025.11_30_x86-64_v0.41_20261007-26aeda73.iso`
  (166 MB) sha256 `006a1dfb4ecdb4678072d73d68e573a6629f644a768727f48eed6c9f096e2407`.
- Standalone script `tscrub.sh` (v1.11.18) sha256 `84811b4e9b9da7940e2302b88441ede1a2de885dcd7b66fb2db0345ae9b74b11`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `ab7217a7f1f297bd2b8b85b44afb77bd2b27b36369d48aedf504f40a81c1701b`.

## [v1.11.17] - 2026-10-07

### Changed

- **Appliance ops polish** — the kernel now ships the 8250 serial console
  (`CONFIG_SERIAL_8250` + console/PCI) and `virtio-net` for faster QEMU/KVM
  testing; the boot menus pass `console=ttyS0,115200` alongside `console=tty3`
  so a serial/IPMI-SoL/Proxmox serial console shows boot logs.
- **Quiet OPAL probe on virtual disks** — discovery no longer runs
  `sedutil-cli --query` against QEMU/VMware/VirtualBox/Hyper-V/Xen/virtio disks
  (they can never be SEDs), removing the SG_IO noise (and occasional trap) such
  disks produced; they are marked `NA` and real drives are still probed.

### Release

- Appliance ISO `tscrub-v1.11.17_2025.11_30_x86-64_v0.41_20261007-1ae4ef75.iso`
  (166 MB) sha256 `399dadfd2566586ff62242965fd2c08b41c62d2efacfa142ad5f24add28df96f`.
- Standalone script `tscrub.sh` (v1.11.17) sha256 `f025af356b0ddd02d4c8bdcb3bb0beb91126f229ea8233453682508196f300ef`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `e36c4a7970ba7665d0ee3f237e42c891cb5dded7c21a2ff47157dbd146640eba`.

## [v1.11.16] - 2026-10-06

### Changed

- **Fix USB peripheral-scan redirect errors** — the webcam/audio peripheral scan
  no longer emits `No such file or directory` errors for every USB root-hub
  interface directory (`1-0:1.0`, `2-0:1.0`, …). Those entries carry only
  `bInterfaceClass`, not `bDeviceClass`/`product`, so they are now skipped
  before the sysfs reads; the PCI class scan got the same guard.

### Release

- Appliance ISO `tscrub-v1.11.16_2025.11_30_x86-64_v0.41_20261006-5d3068eb.iso`
  (166 MB) sha256 `990eace8750711c5a87553582d0e0f1904fe53f7d44ed16157fca34c91ea3338`.
- Standalone script `tscrub.sh` (v1.11.16) sha256 `a85e0559f13ecbcf53b14992a989a670aba6895fdef8368eaed6c4b66180068c`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `5861dfb28977ad3b04a28d69858e3f463a9199e6c81758df70e09fd0a44b3b69`.

## [v1.11.15] - 2026-10-06

### Changed

- **Complete SMBIOS machine profile** — the hardware inventory now mines the
  full SMBIOS table for every field with ITAD value and surfaces them all in
  the diagnostics PDF: CPU socket / family / ID / voltage, BIOS revision and
  firmware revision, chassis intrusion lock and thermal/power/security state,
  onboard devices (SMBIOS Type 41), OEM strings (HP Feature Byte / Build ID,
  etc.) and battery model + chemistry. Type 131 (vendor binary blobs) is
  intentionally skipped.

### Release

- Appliance ISO `tscrub-v1.11.15_2025.11_30_x86-64_v0.41_20261006-19619c8d.iso`
  (166 MB) sha256 `1c100b9e8560ead15b1bda4abd58ca9e76049667e316da2d71b09e30991f65e5`.
- Standalone script `tscrub.sh` (v1.11.15) sha256 `6c99d2b09e04dbb98e7cc5ad57501f8c74c4b8d6bb567e1bf823b95987701f33`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `8c8bca28d68bef2eee949da64ef02e33c6755ea1091c63334b5518b2f9b0aaf1`.

## [v1.11.14] - 2026-10-06

### Changed

- **Richer SMBIOS machine profile** — the boot-time inventory now also uses the
  SMBIOS table for: CPU max (turbo) speed (`2C/4T · 3.5 GHz max`), the board's
  maximum supported RAM (`32 GB (max 64 GB, 2 x 16GB @ 3200)`), and each DIMM's
  manufacturer + form factor (`16 GB Micron DDR4 SODIMM @ 2133 MT/s P/N=… SN=…`).
  These surface in the diagnostics PDF and Devices-tab modal.

### Release

- Appliance ISO `tscrub-v1.11.14_2025.11_30_x86-64_v0.41_20261006-966b42be.iso`
  (166 MB) sha256 `dbe5056f6d39d3e324f399f16c59a59875645acb13188c1a6e02512fbccceb32`.
- Standalone script `tscrub.sh` (v1.11.14) sha256 `ef7b0ffd9d6b8576ba642943ab7716fc9e34411c32a1532216d8abc2baeb81ba`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `0aa4224ee1e0960393bcd5bf4dc73a6a8076815dcfad04ca9c54f783da504318`.

## [v1.11.13] - 2026-10-06

### Changed

- **Diagnostics report: readable USB devices** — the USB list no longer shows
  the machine's own USB host controllers (Linux Foundation root hubs, `1d6b:*`,
  and any "… Host Controller" entries), drops the placeholder "Generic"
  manufacturer, and resolves bare `vendor:product` IDs (e.g. Intel Bluetooth
  `8087:0a2b`) to a friendly name via the appliance's `usb.ids` database. The
  raw list is still kept in the report JSON.

### Release

- Appliance ISO `tscrub-v1.11.13_2025.11_30_x86-64_v0.41_20261006-b5ee92b9.iso`
  (166 MB) sha256 `dc7ce6dd4f53555d73535d83ec05f0470e22a454daac19ee6641c5baaae7340f`.
- Standalone script `tscrub.sh` (v1.11.13) sha256 `83b6421998c847fcfb216db65db07a59dc57e8f67b3acc043f82051851592142`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `7625a220893598f9f56fcf40b7a5384d956a1ad516a239e841b93f355e05d0ec`.

## [v1.11.12] - 2026-10-06

### Changed

- **Diagnostics report: virtual NICs skipped** — the network-interfaces list
  (appliance capture + PDF) no longer includes loopback/tunnel/software
  interfaces (`lo`, `sit0`, `tun`/`tap`, `veth`, bridges, bonds, docker, …);
  only physical, hardware-backed NICs are shown.
- **Diagnostics report: readable PCI devices** — the PDF's PCI list is now a
  concise "Class: Vendor Device" per meaningful device (e.g. `Audio device:
  Intel Sunrise Point-LP HD Audio`) instead of the raw `lspci` line; chipset
  glue (host/PCI/ISA bridges, SMBus, thermal/I2C/HECI/PMC controllers) is
  dropped, and vendor suffixes (`Corporation`, `Inc.`, `Ltd.`, …) and revision
  markers are removed. The raw list is still kept in the report JSON.

### Release

- Appliance ISO `tscrub-v1.11.12_2025.11_30_x86-64_v0.41_20261006-1b68065b.iso`
  (166 MB) sha256 `bc25d81ea961647211755595addfb025e0dc0a9a6708fd53f999b72c05dc8b2e`.
- Standalone script `tscrub.sh` (v1.11.12) sha256 `6767f6e551345b7cc4c7677c60d3a8febbe14786610d442f6f3483560715fca7`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `3b3ef41951139cdb600d852b03d5dc4960d247b0599a0c0893c3c5b23ad7a179`.

## [v1.11.11] - 2026-10-06

### Fixed

- **PCI devices / GPU / Wi-Fi / storage controllers reported empty** — `lspci`
  is dynamically linked against `libpci.so.3`, but that library never landed in
  the appliance image, so every `lspci`-based field (the new full PCI list and
  the pre-existing GPU, Wi-Fi, storage-controller, VMD and RAID-HBA detection)
  silently failed at boot with "error while loading shared libraries". The
  library is now shipped in the rootfs overlay, and `hardware::pci` additionally
  falls back to `/sys/bus/pci/devices` so PCI is never silently "N/A" even on an
  image without it.
- **Webcam / audio always reported absent** — peripheral detection checked only
  `/dev/video*` (uvcvideo) and `/proc/asound/cards` (ALSA), neither of which
  exists on the minimal image. Webcam presence is now also detected from the USB
  video class (`bInterfaceClass 0x0e`) or a camera-named product, and audio from
  a PCI multimedia class (`0x04xx`) or USB audio class (`0x01`).

### Changed

- The peripherals `audio` value is now a presence flag (`0`/`1`) instead of a
  codec count; the diagnostics PDF and the Devices-tab modal both render it as
  "Audio: Yes/No".

### Release

- Appliance ISO `tscrub-v1.11.11_2025.11_30_x86-64_v0.41_20261006-4ac81f4b.iso`
  (166 MB) sha256 `8d0719be2696a2fdfe8a81408072a4e5981b164aed157662c74ea4ab81aa01b8`.
- Standalone script `tscrub.sh` (v1.11.11) sha256 `17c5814c8767e5426a83e504445da70be3c6eb9b858fbdd11e484843bb92e01e`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `358a70b73692ae91b76627926a17d8f184707552c0d77a470d08e2e2ccb82e43`.

## [v1.11.10] - 2026-10-05

### Added

- **Extended hardware inventory** — the boot-time diagnostics now capture the
  full PCI device list (`lspci -nn`), USB devices, a full SMBIOS table dump,
  per-interface network detail (name/MAC/link/driver), UEFI boot entries, and
  peripheral presence (webcam, touchscreen, fingerprint, accelerometer, audio),
  plus a derived **"BIOS lockdown suspected"** flag (SED-locked or still-frozen
  drive). The new fields surface in the Devices-tab modal and on a new hardware
  annex page in the diagnostics PDF; the CSV gains a `BIOSLockdown` column.
  Large tables (SMBIOS/PCI/USB) are JSON-only and never bloat the report CSV.

### Release

- Appliance ISO `tscrub-v1.11.10_2025.11_30_x86-64_v0.41_20261005-99594db4.iso`
  (165 MB) sha256 `04b774060a429c3884668f016ee82a0aebba3afbd04dc6cbdddd40c492cd469a`.
- Standalone script `tscrub.sh` (v1.11.10) sha256 `584275cf72ce0d65d8f3845474f686b199decdd916f18dac6a7fb80b316260b3`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `f16b1ff1b4c433dd452bf8751a0de9c4c9c29d474bbc1975bc9c45b633891d5f`.

## [v1.11.9] - 2026-10-05

### Fixed

- **Remote erasure on selected drives ("no drives matched")** — the triage
  drive-matching loop read the marker's `drive=` lines with `while read`, which
  skips a final line that has no trailing newline. Command substitution strips
  the marker's trailing newline, so on a single-drive machine the only drive
  line was skipped and the wipe failed with "no drives matched (scope=list)".
  The loop now uses `read … || [[ -n "$drv" ]]` (and the marker writer's own
  serial-parsing loop got the same guard), so the last serial is always
  matched. Multi-drive machines also no longer silently drop their last drive.

## [v1.11.8] - 2026-10-05

### Changed

- **No full-screen blank on screen transitions** — `table::render` no longer
  clears the whole terminal (`clear`/`\033[2J`) on every repaint. The screen is
  filled only on the first paint or when the theme (background colour) actually
  changes; a same-theme re-render homes the cursor and overwrites in place,
  clearing any stale lines below the table with `\033[J`. This removes the
  visible flash at the triage → selection → wipe transitions (all on the blue
  theme), matching nWipe/btop (full redraw only on a structural change).

### Release

- Appliance ISO `tscrub-v1.11.8_2025.11_30_x86-64_v0.41_20261005-8a2dbcd8.iso`
  (165 MB) sha256 `f0d47132bfa3ab22fc852b37a161895e7a629d099c2ad922d4ac67bd3fdf2f71`.
- Standalone script `tscrub.sh` (v1.11.8) sha256 `6cf7482992da1bbb01af9568bf28f147e174be066dc49b899291c48251a2b3c6`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `5042612ccbf8dd7bf43fa7b5b86c8f300c3dee93e6ac2a35ed33f3c9d316d4c2`.

## [v1.11.7] - 2026-10-05

### Changed

- **Console table repaint (no full-screen reflow)** — the wipe/triage screen no
  longer clears and reprints the whole terminal on every drive status change
  (the NVMe monitor emits a `%` update every 2 s). `table::render` now paints
  once and seeds a per-drive state cache plus a layout/theme/mode fingerprint;
  `ui::repaint_changed` repaints only the changed rows in place (absolute cursor
  + clear-to-end-of-line) and falls back to a full render only on terminal
  resize, theme, device-set or selection-mode change. A `SIGWINCH` trap
  (`ui::sigwinch`) and `tput`-first terminal-size detection make the table
  follow the terminal as it is resized (different monitor sizes), on the wipe,
  triage and selection screens.

### Release

- Appliance ISO `tscrub-v1.11.7_2025.11_30_x86-64_v0.41_20261005-273c5ed8.iso`
  (165 MB) sha256 `d6926a8ef7830ad2e92c0e3503faa0c935e711a1e84a182eab949b42e73c5298`.
- Standalone script `tscrub.sh` (v1.11.7) sha256 `67ff6ec5e5d3217556d356d475c4062f61b2793a46e945c1c86e00eb7c47bca3`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `118bcdab1b41b89e6f9676b00bd27ff5f6bda1537d3a2d88cd5f209bc2dd18d6`.

## [v1.11.6] - 2026-10-05

### Added

- **HP BIOS password detection** — the appliance kernel now builds in the HP WMI
  drivers (`CONFIG_HP_WMI`, `CONFIG_HP_BIOSCFG`), so HP business machines
  (2018+) expose the `firmware_attributes` interface (`hp-bioscfg`) for BIOS
  setup/admin-password detection and remote unlock. Previously HP machines fell
  through to `UNKNOWN` because no detection surface existed. Pre-2018 HP
  firmware (e.g. the Z840) does not implement the `hp-bioscfg` WMI schema, and
  the legacy `hp-wmi bios_password` node was removed in the 6.x kernel, so such
  machines correctly remain `UNKNOWN`.

### Release

- Appliance ISO `tscrub-v1.11.6_2025.11_30_x86-64_v0.41_20261005-ec2e7100.iso`
  (165 MB) sha256 `196d711700631e5e2e524ed35f79ba3591f1ab2f413466e7264e4376a229c7c7`.
- Standalone script `tscrub.sh` (v1.11.6) sha256 `89b3b4f47121cc99738a2f3ec6478abcfe2d471347105de681aacda8b91c2f2a`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `12768d67d23dc7b413c83fffb562dd3a04b90d514da8dadba3306eaab09b4835`.

## [v1.11.5] - 2026-10-05

### Fixed

- **Remote erasure on selected drives** — the staged-erase marker was written
  non-atomically (`id`/`dry_run` first, then the `scope`/`drive` lines), so the
  triage loop could consume a half-written marker and fail with "no drives
  matched". The marker is now written to a temp file and atomically renamed into
  place (and claimed with a rename), so the drive list is never read mid-write.

### Release

- Appliance ISO `tscrub-v1.11.5_2025.11_30_x86-64_v0.41_20261005-3fffaa91.iso`
  (165 MB) sha256 `4a8209257862f68d26185f48e5993e3b0a94b14f18fd2b58aadd87ab5f7ad463`.
- Standalone script `tscrub.sh` (v1.11.5) sha256 `62d04830c7ba1928fe9fa0f2fdbd5f469b4c215bd266f91425d52ee73c3dcfa6`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `48fb0f28131a24c84f19e2a24e41ca4ab2ee85ced23fe73db1196b02140a3fb9`.

## [v1.11.4] - 2026-10-05

### Fixed

- **Remote erasure on selected drives** — the appliance matched dashboard-selected
  drive serials case-sensitively, so any case difference between the boot-time
  diagnostics report and the live serial made every selected drive fail with
  "no drives matched". Serials are now compared case-insensitively in both the
  triage match (`40_table.sh`) and the erasure selection (`10_main.sh`).

### Changed

- **Certificate personnel** — the "Performed by (operator)" line now carries the
  erasure completion date and falls back to the account holder (name, else email)
  when the report omits an operator; the validator is omitted when absent; and
  the appliance's literal `N/A` sentinel is treated as empty. The same rules
  apply to the device diagnostics PDF.
- **Certificate layout** — removed the `Verification` and `HPA/DCO` rows and
  columns, giving the freed width to `Class` so it no longer wraps.

### Release

- Appliance ISO `tscrub-v1.11.4_2025.11_30_x86-64_v0.41_20261005-61c1c0cd.iso`
  (165 MB) sha256 `341ac1bc16ff0988cd955e8d74e343440cc68f149578eb0e9ee8c29d60d9012b`.
- Standalone script `tscrub.sh` (v1.11.4) sha256 `72548871c621e09911a9662f6bb571cfae371a8c8ae2565e79bc9b3ffcba6143`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `e31f9156655db0347d0f185ea43a8d71754b9ee1e40714729b6de501ea97acf8`.

## [v1.11.3] - 2026-10-05

### Changed

- **MDM checks are now manual-only** — removed the automatic MDM enqueue on
  first diagnostics receipt, on WinPE hash ingest, and on the appliance's
  `/mdm/autopilot` call. Checks are triggered only by the dashboard Re-check
  button. Devices with a captured hash but no job surface as `Unchecked`
  (server + dashboard badge + card copy), instead of implying a pending check.

### Fixed

- **Remote BIOS unlock robustness** — 8 hardening fixes across the appliance
  (`38_bios_unlock.sh`) and server (`bios_unlock.php`, `api.php`):
  - JSON-safe password handling — new base64 `password_b64` field (legacy
    `password` kept) so quotes/backslashes/control chars survive.
  - Dispatched TTL + requeue (10 min) and `curl --retry` on the result POST.
  - Password residue purged — supersede clears `password_enc`; 7-day stale sweep.
  - Explicit slot targeting — setup/`AdminPassword` over power-on/`SystemPassword`.
  - Clear is re-verified — re-reads the slot; "still set" reports a distinct
    `failed` detail (wrong password or read-only).
  - `uuid` in the claim (serial-only fallback when the staged uuid is empty).
  - `curl -k` TLS clock-skew fallback on the pending GET + result POST.
  - Result state machine enforced server-side (`dispatched` → result only).

### Release

- Appliance ISO `tscrub-v1.11.3_2025.11_30_x86-64_v0.41_20261005-773247c3.iso`
  (165 MB) sha256 `144a7a841279ffdacf39cc35105cfc7665b61b4289ad538c1073ea7d94deea6e`.
- Standalone script `tscrub.sh` (v1.11.3) sha256 `65aaf4fe6720d0f4a661dde60330173c24fdb4247d17734465d84a38d0ed4630`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `5a42431f3c4045dc16f16ceb000ab55236fff585ba06dbec37cec617d641a983`.

## [v1.11.2] - 2026-10-05

### Removed

- **Product-key injection** — removed the firmware key-injection operation
  (appliance worker, `/api/key/inject*` endpoints, `key_inject` table, and the
  dashboard "Inject product key" card). Field research confirmed the embedded
  MSDM key lives in a one-way-locked OA3 UEFI variable on committed HP boards
  (`HP_OA3_LOCK=1`), so firmware re-injection is only possible on blank service
  boards — not a standard ITAD workflow. Committed boards use OS-level
  activation (`PID.txt` / `unattend.xml` / `slmgr`). The dashboard still
  *surfaces* the embedded key (Windows licence section).

### Release

- Appliance ISO `tscrub-v1.11.2_2025.11_30_x86-64_v0.41_20261005-1d9ad70b.iso`
  (165 MB) sha256 `6c3f1fe68b93ede9857c21c19d85a51d7bc0831d43a34aa56ffeecda13e2777b`.
- Standalone script `tscrub.sh` (v1.11.2) sha256 `baa9bbaa1d22d8e41e9c4875f062b2b290306414ea4d62cfb1abe62add5cc8d8`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `445bf17eb8d391621700fc2f8dbeeb2311a91540438e8cc69171f9f8bdd2f17e`.

## [v1.11.1] - 2026-10-05

### Changed

- **Product-key injection now writes the OA3 UEFI variable** instead of SPI
  flash. Field-verified on HP: the MSDM key is derived at boot from the OA3 UEFI
  variable (`HP_OA3`), not a static flash table. The worker loads efivarfs,
  locates the variable containing the current key, and replaces its 29 key bytes
  in place — no flashrom, no table assembly, no checksum. Locked firmware
  (`HP_OA3_LOCK=1`) is detected and reported as `unsupported` with the lock
  variable name; diagnostics are logged to `/tScrub.log`.

### Release

- Appliance ISO `tscrub-v1.11.1_2025.11_30_x86-64_v0.41_20261005-3cb6cbcf.iso`
  (166 MB) sha256 `b2796379df8ec1690a0c885ea32225c9dd442c0fa08485d1b839d4cc4cf07436`.
- Standalone script `tscrub.sh` (v1.11.1) sha256 `6e91f84a320c1fbbf3c46eef39efc0f30568611d8f624553cdfbc1124bdbceab`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `15db18e2aa588c98b316313647b4fc12b9299ca3f4d1b14867ba09d49a8722a6`.

## [v1.11.0] - 2026-10-05

### Added

- **Remote product-key injection** — a new Operations-modal action that pushes a
  Windows product key to a live appliance, which writes it into the firmware
  MSDM ACPI table and reports the outcome back. Server-side `key_inject` queue
  (key encrypted at rest with libsodium, purged after the command runs) with
  five new `/api/key/inject*` endpoints and results `injected|failed|unsupported
  |deferred`. Appliance worker `46_inject_key.sh` patches the 85-byte MSDM table
  in pure bash (key at offset 56, ACPI checksum byte recomputed) and reflashes
  via `flashrom`, reporting `unsupported` when the region is write-protected or
  `flashrom` is absent. The dashboard card is version-gated at v1.11.0.

### Release

- Appliance ISO `tscrub-v1.11.0_2025.11_30_x86-64_v0.41_20261005-ad8373f9.iso`
  (166 MB) sha256 `67c8d19860564eea3005abcedeb15c76bb190ddab933697719068170b922d4f9`.
- Standalone script `tscrub.sh` (v1.11.0) sha256 `30603b5e1b41fd688b10b2c53e26099e78e49878df5344875128d805a9574c4e`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `667ad2050d13600b82ce27d4dd978440d97c9d47bb5e6524b477544afddbac2f`.

## [v1.10.19] - 2026-10-05

### Added

- **RAID dismantling detection** — the appliance now detects when a discovered
  drive is a RAID member and flags it for the operator, without ever deleting a
  logical drive or breaking an array. Per-drive `RAID` flag with values
  `md` (Linux md superblock via `mdadm -E`), `vmd` (NVMe behind Intel VMD /
  RST "RAID mode") and `hba` (a RAID-capable HBA is present). The flag is
  recorded in a new `RAID` CSV column (surfaced on the dashboard), and the
  triage screen prints `RAID member(s) … — dismantle in controller BIOS before
  erasing.`. `mdadm` is now bundled in the image for superblock detection.
  Controller-level physical-drive erase (hpacucli/megacli) remains a separate,
  future feature.

### Release

- Appliance ISO `tscrub-v1.10.19_2025.11_30_x86-64_v0.41_20261005-51266ea0.iso`
  (165 MB) sha256 `9679d15d3bf0f1e22dc1957b01062e00a779a38f72aa4285abf14216758c10ed`.
- Standalone script `tscrub.sh` (v1.10.19) sha256 `2322e78912489a57853c9273d6a1a2bf87d966ac607426ddf3d7daae9e5ab3c9`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `4ef2108e3ce2b6f94036c7f6442ed5f027942dbe4665a9643ffc4ff9d2377792`.

## [v1.10.18] - 2026-10-05

### Added

- **SED (OPAL) crypto-erase** — an unlocked self-encrypting drive (TCG OPAL)
  that has no NVMe/ATA/SCSI firmware erase now falls back to an OPAL
  crypto-erase instead of physical destruction. `device::detect` assigns
  `CAP_OPAL_CRYPTO` when a drive is otherwise `CAP_NONE` and `sedutil-cli
  --query` reports it unlocked; `device::exec_opal` takes ownership with a
  per-run random SID password, enables + locks the global locking range, then
  `--revertTPer` destroys the media encryption key — a NIST 800-88 Purge
  recorded as `PURGE` / `DESTRUCTION` / method `OPAL Crypto Erase`. If
  ownership fails (an already-owned SED), the drive is honestly reported
  `FAILED` for physical destruction. Locked SEDs continue down the existing
  PSID-revert prompt. Structured `DRIVE / ACTION` entries are written to the
  appliance log for the audit trail.

### Release

- Appliance ISO `tscrub-v1.10.18_2025.11_30_x86-64_v0.41_20261005-efc1ddf6.iso`
  (165 MB) sha256 `27b54ca43702b9ac61198d0872eee765aadaf755e63aa9c0bb773844388ad5ff`.
- Standalone script `tscrub.sh` (v1.10.18) sha256 `e2f1a9394b874daf42b052041c99e9ab0c7aac0e51eb0ba09949ef4d204a7334`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `709f7d9daa89c75cd4397a10ad82271c8752d5508cb9b6a26e1b53c10c715f20`.

## [v1.10.17] - 2026-10-05

### Added

- **SAS firmware sanitise** — SCSI/SAS drives now prefer the firmware
  `SANITIZE` path over the software `nwipe` fallback. `device::detect` probes
  overwrite support via `sg_opcodes --opcode=0x48,0x1` (conservative: any probe
  failure means "no"), and `device::exec_scsi_sanitize` runs
  `sg_sanitize --overwrite --zero --quick` — a NIST 800-88 Purge recorded as
  `DESTRUCTION` with method `SAS Sanitize`. When a drive rejects SANITIZE as
  unsupported (invalid opcode / field in CDB), the appliance falls back to
  nwipe and corrects the recorded outcome to the honest `CLEAR`/`SANITISATION`
  label, so a report never claims a firmware purge that didn't happen.
  `sg3_utils` is already bundled in the image; this is a `tscrub.sh`-only
  change. Structured `DRIVE / ACTION` entries are written to the appliance log
  for the audit trail.

### Fixed

- **Post-erasure verification sector count** — a drive whose erase was
  `BLOCKED`/`FAILED`/`FROZEN` (not `COMPLETED`) kept the pre-erase sentinel
  plant count in `VerifySectors`, so the report and dashboard showed `n/a`
  alongside a misleading "40 sectors" figure. The count is now cleared for
  non-completed drives, so `n/a` carries no sector count.

### Release

- Appliance ISO `tscrub-v1.10.17_2025.11_30_x86-64_v0.41_20261005-dedb97f7.iso`
  (165 MB) sha256 `9e306beef9bf998896e6efe2986b1199e277098c39deb74b6285e9872cc4bcb2`.
- Standalone script `tscrub.sh` (v1.10.17) sha256 `00c935fbe038c47453ea8e04f6cf7d3db35af3740db1f2c8fe345e42eb274292`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `fb2105958029fcbda38445c3a95bb305a25a55f57566a5cc42df14f02faa5d26`.

## [v1.10.16] - 2026-10-04

### Added

- **HPA/DCO hidden-area removal before erasure** — the appliance now resets the
  ATA Host Protected Area (HPA) and Device Configuration Overlay (DCO) to
  native size before the ATA security erase, so hidden sectors at the tail of
  the drive are sanitised instead of surviving the wipe. `--dco-restore` runs
  first (DCO caps the HPA ceiling), then `hdparm -N p<native>` raises the max
  addressable sector, and both are re-read to confirm. On by default;
  `--hpa off` / `tscrub_hpa=off` disables it. Result recorded per drive as
  `HPAResult` / `DCOResult` in `removed | firmware-erased | none | failed` —
  enhanced security erase and NVMe sanitize already erase the area at firmware
  level, so a failed reset there is `firmware-erased` (data-safe), not a risk;
  a `failed` result on a normal secure erase flags "data may remain" on the
  finish screen, the dashboard Drives page, and the certificate PDF.
  Best-effort — never aborts the wipe; dry runs never write.

### Release

- Appliance ISO `tscrub-v1.10.16_2025.11_30_x86-64_v0.41_20261004-da35fb8f.iso`
  (165 MB) sha256 `cce333b8be75696dc4793244c480316c48355dded08a61979066199985f02a92`.
- Standalone script `tscrub.sh` (v1.10.16) sha256 `2c0099295a0ed308186728bec7960b7b629bb2a2c0f9cd5132ad00be5f9f1c77`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `fcc147b4675270ef0f0ba42affd380be61a982072c4a2a8bc3eababed20e70d3`.

## [v1.10.15] - 2026-10-04

### Added

- **Post-erasure verification (sampled read-back)** — the appliance now proves
  the wipe reached the media instead of asserting it from command completion.
  Before erasing, a per-run sentinel (nonce + LBA) is written to an 8-sector
  window at 5 fixed positions (first, 25%, 50%, 75%, tail) and flushed; after
  the wipe those sectors are re-read and the sentinel must be gone. Modes
  `none` / `sampled` (default) / `full`, set via `--verify` or
  `tscrub_verify=`. Only `COMPLETED` drives are scored — `FAILED`/`BLOCKED`/
  `FROZEN` drives record `n/a` (their sentinel legitimately survives). Flows
  through the report CSV (`Verify`, `VerifySectors`, `VerifyResult`), the signed
  manifest (`verify`), the finish screen, the dashboard Drives page, and the
  certificate PDF (page-1 "Verification" line + Annex A "Verified" column).
  Honest claim: "N sectors re-read, sentinel not found" — never "entire drive
  verified".

### Release

- Appliance ISO `tscrub-v1.10.15_2025.11_30_x86-64_v0.41_20261004-72c38760.iso`
  (165 MB) sha256 `3266353d3d3a8e9d8ce755ece3a98585c25d8b9cadb79f331f670257f21904a1`.
- Standalone script `tscrub.sh` (v1.10.15) sha256 `06db2c8c8f559f5ff89996dea6fcc4e2ea50d772dc44309e8c8d3c999683c753`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `a0c4d68b3a094614b2d18fff2c3797a87308dc7536543c16f51028cbd982c64f`.

## [v1.10.14] - 2026-10-03

### Added

- **Remote erasure** — the dashboard can now start an erasure on a booted
  appliance remotely. The Operations modal gains a "Remote erasure" form
  (dry-run toggle + all-drives or per-serial scope, with a destructive
  confirm), which queues a `wipe` command through the same `device_commands`
  pull queue as remote power. The appliance stages the request to a marker
  file, shows a 5-second grace prompt on the console ("press any key to
  cancel"), then runs the normal erasure workflow against the requested drives
  (respecting the dry-run flag). The form is gated fail-closed to online
  appliances on v1.10.14+ that are not already wiping.

### Fixed

- The SMART timeout watchdog could stall for its full timeout on hosts without
  a `timeout` binary (macOS dev/test): `kill`ing the watchdog subshell with
  SIGTERM is deferred by bash while it waits on the foreground `sleep`, so the
  guard outlived the command. The watchdog now runs detached from the
  caller's pipe and is reaped with SIGKILL, so a completed SMART read returns
  immediately. (Test-suite runtime dropped from ~100 s to ~19 s.)

### Release

- Appliance ISO `tscrub-v1.10.14_2025.11_30_x86-64_v0.41_20261003-7e82df9b.iso`
  (165 MB) sha256 `77fcda25fa55caba85bfe36a5be332c7ae3aaeb59d5a11e5b79bd534a76bfff2`.
- Standalone script `tscrub.sh` (v1.10.14) sha256 `243c3f64a05968388089a82984b758addcea9276eb5c850f3a12fc325c7bf2d9`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `2db002aa142b8699407f80289866a23a22477a3724ac572224c5507259c785ca`.

## [v1.10.13] - 2026-10-03

### Added

- **Remote power** — the dashboard can now shut down or restart a booted
  appliance. It follows the same pull model as remote BIOS unlock: the dashboard
  stages a command (`POST /api/devices/commands`), and a new appliance worker
  (`42_remote.sh`) polls `/api/devices/commands/pending` every 10 s and executes
  it. A command is never run while a wipe is in progress — it stays staged (or
  reports `deferred`) until the device is idle, so a remote power-off can never
  brick a drive mid-sanitise or lose a report.

### Release

- Appliance ISO `tscrub-v1.10.13_2025.11_30_x86-64_v0.41_20261003-2eda3738.iso`
  (165 MB) sha256 `7182f49bafff0096adb19980386a88933539688621896fa63db45a10af5d6890`.
- Standalone script `tscrub.sh` (v1.10.13) sha256 `f7b6babbc96bde1f2721a5428fd41261571ecbae692c156b042fa6038d4249ba`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `8675564fe8dd44336d8be01156f9afa095104d69706e9307b45263d683d57f8d`.

## [v1.10.12] - 2026-10-03

### Changed

- **Continuous MDM polling** — the MDM worker now polls `/api/mdm/status` for
  the entire session (every 10 s) instead of stopping once the verdict settles,
  so the Runtime panel tracks the dashboard in real time. A transient network
  drop flips the panel to "Offline" and it recovers automatically when the link
  returns.
- **WinPE removed from the release ISO** — the Windows PE "Autopilot Capture"
  payload, its boot-menu entries, and the BIOS chainload plumbing are gone. The
  ISO no longer bundles a ~900 MB WinPE partition, and the appended writeable
  FAT partition is now 64 MB (MOK cert + report/licence files only).
- **Boots straight into tScrub** — GRUB and isolinux now use a zero timeout and
  default straight to tScrub, so a written USB boots directly into the appliance
  with no boot menu (memtest/nomodeset entries remain in the config files for
  recovery).

### Release

- Appliance ISO `tscrub-v1.10.12_2025.11_30_x86-64_v0.41_20261003-8b089d70.iso`
  (165 MB) sha256 `aa1b3cefb80e3dee044a416cb3a7b3db572e3ee12d0b154edb4628c0031e7e3a`.
- Standalone script `tscrub.sh` (v1.10.12) sha256 `c2677517924213a84e32c0e70891ea2668b0fedb72f31cf2f6cbb93d57362fff`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `40f44fde8e7e3b0d6509c9f9e0cf3d8ded99c59ba261def56e0773d16e2f26a9`.

## [v1.10.11] - 2026-10-03

### Fixed

- **MDM panel frozen at "Offline" after a boot-time network blip** — the MDM
  worker is forked before device discovery, so its first network/POST attempt
  could race a USB NIC / DHCP that was still coming up; on failure it published
  `Offline` and stopped permanently, even though the parallel diagnostics report
  (forked later, with retries) reached the dashboard moments later and the
  server-side check ran to a verdict. The worker now retries `network::ensure`
  (up to 6 times) and the initial `/api/mdm/autopilot` POST (up to 3 times)
  before giving up, so the panel tracks the real verdict instead of a stale
  "Offline".

### Release

- Appliance ISO `tscrub-v1.10.11_2025.11_30_x86-64_v0.41_20261003-10fb211f.iso`
  (1001 MB) sha256 `ed0479c8697fd0aea5829f06442776f93cf6fae5a42e487b54a33b1ccd386b1f`.
- Standalone script `tscrub.sh` (v1.10.11) sha256 `fe92b0c63cbeece766e1bfa220e4c7bd86c473b796ab1156ca9bf9c3fa35cba4`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `b4c8afa6d6671951faddea94477c8dabd6dc4b970317b7ebc0be42e1d73a286a`.

## [v1.10.10] - 2026-10-03

### Added

- **TPM 1.2 identity fields** — the appliance now collects the raw TPM 1.2
  sysfs caps (`tpm_caps`, from `/sys/class/tpm/tpm0/caps`) so the type-13 TPM
  descriptor can be derived off-device for TPM 1.2 machines (previously only
  TPM 2.0 could derive it, from `tpm_getcap`). Flows through the diagnostics
  report and the signed report manifest.
- **tScrub-standalone MDM hash** — a diagnostics report is now sufficient to
  run the Windows Autopilot MDM check: the server builds the full OAv3 "4K"
  hardware hash from the report's own hardware fields
  (`mdm_report_hash()` in `mdm.php` — identity records 7/8/11/12/13/14/15/16/
  17/18/19/21/22/23/24/25) and stages it, so no WinPE `oa3tool` capture is
  needed. The WinPE hash remains the preferred, authoritative source and is
  never overwritten. Live-verified against Microsoft Graph: the generated hash
  returns the same `ZtdDeviceAssignedToOtherTenant` verdict as the WinPE hash.
- **MDM "Unchecked" state** — a device with a captured/generated hash but no
  MDM job yet now shows **Unchecked** (info acquired, check never initiated)
  instead of "No hash". Devices with no hash at all show `---`.

### Fixed

- **Appliance MDM panel stuck on `---`** — the runtime panel's MDM cell stopped
  at `---` because the worker posted `/api/mdm/autopilot` before the parallel
  diagnostics report had staged the hash, received `{verdict:"na"}`, and did
  not poll. `na` is now a pollable state: the worker keeps polling
  `/api/mdm/status` until a real verdict (or `Offline` after failed polls).
- **TPM 2.0 descriptor derivation** — the appliance's `tpm2_getcap` emits a
  `raw:`-style output that the original regexes mis-parsed (the type-13
  descriptor came out as `Revision:IFX`). The parser now handles both the
  `raw:` and the older `0x`/decimal styles.
- **Hash-builder hardening** — the EK modulus is now validated (exactly 256
  bytes; a truncated value is dropped instead of emitted as a corrupt type
  25/11); the disk serial is no longer whitespace-trimmed (its fixed-width
  spaces are significant); and `/reports/diagnostics` rejects control
  characters in serial/uuid (mirroring `/api/mdm/hash`).

### Changed

- The "No hash" MDM label is now `---` (server `mdm_status_label()`, the
  appliance-facing endpoints, and the dashboard badge).

### Release

- Appliance ISO `tscrub-v1.10.10_2025.11_30_x86-64_v0.41_20261003-b9657eaf.iso`
  (1001 MB) sha256 `824fac814b2c7c81c5f999b7fa68f386d0480bca971a582fa09c0aa2088edc94`.
- Standalone script `tscrub.sh` (v1.10.10) sha256 `92784b6221172cb3765843b37409fcc7539fca4e41de77e2ea87d147e2043dc0`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `79cc84e2d0e52bbd1ba2c4065b97486cb33d9661a23d52d75a76570726036a68`.

## [v1.10.9] - 2026-10-03

### Added

- **Autopilot ProductKeyId (hash type 24)** — the appliance now collects the
  OEM Windows product key from the MSDM ACPI table (`product_key`) so the
  13-digit OA3 ProductKeyId can be derived off-device. The ProductKeyId is
  computed with the PKey2009 algorithm (the key's single `N` is a positional
  marker: its 0-based position is the first base-24 digit; the other 24 chars
  decode with `BCDFGHJKMPQRTVWXY2346789`; Group = bits 0–19, Serial = bits
  20–49; ProductKeyId = group + 9-digit serial). It is derived server-side
  (`product_key_id_from_key()` in `reports_lib.php`, stored as
  `product_key_id`) and by the off-device hash generator (`oa3hash_min.py`),
  which now emits record type 24. Validated byte-exact against three real
  MSDM keys and their `oa3tool.exe` captures.

### Release

- Appliance ISO `tscrub-v1.10.9_2025.11_30_x86-64_v0.41_20261003-05bb401c.iso`
  (1001 MB) sha256 `4e28a42a41728914df79da5d2f5be6b2992d9e12863135676cf110b8f01a8d7b`.
- Standalone script `tscrub.sh` (v1.10.9) sha256 `5a0d8203c0575bfa8ff5a1b443c536c49b1ad0736cc74fdd4ca808ccf48d8782`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `df9c770ef9528d256318f2937982e23b43130da70bac9e003fad24464ae69a2d`.

## [v1.10.8] - 2026-10-03

### Added

- **Autopilot 4K-hash identity capture** — the appliance now collects the
  hardware fields needed to build a Windows Autopilot "4K" hardware hash
  off-device: SMBIOS `family`, `board_product`, `board_version` and
  `system_version`, plus the TPM 2.0 endorsement-key modulus (`tpm_ekpub`) and
  the raw `tpm2_getcap` output (`tpm_getcap`). These flow through the
  diagnostics report and the signed report manifest. The kernel now ships TCG
  TPM drivers and `tpm2-tools`/`tpm2-tss`, so the TPM fields populate on
  TPM 2.0 machines (empty on TPM 1.2 / no-TPM, matching oa3tool).

### Release

- Appliance ISO `tscrub-v1.10.8_2025.11_30_x86-64_v0.41_20261003-5e6202df.iso`
  (1001 MB) sha256 `35c6bd436568d4b1027f63590fac27f13e8bb202a8024639486bbf8662143093`.
- Standalone script `tscrub.sh` (v1.10.8) sha256 `bc1122f331e050bb709cabbcaa76f684907262dd375f830ca8dd6178d7842c1a`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `ebca78c4d192e06e53a6d40577e597abe00405f02c4cdc7ad282cd6066d982e5`.

## [v1.10.7] - 2026-10-02

### Fixed

- **Report upload lost on a transient TLS blip** — `report::upload_http` now
  retries transient transport failures (`--retry 3 --retry-delay 2
  --retry-connrefused --retry-all-errors`). A single mid-handshake reset
  (`curl: (35) "unexpected eof while reading"`) previously failed the upload
  immediately, and on a PXE boot (no writable USB fallback) the report stayed in
  RAM — lost on reboot. Re-sends are safe: the dashboard dedups reports by CSV
  SHA. The `curl: (60)` → `-k` clock-skew fallback is preserved.

### Release

- Appliance ISO `tscrub-v1.10.7_2025.11_30_x86-64_v0.41_20261002-21b268c0.iso`
  (1000 MB) sha256 `f6222cd0881b24d764b46b7f4b5dee867cad05711be503bca353e28456b19cad`.
- Standalone script `tscrub.sh` (v1.10.7) sha256 `f2a49efb7b4b5cc549cd86faa3caf5d1f6ba64e714ca7e4e950ed83b5f5712ea`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `70701e68bc33ca1a6466dc3954710093de796c0d069b8a6e948dec797d4bb9aa`.

## [v1.10.6] - 2026-10-02

### Fixed

- **MDM panel stuck on "Offline" after a transient network blip** — the
  appliance's status poll now retries transient HTTP failures (curl-level retry)
  and tolerates up to three consecutive failed polls before declaring the
  dashboard unreachable, so a single blip during the poll window no longer
  freezes the Runtime panel at "Offline" while the server-side check continues
  and resolves.

### Release

- Appliance ISO `tscrub-v1.10.6_2025.11_30_x86-64_v0.41_20261002-e7286648.iso`
  (1000 MB) sha256 `690a87ae87ea7149e35117c6e3b5797c3eb8c83d374d90268c6d2a97167e8e2b`.
- Standalone script `tscrub.sh` (v1.10.6) sha256 `5621fb36c024c58c95bcf4132e68094d9f61e3369ff17dbe4d55c5e9f311a23d`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `80641a33b54e26d6592de1d4bcf8d8abd1cb6d4a25fb651e64b1ec2cb5bd71df`.

## [v1.10.5] - 2026-10-02

### Added

- **MDM check billing + gating** — the Windows Autopilot (MDM) check is now a
  paid feature: it spends **one credit per live Graph probe** from the same
  device-credit wallet as erasure (idempotent, keyed by job). Free-tier accounts
  can no longer run the check, and paid accounts with a zero balance are refused
  (`No credits` / `Paid only`) instead of enqueuing a job. The worker debits only
  when a real import ran (cached verdicts and offline/MS failures are free), and
  a shortfall is logged without losing the verdict.
- **Erasure pre-flight signal** — `POST /api/reports/diagnostics` now returns the
  account's credit balance and `can_erase` (false only for a paid account with a
  zero balance), so the appliance can gate erasure fail-open (the appliance-side
  enforcement ships separately).

### Fixed

- **MDM status frozen on the finish screen** — the green/red/amber completion
  screen is now live: as the background MDM worker's verdict settles, the Runtime
  panel (MDM cell, elapsed timer, LAN IP) keeps updating instead of freezing at
  the last value synced before the report was written. The report summary and
  drive guidance are preserved across re-renders.

### Release

- Appliance ISO `tscrub-v1.10.5_2025.11_30_x86-64_v0.41_20261002-f28dc6a5.iso`
  (1000 MB) sha256 `9083f651d9725cfee4edcef018e9fb68997c939d5cdb38c7957a3902814c534a`.
- Standalone script `tscrub.sh` (v1.10.5) sha256 `f278a4aa2a2d7b32736df5ab42e7c1ce265954684f0d41eef42fe9b08b3286a0`.
- PXE bzImage signed (My iPXE Vendor Key) sha256 `e5a9f24a1c5215cd83ad6d3c12ff5f7ca99fc0e6f6fe2a291803446d8494e478`.

## [v1.10.4] - 2026-10-02

### Added

- **Live erasure indicator on the dashboard** — the appliance heartbeat now
  carries an erasure phase (`wiping` / `done` / `failed`) plus per-drive counts,
  so the Devices tab status cell shows **Wiping n/m**, **Complete**, or
  **Failed** instead of just "Online" while a wipe is in progress. The appliance
  publishes the phase immediately on start, on each drive finishing, and at the
  end (the 30s heartbeat remains the reconciliation fallback if an event ping is
  lost); the server stores it on `device_presence` and `GET /api/devices`
  exposes it as `erasure`. Offline still wins, and an empty phase clears stale
  state on the next boot.

### Changed

- **PXE payloads served from `.6`** — the tScrub bzImage, WinPE/hashreport
  payload and licence moved to `.6`'s LAN file server
  (`http://192.168.0.6:8080/ipxe/…`, nginx `lan.conf` port 8080). The `.26` boot
  menu keeps all other entries; only its `tScrub` and `hashreport` entries now
  fetch from `.6` (hard IP). The deploy flow (script, bzImage, WIM) no longer
  uploads to `.26`, and the iPXE signing keys now live in `~/ipxe-sb/` on `.6`.

### Release

- PXE bzImage signed (My iPXE Vendor Key) sha256 `11e8308a3ccf1611233a75a0afbc31bedf4ffbcaeadee92949230c728c274354`.

> PXE-only in this pass — the appliance ISO and standalone script are not yet
> republished for v1.10.4.

## [v1.10.3] - 2026-10-02

### Fixed

- **ATA Secure Erase misclassified as FAILED** — SATA drives that support the
  Security Mode feature set but do **not** advertise enhanced erase, and that
  report no `Nmin for SECURITY ERASE UNIT` timing line (identify word 89 = 0,
  e.g. older Seagate Momentus drives), were classified `CAP_NONE` and failed
  instantly instead of running a normal Secure Erase (Clear). Capability
  detection now keys on the Security feature set being supported (with
  enhanced erase absent) — SECURITY ERASE UNIT is a mandatory command of that
  feature set.

### Release

- Appliance ISO `tscrub-v1.10.3_2025.11_30_x86-64_v0.41_20261002-d04bc7ba.iso`
  (990,191,616 B, sha256 `8d2887767573fd2d89327e0bc95969476331c1dd0954fe14000c7dee17f5ae72`);
- standalone script v1.10.3 sha256 `b0b00a16c39f30f33f155dd2be1089e616e57207b1bbee414cd1dfd45529e140` (signed);
- PXE bzImage signed (My iPXE Vendor Key) sha256 `56e9827dd6c4471457c562b595e85bd2a40874ed754c19147c49e80c30a731d9`.

## [v1.10.2] - 2026-10-02

### Fixed

- **Console "Bad file descriptor" noise** — the detached MDM worker wrote to a
  closed IPC fd (3), and the `... >&3 2>/dev/null` guard did not silence the
  redirection error (bash reports it on the original stderr before `2>/dev/null`
  is applied, left-to-right). `mdm::publish` now wraps the writes in a
  stderr-redirected group, so the triage/wipe console stays clean.

### Release

- standalone script v1.10.2 sha256 `e442ced3d6a5d364dfa85bd8454f43da556401cbad301dc826c0f758d0cf2432` (signed);
- PXE bzImage signed (My iPXE Vendor Key) sha256 `3dfdd7adfd1ed7489677d6139db5f544088fb00e76c4aafd85e2935d2a0aa854`.

> The appliance ISO for v1.10.2 is **not published** — deferred until the WinPE
> Autopilot Capture ISO is finalised (see the v1.10.1 boot-menu change).

## [v1.10.1] - 2026-10-01

### Changed — appliance base (kernel)

- **Intel VMD support** — `CONFIG_VMD=y` so NVMe drives behind Intel Volume
  Management Device controllers (common on modern Intel platforms) are
  enumerated instead of appearing as "no supported drives".

### Changed — boot menu (WinPE Autopilot Capture)

- The ISO now boots to a menu offering **tScrub** (default) or
  **Autopilot Capture (Windows PE)** — a WinPE that captures the authoritative
  Windows Autopilot 4K hardware hash (`oa3tool`) and POSTs it to
  `POST /api/mdm/hash`, then reboots. UEFI chainloads `bootmgfw.efi`; BIOS uses
  `wimboot`. The hashreport payload is baked into `sources/boot.wim`.

### Release

- Appliance ISO `tscrub-v1.10.1_2025.11_30_x86-64_v0.41_20261001-06cbaf3c.iso`
  (990,191,616 B, sha256 `0e77f82d2325e633154a9ab792810cfe8b27731c4959eddd977a6cf7194d8303`);
- standalone script v1.10.1 sha256 `8575554c8655f1353a6fac075f01edf3a0aba41b6906cf2d57cb394afa9a6237` (signed);
- PXE bzImage signed (My iPXE Vendor Key) sha256 `83f3a6d5c2fe31c9fbb9fdbaa318bcb73e6aa519ac037b502ca51d18117b3cd3`.

## [v1.10.0] - 2026-10-01

### Changed — triage-first boot

- The appliance is now a **triage tool first**: it connects to the network,
  submits its diagnostics, and starts a long-lived online heartbeat (plus the
  remote BIOS-unlock poll and the opt-in MDM check) before showing anything.
- Boot lands on a **triage screen** (live timer, LAN IP, MDM/BIOS lock status,
  drive inventory). Erasure starts only on **Shift+T**, returns seamlessly to
  triage afterwards, and is repeatable within a session.
- **COCID no longer implies autonuke** — `--cocid` / `tscrub_cocid=` /
  `tscrub.conf` just skip the prompt; `--autonuke` / `tscrub_autonuke=1` is the
  explicit wipe-everything switch (fleet/PXE).
- The elapsed timer now starts **after COCID entry**.
- The post-erasure prompt and the "Run tScrub again" loop are removed; reboot and
  shutdown are disabled during erasure and only offered on the triage screen.
- The heartbeat/BIOS-unlock/MDM workers are now long-lived (no longer killed at
  the end of a run), and MDM status refreshes live on both the triage and wipe
  screens.

### Release

- Appliance ISO `tscrub-v1.10.0_2025.11_30_x86-64_v0.41_20261001-95ece3a3.iso`
  (157,622,272 B, sha256 `e87d5edb20adb81cffcdf914918c936801ae6648cfc8293e4ba7f81da3bc4b32`);
- standalone script v1.10.0 sha256 `ba43c9b1f4301b4c8a28af8903fb7f8444604e3a225c47bc6debbbe7f4e30f22` (signed);
- PXE bzImage signed (My iPXE Vendor Key) sha256 `6859b5c880f99323573e2fe99fd957e004478c52e7adbf34feac61c5acd79b9e`.

## [v1.9.8] - 2026-10-01

### Added

- **Hardware capture (ITAD grading)** — the boot-time diagnostics now capture,
  and the dashboard Devices tab shows: the internal display panel (manufacturer,
  native resolution, diagonal size and manufacture year, parsed from EDID), the
  Wi-Fi adapter model, the CPU core/thread count (`2C/4T`), and DIMM part
  numbers alongside the existing serials.
- **Battery full-charge capacity** — the battery line now reports the current
  full capacity in watt-hours (`(full 33.4 Wh)`), the real wear signal that
  works even on HP firmware that hides the design capacity. The misleading
  "health 100%" is omitted when the firmware reports full == design.
- **SBS battery support in the kernel** — `CONFIG_ACPI_SBS` and
  `CONFIG_BATTERY_SBS` enabled so SBS/SMBus-battery laptops (many ThinkPads and
  Dells) expose their real design capacity and cycle count.
- **Diagnostics report PDF** — each device snapshot renders as a printable
  two-page ITAD-triage report (summary + storage inventory), downloadable from
  the Devices tab; a backfill regenerates existing snapshots.

### Release

- Appliance ISO `tscrub-v1.9.8_2025.11_30_x86-64_v0.41_20261001-276f7320.iso`
  (157,622,272 B, sha256 `d6f2c44e0e12dd1051bb5f45b3b3641e93e2d7868a4baae28a885e4139ae4605`);
- standalone script v1.9.8 sha256 `c8e68cb0801e1b2c0c5a7ccf755e5a6117d6c8c19753d42c790e00f7486b18af` (signed);
- PXE bzImage signed (My iPXE Vendor Key) sha256 `54a5a7c225045c0a4df981cb1ab5c821b9fd04d87fc08922374dcbb45841572b`.

## [v1.9.7] - 2026-10-01

### Added

- **LAN IP reporting** — the appliance now reports its acquired IPv4 address
  (`"ip"`) with every presence heartbeat, so the dashboard shows where each
  machine is reachable (telnet/SSH on the bench LAN). The appliance's System
  Info panel also shows the current LAN IP.

### Changed

- **System Info panel** — the detailed BIOS-lock detection method is no longer
  shown on the appliance console; the Runtime panel keeps the compact
  Locked / Unlocked verdict.

### Fixed

- **Devices tab empty** — `load_devices()` called `ts_rel()`, which was missing
  from `http.php`, so every `/api/devices` request 500'd and the Devices tab
  showed no machines. `ts_rel()` has been restored.

### Release

- Appliance ISO `tscrub-v1.9.7_2025.11_30_x86-64_v0.41_20261001-b1da7932.iso`
  (157,622,272 B, sha256 `37e2f19be7f55f0c8974fbeb6b2f01a67cc7391f16042248ef623de3c60ab22c`);
- standalone script v1.9.7 sha256 `18f5801bb44d5b36aa31adc1e734c60481ba7a44d2b08f67470d93d54467b645` (signed);
- PXE bzImage signed (My iPXE Vendor Key) sha256 `ca2852fbcb60866a8140960430904f84e3fba6fca630eef5d9bd8e22e1726975`.

## [v1.9.6] - 2026-10-01

### Added

- **telnetd in the appliance image** — a `S50telnetd` init script starts
  `telnetd` with a passwordless root shell for bench diagnostics. Intended for
  an isolated bench LAN only; remove the script from the overlay for production
  fleets.

### Release

- Appliance ISO `tscrub-v1.9.6_2025.11_30_x86-64_v0.41_20261001-7bdcec46.iso`
  (157,622,272 B, sha256 `0507efd127a541573abe8ea45f9208f97d8d444c944284fc4de45199cd3d5b6f`);
- standalone script v1.9.6 sha256 `c247d7cc0ceb77b681a14390044ffeadeb423f27d734c61eff4fd5deb2760cac` (signed);
- PXE bzImage signed (My iPXE Vendor Key) sha256 `4661cadeba4d416fa6911931cf64ec2661ee5541e0bafc87d2b4406a8523355f`.

## [v1.9.5] - 2026-10-01

### Changed

- **Unified report delivery** — the boot-time diagnostics snapshot is now
  delivered the same way as the erasure report: writable USB, dashboard
  (`tscrub_api_token=`), and LAN (`tscrub_output=ftp:/sftp:`) all based on
  config. Previously the diagnostics snapshot only went to USB + dashboard.

### Fixed

- **Erasure report gating** — the erasure report is now withheld unless every
  drive reached a terminal state (a worker that died mid-erase no longer
  produces a half-done report).
- **Sector size** — the logical sector size was always recorded empty because
  of a missing-braces array reference in the capture validation; it is now
  populated correctly.

### Release

- Appliance ISO `tscrub-v1.9.5_2025.11_30_x86-64_v0.41_20261001-84709c04.iso`
  (157,622,272 B, sha256 `9f5319f3b9e12550c686441180bdb5baaafa1b681e5b3a9edf1c65cbc222f464`);
- standalone script v1.9.5 sha256 `aa6432dda8523d98081ba58c755cf81d136969c4b4daf1a57b48036692e6f41b` (signed);
- PXE bzImage signed (My iPXE Vendor Key) sha256 `ffca090c6ba962401ca68e62fb52dbc9a049838b5bd161e50af67a3a1a96151e`.

## [v1.9.4] - 2026-10-01

### Added

- **Opt-in hardware self-tests** — `--selftest` (or `tscrub_selftest=1` on the
  kernel command line) runs a deterministic CPU arithmetic check plus a storage
  short self-test per drive (SMART for SATA, `nvme device-self-test` for NVMe).
  Verdicts (`selftest_cpu` and per-drive `selftest_run`: PASS/FAIL/UNKNOWN/UNSUP)
  land in the diagnostics report and the Devices tab. Off by default so triage
  boots stay fast.

### Fixed

- **Battery cycle count** — no longer reports a misleading `0 cycles` when the
  ACPI firmware doesn't expose a cycle count (most laptops, Dell included); the
  field is omitted unless a real, positive count is reported.
- **Battery health** — guarded against a firmware-reported zero full capacity,
  which could otherwise show a bogus `health 0%`.

### Release

- Appliance ISO `tscrub-v1.9.4_2025.11_30_x86-64_v0.41_20261001-454284e8.iso`
  (157,622,272 B, sha256 `53d667b8302cde300a178f4233c4789c127804e5e947267b38ee9f0efe49d10e`);
- standalone script v1.9.4 sha256 `b725a4cab68931fe4279e67acf9dbcb0aff2d4f23a18b840dee1111bad506ff4` (signed);
- PXE bzImage signed (My iPXE Vendor Key) sha256 `1aee89e1b66287fed6f062cfbd77c5137c539409b809dfa615dc3a7010272b3f`.

## [v1.9.3] - 2026-10-01

### Added

- **Report identity & audit proof** — every boot-time diagnostics report now
  carries a `report_id` (UUID) and a `digital_identifier` (SHA-256 over the
  snapshot), so each report is individually identifiable and tamper-evident.
- **Board serial** — the diagnostics envelope now records the baseboard serial
  (previously only captured for erasure reports).
- **Per-drive health in diagnostics** — the drive inventory in a diagnostics
  report now includes firmware revision, logical sector size, total sectors,
  SMART health (PASS/FAIL/UNSUP), the most recent self-test result, and the
  reallocated-sector count.
- **Battery health (laptops)** — battery health is now derived from the ratio
  of full capacity to design capacity (`energy_full`/`energy_full_design`,
  falling back to `charge_full`/`charge_full_design`) plus charge-cycle count,
  instead of the unreliable `health` sysfs attribute.

### Release

- Appliance ISO `tscrub-v1.9.3_2025.11_30_x86-64_v0.41_20261001-052a2217.iso`
  (157,622,272 B, sha256 `df361c77d413dbea435b66a829b218be9c20a4a24d5a0d467889546f9425335d`);
- standalone script v1.9.3 sha256 `a3c2935aaa253bcdfcd8b43cd87a9cc5715f4d81612a300f022be9b1007b3ac6` (signed);
- PXE bzImage signed (My iPXE Vendor Key) sha256 `c282bb1d3822f8e9b8118946f4af27b4590357d0454dc387f8efa47b6f7eb14d`.

## [v1.9.2] - 2026-10-01

### Added

- **Battery, Secure Boot & per-DIMM inventory in diagnostics** — the boot-time
  device snapshot now also records:
  - Battery (model / serial / charge% / health) from `sysfs` on laptops;
  - Secure Boot state (Enabled/Disabled) via `mokutil` or the UEFI `SecureBoot`
    efivar;
  - Per-DIMM memory modules (size / type / speed / serial) from
    `dmidecode -t memory`.
  These surface on the dashboard Devices tab (Hardware & Firmware sections).

### Release

- Appliance ISO `tscrub-v1.9.2_2025.11_30_x86-64_v0.41_20261001-49569a5c.iso`
  (157,622,272 B, sha256 `614459cbe53b9f644c03e0a3097d5ddd5198baa53c0007bc007cd593c3009ae8`);
- standalone script v1.9.2 sha256 `41eaf4a911bfe53ca8ccfb2fc358c55bbbb303862f9005e7f3df22288059693c` (signed);
- PXE bzImage signed (My iPXE Vendor Key) sha256 `b9f04ab365d3a05ccf40cb4fa699a939ae4d046529bb18db30d03651288471d7`.

## [v1.9.1] - 2026-10-01

### Changed

- **Boot diagnostics now carry the v1.9.0 machine fields** — the boot-time
  `/api/reports/diagnostics` envelope now includes SKU, asset tag, BIOS vendor,
  motherboard, TPM, MAC addresses, storage controllers, tool version, operator,
  validator, and media source/destination, so the dashboard Devices tab shows
  the full machine profile (not just system/CPU/GPU/RAM).

### Release

- Appliance ISO `tscrub-v1.9.1_2025.11_30_x86-64_v0.41_20261001-d81e28e5.iso`
  (157,622,272 B, sha256 `d687f7de38e1dd55a5a791acccc534fabd5ce2d8b652a3c2527c75b09f4fe0dc`);
- standalone script v1.9.1 sha256 `53c8789561da943fa0292d77f5fbd1b904b70b7d557d55a0069fb2453c699c03` (signed);
- PXE bzImage signed (My iPXE Vendor Key) sha256 `4da5966d5b9f72262abbe1fcc5583c0fa6f6aefbbb42ad161f7a5217be884ef4`.

## [v1.9.0] - 2026-10-01

### Added

- **Richer device & drive capture in reports** — the report CSV grew from 37 to
  60 columns and the manifest now carries a fuller machine profile:
  - Device: SKU, asset tag, BIOS vendor, motherboard, TPM status, network MAC
    addresses, and storage controllers.
  - Drive: firmware revision, logical sector size, total sectors, HPA/DCO
    state, SED/OPAL lock state, and the most recent SMART self-test result.
  - Timing: per-drive start/end timestamps and duration.
- **Operator & asset metadata fields** — `tscrub_operator`, `tscrub_validator`,
  `tscrub_asset_tag`, `tscrub_media_source` and `tscrub_media_destination` are
  now recorded on the report. Accepted as kernel parameters (PXE), `tscrub.conf`
  keys, or CLI flags (`--operator`, `--validator`, `--asset-tag`,
  `--media-source`, `--media-destination`). The asset-tag value overrides the
  firmware chassis asset tag.

### Release

- Appliance ISO `tscrub-v1.9.0_2025.11_30_x86-64_v0.41_20261001-c6513b5f.iso`
  (157,622,272 B, sha256 `aa7ae31abfd882ae1ecbf941e45fc7ede4054bb237912839cabfa2b0deec10a3`);
- standalone script v1.9.0 sha256 `8043200b82395f434f9ae019a5ec943d66e5d9eaa215a2800685d26f9883a50b` (signed);
- PXE bzImage signed (My iPXE Vendor Key) sha256 `986c79d5f241c02cd4404662b75234131f708dcb6992219ac776f888530d1c8e`.

## [v1.8.19] - 2026-09-30

### Changed

- **Selection screen keys remapped** — Shift+T now starts the wipe (was Shift+S),
  Shift+R restarts the computer, and Shift+S shuts it down. The footer legend
  was updated to match (`T=start R=restart S=shutdown`).

### Release

- Appliance ISO `tscrub-v1.8.19_2025.11_30_x86-64_v0.41_20260930-47d70979.iso`
  (157,622,272 B, sha256 `086cadd008f0dd446d493a15fbd22eb2176cb754dc01f44e4b0da1cc562920ae`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.19 sha256 `0c18ceab3eb03488203b595e78275ad1f1c1a70c1dcf1f14b392b17b26530f06`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `5e3bf4d9dcb86245ba78ea8db85772cc015e68331fc0d4e84dced2fd462656a0`).

## [v1.8.18] - 2026-09-30

### Fixed

- **MDM verdict shown on the selection screen** — the Runtime panel's MDM cell
  showed the initial "Pending" placeholder throughout boot/selection even after
  the worker had settled (the worker publishes over the IPC pipe, which only
  `ui::loop` reads during the wipe). `mdm::sync_state` now copies the worker's
  latest published label from the result file back into the parent shell before
  the selection screen renders, so the laptop and the dashboard agree on the
  verdict from the moment the drive list appears.

  _Shipped together with v1.8.19 (the v1.8.18 ISO was superseded before it was
  announced)._

## [v1.8.17] - 2026-09-30

### Fixed

- **MDM verdict re-polled until it settles** — the appliance's Runtime panel
  used to freeze on "Pending" whenever the server's latest verdict was the
  inconclusive `unknown` (Microsoft had accepted the import but not yet
  processed it). `mdm::detect` now keeps polling `GET /api/mdm/status` through
  both `checking` AND `unknown`, and `POST /api/mdm/autopilot` re-queues a
  fresh probe when the previous job ended inconclusively — so the laptop and
  the dashboard converge on the same latest verdict (e.g. both show "MS error"
  while Microsoft's Autopilot service is degraded).

### Release

- Appliance ISO `tscrub-v1.8.17_2025.11_30_x86-64_v0.41_20260930-7f7b2981.iso`
  (157,622,272 B, sha256 `0003c0b3dbd985ffbe93ce13bb64d71346907c387c342b67f861c9383213cdc6`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.17 sha256 `ce9b1aff4f74f60380b9062dbb14a3fa83197e9b4a672cce321bc200f943cbe0`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `07a32664600c48faaa46438232a8231654b3be601a03a6742816b948a207493b`).

## [v1.8.16] - 2026-09-30

### Changed

- **Boot progress shown before the drive-selection screen** — the boot-time
  device registration (`register::push`) now runs in the background (fd 3
  closed so it can't hold the UI pipe), so a slow or late-arriving network can
  no longer stall the transition to the selection screen. The COCID prompt is
  cleared after entry and replaced with an animated "Discovering devices…"
  progress line, and a "Preparing…" spinner covers the classify/table-build
  step; unfreezing still prints its own progress lines (the suspend/resume is
  the progress).

### Release

- Appliance ISO `tscrub-v1.8.16_2025.11_30_x86-64_v0.41_20260930-f31be9f2.iso`
  (157,622,272 B, sha256 `f3d6b04969fea3540d7487c3f811b363c69d2c0582b8243c9bf8e5bb0f4c6c73`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.16 sha256 `7a1f8005e389871ea9319424d5bb8ab1ee91995a6e3c08af7d48a713741da54b`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `fc2926b640502a93ea5963908316ccef0d663da06b9ed0751519be5f751dada7`).

## [v1.8.15] - 2026-09-30

### Fixed

- **Device registration POST retries for late-link NICs** — the boot-time
  diagnostics push (`register::send` → `POST /api/reports/diagnostics`) was a
  single one-shot attempt (retrying only on TLS error 60). On machines whose
  USB Ethernet adapter brings its link up a few seconds after boot, the POST
  failed silently and never retried, so the machine never appeared on the
  dashboard Devices tab (it kept a live heartbeat but no diagnostics report).
  The push now retries up to three times, re-running `network::ensure` before
  each attempt — mirroring the v1.8.12 licence-fetch fix.

### Release

- Appliance ISO `tscrub-v1.8.15_2025.11_30_x86-64_v0.41_20260930-7b030ec5.iso`
  (157,622,272 B, sha256 `f9e74778542d1692889acc940126586a42de15f9e8f78bcdf1912463c00399f5`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.15 sha256 `2a7d45b54ab35231293a7aaa5b90527d65fc05d60ae0d2c2010c033780b84215`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `6d43d43e9b4033c4eec96df371f1af42a53f9ed36ad4c4001b6273b94c88ff86`).

## [v1.8.14] - 2026-09-30

### Fixed

- **Interfaces brought up before DHCP** — `network::ensure` now issues
  `ip link set <dev> up` for every non-loopback interface before waiting for
  carrier and requesting a lease. A USB Ethernet adapter (e.g. the HP/RTL8153
  dongle) that enumerates after boot — or is reset by the RTL8153
  config-selector re-enumeration — can be left admin-down (`qdisc noop`, no UP
  flag), and `udhcpc` on a down interface never transmits a DISCOVER. The
  interface was bring-up-able manually from the root shell, but the scripts
  weren't doing it; now they do.

### Release

- Appliance ISO `tscrub-v1.8.14_2025.11_30_x86-64_v0.41_20260930-d8beafb7.iso`
  (157,622,272 B, sha256 `94c1557de6ad50374cbe60b4bb7a9bf4440d7e8c385f9bd01ad247bbe7892dac`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.14 sha256 `3d9becda92cc3b3a1d38b423e61caef5ec6d195c63c00eb9cedbdf33af69d3ac`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `fb1ea7a89f6430003a3f7b178b6b2294e4ed447f452fbd4fb3d0cf7e1e2d81dc`).

## [v1.8.13] - 2026-09-30

### Fixed

- **More persistent DHCP for late-link USB NICs** — `network::ensure` now runs
  two DHCP passes per call (a freshly reset RTL8153 re-enumerates via the USB
  config-selector, so the first DISCOVER burst can be missed) with a slower
  retry cadence, and the licence fetch retries up to four times. This gives a
  USB Ethernet adapter time to get a lease on laptops without a working
  built-in NIC.
- **Network state shown on licence failure** — when the licence still can't be
  fetched, the on-screen error now also prints `ip link` / `ip addr` /
  `ip route` before the "Press Enter to retry" prompt, so a failed boot can be
  diagnosed from the console alone.

### Release

- Appliance ISO `tscrub-v1.8.13_2025.11_30_x86-64_v0.41_20260930-406309b6.iso`
  (157,622,272 B, sha256 `f204e0adb0e76b1562eb4dc19dabfb606a37c39ca08395f9e0fcae5683bf2b1a`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.13 sha256 `08a6eb25528eab9d61b37d9c9227fae17b39c21ba937596ee7c6ced683da7361`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `9efde6ca4172e6fb65ee1daf7b52afccec68efff9be27d1d88121bd9f8e2f6f9`).

## [v1.8.12] - 2026-09-30

### Fixed

- **Licence fetch retries for late-link NICs** — USB Ethernet adapters (common
  on laptops with no built-in NIC) can enumerate and bring their link up after
  the boot-time DHCP pass, which made `tscrub_license_url=` fetches fail on the
  first attempt. The licence fetch now retries a few times, re-running
  `network::ensure` before each attempt, so a late link is given time to come
  up instead of failing straight to "No licence file found".
- **Licence error no longer black-screens** — a fatal licence failure printed
  the error and exited, and the appliance's `getty` respawn loop then cleared
  the console and boot-looped into a black screen. The error is now kept on
  screen with a "Press Enter to retry" prompt when running interactively
  (headless runs still exit immediately).

### Release

- Appliance ISO `tscrub-v1.8.12_2025.11_30_x86-64_v0.41_20260930-125c638d.iso`
  (157,622,272 B, sha256 `e6fa286a71b85e0df32cec05761e8c35bb554ed9e03c65affa77676c33cf0ca1`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.12 sha256 `892f699c8242b04ca9e87cb4e7abca55aef06331fd23412b6e0ca9dc554e7efb`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `0c3c4dfd9d1e29692344240a86d937b43cfc7239198f41e97adaaf43b4ef7061`).

## [v1.8.10] - 2026-09-30

### Fixed

- **MDM Runtime panel showed a single dot** — v1.8.9 seeded the panel with the
  Unicode ellipsis `…` while the worker published its first real state, and the
  appliance console renders that character as a lone `.`. The placeholder is now
  plain ASCII `Pending`, so it renders correctly everywhere and is immediately
  replaced by the worker's `Queued`/`Skipped`/`Offline` and then the server's
  exact label.

### Release

- Appliance ISO `tscrub-v1.8.10_2025.11_30_x86-64_v0.41_20260930-b7a11881.iso`
  (157,622,272 B, sha256 `c33632a88aa904a3c01b6b943bfdc39ab363f3f8a186bfbb7624f5ae8409db33`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.10 sha256 `f620375dd6e2edaae0f3810f69903c8b53a9f4fcddc500af7ce9697bcc6cc5cb`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `359648fc69058de600bc8b06422fe27852ede305de3ff19d59007271fd0a6d20`).

## [v1.8.9] - 2026-09-30

### Fixed

- **MDM Runtime panel no longer shows a misleading "Checking…"** — the panel
  was seeded with the literal `Checking…` placeholder and only replaced once the
  MDM worker finished its DHCP wait and the first POST round-trip, so for the
  first ~5–30 s the panel showed the same string the server uses for a genuinely
  in-flight check. The initial state is now a distinct `…` ("no answer yet"),
  and the worker publishes an honest state **before** touching the network
  (`Skipped` when unconfigured/identifiers missing, `Queued` before the POST,
  `Offline` when DHCP/POST fail), then the server's exact `label` after the POST
  and on every poll. `Checking…` now appears only when the server itself reports
  `status=checking`.

### Release

- Appliance ISO `tscrub-v1.8.9_2025.11_30_x86-64_v0.41_20260930-abacb695.iso`
  (157,622,272 B, sha256 `f847b9c154268ad82afebbc928603af9e69405dda5bb11178f8b57b8824d36ff`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.9 sha256 `e08c962b406a76addb03f804c00c8c64dcd2d7859a0e821159d8c69184ed1664`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `4667c0348a05f6a2b32a3bdc04c514c4ec5d833cbc611af3bc786d067dd3986a`).

## [v1.8.8] - 2026-09-30

### Fixed

- **MDM status resolves reliably to the server's verdict** — the 2-minute poll
  window raced the server's resolution (the MDM worker cron runs every minute,
  then the Graph probe takes another ~10–60 s), so a freshly-booted device often
  stayed at `Checking…`/`N/A`. The poll window is now ~5 minutes (10 s × 30),
  so the Runtime panel tracks Queued → Checking… → the final verdict during a
  real wipe. The finish-screen MDM settle wait is also bounded to ~5 s (then the
  worker is stopped and the latest published label recorded), so a fast wipe or
  dry-run can't block the report or the finish screen on a still-pending probe.

### Release

- Appliance ISO `tscrub-v1.8.8_2025.11_30_x86-64_v0.41_20260930-b699700a.iso`
  (157,622,272 B, sha256 `6102a049b783cc11f0a51dbec884ea3066b5bb29fde88321966fdd68f294560b`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.8 sha256 `ea6898a5a1939dac47f962f3bc6eb8ffb57d4941dff2c8eb9708c7f003b024a5`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `4cbf332ed8b912de734ec77f73b5408d0ea334196523083864bb91d56d1d0768`).

## [v1.8.7] - 2026-09-30

### Fixed

- **MDM status now resolves to the server's verdict** — the appliance posted
  `/api/mdm/autopilot` once and showed whatever transient label came back
  (`Queued`/`Checking…`), which never settled because the Graph probe runs in
  the background server-side. The MDM worker now polls `GET /api/mdm/status`
  (bounded, best-effort, never blocks the wipe) and re-publishes the dashboard's
  exact label as it settles, so the Runtime panel tracks
  Queued → Checking… → Locked/Unlocked/… in real time. If the server becomes
  unreachable it shows `Offline`; if the poll window expires while the check is
  still pending it shows `N/A` instead of an indefinite `Checking…`.
- **Cleaner frozen-drive output** — the unfreeze sequence was three confusing
  lines (`sda frozen`, `sda unfreezing`, then `sda not frozen` after the
  suspend/resume), and every never-frozen SATA drive printed a pointless
  `not_frozen`. Now a never-frozen drive prints nothing, a frozen drive prints
  one clear line with the attempt count and a note that the unfreeze is a
  suspend/resume (`sda: frozen — suspending to clear the freeze lock (attempt 1/5)`),
  and success is confirmed with a single `sda: not frozen`.

### Release

- Appliance ISO `tscrub-v1.8.7_2025.11_30_x86-64_v0.41_20260930-187f6a78.iso`
  (157,622,272 B, sha256 `70594481b1f11191059fe12ac2ae5f193276c8ec6009d2000e586d992b094e52`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.7 sha256 `bb3f829a8db2d76662364ea83d380a62ddf2cf11d68a6517ca9d4aae4aec3725`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `a6503cc5875f838ee6a40f3bc911b1a83389f29b0714ff4595094eb8e9331c26`).

## [v1.8.6] - 2026-09-30

### Changed — server-owned MDM wording + two report types

- **Runtime MDM status is now server-owned** — the appliance previously mapped
  the dashboard's machine-readable verdict (`locked_this`, `ms_error`, …) to a
  UI status word itself, so a wording change needed an appliance release. The
  dashboard now returns an exact `label` on `POST /api/mdm/autopilot` and
  `GET /api/mdm/status` (`mdm_status_label()`), and the Runtime panel renders
  it verbatim (colouring still keyed off the verdict). Wording changes are a
  server-only change from now on.
- **Two report types** — reports are now typed in the `reports` table
  (`report_type`: `erasure` | `diagnostics`, default `erasure`). The boot-time
  device + drive snapshot is ingested as a `diagnostics` report via the new
  `POST /api/reports/diagnostics` endpoint (the appliance's registration now
  posts there), and the signed post-erasure CSV remains the `erasure` report.
  The heartbeat itself stays a lightweight presence ping (serial + uuid);
  diagnostics are sent once at boot.
- Dashboard MDM badge prefers the server's `mdm_label` when present.

### Release

- Appliance ISO `tscrub-v1.8.6_2025.11_30_x86-64_v0.41_20260930-b46890f0.iso`
  (157,622,272 B, sha256 `195d2f4f040f0a25712584293b6d30ea35d81e7a41357b4b3aa3c65682c60cf5`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.6 sha256 `8f863fbf1f3705b23f259f6d12349d6b33b07073566833cd410439b1e15844d5`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `5480c7c0354962fcfbdce37807d2d93be727efcd909f4dc491d7137cf4b333b7`).

## [v1.8.5] - 2026-09-30

### Fixed

- **No more black-table flash before selection** — on an interactive boot the
  appliance briefly rendered the old black table (no selection markers) before
  the blue selection screen appeared. The table is now rendered once at the
  right time (blue selection screen for interactive boots, plain table for
  autonuke/headless).

### Release

- Appliance ISO `tscrub-v1.8.5_2025.11_30_x86-64_v0.41_20260930-57f98dba.iso`
  (157,622,272 B, sha256 `ad47ec94eec3a921115ecd83c0cc82b8f9011840aa03206ad1768a654e038c35`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.5 sha256 `925936c6ce965f2e2d786b6d00d29b26133cef4679cb177d85f5757a622d65d4`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `8c80ce147606c082b4355a2c4ff8765930f365d4e4f9d3d0db483286dfaf851b`).

## [v1.8.4] - 2026-09-30

### Fixed — frozen drives + missing identity

- **Frozen-drive hang** — in non-interactive mode (autonuke / PXE with a
  `tscrub_cocid`), a drive that stayed frozen after the suspend/resume unfreeze
  attempts made the appliance loop forever printing `frozen`/`unfreezing`
  instead of giving up — the wipe never started and the drive table never
  rendered. It now gives up after the attempts and records the drive as
  `FROZEN` (physical destruction).
- **Missing model/serial on older laptops** — the drive identity relied solely
  on the `hdparm`/`nvme` pass-through, which some older SATA controllers reject
  (SG_IO), leaving the MODEL/SERIAL columns blank. The appliance now falls back
  to the kernel's `/sys/block/<dev>/device/{model,serial}` inquiry files, which
  are always present.

### Release

- Appliance ISO `tscrub-v1.8.4_2025.11_30_x86-64_v0.41_20260930-046fe730.iso`
  (157,622,272 B, sha256 `d9762d89965ec96deaeaa53fd4aea3ac9088f1f145e1991e5baea18dca5eeb62`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.4 sha256 `5fa8db1f5c7876ebd66f561faed385e2a31e25af3dc690f93b1ba595d3c0e8f6`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `2899fbfa9a59ad2306ee62be5024a660d9cde8429555c6e60838127644a84616`).

## [v1.8.3] - 2026-09-30

### Fixed — boot-time registration & heartbeat

- **PXE appliances now register on boot** — the kernel-command-line
  `tscrub_api_token=` / `tscrub_upload=` were previously only parsed at
  report-upload time (after the wipe), so a PXE-booted appliance never sent its
  boot-time device registration (`POST /api/devices/register`) nor any presence
  heartbeat — it never appeared in the dashboard's Devices tab as a live
  "Not wiped" machine (the final report still uploaded, so it only ever showed
  up as a wiped device). The command line is now parsed at boot.
- **Dead-RTC clock-skew tolerance** — registration and heartbeat now retry once
  without TLS certificate verification on `curl` error 60 (wrong system clock),
  mirroring the report upload.
- **Registration waits for a route** — the one-shot registration now ensures a
  default route exists before posting, matching the report upload.

### Release

- Appliance ISO `tscrub-v1.8.3_2025.11_30_x86-64_v0.41_20260930-377abc28.iso`
  (157,622,272 B, sha256 `5b5f7c880f834aa29faa5f267afb423776ed9a149ca0e4cb4a7dbba83b1634bf`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.3 sha256 `b411e8961e71e259c4e2e3cb8a7bf78812d2b5de401fe90caae0aab142ce10cc`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `b53d3f1df6840a9f44c3c134c75853049b3eb887b3c8d2e5b605795a6324050e`).

## [v1.8.2] - 2026-09-30

### Changed — selection screen polish

- **Blue selection screen** — the triage/drive-selection screen now uses the
  same blue "in progress" theme as a running wipe instead of the black default.
- **Legend moved into the footer** — the key legend
  (`Space=select · ↑/↓ move · A all · N none · S start · Esc quit`) now sits in
  the sticky footer as a third line, with the live selected count, instead of a
  standalone hint line below the table.
- **No more full-screen reflow** — the selection loop renders once, then
  repaints only the affected rows (cursor move, toggle, all/none) and the footer
  legend in place, so the screen no longer redraws end-to-end on every keypress.

### Release

- Appliance ISO `tscrub-v1.8.2_2025.11_30_x86-64_v0.41_20260930-f08ea1eb.iso`
  (157,622,272 B, sha256 `bc7bb961baa801a9585318017966f30e70bf68632fa102d47b15399cd9bf7442`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.2 sha256 `04a2d5e605a0a54f58dd95008a58ccdf3375b867c40ac7f643697e7f648611f6`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `a7e056c2e49f49bfbabbfeadf21c88a2f93f97b64e542656d3dd5f3033a9f92a`).

## [v1.8.1] - 2026-09-30

### Fixed

- **Drive selection lives in the full table UI** — the triage screen now renders
  the complete tScrub interface (system/runtime panels + drive table + footer)
  and overlays an nwipe-style marker gutter on the drive table: `[ ]`/`[x]`
  checkbox per drive, a `>` cursor row in inverse video, and a key legend
  (`Space=select · ↑/↓ move · A all · N none · S start · Esc abort`). The v1.8.0
  build swapped in a bare list instead; this restores the full interface.

### Release

- Appliance ISO `tscrub-v1.8.1_2025.11_30_x86-64_v0.41_20260930-945cd0eb.iso`
  (157,622,272 B, sha256 `9afaa28637a8714518d164ac971abe32cd4b9b2d7f6b98feda652d7278aafb96`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.1 sha256 `022f8c030805aee8760e1bbef8a4db9529befbbff5d4e72b8b8c1c7d6f2d56c0`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `9b529abe3e0b572e975de1bc17c97e14d46343d346ab679f7d2ec5507534074b`).

## [v1.8.0] - 2026-09-30

### Changed — triage-first boot

tScrub is now an ITAD device-triage tool first, eraser second. On boot it no
longer wipes immediately — it registers the machine and presents an interactive
drive selection, and only erases what the operator confirms.

- **No auto-wipe** — the default boot lands on a drive-selection screen
  (nwipe-style): `↑/↓` or `j/k` to move, `Space` to toggle, `a` all / `n` none,
  **`Shift+S`** to start, `Esc` to abort. Nothing is erased until `Shift+S`.
- **Autonuke kept** — `--autonuke` / `tscrub_autonuke=1` select everything and
  start immediately; `--cocid` / `tscrub_cocid=` still imply autonuke (the PXE
  fleet workflow is unchanged).
- **Device registration** — on boot, before any wipe, the appliance posts its
  identity + hardware + drive inventory to the portal (`POST /api/devices/register`)
  and saves the same snapshot to the USB. The Devices tab now shows these as
  live **"Not wiped · N drives"** triage entries; a later report upgrades the
  same serial to wiped.
- **Honest reports** — unselected drives are recorded as `SKIPPED` (`Not selected`,
  not sanitised) in the CSV and manifest, with `selected`/`skipped` counts.

### Release

- Appliance ISO `tscrub-v1.8.0_2025.11_30_x86-64_v0.41_20260930-d2606625.iso`
  (157,622,272 B, sha256 `4e33905769cd7a491bd716ba78f2dbcf87923c156a31fdcb0914498df012ab3b`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.8.0 sha256 `90bef866c1e7b20767a6fe3f6bc39e9f04ab99805c06e4e58e84ebe39ccc6f73`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `8ba7d743c1535491a6ec3add2d31e1a38496e91782fb660f4355c8e16b643c65`).

## [v1.7.0] - 2026-09-29

### Added

- **Presence heartbeat** — the appliance pings the platform every 30 s
  (`POST /api/heartbeat`); the dashboard **Devices** tab shows each machine as
  **Online** or **Offline** from its last-seen time.
- **Remote BIOS unlock** — operators queue a BIOS-password clear from the
  Devices tab (plaintext entry, never stored in plaintext). The appliance polls
  `GET /api/bios/unlock/pending`, clears the password via the kernel
  `firmware_attributes` interface (hp-wmi fallback), and reports the outcome
  back. Queued passwords are encrypted at rest with libsodium secretbox.

### Changed

- **MDM check is non-blocking** — the appliance now shows the server's
  immediate status in a single POST (no client-side polling or verdict
  timeouts); the worker still resolves the final verdict server-side.
- **Reports** — removed the vestigial `drives` array from the report manifest;
  `BIOSLock` now defaults to `UNKNOWN` (was `N/A`) and the detection method is
  reported separately (`BIOSLockMethod`).
- **Dashboard** — pagination with disabled states; fixed-width, non-scrolling
  tables; dim-while-loading on all tabs; report-style detail modals on Devices
  and Drives; per-report expander and drive sort (most recent report first) on
  the Drives tab.

### Release

- Appliance ISO `tscrub-v1.7.0_2025.11_30_x86-64_v0.41_20260929-1754b6c2.iso`
  (157,622,272 B, sha256 `e41f1c49e507afa846c601cd6b53ee33c4f81344ba96666e6d6624d0951810c4`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.7.0 sha256 `56255e4b8dc440742b5c4ca1c48e3c192aaee07911da7ef8d2e9224ef295b250`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `9d77c891cbdeed18339f50ab627daca41f62e2a0a9d0ed1755d3271965434577`).

## [v1.6.3] - 2026-09-29

### Changed — dashboard untangled into focused tabs

Each dashboard tab now answers a single question:

- **Reports** — raw evidence only: Chain of Custody ID, source, ingest time,
  drive/report-file counts, SHA + signature state, and the per-file SHA table.
  The hardware and per-drive drill-down moved out.
- **Devices** (new) — machines processed by tScrub: hardware + firmware profile
  (serials, chassis, BIOS version/date + lock, system UUID, CPU/GPU/RAM),
  consolidated across reports, with a per-device JSON download.
- **Drives** (new) — storage devices: erasure outcome + SMART (pre/post), with a
  per-drive SMART CSV download.
- **MDM** (renamed from "Devices") — the Windows Autopilot enrolment registry
  only. The BIOS-lock badge was removed: BIOS-lock is a point-in-time machine
  attribute and is now reported on the **Devices** tab.

**Backend**
- `GET /api/devices` + `GET /api/drives` aggregate stored report payloads into
  per-machine and per-drive inventories (`load_devices` / `load_drives`).
- Reverted the BIOS-lock state from the MDM path (payload → `mdm_staged_hash`):
  the MDM registry tracks enrolment only; BIOS-lock belongs to the report.

**Release**
- Appliance ISO `tscrub-v1.6.3_2025.11_30_x86-64_v0.41_20260929-c80aa933.iso`
  (157,622,272 B, sha256 `6b57681ef9d0e8ac4118bceace56f6c2c1640dfbf1583e1d324a2a9c66e8b46f`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.6.3 sha256 `35b42d85dde7c0c61210f1008b8730cde8bed34a188d4234b68c05668a19f9f4`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `185559d58b9f9c37a8f5a46a47fb7262e26589f317a030d9c4351b97d121055c`).

## [v1.6.2] - 2026-09-29

### Fixed — BIOS-lock label carried a stray tab (broken display)

When the BIOS is locked, the SMBIOS Type 24 probe read `Administrator Password
Status` from `dmidecode -t 24` without trimming the leading tab, so the
Runtime-panel method cell rendered as `SMBIOS Type 24 (⇥Administrator Password
Status)` — the embedded tab broke the column alignment (extra spacing before the
label) and leaked into the report manifest JSON. The label is now whitespace-
trimmed.

### Added — richer device profile + pre-wipe BIOS-lock flag

- Report CSV + manifest now also carry **chassis serial/type, BIOS version/date,
  system UUID, and the BIOS-lock detection method** (alongside the existing
  `BIOSLock` status), and the dashboard Reports detail renders them.
- The **MDM (Autopilot) submission** now includes the BIOS-lock status + method,
  so a locked unit is flagged on the dashboard **Devices** tab before wiping.
- `marketing/server` — `reports_lib.php` parses the new report columns;
  `mdm.php` stores/returns the BIOS-lock state on `mdm_staged_hash`
  (idempotent `bios_lock`/`bios_lock_method` columns); `api.php` accepts them
  on `POST /api/mdm/autopilot`.

**Release**
- Appliance ISO `tscrub-v1.6.2_2025.11_30_x86-64_v0.41_20260929-4890279e.iso`
  (157,622,272 B, sha256 `e01e1fb25dc115409484525634db7e524048ee4c5780da97109cdf964c398966`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.6.2 sha256 `a7c7259cd83f14b822e8a9b0b0fcfc81d55aed2082b0525fd1cbb683913ec0e5`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `ef24316757f99284cc6fc3f43258e15afd7a4d1df7c10ca49ae0df18d950eeaf`).

## [v1.6.1] - 2026-09-29

### Added — BIOS lock detection

The appliance now detects whether the machine's BIOS has a setup / administrator /
power-on password set, using a vendor-agnostic four-layer cascade (kernel
`firmware_attributes` sysfs → legacy `hp-wmi`/`thinkpad_acpi` → SMBIOS Type 24
"Hardware Security" → UNKNOWN). A LOCKED signal from any layer wins; the result
is surfaced in the Runtime panel (a `BIOS Lock:` row just above MDM) and recorded
in the report (`BIOSLock` CSV column + `bios_lock`/`bios_lock_method` manifest
fields) so locked units can be flagged for password removal before wiping.

- `product/src/36_bios.sh` (new) — `bios::detect`, run synchronously after
  `system::gather_info` (a local read, no worker needed).
- `product/src/40_table.sh` — `ui::bios_render` + the Runtime-panel row
  (Locked=red, Unlocked=green, Unknown=amber).
- `product/src/10_main.sh` — per-run reset + `bios::detect` call.
- `product/src/50_report.sh` — `BIOSLock` CSV column + manifest fields.

**Release**
- Appliance ISO `tscrub-v1.6.1_2025.11_30_x86-64_v0.41_20260929-6d1af711.iso`
  (157,622,272 B, sha256 `68f5c14140742153acc7706a33dd8e1690c34a16b4a214225611a7292e0cca5d`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.6.1 sha256 `67587c1f21c86342edc3871f596460b8e00b6ac48ce20fd48e6d2ea8071158cd`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `f87d5e387e774b883df1a3799a7f5fdd6c2e9ddc5c3c0fa1dbd5f3b466f94529`).

## [v1.6.0] - 2026-09-29

### Changed — Autopilot MDM check: authoritative hash + async queue

The MDM check moved from a synchronous, appliance-generated "base hash" probe to
a two-stage flow: WinPE captures the device's authoritative 4K hardware hash, the
server checks it against Microsoft in the background, and the appliance polls for
the verdict. A generated base hash can never match an enrolled device — only the
full oa3tool hash can (research: `research/autopilot-report.md` §26).

**Server**
- `marketing/server/mdm.php` — added the `mdm_jobs` queue (queued → checking →
  done/failed) with claim/complete/fail/abort lifecycle functions, and
  `mdm_probe()` now takes a poll timeout (default 300 s). The base hash is no
  longer generated; "N/A" is returned when no staged hash exists. Devices are
  keyed by **serial + uuid** (staged hashes and jobs), so two devices sharing a
  serial stay distinct, and duplicate checks aren't queued while one is
  in flight.
- `marketing/server/mdm-worker.php` (new) — cron-driven background worker that
  claims jobs, runs the Graph probe with the staged hash, and records the
  verdict; serialised with a MySQL named lock.
- `marketing/server/api.php` — `POST /api/mdm/hash` now also enqueues a check
  and requires the UUID; `POST /api/mdm/autopilot` returns the job status
  instead of blocking; added `GET /api/mdm/status`, `GET /api/mdm/devices`, and
  `POST /api/mdm/recheck` (all matching by serial + uuid).
- `marketing/server/schema.sql` — added the `mdm_jobs` table.

**Appliance**
- `product/src/35_mdm.sh` — `mdm::detect` now posts, then polls
  `GET /api/mdm/status` (5 s interval, 300 s window) for the background verdict,
  sending serial + uuid.

**Dashboard**
- `marketing/site/dashboard/devices.html` (new) — "Devices" tab showing each
  captured device (serial + UUID) with its status
  (Queued/Checking/Locked/Unlocked/Invalid hash), a Re-check button, and
  auto-refresh while checks are in flight.

**Release**
- Appliance ISO `tscrub-v1.6.0_2025.11_30_x86-64_v0.41_20260929-d77a4e62.iso`
  (157,622,272 B, sha256 `d5097c6ac79969ff11c0aaa861b047c875676fedc9944fb2a8269a1cc3966d82`);
  stable `tscrub-appliance.iso` symlink repointed.
- Standalone script v1.6.0 sha256 `eaf921df1d47409b702e3ccf293221a9d02a1822693e6ed77914102cf4ae1e88`
  (signed).
- PXE `bzImage` republished + signed with the operator's iPXE vendor key
  (sha256 `53a167a5ff034fac5c120a00648c94b3d21ed5fb7c296c1988bc4f940c3740fd`).

## [v1.5.3] - 2026-09-29

### Fixed
- `product/src/10_main.sh` — the runtime **elapsed timer and spinner now keep
  running until every worker has finished**, not just until the last drive
  completes: when the Autopilot check outlives the drive workers, the settle
  wait re-ticks the elapsed/spinner in place.
- `product/src/10_main.sh` — the MDM cell's `Finalising…` state now uses the
  same static three-dot ellipsis as `Checking…` (the previous animated-dots
  version was inconsistent); liveness during the wait comes from the
  timer/spinner.

## [v1.5.2] - 2026-09-29

### Fixed
- `product/src/40_table.sh` — the MDM cell's bold "clash" fallback now resets
  with SGR 22 (normal intensity) as well as the theme text colour. A bare
  `\033[30m`/`\033[37m` only changes the foreground — it does not clear bold —
  so bold was leaking past the MDM value onto the closing border and every row
  below it (reading as a colour/intensity shift on the console).
- `product/src/10_main.sh` — when the Autopilot check outlives the drive workers
  the console now ticks a live one-word `Finalising…` status in the MDM cell
  instead of sitting frozen on `Checking…` for up to several minutes while the
  verdict settles.

## [v1.5.1] - 2026-09-29

### Fixed
- `product/src/50_report.sh` — `report::mount_boot_usb()` no longer falls back to
  a **fixed** disk's FAT partition (e.g. an internal Windows EFI System Partition
  on a PXE boot); it only uses the licence's own volume or a **removable**
  FAT/exFAT volume. PXE-booted machines now keep the report in RAM (or the
  configured network destination) instead of writing it onto the customer's
  drive.
- `product/src/40_table.sh` — the MDM status cell now resets to the **theme's**
  text colour (not the terminal default), fixing the black→white text flip on the
  finish screens; and a status whose colour matches the finish background (green
  "Unlocked" on the green screen, red "Locked" on red, amber "Offline" on amber)
  falls back to the theme's text colour in bold so it stays readable.

## [v1.5.0] - 2026-09-28

### Added — Windows Autopilot MDM check (Phases 1–3)

The Autopilot enrolment-detection feature: a dashboard endpoint that answers
"is this hardware enrolled in Autopilot?" without the appliance ever holding
Azure credentials, an appliance-side background worker + Runtime-panel status,
and report/manifest capture of the verdict (research: `research/autopilot-report.md`
§25; build plan: `research/autopilot-build-plan.md`).

**Server (Phase 1 + 3)**
- `marketing/server/mdm.php` (new) — hash builder (base mode, byte-validated vs
  `research/oa3hash.py`), Graph client (client-credentials token → async import →
  poll → verdict → delete), and an `mdm_checks` verdict cache (24 h TTL).
- `marketing/server/api.php` — new route `POST /api/mdm/autopilot`
  (API-token auth only; per-IP rate limit; input guards; `GET_LOCK` serialisation;
  `set_time_limit(150)` for the async import).
- `marketing/server/schema.sql` — new `mdm_checks` table (also self-created
  idempotently by `mdm_ensure_schema()` on first use).
- `marketing/server/config.example.json` — new `autopilot` block
  (`tenant_id`, `client_id`, `client_secret`) — placeholder only; real values go in
  the server-side `config.json` and are never committed.
- `marketing/server/reports_lib.php` — report ingestion now captures the CSV
  `Enrollment` column (manifest `mdm` field as fallback) into the report payload
  and surfaces it on the reports API (`enrollment`).

**Appliance (Phase 2)**
- `product/src/00_bootstrap.sh` — new `SYS_UUID` capture (`dmidecode -s
  system-uuid` / `/sys/class/dmi/id/product_uuid`) with sentinel-UUID
  normalisation (`03000200-…` / all-zeros → `N/A`).
- `product/src/35_mdm.sh` (new) — background worker: posts `{serial, uuid,
  manufacturer, product}` to the dashboard, publishes `mdm STATUS`/`VERDICT` over
  the worker IPC channel, persists the verdict to `/tmp/tscrub-mdm.verdict`.
- `product/src/10_main.sh` — launches the MDM worker after licence/COCID
  resolution, recovers its verdict after `ui::loop`.
- `product/src/40_table.sh` — Runtime panel `MDM:` row (green Unlocked / red
  Locked / amber Offline / grey Checking), `ui::loop` handles the `mdm` worker.
- `product/src/50_report.sh` — `Enrollment` CSV column + `mdm` manifest field.
- `product/scripts/build.sh`, `product/tests/lib.sh` — include the new module.
- `product/tests/test_mdm.sh` (new) + fake `curl`/`dmidecode` updates — 25 checks.

**Tests:** `research/test_mdm_php.php` 21/21; `product/tests/run.sh` all green
(new `test_mdm.sh` 25/25); `make build-slim` concatenates cleanly.

Verdicts returned: `unlocked`, `locked_this`, `locked_other`, `hash_invalid`,
`unknown`, `offline`, `skipped`.

### Fixed (live-tested against a real tenant)

The Graph import is **async and queue-based**: a busy tenant can leave a new
import at `deviceImportStatus: unknown` for minutes before it flips to
`complete`/`error` (this is a Microsoft-side queue, not a network/egress issue —
confirmed by running the identical flow from two different hosts/egresses).

- `marketing/server/mdm.php` — a poll that closes while the import is still
  `unknown` now returns verdict `unknown` (pending), not `error`. Poll window
  120 s → 180 s.
- `marketing/server/mdm.php` — the unlocked path now also deletes the
  **registered** device (preferred: `state.deviceRegistrationId`; fallback:
  match by serial in `windowsAutopilotDeviceIdentities` — `$filter` is
  unsupported on that collection); previously every successful probe leaked a
  device into the tenant's Autopilot list. Cleanup also runs on a timed-out
  (`unknown`) probe, since a queued import can still complete later.
- `marketing/server/api.php` — `set_time_limit(150)` → `240`; `unknown`
  verdicts are no longer cached (a busy-queue timeout must be re-probed fresh).
- `product/src/35_mdm.sh` — `curl --max-time` 160 → 260 (exceeds the server's
  worst-case response).
- `research/test_mdm_php.php` — new `unknown` + registration-cleanup checks
  (27/27).

### Fixed (deep bug-scan, round 2)

- `product/src/35_mdm.sh` — `mdm::is_configured()` required a non-empty
  `TSCRUB_UPLOAD_URL` **and** token. The upload URL has a built-in default
  (`https://tscrub.com/api/reports`), so token-only configurations (the common
  case) would have the MDM check silently skipped. Now only the token is
  required.
- `marketing/server/api.php` — cached MDM responses now report
  `source: "cache"` (previously they echoed the stale stored `"live"` source).
- `product/src/40_table.sh` — `ui::mdm_render` ended cell colour with `\033[0m`,
  which on the themed wipe screen also cleared the background mid-line. Now uses
  `\033[39m` (foreground-only reset).
- `marketing/server/nginx-location.conf` — added `fastcgi_read_timeout 300s;` to
  the `/api/` location. nginx's 60 s default would truncate the MDM endpoint's
  up-to-240 s Graph response. **Applied live 2026-09-28** (root edit + reload;
  `nginx -t` clean) — verified the directive is in the `/api/` block.
- `product/tests/test_mdm.sh` — added token-only-configured check (26/26).

### Live endpoint tests (2026-09-28, origin + Cloudflare)

- Hash parity: `research/oa3hash.py` and `mdm_base_hash()` produce byte-identical
  4000-char hashes for the known-answer vector
  (`HWIDTEST1234` / `4C4C4544-0036-5710-8032-B5C04F433633` / `DellInc` /
  `Latitude3410`), both matching `research/hwid-base.expected.b64`. New fixture:
  `research/test-mdm-live.json`.
- `POST /api/mdm/autopilot` (origin, `Host: tscrub.com`):
  bad token → `401`; empty/`N/A` serial → `{verdict: skipped}`; fresh device →
  `{verdict: unlocked, source: live}` in 23 s (empty queue); repeat → `{verdict:
  unlocked, source: cache}` in 0.24 s (confirms the cache-source fix).
  Rapid repeat within the 30 s per-IP cooldown → `429`.
- **CONFIRMED (blocker, now FIXED):** via the public URL (`https://tscrub.com`,
  i.e. Cloudflare → nginx) a probe that ran past nginx's default 60 s
  `fastcgi_read_timeout` returned **`504` at 60.3 s** — the live `tscrub.conf`
  `/api/` block lacked the directive. nginx error log:
  `upstream timed out … while reading response header from upstream …
  POST /api/mdm/autopilot`. After applying `fastcgi_read_timeout 300s;` the 504
  is gone (re-verified: no 60 s cut).
- **CONFIRMED (new blocker):** with nginx now allowing 300 s, the public URL
  hits the NEXT limit — Cloudflare's origin read timeout (~100 s) returns
  **`524` at ~125 s** on a busy queue. So the public path still cannot serve
  probes that run 100–240 s. Mitigations: grey-cloud a dedicated API hostname
  (DNS-only → origin, bypasses Cloudflare; e.g. point the appliance's
  `tscrub_upload=` at `https://<direct-host>/api/reports` so both reports and
  MDM go direct), raise the plan (Business = 200 s — still <240 s), or cap the
  server poll window under 100 s (more `unknown` verdicts on a busy queue, each
  re-probed fresh).
- No leftover registrations or import-queue entries after any probe
  (checked `windowsAutopilotDeviceIdentities` and
  `importedWindowsAutopilotDeviceIdentities`).

### Fixed (deep bug-scan, round 3)

- `product/src/40_table.sh` — the MDM cell **never rendered its colour**: it
  checked `[[ -t 1 ]]` but runs inside a `$(...)` command substitution where
  stdout is a pipe, so the green/red/amber branch was always skipped. The colour
  decision is now made by `table::render` and passed in (verified via the raw
  ANSI output and new tests).
- `product/src/35_mdm.sh` — verdict `unknown` (import still queued when the poll
  window closed) now maps to a distinct `UNKNOWN` state rendered as
  **"Pending"** instead of the misleading **"Offline"** (a busy Microsoft queue
  is not a network failure). CSV/manifest still record the honest `unknown`.
- `marketing/server/mdm.php` — registration cleanup now **retries** (Graph's
  registration DELETE is eventually-consistent and returns 400 while the
  registration is still materialising) and falls back to a serial match with
  re-fetch when the reported `deviceRegistrationId` delete doesn't take.

### Fixed (connectivity + retries, round 4)

- `marketing/server/mdm.php` — `mdm_http()` now **retries** transient transport
  failures (DNS/connect/SSL/timeout — e.g. the intermittent `SSL_ERROR_SYSCALL`
  seen live) and Graph **5xx** responses, with a short backoff. POSTs are not
  re-issued on 5xx (a retried import could create a duplicate queue entry);
  2xx/4xx return immediately. Added `CURLOPT_CONNECTTIMEOUT 10`.
- `product/src/35_mdm.sh` — the dashboard `curl` now uses
  `--retry 2 --retry-delay 2 --retry-connrefused` so transient network/5xx
  failures retry automatically (the TLS-skew `-k` retry for curl 60 remains).
- `research/test_mdm_php.php` — +3 checks for the retry policy (transient
  transport retry, GET-on-5xx retry, no POST retry). PHP 31/31.

### Changed (opt-in flag + network gate, round 5)

- `product/src/35_mdm.sh` — the Autopilot check is now **opt-in**: enable it
  with `tscrub_autopilotcheck=true` (tscrub.conf or kernel cmdline) or the CLI
  flag `--autopilotcheck`. `mdm::is_configured()` now requires the flag **and**
  the API token — a token-only config no longer triggers the check. Added
  `mdm::parse_cmdline()` (reads the flag and the cmdline token/upload URL, since
  the worker forks before `report::parse_upload` runs). The worker now skips the
  probe when there is no IPv4 route (`network::ensure` fails) instead of burning
  curl retries against an unreachable dashboard.
- `product/src/00_bootstrap.sh` — new `--autopilotcheck` / `--autopilotcheck=`
  CLI flags.
- `product/src/50_report.sh` — `tscrub.conf` accepts `tscrub_autopilotcheck=true`.
- `marketing/site/docs.html` — documented the parameter (kernel-params table and
  the tscrub.conf example). Deployed.
- `product/tests/` — new fake `ip`; `test_mdm.sh` updated for the flag
  requirement and the network-down skip (35/35).

### Known residual (reproduced live, not code-fixable in this pass)

- On a congested queue, an "unlocked" probe's registration can still leak: it
  appears after the cleanup window and the API DELETE keeps returning **400**
  (eventual consistency). Reproduced live (2 leaked registrations:
  `7H2XK94`, `MDMTEST-VERIFY-1`) — these need **Intune portal** cleanup. A
  deferred background re-check job would close this gap; the Cloudflare 100 s
  ceiling above is the same root cause (the probe script is killed mid-flight).

**Live verification:** `verdict=unlocked` (63 s and 177 s on a congested queue),
with the probe's registration confirmed removed (no leak) via the
`deviceRegistrationId` path.

**Deploy note:** after deploying, add the `autopilot` block to the server's
`config.json` (with a freshly rotated app secret) — the endpoint answers
`offline` until it is configured. No schema step is required (`mdm_ensure_schema`
self-heals), but `schema.sql` is the canonical definition.

**Revert:**
- Server: remove `require_once __DIR__ . '/mdm.php';` and the
  `POST /api/mdm/autopilot` block from `api.php`, delete `marketing/server/mdm.php`,
  and (optionally) `DROP TABLE IF EXISTS mdm_checks;`. The `reports_lib.php`
  `enrollment` fields are additive and harmless to keep.
- Appliance: remove `"$SRC_DIR/35_mdm.sh"` from `product/scripts/build.sh`, delete
  `product/src/35_mdm.sh`, drop the MDM launch/recovery block in
  `product/src/10_main.sh`, and revert the `MDM:` row + `ui::loop` `mdm` case in
  `product/src/40_table.sh`. `SYS_UUID` in `00_bootstrap.sh` and the CSV/manifest
  fields in `50_report.sh` are additive and can stay.

## [v1.4.54] - 2026-09-27

### Changed
- The licence filename is now standardised to `*.lic`. The appliance auto-detects
  only `*.lic` on the boot USB, and the compiled default is
  `/etc/tscrub/license.lic`. **If you still have a `license.key` on your USB,
  rename it to `*.lic`** (or re-download from the dashboard) — `license.key` is
  no longer accepted.

## [v1.4.53] - 2026-09-27

### Changed
- Licence-on-USB: when multiple `.lic` files are present on the boot USB, tScrub
  now selects the highest tier (`enterprise` > `team` > `payg` > `free`) instead
  of the alphabetically-first file. A stray `free.lic` can no longer silently
  downgrade a paid customer's evidence; when multiple files are found, a warning
  names the selected file and tier.

## [v1.4.52] - 2026-09-27

### Fixed
- A `--dry-run` report no longer carries the optimistic class/certification/method
  of the wipe that never ran. DRY-RUN drives are now recorded as NOT SANITISED
  with method "Dry run — no sanitisation performed" (previously the CSV kept the
  classified method, e.g. "NVMe Crypto Purge" / "DESTRUCTION").

### Changed
- Report ingestion: an upload with no Chain of Custody ID now recovers one from
  the filename (any 4–8 digit run) and, failing that, keys off the file's own
  hash, so unrelated COCID-less uploads no longer merge into a single
  certificate.

## [v1.4.51] - 2026-09-26

### Fixed
- The report CSV is now honest for drives that did not complete. A BLOCKED or
  FAILED drive no longer keeps the optimistic class/certification/method it was
  classified for (e.g. "NVMe Crypto Purge" / "DESTRUCTION"); it is recorded as
  NOT SANITISED with a matching method. FROZEN drives stay as-is.

## [v1.4.50] - 2026-09-26

### Changed
- The finish screen now shows green when at least one report destination
  (USB, dashboard, or FTP/SFTP) succeeded; amber is reserved for when every
  configured destination failed. The per-destination detail is still listed.

## [v1.4.49] - 2026-09-26

### Changed
- The runtime spinner now advances 4× per second (was once per second), so a
  long wipe visibly "ticks" instead of appearing stuck.

## [v1.4.48] - 2026-09-26

### Fixed
- Fetching a licence over the network (`tscrub_license_url=`) on a PXE or
  bare-metal boot could fail because the licence was fetched before the Linux
  kernel's DHCP lease had landed (the UEFI/iPXE stack has its own lease). tScrub
  now ensures a default route exists before fetching the licence.

## [v1.4.47] - 2026-09-26

### Changed
- The finish-screen recovery guidance (one line per non-completed drive) now
  blinks so a recoverable condition is not overlooked.

## [v1.4.46] - 2026-09-26

### Changed
- An NVMe sanitise that is denied with `0x4015` ("Operation Denied: lack of
  access rights") is now classified `BLOCKED` rather than `FAILED`. `0x4015` is
  the same BIOS TCG Block SID lockdown family as `0x4286` — the drive itself is
  fine and the wipe is recoverable.

### Added
- The finish screen now prints one actionable line per non-completed drive
  (BLOCKED / FROZEN / FAILED), telling the operator how to recover
  (clear Block SID / hard-disk security in BIOS, or move the drive, then re-run).

## [v1.4.45] - 2026-09-25

### Fixed
- The finish screens (green/red/amber) now have the same top blank-line margin
  as the running screen, so all four sides of the console are framed evenly.

## [v1.4.44] - 2026-09-25

### Changed
- The footer now has a separator line above it and a blank bottom margin,
  matching the spacing at the top of the screen.

## [v1.4.43] - 2026-09-25

### Added
- A sticky footer pinned to the bottom of the console showing
  "tScrub v1.4.43 — tscrub.com".

### Fixed
- Dashboard reports and billing credit history now show British Time
  (Europe/London) instead of UTC.

## [v1.4.42] - 2026-09-25

### Changed
- The full-width table now keeps a small, equal margin on the left and right
  (and the finish messages/summary align to that margin) instead of running
  edge-to-edge.

## [v1.4.41] - 2026-09-25

### Changed
- The device table now fills the full terminal width instead of centring at a
  fixed 182-column cap; MODEL and SERIAL absorb the extra width. Small
  terminals are unchanged (the same column-dropping tiers still apply).

## [v1.4.40] - 2026-09-25

### Changed
- The report, upload and diagnostic progress messages now go to the log only,
  so the console shows nothing between the wipe finishing and the outcome
  screen.
- The tScrub dashboard URL is now built in: setting `tscrub_api_token` alone
  pushes the report to the dashboard (`tscrub_upload=` remains an optional
  override).
- The finish summary now labels the dashboard destination "Dashboard" and only
  lists destinations that are configured.

## [v1.4.39] - 2026-09-25

### Fixed
- The blue "running" screen was painted one line higher than the normal
  running screen, so the in-place elapsed-time tick overwrote the COCID row and
  the ETA/device rows rendered one line out of place. The blue screen now keeps
  the normal layout's leading blank line, and the finish-screen cursor
  reposition is skipped while running.

## [v1.4.38] - 2026-09-25

### Changed
- The console now runs the wipe on a blue screen and only switches to the
  outcome colour (green/red/amber) once the wipe, post-wipe SMART capture and
  report delivery have all finished — no more flash to green/red before the
  amber report-delivery-failed screen.

## [v1.4.37] - 2026-09-25

### Fixed
- A UTF-8 byte-order mark (BOM) on the first line of an on-USB `tscrub.conf`
  (e.g. re-saved by a Windows editor) made the first key — typically
  `tscrub_upload` — unparseable, so the dashboard upload was silently
  unconfigured while later keys (such as `tscrub_cocid`) still worked. The
  config loader now strips a leading BOM from each line.

### Added
- The diagnostics snapshot now records what the on-USB `tscrub.conf` loader
  found — volumes scanned, whether `tscrub.conf` was present, and which keys
  were applied (the API token is redacted). Written to the debug file only.

## [v1.4.36] - 2026-09-25

### Added
- Licence info (customer, tier, expiry) is now shown in the appliance's
  Runtime info panel.
- A live spinner on the elapsed timer, so a running wipe is visibly active
  rather than appearing stuck.

## [v1.4.35] - 2026-09-24

### Fixed
- The "Elapsed" counter (and the per-drive ETA countdown / NVMe monitor
  timeout) could freeze at 00:00:00 on machines whose system clock is wrong,
  frozen, or stepped backwards — e.g. laptops with a dead RTC battery or an
  NTP/hwclock step mid-run. All duration measurements now use a monotonic
  clock (`/proc/uptime`, with a `date +%s` fallback for non-Linux hosts)
  instead of wall-clock time.

## [v1.4.34] - 2026-09-24

### Fixed
- Report upload now retries with `curl -k` (skip certificate verification) when
  TLS verification fails with curl error 60 — caused by a wrong system clock on
  machines whose RTC/battery is dead and can't be set. The dashboard upload now
  succeeds on those machines.

### Changed
- Device discovery no longer writes `hdparm`/`nvme id-ctrl` probe noise (e.g.
  `SG_IO: bad/missing sense data`) into the log when a drive doesn't support
  ATA/NVMe identify — keeps the diagnostics snapshot clean on VM/emulated disks.
- Removed `CONFIG_OF_UNITTEST` from the appliance kernel (removes 385 boot-time
  self-test lines and a little startup delay).

## [v1.4.32] - 2026-09-24

### Fixed
- Appliance boot-time networking regression from v1.4.31: the carrier-up
  handler used `ifdown -f` + `ifup`, which bounced the link and re-triggered
  the carrier handlers in a loop, so the DHCP lease was torn down before it
  could be used. It now re-requests DHCP directly (`killall udhcpc` then
  `udhcpc -b`) without touching link state. Verified on a Proxmox VM with a
  NIC whose link comes up ~6 s after boot (late-link reproduction).

## [v1.4.31] - 2026-09-23

### Fixed
- Boot-time networking: when a NIC's link comes up after the first DHCP pass,
  ShredOS's hotplug `ifup` was a no-op (the interface was already marked "up"
  in `/var/run/ifstate`), so no lease was ever obtained. `shredos_net.sh` now
  forces `ifdown -f` + `ifup` when carrier appears, re-requesting DHCP.

### Changed
- `network::ensure` now waits up to 20 s for a carrier before running DHCP, so
  the end-of-run upload survives NICs that take a while to link.
- The boot network init output is logged to `/var/log/shredos_net.log`, and the
  diagnostics snapshot now includes it plus `/var/run/ifstate`.

## [v1.4.30] - 2026-09-23

### Fixed
- Report upload could fail with "Could not resolve host" on machines whose NIC
  link comes up after boot-time DHCP has already run (e.g. Intel e1000e, which
  can take several seconds to link). Before any configured upload, tScrub now
  re-checks for an IPv4 default route and, if missing, re-runs DHCP on the
  carrier-up interface; if the lease arrives without DNS it falls back to
  public resolvers.

### Added
- The diagnostics snapshot now also records the running process list, so a
  missing/stuck `udhcpc` can be seen after the fact.

## [v1.4.29] - 2026-09-23

### Added
- A diagnostics snapshot is now written to the root of the report USB stick at
  the end of every run (`tScrub_debug_<timestamp>.txt`): kernel command line,
  network links/addresses/routes, `/etc/resolv.conf`, the report-delivery
  outcome, `dmesg`, and the tScrub log — for debugging boot/network/upload
  problems in the field.

## [v1.4.28] - 2026-09-23

### Added
- The finish screen now prints a per-destination "Report delivery" summary:
  USB stick, tscrub.com, and the network location (FTP/SFTP) — each shown as
  OK, FAILED with the reason (the actual curl/lftp error), or "not configured".
- Restored Broadcom NIC firmware (`linux-firmware` bnx2 + bnx2x + tg3, ~3 MB)
  so report uploads also work on the Broadcom onboard NICs common in older
  Dell PowerEdge / HP ProLiant servers.

### Changed
- Report save/upload outcome is now tracked per destination, and the amber
  finish screen triggers when any configured destination fails.

## [v1.4.27] - 2026-09-23

### Added
- The finish screen now turns **amber** when the wipe completed but the report
  could not be delivered: saved to USB/RAM, pushed to tscrub.com (if
  configured), or uploaded to a network location (if configured). Red still
  means a drive failed/blocked; green means everything succeeded.

### Fixed
- The upload dispatcher no longer prints "Dashboard upload configured but no
  API token" when a token *is* present but the HTTP push fails — that message
  only appears when the token is actually missing. Upload failure is now
  reported back to the run flow so the amber screen can trigger.

## [v1.4.26] - 2026-09-23

### Fixed
- Restored the Realtek NIC firmware blobs (`linux-firmware` RTL815x + RTL8169)
  that the rootfs slimming pass had dropped. USB-C Ethernet adapters and docks
  (e.g. the Lenovo X13) use the in-kernel `r8152`/`r8169` drivers but need these
  PHY firmware files to bring the link up, so end-of-run report uploads were
  failing on machines without a built-in Ethernet port.

## [v1.4.25] - 2026-09-23

### Changed
- The dashboard upload confirmation now reads "report(s) stored" (uploading a
  report no longer generates a certificate — that is done on the dashboard),
  and the fallback message only mentions FTP/SFTP when one is configured.

## [v1.4.24] - 2026-09-23

### Added
- The report CSV now includes the machine profile on every row: `System`,
  `SystemSerial`, `BaseboardSerial`, `CPU`, `GPU`, and `RAM` — so each drive in
  a run is attributable to its host (manufacturer, model, serials, processor,
  graphics, and installed memory).

## [v1.4.23] - 2026-09-23

### Added
- `tscrub.conf` now also accepts `tscrub_output` — a local path or an
  `ftp:host:path:user:pass` / `sftp:host:path:user:pass` upload destination
  (same parsing as the kernel parameter, colon-safe passwords included).

## [v1.4.22] - 2026-09-23

### Added
- `tscrub.conf` on the boot USB: a `KEY=VALUE` file that preconfigures a run
  without editing GRUB or the kernel command line. Supported keys:
  `tscrub_upload`, `tscrub_api_token`, `tscrub_cocid`, `tscrub_license_url`.
  Command-line flags always take precedence.

## [v1.4.21] - 2026-09-22

### Changed
- Reports are now written into a `reports/<COCID>/` folder (on the USB stick or
  wherever the report destination is) instead of the volume root, keeping each
  Chain of Custody run together and easy to find.

## [v1.4.20] - 2026-09-22

### Changed
- Reports are now written to the **same volume the licence was found on** — so a
  Rufus-written stick (single writable partition) gets its reports in the same
  place as the `.lic`, matching the `dd`/Etcher flow where everything lives on
  the TSCRUB-USB partition. The FAT scan no longer matches NTFS volumes.

## [v1.4.19] - 2026-09-22

### Changed
- The writable USB partition is now labelled **TSCRUB-USB** (was unlabelled), and
  the report destination message now says "the USB stick" instead of printing
  the internal `/tmp` mountpoint — so it's clear where the report actually went.

## [v1.4.18] - 2026-09-22

### Fixed
- Reports written to the USB stick could vanish on reboot: the writable
  partition was mounted but never `sync`ed/unmounted, so the buffered FAT writes
  were dropped on power-off. The report mount is now flushed and released before
  the reboot/shutdown menu.

### Changed
- The appliance now boots straight into tScrub (GRUB timeout set to 0) instead
  of showing the boot menu with a countdown.

## [v1.4.17] - 2026-09-22

### Fixed
- Reports were written to RAM instead of the boot USB on machines that report
  the USB stick as a fixed disk. `report::mount_boot_usb` now finds the writable
  partition the same way ShredOS does — an `fdisk` scan of FAT/exFAT volumes
  (no removable-flag filter), preferring the partition that carries
  `boot/version.txt` — instead of relying on `lsblk`'s `RM=1` flag.

## [v1.4.16] - 2026-09-22

### Fixed
- The appliance was built **without the `openssl` CLI** (`BR2_PACKAGE_LIBOPENSSL_BIN`
  was unset), so `license::verify` failed on every boot and a valid `.lic` on the
  USB still produced "No valid licence found". The appliance image now includes
  the `openssl` binary.

### Changed
- `license::detect_usb` now scans FAT **and** ISO9660 volumes and falls back to
  non-removable devices, so a `.lic` on the boot stick is found regardless of how
  the USB reports itself (RM=0, superfloppy, ISO root, etc.).
- `license::verify` now prints the specific failure reason (missing openssl,
  malformed file, expired, or bad signature) instead of a generic message.

## [v1.4.15] - 2026-09-22

### Changed
- The appliance now looks for a licence on the **boot USB first** — drop a
  `license.key` (or any `*.lic`) at the root of the stick and it is picked up
  automatically. `/etc/tscrub/license.key` remains the fallback when no USB
  licence is found.

## [v1.4.14] - 2026-09-22

### Removed
- The custom-appliance path: `make build-customer`, the embedded customer
  licence (`LICENSE_EMBEDDED_B64` / `license::apply_embedded`) and the
  `build-enterprise` alias are gone. The appliance image is the only supported
  distribution; licences are supplied at boot (`tscrub_license=` /
  `tscrub_license_url=`).

### Changed
- Internal comments and messages now say "appliance" rather than "ShredOS".

## [v1.4.13] - 2026-09-21

### Added
- Non-interactive "autonuke" mode: supply the Chain of Custody ID via
  `tscrub_cocid=<5 digits>` on the kernel command line (or `--cocid 12345` as a
  CLI flag) and tScrub skips the COC prompt, auto-continues frozen drives, and
  skips the post-run prompt.

## [v1.4.12] - 2026-09-21

### Added
- SFTP report upload: `tscrub_output=sftp:host:path:user:pass` uploads the
  report (CSV + manifest + signature) over encrypted SFTP, alongside the
  existing FTP form. Both run through `lftp`, which is built into the appliance
  image.

### Changed
- The FTP upload destination is now set with `tscrub_output=ftp:…` (renamed from
  `shredos_output=`). `tscrub_output=` is now overloaded: a filesystem path is
  the local report directory, while `ftp:`/`sftp:` prefixes are network uploads.
  `shredos_output=` is still accepted as a deprecated alias.
- Removed the `shredos_license`/`shredos_license_url` kernel parameters; use
  `tscrub_license`/`tscrub_license_url`.

## [v1.4.11] - 2026-09-21

### Fixed
- Licences issued from the website (dashboard) failed verification on the
  appliance: `license::verify` parsed the licence JSON with `sed` patterns that
  expected a space after each colon (`"key": "…"`), but the dashboard stores
  licences as compact JSON (`"key":"…"`). The patterns are now
  whitespace-tolerant, so both pretty-printed and compact licences verify.

## [v1.4.10] - 2026-09-21

### Added
- Drive temperatures above 75°C render in red on the live table.

### Changed
- The device table now adapts to the terminal width instead of being fixed at
  186 columns — columns shrink and low-value columns are dropped (METHOD, then
  CERT, then CLASS/SMART) so the table still fits on one line down to an
  80-column console.
- The table and its info panels are now centred in the terminal, and the
  Chain-of-Custody ID prompt is centred mid-screen.
- Removed the redundant CERT ("Certification") column from the device table;
  CLASS (Clear/Purge/Frozen/Failed) is the meaningful NIST SP 800-88 category.
- The ETA column shows "--" once a drive has finished, instead of "Done".

### Fixed
- Table, panel, and separator widths are now consistent (the table was wider
  than the info panels and the separator overhung the frame).
- The finish message is indented to line up with the table.
- The in-place runtime timer no longer hard-codes a width that broke when the
  panels were widened.
- Blank model/serial cells show "N/A", and vendor placeholder strings ("Not
  Specified", "To Be Filled By O.E.M.", "System Product Name", ...) are
  normalised to "N/A".
- The licence banner now correctly reads "free tier (self-signed reports)"
  instead of "unsigned".
- The slim build no longer reports sedutil as installed when it carries no
  embedded payload.

## [v1.4.9] - 2026-09-21

### Fixed
- The signed-report manifest was invalid JSON — the `"public_key"` field had no
  trailing comma, and on the unsigned path `"signed"` lacked one too, so strict
  parsers (python `json.load`, PHP `json_decode` in certify.php) rejected every
  manifest. Both fields now emit their commas unconditionally.
- FTP report uploads now fail fast instead of hanging indefinitely: lftp is
  given `net:timeout 15` and `net:max-retries 1`.

## [v1.4.8] - 2026-09-21

### Fixed
- `exec 5>>"$LOG_FILE" 2>/dev/null` permanently redirected the shell's own
  stderr to `/dev/null` (a bare `exec` applies its redirections to the current
  shell), silently swallowing every later diagnostic — the licence error, SMART
  warnings, and all `>&2` messages. An unlicensed run printed nothing and
  exited 1. The log fd is now opened without touching stderr.
- The coprocess read-end close in `ui::loop` scoped its `2>/dev/null` to a
  command group so a "Run again" pass can never redirect stderr either.

## [v1.4.7] - 2026-09-20

### Fixed
- A report that fails to write now aborts loudly instead of printing "Report
  written to" while nothing landed on any destination (read-only rootfs / no
  writable USB / full disk).
- When the dashboard upload fails (or curl is missing), tScrub now falls back
  to a configured FTP server instead of silently giving up.
- A worker that dies without emitting a terminal status (OOM/killed) is
  recorded as `UNKNOWN` in the report, never left as `RUNNING`.
- Drive model/serial strings are stripped of control characters at discovery
  (prevents terminal escape injection and keeps CSV/JSON clean).
- Device table columns are width-capped so long firmware strings no longer
  overflow or misalign rows (also fixes the ETA cell).
- Worker `LOG` messages are forwarded to the log file instead of being
  discarded by the UI loop.
- Report timestamps are captured once per job (no second-boundary drift
  between rows); the JSON manifest now escapes every interpolated value.
- FTP paths/filenames are quoted; duplicate `shredos_output=` cmdline params
  are de-duplicated; curl failures now show the underlying error; a missing
  SHA-256 tool is surfaced instead of silently omitting the checksum.

### Changed
- Non-interactive (headless) runs no longer clear/redraw the table every
  second; the completion message is printed on the finish screen.

## [v1.4.6] - 2026-09-20

### Fixed (product)
- SMART raw values now parse the last column, so reports no longer drop temp/
  power-on-hours/wear when smartctl omits the `WHEN_FAILED` cell.
- SMART capture keeps its timeout guarantee when `/tmp` is unwritable.
- The frozen-drive check no longer re-prompts forever when `hdparm -I` shows no
  security section at all.
- Log fd 5 always opens (falling back to `/dev/null`) so worker redirections
  can't silently fail and misclassify drives.
- CLI rejects empty `--license=`, `--license-url=`, and `--output=` values.
- Blank DMI cells (manufacturer/product/chassis/BIOS) now normalise to `N/A`.
- `issue_license.sh` rejects customer names with quotes, backslashes, or
  control characters (matching the web issuer).
- A configured dashboard upload with no API token now warns and falls back to
  FTP instead of silently doing nothing.
- Manifest SHA-256 falls back to `openssl dgst` when `sha256sum`/`shasum` are
  absent.

### Fixed (backend)
- Licence JSON is emitted as raw UTF-8 (`ensure_ascii=False` /
  `JSON_UNESCAPED_UNICODE`) so accented customer names still verify on the
  appliance.
- Uploaded report fields are clipped to their column widths (no more "Data too
  long" 500s); sidecar files match case-insensitively; first/last timestamps
  are compared via `strtotime` instead of raw string order.
- The CSRF token is rotated alongside the session ID at login.
- `/api/certify` and `/api/reports` are rate-limited; `/api/verify-email/resend`
  has an equal-time no-op path.
- Added a defensive nginx `deny all` for the backend directory.

### Changed (marketing)
- Public pages and LLM files now mention the bootable appliance ISO; the
  terminal demo is current with the latest release.

## [v1.4.5] - 2026-09-20

### Fixed
- SMART `-d sat` bridge retry now triggers on a failed attribute-table parse
  (smartctl always prints a banner, so the old empty-stdout test never fired).
- SMART values now parse hex forms (e.g. `0x8` critical warnings) instead of
  reading them as `0`.
- SAS/SCSI drives without a kernel transport file are reported as `SCSI` (was
  the misleading `PCI`).
- Drives whose `blockdev --getsize64` fails now show `N/A` instead of ` GB`.
- NVMe sub-controller namespaces (e.g. `nvme0c1n1`) are discovered; USB-attached
  NVMe is excluded like USB SATA.
- The certificate SMART annex no longer renders when every SMART field is
  `UNSUP`/empty.

## [v1.4.4] - 2026-09-20

### Fixed
- The `tscrub verify <report.csv>` subcommand is now reachable from the CLI
  (option parsing ran before the verify dispatcher and rejected it as unknown).
- Explicit CLI `--license` / `--license-url` / `--output` now take precedence
  over kernel-command-line values (previously the cmdline silently won).
- `--dry-run` reports are never vendor-signed, and the simulated run marks
  drives `DRY-RUN` instead of `COMPLETED`.
- Closing the IPC read end on "Run tScrub again" so repeated runs no longer
  leak a file descriptor per run.

## [v1.4.3] - 2026-09-20

### Fixed
- Removed the dead SCSI progress parser that never matched real `nwipe --nogui`
  output (the live `%` never updated; completion is reported via RUNNING → COMPLETED/FAILED).
- Ctrl+C / SIGTERM now abort the run after restoring the cursor (was silently swallowed).
- `ui::coc_prompt` and the ATA-unfreeze prompt fail cleanly when no controlling
  terminal is present instead of spinning forever.
- Post-run menu option `C` now reads "Continue" (it never started nwipe).
- Added test coverage for the SCSI wipe success and failure paths.

## [v1.4.2] - 2026-09-19

### Added
- Appliance image ships `sedutil-cli` built in (`BR2_PACKAGE_SEDUTIL=y`, upstream
  Buildroot `package/sedutil` v1.20.0) instead of relying on the embedded payload.
- New `make build-slim` target (`SKIP_SEDUTIL_PAYLOAD=1`) builds the script without
  the embedded sedutil payload for the appliance image; the Download-page build
  keeps the payload for bare-Linux use.

### Fixed
- `nvme format` no longer passes an invalid `-f` flag (NVMe Clear-only drives now
  wipe instead of failing).
- `report::mount_boot_usb` no longer `rm -rf`s a live mountpoint on a read-only
  remount (which could have wiped the boot stick).
- Free-tier report signing falls back to a writable tmp key directory on read-only
  rootfs, so free reports stay self-signed on the appliance.
- PSID revert command now runs once (was executed twice).
- ATA Clear on HDDs is labelled `ATA Secure Erase` (Clear), matching the command
  actually run instead of the incorrect `HDD Overwrite` (Purge).
- Removed dead `device::monitor_ata` (hdparm erase is synchronous).
- CSV fields escape embedded double-quotes (RFC 4180).
- `device::monitor_nvme` fails fast when SSTAT never reports progress instead of
  spinning for six hours.
- `device::frozen` handles `sdaa+` device names and quotes the array expansion.
- FTP passwords may now contain colons.

### Changed
- Marketing/docs copy corrected: free-tier reports are self-signed with an
  appliance-generated key (tamper-evident, not attributable), not unsigned; docs
  dependency lists now include `smartctl`/`openssl`.

## [v1.4.1] - 2026-09-19

### Fixed
- NVMe purge methods are now recorded with specific names in the report CSV
  (`NVMe Crypto Purge`, `NVMe Block Purge`, `NVMe Overwrite Purge`) instead of
  the ambiguous `Secure Erase`, so certificates and reports match the
  compliance documentation.

## [v1.4.0] - 2026-09-19

### Added
- Pre-wipe and post-wipe SMART capture (`smart::capture_all`) for ATA/SATA/SAS
  (`smartctl`) and NVMe (`nvme smart-log`) drives.
- SMART value-assessment columns in the report CSV: `SMART`, `TempC`,
  `PowerOnHours`, `PowerCycles`, `ReallocSectors`, `PctUsed`, `AvailSpare`,
  `TBW_TB` (pre-wipe) and `SMARTPOST`, `TempCPost`, `PowerOnHoursPost`
  (post-wipe).
- `SMART` (health) and `TEMP` columns in the terminal UI.
- `smart::run` time-limited runner (`SMART_TIMEOUT`, default 10s) so a hung or
  dead drive can't stall the boot; falls back to a watchdog kill when the
  `timeout` binary is unavailable.

### Changed
- Report CSV expanded from 12 to 23 columns. Backwards-compatible: the
  Certificate of Destruction generator maps columns by name from the header.
- `smartmontools` promoted from optional to a required dependency in the
  roadmap.

## [v1.3.0] - 2026-09-13

### Added
- Baked-in customer licences: a customer `.lic` can be embedded at build time,
  so no licence needs to be supplied at boot.
- Vendor-signed, attributable reports when a valid licence is present.

## [v1.2.0] - 2026-09-13

### Added
- Remote licence fetching (`--license-url` / kernel cmdline) so licences can be
  served from an isolated LAN endpoint.

## [v1.1.0] - 2026-09

### Added
- Initial signed-report support and release-history tracking. See the release
  history table in `marketing/site/docs.html` for the artifact checksums.

## [v1.0.0] - 2026-09

### Added
- Initial release of tScrub: firmware-level sanitisation for NVMe, ATA/SATA and
  SCSI devices, terminal UI, and CSV reporting.
