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

# --- slot priority: prefer AdminPassword over SystemPassword ----------------
BIOS_FA_ROOT="$tmpdir/prio"
mkdir -p "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/SystemPassword"
mkdir -p "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword"
printf 'old' > "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/SystemPassword/current_password"
printf 'old' > "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/SystemPassword/new_password"
printf 'old' > "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword/current_password"
printf 'old' > "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword/new_password"
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
bios_unlock::clear "hunter2"
t::assert_eq "cleared" "$BIOS_UNLOCK_RESULT" "slot priority: cleared"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "AdminPassword" "slot priority: chose AdminPassword"
t::assert_eq "hunter2" "$(cat "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword/current_password")" "slot priority: admin written"
t::assert_eq "old" "$(cat "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/SystemPassword/current_password")" "slot priority: system untouched"

# --- re-verify: slot still reads as set after write -> failed ---------------
BIOS_FA_ROOT="$tmpdir/stillset"
mkdir -p "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword"
printf 'old' > "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword/current_password"
printf 'old' > "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword/new_password"
printf '1' > "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword/is_password_set"
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
bios_unlock::clear "hunter2"
t::assert_eq "failed" "$BIOS_UNLOCK_RESULT" "re-verify: still set -> failed"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "still set" "re-verify: detail names still-set"

# --- re-verify: is_password_set reads cleared -> cleared ---------------------
BIOS_FA_ROOT="$tmpdir/clearedok"
mkdir -p "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword"
printf 'old' > "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword/current_password"
printf 'old' > "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword/new_password"
printf '0' > "$BIOS_FA_ROOT/dell-wmi-sysman/attributes/AdminPassword/is_password_set"
BIOS_UNLOCK_RESULT=""
bios_unlock::clear "hunter2"
t::assert_eq "cleared" "$BIOS_UNLOCK_RESULT" "re-verify: is_password_set=0 -> cleared"

# --- parse: base64 password field -------------------------------------------
t::assert_eq $'7\nhunter2' "$(bios_unlock::_parse_pending '{"id":7,"password":"x","password_b64":"aHVudGVyMg=="}')" "parse: base64 password decodes"

# --- parse: legacy password field fallback ----------------------------------
t::assert_eq $'9\nlegacy' "$(bios_unlock::_parse_pending '{"id":9,"password":"legacy"}')" "parse: legacy password fallback"

# --- parse: base64 survives quotes/backslash --------------------------------
t::assert_eq $'11\na"b\\' "$(bios_unlock::_parse_pending '{"id":11,"password_b64":"YSJiXA=="}')" "parse: base64 survives special chars"

# --- parse: no command -> empty ---------------------------------------------
t::check "parse: no id -> empty" '[[ -z "$(bios_unlock::_parse_pending "{\"pending\":false}")" ]]'

rm -rf "$tmpdir"
t::summary
