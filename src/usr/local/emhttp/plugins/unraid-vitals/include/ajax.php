<?php
/**
 * unraid-vitals — AJAX endpoint for the UI.
 *
 *   ?action=data          latest snapshot + ring (default)
 *   ?action=refresh        force a new sample, then return it
 *   ?action=daily           daily aggregates from the flash rollups
 *   ?action=settings        GET current vitals.cfg + collector status
 *   ?action=save_settings   POST new vitals.cfg values (CSRF-checked), reapply cron/start-page
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/research-plan.php';
require_once __DIR__ . '/checks.php';
require_once __DIR__ . '/cleanup.php';
require_once __DIR__ . '/cleanup_orphan.php';
require_once __DIR__ . '/cleanup_ctrlogs.php';
require_once __DIR__ . '/cleanup_recycle.php';
require_once __DIR__ . '/cleanup_mover.php';
require_once __DIR__ . '/cleanup_junk.php';
require_once __DIR__ . '/playbooks.php';
require_once __DIR__ . '/timeline.php';
require_once __DIR__ . '/smart_selftest.php';
require_once __DIR__ . '/job_control.php';
require_once __DIR__ . '/agent_schedules.php';
require_once __DIR__ . '/dbbackup.php';
require_once __DIR__ . '/kb_curate.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = $_GET['action'] ?? 'data';

const V_CFG_FILE = '/boot/config/plugins/unraid-vitals/vitals.cfg';
const V_INSTALL_SH = '/usr/local/emhttp/plugins/unraid-vitals/scripts/install.sh';

/**
 * CSRF gate for state-changing actions.
 *
 * NB: Unraid's global auto_prepend_file already validates `csrf_token` on
 * every POST and then unsets it before plugin code runs — so an absent token
 * here means "auto_prepend already cleared it after a successful check",
 * not "missing token". Re-reading it unconditionally caused a live bug: the
 * Research and Share-comment buttons failed with "bad csrf token" in the
 * browser while CLI includes (which skip auto_prepend) passed.
 * When a token IS still present we still compare it (CLI/test paths).
 */
function v_csrf_ok(): bool {
  $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
  if ($sent === null) {
    return PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
  }
  $real = @parse_ini_file('/var/local/emhttp/var.ini')['csrf_token'] ?? null;
  return $real && hash_equals($real, (string)$sent);
}

try {
  if ($action === 'refresh') {
    $snap = v_tick(true);
    $snap['csrf_token'] = @parse_ini_file('/var/local/emhttp/var.ini')['csrf_token'] ?? '';
    // Frontend poll cadence (P8/UI): user-configurable in Settings, NOT the
    // same thing as INTERVAL (the cron collector's cadence in minutes) —
    // this is how often the open dashboard tab re-fetches, in seconds.
    // Clamped 3-300s: below 3s is needless load for a metrics page, above
    // 300s stops feeling "live". Default 10s per user request.
    $cfg = is_file(V_CFG_FILE) ? (@parse_ini_file(V_CFG_FILE) ?: []) : [];
    $snap['ui_refresh_seconds'] = max(3, min(300, (int)($cfg['UI_REFRESH_SECONDS'] ?? 10)));
    echo json_encode(['ok' => true, 'data' => $snap, 'ring' => v_ring()], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'daily') {
    $days = max(1, min(365, (int)($_GET['days'] ?? 30)));
    echo json_encode(['ok' => true, 'daily' => v_daily($days)], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'capacity_forecast') {
    $snap = v_collect();
    $totals = [];
    foreach (array_merge($snap['array']['data'] ?? [], $snap['array']['cache'] ?? []) as $d) {
      if (($d['fsSize'] ?? 0) > 0 && !empty($d['name'])) $totals[$d['name']] = [$d['fsSize'], $d['fsFree']];
    }
    if (isset($snap['docker_image']['total'])) {
      $totals['docker.img'] = [$snap['docker_image']['total'], $snap['docker_image']['free']];
    }
    echo json_encode(['ok' => true, 'forecast' => v_capacity_forecast(v_daily(30), $totals)], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'anomaly') {
    echo json_encode(['ok' => true] + v_anomaly_check(), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'chart_events') {
    $hours = min(168, max(1, (int)($_GET['hours'] ?? 24)));
    echo json_encode(['ok' => true, 'events' => v_chart_events($hours)], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'storage_analyzer') {
    echo json_encode(['ok' => true] + v_storage_analyzer(), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'dup_report') {
    echo json_encode(['ok' => true] + v_dup_report(), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'container_weekly') {
    echo json_encode(['ok' => true] + v_container_weekly_report(), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'energy_report') {
    echo json_encode(['ok' => true] + v_energy_report(), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'disk_fleet') {
    echo json_encode(['ok' => true] + v_disk_fleet_report(), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'export') {
    $what = $_GET['what'] ?? 'ring';       // 'ring' or 'rollups'
    $format = $_GET['format'] ?? 'csv';    // 'csv' or 'json'
    $rows = $what === 'rollups' ? v_export_rollup_rows() : v_export_ring_rows();
    $base = 'unraid-vitals-' . $what . '-' . gmdate('Ymd-His');
    if ($format === 'json') {
      header('Content-Type: application/json');
      header('Content-Disposition: attachment; filename="' . $base . '.json"');
      echo json_encode($rows, JSON_PRETTY_PRINT);
    } else {
      header('Content-Type: text/csv');
      header('Content-Disposition: attachment; filename="' . $base . '.csv"');
      echo v_rows_to_csv($rows);
    }
    exit;
  }

  if ($action === 'weekly_report_preview') {
    echo json_encode(['ok' => true] + v_weekly_health_summary(), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'settings') {
    $cfg = is_file(V_CFG_FILE) ? (@parse_ini_file(V_CFG_FILE) ?: []) : [];
    $ring = is_file('/var/tmp/unraid-vitals/history.json')
      ? json_decode((string)file_get_contents('/var/tmp/unraid-vitals/history.json'), true) : [];
    $dir = '/boot/config/plugins/unraid-vitals/history';
    $files = is_dir($dir) ? glob($dir . '/*.jsonl') : [];
    $flashBytes = 0; foreach ($files as $f) $flashBytes += (int)@filesize($f);
    $lastRun = is_file('/var/tmp/unraid-vitals/latest.json')
      ? json_decode((string)file_get_contents('/var/tmp/unraid-vitals/latest.json'), true) : null;
    $log = '/var/tmp/unraid-vitals/collector.log';
    $dbFile = v_db_path();
    echo json_encode(['ok' => true,
      'cfg' => $cfg,
      'checks' => v_checks_config(),
      'db_path' => $dbFile,
      'db_bytes' => $dbFile !== '' && is_file($dbFile) ? (int)@filesize($dbFile) : null,
      'csrf_token' => @parse_ini_file('/var/local/emhttp/var.ini')['csrf_token'] ?? '',
      'ring_samples' => is_array($ring) ? count($ring) : 0,
      'flash_files' => count($files), 'flash_bytes' => $flashBytes,
      'last_run' => $lastRun['time'] ?? null,
      'log_tail' => is_file($log) ? trim((string)@file_get_contents($log)) : ''
    ], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'save_settings') {
    if (!v_csrf_ok()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit; }
    $allowed = ['INTERVAL', 'KEEP_DAYS', 'SET_STARTPAGE', 'ALERT_TEMP', 'ALERT_FILL', 'ALERT_LOAD', 'ALERT_RESTARTS',
                'LLM_STUDIO_PRIMARY', 'LLM_STUDIO_BACKUP', 'UI_REFRESH_SECONDS',
                'VITALS_DIAG_INTERVAL_MINUTES', 'VITALS_DIAG_WINDOW_HOURS', 'VITALS_DIAG_MODELS',
                'VITALS_UPDATE_INTERVAL_MINUTES', 'PRICE_PER_KWH',
                'QUIET_START', 'QUIET_END', 'KB_KEEP_DAYS', 'RESEARCH_KEEP_DAYS', 'BACKUP_DIR', 'VITALS_RUN_BUDGET_MINUTES'];
    foreach (array_keys(v_sched_registry()) as $jobId) {
      $allowed[] = 'SCHED_' . strtoupper($jobId);
      $allowed[] = 'SCHED_' . strtoupper($jobId) . '_ENABLED';
    }
    foreach (array_keys(v_agent_jobs()) as $agentName) {
      $allowed[] = 'SCHED_AGENTS_' . strtoupper($agentName);
      $allowed[] = 'SCHED_AGENTS_' . strtoupper($agentName) . '_ENABLED';
    }
    foreach (array_keys(v_checks_defaults()) as $checkId) {
      $allowed[] = 'CHECK_' . strtoupper($checkId) . '_ENABLED';
      $allowed[] = 'CHECK_' . strtoupper($checkId) . '_SEVERITY';
    }
    $cfg = is_file(V_CFG_FILE) ? (@parse_ini_file(V_CFG_FILE) ?: []) : [];
    foreach ($allowed as $k) if (isset($_POST[$k])) $cfg[$k] = $_POST[$k];
    $lines = [];
    foreach ($cfg as $k => $v) $lines[] = $k . '="' . str_replace('"', '', (string)$v) . '"';
    @mkdir(dirname(V_CFG_FILE), 0755, true);
    file_put_contents(V_CFG_FILE, implode("\n", $lines) . "\n");
    v_flash_writes_track(); // settings save writes to flash too (P14-12)
    // Re-apply cron + start-page immediately, same as the old form's #command.
    if (is_executable(V_INSTALL_SH)) exec(escapeshellarg(V_INSTALL_SH) . ' --reapply 2>&1');
    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'findings') {
    echo json_encode(['ok' => true, 'findings' => v_ai_findings(), 'runs' => v_ai_runs()], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'checks') {
    echo json_encode(['ok' => true] + v_checks_latest(), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'cleanup_kinds') {
    echo json_encode(['ok' => true, 'fs' => ['logs', 'tmp', 'orphan_appdata', 'container_logs', 'recycle', 'junk'], 'docker' => array_keys(v_cleanup_docker_kinds())], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'cleanup_preview') {
    // Read-only scan building a preview. Kind whitelist enforced inside.
    $kind = (string)($_GET['kind'] ?? '');
    v_cleanup_gc();
    $res = v_cleanup_preview($kind);
    echo json_encode($res, JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'cleanup_apply') {
    // Destructive: POST + CSRF + preview-id-only (never client path lists).
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !v_csrf_ok()) {
      http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit;
    }
    $previewId = (string)($_POST['preview_id'] ?? '');
    if (!preg_match('/^pv_[0-9a-f]{16}$/', $previewId)) {
      v_cleanup_audit($previewId, '?', 0, 0, 'rejected', 'malformed preview_id');
      http_response_code(400); echo json_encode(['ok' => false, 'error' => 'malformed preview_id']); exit;
    }
    // optional filters (recycle kind): keep entries newer than N days
    if (isset($_POST['older_days'])) {
      $GLOBALS['v_cleanup_recycle_older_than'] = max(0, (int)$_POST['older_days']) * 86400;
    }
    // Second confirmation for kinds flagged confirm2 (P16-02 unused volumes).
    $load = v_cleanup_load($previewId);
    if ($load['ok']) {
      $spec = v_cleanup_docker_kinds()[$load['kind']] ?? null;
      if (($spec['confirm2'] ?? false) && ($_POST['confirm2'] ?? '') !== 'yes') {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'second confirmation required for this kind']); exit;
      }
    }
    echo json_encode(v_cleanup_apply($previewId), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'cleanup_audit') {
    echo json_encode(['ok' => true, 'audit' => v_cleanup_audit_list(100)], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'mover_status') {
    echo json_encode(v_mover_status(), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'health_check') {
    // GET (fetch, no state change beyond the results themselves — the checks
    // run is the documented scan), but bind to POST too if sent that way.
    require_once __DIR__ . '/diagnose.php';
    echo json_encode(v_health_run_now(), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'health_runs') {
    require_once __DIR__ . '/diagnose.php';
    echo json_encode(['ok' => true, 'runs' => v_health_runs(20)], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'playbook') {
    $checkId = (string)($_GET['check'] ?? '');
    if (!preg_match('/^[a-z0-9_]{1,64}$/', $checkId)) {
      http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad check id']); exit;
    }
    $pb = v_playbook_for($checkId);
    echo json_encode($pb ? ['ok' => true, 'check' => $checkId, 'title' => $pb['title'], 'body' => $pb['body']]
                          : ['ok' => false, 'error' => 'no playbook for ' . $checkId], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'timeline') {
    v_timeline_tick(); // refresh today's snapshot, then diff against history
    echo json_encode(['ok' => true, 'days' => v_timeline_diffs(14)], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'kb_seed') {
    // idempotent weekly seeding of trusted Unraid docs (P20-11); POST+CSRF for --force.
    require_once __DIR__ . '/kb_seed.php';
    $force = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && v_csrf_ok() && ($_POST['force'] ?? '') === 'yes';
    $n = v_kb_seed($force);
    echo json_encode(['ok' => true, 'seeded' => $n], JSON_UNESCAPED_SLASHES);
    exit;
  }

  // --- P12 KB workspace curation (#38) — all mutations CSRF-gated ------------
  if ($action === 'kb_curate_list') {
    echo json_encode(['ok' => true,
      'lessons' => v_kb_lessons_list($_GET['kind'] ?? null),
      'solutions' => v_kb_solutions_list(),
      'events' => v_kb_events_list($_GET['kind'] ?? null, $_GET['status'] ?? null)], JSON_UNESCAPED_SLASHES);
    exit;
  }

  $KB_MUT = [
    'kb_lesson_edit' => function () { return v_kb_lesson_edit((int)$_POST['id'], (string)$_POST['text']); },
    'kb_lesson_merge' => function () { return v_kb_lesson_merge((int)$_POST['id'], (int)$_POST['into']); },
    'kb_lesson_delete' => function () { return v_kb_lesson_delete((int)$_POST['id']); },
    'kb_lesson_pin' => function () { return v_kb_lesson_pin((int)$_POST['id'], (($_POST['pin'] ?? 'yes') === 'yes')); },
    'kb_lesson_manual' => function () { $id = v_kb_lesson_manual((string)$_POST['entity'], (string)$_POST['text']); return $id !== null; },
    'kb_solution_feedback' => function () { return v_kb_solution_feedback((int)$_POST['id'], (string)$_POST['outcome']); },
  ];
  if (isset($KB_MUT[$action])) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !v_csrf_ok()) {
      http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit;
    }
    echo json_encode(['ok' => (bool)(($KB_MUT[$action])())], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'kb_export') {
    $bundle = v_kb_export();
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="unraid-vitals-kb-' . date('Ymd-His') . '.json"');
    echo $bundle ?: json_encode(['ok' => false, 'error' => 'no kb']);
    exit;
  }

  if ($action === 'kb_import') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !v_csrf_ok()) {
      http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit;
    }
    echo json_encode(v_kb_import((string)($_POST['bundle'] ?? '')), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'smart_tests') {
    echo json_encode(['ok' => true, 'disks' => v_smart_test_overview()], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'smart_test_start') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !v_csrf_ok()) {
      http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit;
    }
    $disk = (string)($_POST['disk'] ?? '');
    $type = (string)($_POST['type'] ?? 'short');
    echo json_encode(v_smart_test_start($disk, $type), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'job_statuses') {
    echo json_encode(['ok' => true, 'jobs' => v_job_statuses(),
                      'quiet' => v_job_quiet_active(), 'busy' => v_job_busy_system()], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'job_run') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !v_csrf_ok()) {
      http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit;
    }
    $id = (string)($_POST['job'] ?? '');
    if (!preg_match('/^[a-z0-9_]{1,64}$/', $id)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad job id']); exit; }
    echo json_encode(v_job_run_now($id, ($_POST['force'] ?? '') === 'yes'), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'backups') {
    echo json_encode(['ok' => true, 'backups' => v_backup_list(3),
      'same_pool' => v_backup_same_pool(v_db_path(), v_backup_dir()),
      'dest' => v_backup_dir(), 'integrity' => v_backup_integrity(),
      'schema' => v_backup_schema_check()], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'backup_run') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !v_csrf_ok()) {
      http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit;
    }
    echo json_encode(v_backup_run(), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'backup_restore') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !v_csrf_ok()) {
      http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit;
    }
    echo json_encode(v_backup_restore((string)($_POST['file'] ?? '')), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'backup_download') {
    $f = (string)($_GET['file'] ?? '');
    if (!preg_match('/^vitals-[a-z0-9-]+\.db$/', $f)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad name']); exit; }
    $p = v_backup_dir() . '/' . $f;
    if (!is_file($p)) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not found']); exit; }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $f . '"');
    header('Content-Length: ' . filesize($p));
    readfile($p);
    exit;
  }

  if ($action === 'backup_bundle') {
    // full state bundle (#105): heavier op, POST+CSRF.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !v_csrf_ok()) {
      http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit;
    }
    $z = v_backup_bundle();
    echo json_encode($z ? ['ok' => true, 'bundle' => $z, 'bytes' => filesize($z)]
                        : ['ok' => false, 'error' => 'bundle failed'], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'diag_summary') {
    require_once __DIR__ . '/diagnostics.php';
    echo json_encode(['ok' => true, 'summary' => v_diag_forum_summary(true)], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'diag_bundle') {
    // Triggers Unraid's diagnostics; returns the bundle path for the UI to
    // turn into a download link. Heavier op — bound behind POST + CSRF.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !v_csrf_ok()) {
      http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit;
    }
    require_once __DIR__ . '/diagnostics.php';
    $zip = v_diag_unraid_bundle();
    echo json_encode($zip ? ['ok' => true, 'bundle' => $zip]
                          : ['ok' => false, 'error' => 'diagnostics bundle not produced (check /boot/logs free space)'], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'mover_start') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !v_csrf_ok()) {
      http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit;
    }
    echo json_encode(v_mover_start(v_latest(), (string)($_POST['confirm_parity'] ?? '')), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'mover_stop') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !v_csrf_ok()) {
      http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit;
    }
    echo json_encode(v_mover_stop(), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'share_comment') {
    $share = trim((string)($_GET['share'] ?? ''));
    if ($share === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'missing share']); exit; }
    echo json_encode(['ok' => true, 'comment' => v_share_comment($share)], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'apply_share_comment') {
    if (!v_csrf_ok()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit; }
    $share = trim((string)($_POST['share'] ?? ''));
    $comment = (string)($_POST['comment'] ?? '');
    if ($share === '' || !preg_match('/^[\w.\- ]+$/', $share)) {
      http_response_code(400); echo json_encode(['ok' => false, 'error' => 'invalid share name']); exit;
    }
    $applied = v_apply_share_comment($share, $comment);
    echo json_encode(['ok' => $applied]);
    exit;
  }

  if ($action === 'gen_share_comment') {
    if (!v_csrf_ok()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit; }
    $share = trim((string)($_POST['share'] ?? ''));
    if ($share === '' || !preg_match('/^[\w.\- ]+$/', $share)) {
      http_response_code(400); echo json_encode(['ok' => false, 'error' => 'invalid share name']); exit;
    }
    $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
    $script = escapeshellarg(__DIR__ . '/../agent/share-comment.mjs');
    if (!$node || !is_file(__DIR__ . '/../agent/share-comment.mjs')) {
      http_response_code(500); echo json_encode(['ok' => false, 'error' => 'agent runtime not installed']); exit;
    }
    // Fire-and-forget: the agent call is a single CPU-bound LLM prompt that
    // can take a while on this hardware, so don't block the HTTP request —
    // the UI polls ?action=share_comment for the result once it lands.
    exec(sprintf('nohup %s %s %s > /tmp/unraid-vitals-sharecomment.log 2>&1 &',
      escapeshellarg($node), $script, escapeshellarg($share)));
    echo json_encode(['ok' => true, 'status' => 'started']);
    exit;
  }

  if ($action === 'browse') {
    echo json_encode(['ok' => true] + v_browse_share((string)($_GET['share'] ?? ''), (string)($_GET['path'] ?? '')), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'kb_tags') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'tags' => v_kb_tag_counts()]);
    exit;
  }

  if ($action === 'kb_search') {
    $q = trim((string)($_GET['q'] ?? ''));
    $sev = trim((string)($_GET['severity'] ?? ''));
    if (!in_array($sev, ['', 'low', 'medium', 'high', 'critical'], true)) $sev = '';
    $tag = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string)($_GET['tag'] ?? ''))));
    echo json_encode(['ok' => true, 'results' => $q === '' ? [] : v_kb_search($q, 20, $sev, $tag),
      'topics' => v_kb_topics(), 'severity_counts' => v_kb_severity_counts(), 'tag_counts' => v_kb_tag_counts()], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'kb_recent') {
    $sev = trim((string)($_GET['severity'] ?? ''));
    if (!in_array($sev, ['', 'low', 'medium', 'high', 'critical'], true)) $sev = '';
    $tag = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string)($_GET['tag'] ?? ''))));
    echo json_encode(['ok' => true, 'docs' => v_kb_recent(50, $sev, $tag),
      'topics' => v_kb_topics(), 'severity_counts' => v_kb_severity_counts(), 'tag_counts' => v_kb_tag_counts()], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'kb_get') {
    $id = (int)($_GET['id'] ?? 0);
    $doc = $id ? v_kb_get($id) : null;
    echo json_encode(['ok' => (bool)$doc, 'doc' => $doc], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'kb_asset') {
    // Images referenced by rich KB documents (charts generated by
    // agent/lib/charts.mjs, screenshots attached to a report). Served from
    // a fixed directory only, filename resolved and re-checked to stay
    // inside it — the DB-stored path is model/script generated, never
    // trust it as a literal filesystem path.
    $dir = realpath(v_state_dir() . '/kb-assets');
    $f = (string)($_GET['f'] ?? '');
    if ($dir === false || $f === '' || strpos($f, "\0") !== false) { http_response_code(404); exit; }
    $full = realpath($dir . '/' . $f);
    if ($full === false || strncmp($full, $dir . DIRECTORY_SEPARATOR, strlen($dir) + 1) !== 0) {
      http_response_code(404); exit;
    }
    $mime = match (strtolower(pathinfo($full, PATHINFO_EXTENSION))) {
      'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml',
      'webp' => 'image/webp', default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=3600');
    readfile($full);
    exit;
  }

  if ($action === 'research_plan') {
    $prompt = trim((string)($_GET['prompt'] ?? ''));
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'plan' => $prompt === '' ? null : v_plan_research($prompt)]);
    exit;
  }

  if ($action === 'research_ask') {
    if (!v_csrf_ok()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit; }
    $prompt = trim((string)($_POST['prompt'] ?? ''));
    if ($prompt === '' || mb_strlen($prompt) > 2000) {
      http_response_code(400); echo json_encode(['ok' => false, 'error' => 'prompt required (max 2000 chars)']); exit;
    }
    // Ask-once: the UI sends ONLY free text; the planner detects study
    // intent, window and cadence from the prompt itself ("monitor CPU
    // spikes for the next 6 hours" → study 6h/5min ticks). Explicit POST
    // values still win when present so auto-research (which already knows
    // what it wants) can bypass detection.
    $plan = v_plan_research($prompt);
    $mode = ($_POST['mode'] ?? null) !== null
      ? (($_POST['mode'] ?? 'once') === 'study' ? 'study' : 'once')
      : ($plan['study'] ? 'study' : 'once');
    $hours = max(1, min(72, (int)($_POST['hours'] ?? $plan['hours'])));
    $tickMinutes = max(5, min(180, (int)($_POST['tick_minutes'] ?? $plan['tick'])));
    $id = $mode === 'study'
      ? v_research_create($prompt, 'study', $hours * 60, $tickMinutes)
      : v_research_create($prompt);
    if ($id === null) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'db unavailable']); exit; }
    $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
    // Study jobs are advanced by the study.mjs cron tick (installed by
    // install.sh), not fired immediately here — the whole point is that it
    // runs unattended for hours. Fire one immediate tick anyway so the UI
    // shows the first observation right away instead of waiting a full
    // cron interval for any sign of life.
    $script = __DIR__ . '/../agent/' . ($mode === 'study' ? 'study.mjs' : 'research.mjs');
    if ($node && is_file($script)) {
      $args = $mode === 'study' ? sprintf('--once %d', (int)$id) : (string)(int)$id;
      exec(sprintf('nohup %s %s %s > /tmp/unraid-vitals-research.log 2>&1 &',
        escapeshellarg($node), escapeshellarg($script), $args));
    }
    echo json_encode(['ok' => true, 'job_id' => $id, 'mode' => $mode,
                      'eta_seconds' => $plan['eta_seconds'], 'eta_label' => $plan['eta_label']]);
    exit;
  }

  if ($action === 'research_status') {
    $id = (int)($_GET['id'] ?? 0);
    $job = $id ? v_research_get($id) : null;
    echo json_encode(['ok' => true, 'job' => $job]);
    exit;
  }

  if ($action === 'research_retry') {
    if (!v_csrf_ok()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit; }
    $id = (int)($_POST['id'] ?? 0);
    if (!$id || !v_research_retry($id)) {
      http_response_code(400); echo json_encode(['ok' => false, 'error' => 'not retryable']); exit;
    }
    $job = v_research_get($id);
    $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
    $script = __DIR__ . '/../agent/' . (($job['mode'] ?? 'once') === 'study' ? 'study.mjs' : 'research.mjs');
    if ($node && is_file($script)) {
      $args = ($job['mode'] ?? 'once') === 'study' ? sprintf('--once %d', $id) : (string)$id;
      exec(sprintf('nohup %s %s %s > /tmp/unraid-vitals-research.log 2>&1 &',
        escapeshellarg($node), escapeshellarg($script), $args));
    }
    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'research_list') {
    echo json_encode(['ok' => true, 'jobs' => v_research_list()]);
    exit;
  }

  if ($action === 'list_models') {
    echo json_encode(['ok' => true] + v_list_models(), JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'logs') {
    $source = (string)($_GET['source'] ?? 'syslog');
    $lines = (int)($_GET['lines'] ?? 200);
    $data = v_logs($source, $lines);
    $sources = array_filter(v_log_sources(), fn($k) => !str_ends_with($k, '2'), ARRAY_FILTER_USE_KEY);
    echo json_encode(['ok' => true] + $data + ['sources' => array_map(
      fn($k, $s) => ['id' => $k, 'label' => $s['label']], array_keys($sources), $sources)],
      JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'events_list') {
    $status = $_GET['status'] ?? null;
    $status = in_array($status, ['open', 'resolved', 'superseded'], true) ? $status : null;
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
    echo json_encode(['ok' => true, 'events' => v_events_list($status, $limit)], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'events_get') {
    $id = (int)($_GET['id'] ?? 0);
    echo json_encode(['ok' => true, 'event' => $id ? v_event_get($id) : null], JSON_UNESCAPED_SLASHES);
    exit;
  }

  $snap = v_latest();
  if (!$snap) $snap = v_tick(true);
  $snap['csrf_token'] = @parse_ini_file('/var/local/emhttp/var.ini')['csrf_token'] ?? '';
  $snap['_age'] = max(0, time() - (int)($snap['time'] ?? 0));
  $cfg = is_file(V_CFG_FILE) ? (@parse_ini_file(V_CFG_FILE) ?: []) : [];
  $snap['ui_refresh_seconds'] = max(3, min(300, (int)($cfg['UI_REFRESH_SECONDS'] ?? 10)));
  echo json_encode(['ok' => true, 'data' => $snap, 'ring' => v_ring()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
