#!/usr/bin/env bash
# NVMe sanitize stall detection + exec-time pre-flight (31_device_nvme.sh).
#
# Covers the failure seen on a wedged Intel 660p: SSTAT stuck at 0x2 ("in
# progress") with SPROG frozen, where the monitor used to treat the run as
# healthy progress and burn the whole 6-hour cap before failing with no reason.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmp="$(mktemp -d)"
OUT="$tmp/fd3"
SEQ="$tmp/seq"
export FAKE_NVME_CMDLOG="$tmp/cmds"
export LOG_FILE="$tmp/tscrub.log"
: > "$LOG_FILE"

# The poll loop sleeps 2s per iteration in production. No-op the sleep and run a
# virtual clock (file-backed, so it survives command substitution) that advances
# 2s per call — the stall window is then exercised deterministically and
# instantly. A real 15-minute wait is not a test.
sleep() { :; }
NOWFILE="$tmp/nowfile"
printf '0\n' > "$NOWFILE"
ts::now() {
    local v
    read -r v < "$NOWFILE"
    v=$(( v + 2 ))
    printf '%s\n' "$v" > "$NOWFILE"
    printf '%s\n' "$v"
}

declare -A capability bus
devices=(nvme0n1)
bus[nvme0n1]="NVMe"
capability[nvme0n1]="CAP_NVME_PURGE_CRYPTO"
devrow=()
devrow[nvme0n1.class]="PURGE"
devrow[nvme0n1.capability]="CAP_NVME_PURGE_CRYPTO"

# Run one exec_nvme/monitor cycle, capturing everything written to fd 3 (the
# channel ui::loop reads STATUS/LOG lines from) into $captured.
run_exec() {
    : > "$OUT"
    exec 3>"$OUT"
    device::exec_nvme nvme0n1 CAP_NVME_PURGE_CRYPTO
    exec 3>&-
    captured="$(cat "$OUT")"
}

# --- device::nvme_sanitize_state decodes SSTAT bits 2:0 ----------------------
printf '0x2 655\n' > "$SEQ"
unset FAKE_NVME_SSTAT FAKE_NVME_SPROG
export FAKE_NVME_SEQ="$SEQ"
t::assert_eq "2 655 0x2" "$(device::nvme_sanitize_state nvme0n1)" \
    "sanitize_state: 0x2 -> in progress with the raw SPROG"

printf '0x102 65535\n' > "$SEQ"
t::assert_eq "2 65535 0x102" "$(device::nvme_sanitize_state nvme0n1)" \
    "sanitize_state: high bits are masked (0x102 -> state 2)"

printf 'weird 65535\n' > "$SEQ"
t::assert_eq "- 65535 weird" "$(device::nvme_sanitize_state nvme0n1)" \
    "sanitize_state: unparseable SSTAT reports '-' rather than guessing"

# --- pre-flight: a sanitize already in progress is WAITED FOR, not failed ----
# Bench reality: the drive really was erasing (a prior run had been killed at
# 8%), yet the old pre-flight marked it STALLED/FAILED the instant it looked,
# throwing away a live erase and telling the operator to power-cycle a healthy
# drive. Wait for the in-flight sanitize to clear, then run this run's erase.
printf '0x2 655\n0x2 700\n0x1 65535\n0x2 100\n0x1 65535\n' > "$SEQ"
: > "$FAKE_NVME_CMDLOG"
: > "$LOG_FILE"
run_exec
t::assert_contains "$captured" "already being sanitized" \
    "pre-flight: says the drive was already sanitizing"
t::assert_contains "$captured" "waiting for that erase to finish" \
    "pre-flight: says it is waiting for that erase"
t::assert_contains "$captured" "nvme0n1 PHASE WAIT" \
    "pre-flight: the on-screen phase says WAIT, not a failure"
t::check "pre-flight: a drive that is actually erasing is not called a failure" \
    '[[ "$captured" != *"STATUS STALLED"* && "$captured" != *"STATUS FAILED"* ]]'
t::check "pre-flight: does not demand a power cycle for a healthy drive" \
    '[[ "$captured" != *"power-cycle the drive"* ]]'
t::check "pre-flight: once the in-flight erase clears, this run issues its own" \
    'grep -qE "^sanitize " "$FAKE_NVME_CMDLOG"'
t::assert_contains "$captured" "the in-flight sanitize has finished" \
    "pre-flight: reports the transfer of control"
t::assert_contains "$captured" "nvme0n1 STATUS COMPLETED" \
    "pre-flight: this run's own erase is what gets certified"
t::assert_contains "$(cat "$LOG_FILE")" "PRE-FLIGHT WAIT" \
    "pre-flight: recorded in the run log"

# --- pre-flight: a genuinely wedged in-flight sanitize is still named ---------
printf '0x2 655\n0x2 655\n0x2 655\n0x2 655\n0x2 655\n0x2 655\n' > "$SEQ"
: > "$FAKE_NVME_CMDLOG"
export NVME_STALL_POLLS=2                  # 2 polls x 2s = 4s virtual window
run_exec
unset NVME_STALL_POLLS
t::assert_contains "$captured" "nvme0n1 STATUS STALLED" \
    "wedged in-flight sanitize: still ends in STALLED"
t::assert_contains "$captured" "power-cycle" \
    "wedged in-flight sanitize: tells the operator to power-cycle"
t::check "wedged in-flight sanitize: never issues a second sanitize" \
    '! grep -qE "^sanitize " "$FAKE_NVME_CMDLOG"'

# --- pre-flight: a previous sanitize FAILED is a warning, not a refusal ------
printf '0x3 0\n' > "$SEQ"
: > "$FAKE_NVME_CMDLOG"
run_exec
t::check "pre-flight: previous failure still attempts the sanitize" \
    'grep -qE "^sanitize " "$FAKE_NVME_CMDLOG"'
t::assert_contains "$captured" "previous sanitize as FAILED" \
    "pre-flight: warns about the previous failure"

# --- stall: SSTAT 0x2 with a frozen SPROG must not read as progress ---------
printf '0x1 65535\n0x2 655\n' > "$SEQ"     # pre-flight clean, then wedged
: > "$FAKE_NVME_CMDLOG"
export NVME_STALL_POLLS=3                  # 3 polls x 2s = 6s virtual window
run_exec
t::assert_contains "$captured" "nvme0n1 STATUS STALLED" \
    "stall: frozen SPROG -> STALLED"
t::assert_contains "$captured" "no progress for 6s" \
    "stall: reports the inactivity window"
t::assert_contains "$captured" "power-cycle" \
    "stall: tells the operator to power-cycle"
t::check "stall: sanitize was issued first" 'grep -qE "^sanitize " "$FAKE_NVME_CMDLOG"'
t::check "stall: does not claim completion" '[[ "$captured" != *"STATUS COMPLETED"* ]]'

# --- a slow but genuinely progressing sanitize is never killed --------------
printf '0x1 65535\n0x2 10\n0x2 30000\n0x1 65535\n' > "$SEQ"
export NVME_STALL_POLLS=1                  # even a 2s window must not trip
run_exec
t::assert_contains "$captured" "nvme0n1 STATUS 45%" \
    "progress: SPROG advances -> live percentage"
t::assert_contains "$captured" "nvme0n1 STATUS COMPLETED" \
    "progress: slow progress still completes"
t::check "progress: never reported a stall" '[[ "$captured" != *"STALLED"* ]]'

# --- SSTAT 0x3 (controller reports failure) still maps to FAILED ------------
printf '0x1 65535\n0x3 0\n' > "$SEQ"
unset NVME_STALL_POLLS
run_exec
t::assert_contains "$captured" "nvme0n1 STATUS FAILED" \
    "SSTAT=0x3 -> FAILED"
t::assert_contains "$captured" "Controller reported sanitize failure" \
    "SSTAT=0x3 carries the controller reason"

# --- never-sanitized (SSTAT 0x0) still fails fast on the short grace period --
printf '0x1 65535\n0x0 65535\n' > "$SEQ"
run_exec
t::assert_contains "$captured" "nvme0n1 STATUS FAILED" \
    "SSTAT=0x0 -> FAILED after the grace period"
t::assert_contains "$captured" "No sanitize progress reported" \
    "SSTAT=0x0 keeps its existing reason"
unset FAKE_NVME_SEQ

# --- the new status is terminal, and reported honestly ----------------------
devrow[nvme0n1.status]="STALLED"
t::check "STALLED counts as terminal (report is not withheld)" \
    'ui::all_drives_terminal'
device::normalize_outcome
t::assert_eq "STALLED" "${devrow[nvme0n1.class]}" \
    "normalize_outcome: class STALLED"
t::assert_eq "NOT SANITISED" "${devrow[nvme0n1.cert]}" \
    "normalize_outcome: cert NOT SANITISED"
t::assert_eq "Sanitisation stalled — power-cycle the drive" "${devrow[nvme0n1.method]}" \
    "normalize_outcome: method states the recovery step"

rm -rf "$tmp"
t::summary
