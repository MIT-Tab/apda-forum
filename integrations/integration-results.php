<?php
// Linked results profiles are resolved server-side, never by matching names or emails.
if (!defined('ABSPATH')) {
    exit;
}

class AsgarosForumResultsProfiles {
    const ENDPOINT = 'https://results.apda.online/api/private/forum-profiles/';

    public function __construct() {
        add_filter('asgarosforum_filter_profile_row', array($this, 'add_profile_row'), 10, 2);
    }

    public function add_profile_row($rows, $user) {
        if (!defined('APDA_RESULTS_PROFILE_TOKEN') || !APDA_RESULTS_PROFILE_TOKEN) {
            return $rows;
        }
        $key = 'apda_results_'.hash('sha256', APDA_RESULTS_PROFILE_TOKEN.':'.$user->ID);
        $links = get_transient($key);
        if ($links === false) {
            $links = array();
            $ttl = 30;
            $response = wp_safe_remote_post(self::ENDPOINT, array(
                'headers' => array('Authorization' => 'Bearer '.APDA_RESULTS_PROFILE_TOKEN, 'Content-Type' => 'application/json'),
                'body' => wp_json_encode(array('wordpress_user_id' => (int) $user->ID)),
                'timeout' => 2,
                'redirection' => 0,
                'limit_response_size' => 262144,
            ));
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                if (is_array($data) && isset($data['debaters']) && is_array($data['debaters'])) {
                    foreach ($data['debaters'] as $debater) {
                        if (!is_array($debater) || !isset($debater['id'], $debater['name']) || !is_int($debater['id']) || $debater['id'] <= 0 || !is_string($debater['name'])) {
                            continue;
                        }
                        $label = $debater['name'];
                        if (isset($debater['school']) && is_string($debater['school']) && $debater['school'] !== '') {
                            $label .= ' ('.$debater['school'].')';
                        }
                        $links[$debater['id']] = '<a href="'.esc_url('https://results.apda.online/core/debaters/'.$debater['id']).'">'.esc_html($label).'</a>';
                    }
                    $ttl = $links ? 300 : 60;
                }
            }
            set_transient($key, $links, $ttl);
        }
        if ($links) {
            $rows['results_profiles'] = array('title' => __('Results profiles:', 'asgaros-forum'), 'value' => array_values($links));
        }
        return $rows;
    }
}

new AsgarosForumResultsProfiles();
