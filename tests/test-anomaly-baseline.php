<?php
/**
 * unraid-vitals — standalone test for v_anomaly_check() (P15-03).
 * Run: php tests/test-anomaly-baseline.php
 *
 * Same isolation pattern as test-capacity-forecast.php.
 */

$tmp = sys_get_temp_dir() . '/vitals-anomaly-test-' . getmypid();
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

@mkdir(VITALS_FLASH . '/history', 0755, true);

function seedHour(int $h, array $extra): void {
  $line = array_merge(['h' => $h, 'n' => 60], $extra);
  $month = gmdate('Y-m', $h * 3600);
  $fh = fopen(VITALS_FLASH . '/history/' . $month . '.jsonl', 'a');
  fwrite($fh, json_encode($line, JSON_UNESCAPED_SLASHES) . "\n");
  fclose($fh);
}

// Anchor to a Monday 00:00 UTC so hour-of-week math is deterministic
// regardless of what day "now" happens to be. 2024-01-01 was a Monday.
$mondayMidnight = gmmktime(0, 0, 0, 1, 1, 2024);
$startHour = (int)($mondayMidnight / 3600);

// 3 weeks of baseline: idles at cpu=5 every hour (no special conditions) —
// scenario 1 tests a genuine anomaly against a flat baseline.
$totalHours = 3 * 168 + 6;   // 3 weeks baseline + 6 "recent" hours to test
for ($i = 0; $i < $totalHours - 6; $i++) {
  $h = $startHour + $i;
  seedHour($h, ['cpu_avg' => 5.0, 'mem_avg' => 20.0, 'temp_max' => 35.0]);
}

// ------------------------------------------------------------- scenario 1 -
// Recent hours: a spike to 90% CPU for 3 consecutive hours, on a box that
// idles at 5% every hour. This is exactly the ticket's acceptance
// criterion ("CPU at 90% at 03:00 on a box that idles at that hour").
$recentStart = $startHour + $totalHours - 6;
seedHour($recentStart, ['cpu_avg' => 5.0, 'mem_avg' => 20.0, 'temp_max' => 35.0]);
seedHour($recentStart + 1, ['cpu_avg' => 90.0, 'mem_avg' => 20.0, 'temp_max' => 35.0]);
seedHour($recentStart + 2, ['cpu_avg' => 90.0, 'mem_avg' => 20.0, 'temp_max' => 35.0]);
seedHour($recentStart + 3, ['cpu_avg' => 90.0, 'mem_avg' => 20.0, 'temp_max' => 35.0]);
seedHour($recentStart + 4, ['cpu_avg' => 5.0, 'mem_avg' => 20.0, 'temp_max' => 35.0]);
seedHour($recentStart + 5, ['cpu_avg' => 5.0, 'mem_avg' => 20.0, 'temp_max' => 35.0]);

$result = v_anomaly_check(['cpu' => 'cpu_avg'], 6);
check($result['status'] === 'ok', 'status is ok once 2+ weeks of baseline exist, got ' . $result['status']);
$cpuFinding = null;
foreach ($result['findings'] as $f) if ($f['metric'] === 'cpu') $cpuFinding = $f;
check($cpuFinding !== null, 'a 90% CPU spike outside the idle baseline is flagged');

cleanup($tmp);

// ------------------------------------------------------------- scenario 2 -
// Same setup, but this time the "spike" happens during the box's own
// regular 02:00 backup window (which the baseline already knows is busy at
// 80%) — this must NOT be flagged, per the ticket's acceptance criterion.
$tmp2 = sys_get_temp_dir() . '/vitals-anomaly-test2-' . getmypid();
@mkdir($tmp2, 0755, true);
define('VITALS_FLASH2', $tmp2 . '/flash');
// Can't redefine VITALS_FLASH (already defined) — spin up a second process
// instead for full isolation.
$phpBin = PHP_BINARY ?: 'php';
$script = $tmp2 . '/run.php';
file_put_contents($script, '<?php
define("VITALS_STATE", "' . $tmp2 . '/state");
define("VITALS_FLASH", "' . $tmp2 . '/flash");
@mkdir(VITALS_STATE, 0755, true);
@mkdir(VITALS_FLASH, 0755, true);
@mkdir("' . $tmp2 . '/data", 0755, true);
file_put_contents(VITALS_FLASH . "/vitals.cfg", "DATA_DIR=\"" . "' . $tmp2 . '/data/unraid-vitals" . "\"\n");
require_once "' . __DIR__ . '/../src/usr/local/emhttp/plugins/unraid-vitals/include/checks.php";
@mkdir(VITALS_FLASH . "/history", 0755, true);
function seedHour($h, $extra) {
  $line = array_merge(["h" => $h, "n" => 60], $extra);
  $month = gmdate("Y-m", $h * 3600);
  $fh = fopen(VITALS_FLASH . "/history/" . $month . ".jsonl", "a");
  fwrite($fh, json_encode($line) . "\n");
  fclose($fh);
}
$mondayMidnight = gmmktime(0, 0, 0, 1, 1, 2024);
$startHour = (int)($mondayMidnight / 3600);
$totalHours = 3 * 168 + 3;
for ($i = 0; $i < $totalHours - 3; $i++) {
  $h = $startHour + $i;
  $hourOfDay = $h % 24;
  $cpu = ($hourOfDay === 2) ? 80.0 : 5.0;
  seedHour($h, ["cpu_avg" => $cpu]);
}
// Recent: land exactly on 3 consecutive 02:00-hour-of-day slots at the
// backup\'s normal 80% load — same load, same window, should NOT flag.
$recentStart = $startHour + $totalHours - 3;
for ($i = 0; $i < 3; $i++) {
  $h = $recentStart + $i;
  $hourOfDay = $h % 24;
  $cpu = ($hourOfDay === 2) ? 80.0 : 5.0;
  seedHour($h, ["cpu_avg" => $cpu]);
}
$result = v_anomaly_check(["cpu" => "cpu_avg"], 3);
echo json_encode($result), PHP_EOL;
');
$out = shell_exec(escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' 2>&1');
$lines = array_values(array_filter(explode("\n", trim($out))));
$lastLine = end($lines);
$result2 = json_decode($lastLine, true);
check(is_array($result2) && $result2['status'] === 'ok', 'scenario 2: status ok, got ' . var_export($result2['status'] ?? 'PARSE_FAIL', true));
check(is_array($result2) && empty($result2['findings']), 'the same 80% load during its regular nightly backup window does NOT flag, findings=' . json_encode($result2['findings'] ?? 'n/a'));
cleanup($tmp2);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
