<?php
/**
 * unraid-vitals — checks/anomaly_baseline.php (P15-03)
 *
 * Wraps v_anomaly_check() (see store.php for the median+MAD hour-of-week
 * baseline math) as a finding. Runs on live hourly flash history, not
 * $snap — needs 2+ weeks of rollups, which $snap alone never carries.
 */

function v_check_anomaly_baseline(array $snap): array {
  $result = v_anomaly_check();
  if ($result['status'] !== 'ok') return [];   // not enough history yet — no finding, not an error

  $out = [];
  foreach ($result['findings'] as $f) {
    $out[] = [
      'severity' => 'warning',
      'subject' => 'anomaly_' . $f['metric'],
      'title' => ucfirst($f['metric']) . ' is well outside its usual range for this time',
      'detail' => ucfirst($f['metric']) . ' has read ' . $f['value'] . ' for 3 consecutive hours, versus a usual'
        . ' ' . $f['baseline_median'] . ' at this hour of the week (z-score ' . $f['z_score'] . ').'
        . ' This compares against the same hour-of-week over the last 2+ weeks, so a metric that is'
        . ' normally busy at this hour (e.g. a nightly backup window) will not trigger.',
      'evidence' => [
        ['label' => 'metric', 'value' => $f['metric']],
        ['label' => 'value', 'value' => $f['value']],
        ['label' => 'baseline_median', 'value' => $f['baseline_median']],
        ['label' => 'z_score', 'value' => $f['z_score']],
        ['label' => 'consecutive_hours', 'value' => $f['hours']],
      ],
      'fix_id' => null,
    ];
  }
  return $out;
}
