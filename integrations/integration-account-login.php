<?php
if (!defined('ABSPATH')) {
    exit;
}

// LoginWP otherwise replaces the OAuth continuation with its homepage rule.
class AsgarosForumAccountLogin {
    public function __construct() {
        add_filter('login_url', array($this, 'preserve_authorization_in_login_url'), PHP_INT_MAX, 2);
        add_filter('login_redirect', array($this, 'preserve_results_authorization'), PHP_INT_MAX, 3);
        // WPS Hide Login uses a separate redirect when the WordPress session already exists.
        add_filter('whl_logged_in_redirect', array($this, 'preserve_results_authorization'), PHP_INT_MAX, 3);
        add_filter('auth_cookie_expiration', array($this, 'remembered_login_duration'), 10, 3);
    }

    public function preserve_authorization_in_login_url($login_url, $requested) {
        $authorization = $this->results_authorization_url($requested);
        if ($authorization === null || $login_url === '#') {
            return $login_url;
        }
        // Encode the entire continuation as one argument on the hidden login URL.
        return add_query_arg('redirect_to', rawurlencode($authorization), $login_url);
    }

    public function preserve_results_authorization($redirect, $requested, $user) {
        if (!($user instanceof WP_User) || !$user->exists()) {
            return $redirect;
        }
        $authorization = $this->results_authorization_url($requested);
        return $authorization === null ? $redirect : wp_validate_redirect($authorization, $redirect);
    }

    private function results_authorization_url($requested) {
        if (!is_string($requested)) {
            return null;
        }
        $url = wp_parse_url($requested);
        if (!isset($url['scheme'], $url['host'], $url['path'], $url['query'])
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) {
            return null;
        }
        parse_str($url['query'], $query);
        if (($query['response_type'] ?? '') !== 'code'
            || !is_string($query['state'] ?? null) || $query['state'] === ''
            || !is_string($query['client_id'] ?? null) || $query['client_id'] === ''
            || ($query['redirect_uri'] ?? '') !== 'https://results.apda.online/accounts/apdaonline/login/callback/') {
            return null;
        }
        $authorization = home_url('/oauth/authorize');
        // WP OAuth Server builds the continuation with site_url() by default.
        foreach (array($authorization, site_url('/oauth/authorize')) as $candidate) {
            $endpoint = wp_parse_url($candidate);
            if (isset($endpoint['scheme'], $endpoint['host'], $endpoint['path'])
                && $url['scheme'] === $endpoint['scheme']
                && strcasecmp($url['host'], $endpoint['host']) === 0
                && ($url['port'] ?? null) === ($endpoint['port'] ?? null)
                && rtrim($url['path'], '/') === $endpoint['path']) {
                return $authorization.'?'.$url['query'];
            }
        }
        return null;
    }

    public function remembered_login_duration($duration, $user_id, $remember) {
        // Preserve the browser-only behavior when Remember Me is not selected.
        return $remember ? 30 * DAY_IN_SECONDS : $duration;
    }
}

new AsgarosForumAccountLogin();
