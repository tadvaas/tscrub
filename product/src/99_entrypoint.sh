# `tscrub verify <report.csv> [public-key.pem]` — verify a signed report.
if [[ "${1:-}" == "verify" ]]; then
    shift
    report::verify "$@"
    exit $?
fi

RERUN=1
while [[ "$RERUN" -eq 1 ]]; do
    fn_main
done
