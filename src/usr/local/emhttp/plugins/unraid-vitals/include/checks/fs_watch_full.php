<?php

function v_check_fs_watch_full(array $snap): array {
  $out = [];
  $watch = $snap['fs_watch'] ?? [];
  $mounts = [
    'var_log' => ['label' => '/var/log', 'detail_extra' => ' A full /var/log can silently break logging and the webGUI.'],
    'tmp'     => ['label' => '/tmp',     'detail_extra' => ''],
    'run'     => ['label' => '/run',     'detail_extra' => ' A full /run can break service sockets and PID files.'],
  ];

  foreach ($mounts as $key => $meta) {
    $m = $watch[$key] ?? null;
    $pct = $m['used_pct'] ?? null;
    if ($pct === null || $pct < 80) continue;

    $largestLine = '';
    if (!empty($m['largest'])) {
      $top = $m['largest'][0];
      $largestLine = ' Largest file: ' . $top['path'] . ' (' . v_format_bytes_fs((float)$top['bytes']) . ').';
    }

    $out[] = [
      'severity' => $pct >= 95 ? 'critical' : ($pct >= 90 ? 'alert' : 'warning'),
      'subject' => 'fs_' . $key,
      'title' => $meta['label'] . ' is ' . $pct . '% full',
      'detail' => 'The ' . $meta['label'] . ' filesystem is at ' . $pct . '% usage.'
        . $meta['detail_extra'] . $largestLine,
      'evidence' => array_values(array_filter([
        ['label' => 'path', 'value' => $meta['label']],
        ['label' => 'used_pct', 'value' => $pct],
        isset($m['total']) ? ['label' => 'total_bytes', 'value' => $m['total']] : null,
        isset($m['free']) ? ['label' => 'free_bytes', 'value' => $m['free']] : null,
        !empty($m['largest']) ? ['label' => 'largest_files', 'value' => $m['largest']] : null,
        ['label' => 'threshold_pct', 'value' => 80],
      ])),
      'fix_id' => null,
    ];
  }

  return $out;
}

if (!function_exists('v_format_bytes_fs')) {
  function v_format_bytes_fs(float $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, 1) . ' ' . $units[$i];
  }
}
