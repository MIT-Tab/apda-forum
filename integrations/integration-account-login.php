<?php
if (!defined('ABSPATH')) {
    exit;
}

// LoginWP otherwise replaces the OAuth continuation with its homepage rule.
class AsgarosForumAccountLogin {
    public function __construct() {
        add_filter('login_redirect', array($this, 'preserve_results_authorization'), PHP_INT_MAX, 3);
        add_filter('auth_cookie_expiration', array($this, 'remembered_login_duration'), 10, 3);
    }

    public function preserve_results_authorization($redirect, $requested, $user) {
        if (!($user instanceof WP_User) || !$user->exists() || !is_string($requested)) {
            return $redirect;
        }
        $url = wp_parse_url($requested);
        $home = wp_parse_url(home_url('/'));
        if (!$url || !isset($url['scheme'], $url['host']) || $url['scheme'] !== $home['scheme'] || strtolower($url['host']) !== strtolower($home['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || ($url['port'] ?? null) !== ($home['port'] ?? null) || rtrim($url['path'] ?? '', '/') !== rtrim($home['path'] ?? '', '/').'/oauth/authorize') {
            return $redirect;
        }
        parse_str($url['query'] ?? '', $query);
        if (($query['response_type'] ?? '') !== 'code' || !is_string($query['state'] ?? null) || $query['state'] === '' || !is_string($query['client_id'] ?? null) || $query['client_id'] === '' || ($query['redirect_uri'] ?? '') !== 'https://results.apda.online/accounts/apdaonline/login/callback/') {
            return $redirect;
        }
        return wp_validate_redirect($requested, $redirect);
    }

    public function remembered_login_duration($duration, $user_id, $remember) {
        // Preserve the browser-only behavior when Remember Me is not selected.
        return $remember ? 30 * DAY_IN_SECONDS : $duration;
    }
}

new AsgarosForumAccountLogin();
