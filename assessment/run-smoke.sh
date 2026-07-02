#!/bin/bash
# assessment/run-smoke.sh — drive the master-spec smoke from .cpanel.yml.
# Same shape as inventory/run-smoke.sh: HTTP loopback first, CLI fallback.
set +e

HERE="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="http://127.0.0.1/assessment/smoke_internal.php"
HOST_HEADER="mtt.thelittlegraduates.in"

echo "--- HTTP loopback ($TARGET, Host: $HOST_HEADER) ---"
HTTP_OUT=$(curl -sS --max-time 30 -H "Host: ${HOST_HEADER}" "$TARGET" 2>&1)
HTTP_EXIT=$?
printf '%s\n' "$HTTP_OUT"
echo "(HTTP exit=$HTTP_EXIT)"

if printf '%s' "$HTTP_OUT" | grep -q '^PASS'; then
    exit 0
fi

echo
echo "--- CLI fallback (HTTP loopback did not PASS) ---"
cd "$HERE" && php assessment/smoke_internal.php
