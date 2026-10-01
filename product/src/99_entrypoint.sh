# `tscrub verify <report.csv> [public-key.pem]` — verify a signed report.
if [[ "${1:-}" == "verify" ]]; then
    shift
    report::verify "$@"
    exit $?
fi

# Parse CLI options now that every function (including report::verify) is
# defined. Must run before fn_main, and AFTER the verify dispatch above.
parse_args "$@"

# A single persistent session: fn_main boots the triage screen and, on Shift+T,
# runs (and re-runs) the erasure workflow. The getty respawns tScrub when the
# process exits, so a fresh session starts on the next boot/exit.
fn_main
