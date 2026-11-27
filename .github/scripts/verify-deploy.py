#!/usr/bin/env python3
"""GET-only deployment verification; no login, fixture writes or redirects."""
import argparse
import json
from pathlib import Path
import re
import time
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def verify(base, revision, latest, not_before=0, wait=0):
    parsed = urllib.parse.urlsplit(base)
    if parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in ('', '/'):
        raise ValueError('BASE must be an origin without credentials or a path')
    if parsed.scheme != 'https' and not (parsed.scheme == 'http' and parsed.hostname in ('127.0.0.1', 'localhost', '::1')):
        raise ValueError('HTTPS required except for isolated loopback tests')
    if not re.fullmatch(r'[0-9a-f]{40}', revision):
        raise ValueError('Expected revision must be a full Git SHA')
    base = base.rstrip('/')
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

    def get(path):
        separator = '&' if '?' in path else '?'
        request = urllib.request.Request(base + path + separator + '_deploy=' + str(time.time_ns()),
            headers={'Accept': '*/*', 'Cache-Control': 'no-cache', 'User-Agent': 'Mozilla/5.0 MTT-Deploy-ReadOnly'})
        try:
            response = opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.read(262144).decode('utf-8', errors='replace'), response.headers

    def require(ok, name):
        if not ok:
            raise RuntimeError(name)
        print('PASS ' + name, flush=True)

    deadline = time.monotonic() + wait
    while True:
        try:
            code, body, _ = get('/deploy-status.json')
            state = json.loads(body)
            ready = (code == 200 and state.get('status') == 'ok' and state.get('commit') == revision
                and state.get('latest_migration') == latest
                and isinstance(state.get('started_at'), int) and state['started_at'] >= not_before
                and isinstance(state.get('completed_at'), int) and state['completed_at'] >= state['started_at'])
        except (ValueError, AttributeError, OSError, urllib.error.URLError):
            ready = False
        if ready:
            break
        if time.monotonic() >= deadline:
            raise RuntimeError('No fresh successful deployment marker for the expected revision and migration')
        time.sleep(min(5, max(0, deadline - time.monotonic())))
    print('PASS fresh revision and migration marker', flush=True)
    code, body, _ = get('/build-id.txt')
    require(code == 200 and body.strip() == revision, 'build-id matches expected commit')
    code, body, _ = get('/login.php')
    require(code == 200 and 'id="pinSubmit"' in body and 'window.LG_CSRF' in body,
        'login renders the actual sign-in form')
    code, body, _ = get('/students/parent_form.php?token=deploy-invalid-token')
    require(code == 404 and 'Link not active' in body, 'invalid parent token is rejected')
    for path in ('/inventory/index.php', '/inventory/edit.php', '/inventory/reports.php',
                 '/students/index.php', '/materials/daily.php', '/transport/index.php'):
        code, _, headers = get(path)
        location = urllib.parse.urlsplit(urllib.parse.urljoin(base, headers.get('Location', '')))
        require(code in (302, 303) and location.netloc == parsed.netloc and location.path == '/login.php',
            path + ' redirects to same-origin login')
    for path in ('/api/v1/me.php', '/api/v1/transport/today.php', '/api/v1/materials/today.php'):
        code, body, _ = get(path)
        data = json.loads(body)
        require(code == 401 and data.get('ok') is False and data.get('code') == 'unauthenticated',
            path + ' rejects unauthenticated requests')
    # Never probe legacy PHP smoke/install endpoints: an old/misconfigured
    # handler might mutate data even on GET. Probe inert artifacts instead.
    for path in ('/tests/transport_http_e2e.py', '/.github/workflows/deploy.yml',
                 '/.cursor/environment.json', '/scripts/cloud-agent-install.sh',
                 '/last-migrate.log', '/last-smoke.log', '/last-deploy.log'):
        code, _, _ = get(path)
        require(code in (403, 404), path + ' is not publicly served')
    print('Read-only verification passed; authenticated write flows were not exercised.', flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base', default='https://mtt.thelittlegraduates.in')
    parser.add_argument('--sha', required=True)
    parser.add_argument('--not-before', type=int, default=0)
    parser.add_argument('--wait', type=int, default=0)
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[2]
    files = sorted((root / 'sql').glob('migrate_*.sql'), key=lambda p: [int(x) if x.isdigit() else x for x in re.split(r'(\d+)', p.name)])
    if not files:
        parser.error('No migration files in the checked-out release')
    try:
        verify(args.base, args.sha, files[-1].name, args.not_before, args.wait)
    except (ValueError, RuntimeError, AttributeError, OSError, urllib.error.URLError) as error:
        parser.exit(1, 'Verification failed: ' + str(error) + '\n')


if __name__ == '__main__':
    main()
