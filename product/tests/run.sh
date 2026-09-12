#!/usr/bin/env bash
# Run the tscrub test suite. Requires bash 4+.
if (( BASH_VERSINFO[0] < 4 )); then
    echo "This test suite requires bash 4+ (found ${BASH_VERSINFO[0]}.${BASH_VERSINFO[1]})." >&2
    echo "Run with:  /opt/homebrew/bin/bash tests/run.sh" >&2
    exit 2
fi

TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$TESTS_DIR/.." && pwd)"

echo "== syntax check (bash -n) =="
syntax_fail=0
for f in "$ROOT_DIR"/src/*.sh "$ROOT_DIR"/scripts/*.sh; do
    if ! "$BASH" -n "$f"; then
        echo "SYNTAX ERROR: $f" >&2
        syntax_fail=1
    fi
done
[[ $syntax_fail -eq 0 ]] && echo "ok - all source files parse"

echo
echo "== unit & integration tests =="
overall=0
for t in "$TESTS_DIR"/test_*.sh; do
    echo
    echo "--- ${t##*/} ---"
    if ! "$BASH" "$t"; then
        overall=1
    fi
done

echo
if (( overall == 0 && syntax_fail == 0 )); then
    echo "ALL TESTS PASSED"
    exit 0
else
    echo "TESTS FAILED"
    exit 1
fi
