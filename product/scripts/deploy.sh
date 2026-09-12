#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${TSCRUB_ENV_FILE:-$ROOT_DIR/.config}"

if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  . "$ENV_FILE"
  set +a
fi

BUILD_DIR="$ROOT_DIR/build"

TSCRUB_DEPLOY_HOST="${TSCRUB_DEPLOY_HOST:?TSCRUB_DEPLOY_HOST is required (set in .config or env)}"
TSCRUB_DEPLOY_USER="${TSCRUB_DEPLOY_USER:?TSCRUB_DEPLOY_USER is required (set in .config or env)}"
TSCRUB_DEPLOY_DOCROOT="${TSCRUB_DEPLOY_DOCROOT:?TSCRUB_DEPLOY_DOCROOT is required (set in .config or env)}"
TSCRUB_DOMAIN="${TSCRUB_DOMAIN:?TSCRUB_DOMAIN is required (set in .config or env)}"

CHECK_ONLY=0
if [[ "${1:-}" == "--check" ]]; then
  CHECK_ONLY=1
fi

command -v ssh  >/dev/null 2>&1 || { echo "Missing required command: ssh"  >&2; exit 1; }
command -v scp  >/dev/null 2>&1 || { echo "Missing required command: scp"  >&2; exit 1; }
command -v curl >/dev/null 2>&1 || { echo "Missing required command: curl" >&2; exit 1; }
[[ -d "$BUILD_DIR" ]] || { echo "Missing build directory: $BUILD_DIR" >&2; exit 1; }

BUILD_FILES=()
while IFS= read -r f; do
  BUILD_FILES+=("$f")
done < <(find "$BUILD_DIR" -type f | LC_ALL=C sort)

[[ "${#BUILD_FILES[@]}" -gt 0 ]] || {
  echo "No files found in build directory: $BUILD_DIR" >&2
  exit 1
}

SSH_TARGET="${TSCRUB_DEPLOY_USER}@${TSCRUB_DEPLOY_HOST}"
SSH_OPTS=(
  -o BatchMode=yes
  -o ConnectTimeout=10
  -o ControlMaster=auto
  -o ControlPersist=60
  -o ControlPath="/tmp/tscrub-deploy-%r@%h:%p"
)

echo "Deploy target: ${SSH_TARGET}:${TSCRUB_DEPLOY_DOCROOT}"
echo "Public URLs:"
for src in "${BUILD_FILES[@]}"; do
  rel="${src#${BUILD_DIR}/}"
  echo "  http://${TSCRUB_DOMAIN}/${rel}"
done

if [[ "$CHECK_ONLY" -eq 1 ]]; then
  echo "Local preflight checks passed (${#BUILD_FILES[@]} file(s) staged)."
  exit 0
fi

for src in "${BUILD_FILES[@]}"; do
  rel="${src#${BUILD_DIR}/}"
  remote_path="${TSCRUB_DEPLOY_DOCROOT}/${rel}"
  remote_dir="$(dirname "$remote_path")"

  echo "Uploading: ${rel}"
  ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "mkdir -p '$remote_dir'"
  scp -C "${SSH_OPTS[@]}" "$src" "${SSH_TARGET}:${remote_path}"
done

for src in "${BUILD_FILES[@]}"; do
  rel="${src#${BUILD_DIR}/}"
  curl -fsSI "http://${TSCRUB_DOMAIN}/${rel}" >/dev/null
done

echo "Deploy complete."
