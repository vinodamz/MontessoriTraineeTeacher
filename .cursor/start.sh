#!/usr/bin/env bash
# Per-boot startup: bring MySQL up and wait until it accepts connections.
# The PHP dev server itself runs as a visible terminal (see environment.json).
set -euo pipefail

echo "==> Starting MySQL server…"
sudo service mysql start || true

echo "==> Waiting for MySQL to accept connections…"
for i in $(seq 1 60); do
  if sudo mysqladmin ping >/dev/null 2>&1; then
    echo "   -> MySQL is up."
    exit 0
  fi
  sleep 1
done

echo "   -> MySQL did not become ready in time." >&2
exit 1
