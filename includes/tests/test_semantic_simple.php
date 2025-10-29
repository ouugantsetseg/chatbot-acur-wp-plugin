<?php
/**
 * Simple Semantic Matching Test
 * Quick test to verify semantic matcher is working correctly
 *
 * Usage:
 *   php test_semantic_simple.php
 */

// Load WordPress if not already loaded
if (!defined('ABSPATH')) {
    $wp_load_paths = [
        __DIR__ . '/../../../../wp-load.php',
        __DIR__ . '/../../../wp-load.php',
        __DIR__ . '/../../wp-load.php',
    ];

    foreach ($wp_load_paths as $path) {
        if (file_exists($path)) {
            define('WP_USE_THEMES', false);
            require_once $path;
            break;
        }
    }
}

require_once __DIR__ . '/class-acur-matcher-v1.php';
require_once __DIR__ . '/class-acur-embeddings.php';

echo "\n";
echo "╔════════════════════════════════════════════════╗\n";
echo "║   Semantic Matching Quick Test                ║\n";
echo "╔════════════════════════════════════════════════╗\n";
echo "\n";

// 1. Check service
echo "1. Checking embedding service...\n";
$status = ACURCB_Matcher_V1::check_service_status();
if ($status['status'] === 'online') {
    echo "   ✓ Service is online\n\n";
} else {
    echo "   ✗ Service is offline: {$status['message']}\n";
    exit(1);
}

// 2. Check embeddings
echo "2. Checking FAQ embeddings...\n";
$stats = ACURCB_Embeddings::get_embedding_stats();
echo "   Total FAQs: {$stats['total_faqs']}\n";
echo "   With embeddings: {$stats['embedded_faqs']}\n";
echo "   Coverage: {$stats['coverage_percentage']}%\n\n";

if ($stats['embedded_faqs'] == 0) {
    echo "   ✗ No embeddings found! Run: php batch_embed_faqs.php\n";
    exit(1);
}

// 3. Test queries
echo "3. Testing sample queries...\n\n";

$test_queries = [
    "Can I submit unfinished work?",
    "What is the registration fee?",
    "Do I need to give a presentation?",
    "Are group projects allowed?",
    "What is an abstract?"
];

ACURCB_Matcher_V1::set_performance_tracking(true);

foreach ($test_queries as $i => $query) {
    echo "[" . ($i + 1) . "] Query: \"$query\"\n";

    $result = ACURCB_Matcher_V1::match($query);

    echo "    Match: FAQ #{$result['id']}\n";
    echo "    Score: " . round($result['score'], 3) . "\n";
    echo "    Question: \"{$result['question']}\"\n";

    if ($result['performance']) {
        echo "    Latency: {$result['performance']['total_ms']}ms\n";
    }

    if (!empty($result['alternates'])) {
        echo "    Alternates: ";
        foreach ($result['alternates'] as $alt) {
            echo "#{$alt['id']} (" . round($alt['score'], 2) . ") ";
        }
        echo "\n";
    }

    echo "\n";
}

// 4. Performance summary
echo "4. Performance Summary\n";
$metrics = ACURCB_Matcher_V1::get_performance_metrics();

if (isset($metrics['match_success'])) {
    $avg_time = $metrics['match_success']['total_time'] / $metrics['match_success']['count'];
    echo "   Avg query time: " . round($avg_time, 2) . "ms\n";
    echo "   Total queries: {$metrics['match_success']['count']}\n";
}

echo "\n";
echo "╔════════════════════════════════════════════════╗\n";
echo "║   Test Complete ✓                             ║\n";
echo "╔════════════════════════════════════════════════╗\n";
echo "\n";
