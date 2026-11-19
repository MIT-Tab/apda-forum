<?php
$login = new AsgarosForumAccountLogin();
$user = get_user_by('id', 1);
$parameters = array('response_type' => 'code', 'client_id' => 'test-client', 'state' => 'test-state', 'redirect_uri' => 'https://results.apda.online/accounts/apdaonline/login/callback/');
$authorization = add_query_arg($parameters, home_url('/oauth/authorize'));
$fallback = home_url('/');
forum_assert($login->preserve_results_authorization($fallback, $authorization, $user) === $authorization, 'OAuth must resume after WordPress login');
// Reproduce LoginWP's priority and ensure our narrow continuation wins.
$homepage = function() use ($fallback) { return $fallback; };
add_filter('login_redirect', $homepage, 999999999);
forum_assert(apply_filters('login_redirect', $authorization, $authorization, $user) === $authorization, 'LoginWP must not discard OAuth state');
remove_filter('login_redirect', $homepage, 999999999);
foreach (array('https://evil.example/oauth/authorize', home_url('/forum/'), '//evil.example/oauth/authorize', 'javascript:alert(1)', '') as $unsafe) {
    forum_assert($login->preserve_results_authorization($fallback, $unsafe, $user) === $fallback, 'Other redirects retain existing policy');
}
foreach (array('state' => '', 'client_id' => '', 'response_type' => 'token', 'redirect_uri' => 'https://evil.example/callback') as $key => $value) {
    $invalid = add_query_arg(array_merge($parameters, array($key => $value)), home_url('/oauth/authorize'));
    forum_assert($login->preserve_results_authorization($fallback, $invalid, $user) === $fallback, 'Only the registered results authorization flow may override redirects');
}
forum_assert($login->preserve_results_authorization($fallback, $authorization, new WP_Error('bad_login')) === $fallback, 'Failed authentication must not redirect');
forum_assert($login->remembered_login_duration(14 * DAY_IN_SECONDS, 1, true) === 30 * DAY_IN_SECONDS, 'Remembered logins last 30 days');
forum_assert($login->remembered_login_duration(2 * DAY_IN_SECONDS, 1, false) === 2 * DAY_IN_SECONDS, 'Respect browser-only logins');
echo "OAuth continuation and remembered login policy verified\n";
