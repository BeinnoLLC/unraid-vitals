<?php
/**
 * unraid-vitals — checks/capacity_forecast.php (P15-01)
 *
 * Linear fit over the last 30 daily fs_bytes snapshots per array/cache disk
 * and docker.img (see v_capacity_forecast() in store.php for the math).
 * Raises a finding for anything projected to fill within 14 days — a poor
 * fit or a flat/shrinking trend produces no forecast for that target at
 * all, so this check simply reports whatever v_capacity_forecast() already
 * decided was worth surfacing.
 *
 * Unlike most checks here, this one needs history (30 days of daily
 * rollups), not just the live $snap — the totals/free-space-now numbers
 * come from $snap, the growth trend comes from v_daily(30).
 */

function v_check_capacity_forecast(array $snap): array {
  $totals = [];
  foreach (array_merge($snap['array']['data'] ?? [], $snap['array']['cache'] ?? []) as $d) {
    if (($d['fsSize'] ?? 0) > 0 && !empty($d['name'])) $totals[$d['name']] = [$d['fsSize'], $d['fsFree']];
  }
  if (isset($snap['docker_image']['total'])) {
    $totals['docker.img'] = [$snap['docker_image']['total'], $snap['docker_image']['free']];
  }
  if (!$totals) return [];

  $forecasts = v_capacity_forecast(v_daily(30), $totals);
  $out = [];
  foreach ($forecasts as $f) {
    if (!$f['within_14d']) continue;
    $out[] = [
      'severity' => $f['days_left'] <= 3 ? 'critical' : ($f['days_left'] <= 7 ? 'alert' : 'warning'),
      'subject' => 'capacity_' . $f['name'],
      'title' => $f['name'] . ' projected full in about ' . $f['days_left'] . ' day(s)',
      'detail' => $f['name'] . ' is growing about ' . $f['gb_per_day'] . ' GB/day '
        . '(' . $f['free_now_gb'] . ' GB free of ' . $f['total_gb'] . ' GB total). '
        . 'At this rate it fills in about ' . $f['days_left'] . ' day(s).',
      'evidence' => [
        ['label' => 'target', 'value' => $f['name']],
        ['label' => 'gb_per_day', 'value' => $f['gb_per_day']],
        ['label' => 'days_left', 'value' => $f['days_left']],
        ['label' => 'fit_r2', 'value' => $f['r2']],
        ['label' => 'free_now_gb', 'value' => $f['free_now_gb']],
        ['label' => 'total_gb', 'value' => $f['total_gb']],
      ],
      'fix_id' => null,
    ];
  }
  return $out;
}
