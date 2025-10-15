<?php
if (!defined('ABSPATH')) exit;

class ACURCB_Settings {
  const OPT = 'acurcb_settings';

  static function get($key, $default = '') {
    $opt = get_option(self::OPT, []);
    return isset($opt[$key]) ? $opt[$key] : $default;
  }

  static function render() {
    if (!current_user_can('manage_options')) return;

    // Handle saving settings
    if ($_SERVER['REQUEST_METHOD']==='POST' && check_admin_referer('acurcb_settings')) {
        // Save OpenAI settings
        $enabled = isset($_POST['openai_enabled']) ? 1 : 0;
        $key = sanitize_text_field($_POST['openai_key'] ?? '');
        update_option(self::OPT, ['openai_enabled'=>$enabled, 'openai_key'=>$key]);

        echo '<div class="updated"><p>Settings updated.</p></div>';
    }
    ?>
    <div class="wrap">
      <h1>ACUR Chatbot — Settings</h1>

      <div class="notice notice-info">
        <p><strong>Local Matching Enabled:</strong> This chatbot now uses local PHP-based question matching instead of external APIs. No additional configuration is required.</p>
      </div>

      <h2>OpenAI API Settings</h2>
      <form method="post">
        <?php wp_nonce_field('acurcb_settings'); ?>
        <table class="form-table">
          <tr>
            <th>Enable OpenAI API</th>
            <td>
              <input type="checkbox" name="openai_enabled" value="1" <?php checked(self::get('openai_enabled'),1); ?> />
            </td>
          </tr>
          <tr>
            <th>OpenAI API Key</th>
            <td>
              <input type="text" name="openai_key" value="<?php echo esc_attr(self::get('openai_key')); ?>" size="50" />
            </td>
          </tr>
        </table>
        <p><input type="submit" class="button-primary" value="Save Settings" /></p>
      </form>

      <h2>How It Works</h2>
      <p>The chatbot uses advanced text similarity algorithms to match user questions with your FAQ entries:</p>
      <ul>
        <li><strong>Keyword Matching:</strong> Identifies common words between user questions and FAQ content</li>
        <li><strong>Text Similarity:</strong> Uses Jaccard similarity and Levenshtein distance for fuzzy matching</li>
        <li><strong>Tag-based Matching:</strong> Gives higher priority to matches found in FAQ tags</li>
        <li><strong>Smart Scoring:</strong> Combines multiple matching methods for best results</li>
      </ul>

      <h2>Performance Statistics</h2>
      <?php
      global $wpdb;
      $faq_count = $wpdb->get_var("SELECT COUNT(*) FROM faqs");
      $feedback_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}acur_feedback WHERE helpful = 1");
      $total_feedback = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}acur_feedback");
      $escalation_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}acur_escalations");
      ?>
      <table class="form-table">
        <tr><th>Total FAQ Entries</th><td><?php echo intval($faq_count); ?></td></tr>
        <tr><th>Positive Feedback</th><td><?php echo intval($feedback_count); ?> out of <?php echo intval($total_feedback); ?> responses</td></tr>
        <tr><th>Escalation Requests</th><td><?php echo intval($escalation_count); ?></td></tr>
      </table>

      <form method="post">
        <?php wp_nonce_field('acurcb_settings'); ?>
        <p><em>Future settings for local matching configuration can be added here.</em></p>
      </form>
    </div>
    <?php
  }
}
