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

Reports from **licensed** builds are signed with a vendor-issued key and verify against the published vendor public key.

- **Fingerprint:** `be81586c42b5fb2451f7691782c08376c2038d277e79710ff45294409b476c02`
- **PEM:** published on https://tscrub.com/docs (section 7, Licensing)

Without a licence, reports are **self-signed** — they still prove integrity (`MISMATCH` detection) but not attribution to a customer.

## Count drives for billing

The report is the meter. Drives erased = number of entries in `drives[]`.

```bash
# quick count from a manifest
python3 -c "import json,sys; print(len(json.load(open(sys.argv[1]))['drives']))" tscrub_48213_20260912T1030Z.json
```

Per-customer totals require the manifest to carry a `licence_id`/customer identifier — currently a **to-do** (see licence workflow runbook).

## Manual signature check (no tscrub binary)

```bash
openssl dgst -sha256 report.csv                       # compare to manifest.sha256
openssl pkeyutl -verify -pubin -inkey pub.pem -rawin \
  -in report.csv -sigfile <(openssl base64 -d -in report.sig)
```
