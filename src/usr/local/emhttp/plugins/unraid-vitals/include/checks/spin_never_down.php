<?php
/**
 * unraid-vitals — checks/spin_never_down.php (P14-08)
 *
 * Reads $snap['spin_analysis'] (collect.php's v_spin_track() + v_spin_analysis(),
 * a minute-resolution history of each array disk's spundown state plus
 * /proc/diskstats reads/writes, rotated to the last 24 hours).
 *
 * A disk that's never allowed to spin down burns power and wears bearings
 * for no reason if nothing actually needs it awake. This check fires when
 * a disk has been continuously spun up for the full tracked window (24h)
 * AND names the interval between the I/O activity that's keeping it awake
 * -- matching the ticket's acceptance case: "a disk kept awake by a
 * container scanning it every 10 minutes is reported with the interval."
 */

function v_check_spin_never_down(array $snap): array {
  $out = [];
  $analysis = $snap['spin_analysis'] ?? [];
  foreach ($analysis as $name => $a) {
    if (empty($a['continuously_up'])) continue;

    $gap = $a['median_activity_gap_min'] ?? null;
    $intervalText = $gap !== null
      ? ('activity roughly every ' . $gap . ' minute(s), based on ' . $a['activity_count'] . ' observed I/O burst(s)')
      : 'no distinct I/O bursts detected in the tracked window -- something may be holding the device open';

    $out[] = [
      'severity' => 'warning', 'subject' => 'spin_never_down',
      'title' => $name . ' has not spun down in ' . round($a['span_minutes'] / 60, 1) . 'h',
      'detail' => 'Disk ' . $name . ' has stayed spun up for the entire tracked window ('
        . round($a['span_minutes'] / 60, 1) . ' hours) without a single spin-down. ' . ucfirst($intervalText)
        . '. If nothing needs the disk awake that often, check for a scheduled scan, a monitoring container'
        . ' polling it, or a share/appdata path with the wrong cache setting keeping it active.',
      'evidence' => [
        ['label' => 'disk', 'value' => $name],
        ['label' => 'span_minutes', 'value' => $a['span_minutes']],
        ['label' => 'spun_up_minutes', 'value' => $a['spun_up_minutes']],
        ['label' => 'median_activity_gap_min', 'value' => $gap],
        ['label' => 'activity_count', 'value' => $a['activity_count']],
      ],
      'fix_id' => null,
    ];
  }
  return $out;
}
