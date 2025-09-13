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

    if ($_SERVER['REQUEST_METHOD']==='POST' && check_admin_referer('acurcb_settings')) {
      $opt = [
        'api_base' => esc_url_raw($_POST['api_base'] ?? ''),
        'shared_secret' => sanitize_text_field($_POST['shared_secret'] ?? ''),
      ];
      update_option(self::OPT, $opt);
      echo '<div class="updated"><p>Saved.</p></div>';
    }
    $api = esc_attr(self::get('api_base'));
    $sec = esc_attr(self::get('shared_secret'));
    ?>
    <div class="wrap">
      <h1>ACUR Chatbot — Settings</h1>
      <form method="post">
        <?php wp_nonce_field('acurcb_settings'); ?>
        <table class="form-table">
          <tr><th>FastAPI Base URL</th><td>
            <input type="url" name="api_base" class="regular-text" placeholder="http://127.0.0.1:8000" value="<?php echo $api; ?>">
            <p class="description">Your Python matcher API root</p>
          </td></tr>
          <tr><th>Shared Secret (optional)</th><td>
            <input type="text" name="shared_secret" class="regular-text" value="<?php echo $sec; ?>">
            <p class="description">If your backend expects a header like <code>X-ACUR-Secret</code>.</p>
          </td></tr>
        </table>
        <p><button class="button button-primary">Save</button></p>
      </form>
    </div>
    <?php
  }
}
