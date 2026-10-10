# Deploy Runbook

There are three things that get deployed, each with its own path.

## Signing keys (overview)

There are several distinct signing operations, each with its own key. The deploy
steps below touch only the first two; the rest live in dedicated runbooks.

| Operation | Key | Where |
|---|---|---|
| Release signing — `tscrub.sh` → `tscrub.sh.sig` (Ed25519) + `tscrub.pub` | `vendor.key` (Ed25519) | §2 + `host-download.sh` |
| PXE kernel signing — network-boot `bzImage` (Secure Boot) | `~/ipxe-sb/vendor.key` ("My iPXE Vendor Key") | §2 (Appliance) |
| Secure Boot (shim + MOK) — ISO's `shim → grub → bzImage` chain | `~/ipxe-sb/vendor.key` ("My iPXE Vendor Key", shared with PXE) | `secure-boot-mok.md` |
| Report signing — chain-of-custody CSV → `.sig` (Ed25519) | report key (free = self-signed ephemeral; paid = licence-bound) | `report-verification.md` |
| Licence + vendor root-of-trust | `vendor.key` (Ed25519) | `vendor-key-rotation.md` |
| Certificate / diagnostics PDF signing (X.509) | `sign.key` / `sign.crt` | `certificate-pdf-signing.md` |

The Secure Boot MOK key and the PXE kernel key are now the **same key**
(`~/ipxe-sb/vendor.key`, `CN=My iPXE Vendor Key`): it signs both the ISO's shim
chain and the network-boot kernel, so a machine enrols one MOK for both boot
paths. The old dedicated `~/.tscrub-mok/mok.key` (`CN=tScrub Secure Boot Key`) is
retired.

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
make build            # script build (embeds vendor key; requires a licence)
make deploy           # build + upload
make deploy-check     # build + local preflight only (no upload)
```

What it does:
- `scripts/build.sh` assembles `build/tscrub.sh` (concatenates `src/*.sh`, embeds `payload/sedutil-cli`).
- The vendor public key (`keys/vendor-public-key.pem`) is embedded by **every** build, enabling licence verification and vendor-signed reports. tScrub always requires a licence — even the free tier.
- `scripts/deploy.sh` reads `product/.config` for `TSCRUB_DEPLOY_HOST`, `TSCRUB_DEPLOY_USER`, `TSCRUB_DEPLOY_DOCROOT`, `TSCRUB_DOMAIN`, then scp-uploads `build/*` and curl-checks the public URLs.

Notes:
- `.config` holds deployment host/user/docroot/domain (not committed; treat as env-specific).
- Run tests before deploying: `cd product && /opt/homebrew/bin/bash tests/run.sh`.

### Host the signed script artifact

The signed script is still built and served at `https://tscrub.com/downloads/tscrub.sh` — it backs the `verify` toolchain and the appliance build, but it is **not** surfaced on the Download page (tScrub ships as the appliance ISO). One command rebuilds, re-hosts, **signs the release** (Ed25519, with the vendor key), updates the published checksum in the manifest's `script` block, and deploys the backend (which refreshes the manifest under `/downloads/`):

```bash
bash ops/host-download.sh
```

Served files under `/downloads/`: `tscrub.sh` (the script), `tscrub.sh.sha256` (checksum), `tscrub.sh.sig` (Ed25519 signature), `tscrub.pub` (vendor public key for verification).

**Versioning & release history:** bump `SCRIPT_VERSION` in `product/src/00_bootstrap.sh` whenever the source changes before releasing. Don't bump for a byte-identical re-host — the SHA-256 won't change. `host-download.sh` reads the version and warns if it's already in the release history. After releasing a new version, add a `## [vX.Y.Z]` entry to `CHANGELOG.md`. Note `host-download.sh` only updates the manifest's **script** block; the **appliance** block and the Download page `FALLBACK` are updated in the appliance section below.

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
# 1. Build the SLIM script. host-download.sh just ran `make build`, so
#    product/build/tscrub.sh is currently the FULL build — rebuild slim
#    (sedutil comes from the image, not the script).
make -C product build-slim

# 2. Push the slim build into the overlay and commit on the build host.
#    `git checkout --` restores the bootloaders the ISO build clobbers
#    (bootx64.efi shrinks 1,060,864 -> 966,664 bytes). `git add -A` commits the
#    overlay plus the WinPE payload (board/shredos/winpe/); unstage the transient
#    build log so it never lands in git.
scp -o BatchMode=yes product/build/tscrub.sh \
  oxwet@192.168.0.6:~/shredos.x86_64/board/shredos/fsoverlay/usr/bin/tscrub.sh
ssh -o BatchMode=yes oxwet@192.168.0.6 'cd ~/shredos.x86_64 && \
  chmod 755 board/shredos/fsoverlay/usr/bin/tscrub.sh && \
  git checkout -- board/shredos/bootx64.efi board/shredos/shimx64.efi board/shredos/mmx64.efi board/shredos/grubx64.efi && \
  git add -A && git reset -q build_winpe.log && \
  git commit -m "tscrub vX.Y.Z"'

# 3. Rebuild under tmux (survives SSH drop); ISO + bzImage land in output/images/
ssh -o BatchMode=yes oxwet@192.168.0.6 'cd ~/shredos.x86_64 && \
  rm -f build_tscrub.log && \
  tmux new-session -d -s tscrub "board/shredos/modules/build-into-overlay.sh && make 2>&1 | tee -a build_tscrub.log; echo TSCRUB_BUILD_DONE >> build_tscrub.log"'
# poll: ssh oxwet@192.168.0.6 'grep -q TSCRUB_BUILD_DONE ~/shredos.x86_64/build_tscrub.log'
```

The image also carries tScrub's **out-of-tree kernel modules** from
`board/shredos/modules/` (currently `hp_biospw` — the HP BIOS password-clear
transport; it is why an HP setup password can be cleared from Linux at all).
`build_tscrub.sh` runs `board/shredos/modules/build-into-overlay.sh` for you; if
you drive `make` by hand, run that script **first** or the modules will not be in
the image. It rebuilds them against `output/build/linux-6.18` and installs the
`.ko` into both the rootfs overlay and the live target tree, so it must be re-run
whenever the kernel is rebuilt (vermagic and symbol versions are checked at load
time). Verify inside a booted image with
`modinfo /lib/modules/$(uname -r)/extra/hp_biospw.ko`.

The build produces two artifacts in `~/shredos.x86_64/output/images/`:
- `tscrub-v<VER>_…_<hash>.iso` — the bootable appliance (copy to `~/webs/tscrub/downloads/`, repoint `tscrub-appliance.iso`, update the manifest + download page).
- `bzImage` — the self-contained kernel (embedded initramfs) used for **PXE** boots; it has no separate `initrd`.

> **The build-output `bzImage` is UNSIGNED.** The ISO's `/boot/bzImage` is the
> signed copy (for the ISO's own shim→grub chain, signed with the shared
> "My iPXE Vendor Key"). For a **network** boot that uses `shim` + Secure Boot
> (the iPXE `shim` command verifies the kernel), the served `bzImage` must be
> signed with the same key — `~/ipxe-sb/vendor.key`.

**Publish the appliance ISO** to `~/webs/tscrub/downloads/` (nginx serves it at
`https://tscrub.com/downloads/…`). Copy the ISO, write its `.sha256`, and repoint
the `tscrub-appliance.iso` symlink:

```bash
ssh -o BatchMode=yes oxwet@192.168.0.6 'cd ~/webs/tscrub/downloads && \
  ISO=tscrub-v<VER>_…_<hash>.iso && \
  cp ~/shredos.x86_64/output/images/"$ISO" ./"$ISO" && \
  sha256sum "$ISO" > "$ISO.sha256" && \
  ln -sfn "$ISO" tscrub-appliance.iso && \
  printf "%s  tscrub-appliance.iso\n" "$(sha256sum "$ISO" | awk "{print \$1}")" > tscrub-appliance.iso.sha256'
```

Then update the three places that surface the ISO (the Download page reads
`/downloads/manifest.json` at runtime and falls back to an in-page `FALLBACK`,
so both must change):

1. `marketing/server/download-manifest.json` → `appliance` block: `version`,
   `filename`, `url`, `sha256`, and `size_mb` (MiB = bytes ÷ 1048576).
2. `marketing/site/download.html` → the `FALLBACK` object (same fields).
3. Deploy the backend (manifest) and the site:

```bash
cd marketing
npm run deploy:server
ssh -o BatchMode=yes oxwet@192.168.0.6 'cp ~/webs/tscrub-form/download-manifest.json ~/webs/tscrub/downloads/manifest.json'
npm run build && npm run deploy
```

**Publish the PXE kernel** to the LAN file server on `.6`
(`~/webs/lan/ipxe/tscrub/bzImage`, served at
`http://192.168.0.6:8080/ipxe/tscrub/bzImage`). The build already runs on `.6`,
so there is no Mac relay — copy straight from the build output and sign in place
with the operator's iPXE vendor key (`sbsign`; keys live in `~/ipxe-sb/` on `.6`):

```bash
ssh -o BatchMode=yes oxwet@192.168.0.6 'mkdir -p ~/webs/lan/ipxe/tscrub && \
  cp ~/shredos.x86_64/output/images/bzImage ~/webs/lan/ipxe/tscrub/bzImage && \
  cd ~/webs/lan/ipxe/tscrub && \
  sbsign --key ~/ipxe-sb/vendor.key --cert ~/ipxe-sb/vendor.crt --output bzImage.signed bzImage && \
  mv -f bzImage.signed bzImage && sbverify --list bzImage'
```

Verify the signature shows the enrolled issuer (`/CN=My iPXE Vendor Key`), then
the iPXE `kernel` line fetches it directly — no `initrd` line is needed:

```
kernel ${base-url}/tscrub/bzImage console=tty3 loglevel=3
```

### Verify packaged binaries actually run (before publishing)

Buildroot only proves a package *compiled*, not that it will *execute* at boot.
A dynamically-linked binary can ship into the image while a shared library (or
the dynamic linker) is missing — and then it fails at runtime with
`error while loading shared libraries`, which is easy to miss because the ISO
build still "succeeds". Do this for any new binary added to the image (e.g.
`flashrom`, `mdadm`, `tpm2_*`):

```bash
ssh -o BatchMode=yes oxwet@192.168.0.6 'cd ~/shredos.x86_64 && \
  bin=output/target/usr/sbin/flashrom && \
  readelf -l "$bin" | grep -i interpreter && \
  readelf -d "$bin" | grep NEEDED && \
  for l in $(readelf -d "$bin" | sed -n "s/.*\[\(.*\)\]/\1/p"); do \
    p=$(find output/target -name "$l" 2>/dev/null | head -1); \
    [ -n "$p" ] && echo "OK   $l" || echo "MISS $l"; done'
```

- `interpreter` (e.g. `/lib64/ld-linux-x86-64.so.2`) must exist under
  `output/target/`.
- Every `NEEDED` library must resolve to a file under `output/target/`.
- Note: running the binary *on the build host* is NOT a valid check — the host
  lacks the target libc/interpreter, so it fails even when the image is fine.

**PXE payloads** live in `~/webs/lan/ipxe/` on `.6` (nginx `lan.conf`,
`autoindex on`, port 8080). The boot menu itself stays on `.26`
(`~/html/boot.ipxe` — DHCP chains to it); its `tScrub` and `hashreport` entries
were repointed to `http://192.168.0.6:8080/ipxe/…` (hard IP).

## 3. Backend + database (`~/webs/tscrub-form/` on the server)

Server-side PHP + Python handlers (auth, forms, certificate issuing/verification, licence issuing) backed by **MySQL**.

Files live locally in `marketing/server/` and deploy with:

```bash
cd marketing
npm run deploy:server
```

What it copies: `deploy-server.sh` first propagates `site/public/logo.png` into
`server/logo.png`, then rsyncs the **entire** `marketing/server/` directory —
`api.php`, `auth.php`, `bios_unlock.php`, `db.php`, `download-manifest.json`,
`http.php`, `mail.php`, `mdm.php`, `mdm-worker.php`, `migrate.php`,
`render_cert.php`, `render_diag.php`, `render_drive.php`, `reports_lib.php`,
`schema.sql`, `seed-admin.php`, `stripe.php`, `submit.php`, `verify.php`,
`sendmail.py`, `issue_licence.py`, `config.example.json`, `nginx-location.conf`,
`cert-bg.png`, and `tcpdf/`. Deliberately **no `--delete`**; it excludes the
runtime-only files `config.json`, `certificates/`, `sign.crt`, `sign.key`,
`vendor.key`, and `__pycache__/`.

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
response, never a block. The MDM (Windows Autopilot) check is a paid feature that
spends 1 credit per live Graph probe (idempotent by job id, `mdm:live:<jobId>`)
and is refused for free accounts and zero-balance paid accounts (`mdm_gate()` in
`mdm.php` returns `free_tier` / `insufficient_credits`); the erasure pre-flight
signal (`credits` + `can_erase`) rides on `POST /api/reports/diagnostics`. Test
with Stripe test keys + `stripe listen`; the webhook endpoint returns 200 to
duplicate deliveries.

What NOT to overwrite (`deploy-server.sh` excludes these, so they are never touched):
- `config.json` — live SMTP + DB + Stripe credentials. Only edit on the server.
- `vendor.key` — the vendor private key (640, group www-data — php-fpm signs licences). Never copy it off the server.
- `sign.crt` / `sign.key` — the report-signing certificate + key.
- `certificates/` — persisted certificate records/PDFs (writable by www-data). `deploy-server.sh` has no `--delete`, so nothing is ever wiped, but back this up alongside the DB.

`tcpdf/` and `cert-bg.png` are versioned in `marketing/server/` and ship with every deploy — they are not runtime-only.

Permissions on the server (set once):

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && chmod 640 config.json && chmod 644 *.php && chmod 755 sendmail.py issue_licence.py && chmod 770 rl && chmod 640 vendor.key sign.key'
```

## 4. nginx changes (needs root — you run these)

The nginx config lives at `/etc/nginx/sites-available/tscrub.conf` (root-owned; `oxwet` has no sudo). Reference snippets are in `marketing/server/nginx-location.conf`.

Current PHP routes:
- `location = /verify` → `verify.php` (public, MySQL lookup)
- `location = /submit` → `submit.php` (contact form)
- `location /api/` → `api.php` (auth, dashboard, admin, licences, report upload, cert generation)

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
