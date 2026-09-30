<?php
/**
 * unraid-vitals — standalone test for v_energy_report() (P15-08).
 * Builds a fake hourly history where watts_avg mirrors the UPS's
 * NOMPOWER*LOADPCT/100 computation done in collect.php's v_power(), and
 * checks the ticket's exact acceptance line: "a day's kWh matches the
 * UPS figures within 5%".
 */

$tmpFlash = sys_get_temp_dir() . '/vitals-energy-test-' . getmypid();
@mkdir($tmpFlash . '/history', 0755, true);
define('VITALS_FLASH', $tmpFlash);
define('V_CFG_FILE', $tmpFlash . '/vitals.cfg');

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

// A UPS rated 1000W (NOMPOWER) sitting at a steady 35% load (LOADPCT) all
// day -- exactly v_power()'s own formula: nom * pct / 100 = 350W constant.
// A perfect day at 350W constant draw = 350W * 24h = 8400 Wh = 8.4 kWh --
// that's the "real UPS figure" this test checks the report against.
$nomWatts = 1000.0; $loadPct = 35.0;
$upsWatts = round($nomWatts * $loadPct / 100, 1);   // 350.0, same rounding v_power() does
$expectedKwhFromUps = round($upsWatts * 24 / 1000, 3);   // 8.4

$now = time();
// Anchor to yesterday's UTC midnight so all 24 rows land in ONE calendar
// day regardless of what time this test happens to run -- "now" itself
// straddles two UTC days for any run not exactly at midnight.
$todayMidnightUtc = strtotime(gmdate('Y-m-d 00:00:00', $now));
$dayStart = $todayMidnightUtc - 86400;
$today = gmdate('Y-m-d', $dayStart);
$hourOf = fn($n) => intdiv($dayStart, 3600) + $n;

// 24 full hours of that day, each reporting the UPS-derived watts_avg with
// a tiny bit of realistic sampling jitter (+/-2W) -- still averages to ~350W.
$rows = [];
for ($n = 0; $n < 24; $n++) {
  $jitter = ($n % 2 === 0) ? 2.0 : -2.0;
  $rows[] = ['h' => $hourOf($n), 'watts_avg' => $upsWatts + $jitter, 'watts_includes' => ['ups']];
}

$file = $tmpFlash . '/history/' . date('Y-m', $now) . '.jsonl';
$fh = fopen($file, 'w');
foreach ($rows as $r) fwrite($fh, json_encode($r) . "\n");
fclose($fh);

// Set a price to exercise the cost calc too.
file_put_contents(V_CFG_FILE, "PRICE_PER_KWH=\"0.15\"\n");

$report = v_energy_report();
check($report['price_per_kwh'] === 0.15, 'price_per_kwh read back from config, got ' . var_export($report['price_per_kwh'], true));

$todayEntry = null;
foreach ($report['days'] as $d) if ($d['day'] === $today) $todayEntry = $d;
check($todayEntry !== null, "today's ($today) entry exists in the report");

if ($todayEntry !== null) {
  $diffPct = abs($todayEntry['kwh'] - $expectedKwhFromUps) / $expectedKwhFromUps * 100;
  check($diffPct <= 5.0, "today's reported kWh ({$todayEntry['kwh']}) is within 5% of the UPS-derived figure ({$expectedKwhFromUps}) — off by " . round($diffPct, 2) . '%  [TICKET ACCEPTANCE CRITERION]');
  check($todayEntry['estimated'] === false, 'a full 24-hour day is NOT flagged as a partial/estimated day');
  check(in_array('ups', $todayEntry['includes'], true), 'the day correctly reports its power source as "ups"');
  $expectedCost = round($expectedKwhFromUps * 0.15, 2);
  check(abs($todayEntry['cost'] - $expectedCost) < 0.05, "cost (\${$todayEntry['cost']}) matches kWh * price (\${$expectedCost}) within rounding");
}

check($report['current_watts_avg_24h'] !== null && abs($report['current_watts_avg_24h'] - $upsWatts) < 5,
  'current_watts_avg_24h (' . $report['current_watts_avg_24h'] . ') tracks the steady UPS draw (' . $upsWatts . 'W)');

cleanup($tmpFlash);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
