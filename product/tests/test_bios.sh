#!/usr/bin/env bash
# Tests for the BIOS-lock detector: 36_bios.sh (+ ui::bios_render).
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

# Start every case with all sources disabled; each case re-enables the one
# layer under test. bios::detect resets the state globals itself.
reset_env() {
    BIOS_FA_ROOT="$tmpdir/empty"
    BIOS_HP_WMI_FILE="$tmpdir/empty-hp"
    BIOS_TP_ACPI_FILE="$tmpdir/empty-tp"
    BIOS_DMIDECODE_CMD="__no_such_dmidecode__"
    unset FAKE_DMI24
    rm -rf "$tmpdir/empty" "$tmpdir/empty-hp" "$tmpdir/empty-tp" "$tmpdir/fa"
}
reset_env

check_verdict() {  # <status> <method-substr> [label]
    bios::detect
    t::assert_eq "$1" "$BIOS_PASSWORD_STATUS" "${3:-status}"
    t::assert_contains "$BIOS_DETECTION_METHOD" "$2" "${3:-method}"
}

# Build a fake firmware_attributes tree: <name>=<content> writes both
# is_password_set and current_value for the attribute.
write_fa_tree() {  # <driver> <name>=<content> [<name>=<content> ...]
    local driver="$1" name content; shift
    local dir="$tmpdir/fa/$driver/attributes"
    for pair in "$@"; do
        name="${pair%%=*}"; content="${pair#*=}"
        mkdir -p "$dir/$name"
        printf '%s\n' "$content" > "$dir/$name/is_password_set"
        printf '%s\n' "$content" > "$dir/$name/current_value"
    done
    BIOS_FA_ROOT="$tmpdir/fa"
}

# --- Layer 4: nothing found ---------------------------------------------------
reset_env
check_verdict "UNKNOWN" "NONE" "no sources -> UNKNOWN"

# --- Layer 1: firmware_attributes sysfs --------------------------------------
reset_env
write_fa_tree "dell-wmi-sysman" "SetupPwd=1"
check_verdict "LOCKED" "is_password_set" "sysfs: password set -> LOCKED"

reset_env
write_fa_tree "dell-wmi-sysman" "SetupPwd=0"
check_verdict "UNLOCKED" "firmware_attributes" "sysfs: password not set -> UNLOCKED"

reset_env
write_fa_tree "thinklmi" "AdminPwd=0" "SystemPwd=1"
check_verdict "LOCKED" "SystemPwd" "sysfs: any password set -> LOCKED"

# current_value fallback (no is_password_set): attribute has only current_value.
reset_env
mkdir -p "$tmpdir/fa/hp-bioscfg/attributes/AdminPassword"
printf 'Enabled\n' > "$tmpdir/fa/hp-bioscfg/attributes/AdminPassword/current_value"
BIOS_FA_ROOT="$tmpdir/fa"
check_verdict "LOCKED" "current_value" "sysfs: current_value=Enabled -> LOCKED"

# A password-BYPASS attribute being Enabled must NOT read as a lock.
reset_env
mkdir -p "$tmpdir/fa/x/attributes/PasswordBypass"
printf 'Enabled\n' > "$tmpdir/fa/x/attributes/PasswordBypass/current_value"
BIOS_FA_ROOT="$tmpdir/fa"
check_verdict "UNKNOWN" "NONE" "sysfs: PasswordBypass=Enabled is not a lock"

# Default BIOS_FA_ROOT resolution: prefer the kernel 6.18+ hyphen spelling,
# fall back to the legacy underscore spelling. (Explicit overrides are covered
# by every test above.)
reset_env
mkdir -p "$tmpdir/cls/firmware-attributes"
got="$( ( unset BIOS_FA_ROOT; BIOS_FA_CLASS_BASE="$tmpdir/cls"; source "$ROOT_DIR/src/36_bios.sh" >/dev/null 2>&1; printf '%s' "$BIOS_FA_ROOT" ) )"
t::assert_eq "$tmpdir/cls/firmware-attributes" "$got" "fa default: hyphen spelling preferred (kernel 6.18+)"

got="$( ( unset BIOS_FA_ROOT; BIOS_FA_CLASS_BASE="$tmpdir/cls-missing"; source "$ROOT_DIR/src/36_bios.sh" >/dev/null 2>&1; printf '%s' "$BIOS_FA_ROOT" ) )"
t::assert_eq "$tmpdir/cls-missing/firmware_attributes" "$got" "fa default: underscore fallback (legacy kernel)"

# --- Layer 2: legacy vendor sysfs --------------------------------------------
reset_env
printf '1\n' > "$tmpdir/hp"; BIOS_HP_WMI_FILE="$tmpdir/hp"
check_verdict "LOCKED" "hp-wmi" "legacy: hp-wmi bios_password=1 -> LOCKED"

reset_env
printf 'Admin: enabled\n' > "$tmpdir/tp"; BIOS_TP_ACPI_FILE="$tmpdir/tp"
check_verdict "LOCKED" "thinkpad_acpi" "legacy: thinkpad pws_setting enabled -> LOCKED"

reset_env
printf 'disabled\n' > "$tmpdir/tp"; BIOS_TP_ACPI_FILE="$tmpdir/tp"
check_verdict "UNLOCKED" "thinkpad_acpi" "legacy: thinkpad pws_setting disabled -> UNLOCKED"

# --- Layer 3: SMBIOS Type 24 (via the fake dmidecode on PATH) ----------------
reset_env
BIOS_DMIDECODE_CMD="dmidecode"
export FAKE_DMI24="Administrator Password Status: Enabled"
check_verdict "LOCKED" "SMBIOS Type 24" "smbios: Enabled -> LOCKED"

# Regression: dmidecode indents Type 24 lines with a TAB — the method label
# must be trimmed so the TUI doesn't get a stray tab ("extra spacing").
reset_env
BIOS_DMIDECODE_CMD="dmidecode"
export FAKE_DMI24=$'\tAdministrator Password Status: Enabled'
check_verdict "LOCKED" "SMBIOS Type 24 (Administrator Password Status)" "smbios: tab-indented label is trimmed"

reset_env
BIOS_DMIDECODE_CMD="dmidecode"
export FAKE_DMI24="Power-On Password Status: Disabled"
check_verdict "UNLOCKED" "SMBIOS Type 24" "smbios: Disabled -> UNLOCKED"

# --- Cascade precedence: LOCKED later beats UNLOCKED earlier -----------------
reset_env
write_fa_tree "dell-wmi-sysman" "SetupPwd=0"
BIOS_DMIDECODE_CMD="dmidecode"
export FAKE_DMI24="Administrator Password Status: Enabled"
check_verdict "LOCKED" "SMBIOS Type 24" "cascade: sysfs unlocked but SMBIOS locked -> LOCKED"

# --- ui::bios_render ---------------------------------------------------------
esc_green=$'\033[32m'
esc_red=$'\033[31m'
esc_amber=$'\033[33m'
UI_RUNTIME_VALUE_W=10
UI_COMPLETE_THEME=0

BIOS_PASSWORD_STATUS=UNLOCKED
t::check "bios cell: unlocked is green" '[[ "$(ui::bios_render 1)" == *"$esc_green"* ]]'
BIOS_PASSWORD_STATUS=LOCKED
t::check "bios cell: locked is red" '[[ "$(ui::bios_render 1)" == *"$esc_red"* ]]'
BIOS_PASSWORD_STATUS=UNKNOWN
t::check "bios cell: unknown is amber" '[[ "$(ui::bios_render 1)" == *"$esc_amber"* ]]'
BIOS_PASSWORD_STATUS=UNLOCKED
t::check "bios cell: plain when colour off" '[[ "$(ui::bios_render 0)" != *"$esc_green"* ]]'
t::assert_contains "$(UI_RUNTIME_VALUE_W=20 ui::bios_render 0)" "Unlocked" "bios cell: label present"

t::summary
