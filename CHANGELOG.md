# Changelog

All notable changes to tScrub are documented here. Releases are checksummed and
signed; the full release history (SHA-256 + signing-key fingerprint) lives in
`marketing/docs.html`.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses date-based versioning (`v1.x`).

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
  history table in `marketing/docs.html` for the artifact checksums.

## [v1.0.0] - 2026-09

### Added
- Initial release of tScrub: firmware-level sanitisation for NVMe, ATA/SATA and
  SCSI devices, terminal UI, and CSV reporting.
