<?php

forum_assert(!has_filter('asgarosforum_filter_post_username'), 'APDA permission shortcuts require opt-in');
require_once dirname(__DIR__).'/integrations/integration-apda-site-assets.php';
require_once ABSPATH.'wp-admin/includes/user.php';

function members_multiple_user_roles_enabled() {
    return true;
}

$assets = new AsgarosForumApdaSiteAssets($asgarosforum);
$assets->enqueue_styles();
forum_assert(wp_style_is('apda-site', 'enqueued'), 'Site CSS loads with opt-in');
forum_assert(!wp_style_is('apda-bloghash', 'enqueued'), 'BlogHash CSS stays scoped to BlogHash');
$styles = wp_styles();
wp_register_style('bloghash-dynamic-styles', '/dynamic-styles.css');
wp_enqueue_style('bloghash-dynamic-styles');
$bloghash = function() { return 'bloghash'; };
add_filter('stylesheet', $bloghash);
$assets->enqueue_styles();
remove_filter('stylesheet', $bloghash);
forum_assert($styles->registered['apda-bloghash']->deps === array('bloghash-dynamic-styles'), 'Migrated CSS follows BlogHash dynamic CSS');

$category = wp_insert_term('APDA groups', 'asgarosforum-usergroup');
$group = wp_insert_term('APDA Members', 'asgarosforum-usergroup', array('parent' => $category['term_id']));
add_role('private_side_viewer', 'Private Side Viewer', array('read' => true));
$author = wp_create_user('permissions-author', 'test-password', 'permissions-author@example.test');
$username = '<a class="profile-link">Topic author</a>';
$original_view = $asgarosforum->current_view;
$asgarosforum->current_view = 'topic';
wp_set_current_user($author);
forum_assert($assets->add_permission_links($username, 1) === $username, 'A regular user sees no shortcuts');
wp_set_current_user(1);
forum_assert($assets->add_permission_links($username, 0) === $username, 'Guest posts have no user shortcuts');
forum_assert(strpos($assets->add_permission_links($username, 1), 'apda-add-permissions') === false, 'No bulk shortcut for the administrator account');
$links = $assets->add_permission_links($username, $author);
forum_assert(strpos($links, 'apda-edit-permissions') !== false && strpos($links, 'apda-add-permissions') !== false, 'Administrator sees both shortcuts for an author');
$asgarosforum->current_view = 'post';
forum_assert($assets->add_permission_links($username, $author) === $username, 'Shortcuts only appear in topic view');
$asgarosforum->current_view = $original_view;

$original_query = $_GET;
$_GET = array('user_id' => $author, 'apda_add_permissions' => wp_create_nonce('apda_add_permissions_1'));
$assets->enqueue_permission_script('user-edit.php');
forum_assert(!wp_script_is('apda-add-permissions', 'enqueued'), 'A nonce for another author is rejected');
$_GET['apda_add_permissions'] = wp_create_nonce('apda_add_permissions_'.$author);
$assets->enqueue_permission_script('profile.php');
forum_assert(!wp_script_is('apda-add-permissions', 'enqueued'), 'No permission script on the current user profile page');
$assets->enqueue_permission_script('user-edit.php');
forum_assert(wp_script_is('apda-add-permissions', 'enqueued'), 'The signed shortcut loads the editor script');
$setup = implode('', wp_scripts()->get_data('apda-add-permissions', 'before'));
forum_assert(strpos($setup, '"group":"'.$group['term_id'].'"') !== false, 'APDA Members group resolves by slug');
$_GET = $original_query;

echo "APDA site asset loading, topic shortcuts, and signed admin action verified\n";
