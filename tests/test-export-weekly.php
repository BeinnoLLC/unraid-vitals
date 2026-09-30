<?php
/**
 * unraid-vitals — standalone test for export CSV/JSON shape + weekly
 * report content (P15-10). Exercises v_rows_to_csv() directly (the part
 * with real formatting logic) and a hand-rolled version of the weekly
 * summary's averaging logic against synthetic hourly rows.
 */

define('VITALS_FLASH', sys_get_temp_dir() . '/vitals-test-' . uniqid());
mkdir(VITALS_FLASH, 0755, true);
define('V_CFG_FILE', VITALS_FLASH . '/vitals.cfg');
require_once __DIR__ . '/../src/usr/local/emhttp/plugins/unraid-vitals/include/store.php';

$failures = 0;
function check(bool $ok, string $label): void {
  global $failures;
  echo ($ok ? 'PASS' : 'FAIL') . ' — ' . $label . "\n";
  if (!$ok) $failures++;
}

// --- v_rows_to_csv(): one row per sample, spreadsheet-safe -----------------
$rows = [
  ['time' => 1000, 'cpu_pct' => 12.5, 'name' => 'plain'],
  ['time' => 1060, 'cpu_pct' => 40.0, 'name' => 'has,comma'],
  ['time' => 1120, 'cpu_pct' => null, 'name' => 'has "quote"'],
];
$csv = v_rows_to_csv($rows);
$lines = array_filter(explode("\n", trim($csv)));
check(count($lines) === 4, 'CSV has 1 header row + 3 data rows for 3 input samples, got ' . count($lines));
check(str_starts_with($lines[0], 'time,cpu_pct,name'), 'CSV header row matches the row keys in order');
check(strpos($lines[2], '"has,comma"') !== false, 'a value containing a comma is properly quoted');
check(strpos($lines[3], '""quote""') !== false, 'a value containing a double-quote is properly escaped');

// Parse it back with PHP's own CSV parser to prove it round-trips (a real
// spreadsheet import would do the same) -- this is the ticket's actual
// acceptance bar ("opens correctly in a spreadsheet").
$fh = fopen('php://temp', 'r+');
fwrite($fh, $csv);
rewind($fh);
$header = fgetcsv($fh);
$parsedRows = [];
while (($r = fgetcsv($fh)) !== false) $parsedRows[] = array_combine($header, $r);
fclose($fh);
check(count($parsedRows) === 3, 'round-tripping the CSV through fgetcsv recovers exactly 3 rows');
check($parsedRows[1]['name'] === 'has,comma', 'the comma-containing value survives a full CSV round-trip intact');
check($parsedRows[2]['cpu_pct'] === '', 'a null value serializes as an empty CSV field, not the literal string "NULL"');

check(v_rows_to_csv([]) === '', 'exporting zero rows returns an empty string rather than a bare header or error');

// --- weekly averaging logic (mirrors v_weekly_health_summary's $avgOf) -----
function testAvgOf(array $rows, string $key) {
  $vals = array_values(array_filter(array_column($rows, $key), fn($v) => $v !== null));
  return $vals ? round(array_sum($vals) / count($vals), 1) : null;
}
$thisWeek = [['cpu_avg' => 20], ['cpu_avg' => 40], ['cpu_avg' => null]];
$lastWeek = [['cpu_avg' => 60], ['cpu_avg' => 80]];
check(testAvgOf($thisWeek, 'cpu_avg') === 30.0, 'week average ignores null rows rather than treating them as 0');
check(testAvgOf($lastWeek, 'cpu_avg') === 70.0, 'a fully-populated week averages correctly');
check(testAvgOf([], 'cpu_avg') === null, 'an empty week reports null, not a fabricated 0');

// cleanup
array_map('unlink', glob(VITALS_FLASH . '/*') ?: []);
@rmdir(VITALS_FLASH);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
