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

# --- n/a + no sector count when the erase was BLOCKED after the sentinel was planted ---
devrow[sda.selected]=1
devrow[sda.status]="BLOCKED"
verify::plant_all
t::assert_eq "20" "${devrow[sda.verify_sectors]}" "plant records sentinel count before erase"
verify::check_all
t::assert_eq "n/a" "${devrow[sda.verify_result]}" "n/a for BLOCKED drive"
t::check "blocked drive clears verify_sectors" '[[ -z "${devrow[sda.verify_sectors]}" ]]'
devrow[sda.status]="COMPLETED"

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

# --- coverage sampling is opt-in and parses 1..100 --------------------------
VERIFY_COVERAGE_PCT=""
parse_args --verify-coverage 5
t::assert_eq "5" "$VERIFY_COVERAGE_PCT" "--verify-coverage 5"
VERIFY_COVERAGE_PCT=""
parse_args --verify-coverage=100
t::assert_eq "100" "$VERIFY_COVERAGE_PCT" "--verify-coverage=100"
t::check "--verify-coverage 0 is refused" '( parse_args --verify-coverage 0 ) >/dev/null 2>&1; (( $? != 0 ))'
t::check "--verify-coverage 101 is refused" '( parse_args --verify-coverage 101 ) >/dev/null 2>&1; (( $? != 0 ))'
t::check "--verify-coverage abc is refused" '( parse_args --verify-coverage abc ) >/dev/null 2>&1; (( $? != 0 ))'
t::check "--verify-coverage needs a value" '( parse_args --verify-coverage ) >/dev/null 2>&1; (( $? != 0 ))'
VERIFY_COVERAGE_PCT=""
verify::resolve_coverage
t::assert_eq "0" "$VERIFY_COVERAGE_PCT" "coverage off by default"

# --- coverage primitives, for REAL (not stubbed) ----------------------------
# v1.11.73 compared each sampled range against /dev/zero, which can never report
# equal because /dev/zero never ends — so on real hardware `--verify-coverage`
# silently returned "not applicable" on every drive. It shipped green because
# the tests below stubbed the very function that was broken. These run the
# primitive against real data, with a file standing in for the device.
COVFILE="$TDIR/cov-1m.bin"
dd if=/dev/zero of="$COVFILE" bs=1M count=1 2>/dev/null
t::check "_range_probe_ok accepts a bounded reference" 'verify::_range_probe_ok'
t::check "_range_is_zero passes on an all-zero 1 MiB range" 'verify::_range_is_zero "$COVFILE" 0 1'
t::check "an unbounded /dev/zero reference can never compare equal (why the fix exists)" \
    'dd if=/dev/zero bs=1M count=1 2>/dev/null | cmp -s - /dev/zero; [[ $? -ne 0 ]]'
printf 'X' | dd of="$COVFILE" bs=1 seek=524288 conv=notrunc 2>/dev/null
t::check "_range_is_zero reports data found" 'verify::_range_is_zero "$COVFILE" 0 1; rc=$?; [[ $rc -eq 1 ]]'
t::check "_range_is_zero reports an unreadable range as not applicable" \
    'verify::_range_is_zero "$TDIR/no-such-device" 0 1; rc=$?; [[ $rc -eq 2 ]]'
dd if=/dev/zero of="$COVFILE" bs=1M count=2 conv=notrunc 2>/dev/null
printf 'Y' | dd of="$COVFILE" bs=1 seek=1572864 conv=notrunc 2>/dev/null   # 2nd MiB
t::check "_range_is_zero honours skip (first MiB clean)" 'verify::_range_is_zero "$COVFILE" 0 1'
t::check "_range_is_zero sees data in the skipped-to range" \
    'verify::_range_is_zero "$COVFILE" 1 1; rc=$?; [[ $rc -eq 1 ]]'

# --- coverage arithmetic + verdict precedence -------------------------------
# A virtual 4096 MiB drive whose range reader is stubbed, so the sampling
# arithmetic is checked without touching real media.
verify::_total_sectors() { echo $(( 4096 * 2048 )); }   # 4096 MiB at 512 B
verify::_range_probe_ok() { return 0; }
RANGE_CALLS=0
RANGE_FAIL_AT=-1
verify::_range_is_zero() {
    RANGE_CALLS=$(( RANGE_CALLS + 1 ))
    (( RANGE_FAIL_AT > 0 && RANGE_CALLS >= RANGE_FAIL_AT )) && return 1
    return 0
}

VERIFY_COVERAGE_PCT=5
RANGE_CALLS=0
verify::_coverage_check sda
rc=$?
t::assert_eq "0" "$rc" "coverage passes when every sampled byte is zero"
t::assert_eq "20" "$RANGE_CALLS" "5% of 4096 MiB is sampled as 20 ranges"
t::assert_eq "5" "$VERIFY_COVERAGE_ACTUAL" "coverage rounds 4.88% to 5"

t::check "_range_probe_ok failure makes coverage not applicable" '
    verify::_range_probe_ok() { return 1; }
    VERIFY_COVERAGE_PCT=5
    verify::_coverage_check sda; rc=$?
    verify::_range_probe_ok() { return 0; }
    [[ $rc -eq 2 ]]'

RANGE_CALLS=0
RANGE_FAIL_AT=15
VERIFY_COVERAGE_PCT=5
verify::_coverage_check sda
rc=$?
t::assert_eq "1" "$rc" "coverage reports data found"
t::assert_eq "3" "$VERIFY_COVERAGE_ACTUAL" "partial coverage is recorded honestly"

# The sentinel loop's final assignment must not overwrite a coverage failure.
RANGE_FAIL_AT=15
devrow[sda.standard]="dodshort"
devrow[sda.capability]="CAP_SCSI_NWIPE"
devrow[sda.status]="COMPLETED"
dd if=/dev/zero of="$FAKE_DEV" bs=512 count="$TOTAL" conv=notrunc 2>/dev/null
# The earlier dry-run plant minted a fresh state dir with no per-drive file, and
# _check_drive skips a drive with no plant record.
: > "$VERIFY_STATE_DIR/sda.verify"
RANGE_CALLS=0
verify::check_all
t::assert_eq "overwrite-sample" "${devrow[sda.verify_technique]}" "nwipe path records coverage sampling"
t::assert_eq "failed" "${devrow[sda.verify_result]}" "data found in a sampled range fails the drive"
t::assert_eq "3" "${devrow[sda.verify_coverage]}" "failed coverage still records what was read"

# A clean coverage pass keeps the sentinel verdict.
RANGE_FAIL_AT=-1
verify::check_all
t::assert_eq "passed" "${devrow[sda.verify_result]}" "coverage pass leaves the verdict passed"
dd if=/dev/zero of="$FAKE_DEV" bs=512 count="$TOTAL" conv=notrunc 2>/dev/null
printf '' > "$VERIFY_STATE_DIR/sda.verify"   # no sentinels -> nothing to find
verify::check_all
t::assert_eq "passed" "${devrow[sda.verify_result]}" "sentinel-free drive passes with coverage on"

# Off by default: same drive, coverage disabled, records the sentinel technique.
VERIFY_COVERAGE_PCT=0
verify::check_all
t::assert_eq "sentinel" "${devrow[sda.verify_technique]}" "coverage off records the sentinel technique"
t::check "coverage off records no percentage" '[[ -z "${devrow[sda.verify_coverage]}" ]]'

# A firmware erase can never carry a coverage percentage.
VERIFY_COVERAGE_PCT=5
devrow[sda.standard]=""
verify::check_all
t::assert_eq "sentinel" "${devrow[sda.verify_technique]}" "firmware erase records the sentinel technique"
t::check "firmware erase records no coverage" '[[ -z "${devrow[sda.verify_coverage]}" ]]'

# A crypto erase is exempt rather than sampled.
devrow[sda.capability]="CAP_OPAL_CRYPTO"
verify::check_all
t::assert_eq "ce-exempt" "${devrow[sda.verify_result]}" "crypto erase reports the exemption"
t::check "crypto erase records no coverage" '[[ -z "${devrow[sda.verify_coverage]}" ]]'

# Coverage sampling is not attempted where the whole drive never held a pattern
# but the drive IS an overwrite: only `standard` decides, and a stub that always
# reports data must not be reached at all when coverage is off.
devrow[sda.capability]="CAP_SCSI_NWIPE"
devrow[sda.standard]="dodshort"
RANGE_CALLS=0
VERIFY_COVERAGE_PCT=0
verify::check_all
t::assert_eq "0" "$RANGE_CALLS" "coverage reader is never called when off"

# --- sanitisation claim gates (Phase 2) --------------------------------------
# Every gate that fails must keep the NIST label AND name itself in the
# shortfall, so a refusal is visible rather than silent.
declare -Ag bus type capability
claim_case() {   # <cap> <bus> <type> <status> <verify_result> <coverage> [selected]
    devrow=()
    devrow[sda.selected]="${7:-1}"
    devrow[sda.capability]="$1"
    devrow[sda.status]="$4"
    devrow[sda.verify_result]="$5"
    devrow[sda.verify_coverage]="$6"
    devrow[sda.hpa_result]="n/a"
    devrow[sda.dco_result]="n/a"
    bus[sda]="$2"
    type[sda]="$3"
    SANITIZATION_STANDARD="${8:-ieee2883}"
    verify::_claim_drive sda
}

t::assert_eq "overwrite Clear" "$(standard::technique CAP_SCSI_NWIPE)" "nwipe maps to an overwrite Clear"
t::assert_eq "purge Purge" "$(standard::technique CAP_ATA_PURGE_ENHANCED)" "ATA enhanced maps to Purge"
t::assert_eq "block Clear" "$(standard::technique CAP_NVME_CLEAR_ONLY)" "NVMe format maps to Clear, never Purge"
t::assert_eq "block Clear" "$(standard::technique CAP_ATA_CLEAR)" "ATA normal erase maps to Clear, never Purge"
t::assert_eq "crypto Purge" "$(standard::technique CAP_OPAL_CRYPTO)" "crypto erase maps to Purge"
t::check "unknown capability has no technique" '[[ -z "$(standard::technique CAP_NONE)" ]]'

# All gates pass on the overwrite path with our house sampling bar.
claim_case CAP_SCSI_NWIPE SATA SSD COMPLETED passed 5
t::assert_eq "IEEE 2883-2022" "${devrow[sda.sanitization_standard]}" "overwrite with 5% coverage earns the claim"
t::assert_eq "Clear" "${devrow[sda.sanitization_level]}" "overwrite earns a Clear level"
t::check "no shortfall when the claim is earned" '[[ -z "${devrow[sda.sanitization_shortfall]}" ]]'

# G2: below the bar the claim is refused and the shortfall says why.
claim_case CAP_SCSI_NWIPE SATA SSD COMPLETED passed 2
t::assert_eq "NIST 800-88" "${devrow[sda.sanitization_standard]}" "2% coverage refuses the Clear claim"
t::assert_eq "G2 coverage 2% < 5%" "${devrow[sda.sanitization_shortfall]}" "the shortfall names the coverage gate"

# A firmware Clear cannot show coverage (post-erase content is undefined), so it
# is not asked for one — but it must still have passed verification.
claim_case CAP_NVME_CLEAR_ONLY NVMe SSD COMPLETED passed ""
t::assert_eq "IEEE 2883-2022" "${devrow[sda.sanitization_standard]}" "firmware Clear does not need a coverage number"
t::assert_eq "Clear" "${devrow[sda.sanitization_level]}" "NVMe format is Clear"

# Purge needs a technique that reaches non-addressable locations.
claim_case CAP_ATA_PURGE_ENHANCED SATA HDD COMPLETED passed ""
t::assert_eq "IEEE 2883-2022" "${devrow[sda.sanitization_standard]}" "ATA enhanced earns the claim"
t::assert_eq "Purge" "${devrow[sda.sanitization_level]}" "ATA enhanced is Purge"
claim_case CAP_ATA_PURGE_ENHANCED SATA HDD COMPLETED passed "" 1 ieee2883
devrow[sda.hpa_result]="failed"
verify::_claim_drive sda
t::assert_eq "NIST 800-88" "${devrow[sda.sanitization_standard]}" "a failed hidden-area removal refuses Purge"
t::assert_eq "G1 hidden areas" "${devrow[sda.sanitization_shortfall]}" "hidden-area failure is named"

# G3: a crypto erase is exempt by design; anything else must show the sentinel gone.
claim_case CAP_OPAL_CRYPTO SATA SSD COMPLETED "" ""
t::assert_eq "IEEE 2883-2022" "${devrow[sda.sanitization_standard]}" "crypto erase needs no sentinel result"
t::assert_eq "Purge" "${devrow[sda.sanitization_level]}" "crypto erase is Purge"
claim_case CAP_SCSI_NWIPE SATA SSD COMPLETED failed 5
t::assert_eq "G3 verification failed" "${devrow[sda.sanitization_shortfall]}" "a failed read-back refuses the claim"

# G0: an incomplete erase can never be claimed, whatever the technique.
claim_case CAP_ATA_PURGE_ENHANCED SATA HDD BLOCKED "" ""
t::assert_eq "G0 BLOCKED" "${devrow[sda.sanitization_shortfall]}" "an incomplete erase is refused"

# G5: media we do not classify as in scope never earns the claim.
claim_case CAP_SCSI_NWIPE USB SSD COMPLETED passed 5
t::assert_eq "NIST 800-88" "${devrow[sda.sanitization_standard]}" "out-of-scope bus is refused"
t::check "the scope shortfall names the gate" '[[ "${devrow[sda.sanitization_shortfall]}" == G5* ]]'

# An unselected drive was never sanitised, so it carries no claim at all.
claim_case CAP_SCSI_NWIPE SATA SSD COMPLETED passed 5 0
t::check "unselected drives carry no claim" '[[ -z "${devrow[sda.sanitization_standard]:-}" ]]'

# The default (NIST) path must record nothing at all — byte-identical reports.
claim_case CAP_SCSI_NWIPE SATA SSD COMPLETED passed 5 1 nist
t::check "the NIST default records no claim fields" '[[ -z "${devrow[sda.sanitization_standard]:-}" ]]'
SANITIZATION_STANDARD=nist
devrow=(); devrow[sda.selected]=1
standard::claim_all
t::check "claim_all is a no-op on the default path" '[[ -z "${devrow[sda.sanitization_standard]:-}" ]]'

t::summary
