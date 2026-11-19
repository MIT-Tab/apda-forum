<?php

if (!defined('ABSPATH')) {
    exit;
}

class AsgarosForumReactions {
    private $asgarosforum   = null;
    private $reactions_list = array();
    private $post_reactions = array();

    public function __construct($asgarosForumObject) {
        $this->asgarosforum = $asgarosForumObject;

        add_action('init', array($this, 'initialize'));
        add_action('asgarosforum_prepare_topic', array($this, 'prepare'));
        add_action('asgarosforum_prepare_post', array($this, 'prepare'));
        add_action('rest_api_init', array($this, 'initialize_routes'));
    }

    public function initialize() {
        // Stable ASCII keys preserve existing votes and work with any database charset.
        $emojis = array(
            'up' => array('👍', __('Thumbs up', 'asgaros-forum')),
            'down' => array('👎', __('Thumbs down', 'asgaros-forum')),
            'heart' => array('❤️', __('Heart', 'asgaros-forum')),
            'laugh' => array('😂', __('Laugh', 'asgaros-forum')),
            'celebrate' => array('🎉', __('Celebrate', 'asgaros-forum')),
            'surprised' => array('😮', __('Surprised', 'asgaros-forum')),
            'sad' => array('😢', __('Sad', 'asgaros-forum')),
            'thinking' => array('🤔', __('Thinking', 'asgaros-forum')),
            'fire' => array('🔥', __('Fire', 'asgaros-forum')),
            'eyes' => array('👀', __('Eyes', 'asgaros-forum')),
            'clap' => array('👏', __('Clap', 'asgaros-forum')),
            'hundred' => array('💯', __('Hundred', 'asgaros-forum')),
        );
        foreach ($emojis as $key => $emoji) {
            $this->reactions_list[$key] = array('emoji' => $emoji[0], 'screen_reader_text' => $emoji[1]);
        }

        if ($this->asgarosforum->options['enable_reactions']) {
            // Allow filtering of reactions.
            $this->reactions_list = apply_filters('asgarosforum_reactions', $this->reactions_list);
        }
    }

    public function prepare() {
        if ($this->asgarosforum->options['enable_reactions']) {
            // Load the reactions for the current topic.
            $this->load_reactions($this->asgarosforum->current_topic);
        }
    }

    // Loads all reactions for the given topic.
    public function load_reactions($topic_id) {
        if ($topic_id) {
            $this->post_reactions = array();

            $reactions = $this->asgarosforum->db->get_results("SELECT r.* FROM {$this->asgarosforum->tables->reactions} AS r, {$this->asgarosforum->tables->posts} AS p WHERE p.parent_id = {$topic_id} AND p.id = r.post_id;");

            foreach ($reactions as $reaction) {
                if (!isset($this->post_reactions[$reaction->post_id])) {
                    $this->post_reactions[$reaction->post_id] = array();
                }

                if (!isset($this->post_reactions[$reaction->post_id][$reaction->reaction])) {
                    $this->post_reactions[$reaction->post_id][$reaction->reaction] = array();
                }

                $this->post_reactions[$reaction->post_id][$reaction->reaction][] = $reaction->user_id;
            }
        }
    }

    public function initialize_routes() {
        if ($this->asgarosforum->options['enable_reactions']) {
            register_rest_route(
                'asgaros-forum/v1',
                '/reaction/(?P<post_id>\d+)/(?P<reaction>[a-zA-Z0-9-]+)',
                array(
                    'methods'             => 'POST',
                    'callback'            => array($this, 'reaction_callback'),
                    'permission_callback' => 'is_user_logged_in',
                )
            );
        }
    }

    public function reaction_callback($data) {
        // Build response-array.
        $response           = array();
        $response['status'] = false;

        // Ensure user is logged-in.
        if (is_user_logged_in()) {
			$post_id = absint($data['post_id']);

            // Get post object.
            $post_object = $this->asgarosforum->content->get_post($post_id);

            // Apply the same access checks as the forum before returning reaction names.
            if ($post_object && $this->can_react($post_object)) {
                // Load reactions.
                $this->load_reactions($post_object->parent_id);

                // Change reaction.
                $response['status'] = $this->reaction_change($post_id, get_current_user_id(), $data['reaction'], $post_object->author_id);

                // Reload reactions.
                $this->load_reactions($post_object->parent_id);

                // Build updated reactions for posts.
                $response['data']['reactions'] = $this->render_reactions($post_id, $post_object->author_id);
                $response['data']['summary']   = ($this->asgarosforum->options['reactions_show_names']) ? $this->render_reactions_summary($post_id) : '';
            }
        }

        return new WP_REST_Response($response, 200);
    }

    // Renders reactions-area if the reactions-functionality is enabled.
    public function render_reactions_area($post_id, $author_id) {
        if ($this->asgarosforum->options['enable_reactions']) {
            echo '<div class="post-reactions">';
            echo $this->render_reactions($post_id, $author_id);
            echo '</div>';
        }
    }

    // Reaction access must also be enforced on direct REST requests.
    private function can_react($post) {
        $user_id = get_current_user_id();
        if (!$this->asgarosforum->options['enable_reactions'] || !$user_id || $this->asgarosforum->permissions->isBanned($user_id)) {
            return false;
        }
        $topic = $this->asgarosforum->content->get_topic($post->parent_id);
        $forum = $topic ? $this->asgarosforum->content->get_forum($topic->parent_id) : false;
        if (!$forum || !$this->asgarosforum->permissions->can_access_category($user_id, $forum->parent_id)
            || !AsgarosForumUserGroups::canUserAccessForumCategory($user_id, $forum->parent_id)
            || !apply_filters('asgarosforum_filter_check_access', true, $forum->parent_id)) {
            return false;
        }
        if (!$this->asgarosforum->permissions->isModerator($user_id)) {
            if (!$topic->approved) {
                return false;
            }
            if ($this->asgarosforum->private->is_private_forum($forum->id) && $topic->author_id != $user_id) {
                return false;
            }
        }
        return true;
    }

    private function reaction_icon($reaction) {
        if (isset($reaction['emoji'])) {
            return '<span class="reaction-emoji wp-exclude-emoji" aria-hidden="true">'.esc_html($reaction['emoji']).'</span>';
        }
        // Preserve the existing asgarosforum_reactions filter for icon-based extensions.
        return '<i aria-hidden="true" class="'.esc_attr(isset($reaction['icon']) ? $reaction['icon'] : '').'"></i>';
    }

    public function render_reactions($post_id, $author_id) {
        $output = '';
        // Rendering already occurs inside an authorized topic; REST checks access separately.
        $can_react = is_user_logged_in() && !$this->asgarosforum->permissions->isBanned(get_current_user_id());
        foreach ($this->reactions_list as $key => $reaction) {
            $users = isset($this->post_reactions[$post_id][$key]) ? $this->post_reactions[$post_id][$key] : array();
            if (!$users) {
                continue;
            }
            $active = in_array(get_current_user_id(), $users);
            $label = $reaction['screen_reader_text'].' ('.number_format_i18n(count($users)).')';
            $attributes = ' class="reaction-pill'.($active ? ' reaction-active' : '').'" aria-label="'.esc_attr($label).'"';
            $attributes .= ' title="'.esc_attr($label).'"';
            if ($can_react) {
                $output .= '<button type="button"'.$attributes.' aria-pressed="'.($active ? 'true' : 'false').'" data-post-id="'.absint($post_id).'" data-reaction="'.esc_attr($key).'">';
            } else {
                $output .= '<span'.$attributes.'>';
            }
            $output .= $this->reaction_icon($reaction).'<span class="reaction-count">'.number_format_i18n(count($users)).'</span>';
            $output .= $can_react ? '</button>' : '</span>';
        }
        if ($can_react) {
            $output .= '<details class="reaction-picker"><summary aria-label="'.esc_attr__('Add reaction', 'asgaros-forum').'" title="'.esc_attr__('Add reaction', 'asgaros-forum').'"><span class="reaction-emoji wp-exclude-emoji" aria-hidden="true">🙂</span><span aria-hidden="true">+</span></summary>';
            $output .= '<div class="reaction-picker-options" role="group" aria-label="'.esc_attr__('Choose a reaction', 'asgaros-forum').'">';
            foreach ($this->reactions_list as $key => $reaction) {
                $users = isset($this->post_reactions[$post_id][$key]) ? $this->post_reactions[$post_id][$key] : array();
                $output .= '<button type="button" data-post-id="'.absint($post_id).'" data-reaction="'.esc_attr($key).'" aria-pressed="'.(in_array(get_current_user_id(), $users) ? 'true' : 'false').'" title="'.esc_attr($reaction['screen_reader_text']).'" aria-label="'.esc_attr($reaction['screen_reader_text']).'">'.$this->reaction_icon($reaction).'</button>';
            }
            $output .= '</div></details>';
            $output .= '<span class="reaction-feedback" role="status" aria-live="polite"></span>';
        }
        return $output;
    }

    // Renders reactions-summary-area if the reactions-functionality is enabled.
    public function render_reactions_summary_area($post_id) {
        if ($this->asgarosforum->options['enable_reactions'] && $this->asgarosforum->options['reactions_show_names']) {
            echo '<div class="post-reactions-summary">';
            echo $this->render_reactions_summary($post_id);
            echo '</div>';
        }
    }

    // Renders a summary of given reactions.
    public function render_reactions_summary($post_id) {
        $output = '';

        // Build arrays with names.
        $reaction_names         = array();
        $reaction_names_grouped = array();

        foreach ($this->reactions_list as $key => $reaction) {
            if (!empty($this->post_reactions[$post_id][$key])) {
                $reaction_names_grouped[$key] = array();

                foreach ($this->post_reactions[$post_id][$key] as $userId) {
                    $reaction_names[$userId]         = esc_html($this->asgarosforum->get_plain_username($userId));
                    $reaction_names_grouped[$key][] = esc_html($this->asgarosforum->get_plain_username($userId));
                }
            }
        }

        $reaction_names = array_values($reaction_names);

        // Render summary and details.
        if (!empty($reaction_names)) {
            $output .= '<div class="reaction-names">';

            if (count($reaction_names) === 1) {
                $output .= sprintf(__('%s has reacted to this post.', 'asgaros-forum'), $reaction_names[0]);
            } else if (count($reaction_names) === 2) {
                $output .= sprintf(__('%1$s and %2$s have reacted to this post.', 'asgaros-forum'), $reaction_names[0], $reaction_names[1]);
            } else if (count($reaction_names) === 3) {
                $output .= sprintf(__('%1$s, %2$s and %3$s have reacted to this post.', 'asgaros-forum'), $reaction_names[0], $reaction_names[1], $reaction_names[2]);
            } else {
                $output .= sprintf(__('%1$s, %2$s and %3$s other users have reacted to this post.', 'asgaros-forum'), $reaction_names[0], $reaction_names[1], count($reaction_names) - 2);
            }

            $output .= '</div>';

            // Render details-container.
            $output .= '<div class="temporary-modal-container">';
            $output .= '<div class="asgarosforum-reaction-details">';

            foreach ($reaction_names_grouped as $reaction_name => $reaction_users) {
                foreach ($reaction_users as $reaction_user) {
                    $output .= '<span>'.$this->reaction_icon($this->reactions_list[$reaction_name]).' '.$reaction_user.'</span>';
                }
            }

            $output .= '</div>';
            $output .= '</div>';
        }

        return $output;
    }

    public function reaction_change($post_id, $user_id, $reaction, $author_id) {
        // Only add a reaction when the post exists ...
        if (!$this->asgarosforum->content->post_exists($post_id)) {
            return false;
        }

        // ... and the user is logged in ...
        if (!is_user_logged_in()) {
            return false;
        }

        // ... and the user is not banned ...
        if ($this->asgarosforum->permissions->isBanned($user_id)) {
            return false;
        }

        // ... and the reaction is not empty ...
        if (empty($reaction)) {
            return false;
        }

        // ... and when it is a valid reaction ...
        if (!isset($this->reactions_list[$reaction])) {
            return false;
        }

        $post = $this->asgarosforum->content->get_post($post_id);
        if ($user_id != get_current_user_id() || !$post || !$this->can_react($post)) {
            return false;
        }
        // Check the exact emoji in the database, not a cached first reaction.
        $exists = $this->asgarosforum->db->get_var($this->asgarosforum->db->prepare(
            "SELECT user_id FROM {$this->asgarosforum->tables->reactions} WHERE post_id = %d AND user_id = %d AND reaction = %s",
            $post_id, $user_id, $reaction
        ));
        if ($exists !== null) {
            return $this->remove_reaction($post_id, $user_id, $reaction);
        }
        return $this->add_reaction($post_id, $user_id, $reaction, $post->author_id);
    }

    public function add_reaction($post_id, $user_id, $reaction, $author_id) {
		// Get the current time.
        $date = $this->asgarosforum->current_time();

        $result = $this->asgarosforum->db->insert($this->asgarosforum->tables->reactions, array(
			'post_id'   => $post_id,
			'user_id'   => $user_id,
			'reaction'  => $reaction,
			'author_id' => $author_id,
			'datestamp' => $date,
		), array('%d', '%d', '%s', '%d', '%s'));

        if ($result === false) {
            return false;
        }
        do_action('asgarosforum_after_add_reaction', $post_id, $user_id, $reaction);
        return true;
    }

    public function remove_reaction($post_id, $user_id, $reaction) {

        $result = $this->asgarosforum->db->delete($this->asgarosforum->tables->reactions, array(
			'post_id'  => $post_id,
			'user_id'  => $user_id,
			'reaction' => $reaction,
		), array('%d', '%d', '%s'));

        if ($result === false) {
            return false;
        }
        do_action('asgarosforum_after_remove_reaction', $post_id, $user_id, $reaction);
        return true;
    }

    // Backwards-compatible entrypoint: add this emoji without overwriting other reactions.
    public function update_reaction($post_id, $user_id, $reaction) {
        return $this->reaction_change($post_id, $user_id, $reaction, 0);
    }

    // Removes all reactions from a specific post.
    public function remove_all_reactions($post_id) {
        // Remove reactions from database.
        $this->asgarosforum->db->delete($this->asgarosforum->tables->reactions, array('post_id' => $post_id), array('%d'));

        // Remove reactions from object.
        if (isset($this->post_reactions[$post_id])) {
            unset($this->post_reactions[$post_id]);
        }
    }

    public function reaction_exists($post_id, $user_id) {
        // Cancel if user-ID is 0.
        if ($user_id === 0) {
            return false;
        }

        if (isset($this->post_reactions[$post_id])) {
            foreach ($this->post_reactions[$post_id] as $reaction => $user_ids) {
                if (in_array($user_id, $user_ids)) {
                    return $reaction;
                }
            }
        }

        return false;
    }

    public function get_reactions_received($user_id, $reaction) {
        return $this->asgarosforum->db->get_var("SELECT COUNT(*) FROM {$this->asgarosforum->tables->reactions} AS r, {$this->asgarosforum->tables->posts} AS p WHERE r.post_id = p.id AND p.author_id = {$user_id} AND r.reaction = '{$reaction}';");
    }
}
