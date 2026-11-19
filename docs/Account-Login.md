# WordPress/results login continuation

The account-login integration preserves the authorization-code continuation when
a successful WordPress login came from results.apda.online. It runs after LoginWP
and restricts the exception to the same-site `/oauth/authorize` path with client
ID, state, and the exact results callback. It does not enable general redirect
overrides or modify OAuth client settings, user accounts, or authentication salts.

This fixes the flow where LoginWP sends a newly authenticated user to WordPress's
homepage instead of back to results. The OAuth provider must still validate the
client/callback and results must validate OAuth state normally.

Remember Me sessions last 30 days after the next login. Unchecked sessions retain
WordPress's browser-only behavior. No existing tokens are revoked or rotated.

Companion results changes remove its extra Continue screen, preserve the requested
page, provide a persistent/browser-only choice, and stabilize environment-file
resolution. Full automatic cross-site login and shared logout need additional
identity-provider support; this change does not share application cookies.
