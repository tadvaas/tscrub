#!/usr/bin/env bash
set -euo pipefail

# Deploy the built site to the tScrub web server over SSH/rsync.
# Override with env vars: TSCRUB_WEB_HOST, TSCRUB_WEB_DEST
HOST="${TSCRUB_WEB_HOST:-oxwet@192.168.0.6}"
DEST="${TSCRUB_WEB_DEST:-~/webs/tscrub}"
SSH_OPTS=(-o BatchMode=yes -o StrictHostKeyChecking=accept-new)

cd "$(dirname "$0")"

echo "Building site..."
npm run build

echo "Ensuring remote directory exists ($DEST)..."
ssh "${SSH_OPTS[@]}" "$HOST" "mkdir -p $DEST"

echo "Syncing dist/ to $HOST:$DEST ..."
# --exclude downloads/ so the hosted tScrub artifact survives --delete.
rsync -avz --delete --exclude downloads/ -e "ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new" dist/ "$HOST:$DEST/"

echo "Deploy complete."
