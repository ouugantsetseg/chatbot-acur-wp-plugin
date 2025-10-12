<?php
if (!defined('ABSPATH')) exit;

class ACURCB_Matcher {

    /**
     * Find the best matching FAQ for a given question
     *
     * @param string $question User's question
     * @param int $top_k Number of results to return (default 5)
     * @return array Array with answer, score, id, and alternates
     */
    public static function match($question, $top_k = 5) {
        global $wpdb;

        // Get all FAQs from database
        $faqs = $wpdb->get_results("SELECT id, question, answer, tags FROM faqs ORDER BY id", ARRAY_A);

        if (empty($faqs)) {
            return [
                'answer' => 'Sorry, no FAQ entries are available at the moment.',
                'score' => 0,
                'id' => null,
                'alternates' => []
            ];
        }

        $question = strtolower(trim($question));
        $scores = [];

        foreach ($faqs as $faq) {
            $similarity_data = self::calculate_similarity($question, $faq);
            $scores[] = [
                'id' => $faq['id'],
                'question' => $faq['question'],
                'answer' => $faq['answer'],
                'score' => $similarity_data['total_score'],
                'tag_score' => $similarity_data['tag_score'],
                'tags' => $faq['tags']
            ];
        }

        // Sort by score descending
        usort($scores, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $best_match = $scores[0];

        // Check if we have a strong tag match even if overall score is low
        $has_strong_tag_match = isset($best_match['tag_score']) && $best_match['tag_score'] > 0.5;

        // If the best score is too low AND no strong tag match, return a conversational message
        if ($best_match['score'] < 0.25 && !$has_strong_tag_match) {
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

            return [
                'answer' => $response_msg,
                'score' => $best_match['score'],
                'id' => null,
                'alternates' => array_slice($scores, 0, min(3, count($scores)))
            ];
        }

        // Get alternates (other high-scoring results)
        $alternates = [];
        for ($i = 1; $i < min($top_k, count($scores)); $i++) {
            if ($scores[$i]['score'] > 0.2) { // Only include decent alternatives
                $alternates[] = [
                    'id' => $scores[$i]['id'],
                    'question' => $scores[$i]['question'],
                    'score' => $scores[$i]['score']
                ];
            }
        }

        return [
            'answer' => $best_match['answer'],
            'score' => $best_match['score'],
            'id' => $best_match['id'],
            'alternates' => $alternates
        ];
    }

    /**
     * Calculate similarity between user question and FAQ entry
     *
     * @param string $user_question User's question (normalized)
     * @param array $faq FAQ entry from database
     * @return float Similarity score between 0 and 1
     */
    private static function calculate_similarity($user_question, $faq) {
        $faq_question = strtolower($faq['question']);
        $faq_answer = strtolower($faq['answer']);

        // Weight different matching methods
        $question_score = self::text_similarity($user_question, $faq_question) * 0.5;
        $answer_score = self::text_similarity($user_question, $faq_answer) * 0.2;
        $tag_score = self::keyword_match($user_question, $faq) * 0.3; // Increased weight for tags

        $total_score = $question_score + $answer_score + $tag_score;

        // Boost score if we have strong tag matches
        if ($tag_score > 0.15) { // If tag matching contributed significantly
            $total_score = min($total_score * 1.2, 1.0); // 20% boost, capped at 1.0
        }

        return [
            'total_score' => $total_score,
            'question_score' => $question_score,
            'answer_score' => $answer_score,
            'tag_score' => $tag_score
        ];
    }

    /**
     * Calculate text similarity using multiple methods
     *
     * @param string $text1 First text
     * @param string $text2 Second text
     * @return float Similarity score between 0 and 1
     */
    private static function text_similarity($text1, $text2) {
        // Method 1: Exact substring match
        if (strpos($text2, $text1) !== false || strpos($text1, $text2) !== false) {
            return 1.0;
        }

        // Method 2: Word overlap
        $words1 = self::extract_keywords($text1);
        $words2 = self::extract_keywords($text2);

        if (empty($words1) || empty($words2)) {
            return 0;
        }

        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));

        $jaccard_score = count($intersection) / count($union);

        // Method 3: Levenshtein distance for short texts
        $levenshtein_score = 0;
        if (strlen($text1) < 100 && strlen($text2) < 100) {
            $max_len = max(strlen($text1), strlen($text2));
            if ($max_len > 0) {
                $distance = levenshtein(substr($text1, 0, 255), substr($text2, 0, 255));
                $levenshtein_score = 1 - ($distance / $max_len);
            }
        }

        // Combine scores with weights
        return $jaccard_score * 0.7 + $levenshtein_score * 0.3;
    }

    /**
     * Extract meaningful keywords from text
     *
     * @param string $text Input text
     * @return array Array of keywords
     */
    private static function extract_keywords($text) {
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
    private static function keyword_match($user_question, $faq) {
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

    public static function suggest_tags($question, $answer, $limit = 8) {
    global $wpdb;

    $text = strtolower( trim( (string)$question . ' ' . (string)$answer ) );

    // --- domain stopwords (add/tune freely) ---
    $STOP = [
        'a','an','and','the','is','are','was','were','be','been','being','to','of','in','on','for','with','by','at',
        'from','it','that','this','these','those','as','or','if','then','but','so','than','such','may','can','could',
        'should','would','will','about','into','within','acur','conference','western','sydney','university','campus',
        'please','thanks','thank','you','we','our','your','i','me','my','us','they','them','their'
    ];

    // Known tags (from your own data) to boost real domain phrases
    $known_tag_set = [];
    $rows = $wpdb->get_col("SELECT tags FROM faqs WHERE tags IS NOT NULL");
    if ($rows) {
        foreach ($rows as $js) {
            $arr = json_decode($js, true);
            if (is_array($arr)) {
                foreach ($arr as $t) {
                    $t = strtolower(trim($t));
                    if ($t !== '') $known_tag_set[$t] = true;
                }
            }
        }
    }

    // Tokenize
    preg_match_all('/[a-z0-9][a-z0-9\-]+/u', $text, $m);
    $tokens = $m[0] ?? [];

    // Keep clean words
    $clean = [];
    foreach ($tokens as $w) {
        $w = trim($w, "- \t\n\r\0\x0B");
        if ($w === '' || strlen($w) < 3) continue;
        if (is_numeric($w)) continue;
        if (in_array($w, $STOP, true)) continue;
        $clean[] = $w;
    }

    // Unigrams + simple bigrams to capture phrases (“hearing loop”, “wheelchair access”)
    $scores = [];
    $bigrams = [];
    for ($i=0; $i < count($clean); $i++) {
        $w = $clean[$i];

        // base score from frequency
        $scores[$w] = ($scores[$w] ?? 0) + 1.0;

        // bigram
        if ($i+1 < count($clean)) {
            $w2 = $clean[$i+1];
            // avoid repeating the exact same word twice
            if ($w !== $w2) {
                $bg = $w . ' ' . $w2;
                // prefer meaningful bigrams (both >=3 chars already guaranteed)
                $bigrams[$bg] = ($bigrams[$bg] ?? 0) + 1.0;
            }
        }
    }

    // Merge bigrams and add a gentle bonus
    foreach ($bigrams as $bg => $cnt) {
        // Slightly more weight for phrases
        $scores[$bg] = ($scores[$bg] ?? 0) + (1.2 * $cnt);
    }

    // Boost items that match your existing tags
    foreach (array_keys($scores) as $k) {
        if (isset($known_tag_set[$k])) {
            $scores[$k] += 2.0; // strong boost for already-used tags
        }
    }

    // Light fuzzy collapsing: if "wheelchair" and "wheelchair access" both exist,
    // keep the phrase and downweight the unigram.
    foreach (array_keys($scores) as $k) {
        if (strpos($k, ' ') !== false) {
            // phrase — downweight its contained tokens a bit
            [$a,$b] = explode(' ', $k, 2);
            if (isset($scores[$a])) $scores[$a] *= 0.9;
            if (isset($scores[$b])) $scores[$b] *= 0.9;
        }
    }

    // Rank
    arsort($scores);

    // Post-filter: remove very generic leftovers and duplicates differing only by hyphen/space
    $out = [];
    $seen_norm = [];
    foreach ($scores as $term => $s) {
        $norm = str_replace('-', ' ', $term);
        if (isset($seen_norm[$norm])) continue;
        $seen_norm[$norm] = true;
        $out[] = $term;
        if (count($out) >= ($limit * 2)) break; // take a wider pool before final trim
    }

    // Final tidy: prefer phrases first, then singles, cap to $limit
    usort($out, function($a, $b) use ($scores) {
        $pa = (strpos($a, ' ') !== false) ? 1 : 0;
        $pb = (strpos($b, ' ') !== false) ? 1 : 0;
        if ($pa !== $pb) return $pb <=> $pa; // phrases first
        return ($scores[$b] <=> $scores[$a]);
    });

    // Make them look nice for admins
    $out = array_map(function($t) {
        return trim(preg_replace('/\s+/', ' ', $t));
    }, $out);

    // Ensure uniqueness and cap
    $out = array_values(array_unique($out));
    return array_slice($out, 0, $limit);
}


}