# =============================================================================
# HOT-PLUG DRIVE DETECTION — watch /sys/block for drives added or removed
# after boot and re-scan on change.
#
# The image uses kernel devtmpfs (no udev/mdev daemon), so detection is a cheap
# poll of the block-device tree diffed against the last snapshot. It is only
# acted on while the appliance idles on the triage screen — never during a wipe
# (the wipe set is fixed once started).
# =============================================================================

# The last-seen filtered device list (seeded by hotplug::baseline).
HOTPLUG_SNAPSHOT=""
# Device names added / removed since the last snapshot (set by hotplug::poll).
HOTPLUG_ADDED=""
HOTPLUG_REMOVED=""

# The filtered block-device list — exactly the device::discover acceptance
# rules: skip loop/ram/dm- and sr*, and keep only non-USB nvme*/sd* (a USB
# bridge hides the drive's true identity, so those are never offered).
hotplug::snapshot() {
    local block_dir="${SYS_BLOCK_DIR:-/sys/block}"
    local path dev out=""
    for path in "$block_dir"/*; do
        [[ -e "$path" ]] || continue
        dev="${path##*/}"
        [[ "$dev" =~ ^(loop|ram|dm-) ]] && continue
        [[ "$dev" =~ ^sr[0-9]+$ ]] && continue
        if [[ "$dev" =~ ^nvme[0-9]+(c[0-9]+)?n[0-9]+$ ]]; then
            if realpath "$block_dir/$dev/device" 2>/dev/null | grep -q '/usb'; then
                continue
            fi
        elif [[ "$dev" =~ ^sd[a-z]+$ ]]; then
            if realpath "$block_dir/$dev/device" 2>/dev/null | grep -q '/usb'; then
                continue
            fi
        else
            continue
        fi
        out+="$dev"$'\n'
    done
    printf '%s' "$out" | sort
}

# Seed / re-seed the baseline snapshot (call after device::discover and after
# every handled change).
hotplug::baseline() {
    HOTPLUG_SNAPSHOT="$(hotplug::snapshot)"
}

# Returns 0 when the current snapshot differs from the baseline, recording the
# added/removed device names; returns 1 when unchanged.
hotplug::poll() {
    local now d
    now="$(hotplug::snapshot)"
    [[ "$now" == "$HOTPLUG_SNAPSHOT" ]] && return 1

    HOTPLUG_ADDED=""
    HOTPLUG_REMOVED=""
    while IFS= read -r d || [[ -n "$d" ]]; do
        [[ -z "$d" ]] && continue
        grep -qx "$d" <<<"$HOTPLUG_SNAPSHOT" || HOTPLUG_ADDED+="$d"$'\n'
    done <<<"$now"
    while IFS= read -r d || [[ -n "$d" ]]; do
        [[ -z "$d" ]] && continue
        grep -qx "$d" <<<"$now" || HOTPLUG_REMOVED+="$d"$'\n'
    done <<<"$HOTPLUG_SNAPSHOT"
    return 0
}

# Print a one-line notice for each drive that just appeared or disappeared.
# Called AFTER table::render so it sits below the freshly drawn table; the
# in-place tick that follows leaves it visible until the next full render.
hotplug::notice() {
    local d
    while IFS= read -r d || [[ -n "$d" ]]; do
        [[ -z "$d" ]] && continue
        printf "%s[+] %s — %s %s\n" "$TABLE_INDENT" "/dev/$d" \
            "${model[$d]:-N/A}" "${size[$d]:-}"
    done <<<"${HOTPLUG_ADDED:-}"
    while IFS= read -r d || [[ -n "$d" ]]; do
        [[ -z "$d" ]] && continue
        printf "%s[-] %s removed\n" "$TABLE_INDENT" "/dev/$d"
    done <<<"${HOTPLUG_REMOVED:-}"
}
