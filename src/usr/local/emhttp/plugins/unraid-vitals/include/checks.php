<?php
/**
 * unraid-vitals — checks engine (plan 106 P13-01).
 *
 * Detection today is split between threshold alerts in v_check_alerts()
 * (transient — computed fresh from the latest snapshot every tick, never
 * persisted) and LLM agents that read one snapshot and have to guess.
 * This is the deterministic layer every diagnosis ticket in Phase 14 plugs
 * into: small, pure, rule-based checks that each look at the current
 * snapshot (and optionally recent history) and return zero or more typed
 * findings with the evidence that triggered them.
 *
 * A "check" is a plain function: `array $snap -> array of findings`, one
 * file per check under checks/, each exposing exactly one function named
 * v_check_<id>(). v_checks_registry() collects them by convention (file name
 * == check id == function suffix) rather than a hand-maintained list, so
 * adding a check is "add a file", not "add a file and remember to register
 * it in two places".
 *
 * A finding is:
 *   {check_id, severity, subject, title, detail, evidence, fix_id}
 * - severity: 'info'|'warning'|'alert'|'critical' (same vocabulary as
 *   v_check_alerts()/kb_events, so the UI can render both with one component)
 * - subject: what the finding is about (e.g. a disk name, a container name,
 *   '' for whole-system) — mirrors kb_events.entity, used for de-duplication
 *   the same way v_event_raise() already keys alerts.
 * - evidence: array of {label, value} pairs — the actual numbers/log lines
 *   that triggered the finding, always JSON-serializable, never prose. Kept
 *   separate from `detail` (which is the human sentence) so the AI agents
 *   can consume evidence structurally instead of re-parsing English.
 * - fix_id: optional string naming a remediation the UI/agent can offer;
 *   null when there is nothing actionable to suggest yet.
 */

require_once __DIR__ . '/store.php';

/** Every enabled, non-overridden-off check's id -> its default severity. */
function v_checks_defaults(): array {
  return [
    'rootfs_full' => 'alert',
    'docker_image_full' => 'warning',
    'docker_log_large' => 'warning',
    'fs_watch_full' => 'warning',
    'share_placement' => 'warning',
    'parity_health' => 'warning',
  ];
}

/**
 * Load checks/*.php and return id => callable, keyed off the filename
 * (checks/rootfs_full.php defines v_check_rootfs_full()).
 */
function v_checks_registry(): array {
  $out = [];
  foreach (glob(__DIR__ . '/checks/*.php') ?: [] as $file) {
    $id = basename($file, '.php');
    require_once $file;
    $fn = 'v_check_' . $id;
    if (function_exists($fn)) $out[$id] = $fn;
  }
  return $out;
}

/** Per-check enable flag and severity override, read from vitals.cfg. */
function v_checks_config(): array {
  $cfg = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
  $out = [];
  foreach (v_checks_defaults() as $id => $defaultSeverity) {
    $enabledKey = 'CHECK_' . strtoupper($id) . '_ENABLED';
    $severityKey = 'CHECK_' . strtoupper($id) . '_SEVERITY';
    $out[$id] = [
      'enabled' => !array_key_exists($enabledKey, $cfg) || $cfg[$enabledKey] !== '0',
      'severity' => in_array($cfg[$severityKey] ?? '', ['info', 'warning', 'alert', 'critical'], true)
        ? $cfg[$severityKey]
        : $defaultSeverity,
    ];
  }
  return $out;
}

/**
 * Run every enabled check against $snap, persist results to check_results,
 * and return the findings produced this run. Safe to call with an empty
 * snapshot (e.g. before the first collector tick) — checks are expected to
 * return [] rather than error on missing keys.
 */
function v_checks_run(array $snap): array {
  $registry = v_checks_registry();
  $config = v_checks_config();
  $findings = [];

  foreach ($registry as $id => $fn) {
    $cfg = $config[$id] ?? ['enabled' => true, 'severity' => 'warning'];
    if (!$cfg['enabled']) continue;
    try {
      $results = $fn($snap);
    } catch (Throwable $e) {
      // A broken check must not take the whole run down — record it as a
      // finding about itself rather than silently disappearing.
      $results = [[
        'check_id' => $id, 'severity' => 'warning', 'subject' => '',
        'title' => 'Check "' . $id . '" failed to run',
        'detail' => $e->getMessage(),
        'evidence' => [['label' => 'exception', 'value' => get_class($e)]],
        'fix_id' => null,
      ]];
    }
    foreach ($results as $f) {
      $f['check_id'] = $id;
      // The check's own severity always wins when it explicitly names one
      // (e.g. escalating from warning to critical based on how far over a
      // threshold something is) — the config override only fills the gap
      // when the check didn't set one.
      $f['severity'] = $f['severity'] ?? $cfg['severity'];
      $f += ['subject' => '', 'evidence' => [], 'fix_id' => null];
      $findings[] = $f;
    }
  }

  v_checks_persist($findings);
  return $findings;
}

/**
 * Replace this run's rows for every check that ran (findings and clean-now
 * checks alike), so a resolved condition disappears from ?action=checks
 * instead of lingering as a stale row forever. Mirrors the same
 * delete-then-reinsert contract v_events_db()'s doc comment describes for
 * the AI agents' kb_events table, kept as its own table since checks run on
 * their own schedule and evidence has a different shape than AI findings.
 */
function v_checks_persist(array $findings): void {
  $db = v_events_db();
  if (!$db) return;
  $db->exec("CREATE TABLE IF NOT EXISTS check_results (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    check_id TEXT NOT NULL, severity TEXT NOT NULL,
    subject TEXT NOT NULL DEFAULT '', title TEXT NOT NULL, detail TEXT,
    evidence TEXT, fix_id TEXT, run_at INTEGER NOT NULL)");
  $db->exec("CREATE INDEX IF NOT EXISTS idx_check_results_run ON check_results(run_at DESC)");

  $now = time();
  $db->exec('BEGIN');
  try {
    $db->exec('DELETE FROM check_results');
    $stmt = $db->prepare("INSERT INTO check_results
      (check_id, severity, subject, title, detail, evidence, fix_id, run_at)
      VALUES (:check_id, :severity, :subject, :title, :detail, :evidence, :fix_id, :run_at)");
    foreach ($findings as $f) {
      $stmt->bindValue(':check_id', (string)$f['check_id'], SQLITE3_TEXT);
      $stmt->bindValue(':severity', (string)$f['severity'], SQLITE3_TEXT);
      $stmt->bindValue(':subject', (string)$f['subject'], SQLITE3_TEXT);
      $stmt->bindValue(':title', (string)$f['title'], SQLITE3_TEXT);
      $stmt->bindValue(':detail', (string)($f['detail'] ?? ''), SQLITE3_TEXT);
      $stmt->bindValue(':evidence', json_encode($f['evidence'] ?? [], JSON_UNESCAPED_SLASHES), SQLITE3_TEXT);
      $stmt->bindValue(':fix_id', $f['fix_id'] !== null ? (string)$f['fix_id'] : null, SQLITE3_TEXT);
      $stmt->bindValue(':run_at', $now, SQLITE3_INTEGER);
      $stmt->execute();
      $stmt->reset();
    }
    $db->exec('COMMIT');
  } catch (Throwable $e) {
    $db->exec('ROLLBACK');
  }
  $db->close();
}

/** Current check results (the most recent run), for ajax.php?action=checks. */
function v_checks_latest(): array {
  $dbFile = v_db_path();
  if (!is_file($dbFile) || !class_exists('SQLite3')) return ['run_at' => null, 'findings' => []];
  try {
    $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
  } catch (Throwable $e) {
    return ['run_at' => null, 'findings' => []];
  }
  $out = [];
  $runAt = null;
  $res = @$db->query("SELECT check_id, severity, subject, title, detail, evidence, fix_id, run_at
                        FROM check_results ORDER BY id ASC");
  while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
    $runAt = (int)$row['run_at'];
    $row['evidence'] = json_decode((string)$row['evidence'], true) ?: [];
    $row['fix_id'] = $row['fix_id'] !== null && $row['fix_id'] !== '' ? $row['fix_id'] : null;
    $out[] = $row;
  }
  $db->close();
  return ['run_at' => $runAt, 'findings' => $out];
}
