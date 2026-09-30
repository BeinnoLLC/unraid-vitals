<?php
/**
 * unraid-vitals — standalone test for v_capacity_forecast() (P15-01).
 * Run: php tests/test-capacity-forecast.php
 *
 * Same isolation pattern as test-docker-image.php: VITALS_STATE/VITALS_FLASH
 * redefined to a throwaway temp dir, flash rollup history seeded directly as
 * .jsonl lines rather than running the real collector.
 */

$tmp = sys_get_temp_dir() . '/vitals-capacity-forecast-test-' . getmypid();
@mkdir($tmp, 0755, true);
define('VITALS_STATE', $tmp . '/state');
define('VITALS_FLASH', $tmp . '/flash');
@mkdir(VITALS_STATE, 0755, true);
@mkdir(VITALS_FLASH, 0755, true);
@mkdir($tmp . '/data', 0755, true);
file_put_contents(VITALS_FLASH . '/vitals.cfg', 'DATA_DIR="' . $tmp . '/data/unraid-vitals"' . "\n");

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/unraid-vitals/include/checks.php';

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

/** Seed one hourly rollup line into flash history for a given day-offset. */
function seedHourLine(int $daysAgo, array $extra): void {
  $t = time() - $daysAgo * 86400;
  $line = array_merge(['h' => (int)floor($t / 3600), 'n' => 60], $extra);
  $month = date('Y-m', $t);
  $fh = fopen(VITALS_FLASH . '/history/' . $month . '.jsonl', 'a');
  fwrite($fh, json_encode($line, JSON_UNESCAPED_SLASHES) . "\n");
  fclose($fh);
}

@mkdir(VITALS_FLASH . '/history', 0755, true);

// ---------------------------------------------------------------- test 1 ---
// Acceptance criterion from ticket #66: synthetic data growing 10 GB/day on
// a disk with 100 GB free reports "about 10 days". 10 daily points, disk1
// growing exactly 10 GB/day, starting at some baseline.
$gb = 1073741824;
for ($d = 9; $d >= 0; $d--) {
  $usedGb = 500 - $d * 10;   // day 9 (oldest) = 410GB used ... day 0 (today) = 500GB used
  seedHourLine($d, ['fs_bytes' => ['disk1' => (int)($usedGb * $gb)]]);
}
$daily = v_daily(30);
$forecast = v_capacity_forecast($daily, ['disk1' => [600 * $gb, 100 * $gb]]);   // 600GB total, 100GB free now
$disk1 = null;
foreach ($forecast as $f) if ($f['name'] === 'disk1') $disk1 = $f;
check($disk1 !== null, 'disk1 produces a forecast entry');
if ($disk1 !== null) {
  check(abs($disk1['gb_per_day'] - 10.0) < 0.5, 'fitted growth rate is about 10 GB/day, got ' . $disk1['gb_per_day']);
  check(abs($disk1['days_left'] - 10.0) < 1.0, 'days_left is about 10 days, got ' . $disk1['days_left']);
  check($disk1['within_14d'] === true, 'a 10-day forecast is flagged within_14d');
}

// ---------------------------------------------------------------- test 2 ---
// Flat/noisy trend — no forecast should be produced for a disk that isn't
// meaningfully growing (ticket: "show nothing when the fit is poor or flat").
for ($d = 9; $d >= 0; $d--) {
  $noise = ($d % 2 === 0) ? 0.1 : -0.1;
  seedHourLine($d, ['fs_bytes' => ['disk2' => (int)((200 + $noise) * $gb)]]);
}
$daily = v_daily(30);
$forecast = v_capacity_forecast($daily, ['disk1' => [600 * $gb, 100 * $gb], 'disk2' => [400 * $gb, 200 * $gb]]);
$disk2 = null;
foreach ($forecast as $f) if ($f['name'] === 'disk2') $disk2 = $f;
check($disk2 === null, 'a flat/noisy disk produces no forecast entry');

// ---------------------------------------------------------------- test 3 ---
// The checks engine wires this into a real finding when within 14 days.
$snap = [
  'time' => time(),
  'array' => ['data' => [
    ['name' => 'disk1', 'fsSize' => 600 * $gb, 'fsFree' => 100 * $gb, 'fsUsed' => 500 * $gb],
  ], 'cache' => []],
];
$findings = v_checks_run($snap);
$capFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'capacity_forecast'));
check(count($capFindings) === 1, 'capacity_forecast check produces exactly one finding for the 10-day disk1 forecast, got '
  . count($capFindings));
if ($capFindings) {
  check(str_contains($capFindings[0]['title'] ?? '', 'disk1'), 'finding title names disk1');
}

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
