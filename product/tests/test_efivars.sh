#!/usr/bin/env bash
# Tests for the efivarfs helpers + the Secure Boot read (src/00_bootstrap.sh).
#
# Everything runs against a FAKE efivars tree via EFIVARS_BASE, so the suite
# needs no firmware — and, importantly, it can assert the failure modes that
# matter (missing tree, unmountable tree, attribute bytes that must NOT be read
# as the variable's value).
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"
export EFIVARS_BASE="$tmpdir/efivars"
mkdir -p "$EFIVARS_BASE"

# An efivars file is 4 ATTRIBUTE bytes followed by the data. These fixtures use
# deliberately "noisy" attribute bytes so a naive substring match on the od dump
# (which is what the old Secure Boot check did) would return the wrong answer.
SB="SecureBoot-8be4df61-93ca-11d2-aa0d-00e098032b8c"
SETUP="Setup-a04a27f4-df00-4d42-b552-39511302113d"

echo "== efivars::mount =="
EFIVARS_BASE="$tmpdir/absent-tree"
efivars::mount
t::assert_eq "1" "$?" "returns non-zero when the efivars tree is missing"
EFIVARS_BASE="$tmpdir/empty-tree"; mkdir -p "$EFIVARS_BASE"
efivars::mount
t::assert_eq "1" "$?" "returns non-zero when the tree cannot be mounted"
EFIVARS_BASE="$tmpdir/already-mounted"; mkdir -p "$EFIVARS_BASE"
: > "$EFIVARS_BASE/some-var-00000000-0000-0000-0000-000000000000"
efivars::mount
t::assert_eq "0" "$?" "is a no-op when the tree is already populated (idempotent)"
EFIVARS_BASE="$tmpdir/efivars"

echo
echo "== efivars::byte =="
printf '\x07\x01\x00\x00\x00' > "$EFIVARS_BASE/$SB"
t::assert_eq "0" "$(efivars::byte "$EFIVARS_BASE/$SB")" \
    "reads offset 4 — an attribute bit set to 1 is not the value"
printf '\x06\x00\x00\x00\x01' > "$EFIVARS_BASE/$SB"
t::assert_eq "1" "$(efivars::byte "$EFIVARS_BASE/$SB")" "reads 1 when the value byte is 1"
efivars::byte "$EFIVARS_BASE/nope" >/dev/null 2>&1
t::assert_eq "1" "$?" "fails on a missing variable"

echo
echo "== system::secure_boot =="
if command -v mokutil >/dev/null 2>&1; then
    echo "note: mokutil is present on this host, so the variable is not consulted — assertions skipped"
else
    printf '\x06\x00\x00\x00\x01' > "$EFIVARS_BASE/$SB"
    t::assert_eq "Enabled" "$(system::secure_boot)" "Enabled from the firmware variable"
    printf '\x06\x00\x00\x00\x00' > "$EFIVARS_BASE/$SB"
    t::assert_eq "Disabled" "$(system::secure_boot)" "Disabled from the firmware variable"
    rm -f "$EFIVARS_BASE/$SB"
    t::assert_eq "N/A" "$(system::secure_boot)" "N/A when there is no variable and no mokutil"
fi

echo
echo "== efivars::fingerprint / efivars::b64 =="
printf '\x07\x00\x00\x00ABCDEF' > "$EFIVARS_BASE/$SETUP"
sha_expected="$(printf 'ABCDEF' | efivars::_sha16)"
t::assert_eq "Setup:6:$sha_expected" "$(efivars::fingerprint Setup)" \
    "fingerprint reports the DATA size (4 attribute bytes excluded) and a 16-hex sha"
t::check "fingerprint yields nothing for an absent variable" \
    '[[ -z "$(efivars::fingerprint Custom 2>/dev/null)" ]]'
b64="$(efivars::b64 Setup)"
t::assert_eq "$(printf 'ABCDEF' | base64 | tr -d '\n')" "$b64" \
    "b64 is the data only, attribute bytes stripped"
t::assert_eq "ABCDEF" "$(printf '%s' "$b64" | python3 -c 'import base64,sys; sys.stdout.write(base64.b64decode(sys.stdin.read()).decode())')" \
    "b64 round-trips back to the data"

echo
echo "== init =="
t::check "SYS_EFIVARS and SYS_EFIVARS_B64 are declared empty" \
    '[[ -z "${SYS_EFIVARS}" && -z "${SYS_EFIVARS_B64}" ]]'

rm -rf "$tmpdir"
t::summary
