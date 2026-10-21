#!/usr/bin/env bash
# Regenerate the server test's CSV fixture from the appliance's own report
# writer, so the server tests parse the REAL header instead of a hand-typed
# copy that can drift.
#
#   marketing/tests/overwrite_parse_test.php   reads
#   marketing/tests/fixtures/report-v1.11.73.csv
#
# Run this after changing anything in `report::csv` (`product/src/50_report.sh`),
# then re-run the server test. The fixture is a header + one row, so it is small
# enough to review in a diff; the row's values are fixed on purpose (dodshort,
# 6 passes, nwipe 0.41, overwrite-sample at 5%) because the server test asserts
# them.
#
# Usage:  bash product/tests/tools/gen_report_fixture.sh
source "$(dirname "${BASH_SOURCE[0]}")/../lib.sh"
t::setup_env
t::source_src

# Minimal machine + report state (mirrors product/tests/test_report.sh).
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
SCRIPT_VERSION="v1.11.73"
OPERATOR_NAME="Jane Doe"; VALIDATOR_NAME="John Roe"
ASSET_TAG="CUST-9001"; MEDIA_SOURCE="IT decommissioning"; MEDIA_DESTINATION="resale"
BIOS_PASSWORD_STATUS="LOCKED"; BIOS_DETECTION_METHOD="SMBIOS Type 24 (Administrator Password Status)"
REPORT_DIR="$(mktemp -d)/"
REPORT_KEY_DIR="$(mktemp -d)"
VERIFY_NONCE="nonce-fixture"

declare -Ag secsize
devices=(sda)
devrow=()
d=sda
devrow[$d.model]="Samsung SSD 870 EVO 1TB"; devrow[$d.serial]="S6PTNX0T900001"
devrow[$d.size]="1 TB"; devrow[$d.bus]="SATA"; devrow[$d.type]="SSD"
devrow[$d.class]="CLEAR"; devrow[$d.device]="sda"
devrow[$d.method]="DoD Short"; devrow[$d.cert]="OVERWRITE"
devrow[$d.status]="COMPLETED"; devrow[$d.ts]="2026-10-21 10:00:00"
devrow[$d.standard]="dodshort"; devrow[$d.passes]="6"; devrow[$d.eraser]="nwipe 0.41"
devrow[$d.verify_technique]="overwrite-sample"; devrow[$d.verify_coverage]="5"
devrow[$d.verify_result]="passed"; devrow[$d.verify_sectors]="40"
devrow[$d.firmware]="SVT02B6Q"; devrow[$d.sector_size]="512"; devrow[$d.sectors]="1953525168"
secsize[sda]=512

csv="$(report::csv)"
if [[ -z "$csv" || ! -f "$csv" ]]; then
    echo "gen_report_fixture: report::csv produced no file" >&2
    exit 1
fi

out="$ROOT_DIR/../marketing/tests/fixtures/report-v1.11.73.csv"
mkdir -p "$(dirname "$out")"
head -n 1 "$csv" > "$out"
sed -n '2p' "$csv" >> "$out"
printf 'wrote %s (%s columns)\n' "$out" "$(head -n 1 "$out" | tr ',' '\n' | wc -l | tr -d ' ')"
