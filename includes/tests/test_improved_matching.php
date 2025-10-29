<?php
/**
 * Test script for improved matching with stemming and synonyms
 * Run this from command line: php test_improved_matching.php
 */

// Simulate WordPress environment
define('ABSPATH', dirname(__DIR__) . '/');

// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load our classes
require_once __DIR__ . '/class-acur-text-processor.php';
require_once __DIR__ . '/class-acur-matcher.php';

echo "=== Testing Improved Matching with Stemming and Synonyms ===\n\n";

// Test 1: Stemming functionality
echo "TEST 1: Stemming Functionality\n";
echo str_repeat("-", 50) . "\n";

$test_words = ['running', 'runs', 'ran', 'runner', 'disability', 'disabilities', 'wheelchair', 'wheelchairs'];
echo "Original words: " . implode(', ', $test_words) . "\n";
echo "Stemmed words:  ";
foreach ($test_words as $word) {
    $stemmed = ACURCB_TextProcessor::stem_word($word);
    echo "$stemmed, ";
}
echo "\n\n";

// Test 2: Synonym expansion
echo "TEST 2: Synonym Expansion\n";
echo str_repeat("-", 50) . "\n";

$test_queries = ['assistance', 'help', 'support', 'aid'];
foreach ($test_queries as $query) {
    echo "Word: '$query'\n";
    $synonyms = ACURCB_TextProcessor::get_synonyms($query);
    if (!empty($synonyms)) {
        echo "Synonyms: " . implode(', ', array_slice($synonyms, 0, 5)) . "\n";
    } else {
        echo "No synonyms found\n";
    }
    echo "\n";
}

// Test 3: Query expansion
echo "TEST 3: Query Expansion with Synonyms\n";
echo str_repeat("-", 50) . "\n";

$sample_query = "How can I get help with wheelchair access?";
echo "Original query: $sample_query\n";
$keywords = ACURCB_TextProcessor::process_text($sample_query, true);
echo "Keywords (stemmed): " . implode(', ', $keywords) . "\n";
$expanded = ACURCB_TextProcessor::expand_query_with_synonyms($keywords, 2);
echo "Expanded terms: " . implode(', ', $expanded) . "\n\n";

// Test 4: Keyword extraction comparison
echo "TEST 4: Keyword Extraction - With vs Without Stemming\n";
echo str_repeat("-", 50) . "\n";

$test_text = "Students with disabilities can access various support services";
echo "Text: $test_text\n";
echo "Without stemming: " . implode(', ', ACURCB_TextProcessor::process_text($test_text, false)) . "\n";
echo "With stemming:    " . implode(', ', ACURCB_TextProcessor::process_text($test_text, true)) . "\n\n";

// Test 5: Full matching scenario with sample FAQs
echo "TEST 5: Matching with Sample FAQs\n";
echo str_repeat("-", 50) . "\n";

$sample_faqs = [
    [
        'id' => 1,
        'question' => 'How do I access disability support services?',
        'answer' => 'Students with disabilities can contact the accessibility office for comprehensive support services including academic accommodations.',
        'tags' => json_encode(['disability', 'support', 'accessibility'])
    ],
    [
        'id' => 2,
        'question' => 'Where can I find wheelchair accessible facilities?',
        'answer' => 'All campus buildings have wheelchair ramps and elevators. Contact facilities management for specific accessibility information.',
        'tags' => json_encode(['wheelchair', 'accessibility', 'facilities'])
    ],
    [
        'id' => 3,
        'question' => 'What financial aid is available?',
        'answer' => 'Various scholarships, grants, and loans are available. Visit the financial aid office or check our website for details.',
        'tags' => json_encode(['financial', 'aid', 'scholarships'])
    ]
];

$test_queries_matching = [
    "I need help with disabled student services",
    "Where are the wheelchair ramps?",
    "Can I get assistance with accessibility?",
    "Looking for monetary support for studies"
];

foreach ($test_queries_matching as $query) {
    echo "\nQuery: \"$query\"\n";
    $result = ACURCB_Matcher::match($query, 3, $sample_faqs);
    echo "Best Match (ID: {$result['id']}): " . substr($result['answer'], 0, 80) . "...\n";
    echo "Score: " . round($result['score'], 4) . "\n";

    if (!empty($result['alternates'])) {
        echo "Alternates:\n";
        foreach ($result['alternates'] as $alt) {
            echo "  - ID {$alt['id']}: " . round($alt['score'], 4) . "\n";
        }
    }
}

// Test 6: Library availability check
echo "\n\nTEST 6: Library Availability Check\n";
echo str_repeat("-", 50) . "\n";

$libs = ACURCB_TextProcessor::check_libraries();
echo "Stemming library available: " . ($libs['stemming'] ? 'YES' : 'NO') . "\n";
echo "Synonym library available:  " . ($libs['synonym'] ? 'YES' : 'NO') . "\n";

echo "\n=== Testing Complete ===\n";
