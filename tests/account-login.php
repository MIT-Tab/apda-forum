<?php
$login = new AsgarosForumAccountLogin();
$user = get_user_by('id', 1);
$parameters = array('response_type' => 'code', 'client_id' => 'test-client', 'state' => 'test-state', 'redirect_uri' => 'https://results.apda.online/accounts/apdaonline/login/callback/');
$query = http_build_query($parameters);
$authorization = home_url('/oauth/authorize').'?'.$query;
$fallback = home_url('/');
$application_url = function() { return home_url('/wordpress'); };
add_filter('pre_option_siteurl', $application_url);
$provider_authorization = site_url('oauth/authorize').'?'.$query;
forum_assert($provider_authorization !== $authorization, 'Test the authorization URL generated from a separate WordPress application path');

// A hidden login URL can be rebuilt by plugins before WordPress renders its form.
$hidden_login = function() use ($fallback) { return $fallback.'hidden-login/?reauth=1'; };
add_filter('login_url', $hidden_login, 10);
parse_str(wp_parse_url(wp_login_url($provider_authorization), PHP_URL_QUERY), $login_query);
forum_assert(($login_query['redirect_to'] ?? '') === $authorization, 'The hidden login form must receive the complete canonical OAuth continuation');
forum_assert(($login_query['reauth'] ?? '') === '1', 'The hidden login URL must preserve its other parameters');
forum_assert(wp_login_url(home_url('/forum/')) === $hidden_login(), 'Ordinary login URLs must retain their existing behavior');
remove_filter('login_url', $hidden_login, 10);

forum_assert($login->preserve_results_authorization($fallback, $authorization, $user) === $authorization, 'OAuth must resume after WordPress login');
// Reproduce LoginWP's priority and ensure our narrow continuation wins.
$homepage = function() use ($fallback) { return $fallback; };
add_filter('login_redirect', $homepage, 999999999);
forum_assert(apply_filters('login_redirect', $authorization, $authorization, $user) === $authorization, 'LoginWP must not discard OAuth state');
forum_assert(apply_filters('login_redirect', $fallback, $provider_authorization, $user) === $authorization, 'LoginWP must not discard a continuation generated from site_url()');
remove_filter('login_redirect', $homepage, 999999999);
forum_assert(apply_filters('whl_logged_in_redirect', $fallback, $authorization, $user) === $authorization, 'WPS Hide Login must resume OAuth when a WordPress session already exists');
forum_assert(apply_filters('whl_logged_in_redirect', $fallback, home_url('/forum/'), $user) === $fallback, 'WPS Hide Login must retain ordinary redirects');
remove_filter('pre_option_siteurl', $application_url);

foreach (array('https://evil.example/oauth/authorize', 'https://user@evil.example/oauth/authorize', home_url('/forum/'), '//evil.example/oauth/authorize', 'javascript:alert(1)', '') as $unsafe) {
    if ($unsafe !== '') {
        $unsafe = add_query_arg($parameters, $unsafe);
    }
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
