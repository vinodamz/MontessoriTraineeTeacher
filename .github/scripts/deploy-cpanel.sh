#!/usr/bin/env bash
# cPanel entry point; destination overrides are for disposable local rehearsals.
set -euo pipefail
source_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
deploy_root=${MTT_DEPLOY_ROOT:-${HOME:?}/thelittlegraduates.in/mtt}
log_root=${MTT_DEPLOY_LOG_DIR:-${HOME:?}/mtt-deploy-logs}
php_bin=${MTT_PHP_BIN:-php}
[[ -d "$deploy_root" ]] || { echo 'Existing MTT docroot required.' >&2; exit 1; }
deploy_root=$(cd "$deploy_root" && pwd -P)
[[ "$deploy_root" != / && "$deploy_root" != "$source_root" ]] || exit 1
[[ -f "$deploy_root/includes/config.php" ]] || { echo 'Live config missing; stopping before copy.' >&2; exit 1; }
case "$log_root/" in "$deploy_root/"*) echo 'Logs must be outside docroot.' >&2; exit 1 ;; esac
umask 077
mkdir -p "$log_root"
log_root=$(cd "$log_root" && pwd -P)
case "$log_root/" in "$deploy_root/"*) echo 'Logs must be outside docroot.' >&2; exit 1 ;; esac
chmod 700 "$log_root"
lock_dir="$log_root/.deploy-lock"
mkdir "$lock_dir" || { echo 'Deployment lock exists; inspect private logs before retrying.' >&2; exit 1; }
trap 'rmdir "$lock_dir"' EXIT
revision=$(git -C "$source_root" rev-parse --verify HEAD)
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || exit 1
started_at=$(date -u +%s)
run_root="$log_root/${started_at}-${revision}-$$"
mkdir "$run_root"
exec >"$run_root/deploy.log" 2>&1
printf 'Deploying %s at %s\n' "$revision" "$started_at"

# Move known obsolete tooling/logs outside webroot. No broad deletion.
quarantine() {
  local relative=$1
  if [[ -e "$deploy_root/$relative" || -L "$deploy_root/$relative" ]]; then
    mkdir -p "$run_root/quarantine/$(dirname "$relative")"
    mv "$deploy_root/$relative" "$run_root/quarantine/$relative"
  fi
}
for relative in .git .github .cursor .codex .agents tests test docs node_modules \
    CLAUDE.md AGENTS.md README.md CICD.md DEPLOY.md .mcp.json .cpanel.yml \
    .gitignore install.php sql/odoo_dump build-id.txt deploy-status.json \
    staff/smoke_timesheet.php; do
  quarantine "$relative"
done
for module in inventory tasks assessment crm materials; do
  quarantine "$module/smoke_internal.php"
  quarantine "$module/run-smoke.sh"
done
for relative in scripts/cloud-agent-install.sh scripts/cloud-agent-start.sh scripts/cloud-agent-verify.sh; do
  quarantine "$relative"
done
for artifact in "$deploy_root"/last-*.log; do
  if [[ -e "$artifact" || -L "$artifact" ]]; then quarantine "${artifact##*/}"; fi
done

# Only transferred code receives web permissions. No --delete or recursive
# chmod/touch of live data. Config and the entire uploads tree are excluded.
rsync -rltp --checksum --omit-dir-times --chmod=Du=rwx,Dgo=rx,Fu=rw,Fgo=r \
  --exclude-from="$source_root/.github/deploy-excludes.txt" "$source_root/" "$deploy_root/"
while IFS= read -r -d '' file; do
  relative=${file#"$source_root/"}
  case "$relative" in
    includes/config.php|uploads/*|tests/*|test/*|docs/*|node_modules/*|.git/*|.github/*|.cursor/*|.codex/*|.agents/*|install.php|*/smoke_*.php|*_test.php) continue ;;
  esac
  if [[ -f "$deploy_root/$relative" ]]; then touch "$deploy_root/$relative"; fi
done < <(find "$source_root" -type f -name '*.php' -print0)
cd "$deploy_root"
"$php_bin" migrate.php >"$run_root/migrate.log" 2>&1
# Some migrate.php early exits return zero: require every applied marker too.
"$php_bin" -r '
  $log = file_get_contents($argv[1]);
  $files = glob($argv[2] . "/sql/migrate_*.sql") ?: [];
  natsort($files);
  if (!$files || !str_contains($log, "Done. You can now visit /login.php.") ||
      preg_match("/FAILED|DIAGNOSTIC ERROR|\\[skip\\]/", $log)) {
      fwrite(STDERR, "Migration incomplete; inspect private migrate.log.\n"); exit(1);
  }
  foreach ($files as $file) {
      $name = basename($file);
      if (!preg_match("/── " . preg_quote($name, "/") . " ─[^\\n]*\\n\\s*✓ applied\\./u", $log)) {
          fwrite(STDERR, "Missing migration confirmation: $name\n"); exit(1);
      }
  }
  echo json_encode(["status" => "ok", "commit" => $argv[3],
      "latest_migration" => basename(end($files)), "started_at" => (int)$argv[4],
      "completed_at" => time()], JSON_UNESCAPED_SLASHES) . "\n";
' "$run_root/migrate.log" "$source_root" "$revision" "$started_at" >"$run_root/deploy-status.json"
printf '%s\n' "$revision" >"$run_root/build-id.txt"
cp "$run_root/build-id.txt" "$deploy_root/.build-id.tmp"
cp "$run_root/deploy-status.json" "$deploy_root/.deploy-status.tmp"
chmod 644 "$deploy_root/.build-id.tmp" "$deploy_root/.deploy-status.tmp"
mv "$deploy_root/.build-id.tmp" "$deploy_root/build-id.txt"
mv "$deploy_root/.deploy-status.tmp" "$deploy_root/deploy-status.json"
printf 'Deployment completed: %s\n' "$revision"
