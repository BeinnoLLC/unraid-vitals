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
    echo json_encode(['ok' => true, 'data' => $snap, 'ring' => v_ring()], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'daily') {
    $days = max(1, min(365, (int)($_GET['days'] ?? 30)));
    echo json_encode(['ok' => true, 'daily' => v_daily($days)], JSON_UNESCAPED_SLASHES);
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
    echo json_encode(['ok' => true,
      'cfg' => $cfg,
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
    $allowed = ['INTERVAL', 'KEEP_DAYS', 'SET_STARTPAGE', 'ALERT_TEMP', 'ALERT_FILL', 'ALERT_LOAD', 'ALERT_RESTARTS'];
    $cfg = is_file(V_CFG_FILE) ? (@parse_ini_file(V_CFG_FILE) ?: []) : [];
    foreach ($allowed as $k) if (isset($_POST[$k])) $cfg[$k] = $_POST[$k];
    $lines = [];
    foreach ($cfg as $k => $v) $lines[] = $k . '="' . str_replace('"', '', (string)$v) . '"';
    @mkdir(dirname(V_CFG_FILE), 0755, true);
    file_put_contents(V_CFG_FILE, implode("\n", $lines) . "\n");
    // Re-apply cron + start-page immediately, same as the old form's #command.
    if (is_executable(V_INSTALL_SH)) exec(escapeshellarg(V_INSTALL_SH) . ' --reapply 2>&1');
    echo json_encode(['ok' => true]);
    exit;
  }

  if ($action === 'findings') {
    echo json_encode(['ok' => true, 'findings' => v_ai_findings(), 'runs' => v_ai_runs()], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'share_comment') {
    $share = trim((string)($_GET['share'] ?? ''));
    if ($share === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'missing share']); exit; }
    echo json_encode(['ok' => true, 'comment' => v_share_comment($share)], JSON_UNESCAPED_SLASHES);
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

  if ($action === 'kb_search') {
    $q = trim((string)($_GET['q'] ?? ''));
    echo json_encode(['ok' => true, 'results' => $q === '' ? [] : v_kb_search($q), 'topics' => v_kb_topics()], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'kb_recent') {
    echo json_encode(['ok' => true, 'docs' => v_kb_recent(), 'topics' => v_kb_topics()], JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'research_ask') {
    if (!v_csrf_ok()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'bad csrf token']); exit; }
    $prompt = trim((string)($_POST['prompt'] ?? ''));
    if ($prompt === '' || mb_strlen($prompt) > 2000) {
      http_response_code(400); echo json_encode(['ok' => false, 'error' => 'prompt required (max 2000 chars)']); exit;
    }
    $id = v_research_create($prompt);
    if ($id === null) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'db unavailable']); exit; }
    $node = trim((string)@shell_exec('command -v node 2>/dev/null'));
    $script = __DIR__ . '/../agent/research.mjs';
    if ($node && is_file($script)) {
      exec(sprintf('nohup %s %s %d > /tmp/unraid-vitals-research.log 2>&1 &',
        escapeshellarg($node), escapeshellarg($script), (int)$id));
    }
    echo json_encode(['ok' => true, 'job_id' => $id]);
    exit;
  }

  if ($action === 'research_status') {
    $id = (int)($_GET['id'] ?? 0);
    $job = $id ? v_research_get($id) : null;
    echo json_encode(['ok' => true, 'job' => $job]);
    exit;
  }

  if ($action === 'research_list') {
    echo json_encode(['ok' => true, 'jobs' => v_research_list()]);
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

  $snap = v_latest();
  if (!$snap) $snap = v_tick(true);
  $snap['csrf_token'] = @parse_ini_file('/var/local/emhttp/var.ini')['csrf_token'] ?? '';
  $snap['_age'] = max(0, time() - (int)($snap['time'] ?? 0));
  echo json_encode(['ok' => true, 'data' => $snap, 'ring' => v_ring()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
