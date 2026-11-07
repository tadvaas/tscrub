#!/usr/bin/env bash
# Tests for the HP Replicated Setup module: 37_hp_repsetup.sh.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

# Every case needs an executable fake binary (applicable() checks -x) and an
# HP vendor; the CALL_CMD seam then answers for the binary itself.
export HP_REPSETUP_BIN="$tmpdir/fake-hprep"
printf '#!/usr/bin/env bash\nexit 0\n' > "$HP_REPSETUP_BIN"
chmod +x "$HP_REPSETUP_BIN"
export HP_REPSETUP_VENDOR="HP"

LOCKED_DUMP='Admin Password Present
        No
        *Yes
Power-On Password Present
        *No
        Yes
'
UNLOCKED_DUMP='Admin Password Present
        *No
        Yes
Power-On Password Present
        *No
        Yes
'
NO_FIELDS_DUMP='Boot Order
        Legacy
        *UEFI
'

reset_hp() {
    unset HP_REPSETUP_CALL_CMD
    BIOS_PASSWORD_STATUS="UNKNOWN"
    BIOS_DETECTION_METHOD="NONE"
    BIOS_SRC_SEEN=""
    BIOS_SRC_UNREADABLE=""
}

# --- parse: active value is the starred line --------------------------------
t::assert_eq "Yes" "$(hp_repsetup::_parse_active "Admin Password Present" "$LOCKED_DUMP")" \
    "parse: admin active value"
t::assert_eq "No" "$(hp_repsetup::_parse_active "Power-On Password Present" "$LOCKED_DUMP")" \
    "parse: power-on active value"
t::check "parse: missing option yields nothing" \
    '[[ -z "$(hp_repsetup::_parse_active "SATA Controller Mode" "$LOCKED_DUMP")" ]]'

# --- probe: LOCKED ----------------------------------------------------------
reset_hp
fake_hprep() { printf '%s' "$LOCKED_DUMP"; }
export HP_REPSETUP_CALL_CMD=fake_hprep
hp_repsetup::probe; rc=$?
t::assert_eq "1" "$rc" "probe: locked returns 1"
t::assert_eq "LOCKED" "$BIOS_PASSWORD_STATUS" "probe: locked status"
t::assert_contains "$BIOS_DETECTION_METHOD" "hp-repsetup" "probe: locked method"
t::assert_contains "$BIOS_DETECTION_METHOD" "Admin" "probe: locked method names the slot"
t::assert_contains "$BIOS_SRC_SEEN" "hp-repsetup" "probe: locked source recorded"

# --- probe: UNLOCKED evidence -----------------------------------------------
reset_hp
fake_hprep() { printf '%s' "$UNLOCKED_DUMP"; }
export HP_REPSETUP_CALL_CMD=fake_hprep
hp_repsetup::probe; rc=$?
t::assert_eq "2" "$rc" "probe: unlocked returns 2"
t::assert_eq "UNLOCKED" "$BIOS_PASSWORD_STATUS" "probe: unlocked status"
t::assert_contains "$BIOS_DETECTION_METHOD" "hp-repsetup" "probe: unlocked method"

# --- probe: tool run fails -> unreadable source, no verdict ------------------
reset_hp
fake_hprep() { return 1; }
export HP_REPSETUP_CALL_CMD=fake_hprep
hp_repsetup::probe; rc=$?
t::assert_eq "0" "$rc" "probe: unreadable returns 0"
t::assert_eq "UNKNOWN" "$BIOS_PASSWORD_STATUS" "probe: unreadable stays UNKNOWN"
t::assert_contains "$BIOS_SRC_UNREADABLE" "hp-repsetup" "probe: unreadable recorded"

# --- probe: dump without the password fields -> unreadable -------------------
reset_hp
fake_hprep() { printf '%s' "$NO_FIELDS_DUMP"; }
export HP_REPSETUP_CALL_CMD=fake_hprep
hp_repsetup::probe; rc=$?
t::assert_eq "0" "$rc" "probe: no fields returns 0"
t::assert_contains "$BIOS_SRC_UNREADABLE" "no password presence" \
    "probe: no-fields reason recorded"

# --- probe: non-HP vendor -> silent skip, no source recorded -----------------
reset_hp
export HP_REPSETUP_VENDOR="Dell Inc."
export HP_REPSETUP_CALL_CMD=fake_hprep
hp_repsetup::probe; rc=$?
t::assert_eq "0" "$rc" "probe: non-HP returns 0"
t::check "probe: non-HP records no source" \
    '[[ "$BIOS_SRC_SEEN" != *hp-repsetup* ]]'
export HP_REPSETUP_VENDOR="HP"

# --- clear: accepted -> cleared, staged wording ------------------------------
reset_hp
fake_hprep() { printf 'Admin password updated successfully.\n'; }
export HP_REPSETUP_CALL_CMD=fake_hprep
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
hp_repsetup::clear "hunter2"; rc=$?
t::assert_eq "0" "$rc" "clear: accepted returns 0"
t::assert_eq "cleared" "$BIOS_UNLOCK_RESULT" "clear: accepted result"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "staged" \
    "clear: accepted detail says the change is staged until reboot"

# --- clear: wrong password -> failed with a named reason ---------------------
reset_hp
fake_hprep() { printf 'Admin password was not updated: (Access Denied)\n'; }
export HP_REPSETUP_CALL_CMD=fake_hprep
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
hp_repsetup::clear "wrongpw"; rc=$?
t::assert_eq "1" "$rc" "clear: access denied returns 1"
t::assert_eq "failed" "$BIOS_UNLOCK_RESULT" "clear: access denied result"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "wrong password" \
    "clear: access denied reason"

# --- clear: device missing -> failed, names the device -----------------------
reset_hp
fake_hprep() { printf 'Error opening /dev/hpuefi: No such file or directory\n'; }
export HP_REPSETUP_CALL_CMD=fake_hprep
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
hp_repsetup::clear "pw"; rc=$?
t::assert_eq "1" "$rc" "clear: open error returns 1"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "/dev/hpuefi" "clear: open error names device"

# --- clear: unexpected output -> failed with the output ----------------------
reset_hp
fake_hprep() { printf 'something entirely unexpected\n'; }
export HP_REPSETUP_CALL_CMD=fake_hprep
BIOS_UNLOCK_RESULT=""
BIOS_UNLOCK_DETAIL=""
hp_repsetup::clear "pw"; rc=$?
t::assert_eq "1" "$rc" "clear: unexpected output returns 1"
t::assert_contains "$BIOS_UNLOCK_DETAIL" "did not complete" "clear: unexpected detail"

# --- clear: not applicable -> 2, state untouched -----------------------------
reset_hp
export HP_REPSETUP_VENDOR="Lenovo"
BIOS_UNLOCK_RESULT="sentinel"
hp_repsetup::clear "pw"; rc=$?
t::assert_eq "2" "$rc" "clear: non-HP returns 2"
t::assert_eq "sentinel" "$BIOS_UNLOCK_RESULT" "clear: non-HP leaves state alone"
export HP_REPSETUP_VENDOR="HP"

# --- clear: password reaches the tool (arg passthrough) ----------------------
reset_hp
fake_hprep() { printf 'arg1=%s\narg2=%s\narg3=%s\n' "$1" "$2" "$3"; }
export HP_REPSETUP_CALL_CMD=fake_hprep
out="$(HP_REPSETUP_CALL_CMD=fake_hprep hp_repsetup::_run -p "hunter2" -n /)"
t::assert_contains "$out" "arg1=-p" "run: flag passthrough"
t::assert_contains "$out" "arg2=hunter2" "run: password passthrough"
t::assert_contains "$out" "arg3=-n" "run: clear flag passthrough"

t::summary
