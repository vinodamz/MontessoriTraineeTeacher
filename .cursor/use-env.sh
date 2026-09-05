#!/usr/bin/env bash
# Switch the active Cloud Agent environment by copying one of the variant
# configs over .cursor/environment.json (the single file Cursor reads).
#
# Usage:
#   bash .cursor/use-env.sh mysql     # PHP + MySQL on the default image
#   bash .cursor/use-env.sh mariadb   # PHP + MariaDB via .cursor/Dockerfile
#
# After switching, commit the change — Cursor reads the committed
# .cursor/environment.json when a new Cloud Agent boots.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
name="${1:-}"
src="$DIR/environment.${name}.json"

if [[ -z "$name" || ! -f "$src" ]]; then
  echo "Usage: bash .cursor/use-env.sh <mysql|mariadb>" >&2
  echo "Available variants:" >&2
  ls "$DIR"/environment.*.json 2>/dev/null | sed 's#.*/environment\.\([^.]*\)\.json#  - \1#' >&2
  exit 1
fi

cp "$src" "$DIR/environment.json"
echo "Active environment set to '${name}' (.cursor/environment.json)."
echo "Commit the change so new Cloud Agents pick it up."
