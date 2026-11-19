# Automatic forum deployment

Merges to `master` run the existing PHP/WordPress, Playwright, permission, and
MariaDB checks plus deployment safety tests. A separate clean checkout builds one
ZIP and manifest for that exact commit. The package is retained as a GitHub
artifact for 14 days. Production downloads that immutable artifact and verifies
its SHA-256 before sending it over pinned-host-key SSH. PRs never receive the
production credential or deploy. `workflow_dispatch` on master reruns the same
checks and deployment, useful for retrying a failed infrastructure operation.

The GitHub `production` environment allows only master. Deploy jobs are serialized
and do not cancel an already running deployment. The server also uses `flock`.
Environment configuration:

- Secret `APDA_FORUM_DEPLOY_KEY`: dedicated Ed25519 private key, passed directly
  to GitHub without printing it. Never display, retrieve, or log its value.
- Variables `APDA_FORUM_HOST` and `APDA_FORUM_KNOWN_HOSTS`: server address and
  already-trusted public SSH host key. Do not use unverified runtime ssh-keyscan.
- The public deployment key has OpenSSH `restrict` and a forced root-owned
  `/opt/apda-forum-deploy/server.py` command. It cannot open an interactive shell,
  forward ports, run arbitrary commands, or upload arbitrary server paths.

## Server setup and trust boundary

Run the reviewed `scripts/deploy/install-server [CI_PUBLIC_KEY_FILE]` as root. It installs `scripts/deploy/{server.py,wp-state.php,staging-mu.php}` into
root-owned `/opt/apda-forum-deploy/` and the example config into root-only
`/etc/apda-forum-deploy.json`. The `production-guard.php` MU plugin is installed
separately in the existing WordPress MU-plugin directory. These privileged
infrastructure helpers are deliberately outside the plugin ZIP; changing them
requires a separate reviewed operator installation, not a plugin update.

The configuration fixes the site, private state directory, table prefix, public
forum URL, and explicitly approved schema. Initial installation sets `enabled`
to false. After a successful rehearsal, the operator enables deployments. Schema
65 is approved for the initial 64→65 cutover. A future schema version is rejected
before touching production until explicitly reviewed and approved on the server.
The helper currently supports the rehearsed schema-64→65 transition and schema-65 code updates. Future migrations require review of the server helper as well as its schema approval. Multisite is rejected rather than partly migrated.

The CI key accepts only `status`, `rehearse <40-character-commit> <zip-sha256>`,
and `deploy <40-character-commit> <zip-sha256>`. The ZIP arrives on standard input.
The helper bounds its size, rejects traversal/links/special files and non-runtime
paths, requires the canonical plugin directory and update-protection header,
and checks PHP syntax before using it. The installer adds an AppArmor allowance limited to the private staging directory for MySQL. Production WordPress/PHP runs as www-data; rehearsal PHP and MySQL run as the separate apda-forum-stage user, never root. Approved code still has normal WordPress application privileges;
protect master and review changes as production code.

## Every deployment

1. Verify the live plugin is active, the prefix/schema match, required database privileges exist, and its upstream version is not newer than the fork. Export a consistent database snapshot using table locks: live posts and topics
   are MyISAM, so `--single-transaction` alone is insufficient. All exports/imports and the staging config explicitly use `utf8mb4`, preserving four-byte Unicode independently of WP-CLI charset heuristics.
2. Restore that snapshot into a separate local MySQL process using a private
   datadir/socket and no network listener. Copy WordPress files without production
   wp-config.php. Staging gets its own generated socket-only config. Staging PHP
   runs as a separate `apda-forum-stage` OS user without external networking, mail delivery, or WP cron.
3. Record deterministic row counts/hashes for every native forum table, users,
   WordPress posts, taxonomy tables, and forum usermeta, plus forum settings hashes.
   Hash forum upload contents too. Replace only the staging plugin, migrate, and compare historical state and upload contents exactly.
   Exercise independent reaction add/remove in the clone using an existing administrator identity, then verify history again; no account is created and no password is requested or displayed.
   Load the fork alongside the site's other active plugins.
4. Enable the MU maintenance guard; pause cron and stop/drain Apache. Export a
   fresh database backup, archive forum uploads, and retain the previous plugin.
   No native forum table, settings, users, shortcode page, or uploads are replaced
   with staging data. Never invoke plugin uninstall/delete.
5. Stage the candidate beside the live plugin and rename directories on the same
   filesystem while requests are stopped. Run the migration, compare all protected
   historical data, check plugin bootstrap, and flush the object cache.
6. Start Apache while external requests remain blocked. Loopback checks verify
   the existing forum/login behavior. Only after all checks pass remove the guard
   and restart cron. Record the commit, schema, timestamp, and backup location. The MU guard appends the deployed commit to plugin asset versions so browser CSS/JS caches refresh on code-only updates.

The MU guard also blocks WordPress automatic updates for asgaros-forum, and deployment clears stale official-plugin update metadata. The fork retains its Update URI header.

The current forum requires login. Health checks preserve that access restriction;
no public test endpoint, shared WordPress password, or production test account is
created. The root guard allows loopback health checks on this direct-Apache host;
review that assumption before introducing a local reverse proxy.

## Failure and recovery

Before maintenance, a failed rehearsal leaves production unchanged. During the
cutover, failures restore the previous plugin, database, and changed forum uploads while writes are still
blocked. Failed rollback verification keeps maintenance enabled and Apache down
for operator recovery. After writes reopen, the helper never automatically
restores an older database. It retains the initial successful migration backup
indefinitely plus the latest three successful deployments. Failed recovery data
is retained. At least 4 GiB free space is required before starting another run.

Private SQL, upload archives, and plugin/subprocess logs remain under
`/var/lib/apda-forum-deploy/backups/`, outside the web root. They are not uploaded
to GitHub and may contain private data. These on-server recovery points are not
a substitute for the hosting provider's independent/off-server disaster backups.
Review disk usage and external backup retention with the hosting operator.

For manual recovery, use the existing operator SSH agent. First stop writers and
identify the recorded deployment backup. Restore a database only if new writes
have not been accepted or have been reconciled. An old upstream plugin expects
schema 64; do not replace PHP alone to undo the expanded reaction key. To roll back
a code-only change after writes resume, redeploy the retained, compatible schema-65
plugin artifact through the same rehearsal/checks rather than restoring old SQL.

The helper's `status` command reports only release metadata. It does not print
credentials or private database contents. Diagnose failures through the private
server logs without publishing their raw contents into public PR/Actions logs.

## Validation

The native database CI job also runs `tests/deploy/mysql_unicode_roundtrip.py`, verifying four-byte Unicode, quotes, backslashes, and newlines survive a real MyISAM dump/import.

Run `python3 -m unittest discover -s tests/deploy -v`, `npm run lint`,
`npm run test:workflow`, and `npm test`. Deployment unit tests cover command and
archive restrictions, unchanged history requirements, failed-migration recovery,
and keeping maintenance enabled on recovery failure. The first server rehearsal
must additionally validate the real site's core/plugin combination and native
MySQL schema before enabling live deployment.

For an operator-only recovery rehearsal on the restored native MySQL site, run `python3 tests/deploy/server-recovery-smoke.py` on the server after installing the reviewed helpers. It exercises real private database backup/import and file replacement, deliberately fails health verification, and verifies recovery; live Apache/cron are stubbed and no production database is modified. Failed test artifacts stay private for diagnosis.
