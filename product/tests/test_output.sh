#!/usr/bin/env bash
# Tests report output-dir resolution and network upload.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

TABLE_INDENT="    "

# --- detect_output: explicit writable dir is used (with trailing slash) ---
out="$(mktemp -d)"
REPORT_OUTPUT="$out"
REPORT_DIR="/"
report::detect_output
t::check "explicit writable output dir is used" "[[ \"\$REPORT_DIR\" == \"$out/\" ]]"

# --- detect_output: explicit non-writable dir falls back to / ---
REPORT_OUTPUT="/nonexistent/tscrub"
REPORT_DIR="/tmp/sentinel/"
report::detect_output 2>/dev/null
t::check "non-writable output dir falls back to /" "[[ \"\$REPORT_DIR\" == \"/\" ]]"

# --- detect_output: no lsblk on this host -> auto-mount skipped, fallback / ---
REPORT_OUTPUT=""
REPORT_DIR="/tmp/sentinel/"
report::detect_output 2>/dev/null
t::check "no writable USB found -> report dir is /" "[[ \"\$REPORT_DIR\" == \"/\" ]]"

# --- upload_http: fake curl posts multipart + token, parses count ---
FAKE_CURL_LOG="$(mktemp)"
export FAKE_CURL_LOG
tdir="$(mktemp -d)"
csv="$tdir/report.csv"
printf 'COCID,Timestamp\n' > "$csv"
manifest="$tdir/report.json"
printf '{}' > "$manifest"
TSCRUB_UPLOAD_URL="https://tscrub.com/api/reports"
TSCRUB_API_TOKEN="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"

msg="$(report::upload_http "$csv" 2>&1)"
rc=$?
t::check "upload_http succeeds with fake curl" "[[ $rc -eq 0 ]]"
t::assert_contains "$(cat "$FAKE_CURL_LOG")" "X-Api-Token:"
t::assert_contains "$(cat "$FAKE_CURL_LOG")" "reports[]=@"
t::assert_contains "$msg" "2 certificate"

# --- upload_http: server failure -> non-zero ---
export FAKE_CURL_FAIL=1
msg="$(report::upload_http "$csv" 2>&1)"
rc=$?
t::check "upload_http fails when curl fails" "[[ $rc -eq 1 && \"\$msg\" == *'FAILED'* ]]"
unset FAKE_CURL_FAIL

# --- upload dispatch: unconfigured -> no-op success ---
TSCRUB_UPLOAD_URL=""
SHRED_PROTO=""
report::upload "$csv" && t::check "upload no-op when unconfigured" true

rm -rf "$out" "$tdir" "$FAKE_CURL_LOG"
t::summary
