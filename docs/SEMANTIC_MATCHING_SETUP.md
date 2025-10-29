# Semantic Matching Setup Guide

Complete guide to set up and use the new semantic matching system with all-MiniLM-L6-v2.

## Overview

The new matching system uses semantic embeddings instead of keyword matching:
- **Old system:** BM25 + tags (35.1% accuracy)
- **New system:** Semantic embeddings (expected 75-85% accuracy)

## Architecture

```
Admin creates FAQ
    ↓
FAQ sent to Python API
    ↓
Embedding generated (384-dim vector)
    ↓
Embedding stored in database

User asks question
    ↓
Question sent to Python API
    ↓
Question embedding generated
    ↓
Compare with all FAQ embeddings (cosine similarity)
    ↓
Return best match
```

## Setup Steps

### Step 1: Set Up Python Embedding Service

```bash
# Navigate to embedding service directory
cd embedding_service

# Create virtual environment
python -m venv venv

# Activate virtual environment
# Windows:
venv\Scripts\activate
# Linux/Mac:
source venv/bin/activate

# Install dependencies
pip install -r requirements.txt

# Run the service
uvicorn embedding_service:app --host 0.0.0.0 --port 8000 --reload
```

**Verify service is running:**
```bash
# In a new terminal
curl http://localhost:8000/health
```

You should see:
```json
{
  "status": "healthy",
  "model": "all-MiniLM-L6-v2",
  "dimension": 384,
  "message": "Service is running"
}
```

### Step 2: Update Database Schema

```bash
# Navigate to WordPress root
cd /path/to/wordpress

# Run migration script
php -r "define('WP_USE_THEMES', false); require('wp-load.php'); require('wp-content/plugins/chatbot-acur-wp-plugin/includes/db_migration_add_embeddings.php');"
```

This will add three columns to the `wp_faqs` table:
- `embedding` (TEXT) - JSON array of 384 float values
- `embedding_version` (VARCHAR) - Model version identifier
- `embedding_updated_at` (DATETIME) - Timestamp

### Step 3: Generate Embeddings for Existing FAQs

```bash
# Run batch embedding script
php -r "define('WP_USE_THEMES', false); require('wp-load.php'); require('wp-content/plugins/chatbot-acur-wp-plugin/includes/batch_embed_faqs.php');"
```

This will:
- Check embedding service status
- Generate embeddings for all FAQs without embeddings
- Display progress and statistics

**Force regenerate all embeddings:**
```bash
php batch_embed_faqs.php --force
```

### Step 4: Update Plugin Code to Use New Matcher

Find where `ACURCB_Matcher::match()` is called in your plugin code and replace it:

**Old code:**
```php
$result = ACURCB_Matcher::match($question);
```

**New code:**
```php
// Load new classes
require_once plugin_dir_path(__FILE__) . 'includes/class-acur-embeddings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-acur-matcher-v1.php';

// Use new matcher
$result = ACURCB_Matcher_V1::match($question);
```

### Step 5: Set Up Auto-Embedding for New FAQs

Add this to your FAQ save handler:

```php
// In includes/class-acur-admin.php or wherever FAQs are saved

// After saving FAQ to database
add_action('acur_faq_saved', function($faq_id) {
    // Generate embedding for new FAQ
    ACURCB_Embeddings::generate_faq_embedding($faq_id);
}, 10, 1);

// In your FAQ save function, trigger the action:
function save_faq($faq_data) {
    global $wpdb;

    // Save FAQ
    $wpdb->insert($wpdb->prefix . 'faqs', $faq_data);
    $faq_id = $wpdb->insert_id;

    // Trigger embedding generation
    do_action('acur_faq_saved', $faq_id);

    return $faq_id;
}
```

## Testing

### Test 1: Verify Embedding Service

```bash
curl -X POST http://localhost:8000/embed \
  -H "Content-Type: application/json" \
  -d '{"text": "Can I submit unfinished work?"}'
```

Expected response:
```json
{
  "embedding": [0.023, -0.145, ...],  // 384 numbers
  "dimension": 384,
  "processing_time_ms": 45.23
}
```

### Test 2: Check Embedding Coverage

```php
<?php
require_once 'includes/class-acur-embeddings.php';

$stats = ACURCB_Embeddings::get_embedding_stats();
print_r($stats);
```

Expected output:
```
Array (
    [total_faqs] => 22
    [embedded_faqs] => 22
    [missing_embeddings] => 0
    [coverage_percentage] => 100
)
```

### Test 3: Test Matching

```php
<?php
require_once 'includes/class-acur-matcher-v1.php';

ACURCB_Matcher_V1::set_performance_tracking(true);

$result = ACURCB_Matcher_V1::match("Can I submit unfinished work?");

print_r($result);
```

Expected output:
```
Array (
    [answer] => "Yes, you can submit work in progress..."
    [score] => 0.87
    [id] => 12
    [question] => "Is incomplete research allowed?"
    [alternates] => Array (...)
    [performance] => Array (
        [total_ms] => 65.23
        [embedding_ms] => 48.12
        [load_ms] => 3.45
        [similarity_ms] => 2.34
        [faq_count] => 22
    )
)
```

### Test 4: Run Evaluation Script

Create `test_semantic_matching.php`:

```php
<?php
require_once 'includes/class-acur-matcher-v1.php';

$test_queries = [
    ['query' => 'Can I submit unfinished work?', 'expected_id' => 12],
    ['query' => 'What is the registration fee?', 'expected_id' => 20],
    ['query' => 'Do I need to present?', 'expected_id' => 6],
    // Add more test cases
];

$correct = 0;
$total = count($test_queries);

foreach ($test_queries as $test) {
    $result = ACURCB_Matcher_V1::match($test['query']);

    if ($result['id'] == $test['expected_id']) {
        $correct++;
        echo "✓ PASS: '{$test['query']}' → FAQ #{$result['id']} (score: {$result['score']})\n";
    } else {
        echo "✗ FAIL: '{$test['query']}' → FAQ #{$result['id']} (expected #{$test['expected_id']})\n";
    }
}

$accuracy = ($correct / $total) * 100;
echo "\nAccuracy: {$correct}/{$total} ({$accuracy}%)\n";
```

## Configuration

### Adjust Similarity Threshold

```php
// Lower threshold = more lenient matching
ACURCB_Matcher_V1::set_config('similarity_threshold', 0.55);

// Higher threshold = stricter matching
ACURCB_Matcher_V1::set_config('similarity_threshold', 0.75);
```

### Change Embedding Service URL

```php
// If running on different server/port
ACURCB_Embeddings::set_config('embedding_service_url', 'http://192.168.1.100:8000');
ACURCB_Matcher_V1::set_config('embedding_service_url', 'http://192.168.1.100:8000');
```

### Adjust Question Weight

```php
// Question is repeated 3 times (higher weight)
ACURCB_Embeddings::set_config('question_weight', 3);

// Question only once (equal weight with answer)
ACURCB_Embeddings::set_config('question_weight', 1);
```

## Production Deployment

### Run Embedding Service as System Service

**Linux (systemd):**

Create `/etc/systemd/system/acur-embedding.service`:
```ini
[Unit]
Description=ACUR Embedding Service
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/html/wp-content/plugins/chatbot-acur-wp-plugin/embedding_service
Environment="PATH=/var/www/html/wp-content/plugins/chatbot-acur-wp-plugin/embedding_service/venv/bin"
ExecStart=/var/www/html/wp-content/plugins/chatbot-acur-wp-plugin/embedding_service/venv/bin/uvicorn embedding_service:app --host 0.0.0.0 --port 8000 --workers 2
Restart=always

[Install]
WantedBy=multi-user.target
```

Enable and start:
```bash
sudo systemctl daemon-reload
sudo systemctl enable acur-embedding
sudo systemctl start acur-embedding
sudo systemctl status acur-embedding
```

**Windows:**

Use NSSM (Non-Sucking Service Manager):
```bash
nssm install ACUREmbedding "C:\path\to\venv\Scripts\uvicorn.exe" "embedding_service:app --host 0.0.0.0 --port 8000"
nssm start ACUREmbedding
```

## Monitoring and Maintenance

### Check Service Health

```bash
curl http://localhost:8000/health
```

### View Service Logs

```bash
# If using systemd
sudo journalctl -u acur-embedding -f

# If running directly
# Logs appear in the terminal where you started the service
```

### Monitor Performance

```php
<?php
ACURCB_Matcher_V1::set_performance_tracking(true);

// Run some queries
$result1 = ACURCB_Matcher_V1::match("Query 1");
$result2 = ACURCB_Matcher_V1::match("Query 2");
$result3 = ACURCB_Matcher_V1::match("Query 3");

// Get performance metrics
$metrics = ACURCB_Matcher_V1::get_performance_metrics();
print_r($metrics);
```

### Regenerate Embeddings When Model Updates

```bash
# When you upgrade to a newer model
php batch_embed_faqs.php --force
```

## Troubleshooting

### Problem: "Cannot connect to embedding service"

**Solution:**
```bash
# Check if service is running
curl http://localhost:8000/health

# If not, start it
cd embedding_service
uvicorn embedding_service:app --host 0.0.0.0 --port 8000

# Check firewall
sudo ufw allow 8000
```

### Problem: "Invalid embedding dimension"

**Cause:** FAQ has old or corrupted embedding

**Solution:**
```php
// Regenerate embeddings for specific FAQ
ACURCB_Embeddings::generate_faq_embedding($faq_id);

// Or regenerate all
ACURCB_Embeddings::batch_generate_all_embeddings(true);
```

### Problem: Low accuracy (<60%)

**Possible causes:**
1. Similarity threshold too high
2. Question weight too low
3. FAQs not well-written

**Solutions:**
```php
// Lower threshold
ACURCB_Matcher_V1::set_config('similarity_threshold', 0.60);

// Increase question weight
ACURCB_Embeddings::set_config('question_weight', 3);

// Improve FAQ quality
// - Make questions more specific
// - Add more context to answers
// - Ensure questions use natural language
```

### Problem: Slow response time (>200ms)

**Possible causes:**
1. Too many FAQs to compare
2. Embedding service overloaded
3. Network latency

**Solutions:**
```bash
# Increase embedding service workers
uvicorn embedding_service:app --workers 4

# Use faster hardware for embedding service
# Add caching layer for frequent queries
```

## Expected Performance

### Accuracy
- **Old system (BM25):** 35.1% Hit@1
- **New system (Embeddings):** 75-85% Hit@1 (expected)
- **Improvement:** +40-50 percentage points

### Speed
- **Embedding generation (admin):** 50-100ms per FAQ
- **Query matching (user):** 60-80ms total
  - Embed question: ~50ms
  - Load embeddings: ~5ms
  - Calculate similarity: ~2-8ms (depends on FAQ count)

### Resource Usage
- **Python service memory:** ~500MB
- **Database storage:** ~1.5KB per FAQ (for embedding)
- **CPU:** Low (model is lightweight)

## Migration from Old System

### Parallel Running (Recommended)

Keep old system as fallback while testing new system:

```php
function match_faq($question) {
    // Try new matcher first
    $new_result = ACURCB_Matcher_V1::match($question);

    // If confidence is high, use new result
    if ($new_result['score'] >= 0.75) {
        return $new_result;
    }

    // Otherwise, try old matcher
    $old_result = ACURCB_Matcher::match($question);

    // Return better of the two
    if ($new_result['score'] > $old_result['score']) {
        return $new_result;
    }

    return $old_result;
}
```

### Full Migration

Once confident in new system:

```php
// Replace all calls to ACURCB_Matcher::match()
// with ACURCB_Matcher_V1::match()

// Can optionally keep old matcher as backup
```

## Next Steps

1. ✅ Set up Python embedding service
2. ✅ Run database migration
3. ✅ Generate embeddings for existing FAQs
4. ✅ Update plugin code
5. ✅ Test with sample queries
6. ✅ Monitor accuracy and performance
7. ✅ Deploy to production

## Support

For issues or questions:
1. Check embedding service logs
2. Verify all FAQs have embeddings
3. Test with simple queries first
4. Check configuration settings
