<?php
if (!defined('ABSPATH')) exit;

class ACURCB_REST {
    /**
     * Registers all custom REST API routes for the chatbot plugin.
     */
    static function register_routes() {
        // Route for local FAQ matching
        register_rest_route('acur-chatbot/v1','/match', [
            'methods'  => 'GET',
            'callback' => [__CLASS__, 'match'],
            'permission_callback' => '__return_true',
            'args' => [
                'q' => ['required'=>true], 'session_id' => ['required'=>false],
            ]
        ]);

        // Route for recording user feedback
        register_rest_route('acur-chatbot/v1','/feedback', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'feedback'],
            'permission_callback' => '__return_true',
        ]);

        // Route for recording escalation requests
        register_rest_route('acur-chatbot/v1','/escalate', [
            'methods'  => 'POST',
            'callback' => [__CLASS__, 'escalate'],
            'permission_callback' => '__return_true',
        ]);

        // ✅ New LLM endpoint (local fallback to Cohere)
        register_rest_route('acur-chatbot/v1','/ask', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'ask'],
            'permission_callback' => '__return_true',
            'args' => [
                'q' => ['required'=>true],
                'session_id' => ['required'=>false],
            ]
        ]);
    }

    /**
     * Handles the /match route: Performs local FAQ matching.
     * @param WP_REST_Request $req The request object.
     * @return WP_REST_Response
     */
    static function match($req) {
        $question = sanitize_text_field($req['q']);
        $session_id = sanitize_text_field($req['session_id'] ?: '');

        if (empty($question)) {
            return new WP_REST_Response(['detail' => 'Question parameter is required'], 400);
        }

        // Use local matcher instead of external API
        $result = ACURCB_Matcher::match($question, 5);

        return new WP_REST_Response($result, 200);
    }

    /**
     * Handles the /feedback route: Records user feedback on answers.
     * @param WP_REST_Request $req The request object.
     * @return WP_REST_Response
     */
    static function feedback($req) {
        $session_id = sanitize_text_field($req->get_param('session_id'));
        $faq_id = $req->get_param('faq_id') ? intval($req->get_param('faq_id')) : null;
        $helpful = (bool)$req->get_param('helpful');

        // Store feedback locally
        $success = ACURCB_Matcher::store_feedback($session_id, $faq_id, $helpful);

        if ($success) {
            return new WP_REST_Response(['status' => 'success', 'message' => 'Feedback recorded'], 200);
        } else {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Failed to record feedback'], 500);
        }
    }

    /**
     * Handles the /escalate route: Records user escalation requests.
     * @param WP_REST_Request $req The request object.
     * @return WP_REST_Response
     */
    static function escalate($req) {
        $session_id = sanitize_text_field($req->get_param('session_id'));
        $user_query = wp_strip_all_tags($req->get_param('user_query'));
        $contact_email = sanitize_email($req->get_param('contact_email'));

        if (empty($contact_email) || !is_email($contact_email)) {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Valid email address is required'], 400);
        }

        // Store escalation locally
        $success = ACURCB_Matcher::store_escalation($session_id, $user_query, $contact_email);

        if ($success) {
            return new WP_REST_Response(['status' => 'success', 'message' => 'Escalation request recorded'], 200);
        } else {
            return new WP_REST_Response(['status' => 'error', 'message' => 'Failed to record escalation'], 500);
        }
    }

    /**
     * Handles the /ask route: Tries local FAQ, then falls back to Cohere LLM.
     * @param WP_REST_Request $req The request object.
     * @return WP_REST_Response
     */
    public static function ask($req) {
        $question = sanitize_text_field($req['q'] ?? '');
        $session_id = sanitize_text_field($req['session_id'] ?? '');

        if (empty($question)) {
            return new WP_REST_Response(['detail'=>'Question parameter is required'],400);
        }

        // 1️⃣ Try local FAQ match first
        $result = ACURCB_Matcher::match($question, 5);
        if (!empty($result['answer']) && $result['score'] > 0.9) {
            $result['source'] = 'local';
            return new WP_REST_Response($result, 200);
        }

        // 2️⃣ If Cohere is enabled, call Cohere API
        $enabled = ACURCB_Settings::get('cohere_enabled');
        $apiKey = ACURCB_Settings::get('cohere_key');

        if ($enabled && !empty($apiKey)) {
            // CALL THE COHERE FUNCTION
            $answer = self::ask_cohere($question, $apiKey, $session_id);
            if ($answer) {
                return new WP_REST_Response([
                    'answer'=>$answer,
                    'score'=>0.8,
                    'source'=>'cohere' // Source is now 'cohere'
                ], 200);
            }
        }

        // 3️⃣ Fallback
        return new WP_REST_Response([
            'answer'=>"Sorry, I couldn't find an answer.",
            'score'=>0,
            'source'=>'none'
        ], 200);
    }

    // --- START: New Dynamic RAG Function ---
    /**
     * Dynamically fetches and caches the ACUR FAQ content for RAG.
     * Uses WordPress Transients for a 24-hour cache.
     * @return string The processed context text.
     */
    private static function get_acur_context_cached() {
        // Define cache key and target URL
        $cache_key = 'acurcb_rag_context';
        $faq_url = 'https://www.acur.org.au/acur2025-faq/';
        
        // 1. Check if context is available in cache
        $context = get_transient($cache_key);
        if ($context) {
            return $context;
        }

        // 2. Define the general ACUR info (hardcoded as a reliable fallback)
        $context = "ACUR ORGANIZATION INFO: ACUR is the Australasian Council for Undergraduate Research. We promote, celebrate, and advocate for undergraduate research across the Australasian region. The ACUR region includes: Australia, New Zealand, Pacific Island nations, and Southeast Asian countries. CONTACT: You can contact the ACUR team by email at admin@acur.org.au or by submitting an online form. \n\n";
        $context .= "--- ACUR CONFERENCE FAQS (Fetched dynamically) ---\n";

        // 3. Fetch the HTML content
        $response = wp_remote_get($faq_url, ['timeout' => 10]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $html = wp_remote_retrieve_body($response);
            
            // --- Basic HTML Parsing & Cleaning (Needs verification based on actual page structure) ---
            
            // Attempt to find content between specific markers. (Assuming the FAQ content is the main text block)
            // Note: This simple method might include extraneous text or menus. For robust parsing,
            // you'd typically need the DOMDocument class, but this simpler method works for many sites.
            
            $faq_html = $html; 
            
            // Strip all HTML tags, convert HTML entities (like &amp;), and clean up extra whitespace
            $processed_text = wp_strip_all_tags($faq_html, true);
            $processed_text = html_entity_decode($processed_text);
            $processed_text = preg_replace('/[\r\n]+/', "\n", $processed_text);
            
            // Append processed content to context
            $context .= $processed_text;
            
            // 4. Store the new context in the cache for 24 hours (86400 seconds)
            set_transient($cache_key, $context, DAY_IN_SECONDS);

            return $context;
        }

        // If fetching fails, return general ACUR info only (the hardcoded part)
        error_log('Failed to fetch ACUR FAQ page from: ' . $faq_url);
        // The $context defined in step 2 is returned
        return $context; 
    }
    // --- END: New Dynamic RAG Function ---

    /**
     * Calls the Cohere Chat API using the Bearer token authorization scheme and RAG context.
     * @param string $question The user's query.
     * @param string $apiKey The Cohere API key.
     * @param string $session_id The user's session ID (for conversation history, though not fully used here).
     * @return string|false The model's answer or false on error.
     */
    public static function ask_cohere($question, $apiKey, $session_id = null) {
        $endpoint = "https://api.cohere.ai/v1/chat";
        
        // Use the model provided by the user
        $model_name = 'command-a-03-2025'; 

        // 1. Dynamically retrieve the ACUR context (Cached!)
        $acur_context = self::get_acur_context_cached(); 
        
        // 2. Define the system instructions and persona
        $system_preamble = "You are the ACUR Chatbot, a helpful and professional assistant for the Australasian Council for Undergraduate Research (ACUR). Your primary goal is to answer user questions accurately using the provided context about ACUR's conferences and policies. If the answer to a specific ACUR question is not in the context, you must state that the information is not currently available in your knowledge base. For general knowledge questions (like math or science), you may use your general knowledge.";

        // 3. Final Preamble Construction: prepend context to instructions
        $final_preamble = "CONTEXT:\n" . $acur_context . "\n\nINSTRUCTIONS: " . $system_preamble;


        // Request Body (Payload for Cohere API)
        $body = [
            'model' => $model_name, 
            'message' => $question, 
            'preamble' => $final_preamble, // Pass the RAG context here
            'temperature' => 0.2, // Lower temperature for more factual, context-based answers
            'max_tokens' => 300 // Increased tokens for detailed RAG answers
        ];
        
        // Use wp_remote_post for WordPress HTTP requests
        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey, 
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($body),
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            error_log('Cohere API Error: ' . $response->get_error_message());
            return false;
        }

        $http_status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Check for non-200 HTTP status (e.g., 401 Unauthorized, 400 Bad Request)
        if ($http_status !== 200) {
            error_log('Cohere API HTTP Error: ' . $http_status . ' - ' . ($data['message'] ?? 'Unknown Error'));
            // Return specific error message to user if appropriate (optional)
            // return "Error: Could not reach the AI service. Please check the logs.";
            return false;
        }
        
        // Cohere v1 Chat response is parsed to get the 'text' field
        return $data['text'] ?? false;
    }
}