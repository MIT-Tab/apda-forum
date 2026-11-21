<?php

$sample_topic = $asgarosforum->content->get_topic((int) $wpdb->get_var("SELECT MIN(id) FROM {$asgarosforum->tables->topics}"));
$bot = wp_create_user('results-bot', 'local-password', 'results-bot@example.test');
define('APDA_TOURNAMENT_BOT_USER_ID', $bot);
define('APDA_TOURNAMENT_FORUM_ID', (int) $sample_topic->parent_id);
define('APDA_MITTAB_FORUM_SIGNING_KEY', 'playground-signing-key');
define('APDA_BLACK_ROD_FORUM_TOKEN', 'playground-results-token');
$asgarosforum->options['allow_subscriptions'] = false;
wp_set_current_user(0);

function tournament_request($id, $operation, $data, $claims = null, $results_token = '') {
    $request = new WP_REST_Request('POST', '/apda-forum/v1/tournaments/'.$id.'/'.$operation);
    if ($claims) {
        $encoded = rtrim(strtr(base64_encode(wp_json_encode($claims)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, APDA_MITTAB_FORUM_SIGNING_KEY);
        $request->set_header('X-APDA-Tournament-Token', $encoded.'.'.$signature);
    }
    $request->set_header('X-APDA-Results-Token', $results_token);
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(wp_json_encode($data));
    return rest_do_request($request);
}

$first_id = str_repeat('a', 32);
$existing_id = str_repeat('b', 32);
$closed_id = str_repeat('c', 32);
$results_url = 'https://results.apda.online/core/tournaments/42#tab-cards';
$claims = array('id' => $first_id, 'mode' => 'new', 'title' => 'Playground Invitational', 'tab_url' => 'https://playground.nu-tab.com');
forum_assert(tournament_request($first_id, 'announcement', array())->get_status() === 401, 'An anonymous request must be rejected');
forum_assert(tournament_request($existing_id, 'announcement', array(), $claims)->get_status() === 401, 'A token cannot publish for a different tournament');
forum_assert(tournament_request($first_id, 'tab-cards', array('url' => $results_url), $claims)->get_status() === 401, 'A tournament token cannot set a tab cards link');
forum_assert(tournament_request($first_id, 'tab-cards', array('url' => 'https://example.test/core/tournaments/42#tab-cards'), null, APDA_BLACK_ROD_FORUM_TOKEN)->get_status() === 400, 'Tab cards must link to APDA Results');

$announced = tournament_request($first_id, 'announcement', array(), $claims);
forum_assert($announced->get_status() === 201, 'A new booking creates an announcement: '.wp_json_encode($announced->get_data()));
$topic_id = $announced->get_data()['topic_id'];
$announcement = $asgarosforum->content->get_first_post($topic_id);
forum_assert((int) $announcement->author_id === $bot && strpos($announcement->text, 'https://playground.nu-tab.com') !== false, 'Announcement uses the bot identity and links to MIT-Tab');
$replayed = tournament_request($first_id, 'announcement', array(), $claims);
forum_assert($replayed->get_status() === 200 && $replayed->get_data()['topic_id'] === $topic_id, 'Repeating the announcement returns the same topic');

$results = tournament_request($first_id, 'results', array('body' => "Results\n<script>alert(1)</script>"), $claims);
forum_assert($results->get_status() === 201 && $results->get_data()['topic_id'] === $topic_id, 'Results reply to the created topic');
$post_id = $results->get_data()['post_id'];
$post = $asgarosforum->content->get_post($post_id);
forum_assert((int) $post->author_id === $bot && strpos($post->text, '<script>') === false, 'Results use the bot identity and escape plaintext');
$retried = tournament_request($first_id, 'results', array('body' => 'Retried results'), $claims);
forum_assert($retried->get_status() === 200 && $retried->get_data()['post_id'] === $post_id, 'Retrying results returns the same reply');

foreach (array(1, 2) as $attempt) {
    $response = tournament_request($first_id, 'tab-cards', array('url' => $results_url), null, APDA_BLACK_ROD_FORUM_TOKEN);
    forum_assert($response->get_status() === 200, 'Black Rod can deliver the link and retry');
}
forum_assert(substr_count($asgarosforum->content->get_post($post_id)->text, $results_url) === 1, 'The results reply is edited once');
forum_assert($asgarosforum->content->get_post($announcement->id)->text === $announcement->text, 'The announcement remains as written');

$claims = array('id' => $existing_id, 'mode' => 'existing', 'topic_url' => $asgarosforum->rewrite->get_link('topic', (int) $sample_topic->id, false, '', false));
$original = $asgarosforum->content->get_first_post((int) $sample_topic->id);
forum_assert(tournament_request($existing_id, 'announcement', array(), $claims)->get_status() === 401, 'An existing-topic credential cannot announce a new topic');
tournament_request($existing_id, 'tab-cards', array('url' => $results_url), null, APDA_BLACK_ROD_FORUM_TOKEN);
$results = tournament_request($existing_id, 'results', array('body' => 'Existing tournament results'), $claims);
forum_assert($results->get_status() === 201 && $results->get_data()['topic_id'] === (int) $sample_topic->id, 'Results can reply to a stored topic URL: '.wp_json_encode($results->get_data()));
forum_assert(strpos($asgarosforum->content->get_post($results->get_data()['post_id'])->text, $results_url) !== false, 'A previously imported link is included on first publication');
forum_assert($asgarosforum->content->get_post($original->id)->text === $original->text, 'An existing human post is preserved');

$claims['id'] = $closed_id;
$wpdb->update($asgarosforum->tables->topics, array('closed' => 1), array('id' => $sample_topic->id));
forum_assert(tournament_request($closed_id, 'results', array('body' => 'Closed tournament results'), $claims)->get_status() === 403, 'A closed topic cannot be used for results');

echo "Tournament bot authentication, posting, retries and tab card linking verified\n";
