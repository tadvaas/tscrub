#!/usr/bin/env bash
# Tests for the OA3 key-injection helpers: 46_inject_key.sh.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

KEY="BNKRT-THFMG-46DFH-V4Q7M-FGDGP"
NEW="PC3JK-2FNK9-RQV7M-FPG4K-T83GT"

# Build a synthetic HP_OA3 UEFI variable image: 4-byte attrs, flags, a 29-byte
# length field, then the key (mirrors the real HP_OA3 layout we reverse-engineered).
build_oa3() {  # <path> <key>
    printf '\x07\x00\x00\x00' > "$1"
    printf '\x01\x00\x00\x00\x00\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00\x00' >> "$1"
    printf '\x1d\x00\x00\x00' >> "$1"
    printf '%s' "$2" >> "$1"
}

# --- normalize ---
t::assert_eq "$KEY" "$(msdm::normalize_key "  bnkrt-thfmg-46dfh-v4q7m-fgdgp  ")" \
    "normalise: uppercase + strip whitespace"
t::assert_eq "$KEY" "$(msdm::normalize_key "$KEY")" \
    "normalise: accepts a real key containing N (no strict base-24)"
msdm::normalize_key "BOGUS"; rc=$?
t::assert_eq "1" "$rc" "normalise: rejects malformed key"
msdm::normalize_key "BNKRT-THFMG-46DFH-V4Q7M-FGDG"; rc=$?
t::assert_eq "1" "$rc" "normalise: rejects wrong-length key"

# --- replace_bytes ---
f="$tmpdir/oa3.bin"
build_oa3 "$f" "$KEY"
msdm::replace_bytes "$f" "$KEY" "$NEW"; rc=$?
t::assert_eq "0" "$rc" "replace_bytes: returns 0"
t::assert_eq "$NEW" "$(tail -c 29 "$f")" "replace_bytes: new key is the 29-byte tail"
t::assert_eq "53" "$(wc -c < "$f" | tr -d ' ')" "replace_bytes: total length preserved (53)"
# attrs + flags + length field unchanged (first 24 bytes identical to the original)
build_oa3 "$tmpdir/oa3_orig.bin" "$KEY"
t::assert_eq "$(head -c 24 "$tmpdir/oa3_orig.bin")" "$(head -c 24 "$f")" \
    "replace_bytes: attrs + flags + length untouched"
t::assert_eq "$NEW" "$(grep -aoE '[A-Z0-9]{5}(-[A-Z0-9]{5}){4}' "$f")" \
    "replace_bytes: buffer now contains the new key"

# replace_bytes with absent key must fail
msdm::replace_bytes "$f" "ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ" "$NEW"; rc=$?
t::assert_eq "1" "$rc" "replace_bytes: absent key returns 1"

t::summary