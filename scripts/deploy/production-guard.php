<?php
// Installed outside release artifacts as a must-use plugin.
// Do not let the normal page bootstrap race a deployment or downgrade this fork.
add_filter('auto_update_plugin', function($update, $item) {
    return isset($item->plugin) && $item->plugin === 'asgaros-forum/asgaros-forum.php' ? false : $update;
}, PHP_INT_MAX, 2);
if (defined('WP_CLI') && WP_CLI) return;
if (file_exists('/var/lib/apda-forum-deploy/maintenance') && !in_array($_SERVER['REMOTE_ADDR'] ?? '', array('127.0.0.1', '::1'), true)) {
    if (!headers_sent()) { http_response_code(503); header('Retry-After: 60'); }
    exit('The forum is briefly offline for a deployment. Please retry shortly.');
}
// Invalidate plugin CSS/JS URLs on every successful GitHub deployment, even when
// a code-only commit does not change the upstream plugin version string.
function apda_forum_deployment_asset_version() {
    global $asgarosforum;
    $path = '/var/lib/apda-forum-deploy/asset-version';
    if (isset($asgarosforum) && is_readable($path)) {
        $commit = trim(file_get_contents($path));
        if (preg_match('/^[0-9a-f]{40}$/', $commit)) {
            $asgarosforum->version .= '-'.substr($commit, 0, 12);
        }
    }
}
add_action('wp_enqueue_scripts', 'apda_forum_deployment_asset_version', 1);
add_action('admin_enqueue_scripts', 'apda_forum_deployment_asset_version', 1);
