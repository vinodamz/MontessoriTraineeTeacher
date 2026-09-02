#!/usr/bin/env bash
# Per-boot startup: bring MySQL up and wait until it accepts connections.
# The PHP dev server itself runs as a visible terminal (see environment.json).
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
bash "$REPO_ROOT/.cursor/mysql-up.sh"
