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

    $message = '';
    $message_type = 'updated';

    if ($_SERVER['REQUEST_METHOD']==='POST' && check_admin_referer('acurcb_settings')) {
      // Handle DB Migration
      if (isset($_POST['run_db_migration'])) {
        ob_start();
        include plugin_dir_path(__FILE__) . 'db_migration_add_embeddings.php';
        $output = ob_get_clean();
        $message = '<strong>Database Migration Results:</strong><pre style="background: #f5f5f5; padding: 10px; overflow-x: auto;">' . esc_html($output) . '</pre>';
      }
      // Handle Batch Embedding
      elseif (isset($_POST['run_batch_embed'])) {
        ob_start();
        include plugin_dir_path(__FILE__) . 'batch_embed_faqs.php';
        $output = ob_get_clean();
        $message = '<strong>Batch Embedding Results:</strong><pre style="background: #f5f5f5; padding: 10px; overflow-x: auto;">' . esc_html($output) . '</pre>';
      }
      // Handle Semantic Matching Test
      elseif (isset($_POST['run_test_semantic'])) {
        ob_start();
        include plugin_dir_path(__FILE__) . 'test_semantic_matching.php';
        $output = ob_get_clean();
        $message = '<strong>Semantic Matching Test Results:</strong><pre style="background: #f5f5f5; padding: 10px; overflow-x: auto; max-height: 600px;">' . esc_html($output) . '</pre>';
      }
      // Handle Simple Semantic Test
      elseif (isset($_POST['run_test_simple'])) {
        ob_start();
        include plugin_dir_path(__FILE__) . 'test_semantic_simple.php';
        $output = ob_get_clean();
        $message = '<strong>Simple Semantic Test Results:</strong><pre style="background: #f5f5f5; padding: 10px; overflow-x: auto;">' . esc_html($output) . '</pre>';
      }
      // Settings update
      else {
        $message = 'Settings updated.';
      }
    }

    if ($message) {
      echo '<div class="' . esc_attr($message_type) . '"><p>' . $message . '</p></div>';
    }
    ?>
    <div class="wrap">
      <h1>ACUR Chatbot — Settings</h1>

      <div class="notice notice-info">
        <p><strong>Local Matching Enabled:</strong> This chatbot now uses local PHP-based question matching instead of external APIs. No additional configuration is required.</p>
      </div>

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

      <h2>Database Management</h2>
      <p>Use these tools to manage the database structure and FAQ embeddings.</p>

      <form method="post" style="display: inline-block; margin-right: 10px;">
        <?php wp_nonce_field('acurcb_settings'); ?>
        <input type="hidden" name="run_db_migration" value="1">
        <button type="submit" class="button button-secondary" onclick="return confirm('Run database migration to add embedding columns?\n\nThis will:\n- Add embedding column to FAQs table\n- Add embedding_version column\n- Add embedding_updated_at column\n\nThis is safe to run multiple times.');">
          Run Database Migration
        </button>
      </form>

      <form method="post" style="display: inline-block;">
        <?php wp_nonce_field('acurcb_settings'); ?>
        <input type="hidden" name="run_batch_embed" value="1">
        <button type="submit" class="button button-primary" onclick="return confirm('Generate embeddings for all FAQs?\n\nThis will:\n- Process all FAQs in the database\n- Generate vector embeddings for semantic search\n- Update embedding timestamps\n\nThis may take a few moments for large FAQ databases.');">
          Generate Embeddings for All FAQs
        </button>
      </form>

      <div class="notice notice-warning" style="margin-top: 20px;">
        <p><strong>Note:</strong> Run the Database Migration first if you haven't already. Then use the Generate Embeddings button to create embeddings for all your FAQs.</p>
      </div>

      <h2 style="margin-top: 40px;">Testing & Validation</h2>
      <p>Test the semantic matching system to ensure it's working correctly.</p>

      <form method="post" style="display: inline-block; margin-right: 10px;">
        <?php wp_nonce_field('acurcb_settings'); ?>
        <input type="hidden" name="run_test_simple" value="1">
        <button type="submit" class="button button-secondary">
          Run Simple Test
        </button>
      </form>

      <form method="post" style="display: inline-block;">
        <?php wp_nonce_field('acurcb_settings'); ?>
        <input type="hidden" name="run_test_semantic" value="1">
        <button type="submit" class="button button-secondary">
          Run Full Semantic Test
        </button>
      </form>

      <div class="notice notice-info" style="margin-top: 20px;">
        <p>
          <strong>Simple Test:</strong> Quick verification test with 5 sample queries (fast, ~5 seconds)<br>
          <strong>Full Semantic Test:</strong> Comprehensive accuracy test comparing old vs new matcher (slower, ~1-2 minutes)
        </p>
      </div>

      <form method="post" style="margin-top: 30px;">
        <?php wp_nonce_field('acurcb_settings'); ?>
        <p><em>Future settings for local matching configuration can be added here.</em></p>
      </form>
    </div>
    <?php
  }
}
