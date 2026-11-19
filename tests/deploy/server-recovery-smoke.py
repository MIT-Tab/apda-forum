#!/usr/bin/env python3
"""Operator-only integration test against the restored staging site.

Run on apdahost after a successful rehearsal. Real backups, directory replacement,
and rollback execute ONLY inside the private staging tree. No live service is
stopped and no production database is modified. Never add this to generic CI.
"""
import contextlib
import fcntl
import json
from pathlib import Path
import shutil
import sys
import tempfile
from unittest.mock import Mock

sys.path.insert(0, '/opt/apda-forum-deploy')
import server

if __name__ == '__main__':
    config = json.loads(server.CONFIG.read_text())
    root = Path(config['state'])
    with (root / 'deploy.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        deploy = server.Deployment(config, '0' * 40)
        live = deploy.site
        isolated = deploy.stage_wp
        assert isolated.resolve().is_relative_to((root / 'stage').resolve())
        deploy.stage_start()
        try:
            # This pointer change is confined to the already restored private copy.
            deploy.site = isolated
            deploy.plugin = isolated / 'wp-content/plugins/asgaros-forum'
            assert deploy.plugin.resolve().is_relative_to(isolated.resolve())
            deploy.service = Mock()  # No Apache/cron operation is allowed in this test.
            deploy.health = Mock()   # HTTP health is separately checked before live reopen.
            deploy.prune_backups = Mock()
            original_ownership = deploy.own_stage
            deploy.own_stage = lambda path: original_ownership(path, staging=True)
            with contextlib.nullcontext(tempfile.mkdtemp(prefix='recovery-smoke-', dir=root)) as folder:
                folder = Path(folder)
                deploy.state = folder
                deploy.maintenance = folder / 'maintenance'
                candidate = folder / 'candidate'
                shutil.copytree(deploy.plugin, candidate)
                baseline = deploy.snapshot(isolated)
                baseline_uploads = server.uploads_hash(isolated / 'wp-content/uploads/asgarosforum')
                deploy.release = folder / 'success'
                deploy.release.mkdir()
                if '--failure-only' not in sys.argv:
                    result = deploy.deploy(candidate, 65)
                    assert result['schema'] == 65
                deploy.release = folder / 'failure'
                deploy.release.mkdir()
                deploy.health.side_effect = RuntimeError('simulated health failure')
                try:
                    deploy.deploy(candidate, 65)
                except RuntimeError as error:
                    assert 'simulated health failure' in str(error)
                else:
                    raise AssertionError('Expected rollback was not exercised')
                after = deploy.snapshot(isolated)
                server.compare_history(baseline, after, 65)
                assert baseline_uploads == server.uploads_hash(isolated / 'wp-content/uploads/asgarosforum')
                assert not deploy.maintenance.exists()
                assert (live / 'wp-content/plugins/asgaros-forum').is_dir()
                shutil.rmtree(folder)
                print('Private MySQL cutover and failed-health rollback verified; live services untouched')
        finally:
            deploy.stage_stop()
