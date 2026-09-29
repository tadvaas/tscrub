#!/usr/bin/env bash
# Tests for the MDM (Autopilot) worker: 35_mdm.sh.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

tmpdir="$(mktemp -d)"
MDM_RESULT_FILE="$tmpdir/verdict"
ipc_file="$tmpdir/ipc"
FAKE_MDM_CURL_LOG="$tmpdir/curl.log"
FAKE_MDM_FAIL_FLAG="$tmpdir/failflag"
# The fake curl is a separate process, so the FAKE_MDM_* knobs must be exported.
export FAKE_MDM_VERDICT FAKE_MDM_RC FAKE_MDM_FAIL_FIRST FAKE_MDM_CURL_LOG FAKE_MDM_FAIL_FLAG FAKE_MDM_QUEUE

# --- pure helpers -----------------------------------------------------------
t::assert_eq "UNLOCKED" "$(mdm::state_for_verdict unlocked)"     "mdm: unlocked → UNLOCKED"
t::assert_eq "LOCKED"   "$(mdm::state_for_verdict locked_this)"  "mdm: locked_this → LOCKED"
t::assert_eq "LOCKED"   "$(mdm::state_for_verdict locked_other)" "mdm: locked_other → LOCKED"
t::assert_eq "SKIPPED"  "$(mdm::state_for_verdict skipped)"      "mdm: skipped → SKIPPED"
t::assert_eq "OFFLINE"  "$(mdm::state_for_verdict offline)"      "mdm: offline → OFFLINE"
t::assert_eq "OFFLINE"  "$(mdm::state_for_verdict bogus)"        "mdm: bogus → OFFLINE"
t::assert_eq "UNKNOWN"  "$(mdm::state_for_verdict unknown)"      "mdm: unknown → UNKNOWN"
t::assert_eq "CHECKING" "$(mdm::state_for_verdict checking)"     "mdm: checking → CHECKING"

t::assert_eq "https://tscrub.com/api/mdm/autopilot" \
    "$(TSCRUB_UPLOAD_URL='https://tscrub.com/api/reports' mdm::endpoint)" \
    "mdm endpoint: built-in reports URL"
t::assert_eq "https://host.example/api/mdm/autopilot" \
    "$(TSCRUB_UPLOAD_URL='https://host.example/api/reports' mdm::endpoint)" \
    "mdm endpoint: custom /api/reports"
t::assert_eq "https://host.example/api/mdm/autopilot" \
    "$(TSCRUB_UPLOAD_URL='https://host.example' mdm::endpoint)" \
    "mdm endpoint: bare host"

t::assert_eq "locked_other" \
    "$(printf '{"ok":true,"verdict":"locked_other","source":"live"}' | mdm::json_field verdict)" \
    "mdm json_field: extracts verdict"
t::assert_eq "" \
    "$(printf '{"ok":true,"status":"queued","verdict":"","source":"none"}' | mdm::json_field verdict)" \
    "mdm json_field: empty verdict stays empty"

# ui::mdm_render colouring — regression for the bug where the colour never
# rendered (the function runs inside $(...) so its stdout is a pipe and
# [[ -t 1 ]] was always false; the caller now passes the decision explicitly).
esc=$'\033'
esc_green=$'\033[32m'
esc_red=$'\033[31m'
esc_amber=$'\033[33m'
UI_RUNTIME_VALUE_W=10
MDM_STATUS=UNLOCKED
t::check "mdm: unlocked cell is green" '[[ "$(ui::mdm_render 1)" == *"$esc_green"* ]]'
t::check "mdm: unlocked cell plain when colour off" '[[ "$(ui::mdm_render 0)" != *"$esc"* ]]'
MDM_STATUS=LOCKED
t::check "mdm: locked cell is red" '[[ "$(ui::mdm_render 1)" == *"$esc_red"* ]]'
MDM_STATUS=OFFLINE
t::check "mdm: offline cell is amber" '[[ "$(ui::mdm_render 1)" == *"$esc_amber"* ]]'
MDM_STATUS=CHECKING
t::check "mdm: checking cell uncoloured" '[[ "$(ui::mdm_render 1)" != *"$esc"* ]]'
MDM_STATUS=""

# --- theme clash: the status colour must never vanish into the finish bg ---
esc_bold_black=$'\033[1;30m'
esc_bold_white=$'\033[1;37m'
UI_COMPLETE_THEME=1; MDM_STATUS=UNLOCKED
t::check "mdm: unlocked on green finish is bold black, not green" '[[ "$(ui::mdm_render 1)" == *"$esc_bold_black"* && "$(ui::mdm_render 1)" != *"$esc_green"* ]]'
UI_COMPLETE_THEME=1; MDM_STATUS=LOCKED
t::check "mdm: locked on green finish stays red" '[[ "$(ui::mdm_render 1)" == *"$esc_red"* ]]'
UI_COMPLETE_THEME=2; MDM_STATUS=LOCKED
t::check "mdm: locked on red finish is bold white" '[[ "$(ui::mdm_render 1)" == *"$esc_bold_white"* ]]'
UI_COMPLETE_THEME=3; MDM_STATUS=OFFLINE
t::check "mdm: offline on amber finish is bold black" '[[ "$(ui::mdm_render 1)" == *"$esc_bold_black"* ]]'

# --- the bold clash reset must clear bold (SGR 22), or bold leaks past the
# --- cell onto the closing pipe and every row below (the "font colour bleed").
esc_norm_black=$'\033[22;30m'
esc_norm_white=$'\033[22;37m'
UI_COMPLETE_THEME=1; MDM_STATUS=UNLOCKED
t::check "mdm: green clash reset clears bold" '[[ "$(ui::mdm_render 1)" == *"$esc_norm_black" ]]'
UI_COMPLETE_THEME=2; MDM_STATUS=LOCKED
t::check "mdm: red clash reset clears bold" '[[ "$(ui::mdm_render 1)" == *"$esc_norm_white" ]]'
UI_COMPLETE_THEME=1; MDM_STATUS=LOCKED
t::check "mdm: non-clash reset is also normal-weight black" '[[ "$(ui::mdm_render 1)" == *"$esc_norm_black" ]]'
UI_COMPLETE_THEME=0; MDM_STATUS=""

TSCRUB_UPLOAD_URL=""
TSCRUB_API_TOKEN=""
TSCRUB_AUTOPILOTCHECK=""
t::check "mdm: not configured when unset" '! mdm::is_configured'
TSCRUB_API_TOKEN="$(printf 'a%.0s' {1..64})"
t::check "mdm: not configured with token but no flag" '! mdm::is_configured'
TSCRUB_AUTOPILOTCHECK=1
t::check "mdm: configured with flag + token" 'mdm::is_configured'
TSCRUB_API_TOKEN=""
t::check "mdm: not configured with flag but no token" '! mdm::is_configured'
TSCRUB_API_TOKEN="$(printf 'a%.0s' {1..64})"
# flag + token stay set for the run_detect tests below

run_detect() {
    : > "$ipc_file"
    exec 3>"$ipc_file"
    mdm::detect
    exec 3>&-
}

# --- skipped: no dashboard config -------------------------------------------
TSCRUB_UPLOAD_URL=""
TSCRUB_API_TOKEN=""
TSCRUB_AUTOPILOTCHECK=""
run_detect
t::assert_contains "$(cat "$ipc_file")" "mdm STATUS SKIPPED" "mdm: skipped when unconfigured"

# --- skipped: identifiers are N/A -------------------------------------------
TSCRUB_UPLOAD_URL="https://tscrub.com/api/reports"
TSCRUB_API_TOKEN="$(printf 'a%.0s' {1..64})"
TSCRUB_AUTOPILOTCHECK=1
SYS_SERIAL="N/A"
SYS_UUID="N/A"
run_detect
t::assert_contains "$(cat "$ipc_file")" "mdm STATUS SKIPPED" "mdm: skipped when identifiers N/A"

# --- unlocked ---------------------------------------------------------------
SYS_SERIAL="SYSSN123"
SYS_UUID="4C4C4544-0036-5710-8032-B5C04F433633"
SYS_MANUFACTURER="Fake Inc."
SYS_PRODUCT="FakeStation"
FAKE_MDM_VERDICT="unlocked"
FAKE_MDM_RC=""
FAKE_MDM_FAIL_FIRST=""
: > "$FAKE_MDM_CURL_LOG"
run_detect
t::assert_contains "$(cat "$ipc_file")" "mdm VERDICT unlocked" "mdm: unlocked verdict on IPC"
t::assert_contains "$(cat "$ipc_file")" "mdm STATUS UNLOCKED" "mdm: unlocked status on IPC"
t::assert_eq "unlocked" "$(cat "$MDM_RESULT_FILE")" "mdm: result file unlocked"
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" "SYSSN123" "mdm: request carries serial"
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" "4C4C4544-0036-5710-8032-B5C04F433633" "mdm: request carries uuid"
t::assert_contains "$(cat "$FAKE_MDM_CURL_LOG")" "api/mdm/autopilot" "mdm: request hits mdm endpoint"

# --- locked (this tenant) ---------------------------------------------------
FAKE_MDM_VERDICT="locked_this"
run_detect
t::assert_contains "$(cat "$ipc_file")" "mdm VERDICT locked_this" "mdm: locked_this verdict on IPC"
t::assert_contains "$(cat "$ipc_file")" "mdm STATUS LOCKED" "mdm: locked status on IPC"

# --- no staged hash (N/A) ---------------------------------------------------
FAKE_MDM_VERDICT="na"
run_detect
t::assert_contains "$(cat "$ipc_file")" "mdm VERDICT na" "mdm: na verdict on IPC"
t::assert_contains "$(cat "$ipc_file")" "mdm STATUS NA" "mdm: na status on IPC"

# --- queued/checking on the server: display immediately, don't poll ---------
FAKE_MDM_VERDICT=""
FAKE_MDM_QUEUE="queued"
FAKE_MDM_RC=""
FAKE_MDM_FAIL_FIRST=""
: > "$FAKE_MDM_CURL_LOG"
run_detect
t::assert_contains "$(cat "$ipc_file")" "mdm VERDICT checking" "mdm: queued server state → checking verdict"
t::assert_contains "$(cat "$ipc_file")" "mdm STATUS CHECKING" "mdm: queued server state → CHECKING status"
t::check "mdm: no status poll issued" '! grep -q "api/mdm/status" "$FAKE_MDM_CURL_LOG"'
FAKE_MDM_QUEUE=""

# --- offline (curl fails) ---------------------------------------------------
FAKE_MDM_VERDICT=""
FAKE_MDM_RC=7
run_detect
t::assert_contains "$(cat "$ipc_file")" "mdm VERDICT offline" "mdm: offline verdict on IPC"
t::assert_contains "$(cat "$ipc_file")" "mdm STATUS OFFLINE" "mdm: offline status on IPC"

# --- TLS retry: first call exits 60, retry with -k succeeds -----------------
FAKE_MDM_RC=""
FAKE_MDM_VERDICT="unlocked"
FAKE_MDM_FAIL_FIRST=1
rm -f "$FAKE_MDM_FAIL_FLAG"
: > "$FAKE_MDM_CURL_LOG"
run_detect
t::assert_contains "$(cat "$ipc_file")" "mdm VERDICT unlocked" "mdm: TLS retry succeeds → unlocked"
t::check "mdm: TLS retry used -k" "grep -q -- '-k' '$FAKE_MDM_CURL_LOG'"

# --- network down: worker skips the probe (no curl attempt) -----------------
FAKE_MDM_FAIL_FIRST=""
FAKE_MDM_VERDICT="unlocked"
TSCRUB_API_TOKEN="$(printf 'a%.0s' {1..64})"
TSCRUB_AUTOPILOTCHECK=1
SYS_SERIAL="SYSSN123"
SYS_UUID="4C4C4544-0036-5710-8032-B5C04F433633"
: > "$FAKE_MDM_CURL_LOG"
network::ensure() { return 1; }   # simulate no IPv4 route
run_detect
t::assert_contains "$(cat "$ipc_file")" "mdm VERDICT offline" "mdm: offline when network down"
t::check "mdm: no curl attempt when network down" '[[ ! -s "$FAKE_MDM_CURL_LOG" ]]'

rm -rf "$tmpdir"
t::summary
