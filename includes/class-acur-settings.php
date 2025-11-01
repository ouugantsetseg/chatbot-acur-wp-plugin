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
			// Merge current settings to preserve any other keys, then update.
			$current_opt = get_option(self::OPT, []);

			// 1. Save Cohere AI settings
			$cohere_enabled = isset($_POST['cohere_enabled']) ? 1 : 0;
			$cohere_key = sanitize_text_field($_POST['cohere_key'] ?? '');
			
			// 2. Save OpenAI settings (NEW)
			$openai_enabled = isset($_POST['openai_enabled']) ? 1 : 0;
			$openai_key = sanitize_text_field($_POST['openai_key'] ?? '');
			
			// Combine and save all settings
			$new_opt = array_merge($current_opt, [
				'cohere_enabled' => $cohere_enabled, 
				'cohere_key' => $cohere_key,
				'openai_enabled' => $openai_enabled, // NEW
				'openai_key' => $openai_key,     // NEW
			]);
			
			update_option(self::OPT, $new_opt);

			echo '<div class="updated"><p>Settings updated.</p></div>';
		}
		?>
		<div class="wrap">
			<h1>ACUR Chatbot — Settings</h1>

			<div class="notice notice-info">
				<p><strong>AI Chatbot Options:</strong> You can choose between AI-powered chatbot (OpenAI/Cohere) or local FAQ matching. When an AI service is enabled, it will replace the local matching completely.</p>
			</div>

			<h2>AI Chatbot Settings</h2>

<form method="post">
	<?php wp_nonce_field('acurcb_settings'); ?>
	<table class="form-table">
		
		<tr style="border-top: 2px solid #ccc;">
			<th colspan="2"><h3>Cohere API Settings</h3></th>
		</tr>
		<tr>
			<th>Enable Cohere API</th>
			<td>
				<input type="checkbox" name="cohere_enabled" value="1" <?php checked(self::get('cohere_enabled'), 1); ?> />
				<p class="description">Enable the Cohere AI model to answer questions. When enabled, local matching will be disabled.</p>
			</td>
		</tr>
		<tr>
			<th>Cohere API Key</th>
			<td>
				<input type="text" name="cohere_key" value="<?php echo esc_attr(self::get('cohere_key')); ?>" size="50" />
				<p class="description">Enter your Cohere API key.</p>
			</td>
		</tr>

		<tr style="border-top: 2px solid #ccc;">
			<th colspan="2"><h3>OpenAI API Settings</h3></th>
		</tr>
		<tr>
			<th>Enable OpenAI API</th>
			<td>
				<input type="checkbox" name="openai_enabled" value="1" <?php checked(self::get('openai_enabled'), 1); ?> />
				<p class="description">Enable the OpenAI model (gpt-3.5-turbo) to answer questions. When enabled, local matching will be disabled. Has priority over Cohere if both are enabled.</p>
			</td>
		</tr>
		<tr>
			<th>OpenAI API Key</th>
			<td>
				<input type="text" name="openai_key" value="<?php echo esc_attr(self::get('openai_key')); ?>" size="50" />
				<p class="description">Enter your OpenAI API key.</p>
			</td>
		</tr>
		
	</table>
	<p><input type="submit" class="button-primary" value="Save Settings" /></p>
</form>

			<h2>Local Matching (When AI is Disabled)</h2>
			<p>When both AI services are disabled, the chatbot uses advanced text similarity algorithms to match user questions with your FAQ entries:</p>
			<ul>
				<li><strong>Keyword Matching:</strong> Identifies common words between user questions and FAQ content</li>
				<li><strong>Text Similarity:</strong> Uses Jaccard similarity and Levenshtein distance for fuzzy matching</li>
				<li><strong>Tag-based Matching:</strong> Gives higher priority to matches found in FAQ tags</li>
				<li><strong>Smart Scoring:</strong> Combines multiple matching methods for best results</li>
			</ul>
			
			<hr>

			<h2>How AI Chatbot Works</h2>
			<p>You can configure the chatbot to use AI services (OpenAI or Cohere) or local FAQ matching. Here's how the priority system works:</p>
			<ul>
				<li><strong>Priority 1 - OpenAI:</strong> If OpenAI is enabled and API key is provided, the chatbot will use OpenAI GPT-3.5-turbo model exclusively. Local matching is disabled.</li>
				<li><strong>Priority 2 - Cohere:</strong> If only Cohere is enabled and API key is provided, the chatbot will use Cohere Command model exclusively. Local matching is disabled.</li>
				<li><strong>Priority 3 - Local Matching:</strong> If both AI services are disabled, the chatbot will use local PHP-based FAQ matching.</li>
				<li><strong>Both Enabled:</strong> If both OpenAI and Cohere are enabled, OpenAI takes priority and Cohere is ignored.</li>
				<li><strong>Billing & Limits:</strong> AI services operate on a pay-as-you-go model. Monitor your usage to avoid unexpected costs. Free trials may have usage limits.</li>
			</ul>

			<hr>

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