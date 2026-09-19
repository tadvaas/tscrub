#!/usr/bin/env bash
# Tests the licence mechanism: verify, expiry, tampering, and attributable signing.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

[[ -x /opt/homebrew/bin/openssl ]] && export PATH="/opt/homebrew/bin:$PATH"

have_ed25519=0
_tmpkey="$(mktemp)"
if command -v openssl >/dev/null 2>&1 && openssl genpkey -algorithm ED25519 -out "$_tmpkey" 2>/dev/null; then
    have_ed25519=1
fi
rm -f "$_tmpkey"

if (( have_ed25519 == 0 )); then
    t::check "licence test skipped (no Ed25519 openssl)" 'true'
    t::summary
    exit 0
fi

tdir="$(mktemp -d)"
openssl genpkey -algorithm ED25519 -out "$tdir/vendor.key" 2>/dev/null
LICENSE_VENDOR_PUBLIC_KEY_B64="$(openssl pkey -in "$tdir/vendor.key" -pubout 2>/dev/null | openssl base64 -A)"

# Issue a team licence (signed), a free licence (unsigned), and an expired one.
bash "$ROOT_DIR/scripts/issue_license.sh" "Acme ITAD Ltd" 2099-12-31 "$tdir/vendor.key" "$tdir/license.key" team >/dev/null 2>&1
bash "$ROOT_DIR/scripts/issue_license.sh" "Free Co" 2099-12-31 "$tdir/vendor.key" "$tdir/free.key" free >/dev/null 2>&1
bash "$ROOT_DIR/scripts/issue_license.sh" "Old Co" 2020-01-01 "$tdir/vendor.key" "$tdir/expired.key" team >/dev/null 2>&1

t::check "valid (team) licence verifies" 'license::verify "$tdir/license.key"'
t::check "free licence verifies" 'license::verify "$tdir/free.key"'
t::check "expired licence rejected" '! license::verify "$tdir/expired.key"'

# Free licences carry no report key, so reports stay unsigned.
t::check "free licence has no report key" '! license::apply "$tdir/free.key"'

# Tamper with the signed payload; signature must no longer verify.
sed 's/"expiry": "2099-12-31"/"expiry": "2099-12-30"/' "$tdir/license.key" > "$tdir/tampered.key"
t::check "tampered licence rejected" '! license::verify "$tdir/tampered.key"'

# Apply the team licence and confirm reports are signed with the licensed key.
license::apply "$tdir/license.key"
t::check "apply sets REPORT_KEY" '[[ -n "$REPORT_KEY" && -f "$REPORT_KEY" ]]'

COCID="99999"; TABLE_INDENT="    "
SYS_MANUFACTURER="D"; SYS_PRODUCT="P"; SYS_SERIAL="S"; SYS_BASEBOARD_SERIAL="B"
REPORT_DIR="$tdir/"
devices=(nvme0n1)
devrow=()
devrow[nvme0n1.status]=COMPLETED; devrow[nvme0n1.method]="NVMe Crypto Purge"; devrow[nvme0n1.cert]=DESTRUCTION
devrow[nvme0n1.model]=M; devrow[nvme0n1.serial]=S; devrow[nvme0n1.size]=1TB; devrow[nvme0n1.bus]=NVMe
devrow[nvme0n1.type]=SSD; devrow[nvme0n1.class]=PURGE; devrow[nvme0n1.device]=nvme0n1

csv="$(report::csv 2>/dev/null)"
t::check "report signed with licence key" '[[ -f "$csv.sig" ]]'
vout="$(report::verify "$csv")"
t::check "licensed report verifies VALID" '[[ "$vout" == *"Signature: VALID"* ]]'

# --license flag (both forms) must override the default licence path.
LICENSE_FILE=""
parse_args --license "$tdir/license.key"
t::check "--license PATH sets LICENSE_FILE" '[[ "$LICENSE_FILE" == "$tdir/license.key" ]]'
LICENSE_FILE=""
parse_args "--license=$tdir/license.key"
t::check "--license=PATH sets LICENSE_FILE" '[[ "$LICENSE_FILE" == "$tdir/license.key" ]]'

# --license-url (both forms) and remote fetch.
LICENSE_URL=""
parse_args --license-url "file://$tdir/license.key"
t::check "--license-url sets LICENSE_URL" '[[ "$LICENSE_URL" == "file://$tdir/license.key" ]]'
LICENSE_URL=""
parse_args "--license-url=file://$tdir/license.key"
t::check "--license-url=URL sets LICENSE_URL" '[[ "$LICENSE_URL" == "file://$tdir/license.key" ]]'

LICENSE_FILE=""
license::fetch "file://$tdir/license.key"
t::check "license::fetch downloads licence" '[[ -s "$LICENSE_FILE" && -f "$LICENSE_FILE" ]]'
t::check "fetched licence verifies" 'license::verify "$LICENSE_FILE"'
rm -f "$LICENSE_FILE"

# Embedded licence (customer builds): apply_embedded + detect fallback.
LICENSE_EMBEDDED_B64="$(openssl base64 -A -in "$tdir/license.key")"
LICENSE_FILE=""
LICENSE_SOURCE_SET=0
license::apply_embedded
t::check "embedded licence decoded" '[[ -s "$LICENSE_FILE" && -f "$LICENSE_FILE" ]]'
t::check "embedded licence verifies" 'license::verify "$LICENSE_FILE"'
rm -f "$LICENSE_FILE"

LICENSE_FILE=""
LICENSE_URL=""
LICENSE_SOURCE_SET=0
license::detect
t::check "detect falls back to embedded licence" '[[ -s "$LICENSE_FILE" && -f "$LICENSE_FILE" ]]'
t::check "detected embedded licence verifies" 'license::verify "$LICENSE_FILE"'
rm -f "$LICENSE_FILE"
LICENSE_EMBEDDED_B64=""

rm -rf "$tdir"
t::summary
