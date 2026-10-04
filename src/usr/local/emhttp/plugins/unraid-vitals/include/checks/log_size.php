<?php
/* unraid-vitals — checks/log_size.php (P16-08 companion)
 *
 * Plugin's own logs (/var/tmp/unraid-vitals, /tmp/unraid-vitals) have no
 * inherent rotation; a runaway collector/agent can fill flash/RAM.
 * Raises a finding per log over the warning cap (soft: 10 MiB, critical:
 * 50 MiB) with the file name and size — so growth is visible in the UI and
 * the daily logrotate (scripts/vitals-logrotate.php) keeps files bounded.
 */

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