<?php
/* unraid-vitals — include/job_control.php (P18-05 / #93, P18-06 / #94, P18-08 / #96)
 *
 * Per-job: run-now (CSRF at the ajax layer), last start/duration/exit status/
 * last log lines (parsed from the job's own log — cron stdout), lock refusal
 * (flock -n semantics), timeout enforcement (run-now side), heavy-job guard
 * (quiet hours + parity/mover busy skip), and lock+timeout wiring for jobs
 * that lacked it (collector's overlaps could stack docker stats + SMART reads).
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/schedule_registry.php';

if (!defined('V_JOBCONTROL_DIR')) {
  define('V_JOBCONTROL_DIR', '/var/tmp/unraid-vitals');
}

/** Which lock file backs a job (all lockable jobs get one, collector included). */
function v_job_lock(string $id): string {
  return V_JOBCONTROL_DIR . '/' . $id . '.lock';
}

/** Is the lock held right now? (flock probe via a third fd — non-blocking) */
function v_job_lock_held(string $id): bool {
  $lockFile = v_job_lock($id);
  if (!is_file($lockFile)) return false;
  $fp = @fopen($lockFile, 'c');
  if (!$fp) return true; // cannot even open: assume held (permissions)
  $wouldBlock = false;
  $ok = flock($fp, LOCK_EX | LOCK_NB, $wouldBlock);
  flock($fp, LOCK_UN);
  fclose($fp);
  return !$ok || $wouldBlock;
}

/**
 * quiet hours: cfg QUIET_START/QUIET_END (hours 0-23, may wrap midnight).
 * A heavy job started inside the window is refused.
 */
function v_job_quiet_active(): bool {
  $cfg = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
  $qs = (int)($cfg['QUIET_START'] ?? -1);
  $qe = (int)($cfg['QUIET_END'] ?? -1);
  if ($qs < 0 || $qe < 0 || $qs === $qe) return false;
  $h = (int)date('G');
  if ($qs < $qe) return $h >= $qs && $h < $qe;
  return $h >= $qs || $h < $qe; // wraps midnight
}

/** Heavy-busy: parity/mover running (same check the mover refuses with). */
function v_job_busy_system(): bool {
  require_once __DIR__ . '/cleanup_mover.php';
  return v_mover_parity_busy();
}

/**
 * Run NOW: executes the job's command in the background via the same flock
 * guard the cron entry uses; refuses when the lock is held, when quiet hours
 * block heavy jobs, or when the system is busy (heavy jobs only — unless
 * $force). Records start/duration/exit to the runbook log the UI reads.
 */
function v_job_run_now(string $id, bool $force = false): array {
  $reg = v_sched_registry();
  if (!isset($reg[$id])) return ['ok' => false, 'error' => 'unknown job'];
  $job = $reg[$id];
  if ($id === 'collector') return ['ok' => false, 'error' => 'use action=refresh for the collector'];
  if (v_job_lock_held($id)) return ['ok' => false, 'error' => 'lock held — job already running', 'lock' => v_job_lock($id)];
  if (($job['heavy'] ?? false) && !$force) {
    if (v_job_quiet_active()) return ['ok' => false, 'error' => 'quiet hours — heavy job not started (force to override)'];
    if (v_job_busy_system()) return ['ok' => false, 'error' => 'parity/mover busy — heavy job not started (force to override)'];
  }
  // node requirement honoured at run-now too
  $nodeBin = trim((string)@shell_exec('command -v node 2>/dev/null'));
  if ($job['needs_node'] && $nodeBin === '') return ['ok' => false, 'error' => 'node not available'];
  // The jobwrap path keeps cron and run-now on one command surface and writes
  // the same runbook.<id>.json the status view reads.
  $bg = 'nohup /usr/bin/php /usr/local/emhttp/plugins/unraid-vitals/scripts/vitals-jobwrap.php '
      . escapeshellarg($id) . ' >> /var/tmp/unraid-vitals/jobwrap.log 2>&1 & echo $!';
  // mark the runbook entry as manual BEFORE the wrap overwrites... jobwrap
  // preserves a same-start manual flag; pre-seed it:
  v_write_json(V_JOBCONTROL_DIR . '/runbook.' . $id . '.json',
    ['job' => $id, 'manual' => true, 'started' => time()]);
  $pid = (int)trim((string)@shell_exec($bg));
  return ['ok' => true, 'job' => $id, 'pid' => $pid];
}

/** Per-job status: running?, last run start/duration/exit, last log lines. */
function v_job_status(string $id): array {
  $reg = v_sched_registry();
  if (!isset($reg[$id])) return ['ok' => false];
  $job = $reg[$id];
  $logFile = V_JOBCONTROL_DIR . '/' . $job['log'];
  $running = v_job_lock_held($id);
  $lastLines = [];
  if (is_file($logFile)) {
    $all = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lastLines = array_slice($all, -12);
  }
  $rbPath = V_JOBCONTROL_DIR . '/runbook.' . $id . '.json';
  $rb = is_file($rbPath) ? (v_read_json($rbPath) ?: []) : [];
  $exitStatus = $rb['exit'] ?? null;
  $duration = (isset($rb['started'], $rb['ended']) && is_numeric($rb['started']) && is_numeric($rb['ended']))
    ? (int)$rb['ended'] - (int)$rb['started'] : null;
  return ['ok' => true, 'job' => $id, 'running' => $running, 'last_start' => $rb['started'] ?? null,
          'last_duration_s' => $duration, 'last_exit' => $exitStatus,
          'manual' => $rb['manual'] ?? false, 'lines' => $lastLines];
}

/** All jobs' statuses for the schedules panel. */
function v_job_statuses(): array {
  $out = [];
  foreach (v_sched_registry() as $id => $job) {
    $st = v_job_status($id);
    $out[$id] = ['label' => $job['label'], 'sched' => $job['sched'],
                 'enabled' => ((@parse_ini_file(VITALS_FLASH . '/vitals.cfg')['SCHED_' . strtoupper($id) . '_ENABLED'] ?? '1') !== '0'),
                 'running' => $st['running'] ?? false,
                 'last_start' => $st['last_start'], 'last_duration_s' => $st['last_duration_s'],
                 'last_exit' => $st['last_exit'], 'lines' => $st['lines'],
                 'heavy' => (bool)($job['heavy'] ?? false)];
  }
  return $out;
}