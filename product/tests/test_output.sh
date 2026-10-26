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

# --- mount_boot_usb: fixed-disk FAT (internal EFI) is ignored, removable used ---
USBROOT="$(mktemp -d)"
FAKE_REM="$USBROOT/removable"
FAKE_FIXED="$USBROOT/fixed"
mkdir -p "$FAKE_REM/boot" "$FAKE_FIXED"
printf 'tscrub\n' > "$FAKE_REM/boot/version.txt"
MOUNT_LOG="$(mktemp)"

lsblk() { printf 'sda1 part 0 vfat\nsdb1 part 1 vfat\n'; }
mount() {
    local mnt="${@: -1}" dev=""
    for a in "$@"; do [[ "$a" == /dev/* ]] && dev="$a"; done
    echo "$dev" >> "$MOUNT_LOG"
    case "$dev" in
        /dev/sdb1) cp -a "$FAKE_REM"/. "$mnt"/ ;;
        /dev/sda1) cp -a "$FAKE_FIXED"/. "$mnt"/ ;;
    esac
    return 0
}
umount() { return 0; }
rmdir()  { return 0; }

LICENSE_USB_DEV=""
REPORT_DIR="/tmp/sentinel/"
REPORT_USB_MNT=""
report::mount_boot_usb
t::check "mount_boot_usb finds the removable stick" '[[ -n "$REPORT_USB_MNT" && -f "$REPORT_USB_MNT/boot/version.txt" ]]'
t::check "mount_boot_usb never mounts a fixed disk" '! grep -q "/dev/sda1" "$MOUNT_LOG"'
unset -f lsblk mount umount rmdir
rm -rf "$USBROOT" "$MOUNT_LOG"

# --- mount_boot_usb: an unidentified FAT stick is NOT claimed ----------------
# A plain data stick must never become the report destination: tScrub would
# write its report onto somebody's media AND, because a mounted volume protects
# its whole disk, make that stick permanently un-erasable. That is exactly how
# the bench ended up refusing to erase the only formatted stick it had.
USBROOT2="$(mktemp -d)"
FAKE_DATA="$USBROOT2/data"
mkdir -p "$FAKE_DATA"
lsblk() { printf 'sdb1 part 1 vfat\n'; }
mount() {
    local mnt="${@: -1}"
    cp -a "$FAKE_DATA"/. "$mnt"/ 2>/dev/null || true
    return 0
}
umount() { return 0; }
rmdir()  { return 0; }
LICENSE_USB_DEV=""
REPORT_DIR="/tmp/sentinel/"
REPORT_USB_MNT=""
report::mount_boot_usb
t::check "an unidentified FAT stick is never claimed" '[[ -z "$REPORT_USB_MNT" ]]'
printf 'licence\n' > "$FAKE_DATA/usb-bench.lic"
report::mount_boot_usb
t::check "a stick carrying a licence IS claimed" '[[ -n "$REPORT_USB_MNT" ]]'
REPORT_USB_MNT=""
rm -f "$FAKE_DATA/usb-bench.lic"
FAKE_BLKID_LABEL_sdb1="TSCRUB-USB" report::mount_boot_usb
t::check "a stick with the appliance label IS claimed" '[[ -n "$REPORT_USB_MNT" ]]'
REPORT_USB_MNT=""
unset -f lsblk mount umount rmdir
rm -rf "$USBROOT2"

# --- detect_output: --no-report-usb keeps the only stick ERASABLE ------------
# Without an escape hatch there is no way to wipe the single formatted stick on
# a bench, because tScrub would mount it for reports and then protect it.
REPORT_OUTPUT=""
REPORT_NO_USB=1
REPORT_DIR="/tmp/sentinel/"
REPORT_USB_MNT=""
lsblk() { printf 'sdb1 part 1 vfat\n'; }
mount() { return 0; }
report::detect_output 2>/dev/null
MOUNTED=1
report::detect_output 2>/dev/null
t::check "opt-out keeps the report in RAM" '[[ "$REPORT_DIR" == "/" && -z "$REPORT_USB_MNT" ]]'
t::assert_eq "fail" "${REPORT_USB_STATUS:-}" "opt-out records that the stick was not used"
unset -f lsblk mount
REPORT_NO_USB=0

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
