# Test Data Documentation

## Overview

Test data files for the simplified tag-only matching logic, based on the actual FAQ database structure.

## Files

### 1. `test_data_faqs.json`
Contains 19 FAQs from the actual database with proper structure:
- `id`: FAQ ID (from database)
- `question`: FAQ question text
- `answer`: FAQ answer text
- `tags`: JSON array of tags (auto-generated from question + answer)

**Example:**
```json
{
  "id": 4,
  "question": "What is an abstract?",
  "answer": "An abstract is a short summary of your research project...",
  "tags": "[\"abstract\",\"research\",\"short summary\",\"project\"]"
}
```

### 2. `test_data_queries.json`
Contains 60+ test queries with expected results:
- `query`: User question to test
- `expected_faq_id`: Which FAQ should match
- `query_type`: Type of query (exact_match, paraphrase, keyword, related)
- `difficulty`: easy, medium, or hard
- `expected_tags_match`: Which tags should match

**Example:**
```json
{
  "query": "What is an abstract?",
  "expected_faq_id": 4,
  "query_type": "exact_match",
  "difficulty": "easy",
  "expected_tags_match": ["abstract"]
}
```

### 3. `test_matcher_simple.php`
Simple test runner that:
- Loads test data from JSON files
- Runs matcher on all test queries
- Calculates accuracy, performance metrics
- Shows detailed results
- Exports results to JSON

## Running Tests

### Basic Usage
```bash
cd includes
php test_matcher_simple.php
```

### Expected Output
```
==========================================
  Simple Tag-Only Matcher Test
==========================================

Loaded 19 test FAQs
Loaded 60 test queries

Running tests...
--------------------------------------------------------------------------------

[1/60] ✓ CORRECT exact_match
   Query: "What is an abstract?"
   Expected: FAQ #4 | Got: FAQ #4 | Score: 0.850 | Time: 0.45ms
   Matched Tags: abstract

[2/60] ✓ CORRECT paraphrase
   Query: "Can you explain what an abstract is?"
   Expected: FAQ #4 | Got: FAQ #4 | Score: 0.750 | Time: 0.38ms
   Matched Tags: abstract

...

==========================================
  TEST RESULTS SUMMARY
==========================================

Total Tests:        60
✓ Correct:          45 (75.0%)
✗ Wrong:            10 (16.7%)
❌ No Match:        5 (8.3%)

Average Time:       0.385 ms
Total Time:         23.10 ms
Throughput:         2597 queries/sec

Accuracy by Difficulty:
  Easy      : 30/35 (85.7%)
  Medium    : 13/20 (65.0%)
  Hard      : 2/5 (40.0%)

Overall Rating:     EXCELLENT ✓✓✓
```

## Test Query Types

### 1. Exact Match
User query exactly matches FAQ question
```json
{
  "query": "What is an abstract?",
  "expected_faq_id": 4
}
```

### 2. Paraphrase
User query is rephrased but means the same thing
```json
{
  "query": "Can you explain what an abstract is?",
  "expected_faq_id": 4
}
```

### 3. Keyword
Short keyword-based query
```json
{
  "query": "abstract definition",
  "expected_faq_id": 4
}
```

### 4. Related
Query related to the topic but uses different words
```json
{
  "query": "research summary explanation",
  "expected_faq_id": 4
}
```

## Difficulty Levels

### Easy (Expected 80%+ accuracy)
- Exact matches
- Direct keyword matches
- Common paraphrases

### Medium (Expected 60-80% accuracy)
- Related concepts
- Partial keyword matches
- Synonyms

### Hard (Expected 40-60% accuracy)
- Very short queries
- Ambiguous queries
- Multiple possible matches

## Understanding Results

### Accuracy Metrics

**Overall Accuracy (Hit@1)**
- Percentage of queries that returned the correct FAQ as #1 result
- Target: ≥ 60% (Good), ≥ 70% (Excellent)

**By Difficulty**
- Easy: Should be 80%+
- Medium: Should be 60%+
- Hard: 40%+ is acceptable

### Performance Metrics

**Average Time**
- Time per query in milliseconds
- Target: < 1ms per query

**Throughput**
- Queries per second
- Target: > 1000 QPS

### Common Issues

**Low Accuracy (<60%)**
```
Problem: Tags not comprehensive enough
Solution: Increase tag limit in suggest_tags()
         Review stopwords list
         Add more multi-word phrases
```

**High No-Match Rate (>10%)**
```
Problem: Threshold too high
Solution: Lower min_score_threshold from 0.25 to 0.20
         Check tag generation quality
```

**Wrong FAQ Matched**
```
Problem: Tags too generic or overlapping
Solution: Make tags more specific
         Add context-specific keywords
         Review confused FAQ pairs
```

## Adding New Test Cases

### 1. Add to `test_data_queries.json`

```json
{
  "query": "Your new test query",
  "expected_faq_id": 4,
  "query_type": "keyword",
  "difficulty": "medium",
  "expected_tags_match": ["tag1", "tag2"]
}
```

### 2. Run Tests
```bash
php test_matcher_simple.php
```

### 3. Review Results
Check if new test passed and adjust tags if needed

## Analyzing Failed Tests

Failed tests are exported to `test_results_YYYYMMDD_HHMMSS.json`:

```json
{
  "failed_tests": [
    {
      "query": "tell me about research summary",
      "expected": 4,
      "got": 10,
      "score": 0.650,
      "difficulty": "medium",
      "type": "related"
    }
  ]
}
```

### Common Failure Patterns

**Pattern 1: Generic keywords match wrong FAQ**
- Example: "research" matches FAQ #10 instead of FAQ #4
- Fix: Add more specific multi-word tags like "research summary", "research project"

**Pattern 2: Short queries get no match**
- Example: "fees" returns null
- Fix: Lower threshold OR add single-word tags

**Pattern 3: Synonyms not recognized**
- Example: "cost" doesn't match "fee"
- Fix: Add synonym support or include both in tags

## Integration with Database

### Loading Real FAQs from Database

Replace JSON loading with database query:

```php
global $wpdb;
$test_faqs = $wpdb->get_results(
    "SELECT id, question, answer, tags FROM {$wpdb->prefix}faqs ORDER BY id",
    ARRAY_A
);
```

### Regenerating Tags for All FAQs

```php
foreach ($test_faqs as &$faq) {
    $tags = ACURCB_Matcher::suggest_tags($faq['question'], $faq['answer'], 10);
    $faq['tags'] = json_encode($tags);

    // Update database
    $wpdb->update(
        $wpdb->prefix . 'faqs',
        ['tags' => $faq['tags']],
        ['id' => $faq['id']]
    );
}
```

## Best Practices

### 1. Test Coverage
- ✅ Include all FAQ IDs at least once
- ✅ Test various query types (exact, paraphrase, keyword)
- ✅ Include edge cases (very short queries, typos)
- ✅ Test different difficulty levels

### 2. Tag Quality
- ✅ Tags should include both single words and phrases
- ✅ Prioritize question keywords over answer keywords
- ✅ Remove redundant tags
- ✅ Use 10-15 tags per FAQ

### 3. Continuous Testing
- ✅ Run tests after any matcher logic changes
- ✅ Run tests after updating FAQs or tags
- ✅ Track accuracy trends over time
- ✅ Review failed tests regularly

## Performance Benchmarks

### Expected Performance (19 FAQs)

| Metric | Target | Good | Excellent |
|--------|--------|------|-----------|
| Accuracy | ≥60% | 65-75% | ≥75% |
| Avg Time | <1ms | <0.5ms | <0.3ms |
| Throughput | >1000 QPS | >2000 QPS | >3000 QPS |

### Scaling (Projected)

| FAQ Count | Avg Time | Throughput |
|-----------|----------|------------|
| 19 | 0.3-0.5ms | 2000-3000 QPS |
| 50 | 0.8-1.2ms | 800-1200 QPS |
| 100 | 1.5-2.5ms | 400-650 QPS |
| 200 | 3.0-5.0ms | 200-330 QPS |

## Troubleshooting

### Tests Won't Run
```bash
# Check file paths
ls -la test_data_*.json

# Check PHP syntax
php -l test_matcher_simple.php

# Check dependencies
php -r "require 'class-acur-matcher.php'; echo 'OK';"
```

### JSON Parse Errors
```bash
# Validate JSON files
python -m json.tool test_data_faqs.json
python -m json.tool test_data_queries.json
```

### Low Accuracy Debugging
```php
// Enable detailed output
ACURCB_Matcher::set_performance_tracking(true);

// Check what tags are being generated
$tags = ACURCB_Matcher::suggest_tags($question, $answer);
print_r($tags);

// Check what's being matched
$result = ACURCB_Matcher::match($query, 5, $faqs);
print_r($result);
```

## Next Steps

1. ✅ Run baseline test: `php test_matcher_simple.php`
2. ✅ Review failed tests
3. ✅ Adjust tag generation if needed
4. ✅ Re-run tests and compare
5. ✅ Test with real user queries
6. ✅ Deploy to production

## Summary

These test files provide:
- ✅ Realistic test data from actual database
- ✅ Comprehensive test coverage (60+ queries)
- ✅ Automated accuracy measurement
- ✅ Performance benchmarking
- ✅ Detailed failure analysis
- ✅ Easy to extend and maintain

**Goal: Achieve ≥70% accuracy with <1ms average latency**
