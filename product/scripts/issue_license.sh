#!/usr/bin/env bash
set -euo pipefail

# Issue a tScrub licence. The vendor private key signs the licence; the report
# signing key embedded in the licence is what the appliance uses to sign reports.
#
# Usage:
#   scripts/issue_license.sh "Customer Ltd" 2027-09-12 vendor-private-key.pem license.key [tier]
#
# Prints the vendor PUBLIC key (base64, single line) to stdout for embedding in
# the image as LICENSE_VENDOR_PUBLIC_KEY_B64.

CUSTOMER="${1:?customer name required}"
EXPIRY="${2:?expiry (YYYY-MM-DD) required}"
VENDOR_KEY="${3:?vendor private key path required}"
OUT="${4:-license.key}"
TIER="${5:-free}"

command -v openssl >/dev/null 2>&1 || { echo "openssl required" >&2; exit 1; }
[[ -f "$VENDOR_KEY" ]] || { echo "vendor key not found: $VENDOR_KEY" >&2; exit 1; }
[[ "$EXPIRY" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || { echo "expiry must be YYYY-MM-DD" >&2; exit 1; }

tmp="$(mktemp -d /tmp/tscrub-issue.XXXXXX)"
trap 'rm -rf "$tmp"' EXIT

# A report-signing key is only embedded for paid tiers; free licences stay
# unsigned (checksum-only reports).
key_b64=""
if [[ "$TIER" != "free" ]]; then
    openssl genpkey -algorithm ED25519 -out "$tmp/report.key" 2>/dev/null
    key_b64="$(openssl base64 -A -in "$tmp/report.key")"
fi

msg="${CUSTOMER}|${EXPIRY}|${TIER}|${key_b64}"
printf '%s' "$msg" > "$tmp/msg"
openssl pkeyutl -sign -inkey "$VENDOR_KEY" -rawin -in "$tmp/msg" -out "$tmp/sig" 2>/dev/null
sig_b64="$(openssl base64 -A -in "$tmp/sig")"

{
    printf '{\n'
    printf '  "schema": "tscrub-license/1",\n'
    printf '  "customer": "%s",\n' "$CUSTOMER"
    printf '  "tier": "%s",\n' "$TIER"
    printf '  "expiry": "%s",\n' "$EXPIRY"
    [[ -n "$key_b64" ]] && printf '  "key": "%s",\n' "$key_b64"
    printf '  "signature": "%s"\n' "$sig_b64"
    printf '}\n'
} > "$OUT"

echo "Licence written to: $OUT" >&2
echo "Vendor public key (embed as LICENSE_VENDOR_PUBLIC_KEY_B64):" >&2
openssl pkey -in "$VENDOR_KEY" -pubout 2>/dev/null | openssl base64 -A
