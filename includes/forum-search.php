<?php

if (!defined('ABSPATH')) {
    exit;
}

class AsgarosForumSearch {
    private $asgarosforum;
    private $filters = array(
        'keywords' => '',
        'search_category' => '',
        'search_forum' => '',
        'search_author' => '',
        'search_from' => '',
        'search_to' => '',
        'search_in' => '',
        'search_sort' => '',
    );

    public function __construct($asgarosForumObject) {
        $this->asgarosforum = $asgarosForumObject;

        add_action('init', array($this, 'initialize'));
        add_action('asgarosforum_breadcrumbs_search', array($this, 'add_breadcrumbs'));
    }

    public function initialize() {
        foreach (array_keys($this->filters) as $field) {
            $value = isset($_GET[$field]) && is_string($_GET[$field]) ? wp_unslash($_GET[$field]) : '';
            $this->filters[$field] = sanitize_text_field($value);
        }
    }

    public function get_keywords() {
        return $this->filters['keywords'];
    }

    public function get_query_args() {
        $filters = array_filter($this->filters, function ($value) {
            return $value !== '';
        });
        return array_map('rawurlencode', $filters);
    }

    public function add_breadcrumbs() {
        $element_link  = $this->asgarosforum->get_link('current');
        $element_title = __('Search', 'asgaros-forum');
        $this->asgarosforum->breadcrumbs->add_breadcrumb($element_link, $element_title);
    }

    public function show_search_input() {
        if (!$this->asgarosforum->options['enable_search']) {
            return;
        }

        $url = $this->asgarosforum->get_link('search', false, false, '', false);
        echo '<div id="forum-search">';
        echo '<form method="get" action="'.esc_url($url).'">';
        $this->render_routing_fields($url);
        echo '<button class="search-icon" type="submit" aria-label="'.esc_attr__('Search forum', 'asgaros-forum').'"><span class="fas fa-search" aria-hidden="true"></span></button>';
        echo '<input name="keywords" type="search" maxlength="200" aria-label="'.esc_attr__('Keywords', 'asgaros-forum').'" placeholder="'.esc_attr__('Search …', 'asgaros-forum').'" value="'.esc_attr($this->get_keywords()).'">';
        echo '</form>';
        $filters_url = $this->asgarosforum->current_view === 'search' ? '#forum-search-filters' : $url;
        echo '<a href="'.esc_url($filters_url).'">'.esc_html__('Filters', 'asgaros-forum').'</a>';
        echo '</div>';
    }

    private function render_routing_fields($url) {
        // Browsers replace the action's query string when submitting a GET form.
        wp_parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        foreach (array('view', 'p', 'page_id') as $field) {
            if (isset($query[$field])) {
                echo '<input name="'.esc_attr($field).'" type="hidden" value="'.esc_attr($query[$field]).'">';
            }
        }
    }

    private function render_filters($categories, $url) {
        $filters = $this->filters;
        echo '<form id="forum-search-filters" class="forum-search-filters content-container" aria-label="'.esc_attr__('Search filters', 'asgaros-forum').'" method="get" action="'.esc_url($url).'">';
        $this->render_routing_fields($url);

        echo '<label class="forum-search-wide">'.esc_html__('Keywords', 'asgaros-forum');
        echo '<input type="search" name="keywords" maxlength="200" value="'.esc_attr($filters['keywords']).'"></label>';
        echo '<label class="forum-search-wide">'.esc_html__('Category', 'asgaros-forum').'<select name="search_category">';
        echo '<option value="">'.esc_html__('All accessible categories', 'asgaros-forum').'</option>';
        foreach ($categories as $category) {
            echo '<option value="'.esc_attr($category->term_id).'"'.selected($filters['search_category'], $category->term_id, false).'>'.esc_html($category->name).'</option>';
        }
        echo '</select></label>';
        echo '<label class="forum-search-wide">'.esc_html__('Forum', 'asgaros-forum').'<select name="search_forum">';
        echo '<option value="">'.esc_html__('All accessible forums', 'asgaros-forum').'</option>';
        foreach ($this->get_searchable_forums($categories) as $forum) {
            $label = $forum->parent_name ? $forum->parent_name.' / '.$forum->name : $forum->name;
            echo '<option value="'.esc_attr($forum->id).'"'.selected($filters['search_forum'], $forum->id, false).'>'.esc_html($label).'</option>';
        }
        echo '</select></label>';
        echo '<label class="forum-search-wide">'.esc_html__('Posted by', 'asgaros-forum');
        echo '<input type="text" name="search_author" maxlength="250" placeholder="'.esc_attr__('Exact display name', 'asgaros-forum').'" value="'.esc_attr($filters['search_author']).'"></label>';
        echo '<label>'.esc_html__('From date', 'asgaros-forum');
        echo '<input type="date" name="search_from" value="'.esc_attr($filters['search_from']).'"></label>';
        echo '<label>'.esc_html__('Through date', 'asgaros-forum');
        echo '<input type="date" name="search_to" value="'.esc_attr($filters['search_to']).'"></label>';
        echo '<label>'.esc_html__('Search in', 'asgaros-forum').'<select name="search_in">';
        echo '<option value="">'.esc_html__('Titles and posts', 'asgaros-forum').'</option>';
        echo '<option value="titles"'.selected($filters['search_in'], 'titles', false).'>'.esc_html__('Topic titles only', 'asgaros-forum').'</option>';
        echo '</select></label>';
        echo '<label>'.esc_html__('Sort by', 'asgaros-forum').'<select name="search_sort">';
        echo '<option value="">'.esc_html__('Newest first', 'asgaros-forum').'</option>';
        echo '<option value="oldest"'.selected($filters['search_sort'], 'oldest', false).'>'.esc_html__('Oldest first', 'asgaros-forum').'</option>';
        echo '</select></label>';
        echo '<div class="forum-search-actions">';
        echo '<button class="button button-normal" type="submit">'.esc_html__('Search', 'asgaros-forum').'</button>';
        echo '<a href="'.esc_url($url).'">'.esc_html__('Clear filters', 'asgaros-forum').'</a>';
        echo '</div></form>';
    }

    public function show_search_results() {
        $categories = $this->get_searchable_categories();
        $url = $this->asgarosforum->get_link('search', false, false, '', false);
        $this->render_filters($categories, $url);

        if (!is_user_logged_in() && ($this->asgarosforum->options['require_login'] || $this->asgarosforum->options['require_login_posts'])) {
            $this->asgarosforum->render_notice(esc_html__('Sign in to search forum posts.', 'asgaros-forum'));
            return;
        }

        if (!$this->is_valid_date_range()) {
            $this->asgarosforum->render_notice(esc_html__('Enter a valid date range. The through date must be on or after the from date.', 'asgaros-forum'));
            return;
        }

        $results = $this->get_search_results($categories);
        if ($results === null) {
            return;
        }

        $count = $results['count'];
        $pagination = $this->asgarosforum->pagination->renderPagination('search', false, $count);
        echo '<div class="title-element">'.sprintf(esc_html(_n('%s matching post', '%s matching posts', $count, 'asgaros-forum')), esc_html(number_format_i18n($count))).'</div>';
        echo '<div class="content-container">';
        foreach ($results['posts'] as $post) {
            $link = $this->asgarosforum->rewrite->get_post_link((int) $post->id, (int) $post->topic_id);
            $excerpt = wp_trim_words(strip_shortcodes($post->text ?? ''), 32);
            echo '<article class="content-element forum-search-result">';
            echo '<a class="forum-search-result-title" href="'.esc_url($link).'">'.esc_html(stripslashes($post->topic_name)).'</a>';
            echo '<small>'.esc_html($this->asgarosforum->get_plain_username($post->author_id)).' &middot; ';
            echo esc_html($this->asgarosforum->format_date($post->date)).' &middot; '.esc_html($post->forum_name).'</small>';
            if ($excerpt !== '') {
                echo '<p>'.esc_html($excerpt).'</p>';
            }
            echo '</article>';
        }
        if (!$results['posts']) {
            $message = $count ? __('No matching posts on this page.', 'asgaros-forum') : __('No posts match these filters.', 'asgaros-forum');
            $this->asgarosforum->render_notice(esc_html($message));
        }
        echo '</div>';
        if ($pagination) {
            echo '<div class="pages-and-menu">'.$pagination.'<div class="clear"></div></div>';
        }
    }

    private function is_valid_date_range() {
        $from = $this->filters['search_from'];
        $to = $this->filters['search_to'];
        return $this->is_valid_date($from) && $this->is_valid_date($to) && ($from === '' || $to === '' || $from <= $to);
    }

    private function is_valid_date($date) {
        return $date === '' || (preg_match('/^([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})$/D', $date, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]));
    }

    private function get_searchable_categories() {
        return array_filter($this->asgarosforum->content->get_categories(), function ($category) {
            return apply_filters('asgarosforum_filter_check_access', true, $category->term_id);
        });
    }

    private function get_searchable_forums($categories) {
        if (!$categories) {
            return array();
        }

        $db = $this->asgarosforum->db;
        $table = $this->asgarosforum->tables->forums;
        $category_ids = implode(',', array_map('intval', wp_list_pluck($categories, 'term_id')));
        $query = "SELECT f.id, f.name, f.parent_forum, parent.name AS parent_name FROM {$table} AS f
            LEFT JOIN {$table} AS parent ON parent.id = f.parent_forum AND parent.parent_id IN ($category_ids)
            WHERE f.parent_id IN ($category_ids)";
        $shortcode_forum = $this->asgarosforum->shortcode->search_forum_id;
        if ($shortcode_forum) {
            $query .= $db->prepare(' AND (f.id = %d OR f.parent_forum = %d)', $shortcode_forum, $shortcode_forum);
        }

        return $db->get_results($query." ORDER BY f.parent_id, COALESCE(parent.sort, f.sort), COALESCE(parent.id, f.id), f.parent_forum, f.sort, f.id");
    }

    private function keyword_condition($column, $words) {
        $db = $this->asgarosforum->db;
        if (defined('DB_ENGINE') && DB_ENGINE === 'sqlite') {
            // SQLite (including local Playground) has no MySQL FULLTEXT indexes.
            $conditions = array();
            foreach ($words as $word) {
                $conditions[] = $db->prepare("$column LIKE %s", '%'.$db->esc_like($word).'%');
            }
            return '('.implode(' OR ', $conditions).')';
        }

        return $db->prepare("MATCH ($column) AGAINST (%s IN BOOLEAN MODE)", implode('* ', $words).'*');
    }

    public function get_search_results($categories = null) {
        $filters = $this->filters;
        if ($filters['keywords'] === '' && $filters['search_category'] === '' && $filters['search_forum'] === '' && $filters['search_author'] === ''
            && $filters['search_from'] === '' && $filters['search_to'] === '' && $filters['search_in'] !== 'titles') {
            return null;
        }

        $empty = array('count' => 0, 'posts' => array());
        if (!$this->asgarosforum->options['enable_search']
            || (!is_user_logged_in() && ($this->asgarosforum->options['require_login'] || $this->asgarosforum->options['require_login_posts']))
            || !$this->is_valid_date_range()) {
            return $empty;
        }

        $db = $this->asgarosforum->db;
        $tables = $this->asgarosforum->tables;
        $categories = $categories ?? $this->get_searchable_categories();
        $category_ids = array_map('intval', wp_list_pluck($categories, 'term_id'));
        if ($filters['search_category'] !== '') {
            $category = filter_var($filters['search_category'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
            $category_ids = in_array($category, $category_ids, true) ? array($category) : array();
        }
        if (!$category_ids) {
            return $empty;
        }

        $category_sql = implode(',', $category_ids);
        $where = array('t.approved = 1', "f.parent_id IN ($category_sql)");
        if ($filters['search_forum'] !== '') {
            $forum = filter_var($filters['search_forum'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
            if (!$forum) {
                return $empty;
            }
            $where[] = $db->prepare("(f.id = %d OR (f.parent_forum = %d AND EXISTS (
                SELECT 1 FROM {$tables->forums} AS parent WHERE parent.id = f.parent_forum
                AND parent.parent_id IN ($category_sql))))", $forum, $forum);
        }
        $user_id = get_current_user_id();
        if (!$this->asgarosforum->permissions->isModerator('current')) {
            $where[] = $db->prepare("(f.forum_status <> 'private' OR (%d > 0 AND t.author_id = %d))", $user_id, $user_id);
        }
        if ($filters['search_author'] !== '') {
            $where[] = $db->prepare("EXISTS (SELECT 1 FROM {$db->users} AS u WHERE u.ID = p.author_id AND u.display_name = %s)", $filters['search_author']);
        }
        if ($filters['search_from'] !== '') {
            $where[] = $db->prepare('p.date >= %s', $filters['search_from'].' 00:00:00');
        }
        if ($filters['search_to'] !== '') {
            $where[] = $db->prepare('p.date <= %s', $filters['search_to'].' 23:59:59');
        }
        $shortcode_forum = $this->asgarosforum->shortcode->search_forum_id;
        if ($shortcode_forum) {
            $where[] = $db->prepare('(f.id = %d OR f.parent_forum = %d)', $shortcode_forum, $shortcode_forum);
        }

        $titles_only = $filters['search_in'] === 'titles';
        $first_post = "(SELECT MIN(id) FROM {$tables->posts} WHERE parent_id = t.id)";
        if ($titles_only) {
            $where[] = "p.id = $first_post";
        }

        $keyword_join = '';
        if ($filters['keywords'] !== '') {
            if (strlen($filters['keywords']) > 800 || !preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}]*/u', $filters['keywords'], $matches)) {
                return $empty;
            }
            $words = array_unique($matches[0]);
            $title_match = $this->keyword_condition('t.name', $words);
            if ($titles_only) {
                $where[] = $title_match;
            } else {
                $text_match = $this->keyword_condition('text', $words);
                // Keep the two FULLTEXT lookups separate so each can use its own index.
                $keyword_join = "JOIN (
                    SELECT id FROM {$tables->posts} WHERE {$text_match}
                    UNION
                    SELECT $first_post AS id FROM {$tables->topics} AS t WHERE {$title_match}
                ) AS matches ON matches.id = p.id";
            }
        }

        $query = "FROM {$tables->posts} AS p
            JOIN {$tables->topics} AS t ON t.id = p.parent_id
            JOIN {$tables->forums} AS f ON f.id = t.parent_id
            $keyword_join
            WHERE ".implode(' AND ', $where);
        $count = (int) $db->get_var("SELECT COUNT(*) $query");
        if (!$count) {
            return $empty;
        }

        $direction = $filters['search_sort'] === 'oldest' ? 'ASC' : 'DESC';
        $page_size = (int) $this->asgarosforum->options['topics_per_page'];
        $offset = $this->asgarosforum->current_page * $page_size;
        $limit = $db->prepare('LIMIT %d OFFSET %d', $page_size, $offset);
        $posts = $db->get_results("SELECT p.id, p.author_id, p.date, p.text, t.id AS topic_id, t.name AS topic_name, f.name AS forum_name
            $query ORDER BY p.date $direction, p.id $direction $limit");

        return array('count' => $count, 'posts' => $posts);
    }
}
