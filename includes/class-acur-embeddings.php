<?php
/**
 * ACUR Chatbot Embeddings Manager
 * Handles embedding generation and storage for FAQs
 *
 * This class manages:
 * - Generating embeddings when FAQs are created/updated
 * - Batch processing existing FAQs
 * - Communicating with Python embedding service
 */

if (!defined('ABSPATH')) exit;

class ACURCB_Embeddings {

    /**
     * Configuration
     */
    private static $config = [
        'embedding_service_url' => 'https://embedding-service-0o4h.onrender.com',
        'embedding_dimension' => 384,           // all-MiniLM-L6-v2
        'embedding_version' => 'all-MiniLM-L6-v2-v2',  // Increment version when changing
        'request_timeout' => 10,                // Timeout for embedding generation
        'combine_question_answer' => true,      // Combine Q&A for embedding
        'question_weight' => 2,                 // How many times to repeat question (2x to match BM25)
        'include_tags' => true,                 // Include tags in embedding
        'tag_weight' => 1,                      // How many times to repeat tags
    ];

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
     * Generate and store embedding for a FAQ
     * Called when admin creates or updates a FAQ
     *
     * @param int $faq_id FAQ ID
     * @return bool Success status
     */
    public static function generate_faq_embedding($faq_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'faqs';

        // Get FAQ data (include tags)
        $faq = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, question, answer, tags FROM {$table_name} WHERE id = %d",
                $faq_id
            ),
            ARRAY_A
        );

        if (!$faq) {
            error_log("ACUR Embeddings: FAQ #{$faq_id} not found");
            return false;
        }

        // Prepare text for embedding (now includes tags)
        $text = self::prepare_text_for_embedding($faq['question'], $faq['answer'], $faq['tags'] ?? '');

        // Generate embedding
        $embedding = self::get_embedding($text);

        if ($embedding === null) {
            error_log("ACUR Embeddings: Failed to generate embedding for FAQ #{$faq_id}");
            return false;
        }

        // Validate embedding
        if (!is_array($embedding) || count($embedding) !== self::$config['embedding_dimension']) {
            error_log("ACUR Embeddings: Invalid embedding dimension for FAQ #{$faq_id}");
            return false;
        }

        // Store embedding in database
        $result = $wpdb->update(
            $table_name,
            [
                'embedding' => json_encode($embedding),
                'embedding_version' => self::$config['embedding_version'],
                'embedding_updated_at' => current_time('mysql')
            ],
            ['id' => $faq_id]
        );

        if ($result === false) {
            error_log("ACUR Embeddings: Database update failed for FAQ #{$faq_id}");
            return false;
        }

        error_log("ACUR Embeddings: Successfully generated embedding for FAQ #{$faq_id}");
        return true;
    }

    /**
     * Prepare text for embedding by combining question, answer, and tags
     *
     * @param string $question FAQ question
     * @param string $answer FAQ answer
     * @param string $tags FAQ tags (JSON encoded)
     * @return string Combined text
     */
    private static function prepare_text_for_embedding($question, $answer, $tags = '') {
        if (!self::$config['combine_question_answer']) {
            return $question;
        }

        // Repeat question for higher weight (questions are more important)
        $question_repeated = str_repeat($question . ' ', self::$config['question_weight']);

        $text_parts = [$question_repeated, $answer];

        // Add tags if enabled
        if (self::$config['include_tags'] && !empty($tags)) {
            $tags_array = json_decode($tags, true);
            if (is_array($tags_array) && !empty($tags_array)) {
                // Join tags with spaces and repeat for weighting
                $tags_text = implode(' ', $tags_array);
                $tags_repeated = str_repeat($tags_text . ' ', self::$config['tag_weight']);
                $text_parts[] = $tags_repeated;
            }
        }

        return trim(implode(' ', $text_parts));
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
            error_log('ACUR Embedding Service Invalid Response');
            return null;
        }

        return $body['embedding'];
    }

    /**
     * Batch generate embeddings for all FAQs
     * Useful for initial setup or regenerating all embeddings
     *
     * @param bool $force_regenerate Regenerate even if embedding exists
     * @return array Statistics about the batch operation
     */
    public static function batch_generate_all_embeddings($force_regenerate = false) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'faqs';

        // Get FAQs that need embeddings (include tags)
        if ($force_regenerate) {
            $faqs = $wpdb->get_results(
                "SELECT id, question, answer, tags FROM {$table_name}",
                ARRAY_A
            );
        } else {
            $faqs = $wpdb->get_results(
                "SELECT id, question, answer, tags FROM {$table_name}
                 WHERE embedding IS NULL OR embedding = ''",
                ARRAY_A
            );
        }

        if (empty($faqs)) {
            return [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'skipped' => 0,
                'message' => 'No FAQs need embedding generation'
            ];
        }

        $success_count = 0;
        $failed_count = 0;
        $total = count($faqs);

        foreach ($faqs as $faq) {
            if (self::generate_faq_embedding($faq['id'])) {
                $success_count++;
            } else {
                $failed_count++;
            }

            // Log progress
            error_log("ACUR Embeddings: Progress {$success_count}/{$total} FAQs embedded");

            // Small delay to avoid overwhelming the embedding service
            usleep(100000); // 100ms
        }

        return [
            'total' => $total,
            'success' => $success_count,
            'failed' => $failed_count,
            'skipped' => 0,
            'message' => "Embedded {$success_count} out of {$total} FAQs"
        ];
    }

    /**
     * Get embedding statistics from database
     *
     * @return array Statistics
     */
    public static function get_embedding_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'faqs';

        $total_faqs = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        $embedded_faqs = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name}
             WHERE embedding IS NOT NULL AND embedding != ''"
        );

        $missing_embeddings = $total_faqs - $embedded_faqs;

        $embedding_versions = $wpdb->get_results(
            "SELECT embedding_version, COUNT(*) as count
             FROM {$table_name}
             WHERE embedding IS NOT NULL AND embedding != ''
             GROUP BY embedding_version",
            ARRAY_A
        );

        return [
            'total_faqs' => (int) $total_faqs,
            'embedded_faqs' => (int) $embedded_faqs,
            'missing_embeddings' => $missing_embeddings,
            'coverage_percentage' => $total_faqs > 0 ? round(($embedded_faqs / $total_faqs) * 100, 2) : 0,
            'embedding_versions' => $embedding_versions
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
                'status' => 'offline',
                'message' => 'Cannot connect to embedding service: ' . $response->get_error_message(),
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
                'service_info' => $body
            ];
        }

        return [
            'status' => 'error',
            'message' => 'Embedding service returned HTTP ' . $status_code,
            'url' => self::$config['embedding_service_url']
        ];
    }

    /**
     * Delete embedding for a FAQ
     *
     * @param int $faq_id FAQ ID
     * @return bool Success status
     */
    public static function delete_faq_embedding($faq_id) {
        global $wpdb;

        $result = $wpdb->update(
            $wpdb->prefix . 'faqs',
            [
                'embedding' => null,
                'embedding_version' => null,
                'embedding_updated_at' => null
            ],
            ['id' => $faq_id]
        );

        return $result !== false;
    }

    /**
     * Check if FAQ has valid embedding
     *
     * @param int $faq_id FAQ ID
     * @return bool True if has valid embedding
     */
    public static function has_valid_embedding($faq_id) {
        global $wpdb;

        $embedding = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT embedding FROM {$wpdb->prefix}faqs WHERE id = %d",
                $faq_id
            )
        );

        if (empty($embedding)) {
            return false;
        }

        $embedding_array = json_decode($embedding, true);

        return is_array($embedding_array) &&
               count($embedding_array) === self::$config['embedding_dimension'];
    }

    /**
     * Regenerate embeddings for FAQs with outdated version
     *
     * @param string $current_version Current embedding version
     * @return array Statistics
     */
    public static function regenerate_outdated_embeddings($current_version = null) {
        if ($current_version === null) {
            $current_version = self::$config['embedding_version'];
        }

        global $wpdb;

        $table_name = $wpdb->prefix . 'faqs';

        $faqs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM {$table_name}
                 WHERE embedding_version != %s OR embedding_version IS NULL",
                $current_version
            ),
            ARRAY_A
        );

        if (empty($faqs)) {
            return [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'message' => 'All embeddings are up to date'
            ];
        }

        $success_count = 0;
        $failed_count = 0;
        $total = count($faqs);

        foreach ($faqs as $faq) {
            if (self::generate_faq_embedding($faq['id'])) {
                $success_count++;
            } else {
                $failed_count++;
            }

            usleep(100000); // 100ms delay
        }

        return [
            'total' => $total,
            'success' => $success_count,
            'failed' => $failed_count,
            'message' => "Regenerated {$success_count} out of {$total} embeddings"
        ];
    }
}
