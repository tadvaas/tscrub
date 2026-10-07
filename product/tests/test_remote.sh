#!/usr/bin/env bash
# Tests for the remote power module: 42_remote.sh.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"
STATUS_STATE_FILE="$tmpdir/state"
export STATUS_STATE_FILE

CURL_LOG="$tmpdir/curl.log"
RESULT_LOG="$tmpdir/result.log"
PENDING_RESPONSE='{"ok":true,"pending":false}'
POWER_ACTION=""

# Fake the transport + the power binaries so the poll loop can be driven
# synchronously without a network or a real poweroff.
curl() {
    printf '%s\n' "$*" >> "$CURL_LOG"
    if [[ "$*" == *"/commands/pending"* ]]; then
        printf '%s' "$PENDING_RESPONSE"
        return 0
    fi
    if [[ "$*" == *"/commands/result"* ]]; then
        printf '%s\n' "$*" >> "$RESULT_LOG"
        printf '{"ok":true}'
        return 0
    fi
    printf '{}'
    return 0
}
poweroff() { POWER_ACTION="poweroff"; return 0; }
reboot() { POWER_ACTION="reboot"; return 0; }
sleep() { :; }

# --- endpoint derivation ----------------------------------------------------
t::assert_eq "https://tscrub.com/api/devices/commands/pending" \
    "$(TSCRUB_UPLOAD_URL='https://tscrub.com/api/reports' remote::pending_endpoint)" \
    "remote pending endpoint: built-in reports URL"
t::assert_eq "https://host.example/api/devices/commands/result" \
    "$(TSCRUB_UPLOAD_URL='https://host.example' remote::result_endpoint)" \
    "remote result endpoint: bare host"

# --- no token -> no-op ------------------------------------------------------
TSCRUB_API_TOKEN=""
: > "$CURL_LOG"
remote::poll_and_execute
t::check "remote: no token → no curl" '[[ ! -s "$CURL_LOG" ]]'

TSCRUB_API_TOKEN="$(printf 'a%.0s' {1..64})"

# --- wiping -> skip (command stays staged, no claim, no power) ---------------
status::write wiping 2 1 0
: > "$CURL_LOG"; POWER_ACTION=""
remote::poll_and_execute
t::check "remote: wiping → never claims or powers off" '[[ ! -s "$CURL_LOG" && -z "$POWER_ACTION" ]]'

# --- no pending command -> no action ----------------------------------------
status::clear
PENDING_RESPONSE='{"ok":true,"pending":false}'
: > "$CURL_LOG"; : > "$RESULT_LOG"; POWER_ACTION=""
remote::poll_and_execute
t::check "remote: no pending command → no action" '[[ -z "$POWER_ACTION" && ! -s "$RESULT_LOG" ]]'

# --- shutdown command -> poweroff + result done -----------------------------
PENDING_RESPONSE='{"ok":true,"pending":true,"id":7,"command":"shutdown"}'
: > "$RESULT_LOG"; POWER_ACTION=""
remote::poll_and_execute
t::assert_eq "poweroff" "$POWER_ACTION" "remote: shutdown command powers off"
t::check "remote: shutdown reports done" 'grep -q "\"result\":\"done\"" "$RESULT_LOG"'

# --- reboot command -> reboot -----------------------------------------------
PENDING_RESPONSE='{"ok":true,"pending":true,"id":8,"command":"reboot"}'
: > "$RESULT_LOG"; POWER_ACTION=""
remote::poll_and_execute
t::assert_eq "reboot" "$POWER_ACTION" "remote: reboot command reboots"

# --- unknown command -> failed, no power ------------------------------------
PENDING_RESPONSE='{"ok":true,"pending":true,"id":9,"command":"hack"}'
: > "$RESULT_LOG"; POWER_ACTION=""
remote::poll_and_execute
t::check "remote: unknown command → failed, no power" '[[ -z "$POWER_ACTION" ]] && grep -q "\"result\":\"failed\"" "$RESULT_LOG"'

# --- wipe starts between claim and execute -> deferred, no power -------------
PENDING_RESPONSE='{"ok":true,"pending":true,"id":10,"command":"shutdown"}'
: > "$RESULT_LOG"; POWER_ACTION=""
# First status::field call (the claim gate) is idle; the re-check is wiping.
# The counter must live in a file — command substitution runs the function in a
# subshell, so a plain variable would not persist between the two calls.
printf '0' > "$tmpdir/phase_calls"
status::field() {
    local n
    n="$(cat "$tmpdir/phase_calls")"
    n=$((n + 1))
    printf '%s' "$n" > "$tmpdir/phase_calls"
    [[ "$n" -ge 2 ]] && printf 'wiping'
}
remote::poll_and_execute
t::check "remote: wipe race → deferred, no power" '[[ -z "$POWER_ACTION" ]] && grep -q "\"result\":\"deferred\"" "$RESULT_LOG"'
status::field() { printf 'idle'; }   # subsequent polls see an idle session

# --- wipe command -> stage marker, no power, no immediate result -------------
REMOTE_ERASE_MARKER="$tmpdir/marker"
PENDING_RESPONSE='{"ok":true,"pending":true,"id":11,"command":"wipe","options":{"dry_run":false,"drives":"all"}}'
: > "$REMOTE_ERASE_MARKER"; : > "$RESULT_LOG"; POWER_ACTION=""
remote::poll_and_execute
t::check "remote: wipe writes marker, does not power off" '[[ -z "$POWER_ACTION" && -s "$REMOTE_ERASE_MARKER" ]]'
t::check "remote: wipe does NOT report a result" '[[ ! -s "$RESULT_LOG" ]]'
t::check "remote: wipe marker has id/dry_run/scope=all" 'grep -q "^id=11$" "$REMOTE_ERASE_MARKER" && grep -q "^dry_run=0$" "$REMOTE_ERASE_MARKER" && grep -q "^scope=all$" "$REMOTE_ERASE_MARKER"'

PENDING_RESPONSE='{"ok":true,"pending":true,"id":12,"command":"wipe","options":{"dry_run":true,"drives":["AB123","CD456"]}}'
: > "$REMOTE_ERASE_MARKER"; : > "$RESULT_LOG"; POWER_ACTION=""
remote::poll_and_execute
t::check "remote: wipe list marker" 'grep -q "^scope=list$" "$REMOTE_ERASE_MARKER" && grep -q "^drive=AB123$" "$REMOTE_ERASE_MARKER" && grep -q "^drive=CD456$" "$REMOTE_ERASE_MARKER" && grep -q "^dry_run=1$" "$REMOTE_ERASE_MARKER"'

# --- consume_erase_marker ---------------------------------------------------
printf 'id=7\ndry_run=0\nscope=all\n' > "$REMOTE_ERASE_MARKER"
out="$(remote::consume_erase_marker)"
t::check "remote: consume prints marker" '[[ "$out" == *"id=7"* && "$out" == *"scope=all"* ]]'
t::check "remote: consume removes marker" '[[ ! -e "$REMOTE_ERASE_MARKER" ]]'
t::check "remote: consume absent marker is empty" '[[ -z "$(remote::consume_erase_marker)" ]]'

# --- single-drive marker: final line has no trailing newline -----------------
# `remote::consume_erase_marker` output is captured via command substitution,
# which strips the trailing newline, so the last `drive=` line arrives
# unterminated. The triage matching loop must still process it — a `read`
# returns non-zero at EOF, so without `|| [[ -n "$drv" ]]` a single-drive
# machine skips its only drive and fails "no drives matched". The loop below is
# the exact triage matching block (40_table.sh) run against the consume output.
devices=(nvme0n1)
devrow["nvme0n1.serial"]="98BB74L7K5YS"
printf 'id=20\ndry_run=0\nscope=list\ndrive=98BB74L7K5YS\n' > "$REMOTE_ERASE_MARKER"
marker="$(remote::consume_erase_marker)"
REMOTE_ERASE_DRIVES=""
while IFS= read -r drv || [[ -n "$drv" ]]; do
    for dev in "${devices[@]}"; do
        [[ "${devrow[$dev.serial],,}" == "${drv,,}" ]] && REMOTE_ERASE_DRIVES+="$drv"$'\n'
    done
done < <(printf '%s' "$marker" | sed -n 's/^drive=//p')
t::check "remote: single unterminated drive line still matches" \
    '[[ "$REMOTE_ERASE_DRIVES" == *"98BB74L7K5YS"* ]]'

# --- grace_confirm ----------------------------------------------------------
REMOTE_ERASE_GRACE_SECONDS=1
ui::terminal_controls_supported() { return 1; }   # no console → auto-proceed
t::check "remote: grace auto-proceeds without console" 'remote::grace_confirm'
ui::terminal_controls_supported() { return 0; }   # console present
read() { return 142; }                             # timeout → no key
t::check "remote: grace proceeds on timeout" 'remote::grace_confirm'
read() { return 0; }                               # a key → cancel
t::check "remote: grace cancels on key press" '! remote::grace_confirm'
unset -f read ui::terminal_controls_supported

# --- result POST retry: two failures then success ---------------------------
# A lost result POST strands the job "dispatched" (the server requeues it only
# after 10 min), so remote::report retries a few times first.
RESULT_ATTEMPTS_FILE="$tmpdir/result_attempts"
printf '0' > "$RESULT_ATTEMPTS_FILE"
: > "$RESULT_LOG"
curl() {
    printf '%s\n' "$*" >> "$CURL_LOG"
    if [[ "$*" == *"/commands/result"* ]]; then
        printf '%s\n' "$*" >> "$RESULT_LOG"
        n="$(cat "$RESULT_ATTEMPTS_FILE")"
        n=$((n + 1))
        printf '%s' "$n" > "$RESULT_ATTEMPTS_FILE"
        if (( n < 3 )); then
            return 7   # first two attempts fail
        fi
        printf '{"ok":true}'
        return 0
    fi
    printf '{}'
    return 0
}
remote::report 99 done "started"
t::check "remote: result POST retried after failure" '[[ "$(cat "$RESULT_ATTEMPTS_FILE")" == "3" ]]'
t::check "remote: retry posted to the result endpoint" '[[ "$(grep -c "commands/result" "$RESULT_LOG")" == "3" ]]'

# --- pending fetch: TLS clock-skew retry -------------------------------------
# A dead RTC battery makes curl fail TLS verification (exit 60). The poll must
# retry once with -k so a clock-skewed appliance still claims its wipe.
status::clear
TLS_LOG="$tmpdir/tls.log"
curl() {
    printf '%s\n' "$*" >> "$TLS_LOG"
    if [[ "$*" == *"/commands/pending"* ]]; then
        if [[ "$*" == "-k "* ]]; then
            printf '%s' "$PENDING_RESPONSE"
            return 0
        fi
        printf 'curl: (60) SSL certificate problem: certificate is not yet valid\n' >&2
        return 60
    fi
    printf '{}'
    return 0
}
PENDING_RESPONSE='{"ok":true,"pending":true,"id":13,"command":"wipe","options":{"dry_run":true,"drives":["AB123"]}}'
: > "$REMOTE_ERASE_MARKER"
remote::poll_and_execute
t::check "remote: pending TLS error retries with -k" \
    'grep -qE "^-k " "$TLS_LOG" && grep -q "commands/pending" "$TLS_LOG"'
t::check "remote: pending TLS retry still claims the wipe" \
    'grep -q "^drive=AB123$" "$REMOTE_ERASE_MARKER"'

# --- result POST: TLS clock-skew retry ---------------------------------------
REPORT_TLS_LOG="$tmpdir/report_tls.log"
curl() {
    printf '%s\n' "$*" >> "$REPORT_TLS_LOG"
    if [[ "$*" == *"/commands/result"* ]]; then
        if [[ "$*" == "-k "* ]]; then
            return 0
        fi
        printf 'curl: (60) SSL certificate problem: certificate is not yet valid\n' >&2
        return 60
    fi
    printf '{}'
    return 0
}
remote::report 88 done "started"
t::check "remote: result POST retries with -k after TLS error" \
    'grep -qE "^-k " "$REPORT_TLS_LOG"'

rm -rf "$tmpdir"
t::summary
