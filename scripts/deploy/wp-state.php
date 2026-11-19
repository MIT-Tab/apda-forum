<?php
// Invoked by the root-owned deployment helper through WP-CLI as www-data.
// Never print database credentials or row contents.
global $wpdb, $asgarosforum;
function apda_hash_rows($table, $where = '') {
    global $wpdb;
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) throw new Exception('Invalid table name');
    $indexes = $wpdb->get_results("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'", ARRAY_A);
    $columns = array_map(function($index) { return '`'.$index['Column_name'].'`'; }, $indexes);
    if (!$columns) throw new Exception('Table has no primary key');
    $order = implode(',', $columns);
    $keys = array_column($indexes, 'Column_name');
    $hash = hash_init('sha256');
    $count = 0;
    $last = null;
    do {
        $filter = $where;
        if ($last !== null) {
            // Keyset pagination avoids rescanning large users/posts tables.
            $placeholders = implode(',', array_fill(0, count($keys), '%s'));
            $condition = '('.$order.') > ('.$placeholders.')';
            $filter .= ($filter ? ' AND ' : 'WHERE ').$wpdb->prepare($condition, $last);
        }
        $rows = $wpdb->get_results("SELECT * FROM `$table` $filter ORDER BY $order LIMIT 500", ARRAY_A);
        if ($wpdb->last_error) throw new Exception('Snapshot query failed');
        foreach ($rows as $row) hash_update($hash, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        if ($rows) {
            $row = end($rows);
            $last = array_map(function($key) use ($row) { return $row[$key]; }, $keys);
        }
        $count += count($rows);
    } while (count($rows) === 500);
    return array('rows' => $count, 'sha256' => hash_final($hash));
}
$mode = getenv('APDA_DEPLOY_ACTION');
if (!in_array('asgaros-forum/asgaros-forum.php', get_option('active_plugins', array()), true)) throw new Exception('The existing forum plugin is not active');
if ($mode === 'migrate' || $mode === 'smoke') {
    require_once WP_PLUGIN_DIR.'/asgaros-forum/asgaros-forum.php';
    // The helper loads the plugin after WP-CLI bootstrap, so register its taxonomies explicitly.
    AsgarosForumUserGroups::initializeTaxonomy();
    AsgarosForumContent::initialize_taxonomy();
    $database = new AsgarosForumDatabase();
    $database->buildDatabase();
    if ((int)get_option('asgarosforum_db_version') !== 65) throw new Exception('Expected schema 65');
    if ($mode === 'smoke') {
        // Reuse an identity only inside the clone; never create/delete accounts or
        // risk reusing a deleted historical author's numeric ID.
        $admins = get_users(array('role' => 'administrator', 'fields' => 'ID', 'number' => 1));
        if (!$admins) throw new Exception('No staging administrator available for smoke test');
        $user = (int)$admins[0];
        wp_set_current_user($user);
        $asgarosforum->permissions->initialize();
        $asgarosforum->options['enable_reactions'] = true;
        $asgarosforum->reactions->initialize();
        $post = $wpdb->get_row($wpdb->prepare("SELECT p.* FROM {$wpdb->prefix}forum_posts p JOIN {$wpdb->prefix}forum_topics t ON t.id=p.parent_id WHERE t.approved=1 AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}forum_reactions r WHERE r.post_id=p.id AND r.user_id=%d) ORDER BY p.id LIMIT 1", $user));
        if (!$post) throw new Exception('No unused post/user pair available for smoke test');
        foreach (array('heart', 'up') as $emoji) {
            if (!$asgarosforum->reactions->reaction_change($post->id, $user, $emoji, $post->author_id)) throw new Exception('Cannot add reaction');
        }
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}forum_reactions WHERE post_id=%d AND user_id=%d", $post->id, $user));
        if ((int)$count !== 2) throw new Exception('Independent reactions failed');
        foreach (array('heart', 'up') as $emoji) {
            if (!$asgarosforum->reactions->reaction_change($post->id, $user, $emoji, $post->author_id)) throw new Exception('Cannot remove reaction');
        }
    }
}
$tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix.'forum_').'%'));
// Check only privilege names; do not expose database/user names or credentials.
$database_name = $wpdb->get_var('SELECT DATABASE()');
$grant_text = '';
foreach ($wpdb->get_col('SHOW GRANTS') as $grant) {
    if (preg_match('/^GRANT (.*?) ON (.*?) TO /', $grant, $parts)) {
        $scope = str_replace(array('`', '\\'), '', $parts[2]);
        if ($scope === '*.*' || $scope === $database_name.'.*') $grant_text .= ' '.$parts[1];
    }
}
$all_privileges = strpos($grant_text, 'ALL PRIVILEGES') !== false;
foreach (array('ALTER','CREATE','DROP','SELECT','INSERT','UPDATE','DELETE','LOCK TABLES') as $privilege) {
    if (!$all_privileges && strpos($grant_text, $privilege) === false) throw new Exception('Deployment database privilege not verified: '.$privilege);
}
require_once ABSPATH.'wp-admin/includes/plugin.php';
$plugin_metadata = get_plugin_data(WP_PLUGIN_DIR.'/asgaros-forum/asgaros-forum.php', false, false);
$state = array('version' => $plugin_metadata['Version'], 'schema' => (int)get_option('asgarosforum_db_version'), 'multisite' => is_multisite(), 'prefix' => $wpdb->prefix, 'tables' => array());
foreach ($tables as $table) $state['tables'][$table] = apda_hash_rows($table);
foreach (array($wpdb->users, $wpdb->posts, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta) as $table) {
    $state['tables'][$table] = apda_hash_rows($table);
}
$state['tables']['forum_usermeta'] = apda_hash_rows($wpdb->usermeta, "WHERE meta_key LIKE 'asgarosforum\\_%'");
$state['options'] = array();
foreach (array('asgarosforum_options', 'asgarosforum_appearance') as $name) $state['options'][$name] = hash('sha256', serialize(get_option($name)));
$keys = $wpdb->get_results("SHOW KEYS FROM {$wpdb->prefix}forum_reactions WHERE Key_name='PRIMARY'", ARRAY_A);
$state['reaction_key'] = array_column($keys, 'Column_name');
if (is_multisite()) throw new Exception('Multisite needs a separate deployment procedure');
echo 'APDA_STATE:'.json_encode($state)."\n";
