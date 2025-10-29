<?php
/**
 * TF-IDF Tag Generation Comparison Test
 *
 * Compares old frequency-based tags with new TF-IDF based tags
 * Shows which terms are most distinctive for each FAQ
 *
 * Usage: php test_tfidf_comparison.php
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
echo "  TF-IDF Tag Generation Comparison\n";
echo "==========================================\n\n";

// Load test FAQs
$faqs_json = file_get_contents(__DIR__ . '/test_data_faqs.json');
if (!$faqs_json) {
    echo "❌ Error: Could not load test_data_faqs.json\n";
    exit(1);
}

$test_faqs = json_decode($faqs_json, true);
echo "Loaded " . count($test_faqs) . " test FAQs\n\n";

// Test a few representative FAQs
$test_samples = [4, 8, 12, 16, 20];

echo "Comparing Tag Generation with TF-IDF:\n";
echo str_repeat("=", 80) . "\n\n";

foreach ($test_samples as $faq_id) {
    $faq = null;
    foreach ($test_faqs as $f) {
        if ($f['id'] == $faq_id) {
            $faq = $f;
            break;
        }
    }

    if (!$faq) continue;

    echo "FAQ #{$faq['id']}: " . substr($faq['question'], 0, 60) . "...\n";
    echo str_repeat("-", 80) . "\n";

    // Generate tags with TF-IDF
    $tfidf_tags = ACURCB_Matcher::suggest_tags(
        $faq['question'],
        $faq['answer'],
        10,
        $test_faqs  // Pass all FAQs for TF-IDF calculation
    );

    // Get original tags from data
    $original_tags = json_decode($faq['tags'], true);

    echo "Original Tags (frequency-based):\n";
    echo "  " . implode(', ', $original_tags) . "\n\n";

    echo "New Tags (TF-IDF-based):\n";
    echo "  " . implode(', ', $tfidf_tags) . "\n\n";

    // Show what's different
    $only_in_original = array_diff(
        array_map('strtolower', $original_tags),
        array_map('strtolower', $tfidf_tags)
    );
    $only_in_tfidf = array_diff(
        array_map('strtolower', $tfidf_tags),
        array_map('strtolower', $original_tags)
    );

    if (!empty($only_in_original)) {
        echo "Removed (not distinctive): " . implode(', ', $only_in_original) . "\n";
    }
    if (!empty($only_in_tfidf)) {
        echo "Added (more distinctive):  " . implode(', ', $only_in_tfidf) . "\n";
    }

    echo "\n" . str_repeat("=", 80) . "\n\n";
}

// Now test the actual matching improvement
echo "\n";
echo "==========================================\n";
echo "  Testing Matching Accuracy\n";
echo "==========================================\n\n";

// Load test queries
$queries_json = file_get_contents(__DIR__ . '/test_data_queries.json');
$test_queries = json_decode($queries_json, true);

// Take a sample of queries
$sample_queries = array_slice($test_queries, 0, 20);

echo "Testing with " . count($sample_queries) . " queries...\n\n";

// First, regenerate all FAQ tags with TF-IDF
$faqs_with_tfidf_tags = [];
foreach ($test_faqs as $faq) {
    $new_tags = ACURCB_Matcher::suggest_tags(
        $faq['question'],
        $faq['answer'],
        10,
        $test_faqs
    );
    $faqs_with_tfidf_tags[] = [
        'id' => $faq['id'],
        'question' => $faq['question'],
        'answer' => $faq['answer'],
        'tags' => json_encode($new_tags)
    ];
}

// Test with original tags
$correct_original = 0;
$correct_tfidf = 0;

foreach ($sample_queries as $query_data) {
    $query = $query_data['query'];
    $expected_id = $query_data['expected_faq_id'];

    // Test with original tags
    $result_original = ACURCB_Matcher::match($query, 5, $test_faqs);
    if ($result_original['id'] === $expected_id) {
        $correct_original++;
    }

    // Test with TF-IDF tags
    $result_tfidf = ACURCB_Matcher::match($query, 5, $faqs_with_tfidf_tags);
    if ($result_tfidf['id'] === $expected_id) {
        $correct_tfidf++;
    }
}

$accuracy_original = ($correct_original / count($sample_queries)) * 100;
$accuracy_tfidf = ($correct_tfidf / count($sample_queries)) * 100;
$improvement = $accuracy_tfidf - $accuracy_original;

echo "Results:\n";
echo str_repeat("-", 40) . "\n";
echo sprintf("Original Tags:  %d/%d correct (%.1f%%)\n",
    $correct_original, count($sample_queries), $accuracy_original);
echo sprintf("TF-IDF Tags:    %d/%d correct (%.1f%%)\n",
    $correct_tfidf, count($sample_queries), $accuracy_tfidf);
echo sprintf("Improvement:    %+.1f%%\n\n", $improvement);

if ($improvement > 0) {
    echo "✅ TF-IDF improved accuracy!\n";
} elseif ($improvement < 0) {
    echo "⚠️  TF-IDF reduced accuracy (may need tuning)\n";
} else {
    echo "➖ No change in accuracy\n";
}

echo "\n";

// Detailed analysis
echo "==========================================\n";
echo "  TF-IDF Analysis\n";
echo "==========================================\n\n";

echo "How TF-IDF Works:\n";
echo "  TF  = Term Frequency (how often term appears in document)\n";
echo "  IDF = Inverse Document Frequency (how rare term is across all docs)\n";
echo "  TF-IDF = TF × IDF (high score = distinctive term)\n\n";

echo "Benefits:\n";
echo "  ✓ Identifies distinctive keywords for each FAQ\n";
echo "  ✓ Reduces common/generic terms (e.g., 'research', 'conference')\n";
echo "  ✓ Boosts unique terms (e.g., 'abstract', 'accommodation')\n";
echo "  ✓ Better phrase detection\n";
echo "  ✓ Question terms get 2x boost\n\n";

echo "Example:\n";
echo "  Term 'research' appears in 15/19 FAQs:\n";
echo "    IDF = log(19/(15+1)) = 0.17 (low = common)\n";
echo "  Term 'abstract' appears in 2/19 FAQs:\n";
echo "    IDF = log(19/(2+1)) = 1.86 (high = distinctive)\n\n";

echo "Result: 'abstract' gets much higher TF-IDF score than 'research'\n\n";

// Show most distinctive terms across all FAQs
echo "==========================================\n";
echo "  Most Distinctive Terms Per FAQ\n";
echo "==========================================\n\n";

foreach (array_slice($test_faqs, 0, 5) as $faq) {
    $tags = ACURCB_Matcher::suggest_tags($faq['question'], $faq['answer'], 5, $test_faqs);
    echo sprintf("FAQ #%d: %s\n", $faq['id'], substr($faq['question'], 0, 50));
    echo "  Top tags: " . implode(', ', $tags) . "\n\n";
}

echo "==========================================\n";
echo "  Recommendations\n";
echo "==========================================\n\n";

if ($improvement >= 5) {
    echo "✅ TF-IDF is working well! (+{$improvement}% improvement)\n";
    echo "   • Consider deploying to production\n";
    echo "   • Run full test suite to confirm\n";
} elseif ($improvement >= 0) {
    echo "✓ TF-IDF shows promise (+" . number_format($improvement, 1) . "% improvement)\n";
    echo "   • Test with more queries\n";
    echo "   • May need threshold tuning\n";
} else {
    echo "⚠️  TF-IDF needs tuning (" . number_format($improvement, 1) . "% decrease)\n";
    echo "   • Check if question boost (2x) is too high\n";
    echo "   • Review score threshold (0.01)\n";
    echo "   • Ensure test FAQs are representative\n";
}

echo "\n";
echo "Next Steps:\n";
echo "  1. Run full test: php test_matcher_simple.php\n";
echo "  2. If accuracy improves: Regenerate all FAQ tags in database\n";
echo "  3. Monitor real-world usage\n\n";

echo "Done! ✨\n";
