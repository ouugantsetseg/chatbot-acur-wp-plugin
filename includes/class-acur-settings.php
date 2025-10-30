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
				<p><strong>Local Matching Enabled:</strong> This chatbot now uses local PHP-based question matching with the function of external AI APIs for answering the questions and get more conversational responses. </p>
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
				<p class="description">Enable the Cohere model as a fallback for complex questions.</p>
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
				<p class="description">Enable the OpenAI model (e.g., gpt-3.5-turbo) as a fallback. This option is prioritized over Cohere.</p>
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

			<h2>How It Works</h2>
			<p>The chatbot uses advanced text similarity algorithms to match user questions with your FAQ entries:</p>
			<ul>
				<li><strong>Keyword Matching:</strong> Identifies common words between user questions and FAQ content</li>
				<li><strong>Text Similarity:</strong> Uses Jaccard similarity and Levenshtein distance for fuzzy matching</li>
				<li><strong>Tag-based Matching:</strong> Gives higher priority to matches found in FAQ tags</li>
				<li><strong>Smart Scoring:</strong> Combines multiple matching methods for best results</li>
			</ul>
			
			<hr>

			<h2>API Key Functioning</h2>
			<p>External API keys enable the chatbot to answer complex or general knowledge questions not covered in the local FAQ. This is known as the **Fallback Mechanism**.</p>
			<ul>
				<li><strong>Prioritization:</strong> The chatbot first attempts to find a match in the local FAQ. If the match score is low, it checks for an external API key.</li>
				<li><strong>OpenAI Key (`openai_key`):</strong> If provided and enabled, the chatbot uses the OpenAI **GPT-3.5-turbo** model as the primary LLM fallback. It is designed to be the fastest and most cost-effective solution.</li>
				<li><strong>Cohere Key (`cohere_key`):</strong> If provided and enabled, the chatbot uses the Cohere **Command** model as a secondary LLM fallback, only if the OpenAI key is missing or fails.</li>
				<li><strong>Billing & Limits:</strong> Both keys operate on a **pay-as-you-go** model (or a limited free trial). Usage limits apply to all external API calls. If both keys are missing or their accounts run out of credit, the chatbot will default to the **"Sorry, I couldn't find an answer"** message.</li>
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