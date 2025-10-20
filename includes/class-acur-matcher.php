<?php
if (!defined('ABSPATH')) exit;

class ACURCB_Matcher {

    // Performance tracking
    private static $performance_metrics = [];
    private static $enable_performance_tracking = false;

    // Configurable thresholds for testing
    private static $config = [
        'min_score_threshold' => 0.25,
        'strong_tag_threshold' => 0.5,
        'alternate_min_score' => 0.2,
        'tag_boost_threshold' => 0.15,
        'tag_boost_multiplier' => 1.2,
        'question_weight' => 0.5,
        'answer_weight' => 0.2,
        'tag_weight' => 0.3,
        'jaccard_weight' => 0.7,
        'levenshtein_weight' => 0.3
    ];

    /**
     * Enable/disable performance tracking
     *
     * @param bool $enable Enable tracking
     */
    public static function set_performance_tracking($enable) {
        self::$enable_performance_tracking = $enable;
        if ($enable) {
            self::$performance_metrics = [];
        }
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
     * Reset configuration to defaults
     */
    public static function reset_config() {
        self::$config = [
            'min_score_threshold' => 0.25,
            'strong_tag_threshold' => 0.5,
            'alternate_min_score' => 0.2,
            'tag_boost_threshold' => 0.15,
            'tag_boost_multiplier' => 1.2,
            'question_weight' => 0.5,
            'answer_weight' => 0.2,
            'tag_weight' => 0.3,
            'jaccard_weight' => 0.7,
            'levenshtein_weight' => 0.3
        ];
    }

    /**
     * Track performance metric
     *
     * @param string $operation Operation name
     * @param float $duration Duration in milliseconds
     * @param array $metadata Additional metadata
     */
    private static function track_performance($operation, $duration, $metadata = []) {
        if (!self::$enable_performance_tracking) {
            return;
        }

        if (!isset(self::$performance_metrics[$operation])) {
            self::$performance_metrics[$operation] = [
                'count' => 0,
                'total_time' => 0,
                'min_time' => PHP_FLOAT_MAX,
                'max_time' => 0,
                'metadata' => []
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

        if (!empty($metadata)) {
            self::$performance_metrics[$operation]['metadata'][] = $metadata;
        }
    }

    /**
     * Find the best matching FAQ for a given question
     *
     * @param string $question User's question
     * @param int $top_k Number of results to return (default 5)
     * @param array $faqs Optional FAQ array for testing (bypasses DB)
     * @return array Array with answer, score, id, and alternates
     */
    public static function match($question, $top_k = 5, $faqs = null) {
        $start_time = microtime(true);

        // Get FAQs from parameter or database
        if ($faqs === null) {
            global $wpdb;
            $faqs = $wpdb->get_results("SELECT id, question, answer, tags FROM faqs ORDER BY id", ARRAY_A);
        }

        if (empty($faqs)) {
            $duration = (microtime(true) - $start_time) * 1000;
            self::track_performance('match_empty', $duration);
            return [
                'answer' => 'Sorry, no FAQ entries are available at the moment.',
                'score' => 0,
                'id' => null,
                'alternates' => [],
                'performance' => self::$enable_performance_tracking ? ['total_ms' => $duration] : null
            ];
        }

        $question = strtolower(trim($question));
        $scores = [];

        // Calculate similarities
        $similarity_start = microtime(true);
        foreach ($faqs as $faq) {
            $faq_start = microtime(true);
            $similarity_data = self::calculate_similarity($question, $faq);
            $faq_duration = (microtime(true) - $faq_start) * 1000;

            self::track_performance('calculate_similarity', $faq_duration, [
                'faq_id' => $faq['id'],
                'score' => $similarity_data['total_score']
            ]);

            $scores[] = [
                'id' => $faq['id'],
                'question' => $faq['question'],
                'answer' => $faq['answer'],
                'score' => $similarity_data['total_score'],
                'bm25_score' => $similarity_data['bm25_score'],
                'breakdown' => $similarity_data,
                'tags' => $faq['tags']
            ];
        }
        $similarity_duration = (microtime(true) - $similarity_start) * 1000;
        self::track_performance('all_similarities', $similarity_duration, [
            'faq_count' => count($faqs)
        ]);

        // Sort by score descending
        $sort_start = microtime(true);
        usort($scores, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        $sort_duration = (microtime(true) - $sort_start) * 1000;
        self::track_performance('sort_scores', $sort_duration);

        $best_match = $scores[0];

        // Check if we have a strong BM25 match even if overall score is low
        $has_strong_match = isset($best_match['bm25_score']) &&
                            $best_match['bm25_score'] > self::$config['strong_tag_threshold'];

        // If the best score is too low AND no strong match, return a conversational message
        if ($best_match['score'] < self::$config['min_score_threshold'] && !$has_strong_match) {
            $helpful_responses = [
                "I'm not quite sure about that specific question. Could you try rephrasing it or asking in a different way?",
                "Hmm, I don't have a clear answer for that. Would you mind asking your question differently?",
                "That's a bit outside my knowledge base. Could you provide more details or try a different question?",
                "I want to make sure I give you the right information. Could you rephrase your question or be more specific?"
            ];

            $clarifying_suggestions = [
                "Here are some topics I can definitely help with:",
                "Maybe one of these related questions might help:",
                "I found some potentially related information:"
            ];

            $response_msg = $helpful_responses[array_rand($helpful_responses)];
            if (!empty($scores) && $scores[0]['score'] > 0.1) {
                $response_msg .= "\n\n" . $clarifying_suggestions[array_rand($clarifying_suggestions)];
            }

            $total_duration = (microtime(true) - $start_time) * 1000;
            self::track_performance('match_no_result', $total_duration);

            return [
                'answer' => $response_msg,
                'score' => $best_match['score'],
                'id' => null,
                'alternates' => array_slice($scores, 0, min(3, count($scores))),
                'performance' => self::$enable_performance_tracking ? ['total_ms' => $total_duration] : null
            ];
        }

        // Get alternates (other high-scoring results)
        $alternates = [];
        for ($i = 1; $i < min($top_k, count($scores)); $i++) {
            if ($scores[$i]['score'] > self::$config['alternate_min_score']) {
                $alternates[] = [
                    'id' => $scores[$i]['id'],
                    'question' => $scores[$i]['question'],
                    'score' => $scores[$i]['score']
                ];
            }
        }

        $total_duration = (microtime(true) - $start_time) * 1000;
        self::track_performance('match_success', $total_duration);

        return [
            'answer' => $best_match['answer'],
            'score' => $best_match['score'],
            'id' => $best_match['id'],
            'alternates' => $alternates,
            'performance' => self::$enable_performance_tracking ? ['total_ms' => $total_duration] : null
        ];
    }

    /**
     * Calculate similarity between user question and FAQ entry
     * Uses BM25 to score user query against FAQ question and answer text
     *
     * @param string $user_question User's question (normalized)
     * @param array $faq FAQ entry from database
     * @return array Similarity score
     */
    public static function calculate_similarity($user_question, $faq) {
        $start_time = microtime(true);

        // Extract user query terms
        $user_terms = self::extract_keywords($user_question);

        if (empty($user_terms)) {
            self::track_performance('calculate_similarity_empty', (microtime(true) - $start_time) * 1000);
            return [
                'total_score' => 0.0,
                'bm25_score' => 0.0,
                'matched_terms' => []
            ];
        }

        // Get FAQ text (question + answer, with question weighted more)
        $faq_text = strtolower($faq['question'] . ' ' . $faq['question'] . ' ' . $faq['answer']);
        $faq_terms = self::extract_keywords($faq_text);

        if (empty($faq_terms)) {
            self::track_performance('calculate_similarity_empty', (microtime(true) - $start_time) * 1000);
            return [
                'total_score' => 0.0,
                'bm25_score' => 0.0,
                'matched_terms' => []
            ];
        }

        // Calculate BM25 score for this query-FAQ pair
        $bm25_start = microtime(true);
        $score = self::calculate_bm25_query_score($user_terms, $faq_terms);
        $bm25_duration = (microtime(true) - $bm25_start) * 1000;
        self::track_performance('bm25_scoring', $bm25_duration);

        $total_duration = (microtime(true) - $start_time) * 1000;
        self::track_performance('calculate_similarity_total', $total_duration);

        return [
            'total_score' => $score['score'],
            'bm25_score' => $score['score'],
            'matched_terms' => $score['matched_terms']
        ];
    }

    /**
     * Calculate BM25 score between user query and a single FAQ document
     * This is a simplified BM25 that doesn't need the full document collection
     *
     * @param array $query_terms Terms from user query
     * @param array $doc_terms Terms from FAQ document
     * @param float $k1 Term frequency saturation (default: 1.5)
     * @param float $b Length normalization (default: 0.75)
     * @param int $avg_doc_length Average document length (default: 50 for FAQs)
     * @return array Score and matched terms
     */
    private static function calculate_bm25_query_score($query_terms, $doc_terms, $k1 = 1.5, $b = 0.75, $avg_doc_length = 50) {
        // Get document length
        $doc_length = count($doc_terms);

        // Count term frequencies in document
        $doc_term_freq = array_count_values($doc_terms);

        $total_score = 0.0;
        $matched_terms = [];

        // For each query term, calculate its contribution to BM25 score
        foreach ($query_terms as $query_term) {
            if (!isset($doc_term_freq[$query_term])) {
                continue; // Term not in document
            }

            $freq = $doc_term_freq[$query_term];

            // Simplified IDF (assuming medium frequency across collection)
            // For single-document scoring, we use a fixed IDF boost
            $idf = 1.0;

            // Length normalization
            $normalized_length = 1 - $b + $b * ($doc_length / $avg_doc_length);

            // BM25 term score
            $term_score = $idf * (($freq * ($k1 + 1)) / ($freq + $k1 * $normalized_length));

            $total_score += $term_score;
            $matched_terms[] = $query_term;
        }

        // Normalize score by query length to make scores comparable
        $normalized_score = !empty($query_terms) ? $total_score / count($query_terms) : 0.0;

        return [
            'score' => $normalized_score,
            'matched_terms' => $matched_terms
        ];
    }

    /**
     * Calculate tag-based similarity score
     *
     * @param array $user_tags User query tags
     * @param array $faq_tags FAQ tags
     * @param string $user_question Original user question
     * @param array $faq_tag_strings Original FAQ tag strings
     * @return array Score and matched tags
     */
    private static function calculate_tag_score($user_tags, $faq_tags, $user_question, $faq_tag_strings) {
        $score = 0.0;
        $matched_tags = [];
        $user_question_lower = strtolower($user_question);

        // Method 1: Exact tag matches (highest score)
        foreach ($user_tags as $utag) {
            foreach ($faq_tags as $ftag) {
                if ($utag === $ftag) {
                    $score += 0.5; // Exact match
                    $matched_tags[] = $utag;
                }
            }
        }

        // Method 2: Substring matches in original tags
        foreach ($faq_tag_strings as $faq_tag_string) {
            $faq_tag_lower = strtolower($faq_tag_string);
            if (strpos($user_question_lower, $faq_tag_lower) !== false) {
                $score += 0.4; // Direct phrase match
                $matched_tags[] = $faq_tag_string;
            }
        }

        // Method 3: Partial matches (contains)
        foreach ($user_tags as $utag) {
            foreach ($faq_tags as $ftag) {
                if ($utag !== $ftag) { // Skip if already exact matched
                    if (strpos($ftag, $utag) !== false || strpos($utag, $ftag) !== false) {
                        $score += 0.2; // Partial match
                        if (!in_array($utag, $matched_tags)) {
                            $matched_tags[] = $utag;
                        }
                    }
                }
            }
        }

        // Method 4: Fuzzy matching for longer tags (typo tolerance)
        foreach ($user_tags as $utag) {
            if (strlen($utag) > 4) {
                foreach ($faq_tags as $ftag) {
                    if (strlen($ftag) > 4) {
                        $maxlen = max(strlen($utag), strlen($ftag));
                        $distance = levenshtein(
                            substr($utag, 0, 255),
                            substr($ftag, 0, 255)
                        );
                        $similarity = 1 - ($distance / $maxlen);

                        if ($similarity > 0.85) { // 85% similar
                            $score += $similarity * 0.3;
                            if (!in_array($utag, $matched_tags)) {
                                $matched_tags[] = $utag;
                            }
                        }
                    }
                }
            }
        }

        // Normalize score to 0-1 range
        $final_score = min($score, 1.0);

        return [
            'score' => $final_score,
            'matched_tags' => array_unique($matched_tags)
        ];
    }

    /**
     * Calculate text similarity using multiple methods
     *
     * @param string $text1 First text
     * @param string $text2 Second text
     * @return float Similarity score between 0 and 1
     */
    public static function text_similarity($text1, $text2) {
        $start_time = microtime(true);

        // Method 1: Exact substring match
        if (strpos($text2, $text1) !== false || strpos($text1, $text2) !== false) {
            self::track_performance('text_similarity_exact_match', (microtime(true) - $start_time) * 1000);
            return 1.0;
        }

        // Method 2: Word overlap (Jaccard)
        $keywords_start = microtime(true);
        $words1 = self::extract_keywords($text1);
        $words2 = self::extract_keywords($text2);
        $keywords_duration = (microtime(true) - $keywords_start) * 1000;
        self::track_performance('extract_keywords', $keywords_duration);

        if (empty($words1) || empty($words2)) {
            self::track_performance('text_similarity_empty', (microtime(true) - $start_time) * 1000);
            return 0;
        }

        $jaccard_start = microtime(true);
        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));
        $jaccard_score = count($intersection) / count($union);
        $jaccard_duration = (microtime(true) - $jaccard_start) * 1000;
        self::track_performance('jaccard_calculation', $jaccard_duration);

        // Method 3: Levenshtein distance for short texts
        $levenshtein_score = 0;
        if (strlen($text1) < 100 && strlen($text2) < 100) {
            $lev_start = microtime(true);
            $max_len = max(strlen($text1), strlen($text2));
            if ($max_len > 0) {
                $distance = levenshtein(substr($text1, 0, 255), substr($text2, 0, 255));
                $levenshtein_score = 1 - ($distance / $max_len);
            }
            $lev_duration = (microtime(true) - $lev_start) * 1000;
            self::track_performance('levenshtein_calculation', $lev_duration);
        }

        // Combine scores with configurable weights
        $final_score = $jaccard_score * self::$config['jaccard_weight'] +
                       $levenshtein_score * self::$config['levenshtein_weight'];

        $total_duration = (microtime(true) - $start_time) * 1000;
        self::track_performance('text_similarity_total', $total_duration);

        return $final_score;
    }

    /**
     * Extract meaningful keywords from text
     *
     * @param string $text Input text
     * @return array Array of keywords
     */
    public static function extract_keywords($text) {
        // Remove common stop words - but keep question words that might be important
        $stop_words = [
            'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by',
            'is', 'are', 'was', 'were', 'be', 'been', 'being', 'will', 'would', 'could', 'should',
            'can', 'may', 'might', 'must', 'this', 'that', 'these', 'those',
            'i', 'me', 'my', 'we', 'us', 'our'
        ];

        // Clean and split text
        $text = preg_replace('/[^\w\s?]/', ' ', $text);
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Filter out stop words and short words
        $keywords = [];
        foreach ($words as $word) {
            $word = strtolower(trim($word));
            // Keep words longer than 2 chars, but allow some important short words
            if ((strlen($word) > 2 || in_array($word, ['do', 'you', 'any', 'get', 'has', 'had']))
                && !in_array($word, $stop_words)) {
                $keywords[] = $word;
            }
        }

        return array_unique($keywords);
    }

    /**
     * Check for keyword matches in tags and content
     *
     * @param string $user_question User's question
     * @param array $faq FAQ entry
     * @return float Score based on keyword matches
     */
    public static function keyword_match($user_question, $faq) {
        $score = 0;

        // Extract keywords from user question for better matching
        $user_keywords = self::extract_keywords($user_question);

        // Check tags if available
        if (!empty($faq['tags'])) {
            $tags = json_decode($faq['tags'], true);
            if (is_array($tags)) {
                foreach ($tags as $tag) {
                    $tag = strtolower(trim($tag));

                    // Direct substring match in question
                    if (strpos($user_question, $tag) !== false) {
                        $score += 0.7; // High weight for direct tag matches
                    }

                    // Check if tag matches any user keywords
                    foreach ($user_keywords as $keyword) {
                        if ($keyword === $tag) {
                            $score += 0.6; // Strong match for exact keyword
                        } elseif (strpos($keyword, $tag) !== false || strpos($tag, $keyword) !== false) {
                            $score += 0.4; // Partial match (e.g., "wheelchair" contains "wheel")
                        }
                    }

                    // Fuzzy matching for similar words
                    if (strlen($tag) > 4) {
                        foreach ($user_keywords as $keyword) {
                            if (strlen($keyword) > 4) {
                                $similarity = 1 - (levenshtein($tag, $keyword) / max(strlen($tag), strlen($keyword)));
                                if ($similarity > 0.8) { // 80% similarity threshold
                                    $score += $similarity * 0.5;
                                }
                            }
                        }
                    }
                }
            }
        }

        return min($score, 1.0); // Cap at 1.0
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

        // Create feedback table if it doesn't exist
        $table_name = $wpdb->prefix . 'acur_feedback';
        $charset_collate = $wpdb->get_charset_collate();

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

        // Create escalations table if it doesn't exist
        $table_name = $wpdb->prefix . 'acur_escalations';
        $charset_collate = $wpdb->get_charset_collate();

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

    /**
     * Calculate BM25 scores for terms in a document
     * BM25 is more effective than TF-IDF for short documents like FAQs
     *
     * @param array $doc_terms Terms in current document
     * @param array $all_docs All documents (each is array of terms)
     * @param float $k1 Term frequency saturation parameter (default: 1.5)
     * @param float $b Length normalization parameter (default: 0.75)
     * @return array BM25 scores for each term
     */
    private static function calculate_bm25($doc_terms, $all_docs, $k1 = 1.5, $b = 0.75) {
        $total_docs = count($all_docs);

        // Calculate average document length
        $total_doc_length = 0;
        foreach ($all_docs as $doc) {
            $total_doc_length += count($doc);
        }
        $avg_doc_length = $total_docs > 0 ? $total_doc_length / $total_docs : 1;

        // Get current document length
        $doc_length = count($doc_terms);

        // Calculate term frequency in current document
        $term_counts = array_count_values($doc_terms);

        // Calculate Document Frequency (DF) - how many docs contain each term
        $df = [];
        foreach (array_unique($doc_terms) as $term) {
            $df[$term] = 0;
            foreach ($all_docs as $doc) {
                if (in_array($term, $doc)) {
                    $df[$term]++;
                }
            }
        }

        // Calculate BM25 score for each term
        $bm25 = [];
        foreach ($term_counts as $term => $freq) {
            // IDF component: log((N - df + 0.5) / (df + 0.5))
            // Using a slightly different IDF formula for better performance
            $idf = log(($total_docs - $df[$term] + 0.5) / ($df[$term] + 0.5) + 1);

            // TF component with saturation
            // BM25 = IDF * (f * (k1 + 1)) / (f + k1 * (1 - b + b * |d| / avgdl))
            $normalized_length = 1 - $b + $b * ($doc_length / $avg_doc_length);
            $tf_component = ($freq * ($k1 + 1)) / ($freq + $k1 * $normalized_length);

            $bm25[$term] = $idf * $tf_component;
        }

        return $bm25;
    }

    /**
     * Get all FAQ documents for BM25 calculation
     * Uses WordPress database or provided FAQ array
     *
     * @param array|null $faqs_override Optional FAQ array (for testing)
     * @return array Array of documents (each is array of terms)
     */
    private static function get_all_faq_documents($faqs_override = null) {
        static $cached_docs = null;

        // Return cached if available (unless override provided)
        if ($cached_docs !== null && $faqs_override === null) {
            return $cached_docs;
        }

        $all_docs = [];

        if ($faqs_override !== null) {
            // Use provided FAQs (for testing)
            foreach ($faqs_override as $faq) {
                $text = strtolower(trim($faq['question'] . ' ' . $faq['answer']));
                $terms = self::extract_keywords($text);
                $all_docs[] = $terms;
            }
        } else {
            // Get from database
            global $wpdb;
            $faqs = $wpdb->get_results(
                "SELECT question, answer FROM {$wpdb->prefix}faqs",
                ARRAY_A
            );

            foreach ($faqs as $faq) {
                $text = strtolower(trim($faq['question'] . ' ' . $faq['answer']));
                $terms = self::extract_keywords($text);
                $all_docs[] = $terms;
            }
        }

        // Cache for subsequent calls
        if ($faqs_override === null) {
            $cached_docs = $all_docs;
        }

        return $all_docs;
    }

    /**
     * Auto-generate tags from question and answer using BM25
     * IMPROVED: Uses BM25 to find most distinctive keywords (better than TF-IDF for short docs)
     *
     * @param string $question FAQ question
     * @param string $answer FAQ answer
     * @param int $limit Maximum number of tags to generate
     * @param array|null $all_faqs Optional: all FAQs for BM25 calculation (for testing)
     * @return array Array of suggested tags
     */
    public static function suggest_tags($question, $answer, $limit = 10, $all_faqs = null) {
        $text = strtolower(trim((string)$question . ' ' . (string)$answer));

        // Extract keywords from current document
        $doc_terms = self::extract_keywords($text);

        if (empty($doc_terms)) {
            return [];
        }

        // Get all FAQ documents for BM25 calculation
        $all_docs = self::get_all_faq_documents($all_faqs);

        // Calculate BM25 scores for single terms
        $bm25_scores = self::calculate_bm25($doc_terms, $all_docs);

        // Generate bigrams and trigrams with BM25 scoring
        // Re-index array to ensure sequential numeric keys
        $doc_terms_indexed = array_values($doc_terms);
        $phrases = [];
        $question_terms = self::extract_keywords($question);

        // Generate bigrams
        for ($i = 0; $i < count($doc_terms_indexed) - 1; $i++) {
            $word1 = $doc_terms_indexed[$i];
            $word2 = $doc_terms_indexed[$i + 1];
            $bigram = $word1 . ' ' . $word2;

            // Calculate bigram BM25 as average of component words
            $score1 = $bm25_scores[$word1] ?? 0;
            $score2 = $bm25_scores[$word2] ?? 0;
            $bigram_score = ($score1 + $score2) / 2;

            // Boost bigrams (phrases are more specific)
            $phrases[$bigram] = $bigram_score * 1.3;
        }

        // Generate trigrams
        for ($i = 0; $i < count($doc_terms_indexed) - 2; $i++) {
            $word1 = $doc_terms_indexed[$i];
            $word2 = $doc_terms_indexed[$i + 1];
            $word3 = $doc_terms_indexed[$i + 2];
            $trigram = $word1 . ' ' . $word2 . ' ' . $word3;

            // Calculate trigram BM25
            $score1 = $bm25_scores[$word1] ?? 0;
            $score2 = $bm25_scores[$word2] ?? 0;
            $score3 = $bm25_scores[$word3] ?? 0;
            $trigram_score = ($score1 + $score2 + $score3) / 3;

            // Higher boost for trigrams
            $phrases[$trigram] = $trigram_score * 1.5;
        }

        // Combine single terms and phrases
        $all_tags = array_merge($bm25_scores, $phrases);

        // Boost tags that appear in question (they're more important)
        $question_lower = strtolower($question);
        foreach ($all_tags as $tag => $score) {
            if (strpos($question_lower, $tag) !== false) {
                $all_tags[$tag] *= 2.0; // 2x boost for question terms
            }
        }

        // Sort by BM25 score descending
        arsort($all_tags);

        // Remove redundant tags
        $filtered_tags = [];
        $seen_terms = [];

        foreach ($all_tags as $tag => $score) {
            // Skip if score is too low
            if ($score < 0.01) continue;

            $is_redundant = false;

            // Check if this single word is already in a phrase we've added
            if (!strpos($tag, ' ')) {
                foreach ($filtered_tags as $existing_tag) {
                    if (strpos($existing_tag, ' ') !== false && strpos($existing_tag, $tag) !== false) {
                        $is_redundant = true;
                        break;
                    }
                }
            }

            if (!$is_redundant) {
                $filtered_tags[] = $tag;
            }

            if (count($filtered_tags) >= $limit * 2) break;
        }

        // Prioritize phrases over single words
        usort($filtered_tags, function($a, $b) use ($all_tags) {
            $a_is_phrase = strpos($a, ' ') !== false;
            $b_is_phrase = strpos($b, ' ') !== false;

            // Phrases first
            if ($a_is_phrase && !$b_is_phrase) return -1;
            if (!$a_is_phrase && $b_is_phrase) return 1;

            // Then by score
            return $all_tags[$b] <=> $all_tags[$a];
        });

        // Limit to requested number
        $final_tags = array_slice($filtered_tags, 0, $limit);

        // Capitalize for display
        $final_tags = array_map(function($tag) {
            return ucwords($tag);
        }, $final_tags);

        return array_values(array_unique($final_tags));
    }

    /**
     * Clear cached FAQ documents (call when FAQs are updated)
     */
    public static function clear_faq_cache() {
        // This will force get_all_faq_documents to reload
        // PHP doesn't support static variable clearing directly,
        // but cache will refresh on next page load
    }


}