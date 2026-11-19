import importlib.util
import io
import json
from pathlib import Path
import stat
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('deploy_server', ROOT / 'scripts/deploy/server.py')
server = importlib.util.module_from_spec(spec)
spec.loader.exec_module(server)

class CommandTests(unittest.TestCase):
    def test_only_deployment_commands(self):
        sha, digest = 'a' * 40, 'b' * 64
        self.assertEqual(server.parse_command(f'deploy {sha} {digest}'), ['deploy', sha, digest])
        self.assertEqual(server.parse_command('status'), ['status'])
        for command in ('', 'sh', 'scp -t /tmp/x', f'deploy {sha};id {digest}', f'deploy {sha} {digest} extra'):
            with self.subTest(command=command), self.assertRaises(ValueError):
                server.parse_command(command)

class ArchiveTests(unittest.TestCase):
    def archive(self, files):
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w') as bundle:
            for name, value in files:
                bundle.writestr(name, value)
        buffer.seek(0)
        return buffer

    def valid_files(self):
        return [
            ('asgaros-forum/asgaros-forum.php', 'Update URI: https://github.com/MIT-Tab/apda-forum'),
            ('asgaros-forum/includes/forum-database.php', 'private $db_version = 65;'),
            ('asgaros-forum/includes/forum-reactions.php', '<?php'),
        ]

    def test_valid_release(self):
        with tempfile.TemporaryDirectory() as folder:
            self.assertEqual(server.extract_plugin(self.archive(self.valid_files()), Path(folder)), 65)

    def test_unsafe_paths_and_non_runtime_files(self):
        for name in ('../wp-config.php', '/etc/passwd', 'asgaros-forum/../wp-config.php',
                     'asgaros-forum/wp-config.php', 'asgaros-forum/.github/workflows/x.yml', 'asgaros-forum/includes\\escape.php'):
            with self.subTest(name=name), tempfile.TemporaryDirectory() as folder, self.assertRaises(ValueError):
                server.extract_plugin(self.archive([(name, 'bad')]), Path(folder))

    def test_reject_symlink(self):
        entry = zipfile.ZipInfo('asgaros-forum/includes/link.php')
        entry.external_attr = (stat.S_IFLNK | 0o777) << 16
        with tempfile.TemporaryDirectory() as folder, self.assertRaises(ValueError):
            server.extract_plugin(self.archive([(entry, '/etc/passwd')]), Path(folder))

    def test_reject_incomplete_runtime(self):
        with tempfile.TemporaryDirectory() as folder, self.assertRaises(ValueError):
            server.extract_plugin(self.archive(self.valid_files()[:1]), Path(folder))

class PreservationTests(unittest.TestCase):
    def state(self):
        return {'tables': {'wp_forum_posts': {'rows': 54944, 'sha256': 'unchanged'}},
                'options': {'settings': 'unchanged'}, 'schema': 65,
                'reaction_key': ['post_id', 'user_id', 'reaction'], 'prefix': 'wp_', 'multisite': False}

    def test_migration_can_change_schema_but_not_history(self):
        before, after = self.state(), self.state()
        before['schema'] = 64
        before['reaction_key'] = ['post_id', 'user_id']
        server.compare_history(before, after, 65)
        after['tables']['wp_forum_posts']['rows'] -= 1
        with self.assertRaises(RuntimeError): server.compare_history(before, after, 65)

    def test_expected_schema_and_prefix(self):
        for field, value in [('schema', 64), ('prefix', 'other_'), ('multisite', True), ('reaction_key', ['post_id', 'user_id'])]:
            after = self.state()
            after[field] = value
            with self.subTest(field=field), self.assertRaises(RuntimeError):
                server.compare_history(self.state(), after, 65)

class RollbackTests(unittest.TestCase):
    def fixture(self, folder, failure=None):
        from unittest.mock import Mock
        state = Path(folder)
        site = state / 'site'
        plugin = site / 'wp-content/plugins/asgaros-forum'
        plugin.mkdir(parents=True)
        (plugin / 'version.txt').write_text('old')
        candidate = state / 'candidate'
        candidate.mkdir()
        (candidate / 'version.txt').write_text('new')
        before = PreservationTests().state()
        before['schema'] = 64
        before['reaction_key'] = ['post_id', 'user_id']
        after = PreservationTests().state()
        deployment = server.Deployment.__new__(server.Deployment)
        deployment.site, deployment.state, deployment.plugin = site, state, plugin
        deployment.config = {}
        deployment.commit = 'a' * 40
        deployment.release = state / 'backups/release'
        deployment.release.mkdir(parents=True)
        deployment.maintenance = state / 'maintenance'
        deployment.service = Mock()
        deployment.own_stage = Mock()
        deployment.health = Mock()
        deployment.prune_backups = Mock()
        deployment.run = Mock()
        deployment.wp = Mock()
        deployment.dump = lambda path: path.write_text('private database backup')
        def snapshot(path, action='snapshot'):
            if action == 'migrate':
                if failure: raise RuntimeError('simulated migration failure')
                return after
            return after if (deployment.plugin / 'version.txt').read_text() == 'new' else before
        deployment.snapshot = snapshot
        return deployment, candidate

    def test_failed_migration_restores_files_and_database_before_reopening(self):
        from unittest.mock import patch
        from types import SimpleNamespace
        with tempfile.TemporaryDirectory() as folder, patch.object(server.subprocess, 'run', return_value=SimpleNamespace(returncode=0)):
            deploy, candidate = self.fixture(folder, failure='migration')
            with self.assertRaisesRegex(RuntimeError, 'simulated'):
                deploy.deploy(candidate, 65)
            self.assertEqual((deploy.plugin / 'version.txt').read_text(), 'old')
            self.assertFalse(deploy.maintenance.exists())
            self.assertTrue(any(call.args[1:4] == ('db', 'import', '-') for call in deploy.wp.call_args_list))
            self.assertIn((('start', 'apache2'),), [tuple([call.args]) for call in deploy.service.call_args_list])

    def test_recovered_failure_can_retry_the_same_commit(self):
        from unittest.mock import patch
        from types import SimpleNamespace
        with tempfile.TemporaryDirectory() as folder, patch.object(server.subprocess, 'run', return_value=SimpleNamespace(returncode=0)):
            deploy, candidate = self.fixture(folder, failure='migration')
            for attempt in range(2):
                deploy.release = Path(folder) / 'backups' / str(attempt)
                deploy.release.mkdir()
                with self.assertRaisesRegex(RuntimeError, 'simulated migration'):
                    deploy.deploy(candidate, 65)
                self.assertFalse(deploy.plugin.with_name('.asgaros-forum-previous-'+deploy.commit[:12]).exists())
                self.assertFalse(deploy.plugin.with_name('.asgaros-forum-'+deploy.commit[:12]).exists())

    def test_post_commit_metadata_failure_never_restores_an_old_database(self):
        from unittest.mock import patch
        from types import SimpleNamespace
        replace = Path.replace
        def fail_metadata(path, target):
            if path.name == 'current.json.new': raise OSError('simulated metadata failure')
            return replace(path, target)
        with tempfile.TemporaryDirectory() as folder, patch.object(server.subprocess, 'run', return_value=SimpleNamespace(returncode=0)):
            deploy, candidate = self.fixture(folder)
            with patch.object(Path, 'replace', fail_metadata), self.assertRaisesRegex(OSError, 'metadata failure'):
                deploy.deploy(candidate, 65)
            self.assertEqual((deploy.plugin / 'version.txt').read_text(), 'new')
            self.assertFalse(deploy.maintenance.exists())
            self.assertFalse(any(call.args[1:4] == ('db', 'import', '-') for call in deploy.wp.call_args_list))

    def test_failed_database_recovery_keeps_maintenance_enabled(self):
        from unittest.mock import patch
        from types import SimpleNamespace
        with tempfile.TemporaryDirectory() as folder, patch.object(server.subprocess, 'run', return_value=SimpleNamespace(returncode=0)):
            deploy, candidate = self.fixture(folder, failure='migration')
            deploy.wp.side_effect = RuntimeError('simulated recovery failure')
            with self.assertRaisesRegex(RuntimeError, 'recovery failure'):
                deploy.deploy(candidate, 65)
            self.assertTrue(deploy.maintenance.exists())
            self.assertNotIn(('start', 'apache2'), [call.args for call in deploy.service.call_args_list])

    def test_success_keeps_new_files_and_reopens_only_after_verification(self):
        from unittest.mock import patch
        from types import SimpleNamespace
        with tempfile.TemporaryDirectory() as folder, patch.object(server.subprocess, 'run', return_value=SimpleNamespace(returncode=0)):
            deploy, candidate = self.fixture(folder)
            record = deploy.deploy(candidate, 65)
            self.assertEqual(record['schema'], 65)
            self.assertEqual((deploy.plugin / 'version.txt').read_text(), 'new')
            self.assertFalse(deploy.maintenance.exists())
            self.assertTrue((deploy.release / 'database.sql').exists())

class UploadTests(unittest.TestCase):
    def test_upload_changes_are_detected_without_following_symlinks(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder) / 'uploads'
            root.mkdir()
            file = root / 'historic.pdf'
            file.write_bytes(b'original attachment')
            original = server.uploads_hash(root)
            file.write_bytes(b'changed attachment')
            self.assertNotEqual(original, server.uploads_hash(root))
            file.write_bytes(b'original attachment')
            self.assertEqual(original, server.uploads_hash(root))
            (root / 'link').symlink_to('/not/a/real/secret')
            self.assertIsInstance(server.uploads_hash(root), str)

    def test_missing_uploads(self):
        with tempfile.TemporaryDirectory() as folder:
            self.assertIsNone(server.uploads_hash(Path(folder) / 'missing'))

class ProcessIsolationTests(unittest.TestCase):
    def test_rehearsal_php_uses_a_separate_user_and_network_namespace(self):
        from unittest.mock import Mock
        deploy = server.Deployment.__new__(server.Deployment)
        deploy.stage_wp = Path('/private/staging')
        deploy.run = Mock()
        deploy.wp(deploy.stage_wp, 'eval', 'echo 1;')
        command = deploy.run.call_args.args[0]
        self.assertEqual(command[:5], ['unshare', '--net', 'runuser', '-u', 'apda-forum-stage'])
        self.assertIn('sendmail_path=/bin/true', command)
        self.assertIn('--skip-plugins', command)

    def test_production_php_never_runs_as_root(self):
        from unittest.mock import Mock
        deploy = server.Deployment.__new__(server.Deployment)
        deploy.stage_wp = Path('/private/staging')
        deploy.run = Mock()
        deploy.wp(Path('/live/wordpress'), 'eval', 'echo 1;')
        command = deploy.run.call_args.args[0]
        self.assertEqual(command[:3], ['runuser', '-u', 'www-data'])

class VersionTests(unittest.TestCase):
    def test_fork_suffix_does_not_bypass_newer_upstream_guard(self):
        self.assertEqual(server.upstream_version('3.5.0-apda.1'), (3, 5, 0))
        self.assertGreater(server.upstream_version('3.5.1'), server.upstream_version('3.5.0-apda.1'))
        with self.assertRaises(RuntimeError): server.upstream_version('unknown')
