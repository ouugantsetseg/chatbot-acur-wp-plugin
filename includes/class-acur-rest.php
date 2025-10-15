<?php
if (!defined('ABSPATH')) exit;

class ACURCB_REST {
  static function register_routes() {
    register_rest_route('acur-chatbot/v1','/match', [
      'methods'  => 'GET',
      'callback' => [__CLASS__, 'match'],
      'permission_callback' => '__return_true',
      'args' => [
        'q' => ['required'=>true], 'session_id' => ['required'=>false],
      ]
    ]);

    register_rest_route('acur-chatbot/v1','/feedback', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'feedback'],
      'permission_callback' => '__return_true',
    ]);

    register_rest_route('acur-chatbot/v1','/escalate', [
      'methods'  => 'POST',
      'callback' => [__CLASS__, 'escalate'],
      'permission_callback' => '__return_true',
    ]);

    // ✅ New OpenAI endpoint
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

  // 🔹 New ask method using local first, then OpenAI
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

      // 2️⃣ If OpenAI is enabled, call OpenAI API
      $enabled = ACURCB_Settings::get('openai_enabled');
      $apiKey = ACURCB_Settings::get('openai_key');

      if ($enabled && !empty($apiKey)) {
          $answer = self::ask_openai($question, $apiKey);
          if ($answer) {
              return new WP_REST_Response([
                  'answer'=>$answer,
                  'score'=>0.8,
                  'source'=>'openai'
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

  // 🔹 Function to call OpenAI
  public static function ask_openai($question,$apiKey){
      $endpoint = "https://api.openai.com/v1/chat/completions";
      $body = [
          'model'=>'gpt-3.5-turbo',
          'messages'=>[
              ['role'=>'system','content'=>'You are a helpful assistant.'],
              ['role'=>'user','content'=>$question]
          ],
          'temperature'=>0.7,
          'max_tokens'=>150
      ];
      $response = wp_remote_post($endpoint, [
          'headers'=>[
              'Authorization'=>'Bearer '.$apiKey,
              'Content-Type'=>'application/json'
          ],
          'body'=>wp_json_encode($body),
          'timeout'=>15
      ]);
      if (is_wp_error($response)) return false;
      $data = json_decode(wp_remote_retrieve_body($response), true);
      return $data['choices'][0]['message']['content'] ?? false;
  }
}
