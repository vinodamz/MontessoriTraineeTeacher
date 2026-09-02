#!/usr/bin/env bash
# Idempotently ensure the local MySQL server is running and accepting
# connections. Safe to call from both install and start.
#
# We start mysqld directly rather than via `service mysql start`: the Debian
# SysV init script launches the daemon through `su - mysql`, which is
# unreliable inside Cloud Agent containers. `mysqld --daemonize` forks and
# only returns success once the server is ready to accept connections.
set -uo pipefail

if sudo mysqladmin ping >/dev/null 2>&1; then
  echo "   -> MySQL already running."
  exit 0
fi

# /run is a fresh tmpfs on every boot, so the runtime dir may be absent.
sudo install -d -o mysql -g mysql -m 755 /var/run/mysqld

echo "==> Starting MySQL (mysqld --daemonize)…"
sudo -u mysql /usr/sbin/mysqld --daemonize --pid-file=/var/run/mysqld/mysqld.pid \
  >/tmp/mysqld-start.out 2>&1
rc=$?
echo "   -> mysqld --daemonize exit code: $rc"
if [ -s /tmp/mysqld-start.out ]; then
  echo "   -> mysqld startup output:"; sed 's/^/      /' /tmp/mysqld-start.out
fi

for i in $(seq 1 60); do
  if sudo mysqladmin ping >/dev/null 2>&1; then
    echo "   -> MySQL is up."
    exit 0
  fi
  sleep 1
done

echo "############ MySQL failed to start — diagnostics ############" >&2
echo "--- whoami / sudo -u mysql id ---" >&2
id >&2; sudo -n -u mysql id >&2 2>&1 || echo "(sudo -u mysql failed)" >&2
echo "--- datadir /var/lib/mysql ---" >&2
sudo ls -la /var/lib/mysql 2>&1 | head -20 >&2
echo "--- free -m ---" >&2
free -m >&2 2>&1 || true
echo "--- foreground mysqld (10s, all output) ---" >&2
sudo -u mysql timeout 10 /usr/sbin/mysqld --user=mysql --console 2>&1 | head -60 >&2 || true
echo "--- error log tail ---" >&2
sudo tail -40 /var/log/mysql/error.log >&2 2>&1 || true
echo "--- dmesg tail ---" >&2
sudo dmesg 2>&1 | tail -20 >&2 || true
exit 1
