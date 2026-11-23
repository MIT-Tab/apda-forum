<?php

$search_tables = $asgarosforum->tables;
$search_category = wp_insert_term('Search tests primary category', 'asgarosforum-category')['term_id'];
$other_category = wp_insert_term('Search tests other category', 'asgarosforum-category')['term_id'];
$members_category = wp_insert_term('Search tests members category', 'asgarosforum-category')['term_id'];
update_term_meta($search_category, 'category_access', 'everyone');
update_term_meta($other_category, 'category_access', 'everyone');
update_term_meta($members_category, 'category_access', 'loggedin');

$create_search_forum = function ($category, $status = 'normal', $parent = 0) use ($wpdb, $search_tables) {
    $wpdb->insert($search_tables->forums, array('name' => 'Search test board', 'parent_id' => $category, 'parent_forum' => $parent, 'forum_status' => $status));
    return (int) $wpdb->insert_id;
};
$create_search_topic = function ($board, $name, $approved = 1) use ($wpdb, $search_tables) {
    $wpdb->insert($search_tables->topics, array('name' => $name, 'parent_id' => $board, 'author_id' => 1, 'approved' => $approved));
    return (int) $wpdb->insert_id;
};
$create_search_post = function ($topic_id, $author, $date, $body) use ($wpdb, $search_tables) {
    $wpdb->insert($search_tables->posts, array('parent_id' => $topic_id, 'author_id' => $author, 'date' => $date, 'text' => $body));
    return (int) $wpdb->insert_id;
};

$search_user = wp_insert_user(array('user_login' => 'search-reply-author', 'user_pass' => 'local-only', 'display_name' => "Avery O'Neil"));
forum_assert(!is_wp_error($search_user), 'Search fixture author should be created');
$parent_forum = $create_search_forum($search_category);
$subforum = $create_search_forum($search_category, 'normal', $parent_forum);
$thread = $create_search_topic($subforum, 'Searchfixture planning');
$opening = $create_search_post($thread, 1, '2026-02-01 08:00:00', 'Searchfixture opening post');
$reply = $create_search_post($thread, $search_user, '2026-02-01 23:59:59', 'Searchfixture reply');
$next_day = $create_search_post($thread, $search_user, '2026-02-02 00:00:00', 'Searchfixture next day');
$unmatched = $create_search_post($thread, $search_user, '2026-02-01 09:00:00', 'Unrelated reply');

$other_forum = $create_search_forum($other_category);
$other_post = $create_search_post($create_search_topic($other_forum, 'Other thread'), $search_user, '2026-02-01 11:00:00', 'Searchfixture other category');
$members_forum = $create_search_forum($members_category);
$members_post = $create_search_post($create_search_topic($members_forum, 'Members thread'), $search_user, '2026-02-01 12:00:00', 'Searchfixture members category');
$private_forum = $create_search_forum($search_category, 'private');
$private_post = $create_search_post($create_search_topic($private_forum, 'Private topic'), $search_user, '2026-02-01 16:00:00', 'Searchfixture private post');
$create_search_post($create_search_topic($subforum, 'Unapproved topic', 0), $search_user, '2026-02-01 15:00:00', 'Searchfixture unapproved post');

$original_get = $_GET;
$original_page = $asgarosforum->current_page;
$original_page_size = $asgarosforum->options['topics_per_page'];
$search_as = function ($user_id) use ($asgarosforum) {
    wp_set_current_user($user_id);
    $asgarosforum->permissions->currentUserID = $user_id;
};
$run_search = function ($filters, $num_page = 0) use ($asgarosforum, $wpdb) {
    $_GET = wp_slash($filters);
    $asgarosforum->current_page = $num_page;
    $asgarosforum->search->initialize();
    $results = $asgarosforum->search->get_search_results();
    forum_assert(!$wpdb->last_error, 'Search should not cause a database error');
    return $results;
};
$assert_search = function ($filters, $ids, $message, $page = 0, $count = null) use ($run_search) {
    $results = $run_search($filters, $page);
    forum_assert($results['count'] === ($count ?? count($ids))
        && array_map('intval', wp_list_pluck($results['posts'], 'id')) === $ids, $message);
};

$search_as($search_user);
$filters = array('keywords' => 'Searchfixture', 'search_category' => (string) $search_category,
    'search_author' => "Avery O'Neil", 'search_from' => '2026-02-01', 'search_to' => '2026-02-01');
$assert_search($filters, array($reply), 'All filters must apply to the same post, including the end of the selected day');
$assert_search(array_diff_key($filters, array('keywords' => true)), array($reply, $unmatched), 'Filters work without keywords');
$assert_search(array('search_category' => (string) $search_category), array($next_day, $reply, $unmatched, $opening), 'Category-only search includes its subforum posts');
$assert_search(array('search_forum' => (string) $parent_forum), array($next_day, $reply, $unmatched, $opening), 'Forum filters include subforum posts');
$assert_search(array('search_scope' => 'category:'.$search_category), array($next_day, $reply, $unmatched, $opening), 'The location picker can select a category');
$assert_search(array('search_scope' => 'forum:'.$parent_forum), array($next_day, $reply, $unmatched, $opening), 'The location picker can select a forum and its subforums');
$assert_search(array('search_category' => (string) $other_category, 'search_forum' => (string) $parent_forum), array(), 'Forum and category restrictions must intersect');
$assert_search(array('keywords' => 'Searchfixture', 'search_in' => 'titles'), array($opening), 'Titles-only search excludes body matches and only returns the opening post');
$assert_search(array('keywords' => 'Searchfixture', 'search_in' => 'titles', 'search_author' => "Avery O'Neil"), array(), 'Title-only author filter applies to the author of the opening post');
$assert_search(array('keywords' => 'Searchfixture', 'search_sort' => 'oldest'), array($opening, $other_post, $members_post, $reply, $next_day), 'Date sorting and private-topic visibility must apply to the same result set');
$assert_search(array('search_from' => '2026-02-30'), array(), 'Invalid calendar dates must be rejected');
$assert_search(array('search_from' => '2026-02-02', 'search_to' => '2026-02-01'), array(), 'Reversed dates must be rejected');
$assert_search(array('keywords' => 'Searchfixture', 'search_category' => $search_category.",".$other_category), array(), 'Invalid category identifiers must not broaden the search');
$assert_search(array('search_author' => "Avery O'Neille"), array(), 'Author search must compare a bound display name');
forum_assert($run_search(array('keywords' => array('Searchfixture'))) === null, 'Malformed keyword parameters must be ignored safely');
forum_assert($run_search(array()) === null, 'Opening the search page should not search the full archive');

$asgarosforum->options['topics_per_page'] = 2;
$assert_search(array('keywords' => 'Searchfixture'), array($next_day, $reply), 'First page uses stable newest-first ordering and counts all visible matches', 0, 5);
$assert_search(array('keywords' => 'Searchfixture'), array($members_post, $other_post), 'Second page uses the same filters and counts', 1, 5);
$asgarosforum->options['topics_per_page'] = $original_page_size;

$block_category = function ($allowed, $category) use ($search_category) { return $allowed && (int) $category !== $search_category; };
add_filter('asgarosforum_filter_check_access', $block_category, 10, 2);
$assert_search(array('keywords' => 'Searchfixture', 'search_category' => (string) $search_category), array(), 'Custom category access policies must apply even to a directly submitted category ID');
$assert_search(array('search_scope' => 'forum:'.$parent_forum), array(), 'The location picker cannot expose a forum in an inaccessible category');
remove_filter('asgarosforum_filter_check_access', $block_category, 10);

update_term_meta($other_category, 'usergroups', array(999999));
$assert_search(array('keywords' => 'Searchfixture', 'search_category' => (string) $other_category), array(), 'The usergroup restriction must apply to results and counts');
delete_term_meta($other_category, 'usergroups');

$asgarosforum->shortcode->includeCategories = array($other_category);
$assert_search(array('keywords' => 'Searchfixture'), array($other_post), 'Category-restricted shortcode scope must apply');
$asgarosforum->shortcode->includeCategories = array();
$asgarosforum->shortcode->search_forum_id = $parent_forum;
$assert_search(array('keywords' => 'Searchfixture'), array($next_day, $reply, $opening), 'Forum-restricted shortcode scope includes subforums');
$asgarosforum->shortcode->search_forum_id = 0;

$search_as(0);
$assert_search(array('keywords' => 'Searchfixture'), array($next_day, $reply, $other_post, $opening), 'Guests must not find members-only, private or unapproved posts');
$asgarosforum->options['require_login_posts'] = true;
$assert_search(array('keywords' => 'Searchfixture'), array(), 'Guest search must honor the login-to-read setting');
$asgarosforum->options['require_login_posts'] = false;
$search_as(1);
$assert_search(array('keywords' => 'Searchfixture'), array($next_day, $reply, $private_post, $members_post, $other_post, $opening), 'Moderators can search private forum posts and search still excludes unapproved topics');

$_GET = $original_get;
$asgarosforum->current_page = $original_page;
$asgarosforum->search->initialize();
echo "Forum search filters, matching replies, keyword scope, pagination, and permissions verified\n";
