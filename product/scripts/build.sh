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
  "$SRC_DIR/50_report.sh"; do
  [[ -f "$part" ]] || {
    echo "Missing source part: $part" >&2
    exit 1
  }
  cat "$part" >> "$OUT_FILE"
  printf "\n" >> "$OUT_FILE"
done

# Embed sedutil-cli binary as base64 so the built script is self-contained
SEDUTIL_BIN="$PAYLOAD_DIR/sedutil-cli"
[[ -f "$SEDUTIL_BIN" ]] || { echo "Missing payload: $SEDUTIL_BIN" >&2; exit 1; }
printf 'SEDUTIL_PAYLOAD_B64="%s"\n\n' "$(base64 < "$SEDUTIL_BIN" | tr -d '\n')" >> "$OUT_FILE"

# Embed the vendor licence public key when present (Team/Enterprise builds).
# Provide payload/vendor-public-key.pem (PEM public key) to enable licence
# verification; omit it for community builds (self-signed reports only).
VENDOR_PUB="$PAYLOAD_DIR/vendor-public-key.pem"
if [[ -f "$VENDOR_PUB" ]]; then
  printf 'LICENSE_VENDOR_PUBLIC_KEY_B64="%s"\n\n' "$(base64 < "$VENDOR_PUB" | tr -d '\n')" >> "$OUT_FILE"
fi

# Entrypoint (must be last)
[[ -f "$SRC_DIR/99_entrypoint.sh" ]] || { echo "Missing source part: $SRC_DIR/99_entrypoint.sh" >&2; exit 1; }
cat "$SRC_DIR/99_entrypoint.sh" >> "$OUT_FILE"
printf "\n" >> "$OUT_FILE"

if bash -c 'coproc X { cat; }' >/dev/null 2>&1; then
  bash -n "$OUT_FILE"
else
  echo "Skipping bash -n: local bash does not support 'coproc' syntax used by tscrub.sh" >&2
fi

echo "Build complete: $OUT_FILE"
