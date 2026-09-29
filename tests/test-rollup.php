<?php
/**
 * unraid-vitals — standalone test for v_rollup()/v_daily() (plan 106 P13-02).
 *
 * No PHP test framework in this repo; run directly with `php tests/test-rollup.php`.
 * Exits 0 and prints "PASS" lines on success, exits 1 and prints "FAIL" on
 * any assertion failure — same contract a CI step can gate on.
 *
 * Isolated from the real box: VITALS_STATE and VITALS_FLASH are redefined to
 * a throwaway temp directory before include, so this never touches
 * /var/tmp/unraid-vitals or /boot/config/plugins/unraid-vitals.
 */

$tmp = sys_get_temp_dir() . '/vitals-rollup-test-' . getmypid();
@mkdir($tmp, 0755, true);
define('VITALS_STATE', $tmp . '/state');
define('VITALS_FLASH', $tmp . '/flash');
@mkdir(VITALS_STATE, 0755, true);
@mkdir(VITALS_FLASH, 0755, true);

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/unraid-vitals/include/store.php';

$failures = 0;
function check(bool $ok, string $label): void {
  global $failures;
  echo ($ok ? 'PASS' : 'FAIL') . ' — ' . $label . "\n";
  if (!$ok) $failures++;
}

function cleanup(string $dir): void {
  foreach (glob($dir . '/*') ?: [] as $f) is_dir($f) ? cleanup($f) : @unlink($f);
  @rmdir($dir);
}

function snapAt(int $t, ?float $cpu, ?float $mem = 10.0): array {
  return [
    'time' => $t,
    'cpu' => ['total' => $cpu],
    'mem' => ['pct' => $mem],
    'load' => ['l1' => 0.5],
    'array' => ['data' => [], 'parity' => [], 'cache' => [], 'totals' => ['fs_used' => 0]],
    'sensors' => ['temps' => [], 'fans' => []],
    'net' => [],
    'gpu' => [],
    'docker' => ['containers' => [], 'running' => 0],
    'smart' => [],
  ];
}

// ---------------------------------------------------------------- test 1 ---
// Acceptance line from #40: a synthetic hour with one 100% CPU sample at :30
// and idle (0%) elsewhere must produce cpu_max=100 and a low cpu_avg — not
// the single-snapshot-at-rollover behaviour this ticket removes.
$hourStart = 1000 * 3600; // an arbitrary clean hour boundary
$ring = [];
// Five idle points across the hour, one busy point at :30.
foreach ([0, 10, 20, 30, 40, 50] as $min) {
  $t = $hourStart + $min * 60;
  $cpu = $min === 30 ? 100.0 : 0.0;
  $ring[] = v_point(snapAt($t, $cpu));
}

// v_rollup() is called with the *next* hour's snapshot (rollover moment) and
// the ring that now contains the just-closed hour's points, matching how
// v_tick() actually calls it.
$rolloverSnap = snapAt($hourStart + 3600, 5.0);
v_rollup($ring, $rolloverSnap);

$flashDir = VITALS_FLASH . '/history';
$files = glob($flashDir . '/*.jsonl') ?: [];
check(count($files) === 1, 'rollup wrote exactly one flash file');

$lines = $files ? array_filter(explode("\n", (string)file_get_contents($files[0]))) : [];
check(count($lines) === 1, 'rollup wrote exactly one line for the closed hour');

$row = $lines ? json_decode(reset($lines), true) : null;
check(is_array($row), 'rollup line parses as JSON');
check(($row['h'] ?? null) === 1000, "rolled-up row labels the closed hour (1000), got " . ($row['h'] ?? 'null'));
check(($row['n'] ?? null) === 6, 'rollup counted all 6 ring points in the hour, not 1');
check(($row['cpu_max'] ?? null) == 100.0, 'cpu_max is 100 (the one busy sample), got ' . ($row['cpu_max'] ?? 'null'));
check(($row['cpu_avg'] ?? 999) < 20.0, 'cpu_avg is low (5 idle + 1 busy out of 6), got ' . ($row['cpu_avg'] ?? 'null'));
check(abs(($row['cpu_avg'] ?? 0) - 16.7) < 0.5, "cpu_avg is the real mean ~16.7, got " . ($row['cpu_avg'] ?? 'null'));

// ---------------------------------------------------------------- test 2 ---
// Network: bytes transferred over the hour, not a sum of instantaneous rates
// (the old v_daily() bug this ticket also fixes). Two points, constant
// 1000 B/s rate, 600s apart -> 600,000 bytes, not 1000+1000=2000.
$netRing = [];
$netRing[] = ['t' => $hourStart, 'net_rx' => 1000.0, 'net_tx' => 0.0];
$netRing[] = ['t' => $hourStart + 600, 'net_rx' => 1000.0, 'net_tx' => 0.0];
$bytes = v_hour_bytes($netRing, 'net_rx');
check(abs($bytes - 600000.0) < 1.0, "v_hour_bytes integrates rate x time (600000), got $bytes");

// ---------------------------------------------------------------- test 3 ---
// v_agg on an empty/all-null set returns all-null rather than dividing by zero.
$empty = v_agg([null, null]);
check($empty['avg'] === null && $empty['max'] === null, 'v_agg on an all-null set returns all-null, no division by zero');

// ---------------------------------------------------------------- test 4 ---
// v_daily() flags a day made entirely of legacy (pre-this-ticket) single-
// sample rows, and does NOT flag a day with real-aggregate rows.
cleanup($flashDir);
@mkdir($flashDir, 0755, true);
$legacyDay = 1001; // hour index -> some day in 1970s epoch math, just needs to be distinct
$legacyLine = json_encode(['h' => $legacyDay, 'cpu_avg' => 42.0, 'mem_avg' => 10.0, 'temp_max' => 30, 'net_rx' => 0, 'net_tx' => 0, 'gpu' => null, 'fill_max' => null, 'smart' => []]);
file_put_contents($flashDir . '/' . date('Y-m', $legacyDay * 3600) . '.jsonl', $legacyLine . "\n");
$daily = v_daily(400);
$legacyEntry = null;
foreach ($daily as $d) if ($d['day'] === date('Y-m-d', $legacyDay * 3600)) $legacyEntry = $d;
check($legacyEntry !== null && $legacyEntry['single_sample'] === true, 'a day built only from legacy (no "n") rows is flagged single_sample');

cleanup($flashDir);
@mkdir($flashDir, 0755, true);
@unlink(VITALS_STATE . '/last_rollup_hour');
v_rollup($ring, $rolloverSnap);
$daily2 = v_daily(400);
$newEntry = null;
foreach ($daily2 as $d) if ($d['day'] === date('Y-m-d', $hourStart)) $newEntry = $d;
check($newEntry !== null && $newEntry['single_sample'] === false, 'a day built from real-aggregate rows is not flagged single_sample');
check(($newEntry['cpu_max'] ?? null) == 100.0, 'v_daily surfaces the real cpu_max (100), got ' . ($newEntry['cpu_max'] ?? 'null'));

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
