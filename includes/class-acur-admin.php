<?php
if (!defined('ABSPATH')) exit;

class ACURCB_Admin {
  static function render_faqs() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;

    // Handle add/update/delete
    if ($_SERVER['REQUEST_METHOD']==='POST' && check_admin_referer('acurcb_faqs')) {
      $action = sanitize_text_field($_POST['action'] ?? '');
      if ($action==='add') {
        $wpdb->insert('faqs', [
          'question' => wp_kses_post($_POST['question'] ?? ''),
          'answer'   => wp_kses_post($_POST['answer'] ?? ''),
          'tags'     => wp_unslash($_POST['tags'] ?? null),
        ]);
      } elseif ($action==='update') {
        $wpdb->update('faqs', [
          'question' => wp_kses_post($_POST['question'] ?? ''),
          'answer'   => wp_kses_post($_POST['answer'] ?? ''),
          'tags'     => wp_unslash($_POST['tags'] ?? null),
        ], ['id' => intval($_POST['id'])]);
      } elseif ($action==='delete') {
        $wpdb->delete('faqs', ['id' => intval($_POST['id'])]);
      }
      // ping backend to rebuild index
      self::reload_backend();
      echo '<div class="updated"><p>Saved.</p></div>';
    }

    $rows = $wpdb->get_results("SELECT id, question, LEFT(answer, 120) AS answer FROM faqs ORDER BY updated_at DESC, id DESC LIMIT 500");
    ?>
    <div class="wrap">
      <h1>ACUR Chatbot — FAQs</h1>
      <form method="post">
        <?php wp_nonce_field('acurcb_faqs'); ?>
        <h2>Add / Edit FAQ</h2>
        <input type="hidden" name="action" value="add" id="acur_action">
        <input type="hidden" name="id" value="" id="acur_id">
        <p><label>Question<br><textarea name="question" rows="3" class="large-text" id="acur_q"></textarea></label></p>
        <p><label>Answer<br><textarea name="answer" rows="6" class="large-text code" id="acur_a"></textarea></label></p>
        <p><label>Tags (JSON, optional)<br><input name="tags" class="regular-text code" id="acur_t"></label></p>
        <p><button class="button button-primary">Save FAQ</button></p>
      </form>

      <h2>Existing</h2>
      <table class="widefat">
        <thead><tr><th>ID</th><th>Question</th><th>Answer (preview)</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php echo intval($r->id); ?></td>
            <td><?php echo esc_html(wp_trim_words($r->question, 20)); ?></td>
            <td><code><?php echo esc_html($r->answer); ?>…</code></td>
            <td>
              <form method="post" style="display:inline;">
                <?php wp_nonce_field('acurcb_faqs'); ?>
                <input type="hidden" name="id" value="<?php echo intval($r->id); ?>">
                <input type="hidden" name="action" value="delete">
                <button class="button-link-delete" onclick="return confirm('Delete FAQ #<?php echo intval($r->id); ?>?')">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <script>
        // simple inline edit hydrator (optional improvement: build full edit modal)
        document.querySelectorAll('table.widefat tbody tr').forEach(tr=>{
          tr.addEventListener('click', ()=>{
            const tds = tr.querySelectorAll('td');
            const id = tds[0].innerText.trim();
            // Fetch full row
            fetch('<?php echo esc_js( admin_url('admin-ajax.php?action=acurcb_get_faq&id=') ); ?>'+id, {credentials:'same-origin'})
              .then(r=>r.json()).then(f=>{
                document.getElementById('acur_action').value='update';
                document.getElementById('acur_id').value=f.id;
                document.getElementById('acur_q').value=f.question;
                document.getElementById('acur_a').value=f.answer;
                document.getElementById('acur_t').value=f.tags || '';
                window.scrollTo({top:0,behavior:'smooth'});
              });
          });
        });
      </script>
    </div>
    <?php
  }

  static function reload_backend() {
    $api = ACURCB_Settings::get('api_base');
    if (!$api) return;
    $sec = ACURCB_Settings::get('shared_secret');
    wp_remote_post( trailingslashit($api) . 'reload', [
      'timeout' => 8,
      'headers' => array_filter(['Content-Type'=>'application/json','X-ACUR-Secret'=>$sec]),
      'body'    => wp_json_encode(['source'=>'mysql','table'=>'faqs']),
    ]);
  }
}

// AJAX endpoint to fetch a single FAQ row (for inline edit)
add_action('wp_ajax_acurcb_get_faq', function () {
  if (!current_user_can('manage_options')) wp_die();
  global $wpdb;
  $id = intval($_GET['id'] ?? 0);
  $row = $wpdb->get_row( $wpdb->prepare("SELECT id, question, answer, tags FROM faqs WHERE id=%d", $id), ARRAY_A );
  wp_send_json($row ?: []);
});
