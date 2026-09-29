<?php
/**
 * unraid-vitals — checks/rootfs_full.php
 *
 * Sample check from plan 106 P13-01: root filesystem above 90% is an alert
 * (Unraid's `/` is RAM-backed — filling it can wedge docker, syslog, and the
 * webGUI itself, so it deserves its own check rather than living inside the
 * generic threshold alerts).
 *
 * Default threshold matches v_thresholds()['fill'] (90) for consistency with
 * the existing array-disk fill alert; overridable per-check via
 * CHECK_ROOTFS_FULL_SEVERITY in vitals.cfg (severity only — the threshold
 * itself is not yet configurable; add a CHECK_ROOTFS_FULL_THRESHOLD key if a
 * future ticket needs that).
 */

function v_check_rootfs_full(array $snap): array {
  $pct = $snap['rootfs']['used_pct'] ?? null;
  if ($pct === null) return [];

  $threshold = 90;
  if ($pct < $threshold) return [];

  $total = $snap['rootfs']['total'] ?? null;
  $free = $snap['rootfs']['free'] ?? null;

  return [[
    'severity' => $pct >= 98 ? 'critical' : 'alert',
    'subject' => 'rootfs',
    'title' => 'Root filesystem is ' . $pct . '% full',
    'detail' => 'The root filesystem (/) is at ' . $pct . '% usage'
      . ($free !== null ? ', ' . v_format_bytes((float)$free) . ' free' : '') . '.'
      . ' Unraid runs / from RAM — filling it can break docker, logging, and the webGUI.',
    'evidence' => array_values(array_filter([
      ['label' => 'used_pct', 'value' => $pct],
      $total !== null ? ['label' => 'total_bytes', 'value' => $total] : null,
      $free !== null ? ['label' => 'free_bytes', 'value' => $free] : null,
      ['label' => 'threshold_pct', 'value' => $threshold],
    ])),
    'fix_id' => 'rootfs_full',
  ]];
}

if (!function_exists('v_format_bytes')) {
  function v_format_bytes(float $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, 1) . ' ' . $units[$i];
  }
}
