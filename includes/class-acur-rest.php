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

  private static function api_base() {
    return trailingslashit( ACURCB_Settings::get('api_base') );
  }
  private static function headers() {
    $h = ['Content-Type'=>'application/json'];
    $sec = ACURCB_Settings::get('shared_secret');
    if ($sec) $h['X-ACUR-Secret'] = $sec;
    return $h;
  }

  static function match($req) {
  $api = self::api_base();
  if (!$api) return new WP_REST_Response(['detail'=>'FastAPI base URL not configured'], 502);

  // Ensure exactly one slash before 'match'
  $url = add_query_arg([
    'q'          => $req['q'],
    'session_id' => $req['session_id'] ?: '',
    'top_k'      => 5,
  ], trailingslashit($api) . 'match');

  // MUST be GET (not wp_remote_post)
  $r = wp_remote_get($url, ['timeout'=>10]);
  if (is_wp_error($r)) return new WP_REST_Response(['detail'=>$r->get_error_message()], 502);

  $code = wp_remote_retrieve_response_code($r);
  $body = wp_remote_retrieve_body($r);
  $json = json_decode($body, true);
  if (!is_array($json)) $json = ['detail'=>'Bad backend JSON','raw'=>$body];
  return new WP_REST_Response($json, $code ?: 502);
}



  static function feedback($req) {
    $api = self::api_base(); if (!$api) return new WP_Error('no_api','Not configured', ['status'=>500]);
    $r = wp_remote_post($api.'feedback', [
      'timeout'=>8, 'headers'=>self::headers(),
      'body'=> wp_json_encode([
        'session_id' => sanitize_text_field($req->get_param('session_id')),
        'faq_id'     => sanitize_text_field($req->get_param('faq_id')),
        'helpful'    => (bool)$req->get_param('helpful'),
      ])
    ]);
    if (is_wp_error($r)) return $r;
    $code = wp_remote_retrieve_response_code($r);
    return new WP_REST_Response(json_decode(wp_remote_retrieve_body($r), true), $code);
  }

  static function escalate($req) {
    $api = self::api_base(); if (!$api) return new WP_Error('no_api','Not configured', ['status'=>500]);
    $r = wp_remote_post($api.'escalate', [
      'timeout'=>8, 'headers'=>self::headers(),
      'body'=> wp_json_encode([
        'session_id'    => sanitize_text_field($req->get_param('session_id')),
        'user_query'    => wp_strip_all_tags($req->get_param('user_query')),
        'contact_email' => sanitize_email($req->get_param('contact_email')),
      ])
    ]);
    if (is_wp_error($r)) return $r;
    $code = wp_remote_retrieve_response_code($r);
    return new WP_REST_Response(json_decode(wp_remote_retrieve_body($r), true), $code);
  }
}
