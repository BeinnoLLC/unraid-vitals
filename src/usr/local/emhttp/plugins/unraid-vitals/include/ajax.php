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

function v_csrf_ok(): bool {
  // Same token Unraid's own webGui uses for every state-changing request
  // (var.ini csrf_token) — required on any POST that changes plugin config.
  $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
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

  $snap = v_latest();
  if (!$snap) $snap = v_tick(true);
  $snap['csrf_token'] = @parse_ini_file('/var/local/emhttp/var.ini')['csrf_token'] ?? '';
  echo json_encode(['ok' => true, 'data' => $snap, 'ring' => v_ring()], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
