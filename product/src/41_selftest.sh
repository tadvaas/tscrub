#!/usr/bin/env bash

# =============================================================================
# HARDWARE SELF-TEST (opt-in) — run a fast, deterministic CPU arithmetic check
# and a storage short self-test (SMART / NVMe) per drive, and record the
# PASS/FAIL verdicts so they land in the diagnostics snapshot.
#
# Opt-in only (--selftest / tscrub_selftest=1): storage short self-tests take
# ~2 minutes per drive, so they never run during a normal triage boot.
#
# Results:
#   SELFTEST_CPU               — CPU self-test: PASS / FAIL
#   devrow[$dev.selftest_run]  — storage short self-test: PASS / FAIL /
#                                UNKNOWN / UNSUP
# =============================================================================

# Deterministic CPU exercise: the sum of squares of 1..N compared against its
# closed form. Exercises integer multiply-add on real hardware; any overrun or
# ALU fault flips the verdict to FAIL. Runs in well under a second.
selftest::cpu() {
    local n=100000 i=0 sum=0 expected=0
    for ((i = 1; i <= n; i++)); do
        sum=$(( sum + i * i ))
    done
    expected=$(( n * (n + 1) * (2 * n + 1) / 6 ))
    if [[ "$sum" == "$expected" ]]; then
        SELFTEST_CPU="PASS"
    else
        SELFTEST_CPU="FAIL"
    fi
}

# Storage short self-test (ATA/SATA via smartctl). Starts the test, polls the
# "Self-test execution status" until it is no longer in progress, then reads
# the most recent self-test log entry.
selftest::storage_ata() {
    local dev="$1" out i
    command -v smartctl >/dev/null 2>&1 || { devrow["$dev.selftest_run"]="UNSUP"; return; }
    smart::run "$SMART_TIMEOUT" smartctl -t short "/dev/$dev" >/dev/null 2>&1 \
        || { devrow["$dev.selftest_run"]="UNSUP"; return; }
    for ((i = 0; i < 72; i++)); do   # up to ~6 min at 5 s intervals
        out="$(smart::run "$SMART_TIMEOUT" smartctl -c "/dev/$dev" 2>/dev/null)"
        grep -qi 'in progress' <<<"$out" || break
        sleep 5
    done
    out="$(smart::run "$SMART_TIMEOUT" smartctl -l selftest "/dev/$dev" 2>/dev/null)"
    if grep -qi 'completed without error' <<<"$out"; then
        devrow["$dev.selftest_run"]="PASS"
    elif grep -qiE 'completed.*(read failure|write failure|element failure)' <<<"$out"; then
        devrow["$dev.selftest_run"]="FAIL"
    else
        devrow["$dev.selftest_run"]="UNKNOWN"
    fi
}

# Storage short self-test (NVMe via nvme-cli). Starts a short (2-minute)
# device self-test with `-w` so nvme-cli blocks until it finishes (or the
# timeout elapses) — no hand-rolled polling and no risk of scoring a stale
# historical entry. The verdict is read from the NEWEST result's Operation
# Result field (0 = completed without error; 1..9 = aborted/failed).
selftest::storage_nvme() {
    local dev="$1" log="" op_result="" rc
    command -v nvme >/dev/null 2>&1 || { devrow["$dev.selftest_run"]="UNSUP"; return; }

    # -w waits for completion; -t 360000 caps it at 6 min (short test ≈ 2 min).
    nvme device-self-test -s 1 -w -t 360000 "/dev/$dev" >/dev/null 2>&1
    rc=$?

    log="$(nvme self-test-log "/dev/$dev" 2>/dev/null)"
    # nvme-cli prints the newest entry first as "Self Test Result[0]:".
    op_result="$(awk '/Self Test Result\[0\]/{f=1; next} f && /Operation Result/{print $NF; exit}' <<<"$log")"

    if [[ "$op_result" == "0" ]]; then
        devrow["$dev.selftest_run"]="PASS"
    elif [[ "$op_result" =~ ^[0-9]+$ ]]; then
        devrow["$dev.selftest_run"]="FAIL"
    elif (( rc != 0 )); then
        # No result written and -w returned non-zero: aborted or timed out.
        grep -qiE 'abort|fatal' <<<"$log" \
            && devrow["$dev.selftest_run"]="FAIL" \
            || devrow["$dev.selftest_run"]="UNKNOWN"
    else
        devrow["$dev.selftest_run"]="UNKNOWN"
    fi
}

# Run the full hardware self-test: CPU check plus a storage short self-test for
# every discovered drive. Called only when self-tests are enabled.
selftest::run() {
    local dev
    selftest::cpu
    for dev in "${devices[@]}"; do
        if [[ "$dev" == nvme* ]]; then
            selftest::storage_nvme "$dev"
        elif [[ "$dev" == sd* ]]; then
            selftest::storage_ata "$dev"
        else
            devrow["$dev.selftest_run"]="UNSUP"
        fi
    done
}
