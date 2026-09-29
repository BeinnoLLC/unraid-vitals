<?php
/**
 * unraid-vitals — checks/parity_health.php (P14-06)
 *
 * Reads $snap['parity_history'] (collect.php's v_parity_history(), parsed
 * from /boot/config/parity-checks.log) for three conditions:
 *   1. The last check finished with errors (corrected sectors > 0) -> alert
 *      — this means the array was silently inconsistent until the check
 *      caught and fixed it; worth knowing even though it's already fixed.
 *   2. No check has run in over 30 days -> warning.
 *   3. Speed has dropped across the last 5 checks (each one slower than
 *      the one before it) -> warning — a steadily-declining parity check
 *      speed is a common early sign of a failing/aging drive or a
 *      developing cabling/controller problem, well before SMART notices.
 */

function v_check_parity_health(array $snap): array {
  $out = [];

  if (!empty($snap['system']['unclean_shutdown'])) {
    $out[] = [
      'severity' => 'warning', 'subject' => 'parity',
      'title' => 'Unclean shutdown detected',
      'detail' => 'The array was not unmounted cleanly the last time it stopped (crash, power loss,'
        . ' or a forced shutdown). Unraid runs a parity check on the next array start to verify'
        . ' consistency — if one has not completed yet, treat parity as unverified until it has.',
      'evidence' => [
        ['label' => 'unclean_shutdown', 'value' => true],
      ],
      'fix_id' => null,
    ];
  }

  $history = $snap['parity_history'] ?? [];
  if (!$history) return $out;

  // History is oldest-first (see v_parity_history's own docblock) — last
  // element is the most recent check.
  $last = $history[count($history) - 1];

  if (($last['errors'] ?? 0) > 0 && !($last['cancelled'] ?? false)) {
    $out[] = [
      'severity' => 'alert', 'subject' => 'parity',
      'title' => 'Last parity check found ' . $last['errors'] . ' error(s)',
      'detail' => 'The most recent parity check (' . ($last['type'] ?: 'parity check') . ', '
        . ($last['date'] !== null ? date('Y-m-d', $last['date']) : 'unknown date') . ') corrected '
        . $last['errors'] . ' sector(s). The array was silently inconsistent until this check caught'
        . ' and fixed it — worth investigating why (unclean shutdown, failing drive, bad cabling).',
      'evidence' => [
        ['label' => 'errors', 'value' => $last['errors']],
        ['label' => 'date', 'value' => $last['date']],
        ['label' => 'type', 'value' => $last['type']],
      ],
      'fix_id' => null,
    ];
  }

  $lastDate = $last['date'] ?? null;
  $daysSince = $lastDate !== null ? (int)floor((($snap['time'] ?? time()) - $lastDate) / 86400) : null;
  $staleThresholdDays = 30;
  if ($daysSince !== null && $daysSince >= $staleThresholdDays) {
    $out[] = [
      'severity' => 'warning', 'subject' => 'parity',
      'title' => 'No parity check in ' . $daysSince . ' days',
      'detail' => 'The last parity check ran ' . $daysSince . ' days ago ('
        . date('Y-m-d', $lastDate) . '). A stale array without regular checks can carry a silent'
        . ' inconsistency for a long time before anything notices.',
      'evidence' => [
        ['label' => 'days_since_last_check', 'value' => $daysSince],
        ['label' => 'last_check_date', 'value' => $lastDate],
        ['label' => 'threshold_days', 'value' => $staleThresholdDays],
      ],
      'fix_id' => null,
    ];
  }

  // Speed trend across the last 5 non-cancelled checks with a real speed
  // reading, oldest to newest — strictly decreasing across all of them.
  $withSpeed = array_values(array_filter($history, fn($e) => !($e['cancelled'] ?? false) && ($e['speed_mbps'] ?? null) !== null));
  $recent = array_slice($withSpeed, -5);
  if (count($recent) === 5) {
    $declining = true;
    for ($i = 1; $i < count($recent); $i++) {
      if ($recent[$i]['speed_mbps'] >= $recent[$i - 1]['speed_mbps']) { $declining = false; break; }
    }
    if ($declining) {
      $first = $recent[0]['speed_mbps']; $lastSpeed = $recent[count($recent) - 1]['speed_mbps'];
      $out[] = [
        'severity' => 'warning', 'subject' => 'parity',
        'title' => 'Parity check speed has dropped every time for the last 5 checks',
        'detail' => 'Parity check speed has gotten slower in each of the last 5 checks, from '
          . $first . ' MB/s down to ' . $lastSpeed . ' MB/s. A steadily-declining speed is a common'
          . ' early sign of a failing/aging drive or a developing cabling/controller problem, often'
          . ' before SMART notices anything.',
        'evidence' => [
          ['label' => 'speeds_mbps', 'value' => array_column($recent, 'speed_mbps')],
          ['label' => 'first_speed_mbps', 'value' => $first],
          ['label' => 'last_speed_mbps', 'value' => $lastSpeed],
        ],
        'fix_id' => null,
      ];
    }
  }

  return $out;
}
