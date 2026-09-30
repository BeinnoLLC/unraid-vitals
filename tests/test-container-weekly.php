<?php
/**
 * unraid-vitals — standalone test for v_container_weekly_report() (P15-07).
 * Builds a fake hourly-history JSONL file under a temp VITALS_FLASH dir,
 * with two containers across two synthetic weeks, and checks the
 * acceptance criterion: week-over-week CPU change is reported correctly
 * per container, restarts are counted as a within-window delta of the
 * lifetime counter (not the raw last value), and a container with no
 * prior-week data reports null change rather than a bogus number.
 */

$tmpFlash = sys_get_temp_dir() . '/vitals-weekly-test-' . getmypid();
@mkdir($tmpFlash . '/history', 0755, true);
define('VITALS_FLASH', $tmpFlash);

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/unraid-vitals/include/store.php';

function cleanup(string $dir): void {
  foreach (glob($dir . '/*') ?: [] as $f) is_dir($f) ? cleanup($f) : @unlink($f);
  @rmdir($dir);
}

$failures = 0;
function check(bool $ok, string $label): void {
  global $failures;
  echo ($ok ? 'PASS' : 'FAIL') . ' — ' . $label . "\n";
  if (!$ok) $failures++;
}

$now = time();
$hoursAgo = fn($h) => intdiv($now - $h * 3600, 3600);

$rows = [];
// Last week (days 8-14 ago): plex averages 20% cpu, restarts go 5 -> 7 (2 restarts).
// sonarr has NO data last week (started this week) -- must report null change.
// (2-hour margin on both sides of the 7-day boundary to avoid hour-bucket
// rounding ambiguity right at the cutoff -- the function's threshold is
// wall-clock, not hour-aligned, so a row placed exactly at the boundary
// hour can legitimately land on either side depending on sub-hour timing.)
for ($h = 14 * 24; $h > 7 * 24 + 2; $h--) {
  $rows[] = ['h' => $hoursAgo($h), 'ctr' => [
    'plex' => [20.0, 35.0, 500000, 600000, 5 + intdiv(14*24 - $h, 20)],   // restart counter creeps from 5 to 7
  ]];
}
// This week (days 0-7 ago): plex averages 40% cpu (2x last week -- should show +100% change),
// restarts continue 7 -> 9 (2 more restarts this week). sonarr appears fresh, avg 10% cpu.
for ($h = 7 * 24 - 2; $h >= 0; $h--) {
  $rows[] = ['h' => $hoursAgo($h), 'ctr' => [
    'plex' => [40.0, 55.0, 700000, 750000, 7 + intdiv(7*24 - $h, 84)],
    'sonarr' => [10.0, 15.0, 100000, 120000, 1],
  ]];
}

$file = $tmpFlash . '/history/' . date('Y-m', $now) . '.jsonl';
$fh = fopen($file, 'w');
foreach ($rows as $r) fwrite($fh, json_encode($r) . "\n");
fclose($fh);

$report = v_container_weekly_report();
check($report['have_last_week'] === true, 'have_last_week is true (a full prior week of history exists)');

$byName = [];
foreach ($report['containers'] as $c) $byName[$c['name']] = $c;

check(isset($byName['plex']), 'plex is present in the report');
check(isset($byName['sonarr']), 'sonarr is present in the report');

if (isset($byName['plex'])) {
  $p = $byName['plex'];
  check(abs($p['this_week']['cpu_avg'] - 40.0) < 0.5, 'plex this-week avg CPU is ~40%, got ' . $p['this_week']['cpu_avg']);
  check(abs($p['last_week']['cpu_avg'] - 20.0) < 0.5, 'plex last-week avg CPU is ~20%, got ' . $p['last_week']['cpu_avg']);
  check($p['cpu_change_pct'] !== null && $p['cpu_change_pct'] > 80 && $p['cpu_change_pct'] < 120,
    'plex week-over-week CPU change is roughly +100% (doubled), got ' . $p['cpu_change_pct'] . '%');
  check($p['this_week']['restarts'] >= 1 && $p['this_week']['restarts'] <= 3,
    'plex restarts THIS WEEK is a small delta (2), not the raw lifetime counter (9), got ' . $p['this_week']['restarts']);
}

if (isset($byName['sonarr'])) {
  $s = $byName['sonarr'];
  check($s['last_week'] === null, 'sonarr has no last-week data (it only appeared this week)');
  check($s['cpu_change_pct'] === null, 'sonarr week-over-week change is null, not a bogus number, since there is no baseline');
  check(abs($s['this_week']['cpu_avg'] - 10.0) < 0.5, 'sonarr this-week avg CPU is ~10%, got ' . $s['this_week']['cpu_avg']);
}

cleanup($tmpFlash);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
