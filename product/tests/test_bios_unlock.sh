#!/usr/bin/env bash
# Tests for the BIOS unlock module: 38_bios_unlock.sh.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"

# Deterministic: never let the orphan scan touch the real /sys/devices tree.
export BIOS_FA_ORPHAN_GLOB="$tmpdir/no-orphan/*"

# Deterministic: the WMI layer must not fire just because the machine running
# the suite happens to have the HP BIOS-settings WMI block. Cases that exercise
# it opt back in with their own BIOS_WMI_DEVICE_DIR.
export BIOS_WMI_DEVICE_DIR="$tmpdir/no-wmi"

t::assert_eq "shutdown,reboot,wipe,bios_unlock" "$REMOTE_COMMANDS" \
    "remote: this build declares every command type it can execute"
t::assert_eq "hunter2" "$(remote::_claimed_password '{"id":7,"password":"x","password_b64":"aHVudGVyMg=="}')" \
    "claim: base64 password decodes"
t::assert_eq "legacy" "$(remote::_claimed_password '{"id":9,"password":"legacy"}')" \
    "claim: plain password still accepted"
t::assert_eq 'a"b\' "$(remote::_claimed_password '{"id":11,"password_b64":"YSJiXA=="}')" \
    "claim: base64 survives special chars"
t::check "claim: no password -> empty" \
    '[[ -z "$(remote::_claimed_password "{\"pending\":false}")" ]]'

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

# --- parse: legacy password field fallback ----------------------------------

# --- parse: base64 survives quotes/backslash --------------------------------

# --- parse: no command -> empty ---------------------------------------------

# --- WMI layer: HP machines expose no password object at all ----------------
# HP publishes no firmware-attributes password object on any generation we have
# tested, so Layers 1-2 have nothing to write. The firmware's SetBiosSetting WMI
# method still accepts the three-element frame, and that is what clears the
# password (verified on hardware — research/bios-unlock/18-…). These cases drive
# the state machine with a fake transport; the frame encoder itself is covered
# byte-exactly by board/shredos/modules/hp_biospw.
wmi_dir="$tmpdir/wmi"
# The device name is the real one: UPPERCASE hex, and the last field is …B4B8D5E.
# v1.11.46 matched neither (lowercase in the pattern, and a typo'd …B4D8D5E), which
# is how a false 'cleared' reached a shipped image — so this name is the test.
mkdir -p "$wmi_dir/devices/1F4C91EB-DC5C-460B-951D-C7CB9B4B8D5E-6"
cat > "$wmi_dir/call.sh" <<'EOS'
#!/bin/sh
# argv: name value [credential]. The status for each successive call comes from
# a sequence file, so a case can script the whole exchange.
seq="$WMI_FAKE_SEQ"
[ -f "$seq" ] || exit 1
line="$(head -n 1 "$seq")"
[ -n "$line" ] || exit 1
tail -n +2 "$seq" > "$seq.next" && mv "$seq.next" "$seq"
printf '%s' "$line"
EOS
chmod +x "$wmi_dir/call.sh"
export BIOS_WMI_DEVICE_DIR="$wmi_dir/devices"
export BIOS_WMI_CALL_CMD="$wmi_dir/call.sh"
export WMI_FAKE_SEQ="$wmi_dir/seq"
BIOS_FA_ROOT="$tmpdir/wmi-fa"
mkdir -p "$BIOS_FA_ROOT"

wmi_case() {   # statuses, in call order: before-probe, clear[, after-probe[, repeat]]
    printf '%s\n' "$@" > "$WMI_FAKE_SEQ"
    BIOS_UNLOCK_RESULT=""
    BIOS_UNLOCK_DETAIL=""
    bios_unlock::clear "hpinvent"
}

# Passwords set, clear accepted, and the probe that was refused is now accepted.
wmi_case 0x06 0x00 0x00
t::assert_eq "cleared" "$BIOS_UNLOCK_RESULT" "wmi: corroborated clear -> cleared"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "now accepted" "wmi: detail cites the probe"

# Clear accepted, probe still refused, but a second identical request is no
# longer accepted because its password authenticates against nothing.
wmi_case 0x06 0x00 0x06 0x05
t::assert_eq "cleared" "$BIOS_UNLOCK_RESULT" "wmi: repeat 0x05 -> cleared"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "no longer authenticates" "wmi: detail cites the repeat"

# Clear accepted but nothing corroborates it — must NOT be reported as cleared.
wmi_case 0x06 0x00 0x06 0x00
t::assert_eq "failed" "$BIOS_UNLOCK_RESULT" "wmi: nothing corroborates -> failed"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "could not be confirmed" "wmi: detail stays honest"

# The firmware refused the credential.
wmi_case 0x06 0x06
t::assert_eq "failed" "$BIOS_UNLOCK_RESULT" "wmi: 0x06 -> failed"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "wrong password" "wmi: 0x06 says wrong password"

# Nothing is refused to begin with, so there is no password to remove.
wmi_case 0x00
t::assert_eq "cleared" "$BIOS_UNLOCK_RESULT" "wmi: already unlocked -> cleared"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "no administrator password was set" \
    "wmi: already-unlocked detail"

# An unknown setting name, an invalid value, and a transport that never answers.
wmi_case 0x06 0x04
t::assert_contains "$BIOS_UNLOCK_DETAIL" "does not recognise" "wmi: 0x04 is an unknown setting"
wmi_case 0x06 0x05
t::assert_contains "$BIOS_UNLOCK_DETAIL" "invalid value" "wmi: 0x05 is an invalid value"
wmi_case 0x06
t::assert_contains "$BIOS_UNLOCK_DETAIL" "did not complete" "wmi: no status -> transport failure"

# A machine without the interface falls through to the generic message.
BIOS_WMI_DEVICE_DIR="$tmpdir/wmi/devices-empty"
mkdir -p "$BIOS_WMI_DEVICE_DIR"
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
bios_unlock::clear "hpinvent"
t::assert_eq "unsupported" "$BIOS_UNLOCK_RESULT" "wmi: not applicable -> unsupported"

# The sysfs verdict must not be able to over-claim: with the transport present
# and the firmware still refusing privileged writes, a 'cleared' from the sysfs
# path is reported as failed. The device gate is deliberately LEFT OFF here —
# that is precisely the v1.11.46 scenario (gate wrong, transport available),
# which is how this false positive reached a shipped image.
sysfs_fa="$tmpdir/overclaim"
mkdir -p "$sysfs_fa/hp-bioscfg/authentication/Setup Password"
printf 'x' > "$sysfs_fa/hp-bioscfg/authentication/Setup Password/current_password"
printf 'x' > "$sysfs_fa/hp-bioscfg/authentication/Setup Password/new_password"
printf '0' > "$sysfs_fa/hp-bioscfg/authentication/Setup Password/is_enabled"
BIOS_FA_ROOT="$sysfs_fa"
BIOS_WMI_DEVICE_DIR="$tmpdir/wmi/devices-empty"
printf '0x06\n' > "$WMI_FAKE_SEQ"
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
bios_unlock::clear "hpinvent"
t::assert_eq "failed" "$BIOS_UNLOCK_RESULT" "sysfs verdict corroborated -> failed"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "not trustworthy" "sysfs verdict: detail explains"

# The frame's field separator must be a real NUL: 14 + NUL + 9 + NUL + 17 = 42.
t::assert_eq "42" \
    "$(printf '%s\000%s\000%s' "Setup Password" '<utf-16/>' '<utf-16/>hpinvent' | wc -c | tr -d '[:space:]')" \
    "wmi: fields are NUL-separated"

unset BIOS_WMI_CALL_CMD

rm -rf "$tmpdir"
t::summary
