# Deploy Runbook

There are three things that get deployed, each with its own path. For the
canonical end-to-end order of a full appliance release (script → ISO → PXE
kernel → manifest → site → git tag), start with the release runbook below.

## Release runbook (the short version)

A full appliance release ships the script, the ISO, the PXE kernel, the
manifest, the Download page and the marketing site. Run these in order; each
step links to the detailed section that follows:

1. **Bump the version** — `SCRIPT_VERSION` in `product/src/00_bootstrap.sh`, and
   a dated `## [vX.Y.Z] - YYYY-MM-DD` entry at the top of `CHANGELOG.md`.
2. **Host + sign the script** — `bash ops/host-download.sh` builds `tscrub.sh`,
   signs it with the vendor key, updates the manifest `script` block, deploys
   the backend and copies the manifest under `/downloads/` (§2).
3. **Stage the slim script into the appliance** — `make -C product build-slim`,
   scp it to `board/shredos/fsoverlay/usr/bin/tscrub.sh` on `.6`, `chmod 755`,
   and commit on the build host (§2 "Appliance ISO + PXE kernel").
4. **Rebuild the appliance** — under tmux: `build-into-overlay.sh && make`, then
   poll `build_tscrub.log` for `TSCRUB_BUILD_DONE` (§2).
5. **Publish the ISO** — copy `output/images/tscrub-…-<hash>.iso` into
   `~/webs/tscrub/downloads/`, write its `.sha256`, repoint the
   `tscrub-appliance.iso` symlink (§2). `<hash>` is the **build host's HEAD
   commit**, not a content hash: commit the overlay (step 3) *before* building,
   or a new ISO advertises the previous release's commit in its filename.
6. **Publish + sign the PXE kernel** — `bash ops/publish-netboot.sh` copies,
   signs with the iPXE vendor key, verifies the issuer and prints the SHA-256;
   `--check` re-verifies whatever is being served (§2).
7. **Update the surfaces that show the ISO** — `download-manifest.json`
   `appliance` block + `download.html` `FALLBACK`, then `npm run deploy:server`,
   copy the manifest to `/downloads/manifest.json` (**that exact filename** —
   `manifest.json` is what the Download page fetches; publish
   `download-manifest.json` and the page silently falls back to its in-page
   block), and `npm run build && npm run deploy` (§2).
8. **Commit + tag + push on the Mac repo** — `git add -A && git commit`,
   `git tag -a vX.Y.Z -m "tScrub vX.Y.Z"`, `git push origin main &&
   git push origin vX.Y.Z` (§6).
9. **Verify the release is live AND coherent** — run every check in §7. They
   assert that the artefacts *agree with each other*, not merely that each URL
   answers: a stale symlink, a stale page fallback and a manifest at the wrong
   path all return HTTP 200.

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

Manual equivalent (upload only, no site redeploy). **The checksum and the
signature are separate steps: skip the `pkeyutl` line and the new script ships
beside the PREVIOUS release's `.sig`**, which either fails verification or, far
worse, is trusted against a file it never signed:

```bash
cd product
make build
scp -o BatchMode=yes build/tscrub.sh oxwet@192.168.0.6:~/webs/tscrub/downloads/tscrub.sh
ssh -o BatchMode=yes oxwet@192.168.0.6 'cd ~/webs/tscrub/downloads && \
  sha256sum tscrub.sh > tscrub.sh.sha256 && \
  openssl pkeyutl -sign -inkey /home/oxwet/webs/tscrub-form/vendor.key -rawin -in tscrub.sh -out tscrub.sh.sig'
```

The signature is Ed25519 over the raw script bytes (`-rawin`), verified against
`tscrub.pub` — see `report-verification.md` for the client-side check.

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
#    overlay plus the WinPE payload (board/shredos/winpe/). The transient build
#    log is `build_tscrub.log` (and `build_tscrub_v*.log`) — it is already
#    gitignored, so no `git reset` is needed.
scp -o BatchMode=yes product/build/tscrub.sh \
  oxwet@192.168.0.6:~/shredos.x86_64/board/shredos/fsoverlay/usr/bin/tscrub.sh
ssh -o BatchMode=yes oxwet@192.168.0.6 'cd ~/shredos.x86_64 && \
  chmod 755 board/shredos/fsoverlay/usr/bin/tscrub.sh && \
  git checkout -- board/shredos/bootx64.efi board/shredos/shimx64.efi board/shredos/mmx64.efi board/shredos/grubx64.efi && \
  git add -A && \
  git commit -m "tscrub vX.Y.Z"'

# 3. Rebuild under tmux (survives SSH drop); ISO + bzImage land in output/images/
ssh -o BatchMode=yes oxwet@192.168.0.6 'cd ~/shredos.x86_64 && \
  rm -f build_tscrub.log && \
  tmux new-session -d -s tscrub "board/shredos/modules/build-into-overlay.sh && make 2>&1 | tee -a build_tscrub.log; echo TSCRUB_BUILD_DONE >> build_tscrub.log"'
# poll: ssh oxwet@192.168.0.6 'grep -q TSCRUB_BUILD_DONE ~/shredos.x86_64/build_tscrub.log'
```

**Prove the image carries the version you think it does.** Nothing else checks
this: the build succeeds against *whatever* is in the overlay, and the ISO
filename only tells you the commit, not the script. Compare the overlay byte for
byte with the slim build you just made:

```bash
shasum -a 256 product/build/tscrub.sh | awk '{print $1}'          # local slim
ssh -o BatchMode=yes oxwet@192.168.0.6 'sha256sum \
  ~/shredos.x86_64/board/shredos/fsoverlay/usr/bin/tscrub.sh | awk "{print \$1}"'
# the two must be identical, and both must report the expected version:
grep -m1 '^SCRIPT_VERSION=' product/build/tscrub.sh
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

### Retention — what stays public

**Keep two releases public: the current one and the previous one.** Everything
older stays on the build host under `output/images/`, which is the archive of
record; the public directory is a shop window, not a museum.

```bash
bash ops/prune-downloads.sh                 # dry run: prints the plan, deletes nothing
bash ops/prune-downloads.sh --apply         # delete
bash ops/prune-downloads.sh --keep 3        # keep the N most recent versions
bash ops/prune-downloads.sh --apply --junk  # also drop WinPE dev artefacts + orphan sidecars
```

Why it matters more than disk: **any public ISO is a build that can still
produce evidence.** v1.11.73 shipped a coverage sampler that was inert, fixed in
v1.11.75; while 1.11.73 stays downloadable, a customer can generate a claim the
current release would refuse, carrying our name. One current build plus one
rollback is also the only answer to "which version are you running?".

The script refuses to delete:

- the file named in `/downloads/manifest.json`, or `tscrub-appliance.iso` and
  the file it points at;
- any build of the N most recent versions (it keeps *versions*, not file
  counts — 5 versions were once published twice);
- any ISO with no byte-identical copy in `output/images/` — if the archive copy
  is missing, the public copy stays;
- anything outside `~/webs/tscrub/downloads`.

It appends an audit listing (`ls -l` of every removed file) to
`~/prune-downloads-<UTC>.log` on `.6`. Deleting a binary does not erase its
record: every release's SHA-256 stays in `CHANGELOG.md`.

**Publish the PXE kernel** to the LAN file server on `.6`
(`~/webs/lan/ipxe/tscrub/bzImage`, served at
`http://192.168.0.6:8080/ipxe/tscrub/bzImage`). **Use the helper** — it copies,
signs, verifies and prints the SHA-256 in one step, and exits non-zero rather
than publish an unsigned kernel:

```bash
bash ops/publish-netboot.sh          # copy + sbsign + sbverify + sha256
bash ops/publish-netboot.sh --check  # verify whatever is currently served
```

> The build-output `bzImage` is UNSIGNED. The netboot chain is shim → kernel,
> so serving the raw file stops every Secure Boot machine at a MOK prompt —
> even one that already enrolled the key. The tell is size: the signature adds
> exactly **1,568 bytes**. Do not hand-copy the file.

The manual equivalent (only if the helper is unavailable). The build already
runs on `.6`, so there is no Mac relay — copy straight from the build output and
sign in place with the operator's iPXE vendor key (`~/ipxe-sb/` on `.6`):

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

⚠️ **`.6` is a shared nginx host** (dayrota, emotorbay, grassbarbers, homepage,
lan, nebula, … all live here). Keep every change additive and inside the
tscrub.com server block.

⚠️ **Do not add an origin-level `return 301 https://…` for tscrub.com.** Measured
2026-10-22: that server block has no `listen 443` and no certificate, and the
site sits behind Cloudflare in **Flexible** SSL, so the CDN fetches the origin on
port 80. A scheme-wide redirect there also matches the CDN's own request, which
loops every visitor (`ERR_TOO_MANY_REDIRECTS`). The public redirect belongs at
the edge: Cloudflare → SSL/TLS → Edge Certificates → **Always Use HTTPS**.
`marketing/server/nginx-redirects.conf` PART A carries the same warning.

A **staged patch** for the retired-page 301s (and the `www` target, which sent
visitors to `http://`) lives on `.6` as `~/tscrub.conf.new`. To install it:

```bash
ssh oxwet@192.168.0.6
sudo cp -a /etc/nginx/sites-available/tscrub.conf /etc/nginx/sites-available/tscrub.conf.bak-$(date +%F)
sudo install -o root -g root -m 644 /home/oxwet/tscrub.conf.new /etc/nginx/sites-available/tscrub.conf
sudo nginx -t && sudo systemctl reload nginx
```

The nginx config lives at `/etc/nginx/sites-available/tscrub.conf` (root-owned; `oxwet` has no sudo). Reference snippets are in `marketing/server/nginx-location.conf` (PHP/API routes) and `marketing/server/nginx-redirects.conf` (HTTP→HTTPS + retired-page 301s).

Current PHP routes:
- `location = /verify` → `verify.php` (public, MySQL lookup)
- `location = /submit` → `submit.php` (contact form)
- `location /api/` → `api.php` (auth, dashboard, admin, licences, report upload, cert generation)

To add/change a route, paste the matching block from `nginx-location.conf` into the `server { server_name tscrub.com; … }` block, then:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## 5. Marketing docs sweep (after any feature that changes the CLI, report, or statuses)

Before finishing a release, go over the marketing docs and make sure they match
what the appliance and dashboard actually do now. Surfaces to check, in priority
order:

- `marketing/site/docs.html` — the **kernel-parameters table** (§13), the
  **console-flags table** (§13), the **report/CSV column description** (§8), and
  the **drive final-status table** (§8). Any new `--flag`, `tscrub_*=` parameter,
  CSV column, or drive status must appear here.
- `marketing/site/download.html` — the Download page's feature list and the
  manifest `FALLBACK` checksum block.
- `marketing/site/faq.html`, `compliance.html`, `compare.html`,
  `resources/*.html` — any prose that names flags, columns, statuses, or counts.
  (A blind find-and-replace of a renamed label has bit us before — sweep prose
  too, not just the UI.)
- `marketing/site/public/llms.txt` + `llms-full.txt` — whenever a page is added
  or retitled, or a service/feature list changes (keep the LLM context files in
  sync per the SEO preference).

### Claim surfaces (when the release changes what the product ASSERTS)

A copy sweep is not enough when a release changes a claim about a drive, a job
or a standard. This repo has shipped three claim surfaces disagreeing at once
(PDF, JSON-LD and `/verify`), and a helper with passing tests that nothing
called. Tick each surface against the new class of evidence:

- [ ] **PDF certificate** — `render_cert.php`, rendered and text-extracted, not
      merely read.
- [ ] **JSON-LD twin** — the same certificate with `&format=jsonld`, carrying the
      same claim, or an explicit reason it is absent.
- [ ] **`/verify`** — the public page's wording, plus its tests.
- [ ] **Dashboard drawer + CSV export** — `api.php`'s `$cols` whitelist silently
      drops any field you forget to add.
- [ ] **Grep the RENDERED artefacts** for the claim, never only the source. A
      claim can be correct in one artefact and absent in another; that is
      precisely how a false statement survived a green test suite.

Find every mention of the new token, then update + deploy:

```bash
cd marketing/site && grep -rn -- '--flr\|FreezeLock\|BlockSID\|FLRAttempts\|tscrub_flr' .
cd marketing && npm run build && npm run deploy
```

If a report-schema field changed, the backend parser
(`marketing/server/reports_lib.php`) must go out in the **same pass**:
`npm run deploy:server`.

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

# edge redirects (marketing/server/nginx-redirects.conf)
# These two only pass once §4 has been applied as root and nginx reloaded.
# Until then http:// still answers 200 and /how-it-works still 404s — that is
# the unapplied state, not a failed release.
# NOTE: the http:// -> https:// redirect is a CLOUDFLARE edge toggle (Always Use
# HTTPS), NOT an origin nginx rule — the origin has no TLS listener and the CDN
# fetches it on port 80, so an origin redirect loops. Expect 301 here only once
# the edge toggle is on.
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" http://tscrub.com/                # 301 https://tscrub.com/
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" https://tscrub.com/how-it-works   # 301 https://tscrub.com/docs

# product build
cd product && grep -c 'LICENSE_VENDOR_PUBLIC_KEY_B64' build/tscrub.sh
```

## 6. Git commit, tag & push (Mac repo)

The Mac repo (`/Users/user/Documents/Repositories/tscrub`) holds the source, the
marketing site and the server code; the build host (`~/shredos.x86_64` on `.6`)
is a separate repo, committed during the appliance step above. After everything
is deployed and verified, commit the Mac repo and tag the release:

```bash
cd /Users/user/Documents/Repositories/tscrub
git add -A && git status --short
git commit -m "Release tScrub vX.Y.Z"
git tag -a vX.Y.Z -m "tScrub vX.Y.Z"
git push origin main && git push origin vX.Y.Z
```

- Tag the same `main` HEAD you just committed; the tag is annotated.
- Use `git add -A` (not `git commit -am`) — a release often adds new files, e.g.
  a new `product/tests/test_*.sh`, which `-am` would miss.
- Push the tag explicitly: `git push origin vX.Y.Z`.

## 7. Verify a release is live

These assert the **new version's artifacts** are actually served (complementing
the component-health checks in "Post-deploy verification" above). Run them after
every release; each must succeed before you call it shipped:

```bash
# Manifest: appliance version + filename, script version + checksum prefix
curl -fsS https://tscrub.com/downloads/manifest.json \
  | python3 -c 'import json,sys; m=json.load(sys.stdin); a=m["appliance"]; s=m["script"]; print("appliance:", a["version"], a["filename"]); print("script:", s["version"], s["sha256"][:16])'

# ISO reachable via BOTH the stable symlink and the versioned URL
curl -fsSI https://tscrub.com/downloads/tscrub-appliance.iso | head -1
curl -fsSI https://tscrub.com/downloads/tscrub-v<VER>_..._<hash>.iso | head -1

# Script, signature and public key reachable
curl -fsSI https://tscrub.com/downloads/tscrub.sh | head -1
curl -fsSI https://tscrub.com/downloads/tscrub.sh.sig | head -1
curl -fsSI https://tscrub.com/downloads/tscrub.pub | head -1

# Download page serves the NEW build hash (the in-page FALLBACK and the runtime
# manifest read must agree)
curl -fsS https://tscrub.com/download | grep -c '<hash>'

# docs.html carries any newly-added flag / config token (e.g. psids.conf)
curl -fsS https://tscrub.com/docs.html | grep -oE 'psids\.conf'
```

### 7b. Artefacts must agree, not merely exist

Every check above passes on a broken release: a stale `tscrub-appliance.iso`
symlink, a stale Download-page `FALLBACK`, and a manifest published under the
wrong filename all answer HTTP 200. All three happened in v1.11.77. Assert
correspondence instead:

```bash
M=https://tscrub.com/downloads/manifest.json
ISO=$(curl -fsS $M | python3 -c 'import json,sys;print(json.load(sys.stdin)["appliance"]["filename"])')
SHA=$(curl -fsS $M | python3 -c 'import json,sys;print(json.load(sys.stdin)["appliance"]["sha256"])')
SSH=$(curl -fsS $M | python3 -c 'import json,sys;print(json.load(sys.stdin)["script"]["sha256"])')
SIZ=$(curl -fsS $M | python3 -c 'import json,sys;print(json.load(sys.stdin)["appliance"]["size_mb"])')

# 1. the manifest names a file that actually exists
curl -fsSI "https://tscrub.com/downloads/$ISO" | head -1

# 2. the stable symlink resolves to THAT file (a stale symlink still 200s)
curl -fsS https://tscrub.com/downloads/tscrub-appliance.iso.sha256 | grep -q "$SHA" && echo "symlink OK"

# 3. the Download page's fallback agrees with the manifest
curl -fsS https://tscrub.com/download | grep -q "$SHA" && echo "fallback OK"

# 4. the script checksum sidecar agrees with the manifest's script block
curl -fsS https://tscrub.com/downloads/tscrub.sh.sha256 | grep -q "$SSH" && echo "script sha OK"

# 5. size_mb is the real size, not a figure copied from the previous release
BYTES=$(curl -fsSI "https://tscrub.com/downloads/$ISO" | awk 'tolower($1)=="content-length:"{print $2}' | tr -d '\r')
echo "manifest ${SIZ} MiB vs served $((BYTES / 1048576)) MiB"   # must match
```

> **Cloudflare cache.** `/assets/*` (and the generated site JS) is served with
> `cache-control: public, max-age=2592000, immutable`, so a redeploy does not
> change what users get until the cache key changes; the page HTML itself can
> also be heuristically browser-cached. If a freshly-deployed page still shows
> old bytes, fetch it with a throwaway query (`?fresh=1`); for `/assets/*`,
> bump the `?v=N` on the page's `<script src="…">` tag and re-deploy.
