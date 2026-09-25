#!/usr/bin/env bash
# tScrub Proxmox test runner. Runs ON the Proxmox host.
#   ./run.sh all                # run every scenario
#   ./run.sh 01_nvme_only       # run one scenario
set -euo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCEN="$HARNESS_DIR/scenarios"

scenarios() { ls "$SCEN"/*.sh | xargs -n1 basename | sed 's/\.sh$//' | sort; }

run_one() {
    local s="$1"
    echo "==================================================="
    echo "== scenario: $s"
    echo "==================================================="
    if [[ -x "$SCEN/$s.sh" ]]; then
        "$SCEN/$s.sh"
    else
        echo "FAIL: unknown scenario $s" >&2
        return 1
    fi
}

main() {
    local rc=0
    if [[ $# -eq 0 || "$1" == "all" ]]; then
        for s in $(scenarios); do run_one "$s" || rc=1; done
    else
        for s in "$@"; do run_one "$s" || rc=1; done
    fi
    exit "$rc"
}

main "$@"
