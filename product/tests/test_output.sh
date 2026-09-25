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
LOGFILE="$(mktemp)"
exec 5>"$LOGFILE"        # upload progress goes to the log, not the console
tdir="$(mktemp -d)"
csv="$tdir/report.csv"
printf 'COCID,Timestamp\n' > "$csv"
manifest="$tdir/report.json"
printf '{}' > "$manifest"
TSCRUB_UPLOAD_URL="https://tscrub.com/api/reports"
TSCRUB_API_TOKEN="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"

report::upload_http "$csv" 2>/dev/null
rc=$?
t::check "upload_http succeeds with fake curl" "[[ $rc -eq 0 ]]"
t::assert_contains "$(cat "$FAKE_CURL_LOG")" "X-Api-Token:"
t::assert_contains "$(cat "$FAKE_CURL_LOG")" "reports[]=@"
t::assert_contains "$(cat "$LOGFILE")" "Synced to tScrub dashboard"

# --- upload_http: server failure -> non-zero, reason logged not printed ---
export FAKE_CURL_FAIL=1
report::upload_http "$csv" 2>/dev/null
rc=$?
t::check "upload_http fails when curl fails" "[[ $rc -eq 1 ]]"
t::assert_contains "$(cat "$LOGFILE")" "FAILED"
unset FAKE_CURL_FAIL

# --- upload dispatch: unconfigured -> no-op success ---
TSCRUB_UPLOAD_URL=""
TSCRUB_NET_PROTO=""
report::upload "$csv" && t::check "upload no-op when unconfigured" true

# --- upload_net: FTP and SFTP both invoke lftp with the right URL ---
FAKE_LFTP_LOG="$(mktemp)"
LOG_FILE="$(mktemp)"
export FAKE_LFTP_LOG LOG_FILE
TSCRUB_NET_PROTO="ftp" TSCRUB_NET_HOST="backup.example.com" TSCRUB_NET_PATH="incoming" TSCRUB_NET_USER="itad" TSCRUB_NET_PASS="s3cret"
report::upload_net "$csv" && t::check "upload_net (ftp) succeeds" true
t::assert_contains "$(cat "$FAKE_LFTP_LOG")" "ftp://backup.example.com"
t::assert_contains "$(cat "$FAKE_LFTP_LOG")" "sftp:auto-confirm"

TSCRUB_NET_PROTO="sftp" TSCRUB_NET_HOST="backup.example.com"
report::upload_net "$csv" && t::check "upload_net (sftp) succeeds" true
t::assert_contains "$(cat "$FAKE_LFTP_LOG")" "sftp://backup.example.com"

# --- upload dispatcher: return codes + status globals drive the amber screen ---
TSCRUB_NET_PROTO=""
TSCRUB_UPLOAD_URL="https://tscrub.com/api/reports"
TSCRUB_API_TOKEN="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"

report::upload "$csv" 2>/dev/null
rc=$?
t::check "upload: dashboard success -> rc 0" "[[ $rc -eq 0 ]]"
t::assert_contains "$(cat "$LOGFILE")" "Synced to tScrub dashboard"
t::assert_eq "ok" "${REPORT_DASH_STATUS:-}" "dashboard status ok"

export FAKE_CURL_FAIL=1
report::upload "$csv" 2>/dev/null
rc=$?
t::check "upload: dashboard fail (no ftp) -> rc 1" "[[ $rc -eq 1 ]]"
t::assert_contains "$(cat "$LOGFILE")" "Dashboard upload failed"
t::assert_eq "fail" "${REPORT_DASH_STATUS:-}" "dashboard status fail"
t::check "dashboard fail reason captured" "[[ -n \"${REPORT_DASH_REASON:-}\" ]]"
unset FAKE_CURL_FAIL

# No token and no network -> nothing configured; the built-in dashboard URL
# alone does not trigger an upload.
TSCRUB_API_TOKEN=""
TSCRUB_NET_PROTO=""
report::upload "$csv" 2>/dev/null
rc=$?
t::check "upload: no token, no network -> rc 0 (no-op)" "[[ $rc -eq 0 ]]"
t::assert_eq "" "${REPORT_DASH_STATUS:-}" "dashboard not attempted"

# dashboard fails, FTP fallback succeeds -> delivered (rc 0)
TSCRUB_API_TOKEN="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
TSCRUB_NET_PROTO="ftp"
TSCRUB_NET_HOST="backup.example.com" TSCRUB_NET_PATH="incoming" TSCRUB_NET_USER="itad" TSCRUB_NET_PASS="s3cret"
export FAKE_CURL_FAIL=1
report::upload "$csv" 2>/dev/null
rc=$?
t::check "upload: dashboard fail + ftp success -> rc 0" "[[ $rc -eq 0 ]]"
t::assert_contains "$(cat "$LOGFILE")" "falling back to ftp"
unset FAKE_CURL_FAIL

# dashboard fails and FTP fails -> rc 1
export FAKE_CURL_FAIL=1
export FAKE_LFTP_FAIL=1
report::upload "$csv" 2>/dev/null
rc=$?
t::check "upload: dashboard fail + ftp fail -> rc 1" "[[ $rc -eq 1 ]]"
t::assert_eq "fail" "${REPORT_NET_STATUS:-}" "network status fail"
unset FAKE_CURL_FAIL FAKE_LFTP_FAIL

# --- network::ensure: no-op when a default route already exists ---
ip() { [[ "$1" == "route" ]] && echo "default via 192.168.1.1 dev eth0"; }
network::ensure && t::check "network::ensure no-op when default route exists" true
unset -f ip

rm -rf "$out" "$tdir" "$FAKE_CURL_LOG" "$FAKE_LFTP_LOG" "$LOG_FILE" "$LOGFILE"
t::summary
