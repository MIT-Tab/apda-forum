#!/usr/bin/env python3
"""Root-owned, forced-command-only WordPress plugin deployer.

The CI key can submit only `deploy SHA256-ish-commit SHA256-zip`, `rehearse ...`,
or `status`. Candidate PHP always runs as www-data, never as root. Database dumps
and private subprocess output stay on this server, never in Actions artifacts.
"""
import contextlib
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shlex
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time
import zipfile

CONFIG = Path('/etc/apda-forum-deploy.json')
HELPERS = Path('/opt/apda-forum-deploy')
ALLOWED_DIRS = {'admin', 'includes', 'integrations', 'js', 'languages', 'libs', 'skin', 'widgets'}
ALLOWED_FILES = {'asgaros-forum.php', 'uninstall.php', 'readme.txt', 'LICENSE'}
MAX_ZIP = 32 * 1024 * 1024
MAX_EXPANDED = 128 * 1024 * 1024


def parse_command(command):
    args = shlex.split(command)
    if args == ['status']:
        return args
    if len(args) != 3 or args[0] not in {'deploy', 'rehearse'}:
        raise ValueError('Only deploy/rehearse <commit> <zip-sha256> or status is allowed')
    if not re.fullmatch('[0-9a-f]{40}', args[1]) or not re.fullmatch('[0-9a-f]{64}', args[2]):
        raise ValueError('Invalid commit or artifact digest')
    return args


def extract_plugin(archive, destination):
    """Reject traversal, symlinks, special files, duplicate names, and zip bombs."""
    with zipfile.ZipFile(archive) as bundle:
        seen = set()
        total = 0
        for entry in bundle.infolist():
            name = PurePosixPath(entry.filename)
            if (name.is_absolute() or '..' in name.parts or '\\' in entry.filename
                    or len(name.parts) < 2 or name.parts[0] != 'asgaros-forum'):
                raise ValueError('Archive must contain only canonical asgaros-forum paths')
            relative = PurePosixPath(*name.parts[1:])
            if relative.parts[0] not in ALLOWED_DIRS and str(relative) not in ALLOWED_FILES:
                raise ValueError('Archive contains non-runtime files')
            mode = entry.external_attr >> 16
            if stat.S_ISLNK(mode) or (stat.S_IFMT(mode) not in {0, stat.S_IFREG, stat.S_IFDIR}):
                raise ValueError('Archive contains a link or special file')
            if entry.filename in seen or entry.flag_bits & 1:
                raise ValueError('Duplicate/encrypted archive entry')
            seen.add(entry.filename)
            total += entry.file_size
            if total > MAX_EXPANDED or len(seen) > 10000:
                raise ValueError('Archive is too large')
            target = destination / str(relative)
            if entry.is_dir():
                target.mkdir(parents=True, exist_ok=True)
            else:
                target.parent.mkdir(parents=True, exist_ok=True)
                with bundle.open(entry) as source, target.open('xb') as output:
                    shutil.copyfileobj(source, output)
                target.chmod(0o644)
        for required in ('asgaros-forum.php', 'includes/forum-database.php', 'includes/forum-reactions.php'):
            if not (destination / required).is_file():
                raise ValueError('Plugin runtime is incomplete')
    database = (destination / 'includes/forum-database.php').read_text()
    match = re.search(r'private\s+\$db_version\s*=\s*(\d+)\s*;', database)
    if not match:
        raise ValueError('Plugin schema declaration is missing')
    header = (destination / 'asgaros-forum.php').read_text()
    if 'Update URI: https://github.com/MIT-Tab/apda-forum' not in header:
        raise ValueError('Fork update protection is missing')
    return int(match[1])


def upstream_version(value):
    match = re.match(r'^(\d+)\.(\d+)(?:\.(\d+))?', value)
    if not match:
        raise RuntimeError('Unrecognized installed plugin version; operator review required')
    return tuple(int(part or 0) for part in match.groups())


def compare_history(before, after, schema):
    after_tables = after['tables'].copy()
    if schema == 66:
        publication_table = before['prefix'] + 'forum_tournament_posts'
        if publication_table not in before['tables'] and after_tables.get(publication_table, {}).get('rows') == 0:
            after_tables.pop(publication_table)
        if after.get('transactional_tables') != ['forum_topics', 'forum_posts', 'forum_tournament_posts']:
            raise RuntimeError('Tournament publishing tables must use InnoDB')
    if before['tables'] != after_tables or before['options'] != after['options']:
        raise RuntimeError('Native forum/WordPress history changed; deployment rejected')
    if after['schema'] != schema or after['reaction_key'] != ['post_id', 'user_id', 'reaction']:
        raise RuntimeError('Forum migration did not reach the expected schema')
    if after['multisite'] or before['prefix'] != after['prefix']:
        raise RuntimeError('Unsupported site configuration')


def uploads_hash(folder):
    if not folder.exists():
        return None
    digest = hashlib.sha256()
    for path in sorted(folder.rglob('*')):
        digest.update(os.fsencode(str(path.relative_to(folder))) + b'\0')
        if path.is_symlink():
            digest.update(b'link\0' + os.fsencode(os.readlink(path)))
        elif path.is_file():
            digest.update(b'file\0')
            with path.open('rb') as source:
                for chunk in iter(lambda: source.read(1024 * 1024), b''):
                    digest.update(chunk)
        elif path.is_dir():
            digest.update(b'directory\0')
        else:
            raise RuntimeError('Unexpected special file in forum uploads')
    return digest.hexdigest()


class Deployment:
    def __init__(self, config, commit):
        self.config = config
        self.site = Path(config['site'])
        self.state = Path(config['state'])
        self.commit = commit
        self.plugin = self.site / 'wp-content/plugins/asgaros-forum'
        if self.site.resolve() != self.site or self.plugin.parent.resolve() != self.plugin.parent or self.plugin.is_symlink():
            raise RuntimeError('Deployment paths must not be symlinks')
        self.stage = self.state / 'stage'
        self.stage_wp = self.stage / 'wordpress'
        self.socket = self.stage / 'mysql.sock'
        stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
        self.release = self.state / 'backups' / (stamp + '-' + commit[:12])
        if shutil.disk_usage(self.state).free < 4 * 1024**3:
            raise RuntimeError('Less than 4 GiB free: refusing to risk backup exhaustion')
        self.release.mkdir(parents=True, mode=0o700)
        self.log = (self.release / 'private.log').open('ab', buffering=0)
        self.maintenance = self.state / 'maintenance'

    def run(self, args, *, stdin=None, stdout=None, env=None, timeout=900):
        # On failure, private logs may contain plugin output; never echo them to CI.
        completed = subprocess.run(args, stdin=stdin, stdout=stdout or self.log,
                                   stderr=self.log, env=env, timeout=timeout, check=False)
        if completed.returncode:
            raise RuntimeError('Server command '+Path(args[0]).name+' failed (exit '+str(completed.returncode)+'); inspect the private deployment log')

    def wp(self, site, *args, stdin=None, stdout=None, action=None, skip=True):
        user = 'apda-forum-stage' if site == self.stage_wp else 'www-data'
        command = ['runuser', '-u', user, '--', '/usr/bin/php', '-d', 'sendmail_path=/bin/true', '/usr/local/bin/wp', '--path='+str(site), '--skip-themes']
        if site == self.stage_wp:
            command = ['unshare', '--net'] + command
        if skip:
            command.append('--skip-plugins')
        env = os.environ.copy()
        env['APDA_DEPLOY_ACTION'] = action or 'snapshot'
        if args[:2] == ('db', 'import'):
            args = args + ('--default-character-set=utf8mb4',)
        self.run(command + list(args), stdin=stdin, stdout=stdout, env=env)

    def snapshot(self, site, action='snapshot'):
        with tempfile.TemporaryFile() as result:
            self.wp(site, 'eval-file', str(HELPERS / 'wp-state.php'), stdout=result, action=action)
            result.seek(0)
            lines = result.read().decode(errors='replace').splitlines()
        matches = [line[len('APDA_STATE:'):] for line in lines if line.startswith('APDA_STATE:')]
        if len(matches) != 1:
            raise RuntimeError('No validated WordPress state returned')
        state = json.loads(matches[0])
        if state['multisite'] or state['schema'] not in {64, 65, 66}:
            raise RuntimeError('Installed schema/site requires operator review')
        return state

    def dump(self, destination):
        # Preserve a consistent snapshot across InnoDB and any remaining MyISAM tables.
        with destination.open('wb') as output:
            self.wp(self.site, 'db', 'export', '-', '--lock-tables=true', '--add-drop-table', '--no-tablespaces=true', '--set-gtid-purged=OFF', '--default-character-set=utf8mb4', stdout=output)
        if destination.stat().st_size < 1000:
            raise RuntimeError('Database backup is empty')

    def own_stage(self, path, staging=False):
        user = 'apda-forum-stage' if staging else 'www-data'
        self.run(['chown', '-hR', user+':'+user, str(path)])

    def stage_start(self):
        self.stage.mkdir(exist_ok=True, mode=0o700)
        self.own_stage(self.stage, staging=True)
        mysql = self.stage / 'mysql'
        if not (mysql / 'mysql').is_dir():
            mysql.mkdir(mode=0o700, exist_ok=True)
            self.own_stage(mysql, staging=True)
            self.run(['/usr/sbin/mysqld', '--no-defaults', '--initialize-insecure', '--user=apda-forum-stage', '--datadir='+str(mysql)])
        self.run(['/usr/sbin/mysqld', '--no-defaults', '--user=apda-forum-stage', '--datadir='+str(mysql),
                  '--socket='+str(self.socket), '--pid-file='+str(self.stage / 'mysql.pid'),
                  '--log-error='+str(self.stage / 'mysql.log'), '--skip-networking', '--mysqlx=0', '--innodb-redo-log-capacity=64M',
                  '--skip-log-bin', '--innodb-buffer-pool-size=64M', '--daemonize'])

    def stage_stop(self):
        if self.socket.exists():
            self.run(['/usr/bin/mysqladmin', '--no-defaults', '--socket='+str(self.socket), '-u', 'root', 'shutdown'])

    def rehearse(self, candidate, schema):
        live = self.snapshot(self.site)
        header = (candidate / 'asgaros-forum.php').read_text()
        incoming = re.search(r'Version:\s*([^\s]+)', header)
        if not incoming or upstream_version(live['version']) > upstream_version(incoming[1]):
            raise RuntimeError('Refusing to overwrite a newer upstream plugin version')
        if live['prefix'] != self.config['prefix']:
            raise RuntimeError('Live table prefix does not match the deployment configuration')
        dump = self.release / 'staging-source.sql'
        self.dump(dump)
        self.stage_start()
        try:
            if self.stage_wp.is_symlink():
                self.stage_wp.unlink()
            elif self.stage_wp.exists():
                shutil.rmtree(self.stage_wp)
            self.stage_wp.mkdir(mode=0o750)
            self.own_stage(self.stage_wp, staging=True)
            # Production credentials are never copied to staging.
            self.run(['rsync', '-a', '--delete', '--exclude=wp-config.php', '--exclude=.git',
                      '--exclude=wp-content/cache', '--exclude=wp-content/mu-plugins',
                      '--exclude=.maintenance', str(self.site)+'/', str(self.stage_wp)+'/'])
            self.own_stage(self.stage_wp, staging=True)
            config = self.stage_wp / 'wp-config.php'
            if config.exists():
                config.unlink()
            self.wp(self.stage_wp, 'config', 'create', '--dbname=wordpress', '--dbuser=root', '--dbpass=',
                    '--dbhost=localhost:'+str(self.socket), '--dbcharset=utf8mb4', '--skip-check', '--skip-salts', '--force')
            # WP-CLI config create defaults to wp_; preserve the actual site's prefix.
            prefix = self.config['prefix']
            self.wp(self.stage_wp, 'config', 'set', 'table_prefix', prefix, '--type=variable')
            self.wp(self.stage_wp, 'config', 'set', 'DISABLE_WP_CRON', 'true', '--raw')
            self.wp(self.stage_wp, 'config', 'set', 'WP_ENVIRONMENT_TYPE', 'staging')
            self.run(['/usr/bin/mysql', '--no-defaults', '--socket='+str(self.socket), '-u', 'root',
                      '-e', 'DROP DATABASE IF EXISTS wordpress; CREATE DATABASE wordpress;'])
            with dump.open('rb') as source:
                self.wp(self.stage_wp, 'db', 'import', '-', stdin=source)
            mu = self.stage_wp / 'wp-content/mu-plugins'
            mu.mkdir(exist_ok=True)
            mu.chmod(0o755)
            shutil.copyfile(HELPERS / 'staging-mu.php', mu / 'apda-staging.php')
            (mu / 'apda-staging.php').chmod(0o644)
            before = self.snapshot(self.stage_wp)
            before_uploads = uploads_hash(self.stage_wp / 'wp-content/uploads/asgarosforum')
            staged_plugin = self.stage_wp / 'wp-content/plugins/asgaros-forum'
            shutil.rmtree(staged_plugin)
            shutil.copytree(candidate, staged_plugin)
            self.own_stage(staged_plugin, staging=True)
            after = self.snapshot(self.stage_wp, 'migrate')
            compare_history(before, after, schema)
            if uploads_hash(self.stage_wp / 'wp-content/uploads/asgarosforum') != before_uploads:
                raise RuntimeError('Rehearsal changed historical forum uploads')
            (self.release / 'staging-migration.json').write_text(json.dumps(after, indent=2))
            # Load the actual site's other active plugins together with the fork.
            self.wp(self.stage_wp, 'eval', 'echo "Plugin bootstrap verified\\n";', skip=False)
            compare_history(before, self.snapshot(self.stage_wp), schema)
            compare_history(before, self.snapshot(self.stage_wp, 'smoke'), schema)
            (self.release / 'staging-verified.json').write_text(json.dumps(after, indent=2))
        finally:
            self.stage_stop()

    def service(self, operation, name):
        self.run(['/usr/bin/systemctl', operation, name], timeout=180)

    def health(self):
        # The forum already requires login. Preserve that redirect; don't bypass it.
        with tempfile.TemporaryFile() as result:
            self.run(['/usr/bin/curl', '--fail', '--silent', '--show-error', '--location', '--max-time', '30',
                      '--resolve', self.config['host']+':443:127.0.0.1', self.config['url']], stdout=result, timeout=45)
            result.seek(0)
            body = result.read()
        if b'wp-submit' not in body and b'id="af-wrapper"' not in body:
            raise RuntimeError('Forum/login health check failed')

    def deploy(self, candidate, schema):
        # No destructive cleanup/uninstall is used, including on rollback.
        staged = self.plugin.with_name('.asgaros-forum-'+self.commit[:12])
        previous = self.plugin.with_name('.asgaros-forum-previous-'+self.commit[:12])
        if staged.exists() or previous.exists():
            raise RuntimeError('An unfinished plugin replacement requires operator recovery')
        old = self.release / 'previous-plugin'
        dump = self.release / 'database.sql'
        asset_path = self.state / 'asset-version'
        old_asset = asset_path.read_bytes() if asset_path.exists() else None
        committed = False
        swapped = False
        stopped = False
        cron_active = subprocess.run(['systemctl', 'is-active', '--quiet', 'cron']).returncode == 0
        if self.maintenance.exists() or (self.site / '.maintenance').exists():
            raise RuntimeError('A maintenance marker already exists; operator review required')
        self.maintenance.touch(mode=0o644)
        try:
            if cron_active:
                self.service('stop', 'cron')
            self.service('stop', 'apache2')
            stopped = True
            before = self.snapshot(self.site)
            self.dump(dump)
            (self.release / 'before.json').write_text(json.dumps(before, indent=2))
            uploads = self.site / 'wp-content/uploads/asgarosforum'
            before_uploads = uploads_hash(uploads)
            if uploads.exists():
                self.run(['tar', '-czf', str(self.release / 'forum-uploads.tar.gz'), '-C', str(uploads.parent), 'asgarosforum'])
            shutil.copytree(self.plugin, old, symlinks=True)
            shutil.copytree(candidate, staged)
            self.own_stage(staged)
            # Keep both renames on the same filesystem; traffic is fully stopped.
            self.plugin.rename(previous)
            try:
                staged.rename(self.plugin)
            except BaseException:
                previous.rename(self.plugin)
                raise
            swapped = True
            after = self.snapshot(self.site, 'migrate')
            compare_history(before, after, schema)
            if uploads_hash(uploads) != before_uploads:
                raise RuntimeError('Deployment changed historical forum uploads')
            self.wp(self.site, 'eval', 'echo "Plugin bootstrap verified\\n";', skip=False)
            self.wp(self.site, 'cache', 'flush')
            self.wp(self.site, 'eval', 'delete_site_transient("update_plugins");')
            self.service('start', 'apache2')
            self.health()
            after = self.snapshot(self.site)
            compare_history(before, after, schema)
            if uploads_hash(uploads) != before_uploads:
                raise RuntimeError('Plugin bootstrap/health check changed historical uploads')
            # All checks passed while external requests and cron were still blocked.
            (self.release / 'after.json').write_text(json.dumps(after, indent=2))
            record = {'commit': self.commit, 'schema': schema, 'backup': str(self.release), 'deployed_at': datetime.datetime.now(datetime.timezone.utc).isoformat()}
            pending = self.state / 'current.json.new'
            pending.write_text(json.dumps(record, indent=2))
            asset = self.state / 'asset-version.new'
            asset.write_text(self.commit+'\n')
            asset.chmod(0o644)
            # Do not permit a termination signal between reopening writes and
            # recording the irreversible commit point (which forbids SQL rollback).
            blocked = {signal.SIGTERM, signal.SIGHUP, signal.SIGINT, signal.SIGALRM}
            old_mask = signal.pthread_sigmask(signal.SIG_BLOCK, blocked)
            try:
                asset.replace(asset_path)
                self.maintenance.unlink()
                committed = True
                pending.replace(self.state / 'current.json')
                (self.release / 'committed.json').write_text(json.dumps(record, indent=2))
            finally:
                signal.pthread_sigmask(signal.SIG_SETMASK, old_mask)
            shutil.rmtree(previous)
            if cron_active:
                self.service('start', 'cron')
            self.prune_backups()
            return record
        except BaseException:
            if swapped and not committed:
                self.service('stop', 'apache2')
                shutil.rmtree(self.plugin)
                shutil.copytree(old, self.plugin, symlinks=True)
                self.own_stage(self.plugin)
                with dump.open('rb') as source:
                    self.wp(self.site, 'db', 'import', '-', stdin=source)
                self.wp(self.site, 'cache', 'flush')
                if before_uploads is not None and uploads_hash(uploads) != before_uploads:
                    if uploads.exists():
                        uploads.rename(self.release / 'failed-uploads')
                    self.run(['tar', '-xzf', str(self.release / 'forum-uploads.tar.gz'), '-C', str(uploads.parent)])
                verified = self.snapshot(self.site)
                (self.release / 'rollback-state.json').write_text(json.dumps(verified, indent=2))
                if (verified['tables'] != before['tables'] or verified['schema'] != before['schema']
                        or verified['options'] != before['options'] or uploads_hash(uploads) != before_uploads):
                    raise RuntimeError('Rollback verification failed; maintenance remains enabled')
                if old_asset is None:
                    asset_path.unlink(missing_ok=True)
                else:
                    asset_path.write_bytes(old_asset)
                    asset_path.chmod(0o644)
                shutil.rmtree(previous)
            if staged.exists():
                shutil.rmtree(staged)
            # If recovery fails above, don't reopen writes or hide the failed state.
            if not committed:
                self.maintenance.unlink(missing_ok=True)
            if stopped:
                self.service('start', 'apache2')
            if cron_active:
                self.service('start', 'cron')
            raise

    def prune_backups(self):
        backups = sorted((self.state / 'backups').iterdir())
        successful = [folder for folder in backups if (folder / 'committed.json').exists()]
        # Preserve the initial migration backup indefinitely and the last three releases.
        keep = set(successful[:1] + successful[-3:])
        for folder in backups:
            if folder not in keep and folder != self.release and folder.is_dir():
                # Only prune known complete rehearsals/successes; failed recovery data stays.
                if (folder / 'committed.json').exists() or ((folder / 'staging-verified.json').exists() and not (folder / 'database.sql').exists()):
                    shutil.rmtree(folder)


def main():
    if os.geteuid() != 0:
        raise RuntimeError('This fixed deployment command must run as root')
    args = parse_command(os.environ.get('SSH_ORIGINAL_COMMAND', ''))
    config = json.loads(CONFIG.read_text())
    state = Path(config['state'])
    state.mkdir(exist_ok=True, mode=0o711)
    with (state / 'deploy.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if args == ['status']:
            print((state / 'current.json').read_text() if (state / 'current.json').exists() else '{"deployed":false}')
            return
        operation, commit, digest = args
        if not config.get('enabled') and operation == 'deploy':
            raise RuntimeError('Production deployment is not enabled')
        deployment = Deployment(config, commit)
        with tempfile.TemporaryDirectory(prefix='candidate-', dir=state) as directory:
            archive = Path(directory) / 'plugin.zip'
            signal.alarm(120)
            with archive.open('wb') as output:
                remaining = MAX_ZIP + 1
                while remaining:
                    chunk = sys.stdin.buffer.read(min(1024 * 1024, remaining))
                    if not chunk:
                        break
                    output.write(chunk)
                    remaining -= len(chunk)
            signal.alarm(0)
            if archive.stat().st_size > MAX_ZIP or hashlib.sha256(archive.read_bytes()).hexdigest() != digest:
                raise ValueError('Artifact size/digest validation failed')
            candidate = Path(directory) / 'plugin'
            candidate.mkdir()
            schema = extract_plugin(archive, candidate)
            if schema != config['approved_schema']:
                raise RuntimeError('Schema changes require an explicit server-side approval')
            for php in candidate.rglob('*.php'):
                deployment.run(['/usr/bin/php', '-l', str(php)])
            deployment.rehearse(candidate, schema)
            result = {'commit': commit, 'rehearsal': 'passed', 'backup': str(deployment.release)}
            if operation == 'deploy':
                result = deployment.deploy(candidate, schema)
            print(json.dumps(result))


if __name__ == '__main__':
    os.umask(0o077)
    def interrupted(signum, frame):
        raise RuntimeError('Deployment interrupted')
    for sig in (signal.SIGTERM, signal.SIGHUP, signal.SIGALRM):
        signal.signal(sig, interrupted)
    try:
        main()
    except Exception as error:
        print('Deployment refused/failed: '+str(error), file=sys.stderr)
        sys.exit(1)
