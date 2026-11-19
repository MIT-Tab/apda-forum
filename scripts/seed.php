<?php
require_once '/wordpress/wp-load.php';
global $wpdb, $asgarosforum;
$options = get_option('asgarosforum_options');
$options['enable_reactions'] = true;
$options['reactions_show_names'] = true;
update_option('asgarosforum_options', $options);
$topic = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}forum_topics LIMIT 1");
if (!$topic) {
    $forum = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}forum_forums LIMIT 1");
    $wpdb->insert($wpdb->prefix.'forum_topics', array('name' => 'Welcome to the APDA discussion', 'parent_id' => $forum, 'author_id' => 1));
    $topic = $wpdb->insert_id;
    $wpdb->insert($wpdb->prefix.'forum_posts', array('text' => 'Discuss the round, celebrate a great speech, or react to this post.', 'parent_id' => $topic, 'author_id' => 1, 'date' => current_time('mysql')));
}
echo 'Forum: /?page_id='.get_option('asgarosforum_options')['location'].'&view=topic&id='.$topic;
