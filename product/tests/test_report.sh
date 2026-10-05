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
SYS_CHASSIS_SERIAL="CH123"; SYS_CHASSIS_TYPE="Tower"; SYS_BIOS_VERSION="2.5"; SYS_BIOS_DATE="01/01/2024"
SYS_UUID="4C4C4544-0036-5710-8032-B5C04F433633"
SYS_CPU_LIST=$'1. Intel Xeon\n2. Intel Xeon'
SYS_GPU_LIST='1. NVIDIA T4'
SYS_RAM_GB='64 GB'
SYS_SKU="SKU-001"; SYS_ASSET_TAG="AT-123"; SYS_BIOS_VENDOR="Dell Inc."; SYS_BOARD="Dell 0T7D40"
SYS_TPM="2.0"; SYS_MAC_LIST="aa:bb:cc:dd:ee:ff; 11:22:33:44:55:66"
SYS_STORAGE_CTRLS="1. Dell PERC HBA330; 2. Intel C610 SATA controller"
SCRIPT_VERSION="9.9.9-test"
OPERATOR_NAME="Jane Doe"; VALIDATOR_NAME="John Roe"
ASSET_TAG="CUST-9001"; MEDIA_SOURCE="IT decommissioning"; MEDIA_DESTINATION="resale"
BIOS_PASSWORD_STATUS="LOCKED"; BIOS_DETECTION_METHOD="SMBIOS Type 24 (Administrator Password Status)"
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
t::check "CSV header includes BIOSLock column" 'head -n1 "$csv" | grep -q ",BIOSLock"'
t::check "CSV header includes extended machine columns" 'head -n1 "$csv" | grep -q "ChassisSerial,ChassisType,BIOSVersion,BIOSDate,SystemUUID,BIOSLockMethod"'
t::check "CSV header includes timing columns" 'head -n1 "$csv" | grep -q "StartTime,EndTime,DurationSecs"'
t::check "CSV header includes drive-detail columns" 'head -n1 "$csv" | grep -q "Firmware,SectorSize,Sectors,HPA,DCO,HPAResult,DCOResult,SEDStatus,ReallocSectorsPost,SelfTest"'
t::check "CSV header includes asset columns" 'head -n1 "$csv" | grep -q "SKU,AssetTag,BIOSVendor,BoardModel,TPM,MACAddress,StorageControllers,ToolVersion"'
t::check "CSV header includes personnel columns" 'head -n1 "$csv" | grep -q "Operator,Validator,MediaSource,MediaDestination"'
t::check "CSV header includes verify columns" 'head -n1 "$csv" | grep -q "Verify,VerifySectors,VerifyResult"'
t::check "CSV header includes RAID column" 'head -n1 "$csv" | grep -q ",RAID"'
hdr_cols="$(head -n1 "$csv" | awk -F, '{print NF}')"
row_cols="$(sed -n '2p' "$csv" | awk -F, '{print NF}')"
t::check "CSV data row column count matches header ($hdr_cols)" '[ "$hdr_cols" = "$row_cols" ]'
t::check "CSV row carries machine profile" 'grep -q "Dell PowerEdge" "$csv" && grep -q "1. Intel Xeon; 2. Intel Xeon" "$csv" && grep -q "NVIDIA T4" "$csv" && grep -q "64 GB" "$csv" && grep -q "CH123" "$csv" && grep -q "4C4C4544-0036-5710-8032-B5C04F433633" "$csv" && grep -q "Administrator Password Status" "$csv"'
t::check "CSV row carries new system fields" 'grep -q "SKU-001" "$csv" && grep -q "CUST-9001" "$csv" && grep -q "Dell 0T7D40" "$csv" && grep -q "9.9.9-test" "$csv" && grep -q "aa:bb:cc:dd:ee:ff" "$csv"'
t::check "CSV row carries personnel fields" 'grep -q "Jane Doe" "$csv" && grep -q "John Roe" "$csv" && grep -q "IT decommissioning" "$csv" && grep -q "resale" "$csv"'
t::check "manifest JSON exists" '[[ -f "$manifest" ]]'
t::check "manifest records bios_lock" 'grep -q "bios_lock" "$manifest"'
t::check "manifest records extended profile" 'grep -q "system_uuid" "$manifest" && grep -q "bios_version" "$manifest" && grep -q "chassis_serial" "$manifest"'
t::check "manifest records version + asset fields" 'grep -q "\"version\": \"9.9.9-test\"" "$manifest" && grep -q "\"sku\": \"SKU-001\"" "$manifest" && grep -q "\"tpm\": \"2.0\"" "$manifest" && grep -q "\"board\": \"Dell 0T7D40\"" "$manifest"'
t::check "manifest records personnel fields" 'grep -q "\"operator\": \"Jane Doe\"" "$manifest" && grep -q "\"validator\": \"John Roe\"" "$manifest" && grep -q "\"media_source\": \"IT decommissioning\"" "$manifest" && grep -q "\"media_destination\": \"resale\"" "$manifest"'

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

# --- CLI flags for the operator/validator/asset/media fields ---
OPERATOR_NAME=""; parse_args --operator "Jane Doe"
t::check "--operator sets OPERATOR_NAME" '[[ "$OPERATOR_NAME" == "Jane Doe" ]]'
VALIDATOR_NAME=""; parse_args --validator="John Roe"
t::check "--validator= sets VALIDATOR_NAME" '[[ "$VALIDATOR_NAME" == "John Roe" ]]'
ASSET_TAG=""; parse_args --asset-tag "TAG-1"
t::check "--asset-tag sets ASSET_TAG" '[[ "$ASSET_TAG" == "TAG-1" ]]'
MEDIA_SOURCE=""; parse_args --media-source "dept A"
t::check "--media-source sets MEDIA_SOURCE" '[[ "$MEDIA_SOURCE" == "dept A" ]]'
MEDIA_DESTINATION=""; parse_args --media-destination "recycle"
t::check "--media-destination sets MEDIA_DESTINATION" '[[ "$MEDIA_DESTINATION" == "recycle" ]]'

rm -rf "${REPORT_DIR%/}"
t::summary
