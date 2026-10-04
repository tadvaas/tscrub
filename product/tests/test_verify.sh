#!/usr/bin/env bash
# Post-erasure read-back verification: sentinel determinism, fixed-position
# planting, and the passed/failed/unreadable/skipped/n-a verdicts.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

VERIFY_WINDOW=4   # small window keeps the fake device tiny

TDIR="$(mktemp -d)"
FAKE_DEV="$TDIR/dev.bin"
TOTAL=200
dd if=/dev/zero of="$FAKE_DEV" bs=512 count="$TOTAL" 2>/dev/null

# --- fake device + low-level I/O overrides (never touch a real block device) ---
verify::_total_sectors() { echo "$TOTAL"; }
verify::_write_sector() {
    local dev="$1" lba="$2" ss="$3" sentinel off
    sentinel="$(verify::sentinel_bytes "$VERIFY_NONCE" "$lba" "$ss")"
    off=$(( lba * ss ))
    printf '%s' "$sentinel" | dd of="$FAKE_DEV" bs=1 count="$ss" seek="$off" conv=notrunc 2>/dev/null
}
verify::_read_sector() {
    local dev="$1" lba="$2" ss="$3" out="$4" off
    off=$(( lba * ss ))
    dd if="$FAKE_DEV" of="$out" bs=1 count="$ss" skip="$off" 2>/dev/null
}
verify::_flush() { return 0; }

declare -Ag secsize
secsize[sda]=512

devices=(sda)
devrow=()
devrow[sda.selected]=1
devrow[sda.class]="PURGE"
devrow[sda.capability]="CAP_NVME_PURGE_CRYPTO"
devrow[sda.status]="COMPLETED"

# --- sentinel determinism ---
s1="$(verify::sentinel_bytes "nonce1" "7" "512")"
s2="$(verify::sentinel_bytes "nonce1" "7" "512")"
s3="$(verify::sentinel_bytes "nonce1" "8" "512")"
t::check "sentinel is 512 bytes" '[ "${#s1}" -eq 512 ]'
t::assert_eq "$s1" "$s2" "sentinel deterministic for same (nonce,lba)"
t::check "sentinel differs per LBA" '[[ "$s1" != "$s3" ]]'
t::check "sentinel prefixed with nonce:lba:" '[[ "$s1" == "nonce1:7:"* ]]'

# --- plant: 5 windows x VERIFY_WINDOW(4) = 20 sectors ---
VERIFY_MODE=sampled
verify::plant_all
t::assert_eq "20" "${devrow[sda.verify_sectors]}" "plant writes 5 windows x VERIFY_WINDOW"
t::check "planted state file exists" '[[ -f "$VERIFY_STATE_DIR/sda.verify" ]]'
got="$(dd if="$FAKE_DEV" bs=512 count=1 skip=0 2>/dev/null)"
t::assert_eq "$(verify::sentinel_bytes "$VERIFY_NONCE" "0" "512")" "$got" "LBA 0 holds sentinel"
tailgot="$(dd if="$FAKE_DEV" bs=512 count=1 skip=$((TOTAL - VERIFY_WINDOW)) 2>/dev/null)"
t::assert_eq "$(verify::sentinel_bytes "$VERIFY_NONCE" "$((TOTAL - VERIFY_WINDOW))" "512")" "$tailgot" "tail window starts at total-WINDOW"

# --- check: passed when sentinel destroyed (simulate erase = zero the drive) ---
dd if=/dev/zero of="$FAKE_DEV" bs=512 count="$TOTAL" conv=notrunc 2>/dev/null
verify::check_all
t::assert_eq "passed" "${devrow[sda.verify_result]}" "check passed when sentinel gone"

# --- check: failed when sentinel survives ---
verify::plant_all
verify::check_all
t::assert_eq "failed" "${devrow[sda.verify_result]}" "check failed when sentinel survives"

# --- check: unreadable on read error ---
verify::_read_sector() { return 1; }
verify::plant_all
verify::check_all
t::assert_eq "unreadable" "${devrow[sda.verify_result]}" "check unreadable on read error"

# --- plant: skipped when the write fails ---
verify::_read_sector() {
    local dev="$1" lba="$2" ss="$3" out="$4" off
    off=$(( lba * ss ))
    dd if="$FAKE_DEV" of="$out" bs=1 count="$ss" skip="$off" 2>/dev/null
}
verify::_write_sector() { return 1; }
verify::plant_all
t::assert_eq "skipped" "${devrow[sda.verify_result]}" "plant skipped when write fails"

# --- n/a for a FAILED-class drive (no write attempted) ---
verify::_write_sector() {
    local dev="$1" lba="$2" ss="$3" sentinel off
    sentinel="$(verify::sentinel_bytes "$VERIFY_NONCE" "$lba" "$ss")"
    off=$(( lba * ss ))
    printf '%s' "$sentinel" | dd of="$FAKE_DEV" bs=1 count="$ss" seek="$off" conv=notrunc 2>/dev/null
}
devrow[sda.class]="FAILED"
verify::plant_all
t::assert_eq "n/a" "${devrow[sda.verify_result]}" "n/a for FAILED class"

# --- n/a for an unselected drive ---
devrow[sda.class]="PURGE"
devrow[sda.selected]=0
verify::plant_all
t::assert_eq "n/a" "${devrow[sda.verify_result]}" "n/a for unselected drive"

# --- DRY_RUN plants nothing ---
devrow[sda.selected]=1
DRY_RUN=1
verify::plant_all
t::assert_eq "n/a" "${devrow[sda.verify_result]}" "dry-run plants nothing"
t::check "dry-run leaves verify_sectors empty" '[[ -z "${devrow[sda.verify_sectors]}" ]]'
DRY_RUN=0

# --- mode selection ---
VERIFY_MODE=""
parse_args --verify full
t::assert_eq "full" "$VERIFY_MODE" "--verify full"
parse_args --verify=none
t::assert_eq "none" "$VERIFY_MODE" "--verify=none"
VERIFY_MODE="bogus"
verify::resolve_mode
t::assert_eq "sampled" "$VERIFY_MODE" "invalid mode resolves to sampled"

t::summary
