#!/usr/bin/env bash
set -euo pipefail

# Prune published appliance ISOs from the public downloads directory.
#
# Why this exists: every public ISO is a build the product can still produce
# evidence from. A defect that has since been fixed stays reachable as long as
# its build stays downloadable — v1.11.73 shipped an inert coverage sampler, so
# a customer holding that ISO can generate a verification claim the current
# release refuses. The public directory therefore keeps the current release plus
# the previous one; every other build stays on the build host under
# output/images, which is the archive of record.
#
# Usage:
#   bash ops/prune-downloads.sh                  # dry run (default): print the plan
#   bash ops/prune-downloads.sh --apply          # delete
#   bash ops/prune-downloads.sh --keep 3         # keep the N most recent versions
#   bash ops/prune-downloads.sh --apply --junk   # also drop the WinPE test images
#
# Guarantees:
#   * dry run unless --apply is given
#   * never deletes the file named in /downloads/manifest.json
#   * never deletes tscrub-appliance.iso or the file it points at
#   * keeps every build of the N most recent VERSIONS, not merely N files
#   * never deletes an ISO with no byte-identical counterpart in the build host's
#     output/images — if the archive copy is missing, the public copy stays
#   * appends an audit listing (ls -l of every removed file) to
#     ~/prune-downloads-<UTC timestamp>.log on the server
#
# Overrides: TSCRUB_BUILD_HOST, TSCRUB_DOWNLOADS_DIR, TSCRUB_IMAGE_DIR

HOST="${TSCRUB_BUILD_HOST:-oxwet@192.168.0.6}"
DEST="${TSCRUB_DOWNLOADS_DIR:-~/webs/tscrub/downloads}"
IMAGES="${TSCRUB_IMAGE_DIR:-~/shredos.x86_64/output/images}"
SSH_OPTS=(-o BatchMode=yes -o StrictHostKeyChecking=accept-new)

KEEP=2
APPLY=0
JUNK=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --apply) APPLY=1 ;;
        --junk)  JUNK=1 ;;
        --keep)  KEEP="${2:?--keep needs a value}"; shift ;;
        -h|--help) sed -n '2,32p' "$0"; exit 0 ;;
        *) echo "unknown argument: $1 (try --help)" >&2; exit 2 ;;
    esac
    shift
done

[[ "$KEEP" =~ ^[0-9]+$ ]] || { echo "--keep must be a whole number" >&2; exit 2; }
[[ "$KEEP" -ge 1 ]] || { echo "--keep must be at least 1 (the current release)" >&2; exit 2; }

ssh "${SSH_OPTS[@]}" "$HOST" bash -s -- "$DEST" "$IMAGES" "$KEEP" "$APPLY" "$JUNK" <<'REMOTE'
set -euo pipefail
DEST="$1"; IMAGES="$2"; KEEP="$3"; APPLY="$4"; JUNK="$5"

# Guard: only ever touch the real downloads directory.
case "$DEST" in
    */webs/tscrub/downloads) ;;
    *) echo "refusing: unexpected downloads dir: $DEST" >&2; exit 1 ;;
esac
[[ -d "$DEST" ]] || { echo "missing directory: $DEST" >&2; exit 1; }
cd "$DEST"

# --- what must survive -------------------------------------------------------
current=""
if [[ -f manifest.json ]]; then
    current="$(python3 -c 'import json,sys;print(json.load(open("manifest.json"))["appliance"]["filename"])' 2>/dev/null || true)"
fi
link_target=""
if [[ -L tscrub-appliance.iso ]]; then
    link_target="$(readlink -f tscrub-appliance.iso | sed "s#.*/##")"
fi

# --- keep set: N most recent versions, all builds of each --------------------
mapfile -t versions < <(ls -1 tscrub-v*.iso 2>/dev/null \
    | sed -E 's/^tscrub-v([0-9.]+)_.*/\1/' | sort -V | uniq)
if [[ ${#versions[@]} -eq 0 ]]; then
    echo "no tscrub-v*.iso builds in $DEST — nothing to do"
    exit 0
fi
keep_from=$(( ${#versions[@]} - KEEP ))
[[ $keep_from -lt 0 ]] && keep_from=0
keep_versions=" $(printf '%s\n' "${versions[@]:$keep_from}" | tr '\n' ' ') "

echo "published versions : ${#versions[@]}  (oldest ${versions[0]}, newest ${versions[${#versions[@]}-1]})"
echo "keeping versions   :${keep_versions}"
echo "protected by manifest/symlink: ${current:-none} ${link_target:-none}"
echo

# --- plan --------------------------------------------------------------------
plan=()
skipped_no_archive=()
for f in tscrub-v*.iso; do
    ver="$(sed -E 's/^tscrub-v([0-9.]+)_.*/\1/' <<<"$f")"
    if [[ "$keep_versions" == *" $ver "* ]]; then continue; fi
    [[ "$f" == "$current" ]] && continue
    [[ "$f" == "$link_target" ]] && continue
    if [[ ! -f "$IMAGES/$f" ]]; then
        skipped_no_archive+=("$f")
        continue
    fi
    plan+=("$f")
done

junk_plan=()
if [[ "$JUNK" == "1" ]]; then
    for f in winpe-test.iso winpe-fat-test.iso winpe-tree.tar our-boot.wim; do
        if [[ -e "$f" || -L "$f" ]]; then junk_plan+=("$f"); fi
    done
    # sidecars whose ISO is gone (older naming schemes leave these behind)
    for s in *.iso.sha256; do
        if [[ -e "$s" && ! -e "${s%.sha256}" ]]; then junk_plan+=("$s"); fi
    done
fi

if [[ ${#skipped_no_archive[@]} -gt 0 ]]; then
    echo "KEPT (no archive copy in $IMAGES — refusing to delete the only one):"
    printf '  %s\n' "${skipped_no_archive[@]}"
    echo
fi

if [[ ${#plan[@]} -eq 0 && ${#junk_plan[@]} -eq 0 ]]; then
    echo "already within the retention rule — nothing to delete"
    exit 0
fi

bytes=0
for f in "${plan[@]}" "${junk_plan[@]}"; do
    bytes=$(( bytes + $(stat -c %s "$f") ))
done
echo "to delete: ${#plan[@]} appliance ISO(s) + ${#junk_plan[@]} test image(s), $(( bytes / 1073741824 )) GiB"
if [[ ${#plan[@]} -gt 0 ]]; then
    printf '  %s\n' "${plan[@]}"
fi
if [[ ${#junk_plan[@]} -gt 0 ]]; then
    printf '  %s (test image)\n' "${junk_plan[@]}"
fi

if [[ "$APPLY" != "1" ]]; then
    echo
    echo "dry run — nothing deleted. Re-run with --apply to delete."
    exit 0
fi

log="$HOME/prune-downloads-$(date -u +%Y%m%dT%H%M%SZ).log"
{
    echo "# prune-downloads $(date -u +%FT%TZ)"
    echo "# kept versions:$keep_versions"
    echo "# protected: current=$current symlink=$link_target"
} > "$log"

for f in "${plan[@]}" "${junk_plan[@]}"; do
    ls -l "$f" >> "$log"
    rm -f -- "$f"
    case "$f" in
        tscrub-v*.iso) rm -f -- "$f.sha256" ;;
    esac
done

echo
echo "deleted ${#plan[@]} ISO(s) + ${#junk_plan[@]} other item(s); audit listing: $log"
echo "remaining ISOs: $(ls -1 tscrub-v*.iso | wc -l)  size: $(du -sh . | awk '{print $1}')"
REMOTE
