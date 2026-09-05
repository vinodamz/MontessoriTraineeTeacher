# Cloud Agent environments

This repo keeps **two** interchangeable Cloud Agent dev-environment setups. Cursor
reads a single active config, `.cursor/environment.json`, when a Cloud Agent boots,
so only one is live at a time. The full configs are kept as variants and switched
in with `.cursor/use-env.sh`.

| Variant | Config file | Base image | Database | Scripts |
|---|---|---|---|---|
| `mysql` (active default) | `environment.mysql.json` | Cursor default image (`apt` installs PHP + MySQL) | MySQL 8 | `.cursor/install.sh`, `.cursor/start.sh`, `.cursor/mysql-up.sh` |
| `mariadb` | `environment.mariadb.json` | `.cursor/Dockerfile` (`ubuntu:24.04` + MariaDB + PHP 8.3) | MariaDB | `scripts/cloud-agent-install.sh`, `scripts/cloud-agent-start.sh`, `scripts/cloud-agent-verify.sh` |

Both bring up the same app: apply `sql/schema.sql` + `sql/seeds.sql`, run the
idempotent `php migrate.php`, write a local `includes/config.php`, seed an admin
(PIN `1234`), and serve `php -S …:8000`.

## Switching

```bash
bash .cursor/use-env.sh mysql     # or: mariadb
git add .cursor/environment.json && git commit -m "Switch Cloud Agent env"
```

The change takes effect for **newly created** Cloud Agents (Cursor reads the
committed `.cursor/environment.json` at boot); it does not reconfigure an
already-running agent.

## Which to use

`mysql` is the default because it is validated running in a Cursor Cloud Agent,
and its MySQL launcher (`.cursor/mysql-up.sh`) is hardened for the Cursor
sandbox: it starts `mysqld` in the foreground under `nohup` (the SysV `su - mysql`
path and `mysqld --daemonize` are unreliable in containers) and sets
`innodb_use_native_aio=OFF` + `innodb_flush_method=fsync` (some sandboxes block
native AIO and reject `O_DIRECT`). Prefer `mariadb` if you specifically want the
Dockerfile-baked image with MariaDB.
