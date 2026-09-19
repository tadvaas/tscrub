# Deploy Runbook

There are three things that get deployed, each with its own path.

## 1. Marketing site (`marketing/`)

Static Vite build, rsync'd to the web docroot.

```bash
cd marketing
npm run deploy
```

What it does (`marketing/deploy.sh`):
- `npm run build` (outputs to `marketing/dist/`)
- `rsync -avz --delete dist/` → `oxwet@192.168.0.6:~/webs/tscrub/`

Overrides (if the target ever changes):

```bash
TSCRUB_WEB_HOST=user@host TSCRUB_WEB_DEST=/path/to/docroot npm run deploy
```

Notes:
- `--delete` removes anything in the docroot that isn't in `dist/`. The form backend lives **outside** the docroot (`~/webs/tscrub-form/`), so it's unaffected.
- Static SEO/LLM files (`robots.txt`, `sitemap.xml`, `llms*.txt`, `favicon.svg`) live in `marketing/public/` and ship automatically.
- Auth pages (`login`, `register`, `dashboard`, `admin`) ship automatically and are `noindex` (disallowed in `robots.txt`).

## 2. Product (`product/`)

Builds the self-contained script and uploads it via scp.

```bash
cd product
make build            # free build (embeds vendor key; requires a licence)
make build-enterprise # Team/Enterprise build (same; kept for clarity)
make build-customer   # customer build (embeds vendor key + a specific licence)
make deploy           # build (free) + upload
make deploy-check     # build + local preflight only (no upload)
```

What it does:
- `scripts/build.sh` assembles `build/tscrub.sh` (concatenates `src/*.sh`, embeds `payload/sedutil-cli`).
- The vendor public key (`keys/vendor-public-key.pem`) is embedded by **every** build, enabling licence verification and vendor-signed reports. tScrub always requires a licence — even the free tier. `make build-customer CUSTOMER="..." LIC=/path/to/Acme.lic` also bakes that licence into the image so the customer does not need to supply one at boot.
- `scripts/deploy.sh` reads `product/.config` for `TSCRUB_DEPLOY_HOST`, `TSCRUB_DEPLOY_USER`, `TSCRUB_DEPLOY_DOCROOT`, `TSCRUB_DOMAIN`, then scp-uploads `build/*` and curl-checks the public URLs.

Notes:
- `.config` holds deployment host/user/docroot/domain (not committed; treat as env-specific).
- Run tests before deploying: `cd product && /opt/homebrew/bin/bash tests/run.sh`.

### Host the download artifact (free build)

The free build is served at `https://tscrub.com/downloads/tscrub.sh`. One command rebuilds, re-hosts, **signs the release** (Ed25519, with the vendor key), updates the published checksum, and redeploys the site:

```bash
bash ops/host-download.sh
```

Served files under `/downloads/`: `tscrub.sh` (free build), `tscrub.sh.sha256` (checksum), `tscrub.sh.sig` (Ed25519 signature), `tscrub.pub` (vendor public key for verification).

**Versioning & release history:** bump `SCRIPT_VERSION` in `product/src/00_bootstrap.sh` whenever the source changes before releasing. Don't bump for a byte-identical re-host — the SHA-256 won't change. `host-download.sh` reads the version and warns if it's already in the release history. After releasing a new version, add a row to the "Release & key history" table in `marketing/docs.html` (version + SHA-256 + signing-key fingerprint).

Manual equivalent (upload only, no site redeploy):

```bash
cd product
make build
scp -o BatchMode=yes build/tscrub.sh oxwet@192.168.0.6:~/webs/tscrub/downloads/tscrub.sh
ssh -o BatchMode=yes oxwet@192.168.0.6 'cd ~/webs/tscrub/downloads && sha256sum tscrub.sh > tscrub.sh.sha256'
```

The marketing deploy excludes `downloads/` (`--exclude downloads/`) so `--delete` never wipes it. `download_url` in `~/webs/tscrub-form/config.json` points at this file (used in licence emails).

## 3. Backend + database (`~/webs/tscrub-form/` on the server)

Server-side PHP + Python handlers (auth, forms, certificate issuing/verification, licence issuing) backed by **MySQL**.

Files live locally in `marketing/server/` and deploy with:

```bash
cd marketing
npm run deploy:server
```

What it copies (no `--delete`): `api.php auth.php db.php http.php mail.php reports_lib.php migrate.php seed-admin.php schema.sql certify.php submit.php verify.php sendmail.py issue_licence.py config.example.json`.

### One-time DB setup (already done on this server)

```bash
# 1. Add the db + base_url keys to the live config (keep the SMTP values)
ssh oxwet@192.168.0.6
cd ~/webs/tscrub-form
cp config.json config.json.pre-db.bak
jq '.db = {"host":"127.0.0.1","port":3306,"name":"tScrub","user":"tScrub","password":"<DB_PASSWORD>"} | .base_url = "https://tscrub.com"' config.json > config.json.new
mv config.json.new config.json && chmod 640 config.json
exit

# 2. Create the tables
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && \
  printf "[client]\nhost=127.0.0.1\nuser=tScrub\npassword=<DB_PASSWORD>\ndatabase=tScrub\n" > /tmp/.my.cnf && \
  chmod 600 /tmp/.my.cnf && mysql --defaults-extra-file=/tmp/.my.cnf < schema.sql && rm -f /tmp/.my.cnf'

# 3. Import the legacy JSON registry (idempotent; safe to re-run)
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && php migrate.php'

# 4. Create the first admin
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && php seed-admin.php <admin-email> <password>'
```

### Database facts

- Host `127.0.0.1:3306`, database `tScrub`, user `tScrub`. Credentials live only in `config.json` (mode 640, group www-data); `config.example.json` is the template. Never commit the real password.
- Tables: `users`, `sessions`, `certificates`, `certificate_reports`, `licences`, `tokens`, `admin_audit_log`.
- `/verify` reads MySQL only. The `certificates/` JSON dir is kept on disk for the record but is no longer the source of truth.
- Back up the DB (cron `mysqldump`) — the certificate registry no longer lives in files.

What NOT to overwrite:
- `config.json` — live SMTP + DB credentials. Only edit on the server.
- `vendor.key` — the vendor private key (640, group www-data — php-fpm signs licences). Never copy it off the server.
- `tcpdf/`, `cert-bg.png`, `sign.crt`, `sign.key` — runtime assets for PDF generation.
- `certs/` — persisted certificate PDFs (writable by www-data). `deploy-server.sh` has no `--delete`, so it's never wiped, but back it up alongside the DB (`certificates.pdf_path` points into it).

Permissions on the server (set once):

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && chmod 640 config.json && chmod 644 *.php && chmod 755 sendmail.py issue_licence.py && chmod 770 rl && chmod 640 vendor.key sign.key'
```

## 4. nginx changes (needs root — you run these)

The nginx config lives at `/etc/nginx/sites-available/tscrub.conf` (root-owned; `oxwet` has no sudo). Reference snippets are in `marketing/server/nginx-location.conf`.

Current PHP routes:
- `location = /api/certify` → `certify.php` (certificate upload — login required)
- `location = /verify` → `verify.php` (public, MySQL lookup)
- `location = /submit` → `submit.php` (contact form)
- `location /api/` → `api.php` (auth, dashboard, admin, licences)

To add/change a route, paste the matching block from `nginx-location.conf` into the `server { server_name tscrub.com; … }` block, then:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## Post-deploy verification

```bash
# marketing
curl -s -o /dev/null -w "%{http_code}\n" https://tscrub.com/            # 200
curl -s -o /dev/null -w "%{http_code}\n" https://tscrub.com/login        # 200
curl -s -o /dev/null -w "%{http_code}\n" https://tscrub.com/robots.txt  # 200

# api (JSON; /api/me returns a user object or null)
curl -s -o /dev/null -w "%{http_code}\n" https://tscrub.com/api/me       # 200

# certificate verification (public, DB-backed)
curl -s -o /dev/null -w "%{http_code}\n" "https://tscrub.com/verify?cert=COD-003838-IK-FEW-3120-SDUC"  # 200

# form endpoint (GET is rejected, POST works)
curl -s -o /dev/null -w "%{http_code}\n" https://tscrub.com/submit      # 405

# product build
cd product && grep -c 'LICENSE_VENDOR_PUBLIC_KEY_B64' build/tscrub.sh
```
