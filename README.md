# tScrub

Open, auditable disk sanitisation for regulated IT. tScrub ships as a
ready-to-boot appliance image (ISO or bzImage) that erases NVMe, SATA/ATA, and
SCSI drives to NIST 800-88 Clear, Purge, and Destroy levels, captures
pre/post-wipe SMART data, and writes a verifiable chain-of-custody report —
which you can turn into a printable Certificate of Destruction on the tScrub
platform.

- **Inspectable** — the source is open; your security team can read every line.
- **Appliance-first** — boot our ready-to-boot appliance ISO from USB or PXE on
  hardware you already own (download it from the Download page).
- **Licence required at boot** — even the free tier. Free licences self-sign
  their reports (tamper-evident, not attributable); paid licences add a
  vendor-issued report key so reports and certificates are digitally signed.
  Every certificate carries a verification QR code.

Website: <https://tscrub.com> · Docs: <https://tscrub.com/docs>

## Repository layout

| Path | What it is |
|---|---|
| `product/` | The tScrub appliance script (`src/*.sh`), builder (`scripts/`, `Makefile`), and the bash test suite (`tests/`). |
| `marketing/` | The website — Vite + Tailwind static site, plus the PHP/MySQL backend and dashboard (`marketing/server/`). |
| `ops/` | Deployment and operations runbooks (see `ops/deploy.md`). |
| `test-fixtures/` | Sample signed/unsigned reports for exercising the certificate backend. |
| `ROADMAP.md` | Current state and future plans. |
| `CHANGELOG.md` | Release history (artifact SHA-256s). |

## Quick start — build the appliance image

```sh
cd product
make build-slim     # assembles build/tscrub.sh for the appliance image
./build/tscrub.sh --dry-run   # smoke-test the build
```

`make build` requires `keys/vendor-public-key.pem` (committed) and embeds the
sedutil payload; `make build-slim` builds the script without it for the
appliance image, which ships `sedutil-cli` via Buildroot.

### Get a licence + the appliance

1. Sign up at <https://tscrub.com/register> (personal or company).
2. Sign in and issue your licence (`.lic`) from the dashboard **Licences** page
   (`/dashboard/licences`).
3. Download the bootable appliance ISO from the **Download** page.

Put the `.lic` on the boot USB, or supply it via `tscrub_license=` /
`tscrub_license_url=` on the kernel command line.

## Appliance output & upload

At the end of a run the report (`.csv` + `.sig` + `.json` manifest) is written:

1. to the boot USB automatically (first writable removable FAT32/vfat partition),
   or an explicit `--output DIR` / `tscrub_output=` path;
2. then optionally pushed to your dashboard:

   ```
   tscrub_upload=https://tscrub.com/api/reports
   tscrub_api_token=YOUR-64-HEX-TOKEN
   ```

3. or uploaded via FTP or SFTP:

   ```
   tscrub_output=ftp:host:path:user:password
   tscrub_output=sftp:host:path:user:password
   ```

Full details: <https://tscrub.com/docs> (§5 "The report").

## Verify a report

Reports are verified automatically when uploaded to the dashboard, and
certificates verify online via their QR code. On the appliance console:

```sh
tscrub verify report.csv [public-key.pem]
# SHA-256: ...
# Manifest: OK
# Signature: VALID
```

## Platform (website + backend)

The website is a static Vite build backed by a PHP/MySQL backend (accounts,
licences, certificates, SMART reports, admin).

```sh
cd marketing
npm install
npm run dev            # local dev server

npm run build          # static site -> dist/
npm run deploy         # build + rsync the site
npm run deploy:server  # rsync the PHP backend (marketing/server/)
```

Full setup and deployment instructions (nginx, MySQL schema, migrations, key
handling): [`ops/deploy.md`](ops/deploy.md).

## Tests

The appliance logic is tested without touching real drives, using faked
commands and a fake block-device tree.

```sh
cd product
/opt/homebrew/bin/bash tests/run.sh
```

Requires bash 4+ (`brew install bash` on macOS). See `product/tests/README.md`.

## Compliance

tScrub maps each wipe method to a NIST 800-88 Clear, Purge, or Destroy outcome,
and supports GDPR / HIPAA / ISO 27001 reporting programmes. tScrub is a tool —
it is not itself a certification. Validate final requirements with your
compliance team. See <https://tscrub.com/compliance>.

## License

SPDX-License-Identifier: `GPL-3.0-or-later`

tScrub is free software: you can redistribute it and/or modify it under the
terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT
ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program (see `LICENSE`). If not, see <https://www.gnu.org/licenses/>.

The appliance image additionally bundles third-party components under their own
licences (e.g. Buildroot GPL-2.0-or-later, sedutil GPL-3.0-or-later, nwipe
GPL-2.0). They are distributed as separate programs; `tscrub.sh` itself remains
GPL-3.0-or-later.

## Next steps

See [`ROADMAP.md`](ROADMAP.md) for the plan. Billing (Stripe) is intentionally
parked; database backups are handled by Proxmox Backup Server.
