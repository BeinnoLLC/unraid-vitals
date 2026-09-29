<?php
/**
 * unraid-vitals — checks/docker_log_large.php (P14-02)
 *
 * Container JSON log files (Docker's default json-file log driver) grow
 * unbounded unless the container was started with --log-opt max-size. A
 * chatty container can fill the array/cache disk it lives on.
 *
 * fix_id 'docker_log_truncate' names the action a future ticket (P16-04)
 * is expected to wire up — that action does not exist yet, so this finding
 * currently has no "Fix" button; the fix_id is forward-declared so P16-04
 * only has to implement the action, not also go find every place a fix_id
 * needs adding.
 */

function v_check_docker_log_large(array $snap): array {
  $sizeThreshold = 524288000; // 500 MB
  $growthThreshold = 104857600; // 100 MB/day

  $cache = $snap['docker_logs'] ?? [];
  $logs = $cache['logs'] ?? [];
  $prevLogs = $cache['prev_logs'] ?? [];
  $ts = $cache['ts'] ?? null;
  $prevTs = $cache['prev_ts'] ?? null;
  $dayGap = ($ts !== null && $prevTs !== null && $ts > $prevTs) ? ($ts - $prevTs) / 86400 : null;

  $out = [];
  foreach ($logs as $name => $log) {
    $bytes = $log['bytes'] ?? null;
    if ($bytes === null) continue;

    $growthPerDay = null;
    if ($dayGap !== null && $dayGap > 0 && isset($prevLogs[$name]['bytes'])) {
      $delta = $bytes - $prevLogs[$name]['bytes'];
      if ($delta > 0) $growthPerDay = $delta / $dayGap;
    }

    $overSize = $bytes >= $sizeThreshold;
    $overGrowth = $growthPerDay !== null && $growthPerDay >= $growthThreshold;
    if (!$overSize && !$overGrowth) continue;

    $reasons = [];
    if ($overSize) $reasons[] = v_format_bytes_docker((float)$bytes) . ' log file';
    if ($overGrowth) $reasons[] = 'growing ' . v_format_bytes_docker((float)$growthPerDay) . '/day';

    $out[] = [
      'severity' => $overSize && $bytes >= $sizeThreshold * 2 ? 'alert' : 'warning',
      'subject' => 'docker_logs',
      'title' => 'Container "' . $name . '" log: ' . implode(', ', $reasons),
      'detail' => 'Container "' . $name . '"\'s log file (' . ($log['path'] ?? 'unknown path') . ') is '
        . v_format_bytes_docker((float)$bytes)
        . ($growthPerDay !== null ? ', growing about ' . v_format_bytes_docker((float)$growthPerDay) . ' per day' : '')
        . '. Docker\'s default json-file log driver has no size cap unless the container sets'
        . ' --log-opt max-size.',
      'evidence' => array_values(array_filter([
        ['label' => 'container', 'value' => $name],
        ['label' => 'log_bytes', 'value' => $bytes],
        ['label' => 'size_threshold_bytes', 'value' => $sizeThreshold],
        $growthPerDay !== null ? ['label' => 'growth_bytes_per_day', 'value' => round($growthPerDay, 0)] : null,
        $growthPerDay !== null ? ['label' => 'growth_threshold_bytes_per_day', 'value' => $growthThreshold] : null,
      ])),
      'fix_id' => 'docker_log_truncate',
    ];
  }
  return $out;
}
