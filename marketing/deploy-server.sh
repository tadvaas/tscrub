#!/usr/bin/env bash
set -euo pipefail

# Deploy the backend PHP app to the tScrub form server over SSH/rsync.
# Override with env vars: TSCRUB_FORM_HOST, TSCRUB_FORM_DEST
HOST="${TSCRUB_FORM_HOST:-oxwet@192.168.0.6}"
DEST="${TSCRUB_FORM_DEST:-~/webs/tscrub-form}"

cd "$(dirname "$0")/server"

# Only sync app code + example config. Deliberately NO --delete: the server
# also holds runtime-only files (tcpdf/, certificates/, sign.crt, sign.key,
# vendor.key, config.json) that must never be removed by a deploy.
rsync -avz \
  certify.php submit.php verify.php \
  api.php auth.php db.php http.php mail.php reports_lib.php \
  migrate.php seed-admin.php schema.sql \
  sendmail.py issue_licence.py \
  config.example.json \
  -e "ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new" \
  "$HOST:$DEST/"

echo "Backend deploy complete."
