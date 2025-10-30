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
  }


  

  static function match($req) {
    $question = sanitize_text_field($req['q']);
    $session_id = sanitize_text_field($req['session_id'] ?: '');

    if (empty($question)) {
      return new WP_REST_Response(['detail' => 'Question parameter is required'], 400);
    }

    // Use BM25 matcher instead of semantic matcher
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
}