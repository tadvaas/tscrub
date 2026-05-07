#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC_DIR="$ROOT_DIR/src"
PAYLOAD_DIR="$ROOT_DIR/payload"
BUILD_DIR="$ROOT_DIR/build"
OUT_FILE="$BUILD_DIR/tscrub.sh"

[[ -d "$SRC_DIR" ]] || {
  echo "Missing source directory: $SRC_DIR" >&2
  exit 1
}

[[ -d "$PAYLOAD_DIR" ]] || {
  echo "Missing payload directory: $PAYLOAD_DIR" >&2
  exit 1
}

mkdir -p "$BUILD_DIR"
find "$BUILD_DIR" -mindepth 1 -exec rm -rf {} +
: > "$OUT_FILE"

for part in \
  "$SRC_DIR/00_bootstrap.sh" \
  "$SRC_DIR/10_main.sh" \
  "$SRC_DIR/20_ui.sh" \
  "$SRC_DIR/30_device.sh" \
  "$SRC_DIR/31_device_nvme.sh" \
  "$SRC_DIR/32_device_scsi.sh" \
  "$SRC_DIR/33_device_ata.sh" \
  "$SRC_DIR/40_table.sh" \
  "$SRC_DIR/50_report.sh" \
  "$SRC_DIR/99_entrypoint.sh"; do
  [[ -f "$part" ]] || {
    echo "Missing source part: $part" >&2
    exit 1
  }
  cat "$part" >> "$OUT_FILE"
  printf "\n" >> "$OUT_FILE"
done

if bash -c 'coproc X { cat; }' >/dev/null 2>&1; then
  bash -n "$OUT_FILE"
else
  echo "Skipping bash -n: local bash does not support 'coproc' syntax used by tscrub.sh" >&2
fi
cp -R "$PAYLOAD_DIR"/. "$BUILD_DIR"/

echo "Build complete: $OUT_FILE"
