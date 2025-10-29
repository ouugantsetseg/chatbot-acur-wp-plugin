<?php
/**
 * Tag Generation Diagnostic Tool
 * Tests and shows what tags are being generated for each FAQ
 *
 * Usage: php test_tag_generation.php
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
echo "  Tag Generation Diagnostic\n";
echo "==========================================\n\n";

// Load test FAQs
$faqs_json = file_get_contents(__DIR__ . '/test_data_faqs.json');
if (!$faqs_json) {
    echo "❌ Error: Could not load test_data_faqs.json\n";
    exit(1);
}

$test_faqs = json_decode($faqs_json, true);
echo "Loaded " . count($test_faqs) . " test FAQs\n\n";

// Test sample FAQs
$samples = [4, 7, 12, 16, 20];

foreach ($samples as $faq_id) {
    $faq = null;
    foreach ($test_faqs as $f) {
        if ($f['id'] == $faq_id) {
            $faq = $f;
            break;
        }
    }

    if (!$faq) continue;

    echo str_repeat("=", 80) . "\n";
    echo "FAQ #{$faq['id']}\n";
    echo str_repeat("=", 80) . "\n\n";

    echo "QUESTION:\n";
    echo wordwrap($faq['question'], 75) . "\n\n";

    echo "ANSWER:\n";
    echo wordwrap(substr($faq['answer'], 0, 200), 75) . "...\n\n";

    // Extract keywords to see what's being extracted
    $question_keywords = ACURCB_Matcher::extract_keywords($faq['question']);
    $answer_keywords = ACURCB_Matcher::extract_keywords($faq['answer']);

    echo "EXTRACTED KEYWORDS:\n";
    echo "  From Question: " . implode(', ', $question_keywords) . "\n";
    echo "  From Answer:   " . implode(', ', array_slice($answer_keywords, 0, 10)) . "\n\n";

    // Generate tags with current method
    echo "GENERATED TAGS (Current TF-IDF method):\n";
    $generated_tags = ACURCB_Matcher::suggest_tags(
        $faq['question'],
        $faq['answer'],
        10,
        $test_faqs
    );

    if (empty($generated_tags)) {
        echo "  ⚠️  NO TAGS GENERATED!\n\n";
    } else {
        foreach ($generated_tags as $i => $tag) {
            echo "  " . ($i + 1) . ". " . $tag . "\n";
        }
        echo "\n";
    }

    // Compare with original
    $original_tags = json_decode($faq['tags'], true);
    echo "ORIGINAL TAGS (from database):\n";
    foreach ($original_tags as $i => $tag) {
        echo "  " . ($i + 1) . ". " . $tag . "\n";
    }
    echo "\n";

    // Analysis
    $generated_lower = array_map('strtolower', $generated_tags);
    $original_lower = array_map('strtolower', $original_tags);

    $overlap = array_intersect($generated_lower, $original_lower);
    $only_generated = array_diff($generated_lower, $original_lower);
    $only_original = array_diff($original_lower, $generated_lower);

    echo "ANALYSIS:\n";
    echo "  ✓ Overlap: " . count($overlap) . "/" . count($original_tags) . " tags match\n";

    if (!empty($overlap)) {
        echo "    → " . implode(', ', $overlap) . "\n";
    }

    if (!empty($only_generated)) {
        echo "  + New tags: " . implode(', ', $only_generated) . "\n";
    }

    if (!empty($only_original)) {
        echo "  - Missing: " . implode(', ', $only_original) . "\n";
    }

    echo "\n\n";
}

// Overall quality assessment
echo "==========================================\n";
echo "  Recommendations\n";
echo "==========================================\n\n";

echo "Based on this analysis, here's what works best for tag generation:\n\n";

echo "1. SIMPLE & EFFECTIVE APPROACH:\n";
echo "   ✓ Extract main nouns and noun phrases from question\n";
echo "   ✓ Add key concepts from first 1-2 sentences of answer\n";
echo "   ✓ Use question words as primary tags\n";
echo "   ✓ Keep 5-8 tags (not too many, not too few)\n\n";

echo "2. WHAT TO AVOID:\n";
echo "   ✗ Generic words (research, conference, ACUR, university)\n";
echo "   ✗ Verbs and adjectives (unless very specific)\n";
echo "   ✗ Too many similar variations\n";
echo "   ✗ Overly complex phrases (>3 words)\n\n";

echo "3. GOOD TAG EXAMPLES:\n";
echo "   ✓ 'Abstract' - specific concept\n";
echo "   ✓ 'Registration Fee' - specific phrase\n";
echo "   ✓ 'First Year Student' - specific group\n";
echo "   ✓ 'Public Speaking' - specific concern\n";
echo "   ✓ 'Travel Accommodation' - specific topic\n\n";

echo "4. SUGGESTED MANUAL TAGS FOR EACH FAQ CATEGORY:\n\n";

$suggested_categories = [
    "Abstract/Submission" => ["Abstract", "Submission", "Research Summary", "Project Description"],
    "Presentation Types" => ["Oral Presentation", "Poster", "Presentation Format", "Talk"],
    "Eligibility" => ["Eligible", "Requirements", "First Year", "Undergraduate Student"],
    "Costs/Fees" => ["Fee", "Cost", "Registration Fee", "Budget", "Funding"],
    "Travel" => ["Travel", "Accommodation", "Flight", "Hotel"],
    "Support/Help" => ["Support", "Help", "Anxiety", "Questions", "Assistance"]
];

foreach ($suggested_categories as $category => $tags) {
    echo "  {$category}:\n";
    echo "    → " . implode(', ', $tags) . "\n\n";
}

echo "\n";
echo "Would you like a simpler, manual tag suggestion approach? (Y/n)\n";
