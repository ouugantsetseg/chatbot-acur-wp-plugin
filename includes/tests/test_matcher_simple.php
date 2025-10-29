<?php
/**
 * Simple Matcher Test Script
 * Tests the simplified tag-only matching logic
 *
 * Usage: php test_matcher_simple.php
 */

// Mock WordPress functions for standalone testing
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
echo "  Simple Tag-Only Matcher Test\n";
echo "==========================================\n\n";

// Load test data
$faqs_json = file_get_contents(__DIR__ . '/test_data_faqs.json');
$queries_json = file_get_contents(__DIR__ . '/test_data_queries.json');

if (!$faqs_json || !$queries_json) {
    echo "❌ Error: Could not load test data files\n";
    echo "   Make sure test_data_faqs.json and test_data_queries.json exist\n";
    exit(1);
}

$test_faqs = json_decode($faqs_json, true);
$test_queries = json_decode($queries_json, true);

echo "Loaded " . count($test_faqs) . " test FAQs\n";
echo "Loaded " . count($test_queries) . " test queries\n\n";

// Enable performance tracking
ACURCB_Matcher::set_performance_tracking(true);

// Test statistics
$total_tests = 0;
$correct_matches = 0;
$incorrect_matches = 0;
$no_matches = 0;
$total_time = 0;

$results_by_difficulty = [
    'easy' => ['total' => 0, 'correct' => 0],
    'medium' => ['total' => 0, 'correct' => 0],
    'hard' => ['total' => 0, 'correct' => 0]
];

$failed_tests = [];

echo "Running tests...\n";
echo str_repeat("-", 80) . "\n\n";

foreach ($test_queries as $idx => $test) {
    $total_tests++;
    $query = $test['query'];
    $expected_id = $test['expected_faq_id'];
    $difficulty = $test['difficulty'];
    $query_type = $test['query_type'];

    // Track by difficulty
    $results_by_difficulty[$difficulty]['total']++;

    // Run matcher
    $start = microtime(true);
    $result = ACURCB_Matcher::match($query, 5, $test_faqs);
    $duration = (microtime(true) - $start) * 1000;
    $total_time += $duration;

    $matched_id = $result['id'];
    $score = $result['score'];
    $is_correct = ($matched_id === $expected_id);

    // Update statistics
    if ($matched_id === null) {
        $no_matches++;
        $status = "❌ NO MATCH";
    } elseif ($is_correct) {
        $correct_matches++;
        $results_by_difficulty[$difficulty]['correct']++;
        $status = "✓ CORRECT";
    } else {
        $incorrect_matches++;
        $status = "✗ WRONG";
    }

    // Print result
    $progress = sprintf("[%d/%d]", $total_tests, count($test_queries));
    echo sprintf("%s %s %s\n", $progress, $status, $query_type);
    echo sprintf("   Query: \"%s\"\n", substr($query, 0, 60));
    echo sprintf("   Expected: FAQ #%d | Got: %s | Score: %.3f | Time: %.2fms\n",
        $expected_id,
        $matched_id ? "FAQ #$matched_id" : "NULL",
        $score,
        $duration
    );

    // Show matched tags if available
    if (isset($result['matched_tags']) && !empty($result['matched_tags'])) {
        echo sprintf("   Matched Tags: %s\n", implode(', ', array_slice($result['matched_tags'], 0, 5)));
    }

    echo "\n";

    // Store failed tests for detailed review
    if (!$is_correct) {
        $failed_tests[] = [
            'query' => $query,
            'expected' => $expected_id,
            'got' => $matched_id,
            'score' => $score,
            'difficulty' => $difficulty,
            'type' => $query_type
        ];
    }

    // Pause every 10 tests for readability (optional)
    if ($total_tests % 10 === 0 && $total_tests < count($test_queries)) {
        echo str_repeat("-", 80) . "\n\n";
    }
}

// Print Summary
echo "\n";
echo "==========================================\n";
echo "  TEST RESULTS SUMMARY\n";
echo "==========================================\n\n";

$accuracy = $total_tests > 0 ? ($correct_matches / $total_tests) * 100 : 0;
$avg_time = $total_tests > 0 ? $total_time / $total_tests : 0;

echo sprintf("Total Tests:        %d\n", $total_tests);
echo sprintf("✓ Correct:          %d (%.1f%%)\n", $correct_matches, $accuracy);
echo sprintf("✗ Wrong:            %d (%.1f%%)\n", $incorrect_matches, ($incorrect_matches / $total_tests) * 100);
echo sprintf("❌ No Match:        %d (%.1f%%)\n", $no_matches, ($no_matches / $total_tests) * 100);
echo "\n";

echo sprintf("Average Time:       %.3f ms\n", $avg_time);
echo sprintf("Total Time:         %.2f ms\n", $total_time);
echo sprintf("Throughput:         %.0f queries/sec\n", 1000 / $avg_time);
echo "\n";

// Accuracy by difficulty
echo "Accuracy by Difficulty:\n";
foreach ($results_by_difficulty as $diff => $stats) {
    if ($stats['total'] > 0) {
        $diff_accuracy = ($stats['correct'] / $stats['total']) * 100;
        echo sprintf("  %-10s: %d/%d (%.1f%%)\n",
            ucfirst($diff),
            $stats['correct'],
            $stats['total'],
            $diff_accuracy
        );
    }
}
echo "\n";

// Performance rating
if ($accuracy >= 70) {
    $rating = "EXCELLENT ✓✓✓";
    $color = "\033[32m"; // Green
} elseif ($accuracy >= 50) {
    $rating = "GOOD ✓✓";
    $color = "\033[33m"; // Yellow
} elseif ($accuracy >= 35) {
    $rating = "ACCEPTABLE ✓";
    $color = "\033[33m"; // Yellow
} else {
    $rating = "NEEDS IMPROVEMENT ✗";
    $color = "\033[31m"; // Red
}
$reset_color = "\033[0m";

echo "Overall Rating:     {$color}{$rating}{$reset_color}\n\n";

// Show performance metrics
echo "Performance Breakdown:\n";
$metrics = ACURCB_Matcher::get_performance_metrics();
$key_metrics = ['tag_matching', 'calculate_similarity_total', 'match_success'];
foreach ($key_metrics as $key) {
    if (isset($metrics[$key])) {
        $m = $metrics[$key];
        $avg = $m['total_time'] / $m['count'];
        echo sprintf("  %-30s: Avg %.3f ms (calls: %d)\n",
            ucfirst(str_replace('_', ' ', $key)),
            $avg,
            $m['count']
        );
    }
}
echo "\n";

// Failed tests detail
if (!empty($failed_tests)) {
    echo "==========================================\n";
    echo "  FAILED TESTS DETAIL (First 10)\n";
    echo "==========================================\n\n";

    foreach (array_slice($failed_tests, 0, 10) as $i => $fail) {
        echo sprintf("%d. Query: \"%s\"\n", $i + 1, $fail['query']);
        echo sprintf("   Expected: FAQ #%d | Got: %s | Score: %.3f\n",
            $fail['expected'],
            $fail['got'] ? "FAQ #{$fail['got']}" : "NULL",
            $fail['score']
        );
        echo sprintf("   Type: %s | Difficulty: %s\n\n",
            $fail['type'],
            $fail['difficulty']
        );
    }

    if (count($failed_tests) > 10) {
        echo sprintf("... and %d more failed tests\n\n", count($failed_tests) - 10);
    }
}

// Recommendations
echo "==========================================\n";
echo "  RECOMMENDATIONS\n";
echo "==========================================\n\n";

if ($accuracy < 60) {
    echo "⚠ Accuracy below 60%:\n";
    echo "  • Review tag generation - tags may not be comprehensive enough\n";
    echo "  • Consider increasing tag limit from 10 to 15-20\n";
    echo "  • Check if stopwords are filtering too aggressively\n";
    echo "  • Review failed tests for patterns\n\n";
}

if ($no_matches > $total_tests * 0.1) {
    echo "⚠ High no-match rate (>10%):\n";
    echo "  • Consider lowering min_score_threshold from 0.25 to 0.20\n";
    echo "  • Check if tags are too specific\n";
    echo "  • Add more synonym support\n\n";
}

if ($avg_time > 1.0) {
    echo "⚠ Average time above 1ms:\n";
    echo "  • Performance is acceptable but could be optimized\n";
    echo "  • Consider caching FAQ tags\n\n";
}

if ($accuracy >= 70) {
    echo "✓ Excellent accuracy! Tag-only matching is working well.\n";
    echo "  • Current configuration is optimal\n";
    echo "  • Monitor real-world usage for edge cases\n\n";
}

// Export results to JSON
$export_data = [
    'timestamp' => date('Y-m-d H:i:s'),
    'summary' => [
        'total_tests' => $total_tests,
        'correct' => $correct_matches,
        'wrong' => $incorrect_matches,
        'no_match' => $no_matches,
        'accuracy_percent' => round($accuracy, 2),
        'avg_time_ms' => round($avg_time, 3),
        'throughput_qps' => round(1000 / $avg_time, 0)
    ],
    'by_difficulty' => $results_by_difficulty,
    'failed_tests' => $failed_tests,
    'config' => ACURCB_Matcher::get_config()
];

$export_file = __DIR__ . '/test_results_' . date('Ymd_His') . '.json';
file_put_contents($export_file, json_encode($export_data, JSON_PRETTY_PRINT));
echo "Results exported to: $export_file\n\n";

// Exit code based on accuracy
if ($accuracy >= 60) {
    echo "✅ Tests PASSED (accuracy >= 60%)\n";
    exit(0);
} else {
    echo "❌ Tests FAILED (accuracy < 60%)\n";
    exit(1);
}
