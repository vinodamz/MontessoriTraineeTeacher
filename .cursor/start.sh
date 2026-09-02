#!/usr/bin/env bash
# Per-boot startup: bring MySQL up and wait until it accepts connections.
# The PHP dev server itself runs as a visible terminal (see environment.json).
set -euo pipefail

# /run is a fresh tmpfs on every boot, so the runtime dir MySQL needs may be
# absent. Create it before starting so mysqld can write its socket/pid.
sudo install -d -o mysql -g mysql -m 755 /var/run/mysqld

echo "==> Starting MySQL server…"
# The SysV init script gives up after ~30s; don't let its exit code abort us —
# we poll for real readiness below instead.
sudo service mysql start || true

echo "==> Waiting for MySQL to accept connections…"
for i in $(seq 1 60); do
  if sudo mysqladmin ping >/dev/null 2>&1; then
    echo "   -> MySQL is up."
    exit 0
  fi
  sleep 1
done

echo "   -> MySQL did not become ready in time; recent error log:" >&2
sudo tail -30 /var/log/mysql/error.log >&2 || true
exit 1
