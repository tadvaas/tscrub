#!/usr/bin/env bash
# Automatic PSID revert: serial lookup, the shared device::psid_revert
# primitive, policy dispatch (off/psid), map-file parsing, and the
# "PSID is never written to the log" guard. The interactive `ask` path is not
# exercised here — it reads /dev/tty and would block under a test harness.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

declare -A capability bus opal_locked devrow serial model

PSID="ABCDEFGHIJKLMNOPQRSTUVWXYZ123456"   # 32 chars
LOG_FILE="$(mktemp /tmp/tscrub-psid-log.XXXXXX)"
TABLE_INDENT="    "

# --- lookup: serial is trimmed + uppercased before the map lookup ----------
devices=(sda)
serial[sda]="  s3wx1234ab  "
PSID_MAP["S3WX1234AB"]="$PSID"
t::assert_eq "$PSID" "$(device::psid_lookup sda)" "lookup normalises the serial"

# --- lookup: no entry -> empty ---------------------------------------------
serial[sda]="ZZZZ999999"
t::assert_eq "" "$(device::psid_lookup sda)" "lookup miss -> empty"

# --- psid_revert: success unlocks + records yes ----------------------------
devices=(sda)
serial[sda]="S3WX1234AB"
opal_locked[sda]="YES"
devrow=()
LOCK="$(mktemp /tmp/tscrub-psid-lock.XXXXXX)"
export FAKE_SEDUTIL_LOCK_STATE="$LOCK"
export FAKE_PSID_RC=0
printf '1' > "$LOCK"
device::psid_revert sda "$PSID"
t::assert_eq "NO" "${opal_locked[sda]}" "revert success -> opal_locked=NO"
t::assert_eq "yes" "${devrow[sda.psid_revert]}" "revert success -> psid_revert=yes"

# --- psid_revert: refusal leaves it locked + records failed ----------------
devices=(sda)
opal_locked[sda]="YES"
devrow=()
export FAKE_PSID_RC=1
printf '1' > "$LOCK"
device::psid_revert sda "$PSID"
t::assert_eq "YES" "${opal_locked[sda]}" "revert refusal -> still locked"
t::assert_eq "failed" "${devrow[sda.psid_revert]}" "revert refusal -> psid_revert=failed"
unset FAKE_PSID_RC FAKE_SEDUTIL_LOCK_STATE

# --- psid_revert: the PSID never lands in the log --------------------------
devices=(sda)
opal_locked[sda]="YES"
devrow=()
export FAKE_PSID_RC=0
printf '1' > "$LOCK"
: > "$LOG_FILE"
device::psid_revert sda "$PSID"
t::check "PSID never written to the log" "! grep -q '$PSID' '$LOG_FILE'"
unset FAKE_PSID_RC

# --- handle_locks: off policy leaves the drive locked, no revert -----------
devices=(sda)
serial[sda]="S3WX1234AB"
opal_locked[sda]="YES"
devrow=()
export TS_BLOCK_SID_POLICY=off
export FAKE_SEDUTIL_LOG="$(mktemp /tmp/tscrub-psid-call.XXXXXX)"
device::handle_locks
t::assert_eq "YES" "${opal_locked[sda]}" "off: drive stays locked"
t::check "off: PSID revert never attempted" \
    "! grep -q -- '--yesIreallywanttoERASEALLmydatausingthePSID' '$FAKE_SEDUTIL_LOG'"
unset TS_BLOCK_SID_POLICY FAKE_SEDUTIL_LOG

# --- handle_locks: psid policy + map hit unlocks ---------------------------
devices=(sda)
serial[sda]="S3WX1234AB"
opal_locked[sda]="YES"
devrow=()
LOCK="$(mktemp /tmp/tscrub-psid-lock.XXXXXX)"; printf '1' > "$LOCK"
export TS_BLOCK_SID_POLICY=psid
export FAKE_PSID_RC=0
export FAKE_SEDUTIL_LOCK_STATE="$LOCK"
device::handle_locks
t::assert_eq "NO" "${opal_locked[sda]}" "psid+hit: drive unlocked"
t::assert_eq "yes" "${devrow[sda.psid_revert]}" "psid+hit: psid_revert=yes"
unset TS_BLOCK_SID_POLICY FAKE_PSID_RC FAKE_SEDUTIL_LOCK_STATE

# --- handle_locks: psid policy + no map entry -> na, stays locked ----------
devices=(sda)
serial[sda]="ZZZZ999999"
opal_locked[sda]="YES"
devrow=()
export TS_BLOCK_SID_POLICY=psid
device::handle_locks
t::assert_eq "YES" "${opal_locked[sda]}" "psid+miss: drive stays locked"
t::assert_eq "na" "${devrow[sda.psid_revert]}" "psid+miss: psid_revert=na"
unset TS_BLOCK_SID_POLICY

# --- config::load_psids: map-file parsing via mocked mount/lsblk -----------
# Mock the USB scan so the line parser runs without a real stick: one volume
# whose psids.conf carries a comment, whitespace, lowercase, and a short PSID.
PSID_CONF='# comment line
S3WX1234AB = abcdefghijklmnopqrstuvwxyz123456

BADONE = TOOSHORT
GOOD2=ABCDEFGHIJKLMNOPQRSTUVWXYZ789012'
lsblk() { printf 'sdb1 part 1 vfat\n'; }
findmnt() { return 0; }
mount()  { local mnt="${!#}"; mkdir -p "$mnt"; printf '%s\n' "$PSID_CONF" > "$mnt/psids.conf"; return 0; }
umount() { return 0; }
PSID_MAP=()
config::load_psids
t::assert_eq "ABCDEFGHIJKLMNOPQRSTUVWXYZ123456" "${PSID_MAP[S3WX1234AB]:-}" \
    "map parsing: lowercased PSID uppercased"
t::assert_eq "ABCDEFGHIJKLMNOPQRSTUVWXYZ789012" "${PSID_MAP[GOOD2]:-}" \
    "map parsing: compact SERIAL=PSID accepted"
t::assert_eq "" "${PSID_MAP[BADONE]:-}" "map parsing: short PSID skipped"

# --- psid_revert cascade: method 1 fails -> method 2 (Admin SP) succeeds ----
devices=(sda)
serial[sda]="S3WX1234AB"
opal_locked[sda]="YES"
devrow=()
LOCK="$(mktemp /tmp/tscrub-psid-lock.XXXXXX)"; printf '1' > "$LOCK"
export FAKE_PSID_RC=1
export FAKE_PSID_ADMINSP_RC=0
export FAKE_SEDUTIL_LOCK_STATE="$LOCK"
device::psid_revert sda "$PSID"
t::assert_eq "NO" "${opal_locked[sda]}" "cascade: Admin-SP revert unlocks after plain revert fails"
t::assert_eq "yes" "${devrow[sda.psid_revert]}" "cascade: psid_revert=yes"
unset FAKE_PSID_RC FAKE_PSID_ADMINSP_RC FAKE_SEDUTIL_LOCK_STATE

# --- lock_kind: a locked drive is classified 'locked' ----------------------
devices=(sda)
serial[sda]="S3WX1234AB"
opal_locked[sda]="YES"
devrow=()
export TS_BLOCK_SID_POLICY=off
device::handle_locks
t::assert_eq "locked" "${devrow[sda.lock_kind]}" "locked drive -> lock_kind=locked"
unset TS_BLOCK_SID_POLICY

# --- normalize_outcome: BLOCKED -> lock_kind=password ----------------------
devices=(sda)
devrow=()
devrow[sda.status]="BLOCKED"
device::normalize_outcome
t::assert_eq "password" "${devrow[sda.lock_kind]}" "BLOCKED -> lock_kind=password"
t::assert_eq "Blocked by firmware (Block SID)" "${devrow[sda.method]}" "BLOCKED method label"

# --- normalize_outcome: locked drive reports the honest method --------------
devices=(sda)
devrow=()
devrow[sda.status]="FAILED"
devrow[sda.lock_kind]="locked"
device::normalize_outcome
t::assert_eq "TCG locked — physical destruction required" "${devrow[sda.method]}" "locked drive -> honest method"

t::summary
