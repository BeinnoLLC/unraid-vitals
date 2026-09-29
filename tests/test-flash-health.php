<?php
/**
 * unraid-vitals — standalone test for checks/flash_health.php (P14-12),
 * plus v_flash_writes_track()/v_flash_writes_series() (collect.php).
 * Run: php tests/test-flash-health.php
 */

$tmp = sys_get_temp_dir() . '/vitals-flash-health-test-' . getmypid();
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

function flashSnap(array $o = []): array {
  return ['time' => time(), 'flash' => array_merge([
    'mount' => '/boot', 'total' => 4e9, 'free' => 2e9, 'used_pct' => 50.0,
    'read_only' => false, 'mount_opts' => 'rw,noatime,nodiratime',
    'last_backup' => time() - 2 * 86400, 'backup_age_days' => 2.0,
    'our_writes_today' => 48, 'our_writes_per_day' => 48.0,
  ], $o)];
}

// ---------------------------------------------------------------- test 1 ---
// Acceptance criterion, verbatim: /boot remounted read-only raises a
// critical finding.
$findings = v_checks_run(flashSnap(['read_only' => true, 'mount_opts' => 'ro,noatime,nodiratime']));
$roFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'flash_health' && $f['subject'] === 'flash_ro'));
check(count($roFindings) === 1, 'a read-only /boot produces exactly one finding');
check(($roFindings[0]['severity'] ?? null) === 'critical', 'the read-only /boot finding severity is critical');
check(str_contains($roFindings[0]['title'] ?? '', 'read-only'), 'the finding title says read-only, got: ' . ($roFindings[0]['title'] ?? ''));

// A read-write /boot (the normal state) produces no read-only finding.
$findings = v_checks_run(flashSnap());
$roFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'flash_health' && $f['subject'] === 'flash_ro'));
check(count($roFindings) === 0, 'a read-write /boot produces no read-only finding');

// ---------------------------------------------------------------- test 2 ---
// Free space thresholds: 50% none, 85% alert, 95% critical.
foreach ([[50.0, null], [85.0, 'alert'], [94.9, 'alert'], [95.0, 'critical'], [99.0, 'critical']] as [$pct, $expected]) {
  $findings = v_checks_run(flashSnap(['used_pct' => $pct]));
  $sp = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'flash_health' && $f['subject'] === 'flash_space'));
  if ($expected === null) {
    check(count($sp) === 0, "/boot at {$pct}% full produces no space finding");
  } else {
    check(count($sp) === 1 && $sp[0]['severity'] === $expected, "/boot at {$pct}% full produces a {$expected} finding");
  }
}

// ---------------------------------------------------------------- test 3 ---
// Backup age: 2 days none, 31 days warning.
$findings = v_checks_run(flashSnap(['backup_age_days' => 2.0]));
$bk = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'flash_health' && $f['subject'] === 'flash_backup'));
check(count($bk) === 0, 'a 2-day-old flash backup produces no finding');

$findings = v_checks_run(flashSnap(['backup_age_days' => 45.0, 'last_backup' => time() - 45 * 86400]));
$bk = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'flash_health' && $f['subject'] === 'flash_backup'));
check(count($bk) === 1 && str_contains($bk[0]['title'], '45'), 'a 45-day-old flash backup produces a warning naming the age');

// No backup signal at all -> an honest warning, not a silent pass.
$findings = v_checks_run(flashSnap(['last_backup' => null, 'backup_age_days' => null]));
$bk = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'flash_health' && $f['subject'] === 'flash_backup'));
check(count($bk) === 1 && str_contains($bk[0]['title'], 'No flash backup'), 'a missing backup signal produces an honest "no backup detected" warning');

// ---------------------------------------------------------------- test 4 ---
// Write-budget counter: v_flash_writes_track() increments today's bucket
// and v_flash_writes_series() reports it -- the "prove the wear budget"
// requirement, backed by a real counter.
$before = v_flash_writes_series()['today'];
v_flash_writes_track();
v_flash_writes_track();
v_flash_writes_track();
$after = v_flash_writes_series();
check($after['today'] === $before + 3, 'three tracked writes increment today\'s count by exactly 3, got ' . $after['today']);

// The counter must live in the STATE dir (tmpfs), never on the flash drive.
check(!is_file(VITALS_FLASH . '/flash_writes.json'), 'the write counter is not stored on the flash drive itself');

// ---------------------------------------------------------------- test 5 ---
// A completely missing flash key must not crash the checks engine.
$findings = v_checks_run(['time' => time()]);
$fl = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'flash_health'));
check(count($fl) === 0, 'a snapshot missing the flash key entirely produces no findings and does not crash');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
