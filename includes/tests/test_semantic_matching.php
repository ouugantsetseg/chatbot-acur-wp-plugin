<?php
/**
 * Test Script for Semantic Matching (all-MiniLM-L6-v2)
 *
 * This script tests the new semantic matcher against test queries
 * and compares accuracy with the old BM25 matcher
 *
 * Usage:
 *   php -r "define('WP_USE_THEMES', false); require('wp-load.php'); require('wp-content/plugins/chatbot-acur-wp-plugin/includes/test_semantic_matching.php');"
 */

// Load WordPress if not already loaded

if (!defined('ABSPATH')) {
    $wp_load_paths = [
        __DIR__ . '/../../../../wp-load.php',
        __DIR__ . '/../../../wp-load.php',
        __DIR__ . '/../../wp-load.php',
    ];

    $wp_loaded = false;
    foreach ($wp_load_paths as $wp_load_path) {
        if (file_exists($wp_load_path)) {
            define('WP_USE_THEMES', false);
            require_once $wp_load_path;
            $wp_loaded = true;
            break;
        }
    }

    if (!$wp_loaded) {
        die("ERROR: Could not load WordPress.\n");
    }
}


// Load required classes
require_once __DIR__ . '/class-acur-matcher-v1.php';
require_once __DIR__ . '/class-acur-embeddings.php';

// Load old matcher for comparison (if exists)
$has_old_matcher = file_exists(__DIR__ . '/class-acur-matcher.php');
if ($has_old_matcher) {
    require_once __DIR__ . '/class-acur-matcher.php';
}

echo "===========================================\n";
echo "Semantic Matching Test Suite\n";
echo "===========================================\n\n";

// Check if embedding service is available
echo "Step 1: Checking embedding service...\n";
$service_status = ACURCB_Matcher_V1::check_service_status();

if ($service_status['status'] !== 'online') {
    echo "❌ ERROR: Embedding service is not available!\n";
    echo "   Status: " . $service_status['status'] . "\n";
    echo "   Message: " . $service_status['message'] . "\n";
    echo "   URL: " . $service_status['url'] . "\n\n";
    echo "Please start the embedding service first.\n";
    exit(1);
}

echo "✓ Embedding service is online\n";
echo "   Model: " . ($service_status['details']['model'] ?? 'all-MiniLM-L6-v2') . "\n\n";

// Check embedding coverage
echo "Step 2: Checking FAQ embedding coverage...\n";
$stats = ACURCB_Embeddings::get_embedding_stats();

echo "   Total FAQs: " . $stats['total_faqs'] . "\n";
echo "   FAQs with embeddings: " . $stats['embedded_faqs'] . "\n";
echo "   Coverage: " . $stats['coverage_percentage'] . "%\n\n";

if ($stats['coverage_percentage'] < 100) {
    echo "⚠ WARNING: Not all FAQs have embeddings!\n";
    echo "   Run: php batch_embed_faqs.php\n\n";
}

// Load test data
echo "Step 3: Loading test data...\n";

// Check if test data files exist
$test_queries_file = __DIR__ . '/test_data_queries.json';
$test_faqs_file = __DIR__ . '/test_data_faqs.json';

$test_queries = [];

if (file_exists($test_queries_file)) {
    $test_queries = json_decode(file_get_contents($test_queries_file), true);
    echo "✓ Loaded " . count($test_queries) . " test queries from file\n";

    // Show dataset breakdown
    $dataset_breakdown = ['easy' => 0, 'medium' => 0, 'hard' => 0];
    $faq_ids_covered = [];
    foreach ($test_queries as $q) {
        if (isset($q['difficulty'])) {
            $dataset_breakdown[$q['difficulty']]++;
        }
        if (isset($q['expected_faq_id']) || isset($q['gold_id'])) {
            $faq_id = isset($q['expected_faq_id']) ? $q['expected_faq_id'] : $q['gold_id'];
            $faq_ids_covered[$faq_id] = true;
        }
    }
    echo "   Dataset: " . $dataset_breakdown['easy'] . " easy, "
         . $dataset_breakdown['medium'] . " medium, "
         . $dataset_breakdown['hard'] . " hard\n";
    echo "   Covering " . count($faq_ids_covered) . " unique FAQs\n\n";
} else {
    // Fallback: Use sample test queries
    echo "⚠ Test file not found, using sample queries\n\n";

    $test_queries = [
        ['query' => 'Can you explain what an abstract is?', 'gold_id' => 4],
        ['query' => 'What should I include in the abstract section?', 'gold_id' => 4],
        ['query' => 'How do I summarise my project for submission?', 'gold_id' => 4],
        ['query' => 'My project doesn\'t really match the theme, can I still submit?', 'gold_id' => 5],
        ['query' => 'Do submissions have to be strictly aligned to the theme?', 'gold_id' => 5],
        ['query' => 'Am I required to do a talk, or can I just do a poster?', 'gold_id' => 6],
        ['query' => 'Do all presenters have to present orally?', 'gold_id' => 6],
        ['query' => 'Can I choose between oral and poster presentation?', 'gold_id' => 6],
        ['query' => 'What if I\'m nervous about presenting?', 'gold_id' => 7],
        ['query' => 'I don\'t like speaking in front of people — can I still join?', 'gold_id' => 7],
        ['query' => 'Who looks at and approves the abstracts?', 'gold_id' => 9],
        ['query' => 'Who decides whether my abstract is accepted?', 'gold_id' => 9],
        ['query' => 'Can I submit if my project is still ongoing?', 'gold_id' => 12],
        ['query' => 'Is unfinished research allowed?', 'gold_id' => 12],
        ['query' => 'Do I have to wait until I have final results?', 'gold_id' => 12],
        ['query' => 'Are group projects allowed?', 'gold_id' => 15],
        ['query' => 'Can a team submit together?', 'gold_id' => 15],
        ['query' => 'Are first-year students allowed to submit?', 'gold_id' => 16],
        ['query' => 'Can second-year projects be presented?', 'gold_id' => 16],
        ['query' => 'What is the registration cost?', 'gold_id' => 20],
    ];
}

// Enable performance tracking
ACURCB_Matcher_V1::set_performance_tracking(true);

echo "===========================================\n";
echo "Running Tests\n";
echo "===========================================\n\n";

// Results tracking
$new_results = [];
$old_results = [];

$new_correct = 0;
$old_correct = 0;
$new_in_top3 = 0;
$old_in_top3 = 0;
$new_latencies = [];
$old_latencies = [];

// Difficulty-based tracking
$difficulty_stats = [
    'easy' => ['total' => 0, 'correct' => 0, 'in_top3' => 0],
    'medium' => ['total' => 0, 'correct' => 0, 'in_top3' => 0],
    'hard' => ['total' => 0, 'correct' => 0, 'in_top3' => 0]
];

// Per-FAQ tracking
$per_faq_stats = [];

echo "Testing " . count($test_queries) . " queries...\n\n";

foreach ($test_queries as $i => $test) {
    $query_num = $i + 1;

    $difficulty = isset($test['difficulty']) ? $test['difficulty'] : 'medium';
    // Handle both 'gold_id' and 'expected_faq_id' field names
    $faq_id = isset($test['gold_id']) ? $test['gold_id'] : $test['expected_faq_id'];

    echo "[$query_num/" . count($test_queries) . "] Testing: \"{$test['query']}\"\n";
    echo "   Expected FAQ ID: #{$faq_id} | Difficulty: {$difficulty}\n";

    // Test NEW semantic matcher
    $new_start = microtime(true);
    $new_result = ACURCB_Matcher_V1::match($test['query']);
    $new_latency = (microtime(true) - $new_start) * 1000;
    $new_latencies[] = $new_latency;

    $new_pred_id = $new_result['id'];
    $new_score = $new_result['score'];

    // Check if correct
    $new_is_correct = ($new_pred_id == $faq_id);
    if ($new_is_correct) {
        $new_correct++;
        echo "   ✓ NEW: FAQ #{$new_pred_id} (score: " . round($new_score, 3) . ") - CORRECT\n";
    } else {
        echo "   ✗ NEW: FAQ #{$new_pred_id} (score: " . round($new_score, 3) . ") - WRONG\n";
    }

    // Check if expected FAQ is in top 3 alternates
    $in_top3 = $new_is_correct;
    if (!$in_top3 && !empty($new_result['alternates'])) {
        foreach ($new_result['alternates'] as $alt) {
            if ($alt['id'] == $faq_id) {
                $in_top3 = true;
                $new_in_top3++;
                break;
            }
        }
    } else if ($new_is_correct) {
        $new_in_top3++;
    }

    // Test OLD matcher (if available)
    if ($has_old_matcher && class_exists('ACURCB_Matcher')) {
        $old_start = microtime(true);
        $old_result = ACURCB_Matcher::match($test['query']);
        $old_latency = (microtime(true) - $old_start) * 1000;
        $old_latencies[] = $old_latency;

        $old_pred_id = $old_result['id'];
        $old_score = $old_result['score'];

        $old_is_correct = ($old_pred_id == $faq_id);
        if ($old_is_correct) {
            $old_correct++;
            echo "   ✓ OLD: FAQ #{$old_pred_id} (score: " . round($old_score, 3) . ") - CORRECT\n";
        } else {
            echo "   ✗ OLD: FAQ #{$old_pred_id} (score: " . round($old_score, 3) . ") - WRONG\n";
        }

        // Check if expected FAQ is in top 3 alternates
        $old_top3 = $old_is_correct;
        if (!$old_top3 && !empty($old_result['alternates'])) {
            foreach ($old_result['alternates'] as $alt) {
                if ($alt['id'] == $faq_id) {
                    $old_top3 = true;
                    $old_in_top3++;
                    break;
                }
            }
        } else if ($old_is_correct) {
            $old_in_top3++;
        }
    }

    echo "\n";

    // Track difficulty stats
    $difficulty_stats[$difficulty]['total']++;
    if ($new_is_correct) {
        $difficulty_stats[$difficulty]['correct']++;
    }
    if ($in_top3) {
        $difficulty_stats[$difficulty]['in_top3']++;
    }

    // Track per-FAQ stats
    if (!isset($per_faq_stats[$faq_id])) {
        $per_faq_stats[$faq_id] = [
            'easy' => ['total' => 0, 'correct' => 0],
            'medium' => ['total' => 0, 'correct' => 0],
            'hard' => ['total' => 0, 'correct' => 0],
            'total_queries' => 0,
            'total_correct' => 0
        ];
    }
    $per_faq_stats[$faq_id][$difficulty]['total']++;
    if ($new_is_correct) {
        $per_faq_stats[$faq_id][$difficulty]['correct']++;
    }
    $per_faq_stats[$faq_id]['total_queries']++;
    if ($new_is_correct) {
        $per_faq_stats[$faq_id]['total_correct']++;
    }

    // Store results
    $new_results[] = [
        'query' => $test['query'],
        'gold_id' => $faq_id,
        'pred_id' => $new_pred_id,
        'score' => $new_score,
        'correct' => $new_is_correct,
        'latency_ms' => $new_latency,
        'difficulty' => $difficulty
    ];
}

echo "===========================================\n";
echo "Test Results\n";
echo "===========================================\n\n";

// Calculate metrics
$total = count($test_queries);

// NEW matcher metrics
$new_accuracy = ($new_correct / $total) * 100;
$new_top3_accuracy = ($new_in_top3 / $total) * 100;
$new_avg_latency = array_sum($new_latencies) / count($new_latencies);
$new_p95_latency = $new_latencies[0]; // Simplified, should sort first
sort($new_latencies);
$new_p95_latency = $new_latencies[(int)(count($new_latencies) * 0.95)];

echo "NEW Semantic Matcher (all-MiniLM-L6-v2):\n";
echo "   Hit@1 Accuracy: " . round($new_accuracy, 2) . "% ({$new_correct}/{$total})\n";
echo "   Hit@3 Accuracy: " . round($new_top3_accuracy, 2) . "% ({$new_in_top3}/{$total})\n";
echo "   Avg Latency: " . round($new_avg_latency, 2) . " ms\n";
echo "   P95 Latency: " . round($new_p95_latency, 2) . " ms\n\n";

// Difficulty breakdown
echo "Accuracy by Difficulty:\n";
foreach ($difficulty_stats as $diff => $stats) {
    if ($stats['total'] > 0) {
        $acc = ($stats['correct'] / $stats['total']) * 100;
        $top3_acc = ($stats['in_top3'] / $stats['total']) * 100;
        echo "   " . ucfirst($diff) . ": " . round($acc, 1) . "% ({$stats['correct']}/{$stats['total']}) | Top3: " . round($top3_acc, 1) . "%\n";
    }
}
echo "\n";

// OLD matcher metrics (if available)
if ($has_old_matcher && !empty($old_latencies)) {
    $old_accuracy = ($old_correct / $total) * 100;
    $old_top3_accuracy = ($old_in_top3 / $total) * 100;
    $old_avg_latency = array_sum($old_latencies) / count($old_latencies);
    sort($old_latencies);
    $old_p95_latency = $old_latencies[(int)(count($old_latencies) * 0.95)];

    echo "OLD Matcher (BM25 + Tags):\n";
    echo "   Hit@1 Accuracy: " . round($old_accuracy, 2) . "% ({$old_correct}/{$total})\n";
    echo "   Hit@3 Accuracy: " . round($old_top3_accuracy, 2) . "% ({$old_in_top3}/{$total})\n";
    echo "   Avg Latency: " . round($old_avg_latency, 2) . " ms\n";
    echo "   P95 Latency: " . round($old_p95_latency, 2) . " ms\n\n";

    // Comparison
    $accuracy_improvement = $new_accuracy - $old_accuracy;
    $latency_change = $new_avg_latency - $old_avg_latency;

    echo "===========================================\n";
    echo "Improvement Analysis\n";
    echo "===========================================\n\n";

    echo "Accuracy:\n";
    if ($accuracy_improvement > 0) {
        echo "   ✓ IMPROVED by " . round($accuracy_improvement, 2) . " percentage points\n";
    } else if ($accuracy_improvement < 0) {
        echo "   ✗ DECREASED by " . round(abs($accuracy_improvement), 2) . " percentage points\n";
    } else {
        echo "   = No change\n";
    }

    echo "\nLatency:\n";
    if ($latency_change > 0) {
        echo "   Slower by " . round($latency_change, 2) . " ms (";
        echo round(($latency_change / $old_avg_latency) * 100, 1) . "% increase)\n";
    } else if ($latency_change < 0) {
        echo "   ✓ Faster by " . round(abs($latency_change), 2) . " ms (";
        echo round((abs($latency_change) / $old_avg_latency) * 100, 1) . "% decrease)\n";
    } else {
        echo "   = No change\n";
    }
}

// Per-FAQ detailed breakdown
echo "\n===========================================\n";
echo "Per-FAQ Performance Breakdown\n";
echo "===========================================\n\n";

global $wpdb;
ksort($per_faq_stats);

foreach ($per_faq_stats as $faq_id => $stats) {
    // Get FAQ question
    $faq_question = $wpdb->get_var($wpdb->prepare(
        "SELECT question FROM {$wpdb->prefix}faqs WHERE id = %d",
        $faq_id
    ));

    $overall_acc = ($stats['total_correct'] / $stats['total_queries']) * 100;

    echo "FAQ #{$faq_id}: {$faq_question}\n";
    echo "   Overall: " . round($overall_acc, 1) . "% ({$stats['total_correct']}/{$stats['total_queries']})\n";

    // Show breakdown by difficulty
    $breakdown_parts = [];
    foreach (['easy', 'medium', 'hard'] as $diff) {
        if ($stats[$diff]['total'] > 0) {
            $acc = ($stats[$diff]['correct'] / $stats[$diff]['total']) * 100;
            $symbol = $acc == 100 ? '✓' : ($acc >= 50 ? '~' : '✗');
            $breakdown_parts[] = ucfirst($diff) . ": {$symbol} " . round($acc, 0) . "% ({$stats[$diff]['correct']}/{$stats[$diff]['total']})";
        }
    }
    echo "   " . implode(" | ", $breakdown_parts) . "\n\n";
}

// Detailed failure analysis
echo "===========================================\n";
echo "Failure Analysis\n";
echo "===========================================\n\n";

$failures = array_filter($new_results, function($r) { return !$r['correct']; });

if (empty($failures)) {
    echo "✓ No failures! All queries matched correctly.\n";
} else {
    echo "Failed queries (" . count($failures) . "):\n\n";

    // Group failures by difficulty
    $failures_by_difficulty = ['easy' => [], 'medium' => [], 'hard' => []];
    foreach ($failures as $fail) {
        $failures_by_difficulty[$fail['difficulty']][] = $fail;
    }

    foreach (['easy', 'medium', 'hard'] as $diff) {
        $diff_failures = $failures_by_difficulty[$diff];
        if (!empty($diff_failures)) {
            echo strtoupper($diff) . " Questions (" . count($diff_failures) . " failures):\n";
            echo str_repeat("-", 50) . "\n";

            foreach ($diff_failures as $fail) {
                echo "Query: \"{$fail['query']}\"\n";
                echo "   Expected: FAQ #{$fail['gold_id']}\n";
                echo "   Got: FAQ #{$fail['pred_id']} (score: " . round($fail['score'], 3) . ")\n";

                // Get FAQ questions for comparison
                $expected_faq = $wpdb->get_var($wpdb->prepare(
                    "SELECT question FROM {$wpdb->prefix}faqs WHERE id = %d",
                    $fail['gold_id']
                ));
                $predicted_faq = $wpdb->get_var($wpdb->prepare(
                    "SELECT question FROM {$wpdb->prefix}faqs WHERE id = %d",
                    $fail['pred_id']
                ));

                echo "   Expected FAQ: \"{$expected_faq}\"\n";
                echo "   Predicted FAQ: \"{$predicted_faq}\"\n\n";
            }
        }
    }
}

// Performance metrics
echo "\n===========================================\n";
echo "Performance Breakdown\n";
echo "===========================================\n\n";

$perf_metrics = ACURCB_Matcher_V1::get_performance_metrics();

if (!empty($perf_metrics)) {
    foreach ($perf_metrics as $operation => $metrics) {
        echo "{$operation}:\n";
        echo "   Count: {$metrics['count']}\n";
        echo "   Avg: " . round($metrics['total_time'] / $metrics['count'], 2) . " ms\n";
        echo "   Min: " . round($metrics['min_time'], 2) . " ms\n";
        echo "   Max: " . round($metrics['max_time'], 2) . " ms\n\n";
    }
}

// Save results to file
echo "===========================================\n";
echo "Saving Results\n";
echo "===========================================\n\n";

$results_file = __DIR__ . '/test_results_semantic.json';

// Calculate difficulty-based metrics
$difficulty_metrics = [];
foreach ($difficulty_stats as $diff => $stats) {
    if ($stats['total'] > 0) {
        $difficulty_metrics[$diff] = [
            'total' => $stats['total'],
            'correct' => $stats['correct'],
            'accuracy' => round(($stats['correct'] / $stats['total']) * 100, 2),
            'in_top3' => $stats['in_top3'],
            'top3_accuracy' => round(($stats['in_top3'] / $stats['total']) * 100, 2)
        ];
    }
}

$results_data = [
    'timestamp' => date('Y-m-d H:i:s'),
    'total_queries' => $total,
    'new_matcher' => [
        'accuracy_hit1' => $new_accuracy,
        'accuracy_hit3' => $new_top3_accuracy,
        'correct_count' => $new_correct,
        'avg_latency_ms' => $new_avg_latency,
        'p95_latency_ms' => $new_p95_latency
    ],
    'difficulty_breakdown' => $difficulty_metrics,
    'per_faq_stats' => $per_faq_stats,
    'queries' => $new_results
];

if ($has_old_matcher && !empty($old_latencies)) {
    $results_data['old_matcher'] = [
        'accuracy_hit1' => $old_accuracy,
        'accuracy_hit3' => $old_top3_accuracy,
        'correct_count' => $old_correct,
        'avg_latency_ms' => $old_avg_latency,
        'p95_latency_ms' => $old_p95_latency
    ];
    $results_data['improvement'] = [
        'accuracy_delta' => $accuracy_improvement,
        'latency_delta_ms' => $latency_change
    ];
}

file_put_contents($results_file, json_encode($results_data, JSON_PRETTY_PRINT));

echo "✓ Results saved to: {$results_file}\n";

echo "\n===========================================\n";
echo "Test Complete\n";
echo "===========================================\n";

// Summary
echo "\nSummary:\n";
echo str_repeat("=", 50) . "\n\n";

// Overall accuracy
if ($new_accuracy >= 75) {
    echo "✓ EXCELLENT: Overall accuracy is " . round($new_accuracy, 1) . "% (target: 75-85%)\n";
} else if ($new_accuracy >= 60) {
    echo "⚠ GOOD: Overall accuracy is " . round($new_accuracy, 1) . "% (below target of 75%)\n";
} else {
    echo "✗ NEEDS IMPROVEMENT: Overall accuracy is " . round($new_accuracy, 1) . "% (well below target)\n";
}

// Difficulty-specific insights
echo "\nDifficulty Insights:\n";
foreach (['easy', 'medium', 'hard'] as $diff) {
    if (isset($difficulty_metrics[$diff])) {
        $acc = $difficulty_metrics[$diff]['accuracy'];
        $status = '';
        if ($diff === 'easy' && $acc < 90) {
            $status = ' ⚠ (should be >90%)';
        } else if ($diff === 'medium' && $acc < 70) {
            $status = ' ⚠ (should be >70%)';
        } else if ($diff === 'hard' && $acc < 50) {
            $status = ' ⚠ (should be >50%)';
        } else {
            $status = ' ✓';
        }
        echo "   " . ucfirst($diff) . ": " . round($acc, 1) . "%{$status}\n";
    }
}

// Performance
echo "\nPerformance:\n";
if ($new_avg_latency <= 100) {
    echo "   ✓ FAST: Avg latency is " . round($new_avg_latency, 1) . "ms (excellent)\n";
} else if ($new_avg_latency <= 200) {
    echo "   ✓ ACCEPTABLE: Avg latency is " . round($new_avg_latency, 1) . "ms (acceptable)\n";
} else {
    echo "   ⚠ SLOW: Avg latency is " . round($new_avg_latency, 1) . "ms (consider optimization)\n";
}

// FAQ coverage
$total_faqs_tested = count($per_faq_stats);
$faqs_100_percent = 0;
$faqs_below_50 = 0;
foreach ($per_faq_stats as $faq_id => $stats) {
    $acc = ($stats['total_correct'] / $stats['total_queries']) * 100;
    if ($acc == 100) $faqs_100_percent++;
    if ($acc < 50) $faqs_below_50++;
}

echo "\nFAQ Coverage:\n";
echo "   Total FAQs tested: {$total_faqs_tested}\n";
echo "   FAQs with 100% accuracy: {$faqs_100_percent}\n";
if ($faqs_below_50 > 0) {
    echo "   ⚠ FAQs below 50% accuracy: {$faqs_below_50} (needs attention)\n";
}

echo "\n";
