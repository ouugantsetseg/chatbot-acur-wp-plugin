<?php
if (!defined('WP_UNINSTALL_PLUGIN')) exit;
// Remove options & table (optional). Comment out to keep data.
// delete_option('wpcbmv_min_score');
// global $wpdb; $t = $wpdb->prefix.'wpcbmv_kb';
// $wpdb->query("DROP TABLE IF EXISTS $t");