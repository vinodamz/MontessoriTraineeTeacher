#!/usr/bin/env bash
# Idempotently ensure the local MySQL server is running and accepting
# connections. Safe to call from both install and start.
#
# Why not `service mysql start` or `mysqld --daemonize`?
#   - The Debian SysV init script launches mysqld through `su - mysql`, which
#     is unreliable inside Cloud Agent containers.
#   - `mysqld --daemonize` hits MySQL bug MY-011065 ("Unable to determine if
#     daemon is running: Invalid argument") inside containers, because the
#     forking parent's readiness check syscall returns EINVAL.
# So we launch mysqld in the foreground and background it ourselves with
# nohup + setsid, then poll for readiness. This is the most portable option.
set -uo pipefail

CONSOLE_LOG=/tmp/mysqld-console.log

if sudo mysqladmin ping >/dev/null 2>&1; then
  echo "   -> MySQL already running."
  exit 0
fi

# /run is a fresh tmpfs on every boot, so the runtime dir may be absent.
sudo install -d -o mysql -g mysql -m 755 /var/run/mysqld

echo "==> Starting MySQL (foreground mysqld, backgrounded)…"
# setsid detaches mysqld into its own session so it survives this script
# exiting; nohup ignores SIGHUP. Output goes to a console log we can inspect.
sudo -u mysql bash -c "setsid nohup /usr/sbin/mysqld --user=mysql \
  --pid-file=/var/run/mysqld/mysqld.pid >'$CONSOLE_LOG' 2>&1 < /dev/null &"

for i in $(seq 1 90); do
  if sudo mysqladmin ping >/dev/null 2>&1; then
    echo "   -> MySQL is up."
    exit 0
  fi
  sleep 1
done

echo "############ MySQL failed to start — diagnostics ############" >&2
echo "--- console log ($CONSOLE_LOG) ---" >&2
sudo cat "$CONSOLE_LOG" 2>&1 | head -60 >&2 || true
echo "--- error log tail ---" >&2
sudo tail -40 /var/log/mysql/error.log >&2 2>&1 || true
echo "--- mysqld processes ---" >&2
pgrep -a mysqld >&2 2>&1 || echo "(no mysqld process)" >&2
exit 1
