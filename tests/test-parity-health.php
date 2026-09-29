<?php
/**
 * unraid-vitals — standalone test for checks/parity_health.php (P14-06).
 * Run: php tests/test-parity-health.php
 *
 * v_check_parity_health() only reads $snap['parity_history'] — no state dir
 * cache to seed, unlike the docker checks. This exercises purely synthetic
 * history arrays matching v_parity_history()'s real shape (verified
 * separately against Selene's actual /boot/config/parity-checks.log during
 * development — see the shipping comment for the concrete example).
 */

$tmp = sys_get_temp_dir() . '/vitals-parity-test-' . getmypid();
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

function entry(int $daysAgo, ?float $speed, int $status = 0, int $errors = 0, string $type = 'Scheduled Correcting Parity-Check'): array {
  return [
    'date' => time() - $daysAgo * 86400, 'elapsed_sec' => 100000, 'speed_mbps' => $speed,
    'status' => $status, 'errors' => $errors, 'cancelled' => $status === -4,
    'clean' => $status === 0 && $errors === 0, 'type' => $type,
  ];
}

// ---------------------------------------------------------------- test 1 ---
// Clean, recent, stable speed — no findings.
$history = [entry(60, 50.0), entry(45, 50.0), entry(30, 50.0), entry(15, 50.0), entry(2, 50.0)];
$findings = v_checks_run(['time' => time(), 'parity_history' => $history]);
$parityFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'parity_health'));
check(count($parityFindings) === 0, 'a clean, recent, stable history produces no finding');

// ---------------------------------------------------------------- test 2 ---
// Last check had errors -> alert.
$history = [entry(60, 50.0), entry(30, 50.0), entry(2, 50.0, 0, 3, 'Manual Correcting Parity-Check')];
$findings = v_checks_run(['time' => time(), 'parity_history' => $history]);
$parityFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'parity_health'
  && str_contains($f['title'] ?? '', 'error')));
check(count($parityFindings) === 1, 'a last check with 3 errors produces exactly one alert finding');
check(($parityFindings[0]['severity'] ?? null) === 'alert', 'errors-found finding severity is alert');
check(str_contains($parityFindings[0]['title'] ?? '', '3'), 'finding title states the error count');

// A cancelled run with a nonzero errors field must not trigger the errors
// finding — cancelled runs did not finish, the errors field is meaningless.
$history = [entry(60, 50.0), entry(2, null, -4, 0, 'Manual Non-Correcting Parity-Check')];
$findings = v_checks_run(['time' => time(), 'parity_history' => $history]);
$errFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'parity_health'
  && str_contains($f['title'] ?? '', 'error')));
check(count($errFindings) === 0, 'a cancelled last run does not trigger the errors-found finding');

// ---------------------------------------------------------------- test 3 ---
// No check in 45 days -> warning.
$history = [entry(200, 50.0), entry(45, 50.0)];
$findings = v_checks_run(['time' => time(), 'parity_history' => $history]);
$staleFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'parity_health'
  && str_contains($f['title'] ?? '', 'No parity check')));
check(count($staleFindings) === 1, 'no check in 45 days produces exactly one stale-check finding');
check(($staleFindings[0]['severity'] ?? null) === 'warning', 'stale-check severity is warning');

// 10 days ago must NOT trigger it (under the 30-day threshold).
$history = [entry(60, 50.0), entry(10, 50.0)];
$findings = v_checks_run(['time' => time(), 'parity_history' => $history]);
$staleFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'parity_health'
  && str_contains($f['title'] ?? '', 'No parity check')));
check(count($staleFindings) === 0, 'a check 10 days ago does not trigger the stale-check finding');

// ---------------------------------------------------------------- test 4 ---
// Acceptance-adjacent: strictly declining speed across the last 5 checks.
$history = [entry(50, 100.0), entry(40, 80.0), entry(30, 60.0), entry(20, 40.0), entry(10, 20.0)];
$findings = v_checks_run(['time' => time(), 'parity_history' => $history]);
$trendFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'parity_health'
  && str_contains($f['title'] ?? '', 'dropped')));
check(count($trendFindings) === 1, '5 strictly-declining speeds produce exactly one trend finding');
$evidenceLabels = array_column($trendFindings[0]['evidence'] ?? [], 'label');
check(in_array('speeds_mbps', $evidenceLabels, true), 'trend finding evidence includes the raw speed series');

// A single uptick anywhere in the last 5 must NOT trigger it.
$history = [entry(50, 100.0), entry(40, 80.0), entry(30, 90.0), entry(20, 40.0), entry(10, 20.0)];
$findings = v_checks_run(['time' => time(), 'parity_history' => $history]);
$trendFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'parity_health'
  && str_contains($f['title'] ?? '', 'dropped')));
check(count($trendFindings) === 0, 'one uptick in the middle of 5 checks does not trigger the trend finding');

// Fewer than 5 speed-bearing checks must NOT trigger it.
$history = [entry(30, 60.0), entry(20, 40.0), entry(10, 20.0)];
$findings = v_checks_run(['time' => time(), 'parity_history' => $history]);
$trendFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'parity_health'
  && str_contains($f['title'] ?? '', 'dropped')));
check(count($trendFindings) === 0, 'fewer than 5 speed-bearing checks does not trigger the trend finding');

// ---------------------------------------------------------------- test 5 ---
// Empty history must not crash.
$findings = v_checks_run(['time' => time(), 'parity_history' => []]);
$parityFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'parity_health'));
check(count($parityFindings) === 0, 'an empty parity history produces no findings and does not crash');

// ---------------------------------------------------------------- test 6 ---
// Unclean shutdown flag -> its own warning, independent of history.
$findings = v_checks_run(['time' => time(), 'system' => ['unclean_shutdown' => true], 'parity_history' => []]);
$uncleanFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'parity_health'
  && str_contains($f['title'] ?? '', 'Unclean shutdown')));
check(count($uncleanFindings) === 1, 'unclean_shutdown=true produces exactly one warning finding');
check(($uncleanFindings[0]['severity'] ?? null) === 'warning', 'unclean shutdown finding severity is warning');

$findings = v_checks_run(['time' => time(), 'system' => ['unclean_shutdown' => false], 'parity_history' => []]);
$uncleanFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'parity_health'
  && str_contains($f['title'] ?? '', 'Unclean shutdown')));
check(count($uncleanFindings) === 0, 'unclean_shutdown=false produces no finding');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
