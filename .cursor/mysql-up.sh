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
# InnoDB portability flags for sandboxed Cloud Agent build containers:
#   innodb_use_native_aio=OFF — some sandboxes block Linux native AIO syscalls
#     via seccomp, which makes InnoDB abort before it can even write its log.
#   innodb_flush_method=fsync — the container's overlay/sandbox filesystem
#     rejects O_DIRECT (InnoDB aborts with "OS error 22 / Invalid argument");
#     fsync avoids O_DIRECT and keeps MySQL portable.
set -uo pipefail

MYSQLD_COMPAT_FLAGS=(--innodb-use-native-aio=OFF --innodb-flush-method=fsync)

BOOT_LOG=/tmp/mysqld-boot.log
CONSOLE_LOG=/tmp/mysqld-console.log

if sudo mysqladmin ping >/dev/null 2>&1; then
  echo "   -> MySQL already running."
  exit 0
fi

# /run is a fresh tmpfs on every boot, so the runtime dir may be absent.
sudo install -d -o mysql -g mysql -m 755 /var/run/mysqld

echo "==> Starting MySQL (foreground mysqld; native AIO off, fsync flush)…"
sudo -u mysql bash -c "setsid nohup /usr/sbin/mysqld --user=mysql \
  ${MYSQLD_COMPAT_FLAGS[*]} \
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
