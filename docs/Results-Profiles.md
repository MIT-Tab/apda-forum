# Linked results profiles

Profiles show all approved results debater claims belonging to the profile owner's
linked results.apda.online account. Labels include the school, allowing multiple
profiles for reaffiliations. The lookup uses WordPress user IDs, never names or
email addresses. Existing profile visibility permissions apply.

Requires MIT-Tab/black-rod's private `/api/private/forum-profiles/` endpoint and
matching shared credentials. Define `APDA_RESULTS_PROFILE_TOKEN` in server-side
WordPress configuration. Keep its value outside the web root, source control,
logs, and local development. The backend uses `FORUM_PROFILE_API_TOKEN`.
The production MU configuration may read a root-owned, www-data-readable
credential file outside the web root; the plugin never exposes that constant.

Deploy the backend first. Without configuration, linked claims, or a reachable
backend, the row is omitted. No forum tables, account records, OAuth connections,
or post history are changed. Unlinked claims disappear within five minutes.
Requests have a two-second timeout, no redirects, and bounded response sizes.
Only canonical results.apda.online debater URLs are rendered; labels are escaped.

For a private local demonstration:

```
FORUM_PROFILE_DEMO=1 npm run dev
```

Open `/?page_id=4&view=profile&id=1`. The fake profiles exist only in
Playground and the fixture is excluded from release packages. Browser regressions:
`FORUM_PROFILE_BROWSER_TESTS=1 npm test`.
