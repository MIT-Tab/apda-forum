<?php
// Isolated rehearsal only: no mail, external HTTP, or scheduled WP jobs.
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
add_filter('pre_http_request', function() { return new WP_Error('apda_staging', 'External requests disabled in staging'); }, PHP_INT_MAX);
