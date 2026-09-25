# =============================================================================
# SMART
# =============================================================================
# Capture SMART/health attributes before and after a wipe so a device's resale
# value can be assessed straight from the report (and the TUI). Read-only and
# best-effort: drives that don't expose SMART leave the fields empty (rendered
# as "-" in the TUI and empty in the CSV). Pre-wipe values land under
# "$dev.smart*"; post-wipe values under "$dev.smart_post*" so a diff is
# derivable from the same CSV.

# Echo the first integer found in a value (strips commas, spaces, units).
# Handles "41 C", "1,234", "100%", "0x..."-style inputs.
smart::num() {
    local v="${1:-}"
    v="${v//,/}"
    v="${v// /}"
    if [[ "$v" =~ ^0[xX]([0-9A-Fa-f]+) ]]; then
        printf '%d' "$((16#${BASH_REMATCH[1]}))"
    elif [[ "$v" =~ ^-?[0-9]+ ]]; then
        printf '%s' "${BASH_REMATCH[0]}"
    fi
}

# Run a command with a time limit so a dead drive can't stall the boot.
# Output is captured via a temp file (never a pipe) so an orphaned child of a
# killed command cannot keep the read side blocked. Uses `timeout` when
# available, else a background process + watchdog kill. Returns the command's
# exit status, normalised to 124 on timeout.
smart::run() {
    local secs="${1:-10}"
    shift
    local tmp rc
    tmp="$(mktemp /tmp/tscrub-smart.XXXXXX)" 2>/dev/null
    if [[ -z "$tmp" ]]; then
        # /tmp unwritable: keep the timeout guarantee by running under
        # `timeout` directly (output is still captured by the caller's $(...)).
        if command -v timeout >/dev/null 2>&1; then
            timeout -s KILL "$secs" "$@" 2>/dev/null
            rc=$?
            [[ "$rc" == "124" || "$rc" == "137" || "$rc" == "143" ]] && rc=124
            return "$rc"
        fi
        "$@" 2>/dev/null
        return
    fi

    if command -v timeout >/dev/null 2>&1; then
        timeout -s KILL "$secs" "$@" >"$tmp" 2>/dev/null
        rc=$?
        [[ "$rc" == "124" || "$rc" == "137" || "$rc" == "143" ]] && rc=124
    else
        "$@" >"$tmp" 2>/dev/null &
        local pid=$!
        ( sleep "$secs"; kill -9 "$pid" 2>/dev/null ) &
        local wd=$!
        wait "$pid" 2>/dev/null
        rc=$?
        # 137 = killed by our watchdog -> timeout.
        [[ "$rc" == "137" ]] && rc=124
        kill "$wd" 2>/dev/null
        wait "$wd" 2>/dev/null
    fi

    cat "$tmp" 2>/dev/null
    rm -f "$tmp"
    return "$rc"
}

# Echo the raw value (last column) of a SMART attribute from smartctl output.
smart::ata_attr() {
    local attrs="$1" name="$2"
    awk -v n="$name" '$2==n {print $NF}' <<<"$attrs"
}

# Echo the first non-empty raw value among the given attribute names.
smart::ata_attr_any() {
    local attrs="$1" v
    shift
    local name
    for name in "$@"; do
        v="$(smart::ata_attr "$attrs" "$name")"
        [[ -n "$v" ]] && { printf '%s' "$v"; return 0; }
    done
    return 1
}

# Echo one field from `nvme smart-log` text output (label: value), keyed exactly.
smart::nvme_field() {
    local log="$1" name="$2"
    awk -v n="$name" -F: '
        { k=$1; sub(/^[ \t]+/, "", k); sub(/[ \t]+$/, "", k) }
        k==n { v=$2; sub(/^[ \t]+/, "", v); sub(/[ \t]+$/, "", v); print v; exit }
    ' <<<"$log"
}

# Extract the SMART attribute table from `smartctl -a` output (header -> blank).
smart::ata_attr_table() {
    awk '
        /ATTRIBUTE_NAME/ { f=1; next }
        f && /^[[:space:]]*$/ { exit }
        f { print }
    ' <<<"$1"
}

smart::capture_ata() {
    local dev="$1" sfx="$2"
    local out attrs remain wear lbas rc

    if ! command -v smartctl >/dev/null 2>&1; then
        devrow["$dev.smart${sfx}"]="UNSUP"
        return
    fi

    out="$(smart::run "$SMART_TIMEOUT" smartctl -a /dev/$dev)"
    rc=$?
    # smartctl always prints a banner, so "empty stdout" never happens — retry
    # the bridge hint only when the attribute table failed to parse.
    if [[ -z "$(smart::ata_attr_table "$out")" ]] && (( rc != 124 )); then
        out="$(smart::run "$SMART_TIMEOUT" smartctl -a -d sat /dev/$dev)"
    fi
    [[ -n "$out" ]] || return

    # Overall health from the self-assessment line (SMART support unavailable
    # is a distinct, explicit state).
    if grep -qi 'result: FAILED' <<<"$out"; then
        devrow["$dev.smart${sfx}"]="FAIL"
    elif grep -qi 'result: PASSED' <<<"$out"; then
        devrow["$dev.smart${sfx}"]="PASS"
    elif grep -qiE 'SMART support is:[[:space:]]*Unavailable|Device lacks SMART capability' <<<"$out"; then
        devrow["$dev.smart${sfx}"]="UNSUP"
    fi

    attrs="$(smart::ata_attr_table "$out")"
    [[ -n "$attrs" ]] || return

    devrow["$dev.temp${sfx}"]="$(smart::num "$(smart::ata_attr_any "$attrs" Temperature_Celsius Airflow_Temperature_Cel)")"
    devrow["$dev.poh${sfx}"]="$(smart::num "$(smart::ata_attr "$attrs" Power_On_Hours)")"
    devrow["$dev.cycles${sfx}"]="$(smart::num "$(smart::ata_attr "$attrs" Power_Cycle_Count)")"
    devrow["$dev.realloc${sfx}"]="$(smart::num "$(smart::ata_attr "$attrs" Reallocated_Sector_Ct)")"

    # Percent used for SATA SSDs: prefer Percent_Lifetime_Remain, else a
    # wearout indicator that counts down from 100. Vendor-specific and
    # approximate; left empty when neither is present.
    remain="$(smart::num "$(smart::ata_attr "$attrs" Percent_Lifetime_Remain)")"
    wear="$(smart::num "$(smart::ata_attr_any "$attrs" Media_Wearout_Indicator Wear_Leveling_Count)")"
    if [[ -n "$remain" ]] && (( remain >= 0 && remain <= 100 )); then
        devrow["$dev.pct_used${sfx}"]="$(( 100 - remain ))"
    elif [[ -n "$wear" ]] && (( wear >= 0 && wear <= 100 )); then
        devrow["$dev.pct_used${sfx}"]="$(( 100 - wear ))"
    fi

    devrow["$dev.spare${sfx}"]="$(smart::num "$(smart::ata_attr_any "$attrs" Available_Reservd_Space Available_Spare)")"

    # Total_LBAs_Written is in 512-byte sectors on most ATA drives -> TBW in TB
    # (approximate).
    lbas="$(smart::num "$(smart::ata_attr "$attrs" Total_LBAs_Written)")"
    if [[ -n "$lbas" ]]; then
        devrow["$dev.tbw${sfx}"]="$(awk -v l="$lbas" 'BEGIN{printf "%.2f", l*512/1e12}')"
    fi
}

smart::capture_nvme() {
    local dev="$1" sfx="$2"
    local log cw duw

    if ! command -v nvme >/dev/null 2>&1; then
        devrow["$dev.smart${sfx}"]="UNSUP"
        return
    fi

    log="$(smart::run "$SMART_TIMEOUT" nvme smart-log /dev/$dev)"
    [[ -n "$log" ]] || return

    # critical_warning == 0 means healthy.
    cw="$(smart::num "$(smart::nvme_field "$log" critical_warning)")"
    if [[ -n "$cw" ]]; then
        if [[ "$cw" == "0" ]]; then
            devrow["$dev.smart${sfx}"]="PASS"
        else
            devrow["$dev.smart${sfx}"]="FAIL"
        fi
    fi

    devrow["$dev.temp${sfx}"]="$(smart::num "$(smart::nvme_field "$log" temperature)")"
    devrow["$dev.poh${sfx}"]="$(smart::num "$(smart::nvme_field "$log" power_on_hours)")"
    devrow["$dev.cycles${sfx}"]="$(smart::num "$(smart::nvme_field "$log" power_cycles)")"
    devrow["$dev.pct_used${sfx}"]="$(smart::num "$(smart::nvme_field "$log" percentage_used)")"
    devrow["$dev.spare${sfx}"]="$(smart::num "$(smart::nvme_field "$log" available_spare)")"
    # Reallocated sectors are an ATA concept; NVMe leaves this empty.

    # data_units_written is reported in thousands of 512-byte units -> TBW in TB
    # (approximate).
    duw="$(smart::num "$(smart::nvme_field "$log" data_units_written)")"
    if [[ -n "$duw" ]]; then
        devrow["$dev.tbw${sfx}"]="$(awk -v d="$duw" 'BEGIN{printf "%.2f", d*512000/1e12}')"
    fi
}

# Capture SMART for every discovered drive. Usage: smart::capture_all [pre|post]
smart::capture_all() {
    local phase="${1:-pre}"
    local sfx=""
    [[ "$phase" == "post" ]] && sfx="_post"
    local dev
    for dev in "${devices[@]}"; do
        if [[ "$dev" == nvme* ]]; then
            smart::capture_nvme "$dev" "$sfx"
        elif [[ "$dev" == sd* ]]; then
            smart::capture_ata "$dev" "$sfx"
        fi
    done
}
