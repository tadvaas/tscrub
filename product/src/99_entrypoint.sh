# `tscrub verify <report.csv> [public-key.pem]` — verify a signed report.
if [[ "${1:-}" == "verify" ]]; then
    shift
    report::verify "$@"
    exit $?
fi

# Parse CLI options now that every function (including report::verify) is
# defined. Must run before fn_main, and AFTER the verify dispatch above.
parse_args "$@"

# Anything that ends the session — Ctrl-C, a fatal bash error (an arithmetic
# expansion error kills a non-interactive shell outright), an OOM kill of the
# parent — must leave a durable note when an erasure was in flight. Without
# this, a run that died left NOTHING on disk: no certificate, no report, no
# journal entry, just a RAM log that vanished with the machine.
trap 'report::abort_record $?' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# A single persistent session: fn_main boots the triage screen and, on Shift+T,
# runs (and re-runs) the erasure workflow. The getty respawns tScrub when the
# process exits, so a fresh session starts on the next boot/exit.
fn_main
