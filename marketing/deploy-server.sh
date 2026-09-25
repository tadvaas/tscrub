#!/usr/bin/env bash
set -euo pipefail

# Deploy the backend PHP app to the tScrub form server over SSH/rsync.
# Override with env vars: TSCRUB_FORM_HOST, TSCRUB_FORM_DEST
HOST="${TSCRUB_FORM_HOST:-oxwet@192.168.0.6}"
DEST="${TSCRUB_FORM_DEST:-~/webs/tscrub-form}"

cd "$(dirname "$0")/server"

# Sync the full app code. Deliberately NO --delete: the server also holds
# runtime-only files (certificates/, sign.crt, sign.key, vendor.key,
# config.json) that must never be removed by a deploy, so we exclude them
# rather than mirroring the directory.
rsync -avz \
  --exclude '__pycache__/' \
  --exclude 'config.json' \
  --exclude 'certificates/' \
  --exclude 'sign.crt' \
  --exclude 'sign.key' \
  --exclude 'vendor.key' \
  ./ \
  -e "ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new" \
  "$HOST:$DEST/"

echo "Backend deploy complete."
