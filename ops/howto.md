# tScrub — Technical Build Guide

> Internal runbook. Paths below assume the standard build host
> (`oxwet@192.168.0.6`) and the macOS workstation. Replace them if your setup
> differs. Nothing in this file contains key material, credentials or tokens —
> those live server-side and are referenced by path only.

This guide walks the whole pipeline in three stages:

1. **[The tool](#1-the-tool-tscrubsh)** — what `tscrub.sh` is, its features, and how to assemble it from source.
2. **[Making it bootable](#2-making-it-bootable)** — building the appliance ISO from our Buildroot fork, with UEFI Secure Boot via shim + MOK enrolled from the same USB.
3. **[Generating reports](#3-generating-reports)** — the chain of custody: CSV → signed report → upload → Certificate of Destruction.

---

## 1. The tool (tscrub.sh)

### 1.1 What it is

`tscrub.sh` is a single, self-contained Bash script (GPL-3.0-or-later) for
secure disk sanitisation. It:

- erases **NVMe**, **SATA/ATA** and **SCSI/SAS** drives to **NIST SP 800-88
  Clear, Purge and Destroy** levels, automatically picking the strongest method
  each drive supports;
- captures **SMART** health data before and after the wipe (temperature,
  power-on hours, reallocated sectors, TB written, …);
- writes a **chain-of-custody report** — a CSV plus an Ed25519 signature and a
  JSON manifest — which is uploaded to the platform and turned into a printable
  Certificate of Destruction;
- runs standalone on any Linux live environment, or as the boot program of the
  tScrub appliance ISO (§2);
- **requires a licence** at every run — even the free tier.

The whole thing is one inspectable script: `product/build/tscrub.sh` is produced
by concatenating `product/src/*.sh`, with two blobs appended (sedutil binary and
the vendor public key). Your security team can read every line of the source in
`product/src/`.

### 1.2 Source layout

| Module | Role |
|---|---|
| `00_bootstrap.sh` | Metadata/globals, `system::gather_info` (DMI, CPU, GPU, RAM), `parse_args`, usage text |
| `10_main.sh` | `fn_main` run orchestration + dry-run ETA simulation |
| `20_ui.sh` | UI helpers and prompts (`cocid::detect`, COCID validation) |
| `30_device.sh` | Device discovery, classification, lock handling, per-drive dispatch |
| `31_device_nvme.sh` | NVMe format / sanitize / crypto erase / PSID revert |
| `32_device_scsi.sh` | SCSI software overwrite via nwipe |
| `33_device_ata.sh` | ATA Secure/Enhanced Erase, frozen unlock, OPAL/TCG via sedutil |
| `34_smart.sh` | SMART capture pre/post (`smart::capture_all`, timeout-guarded `smart::run`) |
| `40_table.sh` | TUI table layout/rendering, `ui::loop` |
| `50_report.sh` | Report CSV/sign/verify, licence detection, output resolution, upload |
| `99_entrypoint.sh` | Entry point: `verify` dispatch, CLI parse, main loop (always last) |

### 1.3 Building the script

`product/scripts/build.sh` concatenates the modules in the order above, then
appends:

```text
SEDUTIL_PAYLOAD_B64="…"          # base64 of payload/sedutil-cli (full builds only)
LICENSE_VENDOR_PUBLIC_KEY_B64="…"# base64 of payload/vendor-public-key.pem
```

Build targets (in `product/`):

```sh
make build          # full build — embeds sedutil-cli; for bare-Linux use
make build-slim     # no sedutil payload; for the appliance (Buildroot ships sedutil)
```

Output: `product/build/tscrub.sh`.

- `make build` requires `keys/vendor-public-key.pem` (committed) and
  `payload/sedutil-cli`.
- `make build-slim` sets `SKIP_SEDUTIL_PAYLOAD=1`, so the script is ~81 KB and
  relies on a `sedutil-cli` on `PATH` (the appliance ISO ships it via Buildroot).

Sanity check a build without touching a drive:

```sh
cd product
make build
./build/tscrub.sh --dry-run
```

### 1.4 Features

#### Device discovery & classification

- Discovers `sd[a-z]+` and `nvme*` block devices; **USB devices are excluded**
  so the boot stick is never a wipe target.
- Each drive is classified into a NIST class — `CLEAR`, `PURGE`, `FROZEN`
  (locked, needs a power-cycle), or `FAILED` — and a terminal status:
  `COMPLETED`, `FAILED`, `FROZEN`, `BLOCKED` (TCG Block SID lockdown), or
  `PHYS_DESTR` (no software-erase path available — destroy physically).
- NVMe status `0x4286` (Access Denied) is detected and reported as `BLOCKED`,
  not a false success.

#### Wipe methods by bus

| Bus | Methods | NIST outcome |
|---|---|---|
| NVMe | `nvme format` (Format), Crypto Erase, Sanitize Block/Crypto/Overwrite Purge, PSID revert | Clear / Purge |
| SATA/ATA | ATA Secure Erase, Enhanced Secure Erase, OPAL/TCG via sedutil, frozen-drive unlock | Clear / Purge |
| SCSI/SAS | software overwrite via nwipe; physical-destruction fallback | Clear / Destroy |

The strongest available method is selected per drive and recorded in the report.

#### SMART capture

`smart::capture_all pre` / `post` reads, per drive: temperature, power-on hours,
power cycles, reallocated sectors, percent used, available spare, TB written —
plus post-wipe values. Reads are wrapped in a timeout (`SMART_TIMEOUT`, default
10 s) so a hung `smartctl` cannot block the run.

#### TUI

- Responsive device table with 5 width tiers (80–186 cols), centred layout,
  live ETA, and red highlighting for temp > 75 °C.
- Colour themes: green (all completed) / red (any drive failed or blocked).
- Prompts: chain-of-custody ID, frozen-drive "continue?", post-run menu.

#### Licensing

Every build embeds the vendor public key and **requires a valid licence**, even
for the free tier. The licence is JSON (`schema: tscrub-license/1`):

```json
{ "customer": "...", "expiry": "YYYY-MM-DD", "tier": "free|payg|team|enterprise",
  "key": "<base64 Ed25519 private key PEM — omitted for free>",
  "signature": "<Ed25519 over customer|expiry|tier|key>" }
```

- **Free** — no report key; reports are **self-signed** with an ephemeral
  appliance-generated Ed25519 key (tamper-evident, not attributable).
- **Paid** — carries a vendor-issued report key; reports and certificates are
  **attributable** to the customer.

Licence resolution order: `--license` / `--license-url` (CLI wins) → kernel
cmdline `tscrub_license=` / `tscrub_license_url=` → boot USB (`license.key` or
`*.lic` at the root of any FAT/ISO9660 volume) → `/etc/tscrub/license.key`.

#### CLI flags

```text
tscrub.sh [--dry-run] [--simulate-running-eta=MINUTES]
          [--license PATH] [--license-url URL] [--output DIR] [--cocid 12345]
tscrub.sh verify <report.csv> [public-key.pem]
```

| Flag | Effect |
|---|---|
| `--dry-run` | Simulate without wiping; report rows are `DRY-RUN` (never signed) |
| `--simulate-running-eta=M` | With `--dry-run`, fake an M-minute wipe |
| `--license PATH` | Licence file (default: boot USB, then `/etc/tscrub/license.key`) |
| `--license-url URL` | Fetch the licence over HTTP(S) |
| `--output DIR` | Write reports to DIR (default: boot USB, then `/`) |
| `--cocid 12345` | Set Chain of Custody ID; runs non-interactively (autonuke) |
| `verify <csv> [pub]` | Verify a signed report (§3) |

Kernel command-line equivalents (appliance / PXE): `tscrub_cocid=`,
`tscrub_license=`, `tscrub_license_url=`, `tscrub_output=`,
`tscrub_upload=`, `tscrub_api_token=`. CLI flags always win over the cmdline.

#### Dependencies

`dmidecode`, `sedutil-cli` (embedded or on PATH), `nvme`, `hdparm`, `lftp`,
`openssl` (3.x, for Ed25519 signing), `smartctl`, `nwipe`, `blockdev`, `curl`
or `wget` (licence fetch / dashboard upload).

---

## 2. Making it bootable

The appliance is our **Buildroot fork of ShredOS**, checked out on the build
host at `oxwet@192.168.0.6:~/shredos.x86_64`. We overlay `tscrub.sh` as the boot
program and sign the boot chain so it works with UEFI Secure Boot enabled.

### 2.1 What is customised

| Piece | Where | What |
|---|---|---|
| Buildroot defconfig | `configs/tscrub_defconfig` | openssl, libcurl + curl, ca-certificates, sedutil, lftp; EFI partition 8 MB; `TSCRUB` volume/FAT labels |
| Rootfs overlay | `board/shredos/fsoverlay/` | `usr/bin/tscrub.sh` (slim build), `usr/bin/tscrub_launcher` (`exec tscrub.sh`), `etc/inittab` (tty1 → `tscrub_launcher`) |
| Boot menus | `board/shredos/` isolinux/grub configs | "tScrub" branding, hostname `tscrub` |

The image is a **hybrid ISO** (`dd` it to USB or boot as a CD), with the kernel
self-contained as `bzImage` (embedded initramfs) so PXE needs no initrd.

### 2.2 Building the ISO

1. **Build the slim script** on the workstation:

   ```sh
   cd product && make build-slim
   ```

2. **Copy it into the overlay and fix the exec bit** (scp drops it — this is the
   most common boot failure):

   ```sh
   scp product/build/tscrub.sh \
     oxwet@192.168.0.6:~/shredos.x86_64/board/shredos/fsoverlay/usr/bin/tscrub.sh
   ssh oxwet@192.168.0.6 \
     'chmod 755 ~/shredos.x86_64/board/shredos/fsoverlay/usr/bin/tscrub.sh \
               ~/shredos.x86_64/output/target/usr/bin/tscrub.sh'
   ```

3. **Restore the prebuilt bootloaders** (a prior `sbsign` run can strip their
   signatures — always reset before a build):

   ```sh
   ssh oxwet@192.168.0.6 \
     'cd ~/shredos.x86_64 && git checkout -- board/shredos/shimx64.efi board/shredos/mmx64.efi board/shredos/grubx64.efi'
   ```

4. **Regenerate `.config`** (editing the defconfig alone does nothing):

   ```sh
   ssh oxwet@192.168.0.6 'cd ~/shredos.x86_64 && make tscrub_defconfig'
   ```

5. **Build under tmux** (a full relink can take 10–15 min; survives disconnects):

   ```sh
   ssh oxwet@192.168.0.6 \
     'tmux new-session -d -s tscrub "cd ~/shredos.x86_64 && : > build_tscrub.log && make 2>&1 | tee -a build_tscrub.log"'
   ```

   The ISO lands in `output/images/` with a versioned name:

   ```text
   tscrub-v1.4.35_2025.11_30_x86-64_v0.41_<YYYYMMDD>-<git-short-hash>.iso
   ```

6. **Publish** (same host serves downloads):

   ```sh
   ISO=<the new iso filename>
   scp oxwet@192.168.0.6:~/shredos.x86_64/output/images/$ISO .
   scp $ISO oxwet@192.168.0.6:~/webs/tscrub/downloads/
   ssh oxwet@192.168.0.6 "cd ~/webs/tscrub/downloads && sha256sum $ISO > $ISO.sha256"
   ```

   Then record the new appliance version/filename/checksum in
   `marketing/server/download-manifest.json` (the `appliance` block) **and** in
   the `FALLBACK` block of `marketing/site/download.html`, publish the manifest to
   `/downloads/`, and redeploy the site:

   ```sh
   cd marketing
   npm run deploy:server
   ssh oxwet@192.168.0.6 'cp ~/webs/tscrub-form/download-manifest.json ~/webs/tscrub/downloads/manifest.json'
   npm run deploy
   ```

### 2.3 Secure Boot (shim + MOK)

The appliance bootloader isn't Microsoft-signed, so plain Secure Boot rejects
it. We use the standard **shim + Machine Owner Key (MOK)** chain:

```text
UEFI firmware (Secure Boot ON)
  └─ shimx64.efi      Microsoft-signed → trusted by firmware
       └─ grubx64.efi  signed with OUR MOK (enrolled by the operator)
            └─ bzImage  signed with OUR MOK
```

Key material (build host, never committed):

- `~/.tscrub-mok/mok.key` — MOK private key (mode 600). Signs GRUB + kernel.
- `~/.tscrub-mok/mok.crt` — MOK certificate (PEM).
- `~/.tscrub-mok/ENROLL_THIS_KEY_IN_MOK_MANAGER.cer` — DER form, shipped **on the
  ISO** for first-boot enrolment.

Generate once:

```sh
mkdir -p ~/.tscrub-mok && cd ~/.tscrub-mok
openssl genrsa -out mok.key 2048
openssl req -x509 -new -nodes -key mok.key -subj "/CN=tScrub Secure Boot Key/" -days 3650 -out mok.crt
openssl x509 -in mok.crt -outform DER -out ENROLL_THIS_KEY_IN_MOK_MANAGER.cer
chmod 600 mok.key
```

This key is **separate** from the licence vendor key (`vendor.key`, Ed25519) and
the PDF signing key (`sign.key`, X.509).

Build-time signing is done by `board/shredos/sign_secureboot.sh`, hooked into
the ISO assembly:

- signs the **kernel** (`bzImage`) **and GRUB** (`grubx64.efi`);
- installs the chain into both the EFI system partition and the ISO9660 tree:
  `shimx64.efi → EFI/BOOT/bootx64.efi`, signed GRUB → `EFI/BOOT/grubx64.efi`,
  plus `mmx64.efi` (MokManager) and `ENROLL_THIS_KEY_IN_MOK_MANAGER.cer`;
- **GRUB must carry an SBAT section** via
  `grub-mkimage --sbat=board/shredos/grub.sbat.csv`. This is mandatory: shim 15.x
  writes a default `SbatLevel` on first boot and refuses to load a next-stage
  binary with no `.sbat` section — the symptom is MokManager reappearing on
  every reboot even after a successful enrolment.

Host build requirements (one-time, root):

```sh
sudo apt-get install -y sbsigntool shim-signed libelf-dev libssl-dev
```

### 2.4 Enrolling the MOK from the same USB

The enrolment certificate is already on the boot USB (the `.cer` is part of the
ISO's EFI system partition), so the operator needs nothing extra:

1. Boot the USB with Secure Boot **on**. Shim reports a verification failure and
   opens **MokManager** (blue screen).
2. Choose **Enroll MOK → Continue → Yes**. Shim 15.x auto-finds
   `ENROLL_THIS_KEY_IN_MOK_MANAGER.cer` on the ESP (there is no "Enroll key from
   disk" step in this version).
3. Reboot. The appliance now boots normally with Secure Boot on — no further
   interaction needed.

> If MokManager reappears after a successful enrolment, the GRUB build is
> missing the SBAT section — re-download the current ISO.

Customers who prefer not to enrol can disable Secure Boot (or use CSM/Legacy) —
the BIOS boot path is unaffected.

### 2.5 Verifying a build

```sh
# kernel is signed
sbverify --list output/images/bzImage
# EFI FAT inside the ISO contains the full chain
7z l output/images/tscrub-*.iso | grep -E "efi.img|bootx64|grubx64|mmx64|ENROLL"
```

Full Secure-Boot boot verification needs a Secure-Boot machine (or QEMU + OVMF
with the MOK enrolled); the build-time checks confirm signatures, not the
firmware handshake.

### 2.6 Booting

- **USB:** `dd if=<downloaded-iso> of=/dev/sdX bs=4M status=progress`.
- **PXE:** the `bzImage` is self-contained, so iPXE needs no initrd:

  ```text
  kernel http://host/bzImage console=tty3 loglevel=3 tscrub_license_url=http://host/license.key
  boot
  ```

  (The kernel has no serial console; the TUI runs on `tty3`.)

### 2.7 Appliance gotchas

- **Exec bit:** after any scp/replace, verify
  `ls -l output/target/usr/bin/tscrub.sh` shows `+x`.
- **Prebuilt corruption:** `sbsign` (and old in-place signing experiments) can
  strip the signature from `board/shredos/{shimx64,mmx64,grubx64}.efi` — always
  `git checkout --` them before a build.
- **SBAT:** missing `.sbat` on GRUB = endless MokManager loop (see §2.3).
- **`.config`:** editing `configs/tscrub_defconfig` does nothing until
  `make tscrub_defconfig`.
- **x64 only:** no 32-bit shim on this host, so 32-bit UEFI machines still need
  Secure Boot off.
- **Slimming:** if a future `grub` "out of memory" error appears, the kernel grew
  too large (the base ShredOS defconfig bundles a desktop stack a console tool
  doesn't need). Deselect the bloat in the defconfig and manually `rm -rf` the
  already-installed files from `output/target` before `make` — do **not** use
  `make clean` (it wipes `output/host`, forcing a cold rebuild).

---

## 3. Generating reports

### 3.1 What a run produces

After the wipe, `report::csv` + `report::sign` write three files per job:

```text
tScrub_<COCID>_20260922T103000Z.csv    # chain of custody, one row per drive
tScrub_<COCID>_20260922T103000Z.sig    # base64 Ed25519 signature over the CSV
tScrub_<COCID>_20260922T103000Z.json   # manifest (schema: tscrub-report/1)
```

### 3.2 The CSV (29 columns)

```text
COCID,Timestamp,Model,Serial,Size,Bus,Type,Device,Class,Certification,Method,
FinalStatus,SMART,TempC,PowerOnHours,PowerCycles,ReallocSectors,PctUsed,
AvailSpare,TBW_TB,SMARTPOST,TempCPost,PowerOnHoursPost,System,SystemSerial,
BaseboardSerial,CPU,GPU,RAM
```

- `Class` = NIST category (`CLEAR` / `PURGE` / `FROZEN` / `FAILED`).
- `Method` = concrete wipe method (e.g. `NVMe Crypto Purge`, `ATA Secure Erase`,
  `SCSI Software Overwrite`).
- `FinalStatus` = `COMPLETED`, `FAILED`, `FROZEN`, `BLOCKED`, or `PHYS_DESTR`.
- `SMART`/`TempC`/…/`TBW_TB` = pre-wipe assessment; `SMARTPOST`/`TempCPost`/
  `PowerOnHoursPost` = post-wipe.
- `System`/`SystemSerial`/`BaseboardSerial`/`CPU`/`GPU`/`RAM` = the host machine
  profile, repeated on every row (added v1.4.24) so each drive is attributable
  to the machine that wiped it.

Values are RFC-4180 quoted; the server parses columns by header name, so legacy
CSVs (fewer columns) still work.

### 3.3 The manifest

```json
{
  "schema": "tscrub-report/1",
  "cocid": "12345",
  "created": "2026-09-22T10:30:00Z",
  "report": "tScrub_12345_20260922T103000Z.csv",
  "sha256": "4614d837…",
  "signed": true,
  "public_key": "<base64 DER SPKI of the report signing key>",
  "drives": [
    { "device": "nvme0n1", "status": "COMPLETED", "method": "NVMe Crypto Purge", "cert": "PURGE" }
  ]
}
```

`public_key` is present only when the report is signed. The server compares it
against the licence's report key to decide attribution (§3.6).

### 3.4 Signing

`report::sign` computes the CSV SHA-256, signs with Ed25519
(`openssl pkeyutl -rawin`), and writes the base64 `.sig` + the manifest.

Signing-key resolution:

1. `$REPORT_KEY` (explicit file) — set by a paid licence;
2. `$REPORT_KEY_DIR/report.key` (default `/etc/tscrub/report.key`);
3. otherwise an **ephemeral** Ed25519 key is generated in `/tmp` so free reports
   are still self-signed.

When openssl (or a key) is unavailable, the manifest still records the checksum
(integrity-only; no `.sig`).

### 3.5 Verifying a report

```sh
./build/tscrub.sh verify report.csv
# SHA-256: 4614d837…
# Manifest: OK
# Signature: VALID
```

With an explicit public key: `tscrub verify report.csv public-key.pem`.
Failure signals: `MISMATCH` (CSV altered) or `INVALID` (signature bad).

Manual check without the binary:

```sh
openssl dgst -sha256 report.csv                       # compare to manifest.sha256
openssl pkeyutl -verify -pubin -inkey pub.pem -rawin \
  -in report.csv -sigfile <(openssl base64 -d -in report.sig)
```

### 3.6 Output & upload

Report destination is resolved by `report::detect_output`:

1. explicit `--output DIR` / `tscrub_output=<path>`;
2. the licence volume — the same partition the `.lic` was found on;
3. else a FAT/exFAT scan (ignores the removable flag; prefers the partition
   carrying `boot/version.txt`, then the first writable FAT volume);
4. else `/` (RAM, with a warning).

Upload is dispatched by `report::upload` (priority order):

1. **Dashboard push** (preferred): `tscrub_upload=https://tscrub.com/api/reports`
   + `tscrub_api_token=<64-hex>` → curl multipart `reports[]=@…` with
   `X-Api-Token` header.
2. **FTP / SFTP**: `tscrub_output=ftp:host:path:user:pass` or
   `tscrub_output=sftp:host:path:user:pass` → one `lftp` session uploads the CSV,
   manifest and `.sig`.

### 3.7 Turning a report into a Certificate of Destruction

Reports are uploaded via `POST /api/reports` (SHA-256 + Ed25519 verified before
storage); certificates are generated on demand from stored reports via
`POST /api/certs` (login + CSRF).

| Path | Who | Inputs |
|---|---|---|
| `POST /api/reports` | Appliance (API token) or dashboard (login + CSRF) | multipart `reports[]=@…` (`.csv` + `.json` + optional `.csv.sig`) |
| `POST /api/certs` | Web user (login + CSRF) | JSON `{ cocid, destroyed? }` |

Server-side checks (`reports_lib.php`):

1. CSV SHA-256 matches the manifest.
2. Ed25519 signature verifies against the manifest's `public_key`.
3. **Attribution:** that key must equal the licence's report key
   (`licences.pub_key`) → `attributed`; a valid-but-unmatched signature is
   `valid` ("unattributed"). Forged reports (self-generated keys) verify their
   own signature but are **not** attributed.

Rendering (`render_cert.php`, TCPDF A4 landscape):

- page 1: summary (CoC ID, dates, devices/methods/runs, integrity + signature
  badges), then **Annex A** drive table (device, type, method, model, serial,
  final status, wiped) with page breaks and repeated header;
- report manifest section listing each file + its SHA-256 + state;
- a **verification QR** encoding `https://tscrub.com/verify?cert=<certId>`;
- for paid licences, the PDF is **digitally signed** (X.509, `sign.crt` /
  `sign.key`) — free-tier certificates are unsigned, but every certificate
  (free or paid) carries the verification QR.

Each certificate is stored as `certs/<certId>.pdf` plus a row in MySQL, and is
publicly checked at:

```text
https://tscrub.com/verify?cert=COD-XXXXXXXX-XX-XXX-XXXX-XXXX
```

which shows "Certificate Verified" only when the licence is paid, the document
hash matches, and the report is attributed.

---

## Key material reference (paths only — never in this repo)

| Key | Location (server) | Purpose |
|---|---|---|
| Vendor licence key | `~/webs/tscrub-form/vendor.key` (+ `vendor-public-key.pem`) | Signs licence envelopes |
| MOK key | `~/.tscrub-mok/mok.{key,crt}` | Signs appliance GRUB + kernel |
| PDF signing key | `~/webs/tscrub-form/sign.{crt,key}` | X.509-signs paid certificate PDFs |
| DB / SMTP creds | `~/webs/tscrub-form/config.json` | Backend secrets |

---

## Related docs

- `ops/deploy.md` — website + backend deployment.
- `ops/secure-boot-mok.md` — Secure Boot runbook (this guide's §2 source).
- `ops/report-verification.md` — report verification + attribution (this guide's §3 source).
- `ops/licence-request-workflow.md`, `ops/vendor-key-rotation.md` — licence lifecycle.
- `README.md` — overview + quick start.
- `product/tests/README.md` — the bash test suite (`/opt/homebrew/bin/bash tests/run.sh`).