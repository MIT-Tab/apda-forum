<?php

forum_assert(!has_action('asgarosforum_after_post_username'), 'APDA permission shortcuts require opt-in');
require_once dirname(__DIR__).'/integrations/integration-apda-site-assets.php';
require_once ABSPATH.'wp-admin/includes/user.php';

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
$author_data = get_userdata($author);
$author_data->set_role('contributor');
$other_group = wp_insert_term('Other group', 'asgarosforum-usergroup', array('parent' => $category['term_id']));
AsgarosForumUserGroups::insertUserGroupsOfUsers($author, array($other_group['term_id']));

$controls_for = function($user_id) {
    ob_start();
    do_action('asgarosforum_after_post_username', $user_id);
    return ob_get_clean();
};
$original_view = $asgarosforum->current_view;
$asgarosforum->current_view = 'topic';
wp_set_current_user($author);
forum_assert($controls_for(1) === '', 'A regular user sees no shortcuts');
$denied = $assets->grant_member_permissions($author, wp_create_nonce('apda_add_permissions_'.$author));
forum_assert(is_wp_error($denied) && $denied->get_error_data() === 403, 'A regular user cannot grant permissions');

wp_set_current_user(1);
forum_assert($controls_for(0) === '', 'Guest posts have no user shortcuts');
forum_assert(strpos($controls_for(1), 'apda-add-permissions') === false, 'No grant shortcut for the administrator account');
$controls = $controls_for($author);
forum_assert(strpos($controls, 'apda-edit-permissions') !== false && strpos($controls, 'apda-add-permissions') !== false, 'Administrator sees both shortcuts for an author');
forum_assert(strpos($controls, 'method="post"') !== false && strpos($controls, '/wp-admin/admin-post.php') !== false, 'Add perms uses a native admin POST form');
$asgarosforum->current_view = 'post';
forum_assert($controls_for($author) === '', 'Shortcuts only appear in topic view');
$asgarosforum->current_view = $original_view;

$nonce = wp_create_nonce('apda_add_permissions_'.$author);
$wrong_nonce = $assets->grant_member_permissions($author, wp_create_nonce('apda_add_permissions_1'));
forum_assert(is_wp_error($wrong_nonce) && $wrong_nonce->get_error_data() === 403, 'A nonce for another author is rejected');
$admin_grant = $assets->grant_member_permissions(1, wp_create_nonce('apda_add_permissions_1'));
forum_assert(is_wp_error($admin_grant), 'Cannot grant this bundle to a site administrator');
$exclude_role = function($roles) {
    unset($roles['private_side_viewer']);
    return $roles;
};
add_filter('editable_roles', $exclude_role);
$unavailable = $assets->grant_member_permissions($author, $nonce);
remove_filter('editable_roles', $exclude_role);
forum_assert(is_wp_error($unavailable) && $unavailable->get_error_data() === 409, 'Every role must be assignable before granting');
forum_assert(get_userdata($author)->roles === array('contributor'), 'Rejected requests leave roles intact');
forum_assert(AsgarosForumUserGroups::getUserGroupsOfUser($author, 'ids') === array($other_group['term_id']), 'Rejected requests leave memberships intact');

$group_add_count = 0;
add_action('asgarosforum_usergroup_'.$group['term_id'].'_add_user', function() use (&$group_add_count) { ++$group_add_count; });
forum_assert($assets->grant_member_permissions($author, $nonce) === true, 'A signed grant saves permissions directly');
forum_assert($assets->grant_member_permissions($author, $nonce) === true, 'Repeating the grant succeeds');
$roles = get_userdata($author)->roles;
sort($roles);
forum_assert($roles === array('contributor', 'private_side_viewer', 'subscriber'), 'Both roles are appended to existing roles');
$groups = AsgarosForumUserGroups::getUserGroupsOfUser($author, 'ids');
forum_assert(count($groups) === 2 && in_array($group['term_id'], $groups, true) && in_array($other_group['term_id'], $groups, true), 'APDA Members is appended to existing groups');
forum_assert($group_add_count === 1, 'The membership hook fires once');

echo "APDA site asset loading and native permission grants verified\n";
