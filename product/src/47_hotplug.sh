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

# The filtered block-device list — exactly the devices device::discover can put
# on screen: skip loop/ram/dm- and sr*, keep every nvme*/sd* whether or not it is
# USB-attached. USB must NOT be filtered out here: since P2 a USB stick is a
# first-class target, so a stick arriving or leaving IS a hot-plug event. (This
# filter used to drop USB, when discovery dropped it too. Discovery was widened
# and this was not, so plugging a stick in after boot never triggered a rescan
# and the stick simply never appeared. Keep these two in step — the snapshot
# must describe what the operator can see, not a narrower idea of a drive.)
hotplug::snapshot() {
    local block_dir="${SYS_BLOCK_DIR:-/sys/block}"
    local path dev out=""
    for path in "$block_dir"/*; do
        [[ -e "$path" ]] || continue
        dev="${path##*/}"
        [[ "$dev" =~ ^(loop|ram|dm-) ]] && continue
        [[ "$dev" =~ ^sr[0-9]+$ ]] && continue
        [[ "$dev" =~ ^(sd[a-z]+|nvme[0-9]+(c[0-9]+)?n[0-9]+)$ ]] || continue
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
