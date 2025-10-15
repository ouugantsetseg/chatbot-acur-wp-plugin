<?php
/**
 
 * Usage:
 *   php -d display_errors=1 -d error_reporting=E_ALL matcher_eval_standalone.php \
 *     --faqs="faqs.csv" \ 
 *     --queries=queries="queries.csv"  \
 *     --topk=5 \
 *     --alpha=0.7 \
 *     --maxlat=2000
 *
 * Inputs:
 *   faqs.csv    => id,question,answer,tags
 *   queries.csv => query,gold_id
 *
 * Outputs (in script directory):
 *   matcher_user_results.csv
 *   matcher_user_metrics.json
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Australia/Melbourne');

// ---------- CLI ARGS ----------
$args = getopt("", [
  "faqs:",
  "queries:",
  "topk::",
  "alpha::",
  "maxlat::"
]);

$faqs_csv   = $args["faqs"]        ?? "faqs.csv";
$queries_csv= $args["queries"]     ?? "queries.csv";
$topk       = intval($args["topk"] ?? 5);
$alpha      = floatval($args["alpha"] ?? 0.7);
$maxlat     = intval($args["maxlat"] ?? 2000);

$faqs_csv = realpath($faqs_csv) ?: $faqs_csv;
$queries_csv = realpath($queries_csv) ?: $queries_csv;

if (!file_exists($faqs_csv)) { fwrite(STDERR, "faqs.csv not found at $faqs_csv\n"); exit(1); }
if (!file_exists($queries_csv)) { fwrite(STDERR, "queries.csv not found at $queries_csv\n"); exit(1); }

// ---------- CSV helpers ----------
function csv_read_assoc($path) {
  $rows = [];
  if (($fh = fopen($path, "r")) !== false) {
    $headers = fgetcsv($fh);
    while (($data = fgetcsv($fh)) !== false) {
      $row = [];
      foreach ($headers as $i => $h) { $row[$h] = $data[$i] ?? ""; }
      $rows[] = $row;
    }
    fclose($fh);
  }
  return $rows;
}

$FAQS = csv_read_assoc($faqs_csv);
$QUERIES = csv_read_assoc($queries_csv);

// Validate CSVs
if (!$FAQS) { fwrite(STDERR, "faqs.csv empty or invalid\n"); exit(1); }
if (!$QUERIES) { fwrite(STDERR, "queries.csv empty or invalid\n"); exit(1); }
foreach (["id","question","answer","tags"] as $col) {
  if (!array_key_exists($col, $FAQS[0])) { fwrite(STDERR, "faqs.csv missing column: $col\n"); exit(1); }
}
foreach (["query","gold_id"] as $col) {
  if (!array_key_exists($col, $QUERIES[0])) { fwrite(STDERR, "queries.csv missing column: $col\n"); exit(1); }
}

// ---------- Text utils ----------
function normalize_text($s) {
  $s = strtolower($s ?? "");
  $s = preg_replace("/[^\p{L}\p{N}\s?]/u", " ", $s);
  $s = preg_replace("/\s+/", " ", $s);
  return trim($s);
}

$STOP = array_flip(explode(" ", "the a an and or but in on at to for of with by is are was were be been being will would could should can may might must this that these those i me my we us our do you any get has had"));
function extract_keywords($text) {
  $text = normalize_text($text);
  if ($text === "") return [];
  $parts = explode(" ", $text);
  $out = [];
  $seen = [];
  foreach ($parts as $w) {
    if ($w === "" || isset($STOP[$w])) continue;
    if (mb_strlen($w) <= 2 && !in_array($w, ["do","you","any","get","has","had"])) continue;
    if (!isset($seen[$w])) { $out[] = $w; $seen[$w] = 1; }
  }
  return $out;
}

function jaccard_overlap($a, $b) {
  if (!$a || !$b) return 0.0;
  $sa = array_flip($a);
  $sb = array_flip($b);
  $inter = 0; foreach ($sa as $k=>$v) { if (isset($sb[$k])) $inter++; }
  $union = count($sa) + count($sb) - $inter;
  if ($union <= 0) return 0.0;
  return $inter / $union;
}

function levenshtein_ratio($s1, $s2) {
  $s1 = $s1 ?? ""; $s2 = $s2 ?? "";
  if ($s1 === "" && $s2 === "") return 1.0;
  // Use PHP's built-in levenshtein on normalized strings (ASCII-ish path)
  $t1 = normalize_text($s1);
  $t2 = normalize_text($s2);
  $n = mb_strlen($t1); $m = mb_strlen($t2);
  if ($n === 0 || $m === 0) {
    $max = max($n, $m);
    return $max ? (1.0 - ($max / $max)) : 1.0;
  }
  $dist = levenshtein($t1, $t2);
  $maxlen = max($n, $m);
  return 1.0 - ($dist / ($maxlen ?: 1));
}

function parse_tags_any($raw) {
  if ($raw === null) return [];
  $s = trim((string)$raw);
  if ($s === "") return [];
  // Try JSON
  if ($s[0] === "[" && substr($s, -1) === "]") {
    $decoded = json_decode($s, true);
    if (is_array($decoded)) {
      $tags = [];
      foreach ($decoded as $t) { $tags[] = normalize_text((string)$t); }
      return array_values(array_filter($tags, fn($x)=>$x!==""));
    }
  }
  // Fallback split on ; or ,
  $parts = preg_split("/[;,]/", $s);
  $tags = array_map(fn($t)=>normalize_text($t), $parts);
  return array_values(array_filter($tags, fn($x)=>$x!==""));
}

// ---------- Your matcher logic (embedded) ----------
class ACURCB_Matcher_Embedded {

  private $faqs;

  public function __construct($faqs) {
    // Expect array of rows with id, question, answer, tags
    $this->faqs = $faqs;
  }

  private function text_similarity($text1, $text2) {
    $t1 = normalize_text($text1);
    $t2 = normalize_text($text2);
    if ($t1 !== "" && $t2 !== "" && (str_contains($t2, $t1) || str_contains($t1, $t2))) {
      return 1.0;
    }
    $w1 = extract_keywords($t1);
    $w2 = extract_keywords($t2);
    $jacc = jaccard_overlap($w1, $w2);
    $lev = 0.0;
    if (mb_strlen($t1) < 100 && mb_strlen($t2) < 100) {
      $lev = levenshtein_ratio($t1, $t2);
    }
    return max($jacc, $lev);
  }

  private function tag_signal($user_q, $faq_row) {
    $score = 0.0;
    $uq = normalize_text($user_q);
    $user_kw = extract_keywords($uq);
    $user_set = array_flip($user_kw);
    $tags = parse_tags_any($faq_row['tags'] ?? "");

    // 1) Direct substring hit on tag
    foreach ($tags as $tag) {
      if ($tag !== "" && str_contains($uq, $tag)) { $score += 0.7; }
    }
    // 2) Keyword overlap
    foreach ($tags as $tag) {
      $tw = extract_keywords($tag);
      foreach ($tw as $w) { if (isset($user_set[$w])) { $score += 0.2; } }
    }
    // 3) Tag appears in FAQ Q/A
    $fq = normalize_text((string)($faq_row['question'] ?? ""));
    $fa = normalize_text((string)($faq_row['answer'] ?? ""));
    foreach ($tags as $tag) {
      if ($tag !== "" && (str_contains($fq, $tag) || str_contains($fa, $tag))) { $score += 0.1; }
    }
    return min($score, 1.0);
  }

  private function total_similarity($user_q, $faq_row) {
    $fq = (string)($faq_row['question'] ?? "");
    $fa = (string)($faq_row['answer'] ?? "");

    $question_score = $this->text_similarity($user_q, $fq) * 0.5;
    $answer_score   = $this->text_similarity($user_q, $fa) * 0.2;
    $tag_sig        = $this->tag_signal($user_q, $faq_row); // raw
    $tag_score      = $tag_sig * 0.3;

    $total = $question_score + $answer_score + $tag_score;

    // Tag boost if raw tag_sig > 0.15 -> multiply by 1.2 (cap at 1.0)
    if ($tag_sig > 0.15) { $total = min($total * 1.2, 1.0); }

    return $total;
  }

  public function match($question, $top_k = 5) {
    $scores = [];
    foreach ($this->faqs as $row) {
      $sid = intval($row['id']);
      $s = $this->total_similarity($question, $row);
      $scores[] = ['id' => $sid, 'score' => $s, 'answer' => $row['answer']];
    }
    usort($scores, fn($a,$b)=> $b['score'] <=> $a['score']);
    $top = array_slice($scores, 0, max(1, $top_k));
    $best = $top[0] ?? ['id'=>null,'score'=>0.0,'answer'=>''];
    // Build API-compatible shape
    $alts = [];
    foreach (array_slice($top, 1) as $a) {
      $alts[] = ['id' => $a['id'], 'score' => $a['score']];
    }
    return [
      'id' => $best['id'],
      'score' => $best['score'],
      'answer' => $best['answer'],
      'alternates' => $alts
    ];
  }
}

// ---------- Evaluation ----------
function evaluate_user_matcher($queries, $topk, $alpha, $maxlat, $faqs) {
  $matcher = new ACURCB_Matcher_Embedded($faqs);
  $correct = 0; $mrr_sum = 0.0; $lat = [];
  $rows = [];

  foreach ($queries as $qrow) {
    $q = $qrow['query'];
    $gold = intval($qrow['gold_id']);
    $t0 = microtime(true);

    $res = $matcher->match($q, $topk);

    $dt = (microtime(true) - $t0) * 1000.0;
    $lat[] = $dt;

    $pred_id = isset($res['id']) ? intval($res['id']) : -1;
    $pred_score = isset($res['score']) ? floatval($res['score']) : 0.0;

    $alternates = $res['alternates'] ?? [];
    $ranked = [];
    if ($pred_id !== -1) { $ranked[] = ['id'=>$pred_id, 'score'=>$pred_score]; }
    foreach ($alternates as $a) {
      $aid = intval($a['id'] ?? -1);
      if ($aid <= 0) continue;
      $dup = false;
      foreach ($ranked as $r) { if ($r['id'] === $aid) { $dup = true; break; } }
      if (!$dup) { $ranked[] = ['id'=>$aid, 'score'=>floatval($a['score'] ?? 0.0)]; }
    }
    $ranked = array_slice($ranked, 0, $topk);

    $gold_rank = "";
    foreach ($ranked as $i => $r) {
      if ($r['id'] === $gold) { $gold_rank = $i + 1; break; }
    }

    if ($gold > 0 && $pred_id === $gold) { $correct++; }
    if ($gold_rank !== "") { $mrr_sum += 1.0 / $gold_rank; }

    $rows[] = [
      "query" => $q,
      "gold_id" => $gold,
      "pred_id" => $pred_id,
      "pred_score" => round($pred_score, 6),
      "rank" => $gold_rank,
      "latency_ms" => round($dt, 3)
    ];
  }

  $N = count($queries);
  $accuracy = $N ? ($correct / $N) : 0.0;
  $mrr = $N ? ($mrr_sum / $N) : 0.0;
  $avg_latency = $N ? (array_sum($lat) / $N) : 0.0;

  sort($lat);
  $idx = max(0, intval(floor(0.95 * count($lat))) - 1);
  $p95_latency = $lat ? $lat[$idx] : 0.0;

  $latency_factor = 1.0 - min($avg_latency / $maxlat, 1.0);
  $combined = $alpha * $accuracy + (1 - $alpha) * $latency_factor;

  return [
    "metrics" => [
      "samples" => $N,
      "accuracy_Hit@1" => round($accuracy, 4),
      "MRR" => round($mrr, 4),
      "avg_latency_ms" => round($avg_latency, 2),
      "p95_latency_ms" => round($p95_latency, 2),
      "combined_score" => round($combined, 4),
      "alpha_accuracy_weight" => $alpha,
      "max_acceptable_latency_ms" => $maxlat
    ],
    "rows" => $rows
  ];
}

$result = evaluate_user_matcher($QUERIES, $topk, $alpha, $maxlat, $FAQS);

// ---------- Output ----------
$results_path = __DIR__ . "/matcher_user_results.csv";
$metrics_path = __DIR__ . "/matcher_user_metrics.json";

$fh = fopen($results_path, "w");
fputcsv($fh, ["query","gold_id","pred_id","pred_score","rank","latency_ms"]);
foreach ($result["rows"] as $row) {
  fputcsv($fh, [$row["query"], $row["gold_id"], $row["pred_id"], $row["pred_score"], $row["rank"], $row["latency_ms"]]);
}
fclose($fh);

file_put_contents($metrics_path, json_encode($result["metrics"], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

echo "Wrote results: $results_path\n";
echo "Wrote metrics: $metrics_path\n";

// --- Suggested: Print summary table after evaluation ---
$metrics = json_decode(file_get_contents($metrics_path), true);
echo "\n=== Evaluation Summary ===\n";
foreach ($metrics as $k => $v) {
    printf("%-25s : %s\n", $k, $v);
}

echo "\nTop 10 Query Results:\n";
$results = [];
if (($fh = fopen($results_path, "r")) !== false) {
    $headers = fgetcsv($fh);
    $i = 0;
    while (($row = fgetcsv($fh)) !== false && $i < 10) {
        $assoc = array_combine($headers, $row);
        printf("Q: %-40s | Gold: %-4s | Pred: %-4s | Score: %-6s | Rank: %-2s | Latency: %-6s ms\n",
            mb_substr($assoc["query"], 0, 40),
            $assoc["gold_id"], $assoc["pred_id"], $assoc["pred_score"], $assoc["rank"], $assoc["latency_ms"]
        );
        $i++;
    }
    fclose($fh);
}
?>
