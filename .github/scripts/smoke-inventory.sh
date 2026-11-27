#!/usr/bin/env bash
# Compatibility entry point: production fixture writes are retired.
# GET-only checks; SMOKE_TEST_* credentials are deliberately unused.
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
revision=${EXPECTED_SHA:-$(git -C "$script_dir/../.." rev-parse HEAD)}
exec python3 "$script_dir/verify-deploy.py" --base "${BASE:-https://mtt.thelittlegraduates.in}" --sha "$revision"
