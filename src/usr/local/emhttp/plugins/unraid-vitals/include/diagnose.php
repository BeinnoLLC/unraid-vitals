<?php
/* unraid-vitals — include/diagnose.php (P17-01 / #84)
 *
 * One-click full health check: run every check in the engine NOW, produce
 * (a) a 0-100 score, (b) one severity-sorted list with evidence + fix links,
 * (c) a diff vs the previous stored run (same run_id chain in the DB) so two
 * runs can be compared ("what got better/worse since last time").
 *
 * Results persist in check_results (the standard table v_checks_run writes);
 * the score + run metadata go in a health_runs row per run.
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/checks.php';

const V_SEVERITY_WEIGHTS = ['info' => 0, 'warning' => 3, 'alert' => 8, 'critical' => 25];

/** Score: 100 − Σ(severity weights), floored at 0. */
function v_health_score(array $findings): int {
  $penalty = 0;
  foreach ($findings as $f) {
    $penalty += V_SEVERITY_WEIGHTS[(string)($f['severity'] ?? 'info')] ?? 0;
  }
  return max(0, 100 - $penalty);
}

/** Run the full engine now, store a health_runs row, return the report. */
function v_health_run_now(array $snap = null): array {
  $snap = $snap ?? v_collect();
  $findings = v_checks_run($snap);   // persists to check_results + returns this run's findings

  // severity-sorted, evidence attached
  $order = array_flip(['critical', 'alert', 'warning', 'info']);
  usort($findings, function ($a, $b) use ($order) {
    return ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9);
  });

  $score = v_health_score($findings);
  $prev = v_health_last_run();
  $diff = $prev ? v_health_diff($prev['finding_ids'], $findings) : null;

  $db = v_events_db();
  $runId = 0;
  if ($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS health_runs (
      id INTEGER PRIMARY KEY AUTOINCREMENT, at INTEGER NOT NULL, score INTEGER NOT NULL,
      counts TEXT NOT NULL, finding_ids TEXT NOT NULL, prev_id INTEGER)");
    $ids = array_map(function ($f) { return $f['check_id'] . ':' . ($f['subject'] ?? '') . ':' . ($f['title'] ?? ''); }, $findings);
    $st = $db->prepare('INSERT INTO health_runs (at, score, counts, finding_ids, prev_id) VALUES (:at, :score, :counts, :ids, :prev)');
    $st->bindValue(':at', time(), SQLITE3_INTEGER);
    $st->bindValue(':score', $score, SQLITE3_INTEGER);
    $st->bindValue(':counts', json_encode(array_count_values(array_column($findings, 'severity'))), SQLITE3_TEXT);
    $st->bindValue(':ids', json_encode($ids), SQLITE3_TEXT);
    $st->bindValue(':prev', (int)($prev['id'] ?? 0), SQLITE3_INTEGER);
    $st->execute();
    $runId = $db->lastInsertRowID();
  }

  return ['ok' => true, 'run_id' => $runId, 'score' => $score,
          'counts' => array_count_values(array_column($findings, 'severity')),
          'findings' => $findings, 'diff' => $diff];
}

/** Findings keyed the same way as the diff keys, from the last stored run. */
function v_health_last_run(): ?array {
  $db = v_events_db();
  if (!$db) return null;
  $row = $db->querySingle('SELECT id, at, score, counts, finding_ids FROM health_runs ORDER BY at DESC LIMIT 1', true);
  if (!$row) return null;
  return ['id' => (int)$row['id'], 'at' => (int)$row['at'], 'score' => (int)$row['score'],
          'counts' => json_decode($row['counts'], true) ?: [],
          'finding_ids' => json_decode($row['finding_ids'], true) ?: []];
}

/** Diff two runs by composite key: fixed / regressed / new. */
function v_health_diff(array $prevIds, array $nowFindings): array {
  $key = function ($f) { return $f['check_id'] . ':' . ($f['subject'] ?? '') . ':' . ($f['title'] ?? ''); };
  $prev = array_map(function ($k) { return is_array($k) ? $k : $k; }, $prevIds);
  $nowKeys = array_map($key, $nowFindings);
  return [
    'resolved' => array_values(array_diff($prev, $nowKeys)),
    'still' => array_values(array_intersect($prev, $nowKeys)),
    'new' => array_values(array_diff($nowKeys, $prev)),
  ];
}

/** Full history for the compare view. */
function v_health_runs(int $limit = 20): array {
  $db = v_events_db();
  if (!$db) return [];
  $out = [];
  $res = $db->query('SELECT id, at, score, counts FROM health_runs ORDER BY at DESC LIMIT ' . (int)$limit);
  while (($row = $res->fetchArray(SQLITE3_ASSOC))) {
    $out[] = ['id' => (int)$row['id'], 'at' => (int)$row['at'], 'score' => (int)$row['score'],
              'counts' => json_decode($row['counts'], true) ?: []];
  }
  return $out;
}