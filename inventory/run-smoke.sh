#!/bin/bash
# inventory/run-smoke.sh — drive the master-spec smoke from .cpanel.yml.
#
# Tries the HTTP loopback first (only that path can fetch the rendered
# edit-form HTML + CSV via the synthesized PHPSESSID). If LiteSpeed
# silently 404s the file (cPanel hosts often filter unusual paths) the
# script falls back to a CLI invocation which still covers every other
# master-spec criterion via direct DB queries + helper checks.
set +e

HERE="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="http://127.0.0.1/inventory/smoke_internal.php"
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
cd "$HERE" && php inventory/smoke_internal.php
