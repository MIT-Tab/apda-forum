<?php

if (!defined('ABSPATH')) {
    exit;
}

class AsgarosForumApdaSiteAssets {
    private const ADD_PERMISSIONS = 'apda_add_permissions';
    private const MEMBER_ROLES = array('subscriber', 'private_side_viewer');

    private $forum;

    public function __construct($forum) {
        $this->forum = $forum;

        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'), 100);
        add_action('asgarosforum_after_post_username', array($this, 'render_permission_controls'));
        add_action('admin_post_'.self::ADD_PERMISSIONS, array($this, 'submit_permissions'));
    }

    public function enqueue_styles() {
        $dependencies = array();
        if (get_stylesheet() === 'bloghash') {
            $theme_styles = wp_style_is('bloghash-dynamic-styles', 'enqueued') ? array('bloghash-dynamic-styles') : array();
            wp_enqueue_style('apda-bloghash', $this->forum->plugin_url.'skin/apda-bloghash.css', $theme_styles, $this->forum->version);
            $dependencies[] = 'apda-bloghash';
        }
        wp_enqueue_style('apda-site', $this->forum->plugin_url.'skin/apda-site.css', $dependencies, $this->forum->version);
    }

    public function render_permission_controls($user_id) {
        if ($this->forum->current_view !== 'topic' || !$this->can_edit_author($user_id)) {
            return;
        }

        $edit_url = get_edit_user_link($user_id);
        if (!$edit_url) {
            return;
        }

        echo '<a class="apda-edit-permissions" href="'.esc_url($edit_url).'" target="_blank" rel="noopener">'.esc_html__('Edit perms', 'asgaros-forum').'</a>';
        if (!$this->can_grant_roles($user_id) || !$this->get_members_group()) {
            return;
        }

        echo '<form class="apda-permissions-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'" target="_blank" rel="noopener">';
        echo '<input type="hidden" name="action" value="'.esc_attr(self::ADD_PERMISSIONS).'">';
        echo '<input type="hidden" name="user_id" value="'.esc_attr($user_id).'">';
        $nonce = wp_create_nonce(self::ADD_PERMISSIONS.'_'.$user_id);
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';
        echo '<button class="apda-add-permissions" type="submit">'.esc_html__('Add perms', 'asgaros-forum').'</button>';
        echo '</form>';
    }

    private function can_edit_author($user_id) {
        return $user_id && current_user_can('manage_options') && current_user_can('edit_user', $user_id);
    }

    private function can_grant_roles($user_id) {
        return (int) $user_id !== get_current_user_id()
            && !user_can($user_id, 'manage_options')
            && current_user_can('promote_user', $user_id);
    }

    private function get_members_group() {
        $taxonomy = apply_filters('asgarosforum_filter_user_groups_taxonomy_name', 'asgarosforum-usergroup');
        $group = get_term_by('slug', 'apda-members', $taxonomy);
        return $group && AsgarosForumUserGroups::getUserGroupsArrayFilter($group) ? $group : null;
    }

    public function submit_permissions() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            wp_die(esc_html__('Use the Add perms button to grant permissions.', 'asgaros-forum'), '', array('response' => 405));
        }

        $user_id = is_string($_POST['user_id'] ?? null) && ctype_digit($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $nonce = is_string($_POST['_wpnonce'] ?? null) ? wp_unslash($_POST['_wpnonce']) : '';
        $result = $this->grant_member_permissions($user_id, $nonce);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', array('response' => $result->get_error_data()));
        }

        wp_safe_redirect(add_query_arg('updated', '1', get_edit_user_link($user_id)), 303);
        exit;
    }

    public function grant_member_permissions($user_id, $nonce) {
        $user = get_userdata($user_id);
        if (!$user || !$this->can_edit_author($user_id) || !$this->can_grant_roles($user_id)
            || !wp_verify_nonce($nonce, self::ADD_PERMISSIONS.'_'.$user_id)) {
            return new WP_Error('apda_permissions_denied', __('You cannot grant permissions to this user.', 'asgaros-forum'), 403);
        }

        $group = $this->get_members_group();
        if (!$group || array_diff(self::MEMBER_ROLES, array_keys(get_editable_roles()))) {
            return new WP_Error('apda_permissions_unavailable', __('The APDA member roles or forum group are unavailable.', 'asgaros-forum'), 409);
        }

        $error = new WP_Error('apda_permissions_failed', __('WordPress could not save all member permissions. You can retry from the topic.', 'asgaros-forum'), 500);
        if (is_wp_error(AsgarosForumUserGroups::addUserToUserGroup($user_id, $group->term_id))) {
            return $error;
        }

        // Each grant is additive so retrying can complete an interrupted update.
        foreach (self::MEMBER_ROLES as $role) {
            $user = get_userdata($user_id);
            if (!$user) {
                return $error;
            }
            $user->add_role($role);
        }
        $user = get_userdata($user_id);
        if (!$user || array_diff(self::MEMBER_ROLES, $user->roles)) {
            return $error;
        }

        return true;
    }
}
