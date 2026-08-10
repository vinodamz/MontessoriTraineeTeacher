# CI/CD — auto-deploy to Hostgator (cPanel pull model)

Single repo, single docroot, single subdomain. cPanel clones the repo and pulls
`main` on every push — no build step, because PHP runs from source.

## How it works

```
   git push                                       cPanel git pull
GitHub  ──────► GitHub Actions  ──── HTTPS:2083 ───────────────► GitHub
                      │                       │
                      │ POST UAPI              ▼
                      ▼                  cPanel clones / updates
              cPanel UAPI calls           /home/<user>/repos/MontessoriTraineeTeacher
                  • update                       │
                  • deployment/create            ▼
                                       runs .cpanel.yml
                                                │
                                                ▼
                         rsync → purge sensitive paths → chmod
                         → php migrate.php → per-module smokes
                              /home/<user>/thelittlegraduates.in/mtt
```

No FTP. The only credential traversing the public internet is the cPanel API
token (sent over HTTPS in the `Authorization` header). cPanel itself fetches
from GitHub.

After rsync, `.cpanel.yml`:
1. Deletes `docs/`, `CLAUDE.md`, `.mcp.json`, `install.php`, `sql/odoo_dump/`
   from the docroot (rsync `--exclude` alone does not remove previously synced
   files).
2. Runs `php migrate.php` and writes `/last-migrate.log`.
3. Runs inventory / tasks / assessment / crm / materials smoke wrappers and
   publishes `/last-*-smoke.log` for the Deploy workflow to read.

## One-time setup

### 1. Database

cPanel → **MySQL Databases**:
- Create DB (e.g. `ideyyfbn_lg`).
- Create user; grant ALL on the DB.

cPanel → **phpMyAdmin** → pick the DB:

**Fresh install** — apply both files:
- **Import** → upload `sql/schema.sql` (creates all tables; DROPs existing).
- **Import** → upload `sql/seeds.sql` (rating_config + PG/Nur/LKG/UKG indicators).

**Upgrading the existing MTT database** in-place (preserves all data):
- Deploy once; `.cpanel.yml` runs `php migrate.php` automatically. Or open
  `/migrate.php` once as admin. Migrations are idempotent.

### 2. Subdomain

cPanel → **Domains → Create A New Domain**:

| Field           | Value                                                |
|-----------------|------------------------------------------------------|
| Domain          | `mtt.thelittlegraduates.in`                          |
| Document Root   | `/home/ideyyfbn/thelittlegraduates.in/mtt`           |

The wildcard A-record on the parent zone already resolves the subdomain.

### 3. Clone the repo on cPanel

cPanel → **Git Version Control → Create**:

| Field             | Value                                                              |
|-------------------|--------------------------------------------------------------------|
| Clone a Repository| **on**                                                             |
| Clone URL         | `https://github.com/vinodamz/MontessoriTraineeTeacher.git`         |
| Repository Path   | `/home/ideyyfbn/repos/MontessoriTraineeTeacher`                    |
| Repository Name   | `MontessoriTraineeTeacher`                                         |

This path is **outside the docroot** so `.git/` is never web-served.

### 4. cPanel API token

cPanel → top-right user menu → **Manage API Tokens** → **Create** → name
`gha-deploy-mtt`. Copy the token immediately (you cannot view it again).
Rotate about every 90 days.

### 5. GitHub secrets

GitHub → repo **Settings → Secrets and variables → Actions → New repository secret**.

| Name           | Value                                              |
|----------------|----------------------------------------------------|
| `CPANEL_HOST`  | `s3744.bom1.stableserver.net` (the server hostname)|
| `CPANEL_USER`  | `ideyyfbn`                                         |
| `CPANEL_TOKEN` | the token from step 4                              |
| `SMOKE_TEST_USER_ID` | (optional) user id for authed inventory smoke |
| `SMOKE_TEST_PIN`     | (optional) PIN for that user                  |

> **Why the server hostname?** The cPanel control panel (port 2083) presents
> an SSL cert for the server hostname — using your domain would fail TLS.

### 6. Configure the app on the server

cPanel → **File Manager** → `/home/ideyyfbn/thelittlegraduates.in/mtt/includes/`:

1. Copy `config.example.php` to `config.php`.
2. Edit `config.php` and fill in the real DB credentials.
3. Keep `'session_name' => 'LG_SESSION'` (matches the example). Changing the
   live value forces every staff browser to re-login once.

`config.php` is excluded from rsync in `.cpanel.yml`, so future deploys won't
clobber it.

### 7. Bootstrap the first admin

`install.php` is **not** deployed after the first admin exists (excluded +
purged from the docroot). For a brand-new server only:

1. Temporarily copy `install.php` from the repo clone into the docroot.
2. Visit `https://mtt.thelittlegraduates.in/install.php`.
3. Enter your name and a 4–6 digit PIN.
4. Delete `install.php` again (next deploy also removes it).
5. Visit `/login.php` → tap your profile card → enter your PIN.

### 8. Add staff, modules, and students

Once logged in as admin:
- **Admin** (root `/admin.php`) — add users, set their role (`teacher` / `admin`)
  and the modules they can access.
- Module admins add students / board columns / CRM stages as needed.

Single-module users get redirected straight into that module after sign-in.
Users with multiple modules see a picker at `/index.php`.

## Manual trigger

GitHub → **Actions** → **Deploy to Hostgator (cPanel pull)** → **Run workflow** → main → **Run workflow**.

On-demand inventory-only probe: **Actions** → **Verify live inventory module**.

## How to inspect a deploy

| Where                                                                            | What you see                                                  |
|----------------------------------------------------------------------------------|---------------------------------------------------------------|
| GitHub → **Actions** tab                                                         | `php -l`, UAPI responses, login/parent-form/migrate/smoke gates |
| cPanel → **Git Version Control → Manage → Pull or Deploy**                       | Most recent pull/deploy timestamps + log                      |
| `https://mtt.thelittlegraduates.in/last-deploy.log`                              | Rsync log from the most recent deploy                         |
| `https://mtt.thelittlegraduates.in/last-migrate.log`                             | `php migrate.php` output (must end with latest mig ✓ applied) |
| `https://mtt.thelittlegraduates.in/last-smoke.log`                               | Inventory master-spec smoke                                   |
| `https://mtt.thelittlegraduates.in/last-{tasks,assessment,crm,materials}-smoke.log` | Per-module smoke PASS/FAIL                              |
| cPanel → **Errors**                                                              | PHP runtime errors after deploy                               |

Deploy logs are intentionally public so GitHub Actions can fetch them without
extra secrets. Do not put credentials in those logs.

## Forcing a clean slate

If rsync gets confused:

1. cPanel → **File Manager** → delete everything under the docroot *except*
   `includes/config.php`.
2. cPanel → **Git Version Control → Manage → Pull or Deploy** → **Deploy HEAD**.
   Re-runs `.cpanel.yml`, repopulates the docroot.

## Security notes

- Rotate `CPANEL_TOKEN` about every 90 days.
- `.cpanel.yml` excludes `includes/config.php` from rsync. **Never** commit
  DB credentials.
- Docroot must not serve `docs/`, `CLAUDE.md`, `.mcp.json`, `install.php`, or
  `sql/odoo_dump/` — excludes + post-rsync `rm` + `.htaccess` forbid rules.
- The repo clone at `/home/ideyyfbn/repos/MontessoriTraineeTeacher` is outside
  the docroot — `.git/` is never web-served. Verify:
  `curl -sI https://mtt.thelittlegraduates.in/.git/HEAD` returns 404.
