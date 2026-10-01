#!/usr/bin/env bash
# Tests for the display panel capture: edid::parse + display::capture.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"

write_edid() {
    local f="$1" b
    shift
    : > "$f"
    for b in "$@"; do
        printf "\\$(printf '%03o' "$b")" >> "$f"
    done
}

# Assemble a 128-byte EDID: AUO, 1920x1080, 31x17 cm, year 2018.
local_bytes=()
for ((i = 0; i < 128; i++)); do local_bytes[$i]=0; done
local_bytes[0]=0; local_bytes[1]=255; local_bytes[2]=255; local_bytes[3]=255
local_bytes[4]=255; local_bytes[5]=255; local_bytes[6]=255; local_bytes[7]=0
local_bytes[8]=6; local_bytes[9]=175            # "AUO"
local_bytes[17]=28                              # year 1990+28 = 2018
local_bytes[21]=31; local_bytes[22]=17          # 31 x 17 cm
local_bytes[56]=128; local_bytes[58]=112        # 1920 horizontal active
local_bytes[59]=56;  local_bytes[61]=64         # 1080 vertical active
write_edid "$tmpdir/edid.bin" "${local_bytes[@]}"

# edid::parse directly.
t::assert_eq 'AUO 1920x1080 13.9" (2018)' "$(edid::parse "$tmpdir/edid.bin")" \
    "edid: manufacturer/resolution/size/year"

# display::capture picks the eDP connector.
mkdir -p "$tmpdir/drm/card0-eDP-1"
cp "$tmpdir/edid.bin" "$tmpdir/drm/card0-eDP-1/edid"
SYS_DRM_DIR="$tmpdir/drm" display::capture
t::assert_eq 'AUO 1920x1080 13.9" (2018)' "$SYS_DISPLAY" \
    "display: eDP connector parsed"

# No EDID → empty.
SYS_DRM_DIR="$tmpdir/nodrm" display::capture
t::assert_eq "" "$SYS_DISPLAY" "display: no edid → empty"

rm -rf "$tmpdir"
t::summary
