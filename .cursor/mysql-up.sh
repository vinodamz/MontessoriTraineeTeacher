#!/usr/bin/env bash
# Idempotently ensure the local MySQL server is running and accepting
# connections. Safe to call from both install and start.
#
# Why not `service mysql start` or `mysqld --daemonize`?
#   - The Debian SysV init script launches mysqld through `su - mysql`, which
#     is unreliable inside Cloud Agent containers.
#   - `mysqld --daemonize` hits MySQL bug MY-011065 ("Unable to determine if
#     daemon is running: Invalid argument") inside containers.
# So we launch mysqld in the foreground and background it with setsid+nohup.
#
# innodb_use_native_aio=OFF: some Cloud Agent build containers block Linux
# native AIO / io_uring syscalls via seccomp, which makes InnoDB abort during
# startup (mysqld dies before it can even write to its error log). Falling
# back to synchronous I/O keeps MySQL portable across those sandboxes.
set -uo pipefail

BOOT_LOG=/tmp/mysqld-boot.log
CONSOLE_LOG=/tmp/mysqld-console.log

if sudo mysqladmin ping >/dev/null 2>&1; then
  echo "   -> MySQL already running."
  exit 0
fi

# /run is a fresh tmpfs on every boot, so the runtime dir may be absent.
sudo install -d -o mysql -g mysql -m 755 /var/run/mysqld

echo "==> Starting MySQL (foreground mysqld, native AIO off)…"
sudo -u mysql bash -c "setsid nohup /usr/sbin/mysqld --user=mysql \
  --innodb-use-native-aio=OFF \
  --log-error='$BOOT_LOG' \
  --pid-file=/var/run/mysqld/mysqld.pid >'$CONSOLE_LOG' 2>&1 < /dev/null &"

for i in $(seq 1 90); do
  if sudo mysqladmin ping >/dev/null 2>&1; then
    echo "   -> MySQL is up."
    exit 0
  fi
  sleep 1
done

echo "############ MySQL failed to start — diagnostics ############" >&2
echo "--- boot log ($BOOT_LOG) ---" >&2
sudo cat "$BOOT_LOG" 2>&1 | head -60 >&2 || true
echo "--- console log ($CONSOLE_LOG) ---" >&2
sudo cat "$CONSOLE_LOG" 2>&1 | head -40 >&2 || true
echo "--- mysqld processes ---" >&2
pgrep -a mysqld >&2 2>&1 || echo "(no mysqld process)" >&2
exit 1
