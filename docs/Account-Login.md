# WordPress/results login continuation

The account-login integration preserves the authorization-code continuation when
a successful WordPress login came from results.apda.online. WP OAuth Server
can build its return URL using either the WordPress site URL or the public home
URL. The integration normalizes these to the public `/oauth/authorize` endpoint
and carries the complete OAuth query in the hidden login URL. The exception
requires a client ID, state, and the exact Results callback.

The integration preserves that continuation both after LoginWP runs and when
WPS Hide Login redirects a user whose WordPress session already exists. The
OAuth provider validates the client and callback, and Results validates OAuth
state.

## Diagnosing callback failures

If the browser reaches `results.apda.online/accounts/apdaonline/login/callback/`,
the WordPress handoff has taken place. For a server error at that URL, start
with the Results application logs and check whether a Results deployment was
running at the time.

On November 20, 2026, callbacks reached Results while its deployment was
reinstalling certifi and Django. Requests could not load the CA bundle to
exchange the authorization code, and Django could not load its error template.
See the [Results login and deployment runbook](https://github.com/MIT-Tab/black-rod/blob/master/docs/login-and-sso.md)
for the Results-side workflow. The [forum deployment runbook](Automatic-Deployment.md)
covers the WordPress plugin and its CI pipeline.

## Session behavior

Remember Me sessions last 30 days after the next login. Unchecked sessions retain
WordPress's browser-only behavior. No existing tokens are revoked or rotated.

Companion results changes remove its extra Continue screen, preserve the requested
page, provide a persistent/browser-only choice, and stabilize environment-file
resolution. Full automatic cross-site login and shared logout need additional
identity-provider support; this change does not share application cookies.
