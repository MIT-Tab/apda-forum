---
name: forum-task
description: Implement APDA Forum features, fixes, and maintenance with isolated worktrees, WordPress Playground, regression tests, screenshots, PRs, and quiet CI notifications.
---

# APDA Forum task workflow

Adapted from MIT-Tab/mit-tab's mittab-task and MIT-Tab/black-rod's black-rod-task.
Use this repository copy. Codex discovers `.agents/skills`; Claude uses the relative
symlink in `.claude/skills`. Follow user scope; push and open PRs when authorized.

Resolve `S="$(git rev-parse --show-toplevel)/.agents/skills/forum-task/scripts"`.
Run `"$S/start-task" <kebab-case-slug>`: it fetches origin/master, creates a sibling
worktree on codex/<slug>, and runs npm ci. Keep the starting checkout untouched.
Continue in an existing dedicated task worktree instead of nesting worktrees.
Requires Bash, Git, Node 22+, Python 3, and authenticated gh for PR/CI operations.
No system PHP, Docker, external database, or production credentials are needed.

## Checks and development

Run `npm run lint`, `npm test`, and `npm run test:workflow` before handoff.
Tests boot private WordPress/PHP/SQLite in Playground and run tests/integration.php.
Add focused regressions for behavioral changes. For MySQL-specific schema changes,
also verify against MySQL/MariaDB; Playground's SQLite adapter is not sufficient.

For visible changes run `npm run dev`. It creates a disposable local WordPress
site on 127.0.0.1:9400, mounts this checkout as asgaros-forum, activates the plugin,
and seeds a sample discussion. Login admin / password (local only).
Override the port with FORUM_PORT. Ctrl-C stops it. Each boot has private state;
no .env, production DB, or real APDA posts are loaded. First run needs network.
Capture desktop and mobile with `"$S/shot" URL docs/img/<slug>/desktop.png`
and `"$S/shot" --mobile URL docs/img/<slug>/mobile.png`; view each image once.
Skip screenshots for changes with no visible UI. Do not introduce public test endpoints.

## PR

Commit implementation changes. For images run `"$S/pr-images" docs/img/<slug>/*.png`;
it commits only images, pushes, and prints commit-pinned Markdown links.
Otherwise `git push -u origin HEAD`. Write the exact PR body to a file and run
`gh pr create -R MIT-Tab/apda-forum --base master --head "$(git branch --show-current)" --title "..." --body-file <file>`.
Describe behavior, validation, and material limitations. Include screenshots for UI.

## CI: one watcher, no agent polling

Run `python3 "$S/ci-watch" --detach` once after the final push and end the turn.
It quietly waits for two stable completed snapshots of the PR head and includes
failed GitHub Actions log tails. A head change resets stability; timeout is three hours.
With CODEX_THREAD_ID and codex queue it queues completion to this exact thread.
Never guess another thread. Without notifications, run once in foreground or report
that detached mode only writes a result file. Do not manually poll CI or start a
second watcher for the same PR. After failure reproduce, fix, push, and start one
new watcher. Do not claim green CI before the watcher reports it.

Keep verbose output in logs and report failure tails. Do not repeat passing checks
without a relevant change. Never uninstall the plugin to test a fork switch:
uninstall.php deletes forum tables, metadata, and uploaded attachments.
