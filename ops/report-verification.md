# Report Verification Runbook

How to verify a tScrub chain-of-custody report, and how to count drives for billing.

## What a report is

Each sanitisation job writes:

```
tscrub_48213_20260912T1030Z.csv   # chain of custody, one row per drive
tscrub_48213_20260912T1030Z.sig   # base64 Ed25519 signature over the CSV
tscrub_48213_20260912T1030Z.json  # manifest
```

The manifest (`schema: tscrub-report/1`) contains:
- `sha256` — hash of the CSV
- `public_key` — the report signing key (Ed25519)
- `drives[]` — one entry per physical drive

## Verify a report

```bash
tscrub verify tscrub_48213_20260912T1030Z.csv
# SHA-256: 4614d837...
# Manifest: OK
# Signature: VALID
```

With an explicit public key:

```bash
tscrub verify report.csv public-key.pem
```

Failure signals:
- `MISMATCH` — the CSV has been altered (hash doesn't match)
- `INVALID` — the signature doesn't validate

## The official vendor key

Every build requires a licence (even the free tier). The **licence** verifies
against the published vendor public key (the vendor signs each licence envelope).
Paid licences also carry a per-licence **report-signing key**; the appliance
signs each report with that key, and the server confirms the report's embedded
public key matches the licence before marking it **attributed**.

- **Fingerprint:** `be81586c42b5fb2451f7691782c08376c2038d277e79710ff45294409b476c02`
- **PEM:** published on https://tscrub.com/docs (section 7, Licensing)

## Attribution (server-side)

When a paid user uploads a report, the server:

1. Verifies the CSV SHA-256 against the manifest.
2. Verifies the Ed25519 signature against the `public_key` in the manifest.
3. Compares that key to `licences.pub_key` (the public half of the licence's
   report key, derived at issuance). A match marks the certificate `attributed`
   ("REPORT SIGNATURE VALID"); a valid-but-unmatched signature is recorded as
   `valid` ("SIGNATURE UNATTRIBUTED") and cannot be confirmed as attributable.

So a forged report (self-generated key) verifies its own signature but is NOT
attributed. Free licences carry no report key, so free reports remain
self-signed and non-attributable.

## Count drives for billing

The report is the meter. Drives erased = number of entries in `drives[]`.

```bash
# quick count from a manifest
python3 -c "import json,sys; print(len(json.load(open(sys.argv[1]))['drives']))" tscrub_48213_20260912T1030Z.json
```

Per-customer totals require the manifest to carry a `licence_id`/customer identifier — the licence is recorded in the `licences` table (tied to a user), but reports don't yet reference it. Still a **to-do** (see licence workflow runbook).

## Manual signature check (no tscrub binary)

```bash
openssl dgst -sha256 report.csv                       # compare to manifest.sha256
openssl pkeyutl -verify -pubin -inkey pub.pem -rawin \
  -in report.csv -sigfile <(openssl base64 -d -in report.sig)
```
