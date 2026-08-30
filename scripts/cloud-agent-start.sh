#!/usr/bin/env bash
# Start MariaDB on every environment boot and wait until it accepts connections.
set -euo pipefail

if ! command -v mysqladmin >/dev/null 2>&1; then
    echo "mysqladmin not found — is MariaDB installed?" >&2
    exit 1
fi

if ! sudo mysqladmin ping --silent 2>/dev/null; then
    sudo service mariadb start
fi

for _ in $(seq 1 60); do
    if sudo mysqladmin ping --silent 2>/dev/null \
        && sudo mysql -e "SELECT 1" >/dev/null 2>&1; then
        exit 0
    fi
    sleep 1
done

echo "MariaDB did not become ready within 60 seconds." >&2
exit 1
