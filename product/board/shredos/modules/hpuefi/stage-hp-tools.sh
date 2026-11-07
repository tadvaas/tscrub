#!/usr/bin/env bash
# Stage HP's hp-repsetup binary (and optionally hp-flash) into the rootfs
# overlay, from the official HP softpaq. Run from the Buildroot top directory,
# like build-into-overlay.sh:
#
#     cd ~/shredos.x86_64
#     board/shredos/modules/hpuefi/stage-hp-tools.sh
#
# Why a fetch script and not a committed binary: HP distributes the tools
# "as a courtesy, free of charge, AS-IS" (softpaq README.txt). Fetching the
# unmodified softpaq at image-build time keeps the archive byte-identical to
# HP's publication (MD5-pinned below) and keeps third-party binaries out of
# the repository. The AS-IS disclaimer must be carried into the image (see
# the notice printed at the end).
#
# The hpuefi kernel MODULE is separate: it is GPLv2 and is vendored as source
# in this directory; build-into-overlay.sh builds it automatically.
set -eu

if [ ! -d output/build ] || [ ! -f Makefile ] || [ ! -d board/shredos ]; then
    echo "run this from the Buildroot top directory (it needs output/build, Makefile, board/)" >&2
    exit 2
fi

SP_URL="https://ftp.hp.com/pub/softpaq/sp171001-171500/sp171387.tgz"
SP_MD5="1da80330f3697bbb4fcebbd5e06cabb4"   # sp171387, v3.26, 2026-01-15
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

command -v curl >/dev/null 2>&1 || { echo "Missing required command: curl" >&2; exit 1; }
command -v tar  >/dev/null 2>&1 || { echo "Missing required command: tar" >&2; exit 1; }

echo "fetching $SP_URL"
curl -fsSL -o "$WORK/sp171387.tgz" "$SP_URL"

SUM="$(md5sum "$WORK/sp171387.tgz" 2>/dev/null | awk '{print $1}' \
       || md5 -q "$WORK/sp171387.tgz")"
if [ "$SUM" != "$SP_MD5" ]; then
    echo "MD5 mismatch on the downloaded softpaq: got $SUM, want $SP_MD5" >&2
    echo "HP may have re-published the archive; update SP_MD5 only after re-verifying the contents." >&2
    exit 1
fi
echo "md5 ok: $SUM"

tar xzf "$WORK/sp171387.tgz" -C "$WORK"
# The inner archive carries hp-flash + hp-repsetup binaries for several distros;
# the tgz ships one set of executables (Ubuntu-compatible builds work on the
# appliance's glibc — verified live).
INNER="$WORK/non-rpms/hp-flash-3.26_x86_64.tgz"
[ -f "$INNER" ] || { echo "inner archive not found in the softpaq: $INNER" >&2; exit 1; }
tar xzf "$INNER" -C "$WORK"

# The archive's top-level hp-repsetup is a distro-detecting wrapper around the
# per-distro builds; the appliance has no /etc/os-release story the wrapper
# expects, so stage the single glibc-compatible build directly (u2404 build is
# byte-identical to u2204 and was verified live on the appliance's glibc 2.42).
BIN_SRC="$WORK/hp-flash-3.26_x86_64/builds/hp-repsetup.u2404"
[ -x "$BIN_SRC" ] || { echo "hp-repsetup.u2404 not found in the inner archive" >&2; exit 1; }

# Same dual-destination pattern as build-into-overlay.sh: the overlay that the
# image build consumes, and the live target tree for a same-tree build.
for dest in "board/shredos/fsoverlay/opt/tscrub/bin" \
            "output/target/opt/tscrub/bin"; do
    case "$dest" in
        output/target/*) [ -d output/target ] || continue ;;
    esac
    install -d "$dest"
    install -m 0755 "$BIN_SRC" "$dest/hp-repsetup"
    echo "staged $dest/hp-repsetup"
done

echo
echo "Staged hp-repsetup into opt/tscrub/bin/."
echo
echo "NOTICE — include HP's terms with the image:"
echo "  hp-repsetup is provided by HP Development Company, L.P. as a courtesy,"
echo "  free of charge, 'AS-IS', with no warranty of any kind and no obligation"
echo "  on HP to maintain or support it (softpaq sp171387 README.txt)."
echo
echo "Then rebuild the image so the binary is included:"
echo "    make"
