<?php
/**
 * unraid-vitals — standalone test for v_spin_track()/v_spin_analysis()
 * (collect.php) and checks/spin_never_down.php (P14-08).
 * Run: php tests/test-spin-down.php
 */

$tmp = sys_get_temp_dir() . '/vitals-spin-test-' . getmypid();
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

function seedSpinHistory(array $series): void {
  file_put_contents(VITALS_STATE . '/spin_history.json', json_encode($series));
}

// ---------------------------------------------------------------- test 1 ---
// Acceptance criterion, verbatim: a disk kept awake by a container
// scanning it every 10 minutes over 24h is reported with the interval.
// Build 1440 one-minute samples (24h), spundown=0 throughout, with a
// read-sector bump every 10th sample.
$base = time() - 1440 * 60;
$series = [];
$sectors = 1000;
for ($i = 0; $i < 1440; $i++) {
  if ($i % 10 === 0) $sectors += 200; // "container scan" burst
  $series[] = ['t' => $base + $i * 60, 'spundown' => 0, 'r' => $sectors, 'w' => 5000];
}
seedSpinHistory(['disk3' => $series]);
$findings = v_checks_run(['time' => $base + 1440 * 60, 'spin_analysis' => v_spin_analysis()]);
$spinFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'spin_never_down'));
check(count($spinFindings) === 1, 'a disk continuously up for 24h with 10-min polling produces exactly one finding');
if ($spinFindings) {
  $gap = null;
  foreach ($spinFindings[0]['evidence'] as $e) if ($e['label'] === 'median_activity_gap_min') $gap = $e['value'];
  check($gap !== null && abs($gap - 10.0) < 0.5, 'reported interval is ~10 minutes, got: ' . $gap);
  check(str_contains($spinFindings[0]['detail'] ?? '', '10'), 'finding detail names the ~10-minute interval');
}

// ---------------------------------------------------------------- test 2 ---
// A disk that DOES spin down during the window must not be flagged.
$series = [];
$sectors = 1000;
for ($i = 0; $i < 1440; $i++) {
  $spundown = ($i > 700 && $i < 800) ? 1 : 0; // spent 100 minutes spun down
  if ($spundown === 0 && $i % 10 === 0) $sectors += 200;
  $series[] = ['t' => $base + $i * 60, 'spundown' => $spundown, 'r' => $sectors, 'w' => 5000];
}
seedSpinHistory(['disk4' => $series]);
$findings = v_checks_run(['time' => $base + 1440 * 60, 'spin_analysis' => v_spin_analysis()]);
$spinFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'spin_never_down'));
check(count($spinFindings) === 0, 'a disk that spun down for 100 of 1440 minutes produces no finding');

// ---------------------------------------------------------------- test 3 ---
// Short history (well under 24h) must not falsely claim continuously_up.
$series = [];
for ($i = 0; $i < 30; $i++) {
  $series[] = ['t' => $base + $i * 60, 'spundown' => 0, 'r' => 1000, 'w' => 5000];
}
seedSpinHistory(['disk5' => $series]);
$findings = v_checks_run(['time' => $base + 30 * 60, 'spin_analysis' => v_spin_analysis()]);
$spinFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'spin_never_down'));
check(count($spinFindings) === 0, 'only 30 minutes of history (not a full 24h window) produces no finding yet');

// ---------------------------------------------------------------- test 4 ---
// Empty spin_analysis (no history at all, e.g. first run) must not crash.
$findings = v_checks_run(['time' => time(), 'spin_analysis' => []]);
$spinFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'spin_never_down'));
check(count($spinFindings) === 0, 'empty spin_analysis produces no findings and does not crash');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
