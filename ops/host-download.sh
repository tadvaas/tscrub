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

if grep -q "tscrub $VERSION" "$ROOT/marketing/site/docs.html"; then
  echo "WARNING: 'tscrub $VERSION' is already in the release history." >&2
  echo "         If the source changed, bump SCRIPT_VERSION in product/src/00_bootstrap.sh." >&2
fi

echo "==> Uploading to $HOST:$DEST_DIR"
ssh "${SSH_OPTS[@]}" "$HOST" "mkdir -p $DEST_DIR"
scp "${SSH_OPTS[@]}" "$ROOT/product/build/tscrub.sh" "$HOST:$DEST_DIR/tscrub.sh"
ssh "${SSH_OPTS[@]}" "$HOST" "cd $DEST_DIR && sha256sum tscrub.sh > tscrub.sh.sha256"

echo "==> Signing release on server + publishing public key"
ssh "${SSH_OPTS[@]}" "$HOST" "cd $DEST_DIR && openssl pkeyutl -sign -inkey /home/oxwet/webs/tscrub-form/vendor.key -rawin -in tscrub.sh -out tscrub.sh.sig && cp /home/oxwet/webs/tscrub-form/vendor-public-key.pem tscrub.pub"

echo "==> Updating script version + checksum in download-manifest.json"
python3 - "$VERSION" "$SHA" <<'PY'
import json, sys
version, sha = sys.argv[1], sys.argv[2]
path = "marketing/server/download-manifest.json"
m = json.load(open(path))
m["script"]["version"] = version
m["script"]["sha256"] = sha
with open(path, "w") as f:
    json.dump(m, f, indent=2)
    f.write("\n")
PY

echo "==> Publishing backend (manifest) + copying to /downloads/"
(cd "$ROOT/marketing" && npm run deploy:server)
ssh "${SSH_OPTS[@]}" "$HOST" "cp ~/webs/tscrub-form/download-manifest.json $DEST_DIR/manifest.json"

echo "==> Done: https://tscrub.com/downloads/tscrub.sh ($VERSION, $SHA, signed)"
