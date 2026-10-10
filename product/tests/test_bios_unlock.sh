#!/usr/bin/env bash
# Tests for the BIOS unlock module: 38_bios_unlock.sh.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"

# Deterministic: never let the orphan scan touch the real /sys/devices tree.
export BIOS_FA_ORPHAN_GLOB="$tmpdir/no-orphan/*"

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

# --- HP: password objects live under authentication/; signal is is_enabled ---
hpdir="$tmpdir/hp"
mkdir -p "$hpdir/hp-bioscfg/authentication/Setup Password" \
         "$hpdir/hp-bioscfg/authentication/Power-On Password"
printf 'old' > "$hpdir/hp-bioscfg/authentication/Setup Password/current_password"
printf 'old' > "$hpdir/hp-bioscfg/authentication/Setup Password/new_password"
printf '1'   > "$hpdir/hp-bioscfg/authentication/Setup Password/is_enabled"
printf 'bios-admin' > "$hpdir/hp-bioscfg/authentication/Setup Password/role"
printf 'old' > "$hpdir/hp-bioscfg/authentication/Power-On Password/current_password"
printf 'old' > "$hpdir/hp-bioscfg/authentication/Power-On Password/new_password"
printf '0'   > "$hpdir/hp-bioscfg/authentication/Power-On Password/is_enabled"
printf 'power-on' > "$hpdir/hp-bioscfg/authentication/Power-On Password/role"
BIOS_FA_ROOT="$hpdir"
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
bios_unlock::clear "hpinvent"
t::assert_eq "failed" "$BIOS_UNLOCK_RESULT" "HP auth: is_enabled still 1 -> failed"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "Setup Password" "HP auth: detail names the setup slot"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "hp-bioscfg" "HP auth: detail names the driver"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "still set" "HP auth: detail says still set"
t::assert_eq "hpinvent" "$(cat "$hpdir/hp-bioscfg/authentication/Setup Password/current_password")" "HP auth: current_password written"
t::assert_eq "old" "$(cat "$hpdir/hp-bioscfg/authentication/Power-On Password/current_password")" "HP auth: power-on slot untouched (admin preferred)"

# --- HP auth: is_enabled=0 -> cleared ---------------------------------------
printf '0' > "$hpdir/hp-bioscfg/authentication/Setup Password/is_enabled"
BIOS_UNLOCK_RESULT=""
bios_unlock::clear "hpinvent"
t::assert_eq "cleared" "$BIOS_UNLOCK_RESULT" "HP auth: is_enabled=0 -> cleared"

# --- orphaned device tree (kernel never linked it into the class) ------------
orph="$tmpdir/orphan/devices/hp-bioscfg"
mkdir -p "$orph/authentication/Setup Password"
printf 'old' > "$orph/authentication/Setup Password/current_password"
printf 'old' > "$orph/authentication/Setup Password/new_password"
printf '0'   > "$orph/authentication/Setup Password/is_enabled"
mkdir -p "$tmpdir/empty-class"
BIOS_FA_ROOT="$tmpdir/empty-class"
BIOS_FA_ORPHAN_GLOB="$tmpdir/orphan/devices/*"
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
bios_unlock::clear "hpinvent"
t::assert_eq "cleared" "$BIOS_UNLOCK_RESULT" "orphan root: found + cleared"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "Setup Password" "orphan root: detail names the slot"
t::assert_eq "hpinvent" "$(cat "$orph/authentication/Setup Password/current_password")" "orphan root: password written"

# --- no interface at all -> unsupported with an explanatory detail -----------
BIOS_FA_ROOT="$tmpdir/absent"
BIOS_FA_ORPHAN_GLOB="$tmpdir/absent-orphans/*"
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
bios_unlock::clear "hpinvent"
t::assert_eq "unsupported" "$BIOS_UNLOCK_RESULT" "no interface: unsupported"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "no firmware-attributes device" "no interface: detail explains why"

# --- read-only interface (password object with no write path) ---------------
rodir="$tmpdir/ro"
mkdir -p "$rodir/hp-bioscfg/authentication/Setup Password"
printf 'bios-admin' > "$rodir/hp-bioscfg/authentication/Setup Password/role"
BIOS_FA_ROOT="$rodir"
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
bios_unlock::clear "hpinvent"
t::assert_eq "unsupported" "$BIOS_UNLOCK_RESULT" "read-only interface: unsupported"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "read-only" "read-only interface: detail explains why"

# --- write-error classification (wrong password vs unsupported) -------------
t::assert_contains "$(bios_unlock::_write_error_reason 'sh: printf: write error: Permission denied')" \
    "wrong password" "classify: EACCES (write rejected) -> wrong password"
t::assert_contains "$(bios_unlock::_write_error_reason 'sh: printf: write error: Invalid argument')" \
    "policy" "classify: EINVAL -> password policy"
t::assert_contains "$(bios_unlock::_write_error_reason 'sh: printf: write error: Operation not supported')" \
    "does not support" "classify: EOPNOTSUPP -> firmware does not support"
t::assert_contains "$(bios_unlock::_write_error_reason 'sh: printf: write error: Operation not permitted')" \
    "not permitted" "classify: EPERM -> needs CAP_SYS_ADMIN"
t::assert_contains "$(bios_unlock::_write_error_reason 'sh: /sys/x/is_enabled: Permission denied')" \
    "not writable" "classify: open failure -> attribute not writable"
t::assert_contains "$(bios_unlock::_write_error_reason 'sh: printf: write error: Input/output error')" \
    "Input/output" "classify: unknown errno -> firmware failure text"

# --- _write_attr surfaces a rejected write ----------------------------------
roroot="$tmpdir/roattr"
mkdir -p "$roroot"
: > "$roroot/attr"
chmod 400 "$roroot/attr"
BIOS_UNLOCK_WRITE_ERR=""
if bios_unlock::_write_attr "$roroot/attr" "value"; then wrc=0; else wrc=1; fi
t::assert_eq "1" "$wrc" "_write_attr: unwritable file fails"
t::assert_contains "$BIOS_UNLOCK_WRITE_ERR" "Permission denied" "_write_attr: captures the shell error text"
t::assert_contains "$(bios_unlock::_write_error_reason "$BIOS_UNLOCK_WRITE_ERR")" \
    "not writable" "_write_attr: open failure classified"

# --- the blank new_password value is a newline (drivers strip it) -----------
wcroot="$tmpdir/wc"
mkdir -p "$wcroot/dell-wmi-sysman/attributes/AdminPassword"
printf 'old' > "$wcroot/dell-wmi-sysman/attributes/AdminPassword/current_password"
printf 'old' > "$wcroot/dell-wmi-sysman/attributes/AdminPassword/new_password"
bios_unlock::_write_clear "$wcroot/dell-wmi-sysman/attributes/AdminPassword" "hpinvent"
t::assert_eq "hpinvent" "$(cat "$wcroot/dell-wmi-sysman/attributes/AdminPassword/current_password")" "_write_clear: current_password written"
t::assert_eq "1" "$(wc -c < "$wcroot/dell-wmi-sysman/attributes/AdminPassword/new_password" | awk '{print $1}')" "_write_clear: new_password is 1 byte (newline)"
t::assert_eq "10" "$(od -An -tu1 "$wcroot/dell-wmi-sysman/attributes/AdminPassword/new_password" | awk '{print $1}')" "_write_clear: that byte is LF"

# --- accepted writes + still set -> no clear path, not a wrong password -----
nrp="$tmpdir/noreset"
mkdir -p "$nrp/hp-bioscfg/authentication/Setup Password"
printf 'old' > "$nrp/hp-bioscfg/authentication/Setup Password/current_password"
printf 'old' > "$nrp/hp-bioscfg/authentication/Setup Password/new_password"
printf '1'   > "$nrp/hp-bioscfg/authentication/Setup Password/is_enabled"
BIOS_FA_ROOT="$nrp"
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
bios_unlock::clear "hpinvent"
t::assert_eq "failed" "$BIOS_UNLOCK_RESULT" "no-reset driver: writes accepted -> failed"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "no clear path" "no-reset driver: detail says no clear path"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "cannot be validated" "no-reset driver: detail says password unverifiable"

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
