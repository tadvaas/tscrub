#!/usr/bin/env bash
# 10 — a drive reporting > 75C is flagged in the TUI (red temperature) and the
# high TempC is recorded in the report. We fake only smartctl (a dead-simple
# attribute dump) so the real wipe pipeline still runs end-to-end.
set -euo pipefail
source "$(dirname "$0")/../lib.sh"

IP="$TEST_IP"
VMID="$(next_vmid)"
REPORTS="$IMAGE_DIR/e2e-overheat"
mkdir -p "$REPORTS"
trap 'vm_destroy "$VMID"' EXIT

debian_vm_create "$VMID" tscrub-overheat "$IP"
disk_sata "$VMID" 0 2
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"

vm_ssh "$IP" 'mkdir -p /tmp/tscrub/out /tmp/tscrub/fakebin' >/dev/null 2>&1 || true
scp -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
    "$IMAGE_DIR/tscrub.sh" "$TEST_LICENCE" "$VM_USER@$IP:/tmp/tscrub/" >/dev/null

# Fake smartctl: always reports PASSED health with Temperature_Celsius=78.
vm_ssh "$IP" 'cat > /tmp/tscrub/fakebin/smartctl <<"EOF"
#!/bin/sh
cat <<"SM"
smartctl 7.2 2020-12-30 r5155 [x86_64-linux] (local build)
Copyright (C) 2002-20, Bruce Allen, Christian Franke, www.smartmontools.org

SMART overall-health self-assessment test result: PASSED

ID# ATTRIBUTE_NAME          FLAG     VALUE WORST THRESH TYPE      UPDATED  WHEN_FAILED RAW_VALUE
194 Temperature_Celsius     0x0022   078   050   000    Old_age   Always       -       78
SM
EOF
chmod +x /tmp/tscrub/fakebin/smartctl'

printf '24680\nC\n' | ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -tt \
    "$VM_USER@$IP" "cd /tmp/tscrub && sudo env PATH=/tmp/tscrub/fakebin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin ./tscrub.sh --dry-run --license /tmp/tscrub/test.lic --output /tmp/tscrub/out 2>&1" \
    | tee "$REPORTS/run.log"

scp -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
    "$VM_USER@$IP:/tmp/tscrub/out/tScrub_*" "$REPORTS/" >/dev/null 2>&1 || true

# TempC is column 14 (0-indexed 13) of the CSV. Assert it equals 78.
tempc="$(python3 - <<PY
import csv, glob
with open(glob.glob("$REPORTS/tScrub_*.csv")[0]) as f:
    r = list(csv.reader(f))[1]
    print(r[13])
PY
)"
[[ "$tempc" == "78" ]] && echo "PASS: overheated drive recorded TempC=78" || { echo "FAIL: expected TempC=78, got '$tempc'" >&2; exit 1; }
