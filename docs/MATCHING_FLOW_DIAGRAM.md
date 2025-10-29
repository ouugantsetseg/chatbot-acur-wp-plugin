# FAQ Matching Flow Diagram

## Current Flow (Before Improvements)

```
┌─────────────────────────────────────────────────────────────────┐
│                    User Question Input                          │
│                 "Can I submit unfinished work?"                 │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│              STEP 1: Extract Tags from Question                 │
│  • Extract keywords with stemming                               │
│  • Generate bigrams/trigrams                                    │
│  • Add synonym expansions                                       │
│  Result: ["submit", "unfinish", "work", "submit unfinish", ...] │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│              STEP 2: Filter FAQs by Tag Matching                │
│  • Compare question tags with FAQ tags                          │
│  • Keep only FAQs with tag overlap                              │
│  • Fallback to ALL FAQs if no matches                           │
│  Result: Reduced candidate set (e.g., 8 FAQs from 22)          │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│         STEP 3: Calculate BM25 Score (OLD - SIMPLIFIED)         │
│  For each candidate FAQ:                                        │
│    • Extract FAQ keywords (question x2 + answer)                │
│    • Expand user query with synonyms                            │
│    • Calculate BM25 with FIXED IDF = 1.0  ❌ PROBLEM!          │
│    • Apply synonym penalty (0.95 if synonyms used)              │
│  Result: BM25 score (0.0 - ~2.0 range)                         │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│              STEP 4: Calculate Tag Boost Score                  │
│  • Match question tags with FAQ tags                            │
│  • Exact match: +0.15 per tag                                   │
│  • Substring match: +0.08 per tag                               │
│  • Cap at max 0.3  ❌ TOO LOW!                                  │
│  Result: Tag boost (0.0 - 0.3)                                  │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│              STEP 5: Combine Scores & Sort                      │
│  final_score = BM25_score + tag_boost                           │
│  Sort FAQs by final_score (descending)                          │
│  Result: Ranked list of FAQs                                    │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│              STEP 6: Threshold Check                            │
│  IF best_score < 0.25:                                          │
│    Return "I'm not sure..." message                             │
│  ELSE:                                                           │
│    Return best match + alternates                               │
└─────────────────────────────────────────────────────────────────┘

CURRENT ACCURACY: 35.1% Hit@1 ❌
```

---

## Improved Flow (After Optimization)

```
┌─────────────────────────────────────────────────────────────────┐
│                    User Question Input                          │
│                 "Can I submit unfinished work?"                 │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│              STEP 1: Extract Tags from Question                 │
│  • Extract keywords with stemming                               │
│  • Generate bigrams/trigrams                                    │
│  • Add synonym expansions                                       │
│  Result: ["submit", "unfinish", "work", "submit unfinish", ...] │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│              STEP 2: Filter FAQs by Tag Matching                │
│  • Compare question tags with FAQ tags                          │
│  • Keep only FAQs with tag overlap                              │
│  • Fallback to ALL FAQs if no matches                           │
│  Result: Reduced candidate set (e.g., 8 FAQs from 22)          │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│  ✨ STEP 3: Calculate BM25 Score (NEW - PROPER IDF) ✨         │
│  ONE-TIME SETUP (cached):                                       │
│    • Load all FAQs from corpus                                  │
│    • Calculate document frequency (DF) for each term            │
│    • Compute IDF = log((N - DF + 0.5) / (DF + 0.5) + 1)        │
│    • Calculate average document length                          │
│                                                                  │
│  For each candidate FAQ:                                        │
│    • Extract FAQ keywords (question x2 + answer)                │
│    • Expand user query with synonyms                            │
│    • For each query term:                                       │
│        - Get PROPER IDF from cache  ✅ FIXED!                  │
│        - Calculate TF with saturation (k1=1.2)                  │
│        - Apply length normalization (b=0.75)                    │
│        - term_score = IDF × TF_normalized                       │
│    • Sum all term scores, normalize by query length             │
│                                                                  │
│  Result: BM25 score (now with proper IDF weighting)            │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│  ✨ STEP 4: Calculate Enhanced Scores ✨                       │
│                                                                  │
│  A) Tag Boost Score (INCREASED):                                │
│     • Exact match: +0.15 per tag                                │
│     • Substring match: +0.08 per tag                            │
│     • Cap at max 0.5  ✅ INCREASED from 0.3                    │
│                                                                  │
│  B) Exact Match Bonus (NEW):                                    │
│     • Check if user question is substring of FAQ question       │
│     • Check string similarity ratio                             │
│     • IF similarity > 90%: +0.8 bonus  ✅ NEW!                 │
│     • IF similarity > 75%: +0.4 bonus  ✅ NEW!                 │
│                                                                  │
│  C) Question Field Boost (NEW):                                 │
│     • Separate BM25 for FAQ question only                       │
│     • If term matches question: 1.5x multiplier  ✅ NEW!       │
│                                                                  │
│  Result: Multiple score components                              │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│  ✨ STEP 5: Combine Scores with Weights ✨                     │
│                                                                  │
│  final_score = (BM25_score × 0.6)                               │
│              + (tag_boost × 0.2)                                │
│              + (exact_match_bonus × 0.15)                       │
│              + (question_boost × 0.05)                          │
│                                                                  │
│  Sort FAQs by final_score (descending)                          │
│  Result: Better ranked list of FAQs                             │
└────────────────────────────┬────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│  ✨ STEP 6: Smart Threshold Check ✨                           │
│                                                                  │
│  IF exact_match_bonus > 0.5:                                    │
│    Return match immediately (high confidence)  ✅ NEW!         │
│  ELSE IF best_score < 0.25:                                     │
│    Return "I'm not sure..." message                             │
│  ELSE:                                                           │
│    Return best match + alternates                               │
└─────────────────────────────────────────────────────────────────┘

EXPECTED ACCURACY: 55-65% Hit@1 ✅ (+20-30% improvement)
```

---

## Key Improvements Summary

### 1️⃣ Proper BM25 with IDF
**Before:**
```php
$idf = 1.0;  // Fixed for all terms ❌
```

**After:**
```php
// Calculate IDF from corpus
$idf_values = [];
foreach ($doc_freq as $term => $df) {
    $idf_values[$term] = log(($total_docs - $df + 0.5) / ($df + 0.5) + 1);
}
// Use proper IDF for each term ✅
$idf = $idf_values[$query_term] ?? 2.5;
```

**Impact:** Common words (research, conference) get lower weight, distinctive words get higher weight.

---

### 2️⃣ Exact Match Bonus
**Before:**
- No bonus for near-exact matches ❌

**After:**
```php
$similarity = similar_text_ratio($user_question, $faq_question);
if ($similarity > 0.9) {
    $exact_bonus = 0.8;  // Very strong match
} else if ($similarity > 0.75) {
    $exact_bonus = 0.4;  // Good match
}
```

**Impact:** Questions like "Can I submit?" will strongly match "Can I submit my work?" even if BM25 is low.

---

### 3️⃣ Increased Tag Boost
**Before:**
```php
return min($boost, 0.3);  // Max 0.3 ❌
```

**After:**
```php
return min($boost, 0.5);  // Max 0.5 ✅
```

**Impact:** Tags have more influence on final ranking.

---

### 4️⃣ Weighted Score Combination
**Before:**
```php
$final_score = $bm25_score + $tag_boost;  // Simple addition ❌
```

**After:**
```php
$final_score = ($bm25 × 0.6) + ($tag × 0.2) + ($exact × 0.15) + ($question × 0.05);
```

**Impact:** Balanced contribution from multiple signals.

---

## Performance Considerations

### IDF Cache
- **First call:** ~10-20ms (computes IDF for entire corpus)
- **Subsequent calls:** <1ms (uses cached values)
- **Memory:** ~5-10KB for typical FAQ corpus
- **Invalidation:** Call `clear_idf_cache()` when FAQs are updated

### Optimization Tips
```php
// Pre-compute IDF on FAQ save/update
add_action('acur_faq_updated', function() {
    ACURCB_Matcher::clear_idf_cache();
    ACURCB_Matcher::get_idf_cache(); // Warm up cache
});
```

---

## Testing the Improvements

Run the evaluation:
```bash
php matcher_eval_standalone.php \
  --faqs="test_data_faqs.json" \
  --queries="test_data_queries.json" \
  --topk=5
```

Expected metrics:
- **Before:** Hit@1 = 35.1%, MRR = 47.8%
- **After:** Hit@1 = 55-65%, MRR = 65-75%
- **Improvement:** +20-30 percentage points

---

## Example: How Scoring Changes

### Query: "Can I submit unfinished work?"

#### Before (Old Scoring):
```
FAQ #12: "Is unfinished research allowed?"
  - BM25 (IDF=1.0): 0.45
  - Tag boost: 0.15
  - Final: 0.60
  - Rank: #3 ❌

FAQ #15: "Are group projects allowed?"
  - BM25 (IDF=1.0): 0.52
  - Tag boost: 0.23
  - Final: 0.75
  - Rank: #1 ❌ WRONG!
```

#### After (New Scoring):
```
FAQ #12: "Is unfinished research allowed?"
  - BM25 (proper IDF): 0.78  ← "unfinished" has high IDF
  - Tag boost: 0.25
  - Exact bonus: 0.40  ← High similarity
  - Final: 0.92
  - Rank: #1 ✅ CORRECT!

FAQ #15: "Are group projects allowed?"
  - BM25 (proper IDF): 0.35  ← "group" not in query
  - Tag boost: 0.20
  - Exact bonus: 0.00
  - Final: 0.34
  - Rank: #5 ✅
```

---

## Future Enhancements (Phase 2)

If 55-65% accuracy is not sufficient:

1. **Semantic Embeddings (Sentence-BERT)**
   - Expected: 75-85% accuracy
   - Requires: Python microservice or external API

2. **Learning to Rank**
   - Use user feedback to refine weights
   - Adaptive scoring based on click-through data

3. **Query Expansion**
   - Add domain-specific synonyms
   - Handle acronyms (ACUR, FAQ, etc.)

4. **Multi-field BM25**
   - Separate scoring for question vs answer fields
   - Different k1/b parameters per field
