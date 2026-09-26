# Changelog

All notable changes to tScrub are documented here. Releases are checksummed and
signed; the authoritative checksums live in `/downloads/manifest.json`.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses date-based versioning (`v1.x`).

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
