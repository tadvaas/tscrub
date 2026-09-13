#!/usr/bin/env bash
# Regenerates the JSON manifests (and the signed sidecar) for the sample
# report fixtures used to test the Certificate of Destruction generator.
# Requires openssl 3.x (Ed25519). Run: bash make-fixtures.sh
set -euo pipefail
cd "$(dirname "$0")"

OPENSSL=/opt/homebrew/bin/openssl
[[ -x "$OPENSSL" ]] || OPENSSL=openssl

sha256() { shasum -a 256 "$1" | awk '{print $1}'; }

# 1) Unsigned reports: CSV + JSON manifest only.
for stem in unsigned-report_10001 unsigned-report_10002; do
  csv="$stem.csv"
  sum="$(sha256 "$csv")"
  cocid="${stem##*_}"
  case "$cocid" in
    10001) created="2026-09-11T14:05:00Z"
           drives='    {"device":"nvme0n1","status":"COMPLETED","method":"NVMe Format","cert":"DESTRUCTION"}'
           ;;
    10002) created="2026-09-10T09:15:00Z"
           drives='    {"device":"nvme0n1","status":"COMPLETED","method":"NVMe Crypto Erase","cert":"DESTRUCTION"},
    {"device":"sda","status":"COMPLETED","method":"ATA Enhanced Secure Erase","cert":"SANITISATION"},
    {"device":"sdb","status":"COMPLETED","method":"SCSI Sanitize Overwrite","cert":"SANITISATION"}'
           ;;
  esac
  cat > "$stem.json" <<EOF
{
  "schema": "tscrub-report/1",
  "cocid": "$cocid",
  "created": "$created",
  "report": "$csv",
  "sha256": "$sum",
  "signed": false,
  "drives": [
$drives
  ]
}
EOF
done

# 2) Signed report: CSV + JSON manifest + Ed25519 .sig, with a throwaway key.
csv="signed-report_12345.csv"
stem="${csv%.csv}"
sum="$(sha256 "$csv")"
key="$stem.key"
"$OPENSSL" genpkey -algorithm ED25519 -out "$key" 2>/dev/null
chmod 600 "$key"
"$OPENSSL" pkeyutl -sign -inkey "$key" -rawin -in "$csv" -out "$stem.sig.tmp" 2>/dev/null
"$OPENSSL" base64 -in "$stem.sig.tmp" > "$stem.csv.sig"
rm -f "$stem.sig.tmp"
pub_b64="$("$OPENSSL" pkey -in "$key" -pubout 2>/dev/null | "$OPENSSL" base64 -A)"
cat > "$stem.json" <<EOF
{
  "schema": "tscrub-report/1",
  "cocid": "12345",
  "created": "2026-09-12T10:30:00Z",
  "report": "$csv",
  "sha256": "$sum",
  "signed": true,
  "public_key": "$pub_b64",
  "drives": [
    {"device":"nvme0n1","status":"COMPLETED","method":"NVMe Crypto Purge","cert":"DESTRUCTION"},
    {"device":"sda","status":"COMPLETED","method":"ATA Secure Erase","cert":"SANITISATION"}
  ]
}
EOF

echo "Generated:"
ls -1 "$stem.json" "$stem.csv.sig" "$stem.key" unsigned-report_10001.json unsigned-report_10002.json
