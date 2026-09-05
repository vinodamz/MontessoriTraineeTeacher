# Little Graduates — working notes for Claude

Conventions for anyone (human or agent) working in this repository.

## gstack

This project uses [gstack](https://github.com/garrytan/gstack) for its skills.

**Install it once, on your own machine:**

```bash
git clone --single-branch --depth 1 https://github.com/garrytan/gstack.git ~/.claude/skills/gstack \
  && cd ~/.claude/skills/gstack && ./setup
```

It installs to your home directory, not to this repo, so every person sets it
up themselves. Read `setup` before running it, as you would with any script
you are about to execute.

### Web browsing

**Use the `/browse` skill from gstack for all web browsing.**

**Never use the `mcp__claude-in-chrome__*` tools.** `/browse` is the single
supported path; going around it produces results nobody else can reproduce.

### Available skills

| | | |
|---|---|---|
| `/office-hours` | `/plan-ceo-review` | `/plan-eng-review` |
| `/plan-design-review` | `/design-consultation` | `/design-shotgun` |
| `/design-html` | `/review` | `/ship` |
| `/land-and-deploy` | `/canary` | `/benchmark` |
| `/browse` | `/connect-chrome` | `/qa` |
| `/qa-only` | `/design-review` | `/setup-browser-cookies` |
| `/setup-deploy` | `/setup-gbrain` | `/retro` |
| `/investigate` | `/document-release` | `/document-generate` |
| `/codex` | `/cso` | `/autoplan` |
| `/plan-devex-review` | `/devex-review` | `/careful` |
| `/freeze` | `/guard` | `/unfreeze` |
| `/gstack-upgrade` | `/learn` | |

## Cloud Agent dev environment

Two interchangeable environments live in `.cursor/`. Cursor reads the single active
`.cursor/environment.json` at boot, so only one is live at a time. Switch with
`bash .cursor/use-env.sh <mysql|mariadb>` then commit. Details in `.cursor/README.md`.

- `mysql` (committed default): Cursor default image, `apt` installs PHP 8 + MySQL 8,
  scripts `.cursor/{install,start,mysql-up}.sh`.
- `mariadb`: `.cursor/Dockerfile` (`ubuntu:24.04` + MariaDB + PHP 8.3), scripts
  `scripts/cloud-agent-*.sh`.

Both apply `sql/schema.sql` + `sql/seeds.sql`, run the idempotent `php migrate.php`,
generate a per-agent `includes/config.php` (gitignored), seed a dev admin with
PIN `1234`, and serve `php -S …:8000` (open `/login.php`; `/` 302-redirects). To
reach it from your machine, forward port 8000 via the Ports panel.

### Multiple people, same repo
Each Cloud Agent runs on its own VM with its own DB, config, and port, so people
don't collide at runtime. The only shared piece is the committed
`.cursor/environment.json` — don't flip it on shared branches (it changes the env
for everyone and re-introduces merge conflicts on that file). Switch environments
on a personal branch only, or move to dashboard-managed environments if people
genuinely need different envs at the same time.

### MySQL-in-sandbox gotchas (encoded in `.cursor/mysql-up.sh`)
- Start `mysqld` in the foreground under `nohup`; the SysV `su - mysql` init path and
  `mysqld --daemonize` (bug MY-011065) are unreliable inside Cloud Agent containers.
- Set `innodb_use_native_aio=OFF` (sandbox seccomp blocks native AIO) and
  `innodb_flush_method=fsync` (sandbox filesystem rejects O_DIRECT).
- Prebuilt environment **builds** can't validate the MySQL variant: the prebuild FUSE
  store rejects InnoDB `close()` (`[InnoDB] OS error 22`). This only disables the
  prebuild optimization — agent creation falls back to just-in-time `install.sh`,
  which works on the real agent VM.
