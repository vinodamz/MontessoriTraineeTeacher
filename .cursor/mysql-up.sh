#!/usr/bin/env bash
# Idempotently ensure the local MySQL server is running and accepting
# connections. Safe to call from both install and start.
#
# We start mysqld directly rather than via `service mysql start`: the Debian
# SysV init script launches the daemon through `su - mysql`, which is
# unreliable inside Cloud Agent containers (it can silently fail to spawn
# mysqld and then time out after 30s with nothing written to the error log).
# `mysqld --daemonize` forks and only returns success once the server is
# ready to accept connections.
set -euo pipefail

if sudo mysqladmin ping >/dev/null 2>&1; then
  echo "   -> MySQL already running."
  exit 0
fi

# /run is a fresh tmpfs on every boot, so the runtime dir may be absent.
sudo install -d -o mysql -g mysql -m 755 /var/run/mysqld

echo "==> Starting MySQL (mysqld --daemonize)…"
sudo -u mysql /usr/sbin/mysqld --daemonize --pid-file=/var/run/mysqld/mysqld.pid \
  2> >(sudo tee -a /var/log/mysql/error.log >/dev/null) || true

for i in $(seq 1 60); do
  if sudo mysqladmin ping >/dev/null 2>&1; then
    echo "   -> MySQL is up."
    exit 0
  fi
  sleep 1
done

echo "MySQL failed to start; recent error log:" >&2
sudo tail -40 /var/log/mysql/error.log >&2 || true
exit 1
