#!/usr/bin/env bash
# One-time setup for the tScrub Proxmox test harness. Runs ON the Proxmox host.
set -euo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ -f "$HARNESS_DIR/config.sh" ]] || { echo "copy config.example.sh -> config.sh first" >&2; exit 1; }
source "$HARNESS_DIR/config.sh"

mkdir -p "$ISO_DIR" "$IMAGE_DIR"

echo "==> tScrub appliance ISO"
# The stable symlink (tscrub-appliance.iso) is no longer shipped — resolve the
# versioned ISO URL from the published manifest instead.
ISO_URL="$(curl -fsSL https://tscrub.com/downloads/manifest.json \
  | grep -o '"url": "/downloads/[^"]*\.iso"' | head -1 \
  | sed 's/"url": "//; s/"$//')"
[[ -n "$ISO_URL" ]] || { echo "ERROR: could not resolve appliance ISO URL from manifest" >&2; exit 1; }
ISO_FILE="$ISO_DIR/$(basename "$ISO_URL")"
if [[ ! -f "$ISO_FILE" ]]; then
    curl -fsSL --retry 2 -o "$ISO_FILE" "https://tscrub.com$ISO_URL"
fi
ls -la "$ISO_FILE"

echo "==> Debian cloud image"
if [[ ! -f "$IMAGE_DIR/$DEBIAN_IMG_NAME" ]]; then
    curl -fsSL --retry 2 -o "$IMAGE_DIR/$DEBIAN_IMG_NAME" "$DEBIAN_IMG_URL"
fi
ls -la "$IMAGE_DIR/$DEBIAN_IMG_NAME"

echo "==> tScrub script"
curl -fsSL --retry 2 -o "$IMAGE_DIR/tscrub.sh" "$TSCRUB_URL"
chmod 755 "$IMAGE_DIR/tscrub.sh"
grep -m1 'SCRIPT_VERSION=' "$IMAGE_DIR/tscrub.sh" || true

echo "==> Test licence"
if [[ ! -f "$TEST_LICENCE" ]]; then
    echo "ERROR: $TEST_LICENCE missing — generate it (see README) and re-run." >&2
    exit 1
fi

echo "==> SSH key for test VMs"
if [[ ! -f "$IMAGE_DIR/test" ]]; then
    ssh-keygen -t ed25519 -f "$IMAGE_DIR/test" -N "" -q
fi
[[ -f "$SSH_PUBKEY" ]] || cp "$IMAGE_DIR/test.pub" "$SSH_PUBKEY"

echo "==> Prepare image (root SSH + static IP, no cloud-init)"
"$HARNESS_DIR/prepare-image.sh"

echo "==> FTP server (for the 06_upload_ftp scenario)"
command -v python3 >/dev/null && python3 -c 'import pyftpdlib' 2>/dev/null \
    || { apt-get install -y python3-pyftpdlib >/dev/null 2>&1 || pip3 install pyftpdlib >/dev/null 2>&1 || echo "WARN: pyftpdlib not available; 06_upload_ftp will fail"; }

echo
echo "Setup complete. Run scenarios with:  ./run.sh all"
