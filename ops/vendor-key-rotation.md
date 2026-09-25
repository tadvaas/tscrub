# Vendor Key Rotation & Incident Runbook

The vendor Ed25519 keypair is the **root of trust** for all licences and signed reports. Handle it carefully.

## Where the key lives

| Piece | Location |
|---|---|
| **Private key** | `/home/oxwet/webs/tscrub-form/vendor.key` (mode 640 group www-data, server only) |
| **Public key (build)** | `product/keys/vendor-public-key.pem` (committed) |
| **Public key (published)** | https://tscrub.com/docs (Licensing + "Release & key history" sections) |
| **Fingerprint** | `be81586c42b5fb2451f7691782c08376c2038d277e79710ff45294409b476c02` |

## Key facts

- The private key signs every licence (`tscrub-license/1`).
- A licence carries a **report-signing key**; that key signs the customer's chain-of-custody reports.
- The appliance verifies licences against the **embedded vendor public key** (`LICENSE_VENDOR_PUBLIC_KEY_B64` in `build/tscrub.sh`).
- **Consequence of rotation:** every licence signed by the old key becomes invalid and must be **re-issued**.

---

## Rotation procedure

### 1. Generate a new keypair on the server

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && \
  openssl genpkey -algorithm ED25519 -out vendor.key.new && \
  chmod 640 vendor.key.new && \
  openssl pkey -in vendor.key.new -pubout -out vendor-public-key.pem.new'
```

### 2. Swap in the new key

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && \
  mv vendor.key vendor.key.old && \
  mv vendor.key.new vendor.key && \
  mv vendor-public-key.pem vendor-public-key.pem.old && \
  mv vendor-public-key.pem.new vendor-public-key.pem'
```

### 3. Update the build public key + rebuild + redeploy

```bash
# fetch the new public key to your Mac
scp oxwet@192.168.0.6:~/webs/tscrub-form/vendor-public-key.pem \
  product/keys/vendor-public-key.pem

cd product && make build && bash scripts/deploy.sh
```

### 4. Update the published fingerprint + key

Compute the new fingerprint, then update:
- `marketing/site/docs.html` — the fingerprint in "6. Verify a report", the PEM + fingerprint in "7. Licensing", and the "Release & key history" table (new key fingerprint; keep the old key listed so old licences/releases stay verifiable).

Redeploy the marketing site: `cd marketing && npm run deploy`.

### 5. Re-issue all active licences

Every licence issued with the old key is now invalid — including free-tier ones.
Active licences live in the `licences` table; customers can also self-serve a new
one from `/download` (which records a new DB row). For manual re-issue:

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && python3 issue_licence.py --tier team "Customer Ltd" YYYY-MM-DD buyer@example.com'
```

### 6. Archive the old PUBLIC key, delete the old PRIVATE key

Keep the old **public** key published on /docs ("Release & key history") so licences and releases signed by it stay verifiable. Only the old **private** key should be deleted:

```bash
ssh oxwet@192.168.0.6 'cd ~/webs/tscrub-form && rm -f vendor.key.old'
```

---

## Incident response (key compromised)

If the private key is believed stolen:

1. **Rotate immediately** (steps 1–6 above).
2. **Invalidate old licences** — they're already invalid once the new public key is deployed, but treat any licence issued before the compromise as untrusted.
3. **Notify affected customers** and re-issue their licences.
4. **Audit** — list issued licences: `SELECT * FROM tScrub.licences` (legacy files also in `~/webs/tscrub-form/licences/`).
5. **Review server access** — the key lived only on `192.168.0.6`; investigate how it was exposed and rotate SSH credentials if warranted.

---

## Backup & recovery

- There is **no offline backup** of `vendor.key` by design (it never leaves the server).
- **Losing the key** (without compromise) = generate a new one and re-issue all licences — the same rotation procedure.
- If you want a backup, store an **encrypted** copy offline (e.g. `age`/`gpg`-encrypted), never plaintext.
