#!/usr/bin/env bash
# The HP WMI request frame is built in C, because it ships inside the
# appliance's kernel module (board/shredos/modules/hp_biospw). Compiling that
# source for the host and running its self-test is the only part of the WMI
# clear that can be proven without hardware — and it is the part that matters
# most, because a wrong byte here is silently the wrong credential.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

mod_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../board/shredos/modules/hp_biospw" && pwd)"

if ! command -v cc >/dev/null 2>&1; then
    echo "skip - no host compiler (cc) available"
    t::summary
    exit 0
fi

tmp="$(mktemp -d)"
bin="$tmp/test_hp_biospw_frame"

if cc -std=c11 -O1 -Wall -Wextra -Werror \
      -o "$bin" "$mod_dir/test_hp_biospw_frame.c" 2>"$tmp/cc.log"; then
    t::check "frame: compiles with -Wall -Wextra -Werror" "true"
else
    t::check "frame: compiles with -Wall -Wextra -Werror" "false"
    sed 's/^/      /' "$tmp/cc.log"
fi

if [[ -x "$bin" ]]; then
    out="$("$bin" 2>&1)"
    rc=$?
    t::check "frame: self-test exits 0" "[[ $rc -eq 0 ]]"
    t::assert_contains "$out" "0 failure" "frame: encoder reports no failures"
    t::assert_contains "$out" "OK" "frame: encoder finished"
    echo "$out" | sed 's/^/      /'
fi

rm -rf "$tmp"
t::summary
