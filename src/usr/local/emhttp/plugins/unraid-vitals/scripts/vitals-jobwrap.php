#!/usr/bin/php
<?php

declare(strict_types=1);
require '/usr/local/emhttp/plugins/unraid-vitals/include/schedule_registry.php';
require '/usr/local/emhttp/plugins/unraid-vitals/include/store.php';

$id = $argv[1] ?? '';
$reg = v_sched_registry();
if (!isset($reg[$id])) { fwrite(STDERR, "jobwrap: unknown job '$id'\n"); exit(2); }
$job = $reg[$id];

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

$full = $cmd;

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