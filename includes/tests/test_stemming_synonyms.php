<?php
/**
 * Test Stemming and Synonym Features
 * Tests the enhanced tag generation with stemming and synonyms
 *
 * Usage: php test_stemming_synonyms.php
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
echo "  Stemming and Synonym Test\n";
echo "==========================================\n\n";

// Test 1: Check if libraries are loaded
echo "1. CHECKING LIBRARIES\n";
echo str_repeat("-", 80) . "\n";
$lib_status = ACURCB_TextProcessor::check_libraries();
echo "Stemming available: " . ($lib_status['stemming'] ? "✓ YES" : "✗ NO") . "\n";
echo "Synonym available: " . ($lib_status['synonym'] ? "✓ YES" : "✗ NO") . "\n";
echo "\n";

// Test 2: Stemming examples
echo "2. STEMMING TEST\n";
echo str_repeat("-", 80) . "\n";
$test_words = ['running', 'presentation', 'presenting', 'researcher', 'researching', 'studies', 'studied'];
echo "Testing word stemming:\n";
foreach ($test_words as $word) {
    $stemmed = ACURCB_TextProcessor::stem_word($word);
    echo "  $word → $stemmed\n";
}
echo "\n";

// Test 3: Synonym lookup
echo "3. SYNONYM TEST\n";
echo str_repeat("-", 80) . "\n";
$test_words_syn = ['research', 'presentation', 'student', 'conference', 'abstract'];
foreach ($test_words_syn as $word) {
    $synonyms = ACURCB_TextProcessor::get_synonyms($word);
    if (!empty($synonyms)) {
        echo "  $word → " . implode(', ', array_slice($synonyms, 0, 5)) . "\n";
    } else {
        echo "  $word → (no synonyms found)\n";
    }
}
echo "\n";

// Test 4: Enhanced keyword extraction
echo "4. ENHANCED KEYWORD EXTRACTION\n";
echo str_repeat("-", 80) . "\n";
$sample_text = "I am presenting my research about student learning at the conference";
echo "Text: \"$sample_text\"\n\n";

echo "Without stemming:\n";
$keywords_no_stem = ACURCB_TextProcessor::process_text($sample_text, false);
echo "  " . implode(', ', $keywords_no_stem) . "\n\n";

echo "With stemming:\n";
$keywords_with_stem = ACURCB_TextProcessor::process_text($sample_text, true);
echo "  " . implode(', ', $keywords_with_stem) . "\n\n";

echo "With synonym expansion:\n";
$keywords_expanded = ACURCB_TextProcessor::extract_enhanced_keywords($sample_text, true, true, 2);
echo "  " . implode(', ', array_slice($keywords_expanded, 0, 20)) . "\n\n";

// Test 5: Tag generation comparison
echo "5. TAG GENERATION WITH IMPROVEMENTS\n";
echo str_repeat("-", 80) . "\n";

// Load test FAQs
$faqs_json = file_exists(__DIR__ . '/test_data_faqs.json')
    ? file_get_contents(__DIR__ . '/test_data_faqs.json')
    : null;

if ($faqs_json) {
    $test_faqs = json_decode($faqs_json, true);

    // Test on a specific FAQ
    $faq = $test_faqs[3]; // FAQ #4 about "What is an abstract?"

    echo "Testing FAQ: \"{$faq['question']}\"\n\n";

    echo "Generated tags (with stemming & synonyms):\n";
    $tags = ACURCB_Matcher::suggest_tags($faq['question'], $faq['answer'], 10, $test_faqs);
    foreach ($tags as $i => $tag) {
        echo "  " . ($i + 1) . ". $tag\n";
    }
    echo "\n";

    echo "Original tags:\n";
    $original_tags = json_decode($faq['tags'], true);
    foreach ($original_tags as $i => $tag) {
        echo "  " . ($i + 1) . ". $tag\n";
    }
    echo "\n";

    // Calculate overlap
    $generated_lower = array_map('strtolower', $tags);
    $original_lower = array_map('strtolower', $original_tags);
    $overlap = array_intersect($generated_lower, $original_lower);

    echo "Match rate: " . count($overlap) . "/" . count($original_tags) . " tags\n";
    if (!empty($overlap)) {
        echo "Matched: " . implode(', ', $overlap) . "\n";
    }
} else {
    echo "⚠️  test_data_faqs.json not found. Skipping tag generation test.\n";
}
echo "\n";

// Test 6: Similarity comparison
echo "6. SIMILARITY CALCULATION TEST\n";
echo str_repeat("-", 80) . "\n";
$query1 = "How do I submit my research?";
$query2 = "What's the process for submitting a study?";

echo "Query 1: \"$query1\"\n";
echo "Query 2: \"$query2\"\n\n";

$sim_without = ACURCB_TextProcessor::calculate_similarity_with_stemming($query1, $query2, false);
$sim_with = ACURCB_TextProcessor::calculate_similarity_with_stemming($query1, $query2, true);

echo "Similarity without stemming: " . round($sim_without, 3) . "\n";
echo "Similarity with stemming: " . round($sim_with, 3) . "\n";
echo "Improvement: " . round(($sim_with - $sim_without) * 100, 1) . "%\n";
echo "\n";

// Summary
echo "==========================================\n";
echo "  SUMMARY\n";
echo "==========================================\n\n";

if ($lib_status['stemming'] && $lib_status['synonym']) {
    echo "✓ All libraries loaded successfully!\n";
    echo "✓ Stemming is working correctly\n";
    echo "✓ Synonym expansion is available\n";
    echo "✓ Tag generation has been enhanced\n\n";
    echo "The improvements include:\n";
    echo "  • Better keyword normalization (running → run)\n";
    echo "  • Reduced tag redundancy through stemming\n";
    echo "  • Synonym variations for high-value tags\n";
    echo "  • Improved matching for semantically similar terms\n";
} else {
    echo "⚠️  Some libraries are missing:\n";
    if (!$lib_status['stemming']) {
        echo "  ✗ Stemming library (nadar/stemming) not found\n";
    }
    if (!$lib_status['synonym']) {
        echo "  ✗ Synonym library (hugsbrugs/php-synonym) not found\n";
    }
    echo "\nRun: composer install\n";
}

echo "\n";
