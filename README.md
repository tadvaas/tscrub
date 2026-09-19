# tScrub

Open, auditable disk sanitisation for regulated IT. tScrub is a single,
self-contained Bash script that erases NVMe, SATA/ATA, and SCSI drives to
NIST 800-88 Clear, Purge, and Destroy levels, captures pre/post-wipe SMART data,
and writes a verifiable chain-of-custody report — which you can turn into a
printable Certificate of Destruction on the tScrub platform.

- **Inspectable** — one script; your security team can read every line.
- **Appliance-agnostic** — boot any Linux live environment (ShredOS or your own)
  from USB or PXE.
- **Licence required at boot** — even the free tier. Free licences produce
  unsigned (checksum-only) reports; paid licences add a vendor-issued report key
  so reports and certificates are digitally signed (plus a QR code).

Website: <https://tscrub.com> · Docs: <https://tscrub.com/docs>

## Repository layout

| Path | What it is |
|---|---|
| `product/` | The tScrub appliance script (`src/*.sh`), builder (`scripts/`, `Makefile`), and the bash test suite (`tests/`). |
| `marketing/` | The website — Vite + Tailwind static site, plus the PHP/MySQL backend and dashboard (`marketing/server/`). |
| `ops/` | Deployment and operations runbooks (see `ops/deploy.md`). |
| `test-fixtures/` | Sample signed/unsigned reports for exercising the certificate backend. |
| `ROADMAP.md` | Current state and future plans. |
| `CHANGELOG.md` | Release history (artifact SHA-256s + signing keys live in the docs page). |

## Quick start — build the appliance script

```sh
cd product
make build          # assembles build/tscrub.sh, embeds the vendor public key
./build/tscrub.sh --dry-run
```

`make build` requires `keys/vendor-public-key.pem` (committed). To bake a
customer licence into the image so nothing is supplied at boot:

```sh
make build-customer CUSTOMER="Acme ITAD Ltd" LIC=/path/to/Acme.lic
```

### Get a licence + the script

1. Sign up at <https://tscrub.com/register> (personal or company).
2. On the Download page pick a plan and issue your licence (`.lic`).
3. Download `tscrub.sh` from the dashboard's Licence page.

Place the `.lic` next to `tscrub.sh`, or point to it with `--license`,
`--license-url`, or the `tscrub_license=` / `tscrub_license_url=` kernel params.

## Appliance output & upload

At the end of a run the report (`.csv` + `.sig` + `.json` manifest) is written:

1. to the boot USB automatically (first writable removable FAT32/vfat partition),
   or an explicit `--output DIR` / `tscrub_output=` path;
2. then optionally pushed to your dashboard:

   ```
   tscrub_upload=https://tscrub.com/api/reports
   tscrub_api_token=YOUR-64-HEX-TOKEN
   ```

3. or uploaded via FTP:

   ```
   shredos_output=ftp:host:path:user:password
   ```

Full details: <https://tscrub.com/docs> (§5 "The report").

## Verify a report

```sh
./build/tscrub.sh verify report.csv [public-key.pem]
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

## Next steps

See [`ROADMAP.md`](ROADMAP.md) for the plan. Billing (Stripe) is intentionally
parked; database backups are handled by Proxmox Backup Server.
