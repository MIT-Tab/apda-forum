<?php
// This fixture token exists only in the disposable Playground runtime.
$integration = new AsgarosForumResultsProfiles();
$target = (object) array('ID' => 54321);
forum_assert($integration->add_profile_row(array(), $target) === array(), 'Unconfigured results lookup must do nothing');
define('APDA_RESULTS_PROFILE_TOKEN', 'playground-test-only');
$calls = 0;
$mock = function($preempt, $args, $url) use (&$calls) {
    ++$calls;
    forum_assert($url === AsgarosForumResultsProfiles::ENDPOINT, 'Fixed results endpoint');
    forum_assert(json_decode($args['body'], true)['wordpress_user_id'] === 54321, 'Look up the profile owner, not viewer');
    forum_assert($args['redirection'] === 0 && $args['timeout'] === 2, 'Bound remote requests');
    forum_assert($args['headers']['Authorization'] === 'Bearer playground-test-only', 'Authenticated lookup');
    return array('response' => array('code' => 200), 'body' => wp_json_encode(array('debaters' => array(
        array('id' => 12, 'name' => '<script>unsafe</script>', 'school' => 'A & B'),
        array('id' => 13, 'name' => 'Alex Example', 'school' => null),
        array('id' => 13, 'name' => 'Alex Example'),
        array('id' => -1, 'name' => 'Invalid'),
        array('id' => 'https://evil.example', 'name' => 'Invalid'),
    ))));
};
add_filter('pre_http_request', $mock, 10, 3);
$rows = $integration->add_profile_row(array('existing' => 'preserved'), $target);
forum_assert($rows['existing'] === 'preserved', 'Preserve other profile rows');
$links = $rows['results_profiles']['value'];
forum_assert(count($links) === 2, 'Multiple results with deduplication and strict IDs');
forum_assert(strpos($links[0], '&lt;script&gt;') !== false && strpos($links[0], '<script>') === false, 'Escape remote labels');
forum_assert(strpos($links[1], 'https://results.apda.online/core/debaters/13') !== false, 'Canonical profile links');
$integration->add_profile_row(array(), $target);
forum_assert($calls === 1, 'Cache repeated lookups');
remove_filter('pre_http_request', $mock, 10);
foreach (array(new WP_Error('offline'), array('response' => array('code' => 401), 'body' => ''), array('response' => array('code' => 200), 'body' => 'invalid'), array('response' => array('code' => 200), 'body' => '{"debaters":[]}')) as $response) {
    ++$target->ID;
    $failure = function() use ($response) { return $response; };
    add_filter('pre_http_request', $failure);
    forum_assert($integration->add_profile_row(array(), $target) === array(), 'Unavailable or unlinked lookup must omit row');
    remove_filter('pre_http_request', $failure);
}
// The existing profile visibility guard must run before the integration filter.
wp_set_current_user(0);
$asgarosforum->options['hide_profiles_from_guests'] = true;
$asgarosforum->current_element = 1;
$hidden_calls = 0;
$hidden_mock = function($preempt) use (&$hidden_calls) { ++$hidden_calls; return new WP_Error('unexpected'); };
add_filter('pre_http_request', $hidden_mock);
ob_start();
$asgarosforum->profile->show_profile();
$hidden_output = ob_get_clean();
remove_filter('pre_http_request', $hidden_mock);
forum_assert($hidden_calls === 0 && strpos($hidden_output, 'login') !== false, 'Hidden guest profiles must not query results');
echo "Results profile lookup verified\n";
