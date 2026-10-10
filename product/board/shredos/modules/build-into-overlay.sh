#!/bin/sh
# Build the tScrub out-of-tree kernel modules and install them into the rootfs
# overlay, so the next image build carries them.
#
# Run from the Buildroot top directory — the same place you run `make`:
#
#     cd ~/shredos.x86_64
#     board/shredos/modules/build-into-overlay.sh
#
# Why an overlay install and not a Buildroot package: this kernel is builtin-only
# and tScrub only needs the module at runtime (the BIOS-unlock flow insmods it on
# demand), so keeping it out of the kernel tree means no kernel patch and no
# 19-minute kernel rebuild when the module changes.
#
# IMPORTANT: the module must be rebuilt whenever the kernel is rebuilt — vermagic
# and symbol versions are checked at load time, and a stale .ko simply refuses to
# load. Running this script from build_tscrub.sh (see the note at the bottom)
# keeps that automatic.
#
# Verify a published image with:
#     modinfo /lib/modules/$(uname -r)/extra/hp_biospw.ko     # on the appliance
set -eu

if [ ! -d output/build ] || [ ! -f Makefile ] || [ ! -d board/shredos ]; then
    echo "run this from the Buildroot top directory (it needs output/build, Makefile, board/)" >&2
    exit 2
fi

KERNEL_TREE="output/build/linux-6.18"
CROSS="$(pwd)/output/host/bin/x86_64-buildroot-linux-gnu-"

if [ ! -d "$KERNEL_TREE" ]; then
    echo "no kernel tree at $KERNEL_TREE — build the kernel first" >&2
    exit 2
fi
if [ ! -f "$KERNEL_TREE/Module.symvers" ]; then
    echo "no Module.symvers in $KERNEL_TREE — the kernel has not been built here" >&2
    exit 2
fi
if [ ! -x "${CROSS}gcc" ]; then
    echo "no cross toolchain at ${CROSS}gcc" >&2
    exit 2
fi

KVER="$(make -s -C "$KERNEL_TREE" kernelrelease 2>/dev/null || echo 6.18.0)"
echo "kernel release: $KVER"

for src in board/shredos/modules/*/; do
    name="$(basename "$src")"
    [ -f "$src/Makefile" ] || continue
    echo "== building $name =="
    make -C "$KERNEL_TREE" M="$(pwd)/$src" ARCH=x86_64 CROSS_COMPILE="$CROSS" modules

    # The overlay must NOT contain a real lib/ directory: Buildroot's
    # target-finalize insists that /lib is missing or a relative symlink to
    # usr/lib (merged /usr), and refuses the whole overlay otherwise. The
    # appliance reaches these files through that symlink as /lib/modules/….
    for dest in "board/shredos/fsoverlay/usr/lib/modules/$KVER/extra" \
                "output/target/lib/modules/$KVER/extra"; do
        case "$dest" in
            output/target/*) [ -d output/target/lib ] || continue ;;
        esac
        install -d "$dest"
        install -m 0644 "$src$name.ko" "$dest/"
    done
    printf 'installed %s into the overlay for %s\n' "$name.ko" "$KVER"
    modinfo "$src$name.ko" 2>/dev/null | grep -E '^(vermagic|description)' || true
done

echo
echo "Done. Rebuild the image so the modules are included:"
echo "    make          # re-assembles the rootfs + image from the overlay"
echo "build_tscrub.sh already does this — see the call to this script there."
echo
echo "Check a built image with:"
echo "    ls -l board/shredos/fsoverlay/usr/lib/modules/$KVER/extra/"
