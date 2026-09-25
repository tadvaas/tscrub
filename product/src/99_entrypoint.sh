# `tscrub verify <report.csv> [public-key.pem]` — verify a signed report.
if [[ "${1:-}" == "verify" ]]; then
    shift
    report::verify "$@"
    exit $?
fi

# Parse CLI options now that every function (including report::verify) is
# defined. Must run before fn_main, and AFTER the verify dispatch above.
parse_args "$@"

RERUN=1
while [[ "$RERUN" -eq 1 ]]; do
    fn_main
done
