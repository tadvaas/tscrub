#!/usr/bin/env bash
# Tests for the presence (heartbeat) module: 37_presence.sh.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"
FAKE_MDM_CURL_LOG="$tmpdir/curl.log"
STATUS_STATE_FILE="$tmpdir/state"
export FAKE_MDM_CURL_LOG STATUS_STATE_FILE FAKE_MDM_VERDICT FAKE_MDM_RC FAKE_MDM_FAIL_FIRST FAKE_MDM_QUEUE

t::assert_eq "https://tscrub.com/api/heartbeat" \
    "$(TSCRUB_UPLOAD_URL='https://tscrub.com/api/reports' presence::endpoint)" \
    "presence endpoint: built-in reports URL"
t::assert_eq "https://host.example/api/heartbeat" \
    "$(TSCRUB_UPLOAD_URL='https://host.example' presence::endpoint)" \
    "presence endpoint: bare host"

# ping is a no-op without a token.
TSCRUB_API_TOKEN=""
TSCRUB_UPLOAD_URL="https://tscrub.com/api/reports"
SYS_SERIAL="SYSSN123"
SYS_UUID="4C4C4544-0036-5710-8032-B5C04F433633"
: > "$FAKE_MDM_CURL_LOG"
presence::ping
t::check "presence: no ping without token" '[[ ! -s "$FAKE_MDM_CURL_LOG" ]]'

# with a token, ping hits /api/heartbeat carrying serial + uuid + lan IP.
TSCRUB_API_TOKEN="$(printf 'a%.0s' {1..64})"
presence::ping
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" "api/heartbeat" "presence: ping hits heartbeat endpoint"
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" "SYSSN123" "presence: ping carries serial"
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" "4C4C4544-0036-5710-8032-B5C04F433633" "presence: ping carries uuid"
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" '\"ip\":\"192.168.0.55\"' "presence: ping carries lan ip"

# An idle boot (no state file) reports an empty phase, which clears any stale
# server-side erasure state.
: > "$FAKE_MDM_CURL_LOG"
status::clear
presence::ping
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" '\"phase\":\"\"' "presence: idle ping carries empty phase"

# With a state file, the same ping carries the live erasure indicator.
: > "$FAKE_MDM_CURL_LOG"
status::write wiping 4 1 0
presence::ping
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" '\"phase\":\"wiping\"' "presence: ping carries wiping phase"
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" '\"drives_total\":4' "presence: ping carries drives_total"
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" '\"drives_done\":1' "presence: ping carries drives_done"

# The heartbeat names WHICH drive failed, so the dashboard can say it.
: > "$FAKE_MDM_CURL_LOG"
status::write failed 4 3 1 'S3Z8NB0K123 (sanitize stalled)'
presence::ping
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" '\"failed_devices\"' \
    "presence: ping carries failed_devices"
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" 'S3Z8NB0K123' \
    "presence: ping names the failed drive"
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" 'stalled' \
    "presence: ping carries the failure reason"
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" '\"phase\":\"failed\"' \
    "presence: a failed phase is reported"

# A clean run clears the list rather than leaving a stale name on the server.
: > "$FAKE_MDM_CURL_LOG"
status::write done 4 4 0 ''
presence::ping
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" '\"failed_devices\":\"\"' \
    "presence: a clean run sends an empty failed list"

rm -rf "$tmpdir"
t::summary
