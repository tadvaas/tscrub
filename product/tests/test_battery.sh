#!/usr/bin/env bash
# Tests for the battery capture: battery::capture in 00_bootstrap.sh.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"
export POWER_SUPPLY_DIR="$tmpdir"

# make_bat <dir> <model> <serial> <cap> <status> <cycles> <full> <design> [mode] [voltage-µV]
make_bat() {
    local d="$1" model="$2" serial="$3" cap="$4" status="$5" cycles="$6" full="$7" design="$8" mode="${9:-charge}" voltage="${10:-}"
    mkdir -p "$d/BAT0"
    printf '%s' "$model"  > "$d/BAT0/model_name"
    printf '%s' "$serial" > "$d/BAT0/serial_number"
    printf '%s' "$cap"    > "$d/BAT0/capacity"
    printf '%s' "$status" > "$d/BAT0/status"
    printf '%s' "$cycles" > "$d/BAT0/cycle_count"
    [[ -n "$voltage" ]] && printf '%s' "$voltage" > "$d/BAT0/voltage_min_design"
    if [[ "$mode" == "energy" ]]; then
        printf '%s' "$full"   > "$d/BAT0/energy_full"
        printf '%s' "$design" > "$d/BAT0/energy_full_design"
    else
        printf '%s' "$full"   > "$d/BAT0/charge_full"
        printf '%s' "$design" > "$d/BAT0/charge_full_design"
    fi
}

# 1. HP quirk: full == design → health OMITTED (no misleading 100%).
make_bat "$tmpdir" "Primary" "34637 2018/09/09" "14" "Charging" "0" "2895000" "2895000"
battery::capture
t::assert_eq "Primary SN=34637 2018/09/09 @ 14% [Charging]" "$SYS_BATTERY" \
    "battery: full==design omits health"

# 2. Real degradation: full < design → health + cycles shown.
make_bat "$tmpdir" "LGC-LGC3.65" "49551" "87" "Discharging" "120" "4520" "5000"
battery::capture
t::assert_eq "LGC-LGC3.65 SN=49551 @ 87% (health 90%) (120 cycles) [Discharging]" "$SYS_BATTERY" \
    "battery: full<design shows health + cycles"

# 3. energy_* (µWh) preferred over charge_* (µAh); Wh derived directly.
make_bat "$tmpdir" "XPS" "" "50" "Charging" "0" "45000000" "60000000" energy
battery::capture
t::assert_eq "XPS @ 50% (health 75%) (full 45.0 Wh) [Charging]" "$SYS_BATTERY" \
    "battery: energy_* used, health 75% + Wh"

# 4. No battery present → N/A.
rm -rf "$tmpdir"/BAT0
battery::capture
t::assert_eq "N/A" "$SYS_BATTERY" "battery: no battery → N/A"

# 5. HP quirk WITH nominal voltage: full==design → no health, but Wh shown.
make_bat "$tmpdir" "Primary" "34637 2018/09/09" "14" "Charging" "0" "2895000" "2895000" charge "11550000"
battery::capture
t::assert_eq "Primary SN=34637 2018/09/09 @ 14% (full 33.4 Wh) [Charging]" "$SYS_BATTERY" \
    "battery: full==design omits health but shows Wh"

rm -rf "$tmpdir"
t::summary
