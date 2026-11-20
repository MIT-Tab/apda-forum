# WordPress/results login continuation

The account-login integration preserves the authorization-code continuation when
a successful WordPress login came from results.apda.online. It runs after LoginWP
and restricts the exception to the same-site `/oauth/authorize` path with client
ID, state, and the exact results callback. It does not enable general redirect
overrides or modify OAuth client settings, user accounts, or authentication salts.

This fixes the flow where LoginWP sends a newly authenticated user to WordPress's
homepage instead of back to results. The OAuth provider must still validate the
client/callback and results must validate OAuth state normally.

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
