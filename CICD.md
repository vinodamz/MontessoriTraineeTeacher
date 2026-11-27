# MTT backend release and deployment

This repository deploys PHP source to `https://mtt.thelittlegraduates.in`.
The release owner merges the reviewed PR into `main`; that push starts
`.github/workflows/deploy.yml`. This preparation does not push or deploy.

## Release sequence

1. Finish the parent app/backend tests and review the PR diff. Run the explicit
   PHP lint job even when branch protection does not require checks:
   ```sh
   find . -path ./.git -prune -o -name '*.php' -print | while IFS= read -r file; do php -l "$file" >/dev/null || exit 1; done
   python3 .github/scripts/test_deployment.py
   bash -n .github/scripts/deploy-cpanel.sh .github/scripts/smoke-inventory.sh
   git diff --check
   ```
   Run all write-flow tests only against an isolated local database. The
   deployment regressions use disposable directories, stub migrations and
   mocked HTTP; they do not contact production.
2. Before merging, confirm a recoverable database backup and a private backup
   of the current code/config/uploads exist. Record the previous live
   `build-id.txt`. Review migration changes; deploy never imports `schema.sql`
   or `seeds.sql`, and never supplies the legacy table-drop confirmation flag.
3. Confirm the cPanel checkout is clean, on `main`, and located at
   `$HOME/repos/MontessoriTraineeTeacher`. The existing GitHub secrets are
   `CPANEL_HOST`, `CPANEL_USER`, and `CPANEL_TOKEN`. No new secret is required.
   Existing live `includes/config.php` and uploads must remain in place.
4. Merge the PR into `main` and record the resulting **main commit SHA** (not
   the PR head SHA). The workflow runs deployment regressions and PHP syntax
   checks, asks cPanel to update `main`, and requests deployment. Manual
   dispatch is allowed only from `main`.
5. Monitor the workflow. Its GET-only verifier waits up to 600 seconds for a
   success marker matching the exact SHA, latest migration and this deployment
   request time. It then checks the login form, invalid parent token, module
   redirects, unauthenticated API guards, and exclusion of inert test/log
   artifacts. It does not log in or execute synthetic create/update/delete
   flows. It never probes PHP smoke or install endpoints.
6. Repeat the same read-only verification from the merged checkout if needed:
   ```sh
   python3 .github/scripts/verify-deploy.py --sha "$(git rev-parse HEAD)"
   ```
   `Verify live deployment (read only)` is also available as a manual GitHub
   workflow. `smoke-inventory.sh` is a compatibility entry point for these
   read-only checks; `SMOKE_TEST_*` credentials are no longer used.

## What cPanel runs

`.cpanel.yml` invokes the script by its absolute private-checkout path; it does
not depend on the task working directory. The script derives its source root
from its own location. cPanel's documented examples use repository-relative
sources and require `.cpanel.yml` in the repository root:
[Set Up Deployment](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-set-up-deployment/).

`.github/scripts/deploy-cpanel.sh` performs these steps as one fail-fast task:

1. Require the existing docroot and live config, then acquire a deployment lock
   outside the webroot.
2. Quarantine known previously deployed tests, smoke wrappers, developer
   tooling, documentation and public deploy logs into the private run folder.
   This is a fixed list of deployment artifacts, not a broad deletion of
   untracked files. The previous success marker is quarantined too.
3. Copy application code using `.github/deploy-excludes.txt`. Tests, editor/
   agent environments, cloud-agent scripts and smoke entry points are excluded.
   `includes/config.php` and **all of `uploads/`** are excluded. The operational
   `scripts/leave_monthly_credit.php` cron remains deployed.
4. Set ordinary permissions on transferred code only and touch shipped PHP
   files to refresh timestamp-based opcode caches. No recursive chmod/touch of
   live config or uploads; no `rsync --delete` or `--delete-excluded`.
5. Run `php migrate.php`, capturing output privately. Require zero exit,
   a completion line, and the `applied` marker for **every** migration in this
   checkout. The runner prints `applied` after each successful idempotent
   replay. Its `[skip]` branch means an unreadable migration file, not an
   already-existing table; such a skip correctly fails readiness. Diagnostic
   errors and incomplete zero-exit runs also fail.
6. Only after those gates succeed, atomically publish `build-id.txt` and the
   non-sensitive `deploy-status.json` (revision, migration name and timestamps).

## Logs and recovery

Private run files are at
`$HOME/mtt-deploy-logs/<start-epoch>-<commit>-<pid>/`:

- `deploy.log`: copy/deployment output.
- `migrate.log`: database diagnostics and migration results.
- `quarantine/`: removed public tooling/logs and previous markers, preserved.

The old public `/last-*.log` endpoints are intentionally retired. Do not publish
migration diagnostics containing database/schema details. Inspect logs through
cPanel File Manager or an authorized shell.

If a run fails, stop and inspect its private logs before retrying. This is an
in-place deployment: an interrupted copy or failed migration may leave some
new code/schema in place. The missing/failing success marker prevents a false
green result; it is not an automatic rollback. Restore the reviewed previous
code only after checking schema compatibility; restore database state from a
verified backup when needed. Never erase the webroot, uploads or live config.
A process killed before its cleanup trap may leave `.deploy-lock`; remove that
empty lock only after confirming no deployment is still running.

## Remaining operator checks

- Confirm PHP CLI is 8.1+ and the checkout path matches the workflow. cPanel
  needs Bash, Git, rsync and PHP. Existing uploads protection files/permissions
  are preserved; any deliberate upload-rule change needs separate review.
- Compare the completed marker against the merged main SHA. A newer main commit
  pulled during this run must not be mistaken for the requested revision.
- Verify the hosting account clock is synchronized; freshness uses UTC epochs.
- GET-only checks establish revision, migration completion, bootstrap and access
  guard behavior. Authenticated behavior and writes are covered locally, not
  exercised against school records during deployment.
- Android/Play build, signing and release tracks are owned by the parent release
  task and are outside this backend deployment recipe.
