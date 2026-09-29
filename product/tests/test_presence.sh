#!/usr/bin/env bash
# Tests for the presence (heartbeat) module: 37_presence.sh.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"
FAKE_MDM_CURL_LOG="$tmpdir/curl.log"
export FAKE_MDM_CURL_LOG FAKE_MDM_VERDICT FAKE_MDM_RC FAKE_MDM_FAIL_FIRST FAKE_MDM_QUEUE

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

# with a token, ping hits /api/heartbeat carrying serial + uuid.
TSCRUB_API_TOKEN="$(printf 'a%.0s' {1..64})"
presence::ping
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" "api/heartbeat" "presence: ping hits heartbeat endpoint"
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" "SYSSN123" "presence: ping carries serial"
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" "4C4C4544-0036-5710-8032-B5C04F433633" "presence: ping carries uuid"

rm -rf "$tmpdir"
t::summary
