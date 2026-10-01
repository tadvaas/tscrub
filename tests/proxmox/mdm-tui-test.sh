#!/usr/bin/env bash
# tScrub MDM Runtime-panel TUI test (v1.8.10): run the standalone script in a
# Debian VM with the Autopilot check enabled and a local mock MDM server, then
# verify the Runtime panel shows proper ASCII labels (Pending -> Queued ->
# Unlocked) instead of a single dot.
set -euo pipefail

cd /root/tscrub-tests
source ./lib.sh
source ./config.sh

IP="$TEST_IP"
VMID="$(next_vmid)"
OUT="/tmp/tscrub-mdm-tui.raw"
MOCK_LOG="/tmp/tscrub-mdm-mock.log"

trap 'vm_destroy "$VMID"' EXIT

# 1. Mock MDM dashboard (autopilot POST resolves immediately to Unlocked).
pkill -f tscrub-mdm-mock.py 2>/dev/null || true
sleep 1
cat > /tmp/tscrub-mdm-mock.py <<'PY'
import http.server, json, socketserver

class H(http.server.BaseHTTPRequestHandler):
    def _send(self, obj):
        # Compact JSON (no spaces) — matches json_encode() output from the real
        # dashboard, which the appliance parses with a sed-based field extractor.
        body = json.dumps(obj, separators=(',', ':')).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)
    def do_POST(self):
        n = int(self.headers.get('Content-Length', 0) or 0)
        data = self.rfile.read(n) if n else b''
        print('POST', self.path, data.decode(errors='replace')[:300], flush=True)
        if self.path.startswith('/api/mdm/autopilot'):
            self._send({"ok": True, "status": "done", "verdict": "unlocked",
                        "source": "live", "label": "Unlocked"})
        else:
            self._send({"ok": True, "count": 1})
    def do_GET(self):
        print('GET', self.path, flush=True)
        self._send({"ok": True, "status": "done", "verdict": "unlocked",
                    "source": "live", "label": "Unlocked"})
    def log_message(self, *a):
        pass

socketserver.TCPServer(("0.0.0.0", 8080), H).serve_forever()
PY
python3 /tmp/tscrub-mdm-mock.py > "$MOCK_LOG" 2>&1 &
MOCK_PID=$!
trap 'kill "$MOCK_PID" 2>/dev/null; vm_destroy "$VMID"' EXIT
echo "== mock server pid $MOCK_PID"

# 2. Fresh Debian VM with one supported SATA disk so device::discover succeeds
#    (the boot disk is virtio-blk and is intentionally ignored), plus a DMI
#    system serial + UUID (QEMU VMs otherwise report N/A and the MDM check
#    correctly skips — we need identifiers for a real check).
debian_vm_create "$VMID" tscrub-mdm-tui "$IP"
disk_sata "$VMID" 0 2
qm set "$VMID" --smbios1 "serial=5CG8171LZ8,manufacturer=HP,product=EliteBook820G3,uuid=4C4C4544-0036-5710-8032-B5C04F433633"
vm_start "$VMID"
vm_wait_ssh "$VMID" "$IP"
vm_prepare "$IP"
vm_push_files "$IP"

# 3. Run with the Autopilot check on, against the local mock dashboard.
#    --autonuke skips the triage screen (no selection screen, no post-run
#    prompt); --dry-run --simulate-running-eta=1 keeps the UI up ~60s so the
#    MDM worker has time to resolve (no real wipe).
ssh -i "$SSH_KEY" -o BatchMode=yes -o StrictHostKeyChecking=no \
    -o UserKnownHostsFile=/dev/null -tt \
    "$VM_USER@$IP" \
    "cd /tmp/tscrub && sudo sh -c 'stty cols 120 rows 50 2>/dev/null; TERM=xterm-256color exec env TSCRUB_API_TOKEN=mdm-vm-test-token TSCRUB_UPLOAD_URL=http://192.168.0.85:8080/api/reports ./tscrub.sh --license /tmp/tscrub/test.lic --cocid 12345 --autonuke --autopilotcheck --dry-run --simulate-running-eta=1 --output /tmp/tscrub/out' 2>&1" \
    > "$OUT" 2>&1 || true

echo "== captured $(wc -c < "$OUT") bytes"

# 4. Strip ANSI and inspect the MDM cell values.
sed 's/\x1b\[[0-9;]*[A-Za-z]//g; s/\x1b[()][A-Za-z0-9]//g; s/\x1b[=>]//g; s/\r//g' "$OUT" > /tmp/tscrub-mdm-tui.clean

echo "== MDM cell values seen (unique) =="
grep -o 'MDM:[^|]*' /tmp/tscrub-mdm-tui.clean | sed 's/^MDM://; s/[[:space:]]*$//' | sort -u

echo "== mock server log =="
cat "$MOCK_LOG"

echo "== PASS/FAIL =="
if grep -q 'MDM: *Pending' /tmp/tscrub-mdm-tui.clean; then
    echo "PASS: saw 'Pending' placeholder"
else
    echo "FAIL: 'Pending' placeholder not seen" >&2
fi
if grep -q 'MDM: *Queued' /tmp/tscrub-mdm-tui.clean; then
    echo "PASS: saw 'Queued' (early publish)"
else
    echo "FAIL: 'Queued' not seen" >&2
fi
if grep -q 'MDM: *Unlocked' /tmp/tscrub-mdm-tui.clean; then
    echo "PASS: saw server label 'Unlocked'"
else
    echo "FAIL: server label 'Unlocked' not seen" >&2
fi
if grep -q 'MDM: *\.$' /tmp/tscrub-mdm-tui.clean; then
    echo "FAIL: single-dot MDM cell still present" >&2
else
    echo "PASS: no single-dot MDM cell"
fi
