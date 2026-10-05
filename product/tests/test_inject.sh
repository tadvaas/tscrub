#!/usr/bin/env bash
# Tests for the MSDM key-injection helpers: 46_inject_key.sh.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

KEY="BNKRT-THFMG-46DFH-V4Q7M-FGDGP"

# Build a synthetic 85-byte MSDM table: "MSDM" sig, length 0x55, rev 0x03,
# a placeholder key at offset 56.
build_table() {  # <path>
    dd if=/dev/zero of="$1" bs=85 count=1 2>/dev/null
    printf 'MSDM' | dd of="$1" bs=1 count=4 seek=0 conv=notrunc 2>/dev/null
    printf '\x55' | dd of="$1" bs=1 count=1 seek=4 conv=notrunc 2>/dev/null
    printf '\x03' | dd of="$1" bs=1 count=1 seek=8 conv=notrunc 2>/dev/null
    printf '%s' 'XXXXX-XXXXX-XXXXX-XXXXX-XXXXX' | dd of="$1" bs=1 count=29 seek=56 conv=notrunc 2>/dev/null
}

key_at_56() { dd if="$1" bs=1 count=29 skip=56 2>/dev/null; }

sum85() {  # <path> -> decimal sum mod 256
    local f="$1" i b s=0
    for (( i = 0; i < 85; i++ )); do
        b="$(dd if="$f" bs=1 count=1 skip=$i 2>/dev/null | od -An -tu1 | tr -d ' ')"
        s=$(( (s + b) % 256 ))
    done
    printf '%s' "$s"
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

# --- patch ---
f="$tmpdir/table.bin"
build_table "$f"
msdm::patch_key "$f" 56 "$KEY"; rc=$?
t::assert_eq "0" "$rc" "patch: returns 0"
t::assert_eq "$KEY" "$(key_at_56 "$f")" "patch: key lands at offset 56"

# --- checksum ---
msdm::fix_checksum "$f" 0; rc=$?
t::assert_eq "0" "$rc" "checksum: returns 0"
t::assert_eq "0" "$(sum85 "$f")" "checksum: 85-byte table sums to 0 mod 256"

t::summary