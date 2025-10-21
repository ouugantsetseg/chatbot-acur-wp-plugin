<?php
/**
 * Plugin Name: ACUR Chatbot
 * Description: Floating chatbot widget with local PHP-based FAQ matching. No external APIs required.
 * Version:     0.1.0
 * Author:      Swin students Team
 */

if (!defined('ABSPATH')) exit;

define('ACURCB_VER', '0.1.0');
define('ACURCB_DIR', plugin_dir_path(__FILE__));
define('ACURCB_URL', plugin_dir_url(__FILE__));

require_once ACURCB_DIR . 'includes/class-acur-settings.php';
require_once ACURCB_DIR . 'includes/class-acur-admin.php';
require_once ACURCB_DIR . 'includes/class-acur-matcher.php';
require_once ACURCB_DIR . 'includes/class-acur-rest.php';

register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'faqs';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `question` TEXT NOT NULL,
      `answer`   TEXT NOT NULL,
      `tags`     JSON NULL,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
        // Create feedback, escalations and escalation responses tables at activation
        $fb_table = $wpdb->prefix . 'acur_feedback';
        $es_table = $wpdb->prefix . 'acur_escalations';
        $resp_table = $wpdb->prefix . 'acur_escalation_responses';

        $sql_fb = "CREATE TABLE IF NOT EXISTS $fb_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            faq_id int(11),
            helpful tinyint(1) NOT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY faq_id (faq_id)
        ) $charset;";

        $sql_es = "CREATE TABLE IF NOT EXISTS $es_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            user_query text NOT NULL,
            contact_email varchar(255) NOT NULL,
            status varchar(50) DEFAULT 'pending',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY status (status)
        ) $charset;";

        $sql_resp = "CREATE TABLE IF NOT EXISTS $resp_table (
                id int(11) NOT NULL AUTO_INCREMENT,
                escalation_id int(11) NOT NULL,
                responder varchar(255) DEFAULT 'admin',
                response_text text NOT NULL,
                email_sent tinyint(1) DEFAULT 0,
                email_sent_at datetime NULL,
                email_error text NULL,
                created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY escalation_id (escalation_id)
            ) $charset;";

        dbDelta($sql_fb);
        dbDelta($sql_es);
        dbDelta($sql_resp);
});

add_action('admin_menu', function () {
    add_menu_page('Chatbot', 'Chatbot', 'manage_options', 'acur-chatbot', ['ACURCB_Admin','render_faqs'], 'dashicons-format-chat', 45);
    add_submenu_page('acur-chatbot','Escalation Responses','Escalation Responses','manage_options','acur-chatbot-escalations',['ACURCB_Admin','render_escalations']);
    add_submenu_page('acur-chatbot','Reports','Reports','manage_options','acur-chatbot-reports',['ACURCB_Admin','render_reports']);
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
        'widgetTitle' => 'Connect with ACUR',
    ];

    // Attach BEFORE the script so `window.ACURCB_CFG` exists when widget.js runs
    wp_add_inline_script(
        'acurcb-widget',                                       // <-- must match the enqueue handle
        'window.ACURCB_CFG=' . wp_json_encode($cfg) . ';',
        'before'
    );
});


