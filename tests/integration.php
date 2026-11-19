<?php
require_once '/wordpress/wp-load.php';
global $wpdb, $asgarosforum;
echo 'Actual runtime: WordPress '.get_bloginfo('version').' / PHP '.PHP_VERSION."\n";
if (get_bloginfo('version') !== '6.8.3') throw new Exception('Unexpected WordPress version');
if (!$asgarosforum || !$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}forum_posts")) {
    throw new Exception('Plugin activation or sample forum initialization failed');
}
echo "Plugin activation and sample post verified\n";

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/wordpress/wp-content/plugins/asgaros-forum'));
foreach ($iterator as $file) {
    if ($file->getExtension() === 'php' && strpos($file->getPathname(), '/node_modules/') === false) {
        token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
    }
}
echo "All plugin PHP files parse successfully\n";



function forum_assert($condition, $message) {
    if (!$condition) throw new Exception($message);
}
wp_set_current_user(1);
$asgarosforum->options['enable_reactions'] = true;
$reactions = $asgarosforum->reactions;
$reactions->initialize();
$post = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}forum_posts LIMIT 1");
$topic = $asgarosforum->content->get_topic($post->parent_id);
$forum = $asgarosforum->content->get_forum($topic->parent_id);
$before = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}forum_posts", ARRAY_A);
forum_assert($reactions->reaction_change($post->id, 1, 'up', 1), 'Own-post thumbs up should work');
forum_assert($reactions->reaction_change($post->id, 1, 'heart', 1), 'Second emoji should work');
forum_assert($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}forum_reactions") == 2, 'Multiple emojis must coexist');
$reactions->load_reactions($topic->id);
$html = $reactions->render_reactions($post->id, 1);
forum_assert(substr_count($html, 'class="reaction-pill reaction-active"') === 2, 'Both emojis should be highlighted');
forum_assert(strpos($html, 'aria-pressed="true"') !== false, 'Accessible pressed state');
forum_assert(strpos($reactions->render_reactions_summary($post->id), 'has reacted') !== false, 'Summary must count each person once');
forum_assert($reactions->reaction_change($post->id, 1, 'heart', 1), 'Toggle heart off');
forum_assert($wpdb->get_var("SELECT reaction FROM {$wpdb->prefix}forum_reactions") === 'up', 'Removing heart must preserve thumbs up');
forum_assert(!$reactions->reaction_change($post->id, 1, 'invalid', 1), 'Unknown emojis rejected');
forum_assert(!$reactions->reaction_change(999999, 1, 'up', 1), 'Missing posts rejected');
forum_assert(!$reactions->reaction_change($post->id, 2, 'up', 1), 'Forged user ID rejected');
wp_set_current_user(0);
forum_assert(!$reactions->reaction_change($post->id, 0, 'up', 1), 'Guests rejected');
$guest = $reactions->render_reactions($post->id, 1);
forum_assert(strpos($guest, '<button') === false, 'Guests see counts but no interactive controls');
$user = wp_create_user('reactor', 'password', 'reactor@example.test');
wp_set_current_user($user);
update_user_meta($user, 'asgarosforum_role', 'banned');
forum_assert(!$reactions->reaction_change($post->id, $user, 'up', 1), 'Banned user rejected');
update_user_meta($user, 'asgarosforum_role', 'normal');
update_term_meta($forum->parent_id, 'category_access', 'moderator');
forum_assert(!$reactions->reaction_change($post->id, $user, 'up', 1), 'Moderator category rejected');
update_term_meta($forum->parent_id, 'category_access', 'everyone');
$deny = function() { return false; };
add_filter('asgarosforum_filter_check_access', $deny);
forum_assert(!$reactions->reaction_change($post->id, $user, 'up', 1), 'Custom denied access rejected');
remove_filter('asgarosforum_filter_check_access', $deny);
$wpdb->update($asgarosforum->tables->topics, array('approved' => 0), array('id' => $topic->id));
forum_assert(!$reactions->reaction_change($post->id, $user, 'up', 1), 'Unapproved topic rejected');
$wpdb->update($asgarosforum->tables->topics, array('approved' => 1), array('id' => $topic->id));
update_term_meta($forum->parent_id, 'usergroups', array(999999));
forum_assert(!$reactions->reaction_change($post->id, $user, 'up', 1), 'Usergroup-restricted category rejected');
delete_term_meta($forum->parent_id, 'usergroups');
$wpdb->update($asgarosforum->tables->forums, array('forum_status' => 'private'), array('id' => $forum->id));
forum_assert(!$reactions->reaction_change($post->id, $user, 'up', 1), 'Another user private topic rejected');
$wpdb->update($asgarosforum->tables->topics, array('author_id' => $user), array('id' => $topic->id));
forum_assert($reactions->reaction_change($post->id, $user, 'heart', 1), 'Private topic author can react');
$wpdb->update($asgarosforum->tables->topics, array('author_id' => 1), array('id' => $topic->id));
$wpdb->update($asgarosforum->tables->forums, array('forum_status' => 'normal'), array('id' => $forum->id));
wp_set_current_user(1);
$asgarosforum->options['enable_reactions'] = false;
forum_assert(!$reactions->reaction_change($post->id, 1, 'up', 1), 'Disabled reactions rejected');
$asgarosforum->options['enable_reactions'] = true;
forum_assert($before === $wpdb->get_results("SELECT * FROM {$wpdb->prefix}forum_posts", ARRAY_A), 'Reactions must not modify posts');
// A file replacement/deactivate/reactivate cycle must preserve the forum history.
require_once ABSPATH.'wp-admin/includes/plugin.php';
deactivate_plugins('asgaros-forum/asgaros-forum.php');
activate_plugin('asgaros-forum/asgaros-forum.php');
forum_assert($before === $wpdb->get_results("SELECT * FROM {$wpdb->prefix}forum_posts", ARRAY_A), 'Reactivation must preserve posts');
echo "Reaction toggles, permissions, summaries, and history preservation verified\n";
echo "FORUM_TESTS_PASSED\n";
