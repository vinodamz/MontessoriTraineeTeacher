#!/usr/bin/env bash
# Quick health check for Cloud Agent environments.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
BASE_URL="${LG_BASE_URL:-http://127.0.0.1:8000}"

echo "=== Little Graduates environment verify ==="
php -v | head -1
mysql --version
echo "MariaDB ping: $(mysqladmin ping 2>/dev/null && echo ok || echo fail)"
echo "PHP server: $(curl -sS -o /dev/null -w '%{http_code}' "${BASE_URL}/login.php")"

rm -f /tmp/lg-verify-cookies.txt
curl -sS -c /tmp/lg-verify-cookies.txt "${BASE_URL}/login.php" -o /tmp/lg-verify-login.html
csrf="$(grep -oP 'window\.LG_CSRF = "\K[^"]+' /tmp/lg-verify-login.html)"
login_json="$(curl -sS -b /tmp/lg-verify-cookies.txt -c /tmp/lg-verify-cookies.txt \
  -X POST "${BASE_URL}/login.php" -d "user_id=1&pin=1234&_csrf=${csrf}")"
echo "Login: ${login_json}"

home_title="$(curl -sS -b /tmp/lg-verify-cookies.txt "${BASE_URL}/index.php" | grep -oP '<title>\K[^<]+')"
echo "Home title: ${home_title}"

for mod in tasks assessment crm inventory materials; do
  if [[ -x "${ROOT}/${mod}/run-smoke.sh" ]]; then
    echo "--- ${mod} smoke ---"
    "${ROOT}/${mod}/run-smoke.sh" 2>&1 | tail -2
  fi
done

echo "VERIFY OK"
