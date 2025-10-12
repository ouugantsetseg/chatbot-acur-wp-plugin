<?php
if (!defined('ABSPATH')) exit;

class ACURCB_Admin {
  static function render_faqs() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;

    // Handle add/update/delete
    if ($_SERVER['REQUEST_METHOD']==='POST' && check_admin_referer('acurcb_faqs')) {
      $action = sanitize_text_field($_POST['action'] ?? '');

      if ($action==='add' || $action==='update') {
        // Process tags: convert comma-separated to JSON array
        $tags_input = sanitize_text_field($_POST['tags'] ?? '');
        $tags_json = null;

        if (!empty($tags_input)) {
          $tags_array = array_map('trim', explode(',', $tags_input));
          $tags_array = array_filter($tags_array); // Remove empty entries
          if (!empty($tags_array)) {
            $tags_json = json_encode(array_values($tags_array));
          }
        }

        $faq_data = [
          'question' => wp_kses_post($_POST['question'] ?? ''),
          'answer'   => wp_kses_post($_POST['answer'] ?? ''),
          'tags'     => $tags_json,
        ];

        if ($action==='add') {
          $result = $wpdb->insert('faqs', $faq_data);
          if ($result !== false) {
            echo '<div class="updated"><p><strong>FAQ added successfully!</strong></p></div>';
          } else {
            echo '<div class="error"><p><strong>Error adding FAQ.</strong></p></div>';
          }
        } elseif ($action==='update') {
          $result = $wpdb->update('faqs', $faq_data, ['id' => intval($_POST['id'])]);
          if ($result !== false) {
            echo '<div class="updated"><p><strong>FAQ updated successfully!</strong></p></div>';
          } else {
            echo '<div class="error"><p><strong>Error updating FAQ.</strong></p></div>';
          }
        }
      } elseif ($action==='delete') {
        $result = $wpdb->delete('faqs', ['id' => intval($_POST['id'])]);
        if ($result !== false) {
          echo '<div class="updated"><p><strong>FAQ deleted successfully!</strong></p></div>';
        } else {
          echo '<div class="error"><p><strong>Error deleting FAQ.</strong></p></div>';
        }
      }
    }

    $rows = $wpdb->get_results("SELECT id, question, LEFT(answer, 120) AS answer, tags, updated_at FROM faqs ORDER BY updated_at DESC, id DESC LIMIT 500");
    ?>
    <div class="wrap">
      <h1>ACUR Chatbot — FAQs</h1>
      <form method="post">
        <?php wp_nonce_field('acurcb_faqs'); ?>
        <h2 id="form-title">Add New FAQ</h2>
        <input type="hidden" name="action" value="add" id="acur_action">
        <input type="hidden" name="id" value="" id="acur_id">
        <table class="form-table">
          <tr>
            <th scope="row"><label for="acur_q">Question</label></th>
            <td><textarea name="question" rows="3" class="large-text" id="acur_q" required></textarea></td>
          </tr>
          <tr>
            <th scope="row"><label for="acur_a">Answer</label></th>
            <td><textarea name="answer" rows="6" class="large-text" id="acur_a" required></textarea></td>
          </tr>
          <tr>
            <th scope="row"><label for="acur_t">Tags</label></th>
            <td>
              <input name="tags" class="regular-text" id="acur_t" placeholder="billing, support, technical, help">
              <p class="description">Enter comma-separated tags to help categorize this FAQ (e.g., billing, support, technical)</p>
            </td>
          </tr>
        </table>
        <p class="submit">
          <button type="submit" class="button button-primary" id="save-button">Add FAQ</button>
          <button type="button" class="button" id="clear-form-btn" style="margin-left: 10px;">Clear Form</button>
        </p>
      </form>

      <h2>Existing FAQs (<?php echo count($rows); ?> total)</h2>
      <div style="margin-bottom: 10px;">
        <input type="text" id="faq-search" placeholder="Search FAQs by question, answer, or tags..." style="width: 400px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
      </div>
      <table class="widefat striped" id="faqs-table">
        <thead>
          <tr>
            <th style="width: 50px;">ID</th>
            <th style="width: 30%;">Question</th>
            <th style="width: 35%;">Answer (preview)</th>
            <th style="width: 15%;">Tags</th>
            <th style="width: 10%;">Updated</th>
            <th style="width: 10%;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $tags = json_decode($r->tags, true);
          if (!is_array($tags)) $tags = [];
        ?>
          <tr class="faq-row" data-id="<?php echo intval($r->id); ?>">
            <td><strong><?php echo intval($r->id); ?></strong></td>
            <td class="question-cell">
              <div class="question-text"><?php echo esc_html(wp_trim_words($r->question, 15)); ?></div>
              <div class="row-actions">
                <span class="edit"><a href="#" class="edit-faq">Edit</a> | </span>
                <span class="delete">
                  <a href="#" class="delete-faq" data-id="<?php echo intval($r->id); ?>">Delete</a>
                </span>
              </div>
            </td>
            <td class="answer-cell">
              <div class="answer-preview"><?php echo esc_html($r->answer); ?>…</div>
            </td>
            <td class="tags-cell">
              <?php if (!empty($tags)): ?>
                <div class="tags-container">
                  <?php foreach ($tags as $tag): ?>
                    <span class="faq-tag"><?php echo esc_html($tag); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="no-tags">No tags</span>
              <?php endif; ?>
            </td>
            <td class="date-cell">
              <?php echo date('M j, Y', strtotime($r->updated_at)); ?>
            </td>
            <td class="actions-cell">
              <button class="button-secondary button-small edit-faq" data-id="<?php echo intval($r->id); ?>">Edit</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Delete form (hidden) -->
      <form method="post" id="delete-form" style="display: none;">
        <?php wp_nonce_field('acurcb_faqs'); ?>
        <input type="hidden" name="id" value="" id="delete-id">
        <input type="hidden" name="action" value="delete">
      </form>
      <style>
        .faq-tag {
          display: inline-block;
          background: #0073aa;
          color: white;
          padding: 2px 8px;
          border-radius: 12px;
          font-size: 11px;
          margin: 1px 2px;
          font-weight: normal;
        }
        .tags-container {
          line-height: 1.4;
        }
        .no-tags {
          color: #999;
          font-style: italic;
          font-size: 12px;
        }
        .question-text {
          font-weight: 500;
          margin-bottom: 4px;
        }
        .answer-preview {
          color: #666;
          font-size: 13px;
        }
        .faq-row:hover {
          background-color: #f8f9fa;
        }
        .date-cell {
          font-size: 12px;
          color: #666;
        }
        .button-small {
          padding: 2px 8px !important;
          font-size: 11px !important;
          height: auto !important;
        }
        .row-actions {
          visibility: hidden;
          margin-top: 4px;
        }
        .faq-row:hover .row-actions {
          visibility: visible;
        }
        #faq-search {
          border: 1px solid #ddd;
          border-radius: 4px;
        }
        .form-highlight {
          background-color: #fff2cc !important;
          border: 2px solid #f0b000 !important;
        }
      </style>

      <script>
        document.addEventListener('DOMContentLoaded', function() {
          // Search functionality
          const searchInput = document.getElementById('faq-search');
          const tableRows = document.querySelectorAll('.faq-row');

          searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            tableRows.forEach(row => {
              const question = row.querySelector('.question-text').textContent.toLowerCase();
              const answer = row.querySelector('.answer-preview').textContent.toLowerCase();
              const tags = Array.from(row.querySelectorAll('.faq-tag')).map(tag => tag.textContent.toLowerCase()).join(' ');

              if (question.includes(searchTerm) || answer.includes(searchTerm) || tags.includes(searchTerm)) {
                row.style.display = '';
              } else {
                row.style.display = 'none';
              }
            });
          });

          // Clear form functionality
          function clearForm() {
            document.getElementById('acur_action').value = 'add';
            document.getElementById('acur_id').value = '';
            document.getElementById('acur_q').value = '';
            document.getElementById('acur_a').value = '';
            document.getElementById('acur_t').value = '';
            document.getElementById('form-title').textContent = 'Add New FAQ';
            document.getElementById('save-button').textContent = 'Add FAQ';

            // Remove highlight from any highlighted rows
            document.querySelectorAll('.form-highlight').forEach(el => {
              el.classList.remove('form-highlight');
            });

            document.getElementById('acur_q').focus();
          }

          document.getElementById('clear-form-btn').addEventListener('click', clearForm);

          // Edit functionality
          document.querySelectorAll('.edit-faq').forEach(button => {
            button.addEventListener('click', function(e) {
              e.preventDefault();
              const row = this.closest('.faq-row');
              const id = row.dataset.id;

              // Highlight the row being edited
              document.querySelectorAll('.form-highlight').forEach(el => {
                el.classList.remove('form-highlight');
              });
              row.classList.add('form-highlight');

              // Fetch full FAQ data
              fetch('<?php echo esc_js( admin_url('admin-ajax.php?action=acurcb_get_faq&id=') ); ?>' + id, {
                credentials: 'same-origin'
              })
              .then(r => r.json())
              .then(faq => {
                if (faq && faq.id) {
                  document.getElementById('acur_action').value = 'update';
                  document.getElementById('acur_id').value = faq.id;
                  document.getElementById('acur_q').value = faq.question || '';
                  document.getElementById('acur_a').value = faq.answer || '';
                  document.getElementById('form-title').textContent = 'Edit FAQ #' + faq.id;
                  document.getElementById('save-button').textContent = 'Update FAQ';

                  // Handle tags conversion from JSON to comma-separated
                  let tagsString = '';
                  if (faq.tags) {
                    try {
                      const tagsArray = JSON.parse(faq.tags);
                      if (Array.isArray(tagsArray)) {
                        tagsString = tagsArray.join(', ');
                      }
                    } catch (e) {
                      tagsString = faq.tags;
                    }
                  }
                  document.getElementById('acur_t').value = tagsString;

                  // Scroll to form and focus
                  window.scrollTo({top: 0, behavior: 'smooth'});
                  setTimeout(() => {
                    document.getElementById('acur_q').focus();
                  }, 300);
                }
              })
              .catch(err => {
                console.error('Error fetching FAQ:', err);
                alert('Error loading FAQ for editing.');
              });
            });
          });

          // Delete functionality
          document.querySelectorAll('.delete-faq').forEach(button => {
            button.addEventListener('click', function(e) {
              e.preventDefault();
              const id = this.dataset.id;
              const row = this.closest('.faq-row');
              const question = row.querySelector('.question-text').textContent;

              if (confirm(`Are you sure you want to delete FAQ #${id}?\n\nQuestion: ${question}\n\nThis action cannot be undone.`)) {
                document.getElementById('delete-id').value = id;
                document.getElementById('delete-form').submit();
              }
            });
          });
        });
      </script>

    </div>
    <?php
  }

  // New page: Escalation Responses
  static function render_escalations() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    // Ensure responses table has the email_error column (runtime migration)
    self::ensure_response_schema();
    // Prepare table names
    $esc_table = $wpdb->prefix . 'acur_escalations';
    $resp_table = $wpdb->prefix . 'acur_escalation_responses';

    echo '<div class="wrap"><h1>Escalation Responses</h1>';

    // Handle response submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('acurcb_respond')) {
      $action = sanitize_text_field($_POST['action'] ?? '');
      if ($action === 'respond') {
        $es_id = intval($_POST['escalation_id'] ?? 0);
        $resp_text = wp_kses_post($_POST['response_text'] ?? '');
        $current_user = wp_get_current_user();
        $res = $wpdb->insert($resp_table, [
          'escalation_id' => $es_id,
          'responder' => $current_user->user_login ?: 'admin',
          'response_text' => $resp_text
        ]);
        if ($res !== false) {
          $resp_id = $wpdb->insert_id;
          // mark escalation as responded
          $wpdb->update($esc_table, ['status' => 'responded'], ['id' => $es_id]);
          // Optionally send email to user
          $esc = $wpdb->get_row($wpdb->prepare("SELECT contact_email, user_query FROM $esc_table WHERE id = %d", $es_id), ARRAY_A);
          $email_sent = 0;
          $email_sent_at = null;
          $email_error = null;
          if ($esc && is_email($esc['contact_email'])) {
            $site_name = get_bloginfo('name');
            $responder = esc_html($current_user->display_name ?: $current_user->user_login);
            $subject = sprintf('%s — Response to your question', $site_name);

            // Build HTML body
            $body_html = '<html><body>';
            $body_html .= '<p>Hello,</p>';
            $body_html .= '<p>We have responded to your question:</p>';
            $body_html .= '<blockquote style="background:#f8f8f8;padding:10px;border-left:4px solid #ddd;">' . esc_html($esc['user_query']) . '</blockquote>';
            $body_html .= '<p><strong>Answer :</strong></p>';
            $body_html .= '<div style="padding:8px;border:1px solid #eee;background:#fff;">' . nl2br(esc_html($resp_text)) . '</div>';
            $body_html .= '<p>Regards,<br/>' . esc_html($site_name) . '</p>';
            $body_html .= '</body></html>';

            // Headers
            $headers = [];
            $from_email = get_option('admin_email');
            if ($from_email && is_email($from_email)) {
              $headers[] = 'From: ' . $site_name . ' <' . $from_email . '>';
              $headers[] = 'Reply-To: ' . $from_email;
            }
            $headers[] = 'Content-Type: text/html; charset=UTF-8';

            if (function_exists('wp_mail')) {
              // Prepare capture for wp_mail failures (some mail plugins fire wp_mail_failed)
              $GLOBALS['acurcb_last_mail_error'] = null;
              add_action('wp_mail_failed', 'acurcb_capture_mail_failed');

              // Attempt to send; capture WP-level failures via wp_mail_failed action
              $sent = wp_mail($esc['contact_email'], $subject, $body_html, $headers);

              // Remove our temporary listener
              remove_action('wp_mail_failed', 'acurcb_capture_mail_failed');

              if ($sent) {
                $email_sent = 1;
                $email_sent_at = current_time('mysql');
              } else {
                // First prefer any WP-level failure captured
                $mail_error = !empty($GLOBALS['acurcb_last_mail_error']) ? $GLOBALS['acurcb_last_mail_error'] : null;
                // Next try PHPMailer ErrorInfo
                if (empty($mail_error) && !empty($GLOBALS['phpmailer']) && is_object($GLOBALS['phpmailer'])) {
                  $mail_error = property_exists($GLOBALS['phpmailer'], 'ErrorInfo') ? $GLOBALS['phpmailer']->ErrorInfo : null;
                }
                // Fallback to a generic message
                if (empty($mail_error)) $mail_error = 'wp_mail returned false — check mail configuration.';
                $email_error = $mail_error;
              }
            }
          }

          // Update response row with email status if we have an id
          if (!empty($resp_id)) {
            // Always update email_error (may be null) so column gets set when present
            $update_data = ['email_sent' => $email_sent, 'email_sent_at' => $email_sent_at, 'email_error' => $email_error];
            // Debug: log email_error content when present
            if (!empty($email_error)) {
              error_log('[acur] email_error for resp_id=' . intval($resp_id) . ' => ' . $email_error);
            }
            $res_upd = $wpdb->update($resp_table, $update_data, ['id' => $resp_id]);
            // Log results for troubleshooting even when zero rows changed
            if ($res_upd === false) {
              error_log('[acur] Failed to update escalation response id=' . intval($resp_id) . ' - ' . $wpdb->last_error . ' -- last_query: ' . $wpdb->last_query);
            } else {
              error_log('[acur] Updated escalation response id=' . intval($resp_id) . ' - rows_affected=' . intval($res_upd) . ' -- last_query: ' . $wpdb->last_query);
            }
          }

          echo '<div class="updated"><p><strong>Response recorded and escalation marked responded.</strong></p></div>';
        } else {
          echo '<div class="error"><p><strong>Failed to record response.</strong></p></div>';
        }
      }
    }

    // Filters & pagination inputs
  $raw_status = sanitize_text_field($_GET['status'] ?? 'pending');
  $status_filter = ($raw_status === 'all') ? '' : $raw_status;
    $search_q = sanitize_text_field($_GET['q'] ?? '');
    $paged = max(1, intval($_GET['paged'] ?? 1));
    $per_page = 20;

    $where = [];
    if ($status_filter) $where[] = $wpdb->prepare("status = %s", $status_filter);
    if ($search_q) {
      $like = '%' . $wpdb->esc_like($search_q) . '%';
      $where[] = $wpdb->prepare("(user_query LIKE %s OR contact_email LIKE %s)", $like, $like);
    }
    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $total = $wpdb->get_var("SELECT COUNT(*) FROM $esc_table $where_sql");
    $offset = ($paged - 1) * $per_page;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, session_id, user_query, contact_email, status, created_at FROM $esc_table $where_sql ORDER BY created_at DESC LIMIT %d, %d", $offset, $per_page), ARRAY_A);

    // Filter form
    echo '<form method="get" class="alignleft" style="margin-bottom:12px">';
    echo '<input type="hidden" name="page" value="acur-chatbot-escalations" />';
    echo 'Status: <select name="status">';
    foreach (['pending','responded','all'] as $s) {
      $sel = ($s === $raw_status) ? 'selected' : '';
      echo "<option value=\"$s\" $sel>" . ucfirst($s) . "</option>";
    }
    echo '</select> ';
    echo 'Search: <input name="q" value="' . esc_attr($search_q) . '" /> ';
    echo '<button class="button" type="submit">Filter</button>';
    echo '</form>';

    if (empty($rows)) {
      echo '<p>No escalations found.</p>';
    } else {
      echo '<table class="widefat"><thead><tr><th>ID</th><th>Submitted</th><th>Query</th><th>Contact</th><th>Status</th><th>Mail</th><th>Actions</th></tr></thead><tbody>';
      foreach ($rows as $p) {
        echo '<tr>';
        echo '<td>' . intval($p['id']) . '</td>';
        echo '<td>' . esc_html($p['created_at']) . '</td>';
        echo '<td>' . esc_html(mb_strimwidth($p['user_query'], 0, 120, '...')) . '</td>';
        echo '<td>' . esc_html($p['contact_email']) . '</td>';
        echo '<td>' . esc_html($p['status']) . '</td>';
        // Fetch latest response for this escalation to show mail status
        $latest = $wpdb->get_row($wpdb->prepare("SELECT email_sent, email_sent_at, email_error FROM $resp_table WHERE escalation_id=%d ORDER BY created_at DESC LIMIT 1", $p['id']), ARRAY_A);
        $mail_cell = '&mdash;';
        if ($latest) {
          if (!empty($latest['email_sent'])) {
            $mail_cell = 'Sent';
            if (!empty($latest['email_sent_at'])) $mail_cell .= ' @ ' . esc_html($latest['email_sent_at']);
          } else {
            $err = !empty($latest['email_error']) ? esc_html($latest['email_error']) : 'Failed to send';
            $short = (strlen($err) > 60) ? esc_html(mb_substr($err,0,60)) . '...' : esc_html($err);
            $mail_cell = '<span title="' . esc_attr($err) . '" style="color:#a00;">Failed: ' . $short . '</span>';
          }
        }
        echo '<td>' . $mail_cell . '</td>';
        echo '<td>';
        // Pending -> show Respond only. Responded -> show View history only.
        if ($p['status'] === 'pending') {
          $respond_link = esc_url( admin_url('admin.php?page=acur-chatbot-escalations&respond_id=' . intval($p['id'])) );
          echo '<a href="' . $respond_link . '">Respond</a>';
        } else {
          $history_link = esc_url( admin_url('admin.php?page=acur-chatbot-escalations&view=history&id=' . intval($p['id'])) );
          echo '<a href="' . $history_link . '">View history</a>';
        }
        echo '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';

      // Pagination
      $total_pages = max(1, ceil($total / $per_page));
      echo '<div class="tablenav"><div class="tablenav-pages">';
      for ($i = 1; $i <= $total_pages; $i++) {
        $link = esc_url(add_query_arg(['paged'=>$i]));
        $class = ($i === $paged) ? 'nav-tab-active' : '';
        echo '<a class="button ' . $class . '" href="' . $link . '">' . $i . '</a> ';
      }
      echo '</div></div>';
    }

    // If viewing history or arrived via a Respond link
    $eid = 0;
    if (isset($_GET['view']) && $_GET['view'] === 'history' && isset($_GET['id'])) {
      $eid = intval($_GET['id']);
    } elseif (isset($_GET['respond_id'])) {
      $eid = intval($_GET['respond_id']);
    }

    if ($eid > 0) {
      $esc = $wpdb->get_row($wpdb->prepare("SELECT * FROM $esc_table WHERE id=%d", $eid), ARRAY_A);
      if (!$esc) {
        echo '<p>Escalation not found.</p>';
      } else {
        // If we arrived via the Respond link, show only the respond form (no prior responses)
        $is_respond_mode = isset($_GET['respond_id']) && !isset($_GET['view']);

        if ($is_respond_mode) {
          echo '<h2>Escalation #' . intval($eid) . ' — Respond</h2>';
          echo '<p><strong>Query:</strong> ' . esc_html($esc['user_query']) . '</p>';
          echo '<p><strong>Contact:</strong> ' . esc_html($esc['contact_email']) . '</p>';

          if ($esc['status'] !== 'pending') {
            // Already responded
            echo '<p><em>This escalation was already responded to. Use View history to inspect prior replies.</em></p>';
            echo '<p><a href="' . esc_url(add_query_arg(['view'=>'history','id'=>$eid])) . '">View history</a></p>';
          } else {
            // Show only the respond form
            echo '<h3>Post a Response</h3>';
            echo '<form method="post">' . wp_nonce_field('acurcb_respond', '_wpnonce', true, false);
            echo '<input type="hidden" name="action" value="respond" />';
            echo '<input type="hidden" name="escalation_id" value="' . intval($eid) . '" />';
            echo '<textarea name="response_text" rows="6" style="width:100%" placeholder="Type your response here"></textarea>';
            echo '<p style="margin-top:6px"><button class="button button-primary" type="submit">Send response</button></p>';
            echo '</form>';
          }

        } else {
          // History view: show details and all prior responses
          echo '<h2>Escalation #' . intval($eid) . ' — History</h2>';
          echo '<p><strong>Query:</strong> ' . esc_html($esc['user_query']) . '</p>';
          echo '<p><strong>Contact:</strong> ' . esc_html($esc['contact_email']) . '</p>';
          echo '<p><strong>Status:</strong> ' . esc_html($esc['status']) . '</p>';

          $responses = $wpdb->get_results($wpdb->prepare("SELECT responder, response_text, created_at, email_sent, email_sent_at, email_error FROM $resp_table WHERE escalation_id=%d ORDER BY created_at DESC", $eid), ARRAY_A);
          if ($responses) {
            echo '<h3>Responses</h3><ul>';
            foreach ($responses as $r) {
              $mail_status = '';
              if (!empty($r['email_sent'])) {
                $mail_status = ' <em style="color:green">(mailed ' . esc_html($r['email_sent_at']) . ')</em>';
              } else {
                $err = !empty($r['email_error']) ? esc_html($r['email_error']) : 'Not mailed';
                $mail_status = ' <em style="color:#a00">(mail failed)</em>' . (!empty($r['email_error']) ? ' <small title="' . esc_attr($r['email_error']) . '">[details]</small>' : '');
              }
              echo '<li><strong>' . esc_html($r['responder']) . '</strong> (' . esc_html($r['created_at']) . '):' . $mail_status . '<br/>' . nl2br(esc_html($r['response_text'])) . '</li>';
            }
            echo '</ul>';
          } else {
            echo '<p>No responses yet.</p>';
          }

          // Show respond form only if escalation is pending
          if ($esc['status'] === 'pending') {
            echo '<h3>Post a Response</h3>';
            echo '<form method="post">' . wp_nonce_field('acurcb_respond', '_wpnonce', true, false);
            echo '<input type="hidden" name="action" value="respond" />';
            echo '<input type="hidden" name="escalation_id" value="' . intval($eid) . '" />';
            echo '<textarea name="response_text" rows="6" style="width:100%" placeholder="Type your response here"></textarea>';
            echo '<p style="margin-top:6px"><button class="button button-primary" type="submit">Send response</button></p>';
            echo '</form>';
          }
        }
      }
    }

    echo '</div>';

    echo '</div>';
  }

  // New page: Reports (feedback & escalation summaries)
  static function render_reports() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    // Ensure responses table has the email_error column
    self::ensure_response_schema();
    $fb_table = $wpdb->prefix . 'acur_feedback';
    $es_table = $wpdb->prefix . 'acur_escalations';
    $resp_table = $wpdb->prefix . 'acur_escalation_responses';

    echo '<div class="wrap"><h1>Chatbot Reports</h1>';

    // Feedback aggregates
    $total_fb = intval($wpdb->get_var("SELECT COUNT(*) FROM $fb_table"));
    $helpful = intval($wpdb->get_var("SELECT COUNT(*) FROM $fb_table WHERE helpful=1"));
    $not_helpful = intval($wpdb->get_var("SELECT COUNT(*) FROM $fb_table WHERE helpful=0"));
    $help_rate = $total_fb ? round(100 * $helpful / $total_fb, 1) : 0;

    echo '<h2>User Feedback</h2>';
    echo '<p>Total feedback: <strong>' . $total_fb . '</strong> — Helpful: <strong>' . $helpful . '</strong> — Not helpful: <strong>' . $not_helpful . '</strong> — Helpful rate: <strong>' . $help_rate . '%</strong></p>';

    // Prepare data for feedback pie chart
    $fb_labels = ['Helpful', 'Not helpful'];
    $fb_values = [$helpful, $not_helpful];

  echo '<div style="max-width:700px;display:flex;gap:16px;align-items:center">';
  echo '<div style="max-width:320px;flex:0 0 320px;">';
  echo '<canvas id="acur_feedback_pie" style="width:100%;height:200px;background:#fff;border:1px solid #eee;padding:12px;border-radius:6px;display:block"></canvas>';
  echo '</div>';
  echo '<div style="font-size:13px">';
    echo '<p><strong>Helpful rate:</strong> ' . $help_rate . '%</p>';
    echo '<p>Tip: Click legend to toggle segments.</p>';
    echo '</div></div>';

    // Escalations: compute last 14 days counts
    $days = 14;
    $dates = [];
    $esc_counts = [];
    $mail_success = [];
    $mail_fail = [];
    for ($i = $days-1; $i >= 0; $i--) {
      $dt = date('Y-m-d', strtotime("-$i days"));
      $dates[] = $dt;
      $esc_counts[$dt] = 0;
      $mail_success[$dt] = 0;
      $mail_fail[$dt] = 0;
    }
    $rows_dates = $wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) as d, COUNT(*) as cnt FROM $es_table WHERE created_at >= %s GROUP BY DATE(created_at)", date('Y-m-d', strtotime("-" . ($days-1) . " days"))), ARRAY_A);
    foreach ($rows_dates as $r) {
      $d = $r['d']; if (isset($esc_counts[$d])) $esc_counts[$d] = intval($r['cnt']);
    }
    // Mail success/fail by day (from responses table)
    $rows_mail = $wpdb->get_results($wpdb->prepare("SELECT DATE(created_at) as d, SUM(email_sent=1) AS sent, SUM(email_sent=0 AND email_error IS NOT NULL) AS failed FROM $resp_table WHERE created_at >= %s GROUP BY DATE(created_at)", date('Y-m-d', strtotime("-" . ($days-1) . " days"))), ARRAY_A);
    foreach ($rows_mail as $r) {
      $d = $r['d']; if (isset($mail_success[$d])) { $mail_success[$d] = intval($r['sent']); $mail_fail[$d] = intval($r['failed']); }
    }

    // Render canvases for escalation charts
    echo '<h2 style="margin-top:20px">Escalations (last ' . $days . ' days)</h2>';
  echo '<div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">';
  echo '<div style="flex:1 1 520px;max-width:520px;">';
  echo '<canvas id="acur_es_count" style="width:100%;height:200px;background:#fff;border:1px solid #eee;padding:12px;border-radius:6px;display:block"></canvas>';
  echo '</div>';
  echo '<div style="flex:0 0 320px;max-width:320px;">';
  echo '<canvas id="acur_mail_bar" style="width:100%;height:200px;background:#fff;border:1px solid #eee;padding:12px;border-radius:6px;display:block"></canvas>';
  echo '</div>';
  echo '</div>';

    // Inline Chart.js (CDN) and data wiring
    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
    echo '<script> (function(){
      const fbLabels = ' . wp_json_encode($fb_labels) . ';
      const fbValues = ' . wp_json_encode($fb_values) . ';
      const ctx = document.getElementById("acur_feedback_pie").getContext("2d");
  new Chart(ctx, { type: "pie", data: { labels: fbLabels, datasets: [{ data: fbValues, backgroundColor: ["#28a745","#dc3545"] }] }, options: { responsive: true, maintainAspectRatio: false } });

      const dates = ' . wp_json_encode(array_values($dates)) . ';
      const escCounts = ' . wp_json_encode(array_values($esc_counts)) . ';
      const mailSuccess = ' . wp_json_encode(array_values($mail_success)) . ';
      const mailFail = ' . wp_json_encode(array_values($mail_fail)) . ';

      const ctx2 = document.getElementById("acur_es_count").getContext("2d");
  new Chart(ctx2, { type: "line", data: { labels: dates, datasets: [{ label: "Escalations", data: escCounts, borderColor: "#0073aa", backgroundColor: "rgba(0,115,170,0.08)", tension: 0.25 }] }, options: { scales: { x: { ticks: { maxRotation:45, minRotation:0 } } }, responsive:true, maintainAspectRatio: false } });

      const ctx3 = document.getElementById("acur_mail_bar").getContext("2d");
  new Chart(ctx3, { type: "bar", data: { labels: dates, datasets: [{ label: "Mailed", data: mailSuccess, backgroundColor: "#28a745" }, { label: "Failed", data: mailFail, backgroundColor: "#dc3545" }] }, options: { responsive:true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true } } } });
    })(); </script>';

    // Escalation aggregates
    $total_es = intval($wpdb->get_var("SELECT COUNT(*) FROM $es_table"));
    $pending = intval($wpdb->get_var("SELECT COUNT(*) FROM $es_table WHERE status='pending'"));
    $responded = intval($wpdb->get_var("SELECT COUNT(*) FROM $es_table WHERE status='responded'"));

    echo '<h2 style="margin-top:24px">Escalation Report</h2>';
    echo '<p>Total escalations: <strong>' . $total_es . '</strong> — Pending: <strong>' . $pending . '</strong> — Responded: <strong>' . $responded . '</strong></p>';

    // Recent escalations with mail status
    $recent_es = $wpdb->get_results("SELECT id, user_query, contact_email, status, created_at FROM $es_table ORDER BY created_at DESC LIMIT 15", ARRAY_A);
    if ($recent_es) {
      echo '<table class="widefat"><thead><tr><th>ID</th><th>Submitted</th><th>Query</th><th>Contact</th><th>Status</th><th>Mail</th></tr></thead><tbody>';
      foreach ($recent_es as $e) {
        $latest = $wpdb->get_row($wpdb->prepare("SELECT email_sent, email_sent_at, email_error FROM $resp_table WHERE escalation_id=%d ORDER BY created_at DESC LIMIT 1", $e['id']), ARRAY_A);
        $mail_cell = '&mdash;';
        if ($latest) {
          if (!empty($latest['email_sent'])) {
            $mail_cell = 'Sent @ ' . esc_html($latest['email_sent_at']);
          } else {
            $err = !empty($latest['email_error']) ? esc_html($latest['email_error']) : 'Failed to send';
            $short = (strlen($err) > 60) ? esc_html(mb_substr($err,0,60)) . '...' : esc_html($err);
            $mail_cell = '<span title="' . esc_attr($err) . '" style="color:#a00;">Failed: ' . $short . '</span>';
          }
        }
        echo '<tr><td>' . intval($e['id']) . '</td><td>' . esc_html($e['created_at']) . '</td><td>' . esc_html(mb_strimwidth($e['user_query'],0,120,'...')) . '</td><td>' . esc_html($e['contact_email']) . '</td><td>' . esc_html($e['status']) . '</td><td>' . $mail_cell . '</td></tr>';
      }
      echo '</tbody></table>';
    } else {
      echo '<p>No escalations yet.</p>';
    }

    echo '</div>';
  }

  // Ensure responses table schema includes email_error (runtime-safe)
  private static function ensure_response_schema() {
    global $wpdb;
    $resp_table = $wpdb->prefix . 'acur_escalation_responses';
    $col = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM $resp_table LIKE %s", 'email_error'));
    if (empty($col)) {
      // Add column
      $sql = "ALTER TABLE {$resp_table} ADD COLUMN email_error TEXT NULL";
      $wpdb->query($sql);
    }
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

// Capture wp_mail_failed info into a global temporarily
function acurcb_capture_mail_failed( $wp_error ) {
  if (is_wp_error($wp_error)) {
    $msg = $wp_error->get_error_message();
  } else {
    $msg = print_r($wp_error, true);
  }
  $GLOBALS['acurcb_last_mail_error'] = $msg;
}
