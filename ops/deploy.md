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
- Static SEO/LLM files (`robots.txt`, `sitemap.xml`, `llms*.txt`, `favicon.svg`) live in `marketing/site/public/` and ship automatically.
- Auth pages (`login`, `register`, `dashboard`, `admin`) ship automatically and are `noindex` (disallowed in `robots.txt`).

## 2. Product (`product/`)

Builds the self-contained script and uploads it via scp.

```bash
cd product
make build            # free build (embeds vendor key; requires a licence)
make deploy           # build (free) + upload
make deploy-check     # build + local preflight only (no upload)
```

What it does:
- `scripts/build.sh` assembles `build/tscrub.sh` (concatenates `src/*.sh`, embeds `payload/sedutil-cli`).
- The vendor public key (`keys/vendor-public-key.pem`) is embedded by **every** build, enabling licence verification and vendor-signed reports. tScrub always requires a licence — even the free tier.
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

**Versioning & release history:** bump `SCRIPT_VERSION` in `product/src/00_bootstrap.sh` whenever the source changes before releasing. Don't bump for a byte-identical re-host — the SHA-256 won't change. `host-download.sh` reads the version and warns if it's already in the release history. After releasing a new version, add a row to the "Release & key history" table in `marketing/site/docs.html` (version + SHA-256 + signing-key fingerprint).

Manual equivalent (upload only, no site redeploy):

```bash
cd product
make build
scp -o BatchMode=yes build/tscrub.sh oxwet@192.168.0.6:~/webs/tscrub/downloads/tscrub.sh
ssh -o BatchMode=yes oxwet@192.168.0.6 'cd ~/webs/tscrub/downloads && sha256sum tscrub.sh > tscrub.sh.sha256'
```

The marketing deploy excludes `downloads/` (`--exclude downloads/`) so `--delete` never wipes it. `download_url` in `~/webs/tscrub-form/config.json` points at this file (used in licence emails).

### Appliance ISO + PXE kernel (`bzImage`)

The appliance is a Buildroot/ShredOS fork on `oxwet@192.168.0.6:~/shredos.x86_64`. After a `SCRIPT_VERSION` bump and `bash ops/host-download.sh`, stage the slim script into the overlay, commit, and rebuild:

```bash
# 1. Push the slim build into the overlay and commit on the build host
scp -o BatchMode=yes product/build/tscrub.sh \
  oxwet@192.168.0.6:~/shredos.x86_64/board/shredos/fsoverlay/usr/bin/tscrub.sh
ssh -o BatchMode=yes oxwet@192.168.0.6 'cd ~/shredos.x86_64 && \
  chmod 755 board/shredos/fsoverlay/usr/bin/tscrub.sh && \
  git checkout -- board/shredos/bootx64.efi board/shredos/shimx64.efi board/shredos/mmx64.efi board/shredos/grubx64.efi && \
  git add -A && git commit -m "tscrub vX.Y.Z"'

# 2. Rebuild under tmux (survives SSH drop); ISO + bzImage land in output/images/
ssh -o BatchMode=yes oxwet@192.168.0.6 'cd ~/shredos.x86_64 && \
  rm -f build_tscrub.log && \
  tmux new-session -d -s tscrub "make 2>&1 | tee -a build_tscrub.log; echo TSCRUB_BUILD_DONE >> build_tscrub.log"'
# poll: ssh oxwet@192.168.0.6 'grep -q TSCRUB_BUILD_DONE ~/shredos.x86_64/build_tscrub.log'
```

The build produces two artifacts in `~/shredos.x86_64/output/images/`:
- `tscrub-v<VER>_…_<hash>.iso` — the bootable appliance (copy to `~/webs/tscrub/downloads/`, repoint `tscrub-appliance.iso`, update the manifest + download page).
- `bzImage` — the self-contained kernel (embedded initramfs) used for **PXE** boots; it has no separate `initrd`.

> **The build-output `bzImage` is UNSIGNED.** The ISO's `/boot/bzImage` is the
> MOK-signed copy (for the ISO's own shim→grub chain). For a **network** boot
> that uses `shim` + Secure Boot (the iPXE `shim` command verifies the kernel),
> the served `bzImage` must be signed with the operator's own enrolled key —
> the same "My iPXE Vendor Key" that signs ShredOS's kernel.

**Publish the PXE kernel** to the PXE host (`oxwet@192.168.0.26`, served at
`/tScrub/boot/bzImage`). The Mac can SSH to both hosts; the build host has no
direct key to `.26`, so relay through the Mac, then sign it on `.26` with the
operator's iPXE vendor key:

```bash
scp -o BatchMode=yes oxwet@192.168.0.6:~/shredos.x86_64/output/images/bzImage /tmp/bzImage
ssh -o BatchMode=yes oxwet@192.168.0.26 'mkdir -p ~/html/tScrub/boot'
scp -o BatchMode=yes /tmp/bzImage oxwet@192.168.0.26:~/html/tScrub/boot/bzImage
rm -f /tmp/bzImage

# Sign it on the PXE host with the enrolled key (else shim rejects it under
# Secure Boot: "Failed to load image: Security Policy Violation").
ssh -o BatchMode=yes oxwet@192.168.0.26 'cd ~/html/tScrub/boot && \
  sbsign --key /home/oxwet/ipxe-sb/vendor.key --cert /home/oxwet/ipxe-sb/vendor.crt --output bzImage.signed bzImage && \
  mv -f bzImage.signed bzImage && sbverify --list bzImage'
```

Verify the signature shows the enrolled issuer (`/CN=My iPXE Vendor Key`), then
the iPXE `kernel` line fetches it directly — no `initrd` line is needed:

```
kernel ${base-url}/tScrub/boot/bzImage console=tty3 loglevel=3
```

## 3. Backend + database (`~/webs/tscrub-form/` on the server)

Server-side PHP + Python handlers (auth, forms, certificate issuing/verification, licence issuing) backed by **MySQL**.

Files live locally in `marketing/server/` and deploy with:

```bash
cd marketing
npm run deploy:server
```

What it copies (no `--delete`): `api.php auth.php db.php http.php mail.php reports_lib.php stripe.php migrate.php seed-admin.php schema.sql certify.php submit.php verify.php sendmail.py issue_licence.py config.example.json`.

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
- Tables: `users`, `sessions`, `certificates`, `certificate_reports`, `certificate_drives`, `licences`, `api_tokens`, `tokens`, `admin_audit_log`, `credit_events`, `subscriptions`, `stripe_events`.
- `licences.pub_key` holds the base64 DER (SPKI) public half of each paid licence's report key, derived at issuance, so uploaded reports can be bound back to the licence (attribution). Free licences leave it empty. On an existing server, add it and backfill with:

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && mysql --defaults-extra-file=/tmp/.my.cnf -e "ALTER TABLE licences ADD COLUMN pub_key VARCHAR(255) NOT NULL DEFAULT \"\" AFTER licence_json;"'
# then backfill each paid licence's pub_key from its licence_json.key
```
- `/verify` reads MySQL only. The `certificates/` JSON dir is kept on disk for the record but is no longer the source of truth.
- Backups are handled by Proxmox Backup Server (hypervisor-level, covers MySQL) — no separate `mysqldump` cron needed.

### Stripe payments (device credits)

Prepaid per-device billing: one credit = one device wiped. `stripe.php` talks to
Stripe with raw cURL (no Composer). Keys + price IDs live in `config.json` under
a `stripe` block (see `config.example.json` for shape) — never committed.

One-time setup:

1. In the Stripe dashboard create three one-time Prices (Products "Device credit
   pack 10/50/100") and a webhook endpoint `https://tscrub.com/api/stripe/webhook`
   subscribed to `checkout.session.completed` (and later subscription events).
2. Add the `stripe` block to `~/webs/tscrub-form/config.json`:
   `secret_key`, `webhook_secret`, and `prices.pack10/pack50/pack100`
   (`price_id` + `units`).
3. Apply the new tables (idempotent):

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && mysql --defaults-extra-file=/tmp/.my.cnf -e "
  CREATE TABLE IF NOT EXISTS credit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    type ENUM(\"credit\",\"debit\") NOT NULL,
    units INT UNSIGNED NOT NULL,
    ref VARCHAR(255) NOT NULL DEFAULT \"\",
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_credit_ref (user_id, ref),
    KEY idx_credit_user (user_id, created_at),
    CONSTRAINT fk_credit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  CREATE TABLE IF NOT EXISTS subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    stripe_customer_id VARCHAR(255) NOT NULL DEFAULT \"\",
    stripe_subscription_id VARCHAR(255) NOT NULL DEFAULT \"\",
    price_id VARCHAR(255) NOT NULL DEFAULT \"\",
    status VARCHAR(32) NOT NULL DEFAULT \"\",
    current_period_end DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_subs_user (user_id),
    KEY idx_subs_stripe (stripe_subscription_id),
    CONSTRAINT fk_subs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  CREATE TABLE IF NOT EXISTS stripe_events (
    id VARCHAR(255) NOT NULL,
    type VARCHAR(64) NOT NULL DEFAULT \"\",
    handled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"'
```

Flow: dashboard "Top up" → `POST /api/checkout` → Stripe hosted Checkout →
`POST /api/stripe/webhook` (signature-verified, idempotent) credits the wallet and
issues a `payg` licence on first purchase. Report uploads debit 1 credit per newly
ungested drive (idempotent by CSV SHA) — a shortfall is reported in the JSON
response, never a block. Test with Stripe test keys + `stripe listen`; the
webhook endpoint returns 200 to duplicate deliveries.

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
