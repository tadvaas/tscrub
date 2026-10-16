#!/usr/bin/env bash
# Post-erasure drive formatting: policy selection, tool cascade, per-drive
# gating, and the ok/unsupported/failed/skipped/n-a verdicts.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
t::setup_env
t::source_src

TDIR="$(mktemp -d)"
LOG_FILE="$TDIR/tscrub.log"   # keep format_drive's LOG writes out of /

# --- pure policy -------------------------------------------------------------
t::assert_eq "mbr fat32" "$(format::policy_for $((31*1024*1024*1024)) 512)" "31GiB -> mbr fat32"
t::assert_eq "mbr fat32" "$(format::policy_for $((32*1024*1024*1024)) 512)" "32GiB -> mbr fat32"
t::assert_eq "mbr exfat" "$(format::policy_for $((33*1024*1024*1024)) 512)" "33GiB -> mbr exfat"
t::assert_eq "gpt exfat" "$(format::policy_for $((0x100000000 * 512)) 512)" ">2^32 sectors -> gpt"
t::assert_eq "mbr exfat" "$(format::policy_for $((0xFFFFFFFE * 4096)) 4096)" "4Kn just under MBR limit -> mbr"
t::assert_eq "gpt exfat" "$(format::policy_for $((0x100000000 * 4096)) 4096)" "4Kn over MBR limit -> gpt"
t::assert_eq "mbr fat32" "$(format::policy_for 0 512)" "0 bytes -> mbr fat32"
t::assert_eq "mbr fat32" "$(format::policy_for bogus 512)" "non-numeric bytes -> mbr fat32"

# --- geometry helpers --------------------------------------------------------
t::assert_eq "sda1" "$(format::_part_node sda)" "part node sda1"
t::assert_eq "nvme0n1p1" "$(format::_part_node nvme0n1)" "part node nvme p1"
t::assert_eq "2048" "$(format::_start_sector 512)" "start 2048 @512"
t::assert_eq "256" "$(format::_start_sector 4096)" "start 256 @4Kn"

# --- label sanitise ----------------------------------------------------------
t::assert_eq "TSCRUB" "$(format::_sanitize_label 'tscrub')" "label uppercased"
t::assert_eq "TSCRUB" "$(format::_sanitize_label '')" "empty label -> TSCRUB"
t::assert_eq "TSCRUB123" "$(format::_sanitize_label 'tscrub-123')" "label strips punctuation"
t::assert_eq "ABCDEFGHIJK" "$(format::_sanitize_label 'abcdefghijklmnop')" "label truncated to 11"
t::assert_eq "TSCRUB" "$(format::_sanitize_label '***')" "all-punctuation label -> TSCRUB"

# --- tool availability (real cascade against fakes/bin) ----------------------
t::assert_eq "sfdisk" "$(format::_partitioner)" "partitioner prefers sfdisk"
t::assert_eq "mkfs.fat" "$(format::_mkfs_fat_bin)" "fat mkfs prefers mkfs.fat"
t::assert_eq "mkfs.exfat" "$(format::_mkfs_exfat_bin)" "exfat mkfs prefers mkfs.exfat"

# --- real shell-out integration (fakes) --------------------------------------
t::check "wipefs succeeds" 'format::_wipe_signature sda'
export FAKE_SFDISK_RC=0
t::check "sfdisk partition write mbr" 'format::_write_partition sda mbr fat32 2048'
t::check "sfdisk partition write gpt" 'format::_write_partition sda gpt exfat 2048'
export FAKE_SFDISK_RC=1
t::check "sfdisk failure -> rc 1" '! format::_write_partition sda mbr fat32 2048'
unset FAKE_SFDISK_RC
export FAKE_MKFS_RC=0
t::check "mkfs fat32 succeeds" 'format::_mkfs_run fat32 TSCRUB sda1'
t::check "mkfs exfat succeeds" 'format::_mkfs_run exfat TSCRUB sda1'
export FAKE_MKFS_RC=1
t::check "mkfs failure -> rc 1" '! format::_mkfs_run fat32 TSCRUB sda1'
unset FAKE_MKFS_RC
export FAKE_BLKID_TYPE=exfat
t::assert_eq "exfat" "$(format::_blkid_type sda1)" "blkid type"
unset FAKE_BLKID_TYPE

# --- per-drive gating (function overrides; no real device touched) -----------
declare -Ag secsize sectors
devices=(sda)
devrow=()
devrow[sda.selected]=1
devrow[sda.status]="COMPLETED"
devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
secsize[sda]=512
sectors[sda]=$(( 33*1024*1024*2 ))   # 33 GiB -> exfat

FORMAT_MODE=auto; FORMAT_FS=auto; FORMAT_SCHEME=auto; FORMAT_LABEL=""

format::_wipe_signature() { return 0; }
format::_write_partition() { PART_ARGS="$*"; return 0; }
format::_mkfs_run() { MKFS_ARGS="$*"; return 0; }
format::_reread() { return 0; }
format::_blkid_type() { echo exfat; }

format::format_drive sda
t::assert_eq "ok" "${devrow[sda.format_result]}" "completed drive formats ok"
t::assert_eq "exfat" "${devrow[sda.format_fs]}" "fs exfat"
t::assert_eq "mbr" "${devrow[sda.format_scheme]}" "scheme mbr"
t::assert_eq "TSCRUB" "${devrow[sda.format_label]}" "label TSCRUB"
t::assert_eq "blkid:exfat" "${devrow[sda.format_confirm]}" "confirm blkid exfat"
t::check "partition call carries mbr+exfat" '[[ "$PART_ARGS" == "sda mbr exfat 2048" ]]'
t::check "mkfs call carries fs+label+part" '[[ "$MKFS_ARGS" == "exfat TSCRUB sda1" ]]'

# small drive -> fat32
sectors[sda]=$(( 30*1024*1024*2 ))   # 30 GiB -> fat32
format::format_drive sda
t::assert_eq "fat32" "${devrow[sda.format_fs]}" "30GiB -> fat32"
t::check "partition call carries mbr+fat32" '[[ "$PART_ARGS" == "sda mbr fat32 2048" ]]'

# fs override
sectors[sda]=$(( 33*1024*1024*2 ))
FORMAT_FS=fat32
format::format_drive sda
t::assert_eq "fat32" "${devrow[sda.format_fs]}" "--fs fat32 forces fat32 on big drive"
FORMAT_FS=auto

# scheme override
FORMAT_SCHEME=gpt
format::format_drive sda
t::assert_eq "gpt" "${devrow[sda.format_scheme]}" "--scheme gpt overrides"
t::assert_eq "ok" "${devrow[sda.format_result]}" "gpt drive formats ok"
FORMAT_SCHEME=auto

# big drive (>2^32 sectors) -> gpt auto
sectors[sda]=$(( 0x100000001 ))
format::format_drive sda
t::assert_eq "gpt" "${devrow[sda.format_scheme]}" "over-limit drive -> gpt"

# force mbr on an over-limit drive -> failed, no write attempted
FORMAT_SCHEME=mbr
PART_ARGS=""
format::format_drive sda
t::assert_eq "failed" "${devrow[sda.format_result]}" "forced mbr on over-limit -> failed"
t::check "no partition write attempted" '[[ -z "$PART_ARGS" ]]'
FORMAT_SCHEME=auto
sectors[sda]=$(( 33*1024*1024*2 ))

# gating: not selected / not completed / off
devrow[sda.selected]=0
format::format_drive sda
t::assert_eq "n/a" "${devrow[sda.format_result]}" "unselected -> n/a"
devrow[sda.selected]=1
devrow[sda.status]="FAILED"
format::format_drive sda
t::assert_eq "n/a" "${devrow[sda.format_result]}" "failed wipe -> n/a"
devrow[sda.status]="COMPLETED"
FORMAT_MODE=off
format::format_drive sda
t::assert_eq "skipped" "${devrow[sda.format_result]}" "format off -> skipped"
FORMAT_MODE=auto

# unsupported: no tools at all
format::_partitioner() { :; }
format::_mkfs_exfat_bin() { :; }
format::format_drive sda
t::assert_eq "unsupported" "${devrow[sda.format_result]}" "no tools -> unsupported"

# unsupported: mbr requested but only sgdisk (GPT-only) present
format::_partitioner() { echo sgdisk; }
format::_mkfs_exfat_bin() { echo mkfs.exfat; }
format::format_drive sda
t::assert_eq "unsupported" "${devrow[sda.format_result]}" "sgdisk cannot write mbr -> unsupported"

# gpt via sgdisk works
FORMAT_SCHEME=gpt
format::format_drive sda
t::assert_eq "ok" "${devrow[sda.format_result]}" "sgdisk gpt -> ok"
FORMAT_SCHEME=auto

# failed: partition write fails
format::_partitioner() { echo sfdisk; }
format::_write_partition() { return 1; }
format::format_drive sda
t::assert_eq "failed" "${devrow[sda.format_result]}" "partition write failure -> failed"

# failed: mkfs fails
format::_write_partition() { return 0; }
format::_mkfs_run() { return 1; }
format::format_drive sda
t::assert_eq "failed" "${devrow[sda.format_result]}" "mkfs failure -> failed"

# failed: signature wipe fails
format::_mkfs_run() { return 0; }
format::_wipe_signature() { return 1; }
format::format_drive sda
t::assert_eq "failed" "${devrow[sda.format_result]}" "signature wipe failure -> failed"

# blkid absent -> ok without a confirmation observation
format::_wipe_signature() { return 0; }
format::_blkid_type() { :; }
format::format_drive sda
t::assert_eq "ok" "${devrow[sda.format_result]}" "ok without blkid"
t::check "confirm empty when blkid absent" '[[ -z "${devrow[sda.format_confirm]}" ]]'

# --- run_all iterates every device ------------------------------------------
format::_partitioner() { echo sfdisk; }
format::_mkfs_exfat_bin() { echo mkfs.exfat; }
format::_mkfs_fat_bin() { echo mkfs.fat; }
format::_wipe_signature() { return 0; }
format::_write_partition() { return 0; }
format::_mkfs_run() { return 0; }
format::_reread() { return 0; }
format::_blkid_type() { echo exfat; }

devices=(sda sdb)
devrow=()
devrow[sda.selected]=1; devrow[sda.status]="COMPLETED"; devrow[sda.capability]="CAP_ATA_PURGE_ENHANCED"
devrow[sdb.selected]=1; devrow[sdb.status]="FAILED";   devrow[sdb.capability]="CAP_NONE"
secsize[sda]=512; secsize[sdb]=512
sectors[sda]=$(( 33*1024*1024*2 )); sectors[sdb]=0
FORMAT_MODE=auto; FORMAT_FS=auto; FORMAT_SCHEME=auto; FORMAT_LABEL=""
format::run_all
t::assert_eq "ok" "${devrow[sda.format_result]}" "run_all formats completed drive"
t::assert_eq "n/a" "${devrow[sdb.format_result]}" "run_all leaves failed drive n/a"

# --- config resolution -------------------------------------------------------
FORMAT_MODE=""; FORMAT_FS=""; FORMAT_SCHEME=""; FORMAT_LABEL=""
parse_args --format off
t::assert_eq "off" "$FORMAT_MODE" "--format off"
parse_args --format=auto
t::assert_eq "auto" "$FORMAT_MODE" "--format=auto"
parse_args --fs exfat
t::assert_eq "exfat" "$FORMAT_FS" "--fs exfat"
parse_args --scheme gpt
t::assert_eq "gpt" "$FORMAT_SCHEME" "--scheme gpt"
parse_args --label MYLABEL
t::assert_eq "MYLABEL" "$FORMAT_LABEL" "--label MYLABEL"
FORMAT_MODE="bogus"; FORMAT_FS="bogus"; FORMAT_SCHEME="bogus"; FORMAT_LABEL=""
format::resolve_config
t::assert_eq "auto" "$FORMAT_MODE" "bogus mode -> auto"
t::assert_eq "auto" "$FORMAT_FS" "bogus fs -> auto"
t::assert_eq "auto" "$FORMAT_SCHEME" "bogus scheme -> auto"
t::assert_eq "TSCRUB" "$FORMAT_LABEL" "empty label -> TSCRUB"

t::summary
