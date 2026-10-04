#!/usr/bin/php
<?php
/* unraid-vitals — scripts/vitals-jobwrap.php (P18-05 backend)
 * Usage: vitals-jobwrap.php <jobId>
 * Runs the job's registry command under its flock + timeout, and records
 * start/duration/exit into runbook.<id>.json for the schedules panel.
 * The cron entry (written by the registry) calls this — so cron and the
 * Run-now button share one command path.
 */
declare(strict_types=1);
require '/usr/local/emhttp/plugins/unraid-vitals/include/schedule_registry.php';
require '/usr/local/emhttp/plugins/unraid-vitals/include/store.php';

$id = $argv[1] ?? '';
$reg = v_sched_registry();
if (!isset($reg[$id])) { fwrite(STDERR, "jobwrap: unknown job '$id'\n"); exit(2); }
$job = $reg[$id];

// env from getenv (install.sh exports them; run-now passes its own)
$envVars = [];
foreach (['NODE_BIN', 'LLM_PRIMARY_CFG', 'LLM_BACKUP_CFG', 'DIAG_INTERVAL_CFG',
          'DIAG_WINDOW_CFG', 'DIAG_MODELS_CFG', 'UPDATE_INTERVAL_CFG'] as $k) {
  if (getenv($k) !== false) $envVars[$k] = (string)getenv($k);
}
$cmd = v_sched_build_cmd($id, $envVars);
if ($cmd === '') { fwrite(STDERR, "jobwrap: no command for '$id'\n"); exit(2); }

$rbFile = V_JOBCONTROL_DIR . '/runbook.' . $id . '.json';
$started = time();
$timeout = (int)($job['timeout'] ?? 14400);

// Order matters: VAR=… prefixes come OUTSIDE the flock/timeout wrapper —
// 'timeout 600 VAR=1 cmd' makes timeout(1) treat VAR=1 as the binary
// (caught live: "failed to run command 'VITALS_UPDATE_INTERVAL_MINUTES=1440'").
$full = $cmd;
// inject flock+timeout right after a leading run of VAR=... tokens
if (preg_match('/^((?:[A-Za-z_][A-Za-z0-9_]*="[^"]*" )+)(.*)$/', $cmd, $m)) {
  $full = $m[1] . '/usr/bin/flock -n ' . escapeshellarg(V_JOBCONTROL_DIR . '/' . $id . '.lock')
        . ' /usr/bin/timeout ' . $timeout . ' ' . $m[2];
} else {
  $full = '/usr/bin/flock -n ' . escapeshellarg(V_JOBCONTROL_DIR . '/' . $id . '.lock')
        . ' /usr/bin/timeout ' . $timeout . ' ' . $cmd;
}
$code = 0;
passthru($full, $code);

$rb = ['job' => $id, 'started' => $started, 'ended' => time(), 'exit' => $code,
       'duration_s' => time() - $started, 'manual' => false];
if (is_file($rbFile)) {
  $prev = v_read_json($rbFile) ?: [];
  if (($prev['started'] ?? 0) === $started) $rb['manual'] = $prev['manual'] ?? false;
}
v_write_json($rbFile, $rb);
exit(0);