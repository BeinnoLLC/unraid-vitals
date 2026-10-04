<?php
/* unraid-vitals — include/smart_selftest.php (P17-04 / #87)
 *
 * Start short/long SMART self-tests per disk from the UI, show running state
 * + result history. Never starts during a parity check (mdstat op check the
 * mover refuses with — same v_mover_parity_busy()). Scheduling through the
 * P18-01 registry when that lands; the UI exposes manual start/progress now.
 *
 * Disk identity: /dev/sdX from disks.ini's device assignments (real device
 * nodes only — nvme uses nvme0n1 and smartctl --json there).
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';

const V_SMART_TEST_TYPES = ['short' => 'short', 'long' => 'long', 'conveyance' => 'conveyance'];

/** All real disks assignable to a test: name → device node (bare names in
 *  disks.ini become /dev/<dev>; nvme0n1-style too). */
function v_smart_disks(): array {
  $out = [];
  foreach ((@parse_ini_file('/var/local/emhttp/disks.ini', true) ?: []) as $name => $d) {
    if (!is_array($d)) continue;
    $dev = (string)($d['device'] ?? '');
    if ($dev === '') continue;
    if (!preg_match('/^(sd[a-z]+|nvme[0-9]+n[0-9]+)$/', $dev)) continue;
    if (!@file_exists('/dev/' . $dev)) continue;
    $out[$name] = ['device' => '/dev/' . $dev, 'rotational' => (($d['rotational'] ?? '1') === '1')];
  }
  return $out;
}

/** Progress/state: smartctl -a shows Self-test execution status + progress %. */
function v_smart_test_status(string $dev): array {
  if (str_contains($dev, 'nvme')) {
    $raw = @shell_exec('timeout 15 smartctl --json -a ' . escapeshellarg($dev) . ' 2>/dev/null');
    $j = $raw ? json_decode($raw, true) : null;
    $st = $j['smart_status'] ?? [];
    return [
      'running' => false,
      'status' => $j['smart_status']['passed'] ?? null ? 'passed' : 'unknown',
      'selftest' => $j['nvme_self_test_log'] ?? [],
      'progress_pct' => null,
    ];
  }
  $raw = @shell_exec('timeout 20 smartctl -a ' . escapeshellarg($dev) . ' 2>/dev/null') ?: '';
  $running = false; $pct = null; $status = null;
  if (preg_match('/Self-test execution status:\s*\(\s*(\d+)\)\s+([^(]+?)(?:\s+([0-9]+)%)?\s*(?:remaining|in progress)?\s*\)/i', $raw, $m)) {
    $code = (int)$m[1];
    // code 249/240/ etc: in progress forms; 0: completed without error
    if ($code === 240 || $code === 249 || $code === 217) { $running = true; $pct = isset($m[3]) ? (int)$m[3] : null; }
    $status = trim($m[2]);
  }
  // last test result table
  $last = null;
  if (preg_match('/SMART Self-test log structure revision number: (\d+)/si', $raw) === 1) {
    $m = null;
    if (preg_match('#^\s*#\s*1\s+(Extended|Short|Conveyance)\s+offline\s+(.*)$#im', $raw, $m)) {
      $last = ['type' => $m[1], 'result' => trim($m[2])];
    }
  }
  return ['running' => $running, 'progress_pct' => $pct, 'status' => $status, 'last' => $last];
}

/** Start a test: refuses during parity; refuses if one is already running. */
function v_smart_test_start(string $deviceName, string $type): array {
  if (!isset(V_SMART_TEST_TYPES[$type])) return ['ok' => false, 'error' => 'unknown test type'];
  require_once __DIR__ . '/cleanup_mover.php';
  if (v_mover_parity_busy()) {
    return ['ok' => false, 'error' => 'refusing: a parity check/resync is running', 'confirm_required' => false];
  }
  $disks = v_smart_disks();
  if (!isset($disks[$deviceName])) return ['ok' => false, 'error' => 'unknown disk'];
  $dev = $disks[$deviceName]['device'];
  // guard real path
  if (!preg_match('#^/dev/(sd[a-z]+|nvme[0-9]+n[0-9]+)$#', $dev)) return ['ok' => false, 'error' => 'device path failed validation'];
  // nvme: self-test via nvme-cli is a different surface — not supported here
  if (str_contains($dev, 'nvme')) return ['ok' => false, 'error' => 'nvme self-test not supported yet (device reported in the UI as data-only)'];
  // refuse if a test is already running on this disk
  $st = v_smart_test_status($dev);
  if ($st['running']) return ['ok' => false, 'error' => 'a self-test is already running on this disk'];
  $flag = $type === 'long' ? 'long' : ($type === 'conveyance' ? 'conveyance' : 'short');
  $out = (string)@shell_exec('timeout 30 smartctl -t ' . $flag . ' ' . escapeshellarg($dev) . ' 2>&1');
  $okStart = stripos($out, 'START OF') !== false || stripos($out, 'has begun') !== false || stripos($out, 'Testing has begun') !== false;
  return ['ok' => $okStart, 'started' => $okStart, 'device' => $deviceName, 'type' => $flag,
          'error' => $okStart ? null : substr($out, 0, 200)];
}

/** History for one disk (parsed self-test log) + the UI list. */
function v_smart_test_history(string $deviceName): array {
  $disks = v_smart_disks();
  if (!isset($disks[$deviceName])) return [];
  $dev = $disks[$deviceName]['device'];
  $raw = @shell_exec('timeout 20 smartctl -l selftest ' . escapeshellarg($dev) . ' 2>/dev/null') ?: '';
  $rows = [];
  foreach (explode("\n", $raw) as $line) {
    // "# 1  Short offline  Completed without error  00%  123  -"
    if (preg_match('/^#\s*(\d+)\s+(Short|Extended|Long|Conveyance|Vendor)\s+(offline|Online|Offline)\s+(.+?)\s+(\d+)%\s+(\d+)\s+([-0-9]+)$/', trim($line), $m)) {
      $rows[] = ['num' => (int)$m[1], 'type' => $m[2] . ' ' . $m[3], 'result' => trim($m[4]),
                 'lifetime_pct' => (int)$m[5], 'lba' => $m[6], 'status' => $m[7] === '-' ? null : $m[7]];
    }
  }
  return $rows;
}

/** Full status+history payload for the UI (all disks at once). */
function v_smart_test_overview(): array {
  $out = [];
  foreach (v_smart_disks() as $name => $info) {
    $st = v_smart_test_status($info['device']);
    $out[$name] = [
      'device' => $info['device'],
      'running' => $st['running'],
      'progress_pct' => $st['progress_pct'],
      'status' => $st['status'] ?? null,
      'history' => array_slice(v_smart_test_history($name), 0, 5),
    ];
  }
  return $out;
}