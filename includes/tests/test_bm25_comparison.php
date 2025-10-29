<?php
/**
 * BM25 vs TF-IDF Comparison Test
 *
 * Compares matching accuracy with BM25 vs the old TF-IDF implementation
 * Shows which algorithm performs better for FAQ matching
 *
 * Usage: php test_bm25_comparison.php
 */

// Mock WordPress functions
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string) { return strip_tags($string); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return trim(strip_tags($str)); }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email) { return filter_var($email, FILTER_SANITIZE_EMAIL); }
}
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once __DIR__ . '/class-acur-matcher.php';

echo "==========================================\n";
echo "  BM25 vs TF-IDF Comparison\n";
echo "==========================================\n\n";

// Load test FAQs
$faqs_json = file_get_contents(__DIR__ . '/test_data_faqs.json');
if (!$faqs_json) {
    echo "❌ Error: Could not load test_data_faqs.json\n";
    exit(1);
}

$test_faqs = json_decode($faqs_json, true);
echo "Loaded " . count($test_faqs) . " test FAQs\n";

// Load test queries
$queries_json = file_get_contents(__DIR__ . '/test_data_queries.json');
if (!$queries_json) {
    echo "❌ Error: Could not load test_data_queries.json\n";
    exit(1);
}

$test_queries = json_decode($queries_json, true);
echo "Loaded " . count($test_queries) . " test queries\n\n";

// First, regenerate all FAQ tags with BM25 (current implementation)
echo "Regenerating tags with BM25...\n";
$faqs_with_bm25_tags = [];
foreach ($test_faqs as $faq) {
    $new_tags = ACURCB_Matcher::suggest_tags(
        $faq['question'],
        $faq['answer'],
        10,
        $test_faqs
    );
    $faqs_with_bm25_tags[] = [
        'id' => $faq['id'],
        'question' => $faq['question'],
        'answer' => $faq['answer'],
        'tags' => json_encode($new_tags)
    ];
}
echo "✓ Generated BM25 tags for all FAQs\n\n";

// Use original tags from database (which were TF-IDF generated)
$faqs_with_original_tags = $test_faqs;

// Run matching tests with both tag sets
echo "==========================================\n";
echo "  Running Matching Tests\n";
echo "==========================================\n\n";

$correct_original = 0;
$correct_bm25 = 0;
$failed_tests = [];

$sample_size = min(30, count($test_queries)); // Test with first 30 queries

foreach (array_slice($test_queries, 0, $sample_size) as $i => $query_data) {
    $query = $query_data['query'];
    $expected_id = $query_data['expected_faq_id'];

    // Test with original tags
    $result_original = ACURCB_Matcher::match($query, 5, $faqs_with_original_tags);
    $matched_original = ($result_original['id'] ?? null) === $expected_id;
    if ($matched_original) {
        $correct_original++;
    }

    // Test with BM25 tags
    $result_bm25 = ACURCB_Matcher::match($query, 5, $faqs_with_bm25_tags);
    $matched_bm25 = ($result_bm25['id'] ?? null) === $expected_id;
    if ($matched_bm25) {
        $correct_bm25++;
    }

    // Track failed tests
    if (!$matched_bm25) {
        $failed_tests[] = [
            'query' => $query,
            'expected' => $expected_id,
            'got_original' => $result_original['id'] ?? 'NO_MATCH',
            'got_bm25' => $result_bm25['id'] ?? 'NO_MATCH',
            'difficulty' => $query_data['difficulty']
        ];
    }

    // Show progress
    if (($i + 1) % 10 === 0) {
        echo "Tested " . ($i + 1) . "/$sample_size queries...\n";
    }
}

echo "\n";

// Calculate accuracies
$accuracy_original = ($correct_original / $sample_size) * 100;
$accuracy_bm25 = ($correct_bm25 / $sample_size) * 100;
$improvement = $accuracy_bm25 - $accuracy_original;

echo "==========================================\n";
echo "  RESULTS\n";
echo "==========================================\n\n";

echo sprintf("Original Tags:  %d/%d correct (%.1f%%)\n",
    $correct_original, $sample_size, $accuracy_original);
echo sprintf("BM25 Tags:      %d/%d correct (%.1f%%)\n",
    $correct_bm25, $sample_size, $accuracy_bm25);
echo sprintf("Improvement:    %+.1f%%\n\n", $improvement);

if ($improvement > 5) {
    echo "✅ BM25 is significantly better! (+$improvement%)\n";
    echo "   Recommendation: Deploy BM25 to production\n";
} elseif ($improvement > 0) {
    echo "✓ BM25 is slightly better (+$improvement%)\n";
    echo "   Recommendation: Test with more queries\n";
} elseif ($improvement === 0) {
    echo "➖ No change in accuracy\n";
    echo "   Recommendation: Investigate tag quality\n";
} else {
    echo "⚠️  BM25 is worse ($improvement%)\n";
    echo "   Recommendation: Review BM25 parameters (k1, b)\n";
}

echo "\n";

// Show some example tag differences
echo "==========================================\n";
echo "  Tag Comparison Examples\n";
echo "==========================================\n\n";

$sample_faqs = [4, 7, 12, 16, 20];
foreach ($sample_faqs as $faq_id) {
    // Find FAQ
    $original_faq = null;
    $bm25_faq = null;

    foreach ($faqs_with_original_tags as $faq) {
        if ($faq['id'] == $faq_id) {
            $original_faq = $faq;
            break;
        }
    }

    foreach ($faqs_with_bm25_tags as $faq) {
        if ($faq['id'] == $faq_id) {
            $bm25_faq = $faq;
            break;
        }
    }

    if (!$original_faq || !$bm25_faq) continue;

    echo "FAQ #$faq_id: " . substr($original_faq['question'], 0, 50) . "...\n";
    echo str_repeat("-", 60) . "\n";

    $original_tags = json_decode($original_faq['tags'], true);
    $bm25_tags = json_decode($bm25_faq['tags'], true);

    echo "Original: " . implode(', ', array_slice($original_tags, 0, 5)) . "\n";
    echo "BM25:     " . implode(', ', array_slice($bm25_tags, 0, 5)) . "\n\n";
}

// Show failed tests
if (!empty($failed_tests) && count($failed_tests) <= 10) {
    echo "==========================================\n";
    echo "  Failed Tests (BM25)\n";
    echo "==========================================\n\n";

    foreach (array_slice($failed_tests, 0, 10) as $test) {
        echo "Query: \"{$test['query']}\"\n";
        echo "  Expected: FAQ #{$test['expected']}\n";
        echo "  Got (BM25): {$test['got_bm25']}\n";
        echo "  Difficulty: {$test['difficulty']}\n\n";
    }
}

// Explanation
echo "==========================================\n";
echo "  What is BM25?\n";
echo "==========================================\n\n";

echo "BM25 (Best Matching 25) is a ranking function that improves on TF-IDF:\n\n";

echo "Key Differences:\n";
echo "  1. Term Frequency Saturation\n";
echo "     • TF-IDF: Linear increase with term frequency\n";
echo "     • BM25: Diminishing returns (prevents over-weighting repeated terms)\n\n";

echo "  2. Document Length Normalization\n";
echo "     • TF-IDF: No length normalization\n";
echo "     • BM25: Adjusts for document length (fairer for short FAQs)\n\n";

echo "  3. Tunable Parameters\n";
echo "     • k1 (default 1.5): Controls term frequency saturation\n";
echo "     • b (default 0.75): Controls length normalization strength\n\n";

echo "Why BM25 is Better for FAQs:\n";
echo "  ✓ Short documents (FAQs) benefit from length normalization\n";
echo "  ✓ Prevents over-weighting of repeated terms\n";
echo "  ✓ More robust IDF calculation\n";
echo "  ✓ Industry-standard for information retrieval\n\n";

echo "Formula:\n";
echo "  BM25 = IDF × (f × (k1 + 1)) / (f + k1 × (1 - b + b × |d| / avgdl))\n\n";
echo "  where:\n";
echo "    f = term frequency\n";
echo "    |d| = document length\n";
echo "    avgdl = average document length\n";
echo "    k1 = 1.5 (term saturation)\n";
echo "    b = 0.75 (length normalization)\n\n";

// Recommendations
echo "==========================================\n";
echo "  Next Steps\n";
echo "==========================================\n\n";

if ($improvement > 0) {
    echo "1. ✅ BM25 improved accuracy by {$improvement}%\n";
    echo "2. Run full test suite: php test_matcher_simple.php\n";
    echo "3. If accuracy is ≥70%, regenerate all FAQ tags in database\n";
    echo "4. Monitor real-world performance\n";
} else {
    echo "1. ⚠️  BM25 didn't improve accuracy\n";
    echo "2. Try adjusting parameters:\n";
    echo "   • Increase k1 (1.5 → 2.0) for more term frequency weight\n";
    echo "   • Decrease b (0.75 → 0.5) for less length normalization\n";
    echo "3. Review tag generation quality\n";
    echo "4. Consider other improvements (synonyms, stemming)\n";
}

echo "\n";
echo "Tuning BM25 Parameters:\n";
echo "  In class-acur-matcher.php, line ~621:\n";
echo "  private static function calculate_bm25(\$doc_terms, \$all_docs, \$k1 = 1.5, \$b = 0.75)\n";
echo "  \n";
echo "  Try different values:\n";
echo "  • k1 = 1.2 (less saturation, more linear)\n";
echo "  • k1 = 2.0 (more saturation, term frequency matters less)\n";
echo "  • b = 0.5 (less length normalization)\n";
echo "  • b = 1.0 (full length normalization)\n\n";

echo "Done! ✨\n";
