#!/usr/bin/env bash
# Tests for the BIOS unlock module: 38_bios_unlock.sh.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"

t::assert_eq "https://tscrub.com/api/bios/unlock/pending" \
    "$(TSCRUB_UPLOAD_URL='https://tscrub.com/api/reports' bios_unlock::pending_endpoint)" \
    "unlock pending endpoint: built-in reports URL"
t::assert_eq "https://host.example/api/bios/unlock/result" \
    "$(TSCRUB_UPLOAD_URL='https://host.example' bios_unlock::result_endpoint)" \
    "unlock result endpoint: bare host"

# --- clear via firmware_attributes sysfs ------------------------------------
BIOS_FA_ROOT="$tmpdir/fa"
mkdir -p "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword"
printf 'old' > "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword/current_password"
printf 'old' > "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword/new_password"
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
bios_unlock::clear "hunter2"
t::assert_eq "cleared" "$BIOS_UNLOCK_RESULT" "unlock: sysfs clear reports cleared"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "AdminPassword" "unlock: detail names the attribute"
t::assert_eq "hunter2" "$(cat "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword/current_password")" "unlock: current_password written"
t::assert_eq "" "$(cat "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword/new_password")" "unlock: new_password cleared"

# --- no writable attribute -> unsupported -----------------------------------
BIOS_FA_ROOT="$tmpdir/none"
mkdir -p "$BIOS_FA_ROOT"
bios_unlock::clear "hunter2"
t::assert_eq "unsupported" "$BIOS_UNLOCK_RESULT" "unlock: no attribute -> unsupported"

rm -rf "$tmpdir"
t::summary
