<?php

if (!defined('ABSPATH')) {
    exit;
}

class APDATournamentPosts {
    private $forum;

    public function __construct($forum) {
        $this->forum = $forum;
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route('apda-forum/v1', '/tournaments/(?P<publication_id>[a-f0-9]{32})/(?P<operation>announcement|results|tab-cards)', array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'authorize'),
            'callback' => array($this, 'publish'),
        ));
    }

    private function config($key) {
        return defined($key) ? constant($key) : '';
    }

    private function claims($request) {
        $secret = $this->config('APDA_MITTAB_FORUM_SIGNING_KEY');
        $token = $request->get_header('X-APDA-Tournament-Token');
        if (!$secret || !preg_match('/\A([A-Za-z0-9_-]{1,2400})\.([a-f0-9]{64})\z/', $token ?? '', $parts)
            || !hash_equals(hash_hmac('sha256', $parts[1], $secret), $parts[2])) {
            return null;
        }

        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        if (!is_array($claims) || ($claims['id'] ?? null) !== $request->get_url_params()['publication_id']
            || !in_array($claims['mode'] ?? null, array('new', 'existing'), true)) {
            return null;
        }
        return $claims;
    }

    public function authorize($request) {
        if ($request->get_url_params()['operation'] === 'tab-cards') {
            $secret = $this->config('APDA_BLACK_ROD_FORUM_TOKEN');
            $allowed = $secret && hash_equals($secret, $request->get_header('X-APDA-Results-Token') ?? '');
        } else {
            $claims = $this->claims($request);
            $allowed = $claims && ($request->get_url_params()['operation'] === 'results' || $claims['mode'] === 'new');
        }
        return $allowed ? true : new WP_Error('forum_tournament_unauthorized', 'Invalid tournament publishing credential.', array('status' => 401));
    }

    public function publish($request) {
        global $wpdb;
        $route = $request->get_url_params();
        $operation = $route['operation'];
        $id = $route['publication_id'];
        $author = (int) $this->config('APDA_TOURNAMENT_BOT_USER_ID');
        $destination = $this->forum->content->get_forum((int) $this->config('APDA_TOURNAMENT_FORUM_ID'));
        if ((int) get_option('asgarosforum_db_version') < 66 || !$author || !get_user_by('id', $author) || user_can($author, 'manage_options') || !$destination || $this->forum->permissions->isBanned($author)) {
            return new WP_Error('forum_bot_unavailable', 'Tournament publishing is unavailable.', array('status' => 503));
        }
        $params = $request->get_json_params();
        $text = $params['body'] ?? null;
        $link = $params['url'] ?? null;
        if ($operation === 'results' && (!is_string($text) || trim($text) === '' || strlen($text) > 60000)) {
            return new WP_Error('forum_tournament_body', 'Supply a nonempty results body up to 60 KB.', array('status' => 400));
        }
        if ($operation === 'tab-cards' && (!is_string($link) || !$this->valid_results_url($link))) {
            return new WP_Error('forum_tab_cards_url', 'Supply a tournament tab cards URL.', array('status' => 400));
        }

        $table = $this->forum->tables->tournament_posts;
        if ($wpdb->query('START TRANSACTION') === false) {
            return $this->write_error();
        }

        try {
            // A write before reading serializes requests for the same tournament.
            if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (publication_id) VALUES (%s)", $id)) === false) {
                throw new RuntimeException();
            }
            if ($wpdb->query($wpdb->prepare("UPDATE $table SET publication_id = publication_id WHERE publication_id = %s", $id)) === false) {
                throw new RuntimeException();
            }
            $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE publication_id = %s", $id));
            if (!$record) {
                throw new RuntimeException();
            }
            if ($operation === 'tab-cards') {
                $response = $this->save_tab_cards($record, $link, $author);
            } else {
                $response = $this->save_post($record, $this->claims($request), $operation, $author, $destination, $text);
            }
            if (is_wp_error($response)) {
                $wpdb->query('ROLLBACK');
                return $response;
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException();
            }
        } catch (RuntimeException $error) {
            $wpdb->query('ROLLBACK');
            return $this->write_error();
        }

        if ($response->get_status() === 201) {
            $data = $response->get_data();
            if ($operation === 'results') {
                $this->forum->notifications->notify_about_new_post($data['post_id']);
            } elseif ($data['status'] === 'published') {
                $this->forum->notifications->notify_about_new_topic($data['topic_id']);
            } else {
                $this->forum->approval->notify_about_new_unapproved_topic($data['topic_id']);
            }
        }
        return $response;
    }

    private function write_error() {
        return new WP_Error('forum_tournament_write', 'The forum could not save the tournament post. Try again.', array('status' => 503));
    }

    private function save_post($record, $claims, $operation, $author, $destination, $text) {
        global $wpdb;
        $content = $this->forum->content;
        $topic_id = (int) $record->topic_id;
        $post_id = (int) $record->results_post_id;
        $status = 200;
        if ($operation === 'announcement') {
            if (!$topic_id) {
                if (!$this->can_publish($author, $destination, $destination)) {
                    return $this->destination_error();
                }
                $title = sanitize_text_field($claims['title']);
                $body = '<p>'.esc_html($title).'</p><p><a href="'.esc_url($claims['tab_url']).'">Open MIT-Tab</a></p>';
                $previous_user = get_current_user_id();
                wp_set_current_user($author);
                try {
                    $created = $content->insert_topic((int) $destination->id, $title, $body, $author);
                } finally {
                    wp_set_current_user($previous_user);
                }
                $topic_id = (int) $created->topic_id;
                $post_id = (int) $created->post_id;
                if (!$post_id || !$topic_id || !$content->get_topic($topic_id)) {
                    throw new RuntimeException();
                }
                $this->update_record($record, array('topic_id' => $topic_id));
                $status = 201;
            } else {
                $post_id = (int) ($content->get_first_post($topic_id)->id ?? 0);
            }
        } elseif (!$post_id) {
            if (!$topic_id && $claims['mode'] === 'existing') {
                $topic_id = $this->topic_id_from_url($claims['topic_url']);
            }
            $topic = $topic_id ? $content->get_topic($topic_id) : null;
            $forum = $topic ? $content->get_forum($topic->parent_id) : null;
            if (!$topic) {
                return new WP_Error('forum_tournament_topic_missing', 'The tournament topic is unavailable.', array('status' => 409));
            }
            if (!$topic->approved || $topic->closed || !$this->can_publish($author, $forum, $destination)) {
                return $this->destination_error();
            }
            $body = '<p>'.nl2br(esc_html(trim($text)), false).'</p>';
            if ($record->tab_cards_url) {
                $body .= $this->tab_cards_html($record->tab_cards_url);
            }
            $post_id = (int) $content->insert_post($topic_id, (int) $forum->id, $body, $author);
            if (!$post_id) {
                throw new RuntimeException();
            }
            $this->update_record($record, array('topic_id' => $topic_id, 'results_post_id' => $post_id));
            $status = 201;
        }

        $post = $post_id ? $content->get_post($post_id) : null;
        $topic = $topic_id ? $content->get_topic($topic_id) : null;
        if (!$post || !$topic || (int) $post->parent_id !== $topic_id) {
            return new WP_Error('forum_tournament_post_missing', 'The automated post was removed.', array('status' => 410));
        }
        $approved = (bool) $topic->approved;
        return new WP_REST_Response(array(
            'post_id' => $post_id,
            'topic_id' => $topic_id,
            'status' => $approved ? 'published' : 'pending_approval',
            'url' => $approved ? html_entity_decode($this->forum->rewrite->get_post_link($post_id, $topic_id)) : null,
        ), $status);
    }

    private function can_publish($author, $forum, $destination) {
        return $forum && $forum->parent_id === $destination->parent_id
            && $forum->forum_status !== 'closed' && $forum->forum_status !== 'private'
            && $this->forum->permissions->can_access_category($author, $forum->parent_id)
            && AsgarosForumUserGroups::canUserAccessForumCategory($author, $forum->parent_id)
            && apply_filters('asgarosforum_filter_check_access', true, $forum->parent_id);
    }

    private function destination_error() {
        return new WP_Error('forum_tournament_destination', 'The bot cannot post to this forum topic.', array('status' => 403));
    }

    private function update_record($record, $values) {
        global $wpdb;
        if ($wpdb->update($this->forum->tables->tournament_posts, $values, array('publication_id' => $record->publication_id)) === false) {
            throw new RuntimeException();
        }
    }

    private function save_tab_cards($record, $url, $author) {
        global $wpdb;
        if ($record->tab_cards_url === $url) {
            return new WP_REST_Response(array('status' => 'saved'));
        }
        if ($record->tab_cards_url) {
            return new WP_Error('forum_tab_cards_conflict', 'This tournament is already linked to a results page.', array('status' => 409));
        }
        if ($record->results_post_id) {
            $post = $this->forum->content->get_post((int) $record->results_post_id);
            if ($post && (int) $post->author_id === $author && (int) $post->parent_id === (int) $record->topic_id) {
                if ($wpdb->update($this->forum->tables->posts, array(
                    'text' => $post->text.$this->tab_cards_html($url),
                    'date_edit' => $this->forum->current_time(),
                    'author_edit' => $author,
                ), array('id' => $post->id, 'text' => $post->text, 'author_id' => $author)) !== 1) {
                    throw new RuntimeException();
                }
            }
        }
        $this->update_record($record, array('tab_cards_url' => $url));
        return new WP_REST_Response(array('status' => 'saved'));
    }

    private function tab_cards_html($url) {
        return '<p><a href="'.esc_url($url).'">Tab cards on APDA Results</a></p>';
    }

    private function valid_results_url($url) {
        $parts = wp_parse_url($url);
        $host = $this->config('APDA_TOURNAMENT_RESULTS_HOST') ?: 'results.apda.online';
        return $parts && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === $host
            && !isset($parts['port']) && !isset($parts['user'])
            && !isset($parts['pass']) && !isset($parts['query']) && ($parts['fragment'] ?? '') === 'tab-cards'
            && preg_match('#\A/core/tournaments/[1-9][0-9]{0,18}\z#', $parts['path'] ?? '');
    }

    private function topic_id_from_url($url) {
        global $wpdb;
        $parts = wp_parse_url($url);
        $base = wp_parse_url($this->forum->rewrite->get_link('topic', false, false, '', false));
        if (!$parts || !$base || ($parts['host'] ?? null) !== ($base['host'] ?? null)
            || ($parts['port'] ?? null) !== ($base['port'] ?? null)) {
            return 0;
        }
        $path = untrailingslashit($parts['path'] ?? '');
        $prefix = untrailingslashit($base['path'] ?? '');
        if ($this->forum->rewrite->use_permalinks && preg_match('#\A'.preg_quote($prefix, '#').'/([^/]+)(?:/.*)?\z#', $path, $match)) {
            return ctype_digit($match[1]) ? (int) $match[1] : (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->forum->tables->topics} WHERE slug = %s", sanitize_title(rawurldecode($match[1]))
            ));
        }
        parse_str($parts['query'] ?? '', $args);
        parse_str($base['query'] ?? '', $base_args);
        return isset($base_args['view']) && $path === $prefix && array_intersect_assoc($args, $base_args) === $base_args
            && isset($args['id']) && is_string($args['id']) && ctype_digit($args['id']) ? (int) $args['id'] : 0;
    }
}
