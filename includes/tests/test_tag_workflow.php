<?php
/**
 * Test Tag-Based Matching Workflow
 * Tests the complete flow: extract tags from question → filter FAQs → score with BM25
 *
 * Usage: php test_tag_workflow.php
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

// Load Composer dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Load classes
require_once __DIR__ . '/class-acur-text-processor.php';
require_once __DIR__ . '/class-acur-matcher.php';

echo "==========================================\n";
echo "  Tag-Based Matching Workflow Test\n";
echo "==========================================\n\n";

// Load test FAQs
$faqs_json = file_get_contents(__DIR__ . '/test_data_faqs.json');
if (!$faqs_json) {
    echo "❌ Error: Could not load test_data_faqs.json\n";
    exit(1);
}

$test_faqs = json_decode($faqs_json, true);

// First, generate tags for all FAQs
echo "STEP 1: Auto-generating tags for FAQs\n";
echo str_repeat("-", 80) . "\n";

foreach ($test_faqs as &$faq) {
    $generated_tags = ACURCB_Matcher::suggest_tags($faq['question'], $faq['answer'], 6, $test_faqs);
    $faq['generated_tags'] = json_encode($generated_tags);
    echo "FAQ #{$faq['id']}: " . implode(', ', array_slice($generated_tags, 0, 4)) . "...\n";
}
unset($faq);

echo "\n";

// Test queries
$test_queries = [
    "What is an abstract?" => [
        'expected_faq_id' => 4,
        'description' => "Should match FAQ about abstracts"
    ],
    "I'm nervous about public speaking" => [
        'expected_faq_id' => 7,
        'description' => "Should match FAQ about presentation anxiety"
    ],
    "Can I present unfinished research?" => [
        'expected_faq_id' => 12,
        'description' => "Should match FAQ about research in progress"
    ],
    "Am I eligible as a first year student?" => [
        'expected_faq_id' => 16,
        'description' => "Should match FAQ about first year eligibility"
    ],
];

echo "STEP 2: Testing tag-based matching workflow\n";
echo str_repeat("-", 80) . "\n\n";

// Enable performance tracking
ACURCB_Matcher::set_performance_tracking(true);

$total_correct = 0;
$total_tests = count($test_queries);

foreach ($test_queries as $query => $test_info) {
    echo "Query: \"$query\"\n";
    echo "Expected: FAQ #{$test_info['expected_faq_id']} - {$test_info['description']}\n";

    // Use generated tags instead of original tags
    $faqs_with_generated_tags = array_map(function($faq) {
        $copy = $faq;
        $copy['tags'] = $faq['generated_tags'];
        return $copy;
    }, $test_faqs);

    // Run the match
    $result = ACURCB_Matcher::match($query, 3, $faqs_with_generated_tags);

    $matched_id = $result['id'];
    $score = $result['score'];

    echo "Result: FAQ #" . ($matched_id ?? 'null') . " (score: " . round($score, 3) . ")\n";

    if ($matched_id == $test_info['expected_faq_id']) {
        echo "✓ CORRECT MATCH!\n";
        $total_correct++;
    } else {
        echo "✗ INCORRECT - got FAQ #$matched_id instead\n";

        // Show top 3 alternates
        if (!empty($result['alternates'])) {
            echo "  Alternates:\n";
            foreach (array_slice($result['alternates'], 0, 3) as $alt) {
                echo "    - FAQ #{$alt['id']}: {$alt['question']} (score: " . round($alt['score'], 3) . ")\n";
            }
        }
    }

    echo "\n";
}

// Performance summary
$perf = ACURCB_Matcher::get_performance_metrics();

echo str_repeat("=", 80) . "\n";
echo "SUMMARY\n";
echo str_repeat("=", 80) . "\n\n";

echo "Accuracy: $total_correct / $total_tests correct (" . round(100 * $total_correct / $total_tests, 1) . "%)\n\n";

if (!empty($perf)) {
    echo "Performance Metrics:\n";
    foreach ($perf as $operation => $data) {
        $avg_time = $data['total_time'] / $data['count'];
        echo "  $operation:\n";
        echo "    - Calls: {$data['count']}\n";
        echo "    - Avg time: " . round($avg_time, 2) . "ms\n";
        echo "    - Total time: " . round($data['total_time'], 2) . "ms\n";

        // Show specific metadata if available
        if (!empty($data['metadata'])) {
            $sample = $data['metadata'][0];
            if (isset($sample['filtered_count']) && isset($sample['original_count'])) {
                echo "    - FAQs filtered: {$sample['original_count']} → {$sample['filtered_count']}\n";
            }
        }
    }
}

echo "\n";
echo "==========================================\n";
echo "  Workflow Explanation\n";
echo "==========================================\n\n";

echo "The complete workflow:\n\n";
echo "1. ADMIN ADDS FAQ:\n";
echo "   • Admin enters question and answer\n";
echo "   • Clicks 'Generate Tags' button\n";
echo "   • System extracts keywords with stemming\n";
echo "   • BM25 scores identify most distinctive terms\n";
echo "   • Tags saved with FAQ\n\n";

echo "2. USER ASKS QUESTION:\n";
echo "   • Extract tags from user question (keywords + phrases)\n";
echo "   • Apply stemming and synonym expansion\n\n";

echo "3. FILTER FAQs BY TAGS:\n";
echo "   • Find FAQs with matching tags\n";
echo "   • Use stemmed comparison for flexibility\n";
echo "   • Fall back to all FAQs if no tag matches\n\n";

echo "4. SCORE FILTERED FAQs:\n";
echo "   • Apply BM25 algorithm to candidate FAQs\n";
echo "   • Add tag match boost (up to +0.3)\n";
echo "   • Sort by combined score\n\n";

echo "5. RETURN HIGHEST SCORED FAQ:\n";
echo "   • Best match is returned to user\n";
echo "   • Alternative matches also provided\n\n";

echo "Benefits:\n";
echo "  ✓ Faster matching (fewer FAQs to score)\n";
echo "  ✓ Better accuracy (tag filtering pre-selects relevant FAQs)\n";
echo "  ✓ Automatic tagging (no manual tag entry needed)\n";
echo "  ✓ Semantic matching (stemming & synonyms)\n\n";
