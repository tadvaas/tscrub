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

## 2. Product (`product/`)

Builds the self-contained script and uploads it via scp.

```bash
cd product
make build            # free/community build (no vendor key)
make build-enterprise # Team/Enterprise build (embeds the vendor public key)
make deploy           # build (free) + upload
make deploy-check     # build + local preflight only (no upload)
```

What it does:
- `scripts/build.sh` assembles `build/tscrub.sh` (concatenates `src/*.sh`, embeds `payload/sedutil-cli`).
- The vendor public key (`keys/vendor-public-key.pem`) is embedded **only** by `make build-enterprise`, which enables licence verification and vendor-signed reports.
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

## 3. Form / licence backend (`~/webs/tscrub-form/` on the server)

Server-side PHP + Python handlers (form submissions + licence issuing).

Files live locally in `marketing/server/` and are copied to the server:

```bash
cd marketing/server
scp submit.php sendmail.py issue_licence.py oxwet@192.168.0.6:~/webs/tscrub-form/
```

What NOT to overwrite:
- `config.json` — holds live SMTP credentials, routing, and vendor key path. Only edit on the server; use `config.example.json` as the template.
- `vendor.key` — the vendor private key (600). Never copy it off the server.

Permissions on the server (set once):

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && chmod 640 config.json && chmod 644 submit.php && chmod 755 sendmail.py issue_licence.py && chmod 770 rl && chmod 600 vendor.key'
```

## 4. nginx changes (needs root — you run these)

The `/submit` route and any nginx config changes require sudo (my SSH session has none):

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Reference snippet lives in `marketing/server/nginx-location.conf`.

---

## Post-deploy verification

```bash
# marketing
curl -s -o /dev/null -w "%{http_code}\n" https://tscrub.com/            # 200
curl -s -o /dev/null -w "%{http_code}\n" https://tscrub.com/robots.txt  # 200

# form endpoint (GET is rejected, POST works)
curl -s -o /dev/null -w "%{http_code}\n" https://tscrub.com/submit      # 405

# product build
cd product && grep -c 'LICENSE_VENDOR_PUBLIC_KEY_B64' build/tscrub.sh
```
