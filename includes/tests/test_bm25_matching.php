<?php
/**
 * BM25 Direct Matching Test
 *
 * Tests BM25 scoring directly on FAQ question + answer text
 * No automatic tag generation - just pure BM25 ranking
 *
 * Usage: php test_bm25_matching.php
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
echo "  BM25 Direct Matching Test\n";
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

echo "Testing BM25 direct matching (no auto-generated tags)...\n";
echo str_repeat("=", 80) . "\n\n";

// Run tests
$correct = 0;
$total = 0;
$failed_tests = [];
$times = [];

foreach ($test_queries as $i => $query_data) {
    $query = $query_data['query'];
    $expected_id = $query_data['expected_faq_id'];
    $difficulty = $query_data['difficulty'];

    $start = microtime(true);
    $result = ACURCB_Matcher::match($query, 5, $test_faqs);
    $duration = (microtime(true) - $start) * 1000;
    $times[] = $duration;

    $matched_id = $result['id'] ?? null;
    $score = $result['score'] ?? 0;

    $is_correct = ($matched_id === $expected_id);
    $total++;

    if ($is_correct) {
        $correct++;
        $status = "✓";
    } else {
        $status = "✗";
        $failed_tests[] = [
            'query' => $query,
            'expected' => $expected_id,
            'got' => $matched_id,
            'score' => $score,
            'difficulty' => $difficulty
        ];
    }

    // Show first 20 tests in detail
    if ($i < 20) {
        echo sprintf("[%d/%d] %s %s\n",
            $i + 1,
            count($test_queries),
            $status,
            $difficulty
        );
        echo "   Query: \"$query\"\n";
        echo sprintf("   Expected: FAQ #%s | Got: FAQ #%s | Score: %.3f | Time: %.2fms\n",
            $expected_id,
            $matched_id ?? 'NO_MATCH',
            $score,
            $duration
        );
        echo "\n";
    } elseif (($i + 1) % 10 === 0) {
        echo "Tested " . ($i + 1) . "/" . count($test_queries) . " queries...\n";
    }
}

echo "\n";

// Calculate statistics
$accuracy = ($correct / $total) * 100;
$avg_time = array_sum($times) / count($times);
$p95_time = $times[intval(count($times) * 0.95)];
sort($times);
$median_time = $times[intval(count($times) / 2)];

echo "==========================================\n";
echo "  TEST RESULTS\n";
echo "==========================================\n\n";

echo sprintf("Total Tests:        %d\n", $total);
echo sprintf("✓ Correct:          %d (%.1f%%)\n", $correct, $accuracy);
echo sprintf("✗ Wrong:            %d (%.1f%%)\n", $total - $correct, 100 - $accuracy);
echo "\n";

echo "Performance:\n";
echo sprintf("  Average Time:     %.2f ms\n", $avg_time);
echo sprintf("  Median Time:      %.2f ms\n", $median_time);
echo sprintf("  P95 Time:         %.2f ms\n", $p95_time);
echo sprintf("  Total Time:       %.2f ms\n", array_sum($times));
echo sprintf("  Throughput:       %d queries/sec\n", intval(1000 / $avg_time));
echo "\n";

// Accuracy by difficulty
$by_difficulty = ['easy' => ['correct' => 0, 'total' => 0],
                  'medium' => ['correct' => 0, 'total' => 0],
                  'hard' => ['correct' => 0, 'total' => 0]];

foreach ($test_queries as $i => $query_data) {
    $diff = $query_data['difficulty'];
    $expected_id = $query_data['expected_faq_id'];

    $result = ACURCB_Matcher::match($query_data['query'], 5, $test_faqs);
    $matched_id = $result['id'] ?? null;

    $by_difficulty[$diff]['total']++;
    if ($matched_id === $expected_id) {
        $by_difficulty[$diff]['correct']++;
    }
}

echo "Accuracy by Difficulty:\n";
foreach ($by_difficulty as $diff => $stats) {
    if ($stats['total'] > 0) {
        $pct = ($stats['correct'] / $stats['total']) * 100;
        echo sprintf("  %-10s: %d/%d (%.1f%%)\n",
            ucfirst($diff),
            $stats['correct'],
            $stats['total'],
            $pct
        );
    }
}
echo "\n";

// Overall rating
if ($accuracy >= 75) {
    echo "Overall Rating:     EXCELLENT ✓✓✓\n";
} elseif ($accuracy >= 65) {
    echo "Overall Rating:     GOOD ✓✓\n";
} elseif ($accuracy >= 55) {
    echo "Overall Rating:     ACCEPTABLE ✓\n";
} else {
    echo "Overall Rating:     NEEDS IMPROVEMENT ✗\n";
}

echo "\n";

// Show some failed tests
if (!empty($failed_tests) && count($failed_tests) <= 15) {
    echo "==========================================\n";
    echo "  Failed Tests\n";
    echo "==========================================\n\n";

    foreach (array_slice($failed_tests, 0, 15) as $test) {
        echo "Query: \"{$test['query']}\"\n";
        echo "  Expected: FAQ #{$test['expected']}\n";
        echo "  Got:      " . ($test['got'] ?? 'NO_MATCH') . "\n";
        echo "  Score:    " . sprintf("%.3f", $test['score']) . "\n";
        echo "  Difficulty: {$test['difficulty']}\n";
        echo "\n";
    }
}

echo "==========================================\n";
echo "  BM25 Direct Matching Approach\n";
echo "==========================================\n\n";

echo "How it works:\n";
echo "  1. Extract keywords from user query\n";
echo "  2. Extract keywords from FAQ question + answer\n";
echo "  3. Calculate BM25 score based on term matches\n";
echo "  4. Return FAQ with highest BM25 score\n\n";

echo "Benefits:\n";
echo "  ✓ No need to manually create tags\n";
echo "  ✓ No automatic tag generation complexity\n";
echo "  ✓ BM25 handles term frequency and document length naturally\n";
echo "  ✓ FAQ question weighted 2x (appears twice in text)\n";
echo "  ✓ Simple and fast\n\n";

echo "BM25 Formula:\n";
echo "  score = Σ (f(t,d) × (k1 + 1)) / (f(t,d) + k1 × (1 - b + b × |d| / avgdl))\n\n";
echo "  where:\n";
echo "    f(t,d) = term frequency in document\n";
echo "    k1 = 1.5 (term saturation parameter)\n";
echo "    b = 0.75 (length normalization parameter)\n";
echo "    |d| = document length\n";
echo "    avgdl = average document length (50 for FAQs)\n\n";

echo "==========================================\n";
echo "  Recommendations\n";
echo "==========================================\n\n";

if ($accuracy >= 70) {
    echo "✅ BM25 direct matching achieved {$accuracy}% accuracy!\n";
    echo "   This is excellent for FAQ matching.\n\n";
    echo "Next steps:\n";
    echo "  1. Deploy to production\n";
    echo "  2. Monitor real-world performance\n";
    echo "  3. Collect user feedback\n";
} elseif ($accuracy >= 60) {
    echo "✓ BM25 achieved {$accuracy}% accuracy - decent performance\n\n";
    echo "Consider improvements:\n";
    echo "  1. Adjust BM25 parameters:\n";
    echo "     • Try k1 = 2.0 for more term frequency weight\n";
    echo "     • Try b = 0.5 for less length normalization\n";
    echo "  2. Weight FAQ question more (currently 2x)\n";
    echo "  3. Add synonyms (e.g., 'cost' = 'fee')\n";
    echo "  4. Add stemming (e.g., 'presenting' → 'present')\n";
} else {
    echo "⚠️  BM25 achieved {$accuracy}% accuracy - needs improvement\n\n";
    echo "Suggestions:\n";
    echo "  1. Review stopwords - may be removing important terms\n";
    echo "  2. Add synonym support\n";
    echo "  3. Consider hybrid approach:\n";
    echo "     • Manual tags for key concepts\n";
    echo "     • BM25 for detailed matching\n";
    echo "  4. Analyze failed tests to identify patterns\n";
}

echo "\n";
echo "Tuning BM25 Parameters:\n";
echo "  In class-acur-matcher.php, line ~318:\n";
echo "  calculate_bm25_query_score(\$query_terms, \$doc_terms, \$k1 = 1.5, \$b = 0.75)\n";
echo "  \n";
echo "  • k1: Controls term frequency saturation\n";
echo "    - Lower (1.0-1.2): Less emphasis on repeated terms\n";
echo "    - Higher (2.0-3.0): More emphasis on repeated terms\n";
echo "  \n";
echo "  • b: Controls document length normalization\n";
echo "    - Lower (0.0-0.5): Less penalty for long documents\n";
echo "    - Higher (0.75-1.0): More penalty for long documents\n";
echo "\n";

echo "Done! ✨\n";
