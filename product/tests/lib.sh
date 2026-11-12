#!/usr/bin/env bash
# Shared helpers for the tscrub test suite. Requires bash 4+.

if (( BASH_VERSINFO[0] < 4 )); then
    echo "tests require bash 4+ (found ${BASH_VERSINFO[0]}.${BASH_VERSINFO[1]})." >&2
    echo "Run with:  /opt/homebrew/bin/bash tests/run.sh" >&2
    exit 2
fi

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$TESTS_DIR/.." && pwd)"
FAKES_BIN="$TESTS_DIR/fakes/bin"
FIXTURES="$TESTS_DIR/fixtures"

TEST_COUNT=0
TEST_FAILURES=0

# Point production code at the fake device tree and mock external commands.
t::setup_env() {
    PATH="$FAKES_BIN:$PATH"
    export SYS_BLOCK_DIR="$FIXTURES/sys/block"
    unset FAKE_HDPARM_MODE FAKE_NVME_MODE FAKE_NVME_SSTAT FAKE_NVME_SPROG \
          FAKE_NVME_SEQ FAKE_NVME_CMDLOG \
          FAKE_NVME_SANITIZE_RC FAKE_NVME_SANITIZE_OUT FAKE_NVME_FORMAT_RC \
          FAKE_NVME_FORMAT_OUT FAKE_NVME_CW FAKE_NVME_TEMP FAKE_NVME_SPARE \
          FAKE_NVME_PCT_USED FAKE_NVME_DUW FAKE_NVME_CYCLES FAKE_NVME_POH \
          FAKE_NVME_MEDIA_ERR FAKE_NVME_UNSAFE_SHUT FAKE_NVME_SPARE_THRESH \
          FAKE_SMART_MODE FAKE_SMART_TEMP FAKE_SMART_POH FAKE_SMART_POH_MIN FAKE_SMART_CYCLES \
          FAKE_SMART_SCSI_HEALTH \
          FAKE_SMART_REALLOC FAKE_SMART_LIFE_REMAIN FAKE_SMART_LBA_WRITTEN \
          FAKE_SMART_PENDING FAKE_SMART_OFFLINE_UNC FAKE_SMART_UDMA_CRC \
          FAKE_SMART_FIRMWARE FAKE_SMART_GROWN \
          FAKE_USB_DEVICES FAKE_SEDUTIL_LOCKED FAKE_SEDUTIL_LOCKING_ENABLED FAKE_HDPARM_MODEL FAKE_HDPARM_SERIAL \
          FAKE_HDPARM_STATE_FILE FAKE_HDPARM_UNLOCK_RC FAKE_HDPARM_SETPASS_RC \
          FAKE_HDPARM_MREV FAKE_HDPARM_LEVEL FAKE_HDPARM_EXPIRED FAKE_HDPARM_USERPW \
          FAKE_HDPARM_LOG FAKE_HDPARM_UNFREEZE_FILE FAKE_HDPARM_ERASE_RC FAKE_HDPARM_SANITIZE \
          FAKE_HDPARM_SATL_BUSY_FILE FAKE_SMARTCTL_RSOS FAKE_SMART_BUSY_FILE \
          FAKE_SGRAW_LOG FAKE_SGRAW_SANITIZE_RC FAKE_SGRAW_BUSY_FILE FAKE_SGRAW_PREPARE_RC FAKE_SGRAW_ERASE_RC \
          TS_SATL_ERASE_POLL_SECS TS_SATL_ERASE_BUDGET_MINS TS_SATL_ERASE_BUDGET_SECS TS_SATL_ERASE_GRACE_SECS SATL_IO_BUSY_FILE \
          TS_REPORT_RETRY_SECS FAKE_CURL_FAIL_FILE REPORT_RETRY_MARKER \
          TS_ATA_PASSWORD \
          FAKE_SEDUTIL_LOG \
          FAKE_SEDUTIL_REVERT_RC FAKE_SEDUTIL_INITIALSETUP_RC \
          FAKE_PSID_RC FAKE_PSID_RESULT FAKE_PSID_ADMINSP_RC FAKE_SEDUTIL_LOCK_STATE \
          FAKE_NWIPE_RC FAKE_SG_SANITIZE_RC FAKE_SG_SANITIZE_OUT FAKE_SG_SANITIZE_SLEEP \
          FAKE_SG_OPCODES_SUPPORTED FAKE_SG_VPD_SERIAL FAKE_SG_REQUESTS_PCT FAKE_SG_REQUESTS_SEQ_FILE \
          FAKE_SG_READCAP_RC FAKE_MDADM_MEMBER \
          FAKE_WIPEFS_RC FAKE_WIPEFS_OUT FAKE_SFDISK_RC FAKE_SFDISK_OUT \
          FAKE_SGDISK_RC FAKE_SGDISK_OUT FAKE_MKFS_RC FAKE_MKFS_OUT \
          FAKE_BLKID_TYPE FAKE_BLKID_RC FAKE_FORMAT_CMDLOG \
          FAKE_LSPCI_VMD FAKE_LSPCI_RAID_HBA FAKE_LSPCI_SAS3008 FAKE_LSPCI_BROKEN \
          FAKE_MT_RC FAKE_MT_ERASE_RC FAKE_MT_ERASE_SLEEP FAKE_MT_OFFLINE_RC FAKE_MT_REWIND_RC FAKE_MT_LOG \
          FAKE_SGREADATTR_OUT FAKE_SGRAW_TUR_SEQ FAKE_SGRAW_LOAD_RC \
          TS_TAPE_READY_TRIES TS_TAPE_READY_POLL_SECS TS_TAPE_TEMP_POLL_SECS SYS_TAPE_DIR \
          TAPE_ERASE_RATE_LTO1 TAPE_ERASE_RATE_LTO2 TAPE_ERASE_RATE_LTO3 TAPE_ERASE_RATE_LTO4 \
          TAPE_ERASE_RATE_LTO5 TAPE_ERASE_RATE_LTO6 TAPE_ERASE_RATE_LTO7 TAPE_ERASE_RATE_LTO8 TAPE_ERASE_RATE_LTO9 \
          2>/dev/null || true
}

# Source the production functions without executing the entrypoint.
t::source_src() {
    local f
    for f in \
        "$ROOT_DIR/src/00_bootstrap.sh" \
        "$ROOT_DIR/src/10_main.sh" \
        "$ROOT_DIR/src/20_ui.sh" \
        "$ROOT_DIR/src/30_device.sh" \
        "$ROOT_DIR/src/31_device_nvme.sh" \
        "$ROOT_DIR/src/32_device_scsi.sh" \
        "$ROOT_DIR/src/33_device_tape.sh" \
        "$ROOT_DIR/src/33_device_ata.sh" \
        "$ROOT_DIR/src/34_smart.sh" \
        "$ROOT_DIR/src/35_mdm.sh" \
        "$ROOT_DIR/src/36_bios.sh" \
        "$ROOT_DIR/src/37_presence.sh" \
        "$ROOT_DIR/src/37_hp_repsetup.sh" \
        "$ROOT_DIR/src/38_bios_unlock.sh" \
        "$ROOT_DIR/src/39_register.sh" \
        "$ROOT_DIR/src/40_table.sh" \
        "$ROOT_DIR/src/41_selftest.sh" \
        "$ROOT_DIR/src/42_remote.sh" \
        "$ROOT_DIR/src/43_verify.sh" \
        "$ROOT_DIR/src/44_device_ppi.sh" \
        "$ROOT_DIR/src/44_device_sed.sh" \
        "$ROOT_DIR/src/45_device_raid.sh" \
        "$ROOT_DIR/src/46_hardware.sh" \
        "$ROOT_DIR/src/47_hotplug.sh" \
        "$ROOT_DIR/src/48_diag.sh" \
        "$ROOT_DIR/src/49_format.sh" \
        "$ROOT_DIR/src/50_report.sh"; do
        source "$f" || return 1
    done
}

t::check() {  # <label> <expression to eval (e.g. '[[ ... ]]' or a command)>
    local label="$1" expr="$2"
    TEST_COUNT=$((TEST_COUNT + 1))
    if eval "$expr"; then
        printf "ok   - %s\n" "$label"
    else
        TEST_FAILURES=$((TEST_FAILURES + 1))
        printf "FAIL - %s\n" "$label"
    fi
}

t::assert_eq() {  # <expected> <actual> [label]
    local expected="$1" actual="$2" label="${3:-}"
    TEST_COUNT=$((TEST_COUNT + 1))
    if [[ "$expected" == "$actual" ]]; then
        printf "ok   - %s\n" "${label:-assert_eq}"
    else
        TEST_FAILURES=$((TEST_FAILURES + 1))
        printf "FAIL - %s\n      expected: %q\n      actual:   %q\n" "${label:-assert_eq}" "$expected" "$actual"
    fi
}

t::assert_contains() {  # <haystack> <needle> [label]
    local haystack="$1" needle="$2" label="${3:-}"
    TEST_COUNT=$((TEST_COUNT + 1))
    if [[ "$haystack" == *"$needle"* ]]; then
        printf "ok   - %s\n" "${label:-assert_contains}"
    else
        TEST_FAILURES=$((TEST_FAILURES + 1))
        printf "FAIL - %s\n      expected to contain: %q\n      got: %q\n" "${label:-assert_contains}" "$needle" "$haystack"
    fi
}

t::assert_not_contains() {  # <haystack> <needle> [label]
    local haystack="$1" needle="$2" label="${3:-}"
    TEST_COUNT=$((TEST_COUNT + 1))
    if [[ "$haystack" != *"$needle"* ]]; then
        printf "ok   - %s\n" "${label:-assert_not_contains}"
    else
        TEST_FAILURES=$((TEST_FAILURES + 1))
        printf "FAIL - %s\n      expected to NOT contain: %q\n      got: %q\n" "${label:-assert_not_contains}" "$needle" "$haystack"
    fi
}

t::summary() {
    printf "\n%d tests, %d failures\n" "$TEST_COUNT" "$TEST_FAILURES"
    return "$TEST_FAILURES"
}
