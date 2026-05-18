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
                                       rsync into docroot
                              /home/<user>/thelittlegraduates.in/mtt
```

No FTP. The only credential traversing the public internet is the cPanel API
token (sent over HTTPS in the `Authorization` header). cPanel itself fetches
from GitHub.

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
- Apply `sql/migrate_001_unify_users.sql` via phpMyAdmin → Import, or open
  `/migrate.php` once after deploying and signing in as admin. The migration
  is fully idempotent.

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

### 5. GitHub secrets

GitHub → repo **Settings → Secrets and variables → Actions → New repository secret**.
**Same values as on LGTaskManager** (shared Hostgator account):

| Name           | Value                                              |
|----------------|----------------------------------------------------|
| `CPANEL_HOST`  | `s3744.bom1.stableserver.net` (the server hostname)|
| `CPANEL_USER`  | `ideyyfbn`                                         |
| `CPANEL_TOKEN` | the token from step 4 (or reuse LGTaskManager's)   |

> **Why the server hostname?** The cPanel control panel (port 2083) presents
> an SSL cert for the server hostname — using your domain would fail TLS.

### 6. Configure the app on the server

cPanel → **File Manager** → `/home/ideyyfbn/thelittlegraduates.in/mtt/includes/`:

1. Copy `config.example.php` to `config.php`.
2. Edit `config.php` and fill in the real DB credentials.

`config.php` is excluded from rsync in `.cpanel.yml`, so future deploys won't
clobber it.

### 7. Bootstrap the first admin

1. Visit `https://mtt.thelittlegraduates.in/install.php`.
2. Enter your name and a 4–6 digit PIN — this creates the first admin teacher.
3. **Delete `install.php`** via cPanel File Manager (the page also warns you).
4. Visit `https://mtt.thelittlegraduates.in/login.php` → tap your profile card → enter your PIN.

### 8. Add staff, modules, and students

Once logged in as admin:
- **Admin** (root `/admin.php`) — add users, set their role (`teacher` / `admin`)
  and the modules they can access (`Assessment` and/or `Tasks`).
- **Assessment → Admin → Students** — add students and assign each to a teacher.
- **Tasks → Team / Board columns / Recurring tasks** — set up board columns,
  recurring routines, and the task team list.

Single-module users (e.g. someone with only `Assessment` checked) get
redirected straight into that module after sign-in. Users with both modules see
a picker tile screen at `/index.php`.

## Manual trigger

GitHub → **Actions** → **Deploy to Hostgator (cPanel pull)** → **Run workflow** → main → **Run workflow**.

## How to inspect a deploy

| Where                                                                            | What you see                                                  |
|----------------------------------------------------------------------------------|---------------------------------------------------------------|
| GitHub → **Actions** tab                                                         | `php -l` lint logs, UAPI HTTP responses (JSON with `status`)  |
| cPanel → **Git Version Control → Manage → Pull or Deploy**                       | Most recent pull/deploy timestamps + log                      |
| `https://mtt.thelittlegraduates.in/last-deploy.log`                              | The rsync log from the most recent deploy                     |
| cPanel → **Errors**                                                              | PHP runtime errors after deploy                               |

## Forcing a clean slate

If rsync gets confused:

1. cPanel → **File Manager** → delete everything under the docroot *except*
   `includes/config.php`.
2. cPanel → **Git Version Control → Manage → Pull or Deploy** → **Deploy HEAD**.
   Re-runs `.cpanel.yml`, repopulates the docroot.

## Security notes

- The `CPANEL_TOKEN` has whatever scopes cPanel granted it (or full account
  access if your cPanel version lacks scopes). Rotate every 90 days.
- `.cpanel.yml` excludes `includes/config.php` from rsync. **Never** commit
  DB credentials.
- The repo clone at `/home/ideyyfbn/repos/MontessoriTraineeTeacher` is outside
  the docroot — `.git/` is never web-served. Verify:
  `curl -sI https://mtt.thelittlegraduates.in/.git/HEAD` returns 404.
