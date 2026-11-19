<?php
require_once '/wordpress/wp-load.php';
global $wpdb, $asgarosforum;
echo 'Actual runtime: WordPress '.get_bloginfo('version').' / PHP '.PHP_VERSION."\n";
if (get_bloginfo('version') !== '6.8.3') throw new Exception('Unexpected WordPress version');
if (!$asgarosforum || !$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}forum_posts")) {
    throw new Exception('Plugin activation or sample forum initialization failed');
}
echo "Plugin activation and sample post verified\n";

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/wordpress/wp-content/plugins/asgaros-forum'));
foreach ($iterator as $file) {
    if ($file->getExtension() === 'php' && strpos($file->getPathname(), '/node_modules/') === false) {
        token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
    }
}
echo "All plugin PHP files parse successfully\\n";

echo "FORUM_TESTS_PASSED\n";
