<?php

require_once __DIR__ . '/store.php';

function v_checks_defaults(): array {
  return [
    'rootfs_full' => 'alert',
    'docker_image_full' => 'warning',
    'docker_log_large' => 'warning',
    'fs_watch_full' => 'warning',
    'share_placement' => 'warning',
    'parity_health' => 'warning',
    'smart_deep' => 'warning',
    'spin_never_down' => 'warning',
    'signature_scan' => 'warning',
    'pool_health' => 'warning',
    'net_health' => 'warning',
    'flash_health' => 'warning',
    'system_maintenance' => 'warning',
    'share_permissions' => 'warning',
    'log_size' => 'warning',
    'docker_hygiene' => 'warning',
    'vm_storage' => 'warning',
    'capacity_forecast' => 'warning',
    'anomaly_baseline' => 'warning',
  ];
}

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
      
      
      
      
      $f['severity'] = $f['severity'] ?? $cfg['severity'];
      $f += ['subject' => '', 'evidence' => [], 'fix_id' => null];
      $findings[] = $f;
    }
  }

  v_checks_persist($findings);
  return $findings;
}

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
