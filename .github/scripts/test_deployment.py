#!/usr/bin/env python3
"""Disposable filesystem and mocked HTTP checks. No database or live requests."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
import urllib.parse

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
SHA = 'a' * 40
MIGRATION = 'migrate_001_fixture.sql'
GOOD_LOG = '── ' + MIGRATION + ' ─────\n  ✓ applied.\n\nDone. You can now visit /login.php.\n'
spec = importlib.util.spec_from_file_location('verify_deploy', Path(__file__).with_name('verify-deploy.py'))
verifier = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verifier)


class DeploymentFiles(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='mtt-deploy-test-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / 'checkout'
        self.live = self.root / 'live'
        self.logs = self.root / 'private-logs'
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.write(self.bin / 'git', '#!/bin/sh\nprintf "%s\\n" ' + SHA + '\n')
        (self.bin / 'git').chmod(0o755)
        for name in ['.github/scripts/deploy-cpanel.sh', '.github/deploy-excludes.txt']:
            self.write(self.source / name, (ROOT / name).read_text())
        self.write(self.source / 'sql' / MIGRATION, '-- Fixture only; never executed')
        self.migration(GOOD_LOG)
        self.write(self.source / 'index.php', '<?php echo "release";')
        self.write(self.source / 'includes/config.php', 'must not overwrite live config')
        self.write(self.live / 'includes/config.php', 'preserved config')
        (self.live / 'includes/config.php').chmod(0o600)
        self.write(self.source / 'uploads/photo.jpg', 'must not overwrite upload')
        self.write(self.live / 'uploads/photo.jpg', 'preserved upload')
        (self.live / 'uploads/photo.jpg').chmod(0o640)
        self.write(self.live / 'unrelated.txt', 'preserved untracked data')
        for name in ['tests/fixture.php', 'inventory/smoke_internal.php', 'inventory/run-smoke.sh',
                     '.cursor/environment.json', 'scripts/cloud-agent-start.sh', 'last-migrate.log']:
            self.write(self.source / name, 'support tooling')
            self.write(self.live / name, 'old tooling')
        self.write(self.source / 'scripts/leave_monthly_credit.php', '<?php // operational cron')
        self.write(self.live / 'build-id.txt', 'old revision')
        self.write(self.live / 'deploy-status.json', '{"status":"old"}')

    @staticmethod
    def write(path, data):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(data)

    def migration(self, log, exit_code=0):
        self.write(self.source / 'migrate.php', '<?php echo ' + json.dumps(log, ensure_ascii=False) + '; exit(' + str(exit_code) + ');')

    def run_deploy(self):
        env = dict(os.environ, MTT_DEPLOY_ROOT=str(self.live), MTT_DEPLOY_LOG_DIR=str(self.logs),
            MTT_PHP_BIN=shutil.which('php') or '/opt/homebrew/bin/php', PATH=str(self.bin) + os.pathsep + os.environ['PATH'])
        return subprocess.run(['bash', str(self.source / '.github/scripts/deploy-cpanel.sh')],
            env=env, capture_output=True, text=True)

    def test_success_preserves_data_excludes_tools_and_publishes_revision(self):
        result = self.run_deploy()
        logs = '\n'.join(p.read_text() for p in self.logs.rglob('*.log'))
        self.assertEqual(result.returncode, 0, result.stderr + logs)
        self.assertEqual((self.live / 'includes/config.php').read_text(), 'preserved config')
        self.assertEqual((self.live / 'includes/config.php').stat().st_mode & 0o777, 0o600)
        self.assertEqual((self.live / 'uploads/photo.jpg').read_text(), 'preserved upload')
        self.assertEqual((self.live / 'uploads/photo.jpg').stat().st_mode & 0o777, 0o640)
        self.assertEqual((self.live / 'unrelated.txt').read_text(), 'preserved untracked data')
        for name in ['tests', '.github', '.cursor', 'inventory/smoke_internal.php',
                     'inventory/run-smoke.sh', 'scripts/cloud-agent-start.sh', 'last-migrate.log']:
            self.assertFalse((self.live / name).exists(), name)
        self.assertTrue((self.live / 'scripts/leave_monthly_credit.php').exists())
        self.assertEqual((self.live / 'index.php').stat().st_mode & 0o777, 0o644)
        self.assertTrue(list(self.logs.glob('*/quarantine/tests/fixture.php')))
        self.assertEqual((self.live / 'build-id.txt').read_text().strip(), SHA)
        state = json.loads((self.live / 'deploy-status.json').read_text())
        self.assertEqual((state['commit'], state['latest_migration']), (SHA, MIGRATION))
        self.assertFalse((self.logs / '.deploy-lock').exists())

    def test_nonzero_migration_fails_without_success_marker(self):
        self.migration('FAILED\n', 1)
        self.assertNotEqual(self.run_deploy().returncode, 0)
        self.assertFalse((self.live / 'deploy-status.json').exists())
        self.assertFalse((self.live / 'build-id.txt').exists())

    def test_zero_exit_diagnostic_failure_is_rejected(self):
        self.migration('DIAGNOSTIC ERROR: database unavailable\n')
        self.assertNotEqual(self.run_deploy().returncode, 0)
        self.assertFalse((self.live / 'deploy-status.json').exists())

    def test_missing_applied_marker_is_rejected(self):
        self.migration('Done. You can now visit /login.php.\n')
        self.assertNotEqual(self.run_deploy().returncode, 0)

    def test_unreadable_migration_skip_is_rejected(self):
        self.migration(GOOD_LOG.replace('✓ applied.', '[skip] cannot read.'))
        self.assertNotEqual(self.run_deploy().returncode, 0)

    def test_missing_config_stops_before_copy(self):
        (self.live / 'includes/config.php').unlink()
        self.assertNotEqual(self.run_deploy().returncode, 0)
        self.assertFalse((self.live / 'index.php').exists())
        self.assertEqual((self.live / 'build-id.txt').read_text(), 'old revision')

    def test_rsync_failure_is_not_published(self):
        self.write(self.bin / 'rsync', '#!/bin/sh\nexit 23\n')
        (self.bin / 'rsync').chmod(0o755)
        self.assertNotEqual(self.run_deploy().returncode, 0)
        self.assertFalse((self.live / 'deploy-status.json').exists())


class Response:
    def __init__(self, status, body, headers=None):
        self.status, self.body, self.headers = status, body, headers or {}
    def __enter__(self): return self
    def __exit__(self, *args): pass
    def read(self, limit): return self.body.encode()[:limit]


class ReadOnlyVerifier(unittest.TestCase):
    def setUp(self):
        self.paths = []
        self.state = {'status': 'ok', 'commit': SHA, 'latest_migration': MIGRATION,
                      'started_at': 100, 'completed_at': 101}
        self.redirect = '/login.php'
        self.login = '<button id="pinSubmit">Sign in</button><script>window.LG_CSRF="token"</script>'
        self.exposed = False

    def open(self, request, timeout):
        self.assertEqual(request.get_method(), 'GET')
        self.assertIsNone(request.data)
        self.assertIsNone(request.get_header('Authorization'))
        path = urllib.parse.urlsplit(request.full_url).path
        self.paths.append(path)
        if path == '/deploy-status.json': return Response(200, json.dumps(self.state))
        if path == '/build-id.txt': return Response(200, SHA)
        if path == '/login.php': return Response(200, self.login)
        if path == '/students/parent_form.php': return Response(404, 'Link not active')
        if path.startswith('/api/'): return Response(401, '{"ok":false,"code":"unauthenticated"}')
        if path.endswith('.log') or path.startswith(('/tests/', '/.github/', '/.cursor/', '/scripts/')):
            return Response(200 if self.exposed else 404, '')
        return Response(302, '', {'Location': self.redirect})

    def verify(self):
        with patch.object(verifier.urllib.request, 'build_opener', return_value=self), contextlib.redirect_stdout(io.StringIO()):
            verifier.verify('https://release.invalid', SHA, MIGRATION, not_before=99)

    def test_success_uses_only_safe_get_requests(self):
        self.verify()
        self.assertGreater(len(self.paths), 15)
        self.assertFalse(any('smoke_internal' in p or 'install.php' in p for p in self.paths))

    def test_stale_revision_rejected(self):
        self.state['commit'] = 'b' * 40
        with self.assertRaises(RuntimeError): self.verify()

    def test_old_attempt_same_revision_rejected(self):
        self.state['started_at'] = 98
        with self.assertRaises(RuntimeError): self.verify()

    def test_wrong_migration_rejected(self):
        self.state['latest_migration'] = 'old.sql'
        with self.assertRaises(RuntimeError): self.verify()

    def test_waf_html_is_not_a_login_success(self):
        self.login = '<html>Please wait while being verified</html>'
        with self.assertRaises(RuntimeError): self.verify()

    def test_external_login_redirect_rejected(self):
        self.redirect = 'https://external.invalid/login.php'
        with self.assertRaises(RuntimeError): self.verify()

    def test_public_tooling_rejected(self):
        self.exposed = True
        with self.assertRaises(RuntimeError): self.verify()


if __name__ == '__main__':
    unittest.main(verbosity=2)
