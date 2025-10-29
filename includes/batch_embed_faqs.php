<?php
/**
 * Batch Embedding Script for Existing FAQs
 *
 * This script generates embeddings for all FAQs that don't have embeddings yet
 *
 * Usage:
 *   php -r "define('WP_USE_THEMES', false); require('wp-load.php'); require('wp-content/plugins/chatbot-acur-wp-plugin/includes/batch_embed_faqs.php');"
 *
 * Or with force regenerate:
 *   php batch_embed_faqs.php --force
 */

// Ensure WordPress is loaded
if (!defined('ABSPATH')) {
    // Try to load WordPress
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
        die("ERROR: Could not load WordPress. Please run this script from WordPress root or adjust paths.\n");
    }
}

// Load required classes
require_once __DIR__ . '/class-acur-embeddings.php';

echo "===========================================\n";
echo "ACUR Chatbot - Batch Embedding Generation\n";
echo "===========================================\n\n";

// Parse arguments
$force_regenerate = false;
if (isset($argv) && in_array('--force', $argv)) {
    $force_regenerate = true;
    echo "⚠ Force regenerate mode: Will regenerate ALL embeddings\n\n";
}

// Check if embedding service is available
echo "Checking embedding service status...\n";
$service_status = ACURCB_Embeddings::check_service_status();

if ($service_status['status'] !== 'online') {
    echo "❌ ERROR: Embedding service is not available!\n";
    echo "   Status: " . $service_status['status'] . "\n";
    echo "   Message: " . $service_status['message'] . "\n";
    echo "   URL: " . $service_status['url'] . "\n\n";
    echo "Please start the embedding service:\n";
    echo "   cd embedding_service\n";
    echo "   uvicorn embedding_service:app --host 0.0.0.0 --port 8000\n\n";
    exit(1);
}

echo "✓ Embedding service is online\n";
echo "   URL: " . $service_status['url'] . "\n";
if (isset($service_status['service_info']['model'])) {
    echo "   Model: " . $service_status['service_info']['model'] . "\n";
}
echo "\n";

// Get current statistics
echo "Getting current embedding statistics...\n";
$stats_before = ACURCB_Embeddings::get_embedding_stats();

echo "Current status:\n";
echo "   Total FAQs: " . $stats_before['total_faqs'] . "\n";
echo "   FAQs with embeddings: " . $stats_before['embedded_faqs'] . "\n";
echo "   FAQs without embeddings: " . $stats_before['missing_embeddings'] . "\n";
echo "   Coverage: " . $stats_before['coverage_percentage'] . "%\n\n";

if ($stats_before['missing_embeddings'] == 0 && !$force_regenerate) {
    echo "✓ All FAQs already have embeddings. Nothing to do!\n";
    echo "   Use --force flag to regenerate all embeddings.\n";
    exit(0);
}

// Confirm before proceeding
if ($stats_before['total_faqs'] > 50) {
    echo "⚠ This will process " . ($force_regenerate ? $stats_before['total_faqs'] : $stats_before['missing_embeddings']) . " FAQs.\n";
    echo "   This may take several minutes.\n";
    echo "   Press ENTER to continue or Ctrl+C to cancel...\n";
    if (php_sapi_name() === 'cli') {
        fgets(STDIN);
    }
}

echo "\nStarting batch embedding generation...\n";
echo "===========================================\n\n";

$start_time = time();

// Run batch generation
$result = ACURCB_Embeddings::batch_generate_all_embeddings($force_regenerate);

$end_time = time();
$duration = $end_time - $start_time;

echo "\n===========================================\n";
echo "Batch Generation Complete\n";
echo "===========================================\n";

echo "Results:\n";
echo "   Total processed: " . $result['total'] . "\n";
echo "   Successful: " . $result['success'] . "\n";
echo "   Failed: " . $result['failed'] . "\n";

if ($result['failed'] > 0) {
    echo "   ⚠ Some FAQs failed to generate embeddings. Check error log.\n";
}

echo "\nTiming:\n";
echo "   Duration: " . $duration . " seconds\n";
if ($result['total'] > 0) {
    echo "   Average per FAQ: " . round($duration / $result['total'], 2) . " seconds\n";
}

// Get updated statistics
echo "\nGetting updated statistics...\n";
$stats_after = ACURCB_Embeddings::get_embedding_stats();

echo "\nFinal status:\n";
echo "   Total FAQs: " . $stats_after['total_faqs'] . "\n";
echo "   FAQs with embeddings: " . $stats_after['embedded_faqs'] . "\n";
echo "   FAQs without embeddings: " . $stats_after['missing_embeddings'] . "\n";
echo "   Coverage: " . $stats_after['coverage_percentage'] . "%\n";

echo "\nEmbedding versions:\n";
foreach ($stats_after['embedding_versions'] as $version) {
    echo "   " . ($version['embedding_version'] ?? 'unknown') . ": " . $version['count'] . " FAQs\n";
}

echo "\n";

if ($stats_after['coverage_percentage'] == 100) {
    echo "✓ SUCCESS: All FAQs now have embeddings!\n";
    echo "\nNext steps:\n";
    echo "1. Update your code to use the new matcher:\n";
    echo "   \$result = ACURCB_Matcher_V1::match(\$question);\n";
    echo "\n2. Test the matching accuracy:\n";
    echo "   php test_semantic_matching.php\n";
} else {
    echo "⚠ WARNING: Not all FAQs have embeddings.\n";
    echo "   Check the error log for details about failed FAQs.\n";
}

echo "\n===========================================\n";
echo "Batch embedding generation complete!\n";
echo "===========================================\n";
