<?php
if (!defined('ABSPATH')) exit;

use Nadar\Stemming\Stemm;
use Hug\Synonym\Synonym;

/**
 * Text Processing Helper Class
 * Provides stemming and synonym expansion for improved matching
 */
class ACURCB_TextProcessor {

    private static $stemmer = null;
    private static $synonym = null;
    private static $stemming_enabled = true;
    private static $synonym_expansion_enabled = true;

    /**
     * Initialize the stemmer
     */
    private static function init_stemmer() {
        if (self::$stemmer === null && class_exists('Nadar\Stemming\Stemm')) {
            self::$stemmer = new Stemm();
        }
    }

    /**
     * Initialize the synonym engine
     */
    private static function init_synonym() {
        if (self::$synonym === null && class_exists('Hug\Synonym\Synonym')) {
            self::$synonym = new Synonym();
        }
    }

    /**
     * Enable or disable stemming
     *
     * @param bool $enabled
     */
    public static function set_stemming_enabled($enabled) {
        self::$stemming_enabled = $enabled;
    }

    /**
     * Enable or disable synonym expansion
     *
     * @param bool $enabled
     */
    public static function set_synonym_expansion_enabled($enabled) {
        self::$synonym_expansion_enabled = $enabled;
    }

    /**
     * Stem a single word to its root form
     *
     * @param string $word Word to stem
     * @param string $language Language code (default: 'en' for English)
     * @return string Stemmed word
     */
    public static function stem_word($word, $language = 'en') {
        if (!self::$stemming_enabled) {
            return $word;
        }

        self::init_stemmer();

        if (self::$stemmer !== null) {
            try {
                // The Stemm class requires word and language as parameters
                return self::$stemmer->stem($word, $language);
            } catch (Exception $e) {
                error_log('Stemming error: ' . $e->getMessage());
                return $word;
            }
        }

        return $word;
    }

    /**
     * Stem an array of words
     *
     * @param array $words Array of words to stem
     * @return array Array of stemmed words
     */
    public static function stem_words($words) {
        if (!self::$stemming_enabled || empty($words)) {
            return $words;
        }

        $stemmed = [];
        foreach ($words as $word) {
            $stemmed[] = self::stem_word($word);
        }

        return $stemmed;
    }

    /**
     * Get synonyms for a word
     *
     * @param string $word Word to find synonyms for
     * @param int $limit Maximum number of synonyms to return
     * @return array Array of synonyms
     */
    public static function get_synonyms($word) {
        if (!self::$synonym_expansion_enabled) {
            return [];
        }

        self::init_synonym();

        if (self::$synonym === null) {
            return [];
        }

        try {
            // Get synonyms using the php-synonym library (Hug\Synonym)
            // The library uses Synonym::find() method with word and language
            $synonyms = Synonym::find($word, 'en');

            if (is_array($synonyms) && !empty($synonyms)) {
                // Convert to lowercase and remove duplicates
                $synonyms = array_map('strtolower', $synonyms);
                $synonyms = array_unique($synonyms);
                // Remove the original word from synonyms
                $synonyms = array_filter($synonyms, function($syn) use ($word) {
                    return $syn !== strtolower($word);
                });
                return array_values($synonyms);
            }
        } catch (Exception $e) {
            error_log('Synonym lookup error: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Expand query terms with synonyms
     * Returns original terms + their synonyms
     *
     * @param array $terms Original query terms
     * @param int $max_synonyms_per_term Maximum synonyms per term
     * @return array Expanded terms including original and synonyms
     */
    public static function expand_query_with_synonyms($terms, $max_synonyms_per_term = 3) {
        if (!self::$synonym_expansion_enabled || empty($terms)) {
            return $terms;
        }

        $expanded = [];

        foreach ($terms as $term) {
            // Always include the original term
            $expanded[] = $term;

            // Skip very short words (likely not to have useful synonyms)
            if (strlen($term) <= 3) {
                continue;
            }

            // Get synonyms for this term
            $synonyms = self::get_synonyms($term);

            if (!empty($synonyms)) {
                // Limit synonyms per term to avoid query explosion
                $synonyms = array_slice($synonyms, 0, $max_synonyms_per_term);
                $expanded = array_merge($expanded, $synonyms);
            }
        }

        return array_unique($expanded);
    }

    /**
     * Process text with stemming
     * Extracts keywords and stems them
     *
     * @param string $text Input text
     * @param bool $apply_stemming Whether to apply stemming
     * @return array Array of processed keywords
     */
    public static function process_text($text, $apply_stemming = true) {
        // Stop words to filter out
        $stop_words = [
            'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by',
            'is', 'are', 'was', 'were', 'be', 'been', 'being', 'will', 'would', 'could', 'should',
            'can', 'may', 'might', 'must', 'this', 'that', 'these', 'those',
            'i', 'me', 'my', 'we', 'us', 'our'
        ];

        // Clean and split text
        $text = strtolower($text);
        $text = preg_replace('/[^\w\s?]/', ' ', $text);
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Filter out stop words and short words
        $keywords = [];
        foreach ($words as $word) {
            $word = trim($word);
            // Keep words longer than 2 chars, but allow some important short words
            if ((strlen($word) > 2 || in_array($word, ['do', 'you', 'any', 'get', 'has', 'had']))
                && !in_array($word, $stop_words)) {
                $keywords[] = $word;
            }
        }

        // Apply stemming if enabled
        if ($apply_stemming && self::$stemming_enabled) {
            $keywords = self::stem_words($keywords);
        }

        return array_unique($keywords);
    }

    /**
     * Enhanced keyword extraction with optional stemming and synonym expansion
     *
     * @param string $text Input text
     * @param bool $apply_stemming Whether to apply stemming
     * @param bool $expand_synonyms Whether to expand with synonyms
     * @param int $max_synonyms Maximum synonyms per term
     * @return array Array of processed keywords
     */
    public static function extract_enhanced_keywords($text, $apply_stemming = true, $expand_synonyms = false, $max_synonyms = 2) {
        // Get base keywords
        $keywords = self::process_text($text, $apply_stemming);

        // Optionally expand with synonyms
        if ($expand_synonyms && self::$synonym_expansion_enabled) {
            $keywords = self::expand_query_with_synonyms($keywords, $max_synonyms);
        }

        return $keywords;
    }

    /**
     * Calculate similarity between two texts with stemming support
     *
     * @param string $text1 First text
     * @param string $text2 Second text
     * @param bool $use_stemming Whether to use stemming
     * @return float Similarity score between 0 and 1
     */
    public static function calculate_similarity_with_stemming($text1, $text2, $use_stemming = true) {
        // Extract keywords with stemming
        $keywords1 = self::process_text($text1, $use_stemming);
        $keywords2 = self::process_text($text2, $use_stemming);

        if (empty($keywords1) || empty($keywords2)) {
            return 0.0;
        }

        // Calculate Jaccard similarity
        $intersection = array_intersect($keywords1, $keywords2);
        $union = array_unique(array_merge($keywords1, $keywords2));

        return count($intersection) / count($union);
    }

    /**
     * Check if required libraries are available
     *
     * @return array Status of each library
     */
    public static function check_libraries() {
        return [
            'stemming' => class_exists('Nadar\Stemming\Stemm'),
            'synonym' => class_exists('Hug\Synonym\Synonym')
        ];
    }
}
