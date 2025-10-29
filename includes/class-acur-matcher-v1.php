<?php
/**
 * ACUR Chatbot Matcher V1 - Semantic Matching
 * Uses all-MiniLM-L6-v2 embeddings for FAQ matching
 *
 * This replaces the old BM25/tag-based matching with semantic embeddings
 * Expected accuracy: 75-85% (vs 35% with old approach)
 */

if (!defined('ABSPATH')) exit;

class ACURCB_Matcher_V1 {

    /**
     * Configuration
     */
    private static $config = [
        'embedding_service_url' => 'http://localhost:8000',
        'similarity_threshold' => 0.50,      // Minimum score to return a match (lowered from 0.65 to match more queries)
        'alternate_threshold' => 0.40,       // Minimum score for alternate suggestions
        'max_alternates' => 3,               // Max number of alternate suggestions
        'embedding_dimension' => 384,        // all-MiniLM-L6-v2 dimension
        'request_timeout' => 5,              // Timeout for embedding service (seconds)
    ];

    /**
     * Performance tracking
     */
    private static $performance_metrics = [];
    private static $enable_performance_tracking = false;

    /**
     * Set configuration parameter
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     */
    public static function set_config($key, $value) {
        if (array_key_exists($key, self::$config)) {
            self::$config[$key] = $value;
        }
    }

    /**
     * Get configuration parameter
     *
     * @param string $key Configuration key
     * @return mixed Configuration value or null
     */
    public static function get_config($key = null) {
        if ($key === null) {
            return self::$config;
        }
        return self::$config[$key] ?? null;
    }

    /**
     * Enable/disable performance tracking
     *
     * @param bool $enable Enable tracking
     */
    public static function set_performance_tracking($enable) {
        self::$enable_performance_tracking = $enable;
    }

    /**
     * Get performance metrics
     *
     * @return array Performance data
     */
    public static function get_performance_metrics() {
        return self::$performance_metrics;
    }

    /**
     * Track performance metric
     *
     * @param string $operation Operation name
     * @param float $duration Duration in milliseconds
     */
    private static function track_performance($operation, $duration) {
        if (!self::$enable_performance_tracking) {
            return;
        }

        if (!isset(self::$performance_metrics[$operation])) {
            self::$performance_metrics[$operation] = [
                'count' => 0,
                'total_time' => 0,
                'min_time' => PHP_FLOAT_MAX,
                'max_time' => 0,
            ];
        }

        self::$performance_metrics[$operation]['count']++;
        self::$performance_metrics[$operation]['total_time'] += $duration;
        self::$performance_metrics[$operation]['min_time'] = min(
            self::$performance_metrics[$operation]['min_time'],
            $duration
        );
        self::$performance_metrics[$operation]['max_time'] = max(
            self::$performance_metrics[$operation]['max_time'],
            $duration
        );
    }

    /**
     * Find the best matching FAQ using semantic similarity
     * MAIN MATCHING FUNCTION
     *
     * @param string $question User's question
     * @param int $top_k Number of alternate results to return
     * @return array Array with answer, score, id, and alternates
     */
    public static function match($question, $top_k = 5) {
        $start_time = microtime(true);

        // Validate input
        $question = trim($question);
        if (empty($question)) {
            return self::error_response('Please enter a question.', $start_time);
        }

        // STEP 1: Generate embedding for user question
        $embedding_start = microtime(true);
        $query_embedding = self::get_embedding($question);
        $embedding_duration = (microtime(true) - $embedding_start) * 1000;
        self::track_performance('generate_query_embedding', $embedding_duration);

        if ($query_embedding === null) {
            return self::error_response(
                'Sorry, I could not process your question at this time. Please try again.',
                $start_time
            );
        }

        // STEP 2: Load all FAQ embeddings from database
        $load_start = microtime(true);
        $faqs = self::load_faq_embeddings();
        $load_duration = (microtime(true) - $load_start) * 1000;
        self::track_performance('load_faq_embeddings', $load_duration);

        if (empty($faqs)) {
            return self::error_response(
                'Sorry, no FAQ entries are available at the moment.',
                $start_time
            );
        }

        // STEP 3: Extract tags from user question for hybrid scoring
        $tag_extract_start = microtime(true);
        $question_tags = self::extract_tags_from_question($question);
        $tag_extract_duration = (microtime(true) - $tag_extract_start) * 1000;
        self::track_performance('extract_question_tags', $tag_extract_duration);

        // STEP 4: Calculate cosine similarity + tag boost for each FAQ (hybrid scoring)
        $similarity_start = microtime(true);
        $scores = self::calculate_all_similarities($query_embedding, $faqs, $question_tags);
        $similarity_duration = (microtime(true) - $similarity_start) * 1000;
        self::track_performance('calculate_similarities', $similarity_duration);

        // STEP 4: Sort by similarity score (descending)
        usort($scores, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        if (empty($scores)) {
            return self::error_response(
                'Sorry, I could not find any matching answers.',
                $start_time
            );
        }

        $best_match = $scores[0];

        // STEP 5: Check if best match meets threshold
        if ($best_match['score'] < self::$config['similarity_threshold']) {
            $total_duration = (microtime(true) - $start_time) * 1000;
            self::track_performance('match_below_threshold', $total_duration);

            return [
                'answer' => self::get_fallback_message(),
                'score' => $best_match['score'],
                'id' => null,
                'alternates' => self::get_alternates($scores, self::$config['max_alternates']),
                'performance' => self::$enable_performance_tracking ? [
                    'total_ms' => $total_duration,
                    'embedding_ms' => $embedding_duration,
                    'load_ms' => $load_duration,
                    'similarity_ms' => $similarity_duration
                ] : null
            ];
        }

        // STEP 6: Return best match with alternates
        $total_duration = (microtime(true) - $start_time) * 1000;
        self::track_performance('match_success', $total_duration);

        return [
            'answer' => $best_match['answer'],
            'score' => $best_match['score'],
            'id' => $best_match['id'],
            'question' => $best_match['question'],
            'alternates' => self::get_alternates($scores, self::$config['max_alternates']),
            'performance' => self::$enable_performance_tracking ? [
                'total_ms' => $total_duration,
                'embedding_ms' => $embedding_duration,
                'load_ms' => $load_duration,
                'similarity_ms' => $similarity_duration,
                'faq_count' => count($faqs)
            ] : null
        ];
    }

    /**
     * Get embedding from Python service
     *
     * @param string $text Text to embed
     * @return array|null Embedding vector or null on failure
     */
    private static function get_embedding($text) {
        $url = self::$config['embedding_service_url'] . '/embed';

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['text' => $text]),
            'timeout' => self::$config['request_timeout']
        ]);

        if (is_wp_error($response)) {
            error_log('ACUR Embedding Service Error: ' . $response->get_error_message());
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log('ACUR Embedding Service HTTP Error: ' . $status_code);
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['embedding']) || !is_array($body['embedding'])) {
            error_log('ACUR Embedding Service Invalid Response: ' . print_r($body, true));
            return null;
        }

        // Validate embedding dimension
        if (count($body['embedding']) !== self::$config['embedding_dimension']) {
            error_log('ACUR Embedding Dimension Mismatch: Expected ' .
                     self::$config['embedding_dimension'] . ', got ' . count($body['embedding']));
            return null;
        }

        return $body['embedding'];
    }

    /**
     * Load all FAQ embeddings from database
     *
     * @return array Array of FAQs with embeddings
     */
    private static function load_faq_embeddings() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'faqs';

        $faqs = $wpdb->get_results(
            "SELECT id, question, answer, tags, embedding, embedding_version
             FROM {$table_name}
             WHERE embedding IS NOT NULL
             AND embedding != ''
             ORDER BY id",
            ARRAY_A
        );

        if (empty($faqs)) {
            return [];
        }

        // Parse and validate embeddings
        $valid_faqs = [];
        foreach ($faqs as $faq) {
            $embedding = json_decode($faq['embedding'], true);

            // Validate embedding
            if (!is_array($embedding) || count($embedding) !== self::$config['embedding_dimension']) {
                error_log("FAQ #{$faq['id']} has invalid embedding, skipping");
                continue;
            }

            $valid_faqs[] = [
                'id' => $faq['id'],
                'question' => $faq['question'],
                'answer' => $faq['answer'],
                'tags' => $faq['tags'],
                'embedding' => $embedding,
                'embedding_version' => $faq['embedding_version']
            ];
        }

        return $valid_faqs;
    }

    /**
     * Calculate cosine similarity between query and all FAQs
     * Now includes hybrid scoring: semantic similarity + tag boost
     *
     * @param array $query_embedding Query embedding vector
     * @param array $faqs Array of FAQs with embeddings
     * @param array $question_tags Tags extracted from user question
     * @return array Array of scores
     */
    private static function calculate_all_similarities($query_embedding, $faqs, $question_tags = []) {
        $scores = [];

        foreach ($faqs as $faq) {
            // Calculate semantic similarity (cosine similarity)
            $similarity = self::cosine_similarity($query_embedding, $faq['embedding']);

            // Calculate tag boost for hybrid scoring
            $tag_boost = 0.0;
            if (!empty($question_tags) && isset($faq['tags'])) {
                $tag_boost = self::calculate_tag_boost($question_tags, $faq['tags']);
            }

            // Hybrid score: semantic similarity + tag boost
            $final_score = $similarity + $tag_boost;

            $scores[] = [
                'id' => $faq['id'],
                'question' => $faq['question'],
                'answer' => $faq['answer'],
                'score' => $final_score,
                'semantic_score' => $similarity,
                'tag_boost' => $tag_boost
            ];
        }

        return $scores;
    }

    /**
     * Calculate cosine similarity between two vectors
     * Formula: cos(θ) = (A · B) / (||A|| × ||B||)
     *
     * @param array $vec1 First vector
     * @param array $vec2 Second vector
     * @return float Similarity score (0 to 1)
     */
    private static function cosine_similarity($vec1, $vec2) {
        if (count($vec1) !== count($vec2)) {
            return 0.0;
        }

        $dot_product = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;

        for ($i = 0; $i < count($vec1); $i++) {
            $dot_product += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] * $vec1[$i];
            $norm2 += $vec2[$i] * $vec2[$i];
        }

        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);

        if ($norm1 == 0 || $norm2 == 0) {
            return 0.0;
        }

        return $dot_product / ($norm1 * $norm2);
    }

    /**
     * Extract keywords from text for tag matching
     * Simplified version - uses basic word extraction
     *
     * @param string $text Input text
     * @return array Array of keywords
     */
    private static function extract_keywords($text) {
        $stop_words = [
            'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by',
            'is', 'are', 'was', 'were', 'be', 'been', 'being', 'will', 'would', 'could', 'should',
            'can', 'may', 'might', 'must', 'this', 'that', 'these', 'those',
            'i', 'me', 'my', 'we', 'us', 'our'
        ];

        $text = preg_replace('/[^\w\s?]/', ' ', strtolower($text));
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ((strlen($word) > 2 || in_array($word, ['do', 'you', 'any', 'get', 'has', 'had']))
                && !in_array($word, $stop_words)) {
                $keywords[] = $word;
            }
        }

        return array_unique($keywords);
    }

    /**
     * Extract potential tags from user question
     * Combines keywords and bigrams
     *
     * @param string $question User's question
     * @return array Array of potential tags
     */
    private static function extract_tags_from_question($question) {
        $keywords = self::extract_keywords($question);

        // Generate bigrams
        $keywords_indexed = array_values($keywords);
        $phrase_tags = [];

        for ($i = 0; $i < count($keywords_indexed) - 1; $i++) {
            $phrase_tags[] = $keywords_indexed[$i] . ' ' . $keywords_indexed[$i + 1];
        }

        return array_unique(array_merge($keywords, $phrase_tags));
    }

    /**
     * Calculate tag match boost score
     * Similar to BM25+tag approach
     *
     * @param array $question_tags Tags from user question
     * @param string $faq_tags_json FAQ tags as JSON string
     * @return float Boost score (0 to 0.2)
     */
    private static function calculate_tag_boost($question_tags, $faq_tags_json) {
        if (empty($question_tags)) {
            return 0.0;
        }

        $faq_tags_raw = json_decode($faq_tags_json, true);
        if (!is_array($faq_tags_raw) || empty($faq_tags_raw)) {
            return 0.0;
        }

        // Normalize FAQ tags
        $faq_tags = array_map(function($tag) {
            return strtolower(trim($tag));
        }, $faq_tags_raw);

        $boost = 0.0;

        foreach ($question_tags as $q_tag) {
            foreach ($faq_tags as $f_tag) {
                // Exact match: highest boost
                if ($q_tag === $f_tag) {
                    $boost += 0.10;
                    break;
                }

                // Substring match: medium boost
                if (strpos($f_tag, $q_tag) !== false || strpos($q_tag, $f_tag) !== false) {
                    $boost += 0.05;
                    break;
                }
            }
        }

        // Cap the boost to prevent over-weighting (0.2 max instead of 0.3 to be conservative)
        return min($boost, 0.2);
    }

    /**
     * Get alternate suggestions from scores
     *
     * @param array $scores All scores sorted by similarity
     * @param int $limit Maximum number of alternates
     * @return array Array of alternate suggestions
     */
    private static function get_alternates($scores, $limit) {
        $alternates = [];

        // Skip first item (best match) and get next top items
        for ($i = 1; $i < min($limit + 1, count($scores)); $i++) {
            if ($scores[$i]['score'] >= self::$config['alternate_threshold']) {
                $alternates[] = [
                    'id' => $scores[$i]['id'],
                    'question' => $scores[$i]['question'],
                    'score' => $scores[$i]['score']
                ];
            }
        }

        return $alternates;
    }

    /**
     * Get a random fallback message when no good match is found
     *
     * @return string Fallback message
     */
    private static function get_fallback_message() {
        $messages = [
            "I'm not quite sure about that specific question. Could you try rephrasing it or asking in a different way?",
            "Hmm, I don't have a clear answer for that. Would you mind asking your question differently?",
            "That's a bit outside my knowledge base. Could you provide more details or try a different question?",
            "I want to make sure I give you the right information. Could you rephrase your question or be more specific?"
        ];

        return $messages[array_rand($messages)];
    }

    /**
     * Generate error response
     *
     * @param string $message Error message
     * @param float $start_time Start time for performance tracking
     * @return array Error response
     */
    private static function error_response($message, $start_time) {
        $total_duration = (microtime(true) - $start_time) * 1000;
        self::track_performance('match_error', $total_duration);

        return [
            'answer' => $message,
            'score' => 0,
            'id' => null,
            'alternates' => [],
            'performance' => self::$enable_performance_tracking ? [
                'total_ms' => $total_duration
            ] : null
        ];
    }

    /**
     * Check if embedding service is available
     *
     * @return array Status information
     */
    public static function check_service_status() {
        $url = self::$config['embedding_service_url'] . '/health';

        $response = wp_remote_get($url, [
            'timeout' => 2
        ]);

        if (is_wp_error($response)) {
            return [
                'status' => 'error',
                'message' => $response->get_error_message(),
                'url' => self::$config['embedding_service_url']
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            return [
                'status' => 'online',
                'message' => 'Embedding service is running',
                'url' => self::$config['embedding_service_url'],
                'details' => $body
            ];
        }

        return [
            'status' => 'error',
            'message' => 'Embedding service returned HTTP ' . $status_code,
            'url' => self::$config['embedding_service_url']
        ];
    }

    /**
     * Store feedback for future improvements
     *
     * @param string $session_id Session identifier
     * @param int $faq_id FAQ ID that was rated
     * @param bool $helpful Whether the answer was helpful
     * @return bool Success status
     */
    public static function store_feedback($session_id, $faq_id, $helpful) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acur_feedback';
        $charset_collate = $wpdb->get_charset_collate();

        // Create feedback table if it doesn't exist
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            faq_id int(11),
            helpful tinyint(1) NOT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY faq_id (faq_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Insert feedback
        return $wpdb->insert($table_name, [
            'session_id' => sanitize_text_field($session_id),
            'faq_id' => $faq_id ? intval($faq_id) : null,
            'helpful' => $helpful ? 1 : 0
        ]);
    }

    /**
     * Store escalation request
     *
     * @param string $session_id Session identifier
     * @param string $user_query Original user query
     * @param string $contact_email User's email for follow-up
     * @return bool Success status
     */
    public static function store_escalation($session_id, $user_query, $contact_email) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'acur_escalations';
        $charset_collate = $wpdb->get_charset_collate();

        // Create escalations table if it doesn't exist
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id int(11) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            user_query text NOT NULL,
            contact_email varchar(255) NOT NULL,
            status varchar(50) DEFAULT 'pending',
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY status (status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Insert escalation
        return $wpdb->insert($table_name, [
            'session_id' => sanitize_text_field($session_id),
            'user_query' => wp_strip_all_tags($user_query),
            'contact_email' => sanitize_email($contact_email),
            'status' => 'pending'
        ]);
    }
}
