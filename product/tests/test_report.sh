#!/usr/bin/env bash
# Tests the signed-report sidecar: SHA-256 manifest + Ed25519 signature + verify.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

# Prefer a modern OpenSSL (Ed25519 -rawin needs 3.x) for local testing.
[[ -x /opt/homebrew/bin/openssl ]] && export PATH="/opt/homebrew/bin:$PATH"

have_ed25519=0
_tmpkey="$(mktemp)"
if command -v openssl >/dev/null 2>&1 && openssl genpkey -algorithm ED25519 -out "$_tmpkey" 2>/dev/null; then
    have_ed25519=1
fi
rm -f "$_tmpkey"

# Minimal report state.
COCID="48213"
TABLE_INDENT="    "
SYS_MANUFACTURER="Dell"; SYS_PRODUCT="PowerEdge"; SYS_SERIAL="SYS123"; SYS_BASEBOARD_SERIAL="BB123"
SYS_CPU_LIST=$'1. Intel Xeon\n2. Intel Xeon'
SYS_GPU_LIST='1. NVIDIA T4'
SYS_RAM_GB='64 GB'
REPORT_DIR="$(mktemp -d)/"
REPORT_KEY_DIR="$(mktemp -d)"
devices=(nvme0n1 sda)
devrow=()
for d in "${devices[@]}"; do
    devrow[$d.model]="M-$d"; devrow[$d.serial]="S-$d"; devrow[$d.size]="1 TB"
    devrow[$d.bus]="NVMe"; devrow[$d.type]="SSD"; devrow[$d.class]="PURGE"
    devrow[$d.device]="$d"
done
devrow[nvme0n1.status]="COMPLETED"; devrow[nvme0n1.method]="NVMe Crypto Purge"; devrow[nvme0n1.cert]="DESTRUCTION"
devrow[sda.status]="FROZEN"; devrow[sda.method]=""; devrow[sda.cert]="PHYS_DESTR"

csv="$(report::csv)"
manifest="${csv%.csv}.json"
sig="${csv}.sig"

t::check "report CSV exists" '[[ -f "$csv" ]]'
t::check "CSV header includes machine columns" 'head -n1 "$csv" | grep -q "System,SystemSerial,BaseboardSerial,CPU,GPU,RAM"'
t::check "CSV row carries machine profile" 'grep -q "Dell PowerEdge" "$csv" && grep -q "1. Intel Xeon; 2. Intel Xeon" "$csv" && grep -q "NVIDIA T4" "$csv" && grep -q "64 GB" "$csv"'
t::check "manifest JSON exists" '[[ -f "$manifest" ]]'

# The manifest must be strict-JSON-parseable (certify.php json_decode + the
# harness's python3 json.load both reject the missing-comma bug this guards).
if command -v python3 >/dev/null 2>&1; then
    t::check "manifest is valid JSON" 'python3 -c "import json,sys; json.load(open(sys.argv[1]))" "$manifest"'
else
    echo "# (python3 not found; skipping manifest JSON validity check)"
fi

sha="$(report::_sha256 "$csv")"
recorded="$(sed -n 's/.*"sha256": "\([0-9a-fA-F]\{64\}\)".*/\1/p' "$manifest")"
t::check "manifest sha256 matches report" '[[ -n "$sha" && "$sha" == "$recorded" ]]'

if (( have_ed25519 == 1 )); then
    t::check "signature file exists" '[[ -f "$sig" ]]'

    vout="$(report::verify "$csv")"
    t::check "verify reports VALID" '[[ "$vout" == *"Signature: VALID"* ]]'
    t::check "verify reports Manifest OK" '[[ "$vout" == *"Manifest: OK"* ]]'

    # Tamper with the report; verification must fail.
    tdir="$(mktemp -d)"
    cp "$csv" "$tdir/"; cp "$manifest" "$tdir/"; cp "$sig" "$tdir/"
    bad="$tdir/$(basename "$csv")"
    printf 'tampered\n' >> "$bad"
    bout="$(report::verify "$bad" 2>&1 || true)"
    t::check "tampered report fails verification" '[[ "$bout" == *"INVALID"* || "$bout" == *"MISMATCH"* ]]'
    rm -rf "$tdir"
else
    t::check "signing skipped (no Ed25519-capable openssl)" 'true'
fi

rm -rf "${REPORT_DIR%/}"
t::summary
