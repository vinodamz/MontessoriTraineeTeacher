# CI/CD — auto-build & deploy to Hostgator (cPanel pull model)

This repo follows the same deploy pattern as
[LGTaskManager](https://github.com/vinodamz/LGTaskManager), adapted for a Vite
static build.

## Flow

```
git push (main)                       Force-push dist/ → deploy branch
─────────────────►  GitHub Actions  ─────────────────────────────────►  GitHub
                          │
                          │ POST cPanel UAPI (HTTPS:2083)
                          ▼
                    cPanel git fetch + reset to deploy
                                │
                                ▼
                          runs .cpanel.yml
                                │
                                ▼
                    rsync into subdomain docroot
            /home/<user>/thelittlegraduates.in/mtt/
```

No FTP. The only credential that traverses the public internet is the cPanel
API token (sent over HTTPS in the `Authorization` header). cPanel itself fetches
the build output from GitHub.

## What lives where

| Branch    | Contents                                                    |
|-----------|-------------------------------------------------------------|
| `main`    | React/TS source. **No `dist/`** (gitignored).               |
| `deploy`  | Pre-built `dist/` contents + `.cpanel.yml`. Force-pushed by CI on every successful build. |

The `deploy` branch is a single-commit orphan — no history is preserved. cPanel
just sees the latest bundle.

## Public values (committed to the repo)

`.env` is **intentionally not gitignored**:

```
VITE_SUPABASE_URL=https://uijowedzyssnfcmkhbuo.supabase.co
VITE_SUPABASE_ANON_KEY=<anon JWT>
```

Both values are baked into the client bundle at build time. They are
**publishable by design** — Supabase Row-Level-Security policies gate access,
not key secrecy. **Never** add a service-role key here.

## One-time setup

### A. GitHub repository

```bash
gh repo create vinodamz/MontessoriTraineeTeacher \
  --public \
  --description "Montessori trainee-teacher assessment app" \
  --source=. --remote=origin --push
```

Then add three repository secrets — they have the **same values** as on
[LGTaskManager](https://github.com/vinodamz/LGTaskManager/settings/secrets/actions),
since the Hostgator account is shared:

| Name           | Value                                              |
|----------------|----------------------------------------------------|
| `CPANEL_HOST`  | e.g. `s3744.bom1.stableserver.net`                 |
| `CPANEL_USER`  | `ideyyfbn`                                         |
| `CPANEL_TOKEN` | the same cPanel API token used by LGTaskManager    |

Add via UI:
GitHub → repo **Settings → Secrets and variables → Actions → New repository secret**.

### B. Subdomain (cPanel → Domains → Create A New Domain)

| Field            | Value                                                  |
|------------------|--------------------------------------------------------|
| Domain           | `mtt.thelittlegraduates.in`                            |
| Document Root    | `/home/ideyyfbn/thelittlegraduates.in/mtt`             |

> Change `mtt` if you want a different subdomain — update **two places**:
> 1. The `Document Root` here in cPanel.
> 2. The `rsync` target path inside the `.cpanel.yml` block in
>    [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml).
> 3. Any DNS wildcard A-record already exists on the parent zone, so the
>    subdomain resolves immediately.

### C. First CI run (to create the `deploy` branch)

The `deploy` branch doesn't exist yet — CI must run first to create it.

```bash
# Push main; the workflow will build and force-push the deploy branch.
git push origin main
```

Watch **Actions → Build & Deploy to Hostgator (cPanel pull)** until the
"Publish dist/ to deploy branch" step is green. The first run will then **fail
at the cPanel pull step** because cPanel doesn't yet know about the repo —
that's expected. Continue to step D.

### D. Clone the deploy branch on cPanel

cPanel → **Git Version Control → Create**:

| Field             | Value                                                              |
|-------------------|--------------------------------------------------------------------|
| Clone a Repository| **on**                                                             |
| Clone URL         | `https://github.com/vinodamz/MontessoriTraineeTeacher.git`         |
| Repository Path   | `/home/ideyyfbn/repos/MontessoriTraineeTeacher`                    |
| Repository Name   | `MontessoriTraineeTeacher`                                         |

After the clone finishes, **check out the `deploy` branch**: cPanel → Git
Version Control → **Manage** → **Pull or Deploy** tab → switch branch dropdown
to `deploy` → click **Update from Remote**, then **Deploy HEAD**.

This first manual deploy populates `/home/ideyyfbn/thelittlegraduates.in/mtt/`
with the built bundle.

### E. Re-run the workflow

GitHub → Actions → latest run → **Re-run all jobs**. The cPanel-pull steps now
succeed because the repo is registered. From here on, every push to `main`
auto-deploys.

### F. Verify

Visit `https://mtt.thelittlegraduates.in/` — the login page should render with
the Little Graduates Supabase backend wired up.

## Manual trigger

GitHub → Actions → **Build & Deploy to Hostgator (cPanel pull)** → **Run
workflow** → main → **Run workflow**.

## How to inspect a deploy

| Where                                                                            | What you see                                                  |
|----------------------------------------------------------------------------------|---------------------------------------------------------------|
| GitHub → **Actions** tab                                                         | npm ci / build logs, UAPI HTTP responses (JSON with `status`) |
| cPanel → **Git Version Control → Manage → Pull or Deploy**                       | Most recent pull/deploy timestamps + log                      |
| `https://mtt.thelittlegraduates.in/last-deploy.log`                              | The rsync log from the most recent deploy                     |
| cPanel → **Errors**                                                              | Apache / PHP errors (PHP isn't used here, but 404s etc.)      |

## Forcing a clean slate

If the rsync gets confused:

1. cPanel → File Manager → delete everything under
   `/home/ideyyfbn/thelittlegraduates.in/mtt/`.
2. cPanel → Git Version Control → Manage → **Pull or Deploy** → **Deploy HEAD**.
   Re-runs `.cpanel.yml`, repopulates the docroot from the deploy branch.

## Security notes

- The `CPANEL_TOKEN` has whatever scopes you grant it on cPanel. If your cPanel
  version doesn't support scoped tokens, it has **full account access** — treat
  it like a root password. Rotate every 90 days.
- `.env` is committed, but contains **only publishable values**. Service-role
  keys, database passwords, or anything you wouldn't paste into a public DM must
  never go there.
- The repo clone at `/home/ideyyfbn/repos/MontessoriTraineeTeacher/` is outside
  the docroot — `.git/` is never web-served. Verify:
  `curl -sI https://mtt.thelittlegraduates.in/.git/` should return 404.
