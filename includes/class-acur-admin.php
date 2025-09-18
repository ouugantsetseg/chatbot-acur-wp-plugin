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

}

// AJAX endpoint to fetch a single FAQ row (for inline edit)
add_action('wp_ajax_acurcb_get_faq', function () {
  if (!current_user_can('manage_options')) wp_die();
  global $wpdb;
  $id = intval($_GET['id'] ?? 0);
  $row = $wpdb->get_row( $wpdb->prepare("SELECT id, question, answer, tags FROM faqs WHERE id=%d", $id), ARRAY_A );
  wp_send_json($row ?: []);
});
