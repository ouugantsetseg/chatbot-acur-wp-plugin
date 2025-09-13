<?php
/**
 * Plugin Name: ACUR Chatbot
 * Description: Floating chatbot widget + FAQ admin + proxy to Python matcher API.
 * Version:     0.1.0
 * Author:      Swin students Team
 */

if (!defined('ABSPATH')) exit;

define('ACURCB_VER', '0.1.0');
define('ACURCB_DIR', plugin_dir_path(__FILE__));
define('ACURCB_URL', plugin_dir_url(__FILE__));

require_once ACURCB_DIR . 'includes/class-acur-settings.php';
require_once ACURCB_DIR . 'includes/class-acur-admin.php';
require_once ACURCB_DIR . 'includes/class-acur-rest.php';

register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'faqs'; // 
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS `faqs` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `question` TEXT NOT NULL,
      `answer`   TEXT NOT NULL,
      `tags`     JSON NULL,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});

add_action('admin_menu', function () {
    add_menu_page('Chatbot', 'Chatbot', 'manage_options', 'acur-chatbot', ['ACURCB_Admin','render_faqs'], 'dashicons-format-chat', 45);
    add_submenu_page('acur-chatbot','Settings','Settings','manage_options','acur-chatbot-settings',['ACURCB_Settings','render']);
});

add_action('rest_api_init', ['ACURCB_REST','register_routes']);

add_action('wp_enqueue_scripts', function () {
    // enqueue your actual file path & handle
    wp_enqueue_style(
        'acurcb-css',
        plugins_url('assets/css/widget.css', __FILE__),
        [],
        '0.1.0'
    );

    wp_enqueue_script(
        'acurcb-widget',                                      // <-- handle used below
        plugins_url('assets/js/widget.js', __FILE__),         // <-- matches your screenshot path
        [],
        '0.1.0',
        true
    );

    // Build config
    $cfg = [
        'restBase'    => esc_url_raw( get_rest_url(null, 'acur-chatbot/v1/') ), // trailing slash
        'siteNonce'   => wp_create_nonce('wp_rest'),
        'siteUrl'     => home_url('/'),
        'widgetTitle' => 'ACUR Chatbot',
    ];

    // Attach BEFORE the script so `window.ACURCB_CFG` exists when widget.js runs
    wp_add_inline_script(
        'acurcb-widget',                                       // <-- must match the enqueue handle
        'window.ACURCB_CFG=' . wp_json_encode($cfg) . ';',
        'before'
    );
});


