<?php
// Explicit local Playground fixture; never included in the deployment ZIP.
define('APDA_RESULTS_PROFILE_TOKEN', 'playground-demo-only');
add_filter('pre_http_request', function($preempt, $args, $url) {
    if ($url !== 'https://results.apda.online/api/private/forum-profiles/') {
        return $preempt;
    }
    $id = json_decode($args['body'], true)['wordpress_user_id'];
    return array('response' => array('code' => 200), 'body' => wp_json_encode(array('debaters' => $id === 1 ? array(
        array('id' => 101, 'name' => 'Alex Example', 'school' => 'Example University'),
        array('id' => 102, 'name' => 'Alex Example', 'school' => 'Second University'),
    ) : array())));
}, 10, 3);
