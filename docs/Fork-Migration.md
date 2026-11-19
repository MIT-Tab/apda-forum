# Switching APDA to the GitHub fork

## Finding and effort

Switching from official Asgaros Forum to this fork should not require moving or
reimporting posts. Keep the same WordPress database/table prefix, plugin path
`wp-content/plugins/asgaros-forum/asgaros-forum.php`, shortcode page, and uploads.
Replace the plugin files in place. **Do not click Delete or run plugin uninstall.**

Estimated operator effort: 2–4 hours for inventory, a staging rehearsal, backups,
file replacement, and verification; allow a half day if hosting access or backups
need setup. This is an engineering estimate, not a production rehearsal result.
Large reaction tables may extend the schema change/maintenance window because
ALTER TABLE can rebuild or lock the table. Test on a same-size staging copy.
Ongoing work is maintaining GitHub releases and reviewing upstream security fixes.

As checked on 2026-11-19, WordPress.org's plugin API reports official version 3.5.0.
The official 3.5.0 ZIP matches all 76 comparable non-language files in fork master
786999e (line endings normalized). The live APDA site's installed version, schema,
table counts, customizations, hosting, and backup policy have **not** been inspected.
An installed version newer than this fork or schema newer than 65 is a stop condition:
rebase/port the fork onto that release before replacing it. Do not downgrade blindly.

## Why history survives, and how it can be lost

`includes/forum-database.php` derives tables from `$wpdb->prefix`, not repository name:
`forum_forums`, `forum_topics`, `forum_posts`, `forum_reports`, `forum_reactions`,
and the three `forum_polls*` tables. Forum options remain in `asgarosforum_options`,
`asgarosforum_appearance`, and `asgarosforum_db_version`. User identities, forum roles,
subscriptions and unread state remain in WordPress users/usermeta. Categories and
usergroups remain WordPress terms/termmeta. Files remain in
`wp-content/uploads/asgarosforum/` (including each site's uploads path on multisite).
The `[forum]` page remains an ordinary WordPress page.

`uninstall.php` explicitly drops all eight forum tables, deletes options, roles,
subscriptions, categories/usergroups, and recursively deletes uploaded attachments.
Deleting through the WordPress Plugins screen invokes this cleanup. Deactivation
and replacement do not invoke it. Having a backup is essential even for an in-place update.

The emoji feature changes schema 64 → 65: the reaction primary key expands from
`(post_id, user_id)` to `(post_id, user_id, reaction)`. It preserves every existing
reaction, including stable `up`/`down` keys. It does not change forum post records.
The database user needs ALTER privilege. The version stays unchanged on an ALTER
failure so it can be retried; investigate any database errors before reopening.

The fork adds `Update URI: https://github.com/MIT-Tab/apda-forum` so WordPress.org
updates do not overwrite it. This does **not** install a GitHub updater. Operators
must deploy reviewed fork releases and track upstream fixes. Managed hosting or
third-party update systems need their own exclusion rule if they bypass WordPress.

## Rehearsal and production procedure

1. Record WordPress/PHP versions, installed plugin version, plugin directory,
   `asgarosforum_db_version`, database prefix, active site/network activation,
   forum permalink, themes/custom CSS, integrations and any edited plugin files.
   Compare the installed plugin with official 3.5.0; retain local customizations.
2. Make and verify a full database backup and a file backup of the current plugin
   and uploads. Restore them to isolated staging; disable outgoing notifications,
   search indexing and integrations there. For multisite rehearse each subsite.
3. Record counts and representative IDs/text/attachment references from all eight
   forum tables, relevant taxonomy and usermeta rows. Check old topics, images,
   private categories, usergroups, subscriptions, polls, and permalinks before and after.
4. Package a reviewed fork commit using `python3 scripts/package-plugin.py`.
   The ZIP has the canonical `asgaros-forum/` top-level directory and excludes
   development dependencies, tests, screenshots, and repository metadata. Do not
   install GitHub's default `apda-forum-<ref>/` source ZIP as a second plugin.
5. During a short maintenance window disable writes, deactivate Asgaros Forum,
   and replace only the plugin's files under the existing `asgaros-forum` directory
   using hosting file access/SFTP or a tested WordPress replace-upload operation.
   Do not use the Delete button. Do not touch uploads, options or database tables.
   Activate the fork, load WordPress, and inspect PHP/database logs and schema version.
6. Compare counts and samples from step 3. All pre-existing post IDs and contents
   must match. Verify roles/private access, attachment URLs, permalink routing,
   posting/editing, polls and subscriptions. Exercise emoji add/remove across users
   and confirm both historical thumbs votes and new independent emoji counts.
7. Confirm official automatic updates are excluded; retain backups and deployment
   commit. Reopen writes only after verification. Repeat the rehearsed process in
   production; do not treat this local investigation as authorization to deploy.

## Rollback

Keep writes paused until validation finishes. To undo the initial cutover, restore
the database **and** original plugin/files from the same backup. An old upstream
plugin expects one reaction per user/post and schema 64; simply replacing PHP files
is not a complete schema rollback once multiple emoji rows exist. Do not drop the
expanded key until a deliberate policy for extra reactions has been applied.
After accepting new posts, restoring the old backup would discard them: reconcile
new writes first or repair forward. Uploaded attachments must be restored consistently.

## Evidence and limits

- Local WordPress 6.8.3/PHP 8.3/SQLite tests verify independent toggles, summary
  de-duplication, access checks, unchanged post rows, and deactivate/reactivate survival.
- A private MariaDB 10.11 test (`tests/mariadb-migration.py`) executes the exact
  migration SQL extracted from the implementation, compares historical posts/votes,
  and checks multiple emojis and duplicate prevention. It is a focused SQL test,
  not a full WordPress/MariaDB production rehearsal or a large-table timing test.
- No live APDA database, uploads, or credentials were used or changed.

Sources: [WordPress uninstall behavior](https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/),
[activation and deactivation](https://developer.wordpress.org/plugins/plugin-basics/activation-deactivation-hooks/),
[Update URI behavior](https://make.wordpress.org/core/2021/06/29/introducing-update-uri-plugin-header-in-wordpress-5-8/),
[official plugin](https://wordpress.org/plugins/asgaros-forum/),
[official 3.5.0 ZIP](https://downloads.wordpress.org/plugin/asgaros-forum.3.5.0.zip).
