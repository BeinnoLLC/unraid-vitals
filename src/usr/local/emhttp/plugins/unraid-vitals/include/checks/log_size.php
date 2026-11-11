<?php

declare(strict_types=1);

function v_check_log_size(array $snap): array {
  $warn = 10 * 1024 * 1024;
  $crit = 50 * 1024 * 1024;
  $findings = [];
  foreach (['/var/tmp/unraid-vitals', '/tmp/unraid-vitals'] as $root) {
    foreach (glob($root . '/*.log') ?: [] as $log) {
      $size = @filesize($log);
      if ($size === false) continue;
      if ($size < $warn) continue;
      $name = basename($log);
      $sev = $size >= $crit ? 'critical' : 'warning';
      $mb = round($size / 1048576, 1);
      $findings[] = [
        'check_id' => 'log_size',
        'severity' => $sev,
        'subject'  => 'log_size',
        'title'    => "Log file $name is {$mb} MiB",
        'detail'   => "The plugin's own log $log has grown to {$mb} MiB ("
          . ($sev === 'critical' ? 'critical' : 'warning')
          . " cap " . ($sev === 'critical' ? '50' : '10') . " MiB). The daily logrotate job"
          . " archives and truncates it; repeated growth means a caller is logging too much.",
        'evidence' => [['label' => 'file', 'value' => $log], ['label' => 'bytes', 'value' => $size]],
        'fix_id'   => 'log_size_rotate',
      ];
    }
  }
  return $findings;
}