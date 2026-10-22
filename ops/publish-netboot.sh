#!/usr/bin/env bash
set -euo pipefail

# Publish the netboot kernel, SIGNED.
#
# Why this exists: the kernel that iPXE serves must carry a Secure Boot
# signature, because the netboot chain is shim -> kernel. `output/images/bzImage`
# is the RAW build output and is UNSIGNED — the signature is only applied to the
# copy inside the ISO by `board/shredos/sign_secureboot.sh`. Copying the raw
# output into the netboot directory therefore hands shim an unsigned kernel, and
# every Secure Boot machine stops at a MOK prompt (a signature adds 1,568 bytes,
# which is the quickest way to spot it).
#
# Usage: bash ops/publish-netboot.sh [--check]
#
# Overrides: TSCRUB_BUILD_HOST, TSCRUB_BUILD_DIR, TSCRUB_NETBOOT_DIR, TSCRUB_MOK_DIR

HOST="${TSCRUB_BUILD_HOST:-oxwet@192.168.0.6}"
BUILD_DIR="${TSCRUB_BUILD_DIR:-~/shredos.x86_64}"
DEST_DIR="${TSCRUB_NETBOOT_DIR:-~/webs/lan/ipxe/tscrub}"
MOK_DIR="${TSCRUB_MOK_DIR:-~/ipxe-sb}"
SSH_OPTS=(-o BatchMode=yes -o StrictHostKeyChecking=accept-new)
MODE="${1:-publish}"

ssh "${SSH_OPTS[@]}" "$HOST" bash -s -- "$BUILD_DIR" "$DEST_DIR" "$MOK_DIR" "$MODE" <<'REMOTE'
set -euo pipefail
BUILD_DIR="$1"; DEST_DIR="$2"; MOK_DIR="$3"; MODE="$4"

SRC="$BUILD_DIR/output/images/bzImage"
DST="$DEST_DIR/bzImage"
KEY="$MOK_DIR/vendor.key"
CRT="$MOK_DIR/vendor.crt"

verify() {   # <file> — fail unless a signature from our MOK cert is present
    command -v sbverify >/dev/null 2>&1 || { echo "sbverify not installed" >&2; return 2; }
    local out subject
    out="$(sbverify --list "$1" 2>&1)" || {
        echo "UNSIGNED: $1 (shim will refuse it or prompt for MOK)" >&2
        return 1
    }
    grep -q "image signature issuers" <<<"$out" || {
        echo "UNSIGNED: $1 (shim will refuse it or prompt for MOK)" >&2
        return 1
    }
    subject="$(sed -n 's/^ - //p' <<<"$out" | head -n1)"
    printf 'signed: %s\n  issuer: %s\n  size:   %s bytes\n' \
        "$1" "$subject" "$(stat -c %s "$1")"
}

if [[ "$MODE" == "--check" ]]; then
    verify "$DST"
    exit $?
fi

[[ -f "$SRC" ]] || { echo "missing build output: $SRC" >&2; exit 1; }
[[ -f "$KEY" && -f "$CRT" ]] || { echo "missing MOK key/cert in $MOK_DIR" >&2; exit 1; }

cp -f "$SRC" "$DST"
sbsign --key "$KEY" --cert "$CRT" --output "$DST.signed" "$DST" >/dev/null
mv -f "$DST.signed" "$DST"
chmod 644 "$DST"

verify "$DST"
sha256sum "$DST"
REMOTE
