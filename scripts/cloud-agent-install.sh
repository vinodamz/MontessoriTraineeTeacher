#!/usr/bin/env bash
# Idempotent Cloud Agent bootstrap: local MariaDB, schema, migrations, dev config.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DB_NAME="lg_dev"
DB_USER="lg_dev"
DB_PASS="lg_dev"
ADMIN_PIN="1234"

"$ROOT/scripts/cloud-agent-start.sh"

sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

has_users_table() {
    mysql -u "$DB_USER" -p"$DB_PASS" -N -e \
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema='${DB_NAME}' AND table_name='users'" 2>/dev/null | grep -q '^1$'
}

if ! has_users_table; then
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$ROOT/sql/schema.sql"
fi

php "$ROOT/migrate.php" >/dev/null

seed_count="$(mysql -u "$DB_USER" -p"$DB_PASS" -N -e \
    "SELECT COUNT(*) FROM \`${DB_NAME}\`.rating_config" 2>/dev/null || echo 0)"
if [[ "${seed_count}" == "0" ]]; then
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$ROOT/sql/seeds.sql"
fi

if [[ ! -f "$ROOT/includes/config.php" ]]; then
    cat >"$ROOT/includes/config.php" <<'PHP'
<?php
return [
    'db' => [
        'host'     => 'localhost',
        'name'     => 'lg_dev',
        'user'     => 'lg_dev',
        'password' => 'lg_dev',
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'name'           => 'Little Graduates',
        'short_name'     => 'LG',
        'session_name'   => 'LG_SESSION',
        'max_pin_tries'  => 5,
        'lock_seconds'   => 30,
        'timezone'       => 'Asia/Kolkata',
    ],
];
PHP
fi

php -r "
require '$ROOT/includes/db.php';
\$count = (int) db()->query(\"SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1\")->fetchColumn();
if (\$count > 0) {
    exit(0);
}
\$mods = 'tasks,montessori,students,crm,recruitment,staff,expenses,fees,logbook,inventory,materials,wacrm,n8n,daycare';
\$hash = password_hash('${ADMIN_PIN}', PASSWORD_DEFAULT);
\$stmt = db()->prepare(
    \"INSERT INTO users (name, pin_hash, role, modules, active) VALUES ('Dev Admin', :h, 'admin', :m, 1)\"
);
\$stmt->execute([':h' => \$hash, ':m' => \$mods]);
"

echo "Little Graduates dev environment ready (DB=${DB_NAME}, admin PIN=${ADMIN_PIN})."
