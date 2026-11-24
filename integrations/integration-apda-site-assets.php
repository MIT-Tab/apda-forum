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
        add_filter('asgarosforum_filter_post_username', array($this, 'add_permission_links'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_permission_script'));
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

    public function add_permission_links($username, $user_id) {
        if ($this->forum->current_view !== 'topic' || !$this->can_manage_author($user_id)) {
            return $username;
        }

        $edit_url = get_edit_user_link($user_id);
        if (!$edit_url) {
            return $username;
        }

        $username .= $this->permission_link($edit_url, 'apda-edit-permissions', __('Edit perms', 'asgaros-forum'));
        if ($this->can_add_permissions($user_id) && $this->get_members_group()) {
            $add_url = add_query_arg(self::ADD_PERMISSIONS, wp_create_nonce(self::ADD_PERMISSIONS.'_'.$user_id), $edit_url);
            $username .= $this->permission_link($add_url, 'apda-add-permissions', __('Add perms', 'asgaros-forum'));
        }
        return $username;
    }

    private function permission_link($url, $class, $label) {
        return '<a class="'.esc_attr($class).'" href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html($label).'</a>';
    }

    private function can_manage_author($user_id) {
        return $user_id && current_user_can('manage_options') && current_user_can('edit_user', $user_id);
    }

    private function can_add_permissions($user_id) {
        return (int) $user_id !== get_current_user_id()
            && !user_can($user_id, 'manage_options')
            && current_user_can('promote_user', $user_id)
            && function_exists('members_multiple_user_roles_enabled')
            && members_multiple_user_roles_enabled();
    }

    private function get_members_group() {
        $taxonomy = apply_filters('asgarosforum_filter_user_groups_taxonomy_name', 'asgarosforum-usergroup');
        $group = get_term_by('slug', 'apda-members', $taxonomy);
        return $group && AsgarosForumUserGroups::getUserGroupsArrayFilter($group) ? $group : null;
    }

    public function enqueue_permission_script($page) {
        if ($page !== 'user-edit.php' || !is_string($_GET[self::ADD_PERMISSIONS] ?? null)) {
            return;
        }

        $user_id = absint($_GET['user_id'] ?? 0);
        if (!$this->can_manage_author($user_id) || !$this->can_add_permissions($user_id)
            || !wp_verify_nonce(wp_unslash($_GET[self::ADD_PERMISSIONS]), self::ADD_PERMISSIONS.'_'.$user_id)) {
            return;
        }

        $editable_roles = get_editable_roles();
        if (array_diff(self::MEMBER_ROLES, array_keys($editable_roles))) {
            return;
        }

        $group = $this->get_members_group();
        if (!$group) {
            return;
        }

        wp_enqueue_script('apda-add-permissions', $this->forum->plugin_url.'admin/js/apda-add-permissions.js', array(), $this->forum->version, true);
        wp_add_inline_script('apda-add-permissions', 'const apdaMemberPermissions = '.wp_json_encode(array(
            'roles' => self::MEMBER_ROLES,
            'group' => (string) $group->term_id,
            'groupField' => $group->taxonomy.'[]',
        )).';', 'before');
    }
}
