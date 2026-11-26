"""Deployment-file regression tests, run inside the operations container before rollout."""
import concurrent.futures
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class Operations(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for source in [ROOT / 'docker/init', ROOT / '.env.example']:
            shutil.copy(source, self.root)
        self.var = self.root / 'state/var'
        self.config = self.root / 'state/.env'
        self.env = dict(os.environ, HEJI_ENV_FILE=str(self.config), HEJI_VAR=str(self.var))
        self.cert = self.var / 'tls/fullchain.pem'
        self.key = self.var / 'tls/privkey.pem'

    def invoke(self, script, *args, check=True):
        return subprocess.run(['bash', str(script), *args], env=self.env, capture_output=True, check=check)

    def test_parallel_initialization_keeps_credentials_and_private_modes(self):
        with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
            calls = [pool.submit(self.invoke, self.root / 'init') for _ in range(3)]
            for call in calls:
                call.result()
        first = self.config.read_bytes()
        self.invoke(self.root / 'init')
        self.assertEqual(self.config.read_bytes(), first)
        self.assertIn(b'APP_KEY=base64:', first)
        self.assertEqual(stat.S_IMODE(self.config.stat().st_mode), 0o600)

    def test_local_certificate_can_be_imported_and_repeated(self):
        self.invoke(self.root / 'init', '--local-cert')
        pair = [self.cert.read_bytes(), self.key.read_bytes()]
        self.invoke(ROOT / 'docker/tls-import', self.cert, self.key)
        self.invoke(self.root / 'init', '--local-cert')
        self.assertEqual(pair, [self.cert.read_bytes(), self.key.read_bytes()])
        self.assertEqual(stat.S_IMODE(self.key.stat().st_mode), 0o600)
        for site in ('shop', 'stock', 'recipe'):
            result = subprocess.check_output(['openssl', 'x509', '-in', self.cert, '-noout', '-checkhost', site + '.laravel.demo'])
            self.assertIn(b'does match', result)

    def test_invalid_key_or_hostname_keeps_the_previous_pair(self):
        self.invoke(self.root / 'init', '--local-cert')
        pair = [self.cert.read_bytes(), self.key.read_bytes()]
        wrong_cert = self.root / 'wrong.pem'
        wrong_key = self.root / 'wrong.key'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                        '-subj', '/CN=elsewhere.example', '-out', wrong_cert, '-keyout', wrong_key],
                       check=True, capture_output=True)
        for cert in (self.cert, wrong_cert):
            result = self.invoke(ROOT / 'docker/tls-import', cert, wrong_key, check=False)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(pair, [self.cert.read_bytes(), self.key.read_bytes()])

    def test_compose_uses_archived_live_config_and_preserves_arguments(self):
        current = self.var / 'current'
        runtime = self.var / 'releases/r1/runtime/compose.yaml'
        runtime.parent.mkdir(parents=True)
        runtime.write_text('services: {}')
        current.write_text('r1')
        fake_cli = self.root / 'docker'
        fake_cli.write_text('#!/bin/sh\nprintf "%s\\n" "$@"\n')
        fake_cli.chmod(0o755)
        self.env.update(PATH=str(self.root) + os.pathsep + self.env['PATH'], HEJI_SOURCE_DIR=str(ROOT))
        result = self.invoke(ROOT / 'docker/ops', 'compose', 'exec', '-T', 'app', 'echo', 'two words')
        self.assertEqual(result.stdout.decode().splitlines(),
                         ['compose', '--env-file', str(self.config), '-p', 'heji', '-f', str(runtime),
                          'exec', '-T', 'app', 'echo', 'two words'])

    def test_unknown_operation_fails_and_legacy_launcher_defers_to_release(self):
        self.env['HEJI_SOURCE_DIR'] = str(ROOT)
        self.assertNotEqual(self.invoke(ROOT / 'docker/ops', 'typo', check=False).returncode, 0)
        self.assertIn('自动启动', self.invoke(ROOT / 'docker/ops', 'logs-start').stdout.decode())

    def test_partial_existing_tls_is_preserved(self):
        self.invoke(self.root / 'init')
        self.cert.unlink()
        self.key.write_bytes(b'previous key')
        self.assertNotEqual(self.invoke(self.root / 'init', '--local-cert', check=False).returncode, 0)
        self.assertEqual(b'previous key', self.key.read_bytes())
        self.assertFalse(self.cert.exists())


if __name__ == '__main__':
    unittest.main(verbosity=2)
