#!/usr/bin/env bash
set -euo pipefail

# Build and host the tScrub download artifact, and keep the published checksum in sync.
# Usage: bash ops/host-download.sh
# Overrides: TSCRUB_WEB_HOST, TSCRUB_WEB_DOWNLOADS

HOST="${TSCRUB_WEB_HOST:-oxwet@192.168.0.6}"
DEST_DIR="${TSCRUB_WEB_DOWNLOADS:-~/webs/tscrub/downloads}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SSH_OPTS=(-o BatchMode=yes -o StrictHostKeyChecking=accept-new)
VERSION="$(sed -n 's/^SCRIPT_VERSION="\([^"]*\)"/\1/p' "$ROOT/product/src/00_bootstrap.sh")"

echo "==> Building tScrub $VERSION (licence required at boot)"
(cd "$ROOT/product" && make build)

SHA="$(shasum -a 256 "$ROOT/product/build/tscrub.sh" | awk '{print $1}')"
echo "==> SHA-256: $SHA"

if grep -q "tscrub $VERSION" "$ROOT/marketing/docs.html"; then
  echo "WARNING: 'tscrub $VERSION' is already in the release history." >&2
  echo "         If the source changed, bump SCRIPT_VERSION in product/src/00_bootstrap.sh." >&2
fi

echo "==> Uploading to $HOST:$DEST_DIR"
ssh "${SSH_OPTS[@]}" "$HOST" "mkdir -p $DEST_DIR"
scp "${SSH_OPTS[@]}" "$ROOT/product/build/tscrub.sh" "$HOST:$DEST_DIR/tscrub.sh"
ssh "${SSH_OPTS[@]}" "$HOST" "cd $DEST_DIR && sha256sum tscrub.sh > tscrub.sh.sha256"

echo "==> Signing release on server + publishing public key"
ssh "${SSH_OPTS[@]}" "$HOST" "cd $DEST_DIR && openssl pkeyutl -sign -inkey /home/oxwet/webs/tscrub-form/vendor.key -rawin -in tscrub.sh -out tscrub.sh.sig && cp /home/oxwet/webs/tscrub-form/vendor-public-key.pem tscrub.pub"

echo "==> Updating published checksum in marketing/download.html"
perl -0pi -e "s/[0-9a-f]{64}  tscrub\\.sh/$SHA  tscrub.sh/" "$ROOT/marketing/dashboard/licence.html"

echo "==> Redeploying marketing site"
(cd "$ROOT/marketing" && npm run deploy)

echo "==> Done: https://tscrub.com/downloads/tscrub.sh ($VERSION, $SHA, signed)"
