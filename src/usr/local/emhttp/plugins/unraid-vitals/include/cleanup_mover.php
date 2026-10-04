<?php
/* unraid-vitals — include/cleanup_mover.php (P16-07 / #82)
 *
 * Start / stop / status for Unraid's mover (/usr/local/sbin/mover).
 * START: refuses while a parity check/sync/rebuild is running (md_state not
 * in {STARTED,STOPPED,SLEEPING}) unless the caller sends confirm_parity=yes.
 * PROGRESS: mover itself has no progress API; we surface liveness (pidfile +
 * process) + a tail of its syslog lines (logger -t move) as the progress feed.
 * STOP: mover responds to SIGINT/SIGTERM per its own trap (kill by pid; children
 * in same namespace via the pidfile's pgrep -n).
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';

function v_mover_pidfile(): string { return '/var/run/mover.pid'; }

function v_mover_running(): bool {
  $pid = (int)trim((string)@file_get_contents(v_mover_pidfile()) ?: '0');
  if ($pid <= 1) return false;
  // pidfile may outlive the process — verify with /proc
  return @file_exists('/proc/' . $pid) && trim((string)@file_get_contents('/proc/' . $pid . '/comm')) === 'bash';
}

/** Parity state from the snapshot; falls back to /proc/mdstat scan. */
function v_mover_parity_busy(array $snap = []): bool {
  $state = (string)($snap['array']['state'] ?? $snap['system']['mdState'] ?? '');
  // mdState STARTED means array up but NOT checking. resync/rebuild/check states:
  $busyStates = ['resync', 'rebuild', 'check', 'clear', 'mdresync'];
  foreach ($busyStates as $b) if (stripos($state, $b) !== false) return true;
  // belt+braces: /proc/mdstat live scan. The progress lines ("resync = 12%",
  // "recovery = ...", "check = ...") ONLY exist while an op is actually
  // running — a healthy idle mdstat never contains them.
  $md = @file_get_contents('/proc/mdstat') ?: '';
  return (bool)preg_match('/\b(resync|recovery|check)\s*=\s*[0-9.]+%/', $md);
}

/** Start mover. $confirmParity: explicit 'yes' overrides the parity refusal. */
function v_mover_start(array $snap = [], string $confirmParity = ''): array {
  if (v_mover_running()) return ['ok' => false, 'error' => 'mover is already running'];
  if (v_mover_parity_busy($snap) && $confirmParity !== 'yes') {
    return ['ok' => false, 'error' => 'parity check/sync is running — confirm to start the mover anyway',
            'confirm_required' => true];
  }
  $out = (string)@shell_exec('nohup /usr/local/sbin/mover start > /var/tmp/unraid-vitals/mover-run.log 2>&1 & echo $!');
  return ['ok' => true, 'started' => true, 'spawn_pid' => (int)trim($out)];
}

/** Stop a running mover (SIGTERM to pid + its children per mover's own trap). */
function v_mover_stop(): array {
  $pid = (int)trim((string)@file_get_contents(v_mover_pidfile()) ?: '0');
  if ($pid <= 1 || !@file_exists('/proc/' . $pid)) return ['ok' => false, 'error' => 'mover not running'];
  @shell_exec('timeout 5 kill -TERM ' . (int)$pid . ' 2>&1');
  return ['ok' => true, 'stopped' => $pid];
}

/** Status: live + recent syslog lines as the progress feed. */
function v_mover_status(): array {
  $running = v_mover_running();
  $pid = (int)trim((string)@file_get_contents(v_mover_pidfile()) ?: '0');
  $lines = [];
  if (is_file('/var/tmp/unraid-vitals/mover-run.log')) {
    $lines = array_slice(@file('/var/tmp/unraid-vitals/mover-run.log', FILE_IGNORE_NEW_LINES) ?: [], -30);
  } else {
    // syslog fallback (schedule-driven runs)
    $out = @shell_exec('grep -h "mover" /var/log/syslog 2>/dev/null | tail -n 12') ?: '';
    $lines = explode("\n", trim($out));
  }
  return ['ok' => true, 'running' => $running, 'pid' => $running ? $pid : 0,
          'parity_busy' => v_mover_parity_busy(), 'log' => array_filter($lines)];
}