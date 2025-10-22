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
        // NOTE: The settings keys are assumed to be 'cohere_enabled' and 'cohere_key'.
        $enabled = ACURCB_Settings::get('cohere_enabled');
        $apiKey = ACURCB_Settings::get('cohere_key');

        if ($enabled && !empty($apiKey)) {
            // CALL THE COHERE FUNCTION
            $answer = self::ask_cohere($question, $apiKey);
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

    /**
     * Calls the Cohere Chat API using the Bearer token authorization scheme.
     * * @param string $question The user's query.
     * @param string $apiKey The Cohere API key.
     * @return string|false The model's answer or false on error.
     */
    public static function ask_cohere($question, $apiKey) {
        // Cohere Chat Endpoint (v1)
        $endpoint = "https://api.cohere.ai/v1/chat";
        
        // Request Body (Payload for Cohere API)
        $body = [
            'model' => 'command-a-03-2025', 
            'message' => $question, 
            'temperature' => 0.7,
            'max_tokens' => 150
        ];
        
        // Use wp_remote_post for WordPress HTTP requests
        $response = wp_remote_post($endpoint, [
            'headers' => [
                // 🔑 Authorization is 'Bearer' token for Cohere
                'Authorization' => 'Bearer ' . $apiKey, 
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($body),
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            // Log the error for debugging
            error_log('Cohere API Error: ' . $response->get_error_message());
            return false;
        }

        $http_status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Check for non-200 HTTP status (e.g., 401 Unauthorized, 400 Bad Request)
        if ($http_status !== 200) {
            error_log('Cohere API HTTP Error: ' . $http_status . ' - ' . ($data['message'] ?? 'Unknown Error'));
            return false;
        }
        
        // Cohere v1 Chat response is parsed to get the 'text' field
        return $data['text'] ?? false;
    }
}