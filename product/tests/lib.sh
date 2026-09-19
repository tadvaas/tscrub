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
          FAKE_NVME_SANITIZE_RC FAKE_NVME_SANITIZE_OUT FAKE_NVME_FORMAT_RC \
          FAKE_NVME_FORMAT_OUT FAKE_NVME_CW FAKE_NVME_TEMP FAKE_NVME_SPARE \
          FAKE_NVME_PCT_USED FAKE_NVME_DUW FAKE_NVME_CYCLES FAKE_NVME_POH \
          FAKE_SMART_MODE FAKE_SMART_TEMP FAKE_SMART_POH FAKE_SMART_CYCLES \
          FAKE_SMART_REALLOC FAKE_SMART_LIFE_REMAIN FAKE_SMART_LBA_WRITTEN \
          FAKE_USB_DEVICES FAKE_SEDUTIL_LOCKED \
          FAKE_NWIPE_RC 2>/dev/null || true
}

# Source the production functions without executing the entrypoint.
t::source_src() {
    local f
    for f in \
        "$ROOT_DIR/src/00_bootstrap.sh" \
        "$ROOT_DIR/src/20_ui.sh" \
        "$ROOT_DIR/src/30_device.sh" \
        "$ROOT_DIR/src/31_device_nvme.sh" \
        "$ROOT_DIR/src/32_device_scsi.sh" \
        "$ROOT_DIR/src/33_device_ata.sh" \
        "$ROOT_DIR/src/34_smart.sh" \
        "$ROOT_DIR/src/40_table.sh" \
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

t::summary() {
    printf "\n%d tests, %d failures\n" "$TEST_COUNT" "$TEST_FAILURES"
    return "$TEST_FAILURES"
}
