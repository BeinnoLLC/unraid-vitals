<?php
/**
 * unraid-vitals — standalone test for checks/pool_health.php (P14-10).
 * Run: php tests/test-pool-health.php
 *
 * v_check_pool_health() only reads $snap['pool_health'] -- synthetic
 * entries matching v_pool_health()'s real shape (verified separately
 * against Selene's actual btrfs/zpool output during development).
 */

$tmp = sys_get_temp_dir() . '/vitals-pool-health-test-' . getmypid();
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

function btrfsPool(string $mount, string $device, array $stats, string $scrubStatus = 'finished'): array {
  return ['mount' => $mount, 'device' => $device, 'scrub_status' => $scrubStatus, 'scrub_date' => null,
    'devices' => [['device' => $device, 'stats' => $stats, 'total_errors' => array_sum($stats)]]];
}

function zfsPool(string $name, string $state = 'ONLINE', array $deviceErrors = [], ?string $scrubDate = null): array {
  return ['pool' => $name, 'state' => $state, 'scrub_date' => $scrubDate, 'scrub_errors' => 0,
    'device_errors' => $deviceErrors, 'arc' => ['size_bytes' => 1000, 'target_bytes' => 1000, 'max_bytes' => 10000, 'pct_of_ram' => 5]];
}

// ---------------------------------------------------------------- test 1 ---
// Acceptance criterion, verbatim: a pool with a non-zero btrfs device
// error counter is reported with the device name.
$clean = ['write_io_errs' => 0, 'read_io_errs' => 0, 'flush_io_errs' => 0, 'corruption_errs' => 0, 'generation_errs' => 0];
$errored = ['write_io_errs' => 3, 'read_io_errs' => 0, 'flush_io_errs' => 0, 'corruption_errs' => 0, 'generation_errs' => 0];
$findings = v_checks_run(['time' => time(), 'pool_health' => ['btrfs' => [
  btrfsPool('/mnt/virtualmachine', '/dev/nvme0n1p1', $errored),
], 'zfs' => []]]);
$btrfsFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'pool_health' && $f['subject'] === 'pool_btrfs'));
check(count($btrfsFindings) === 1, 'a btrfs device with a non-zero error counter produces exactly one finding');
check(str_contains($btrfsFindings[0]['title'] ?? '', '/dev/nvme0n1p1'), 'the finding names the device, got: ' . ($btrfsFindings[0]['title'] ?? ''));

// A clean device (all zero counters, matching Selene's actual pools) produces no finding.
$findings = v_checks_run(['time' => time(), 'pool_health' => ['btrfs' => [
  btrfsPool('/mnt/virtualmachine', '/dev/nvme0n1p1', $clean),
], 'zfs' => []]]);
$btrfsFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'pool_health' && $f['subject'] === 'pool_btrfs'));
check(count($btrfsFindings) === 0, 'an all-zero btrfs device error counter (matching Selene\'s real clean pools) produces no finding');

// ---------------------------------------------------------------- test 2 ---
// A btrfs pool that has never been scrubbed (Selene's real state on two
// of its three pools) produces a warning naming the mount.
$findings = v_checks_run(['time' => time(), 'pool_health' => ['btrfs' => [
  btrfsPool('/var/lib/docker', '/dev/loop2', $clean, 'never_run'),
], 'zfs' => []]]);
$scrubFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'pool_health' && $f['subject'] === 'pool_btrfs_scrub'));
check(count($scrubFindings) === 1 && str_contains($scrubFindings[0]['title'], '/var/lib/docker'), 'a never-scrubbed btrfs pool produces a warning naming the mount');

// A finished scrub (Selene's real "cache" equivalent state) produces no scrub warning.
$findings = v_checks_run(['time' => time(), 'pool_health' => ['btrfs' => [
  btrfsPool('/mnt/virtualmachine', '/dev/nvme0n1p1', $clean, 'finished'),
], 'zfs' => []]]);
$scrubFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'pool_health' && $f['subject'] === 'pool_btrfs_scrub'));
check(count($scrubFindings) === 0, 'a btrfs pool with a finished scrub produces no scrub warning');

// ---------------------------------------------------------------- test 3 ---
// ZFS pool state ONLINE (Selene's real "cache" pool state) -> no finding.
$findings = v_checks_run(['time' => time(), 'pool_health' => ['btrfs' => [], 'zfs' => [zfsPool('cache', 'ONLINE')]]]);
$stateFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'pool_health' && $f['subject'] === 'pool_zfs_state'));
check(count($stateFindings) === 0, 'a ZFS pool in state ONLINE produces no state finding');

// DEGRADED -> critical, naming the pool.
$findings = v_checks_run(['time' => time(), 'pool_health' => ['btrfs' => [], 'zfs' => [zfsPool('cache', 'DEGRADED')]]]);
$stateFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'pool_health' && $f['subject'] === 'pool_zfs_state'));
check(count($stateFindings) === 1 && $stateFindings[0]['severity'] === 'critical' && str_contains($stateFindings[0]['title'], 'cache'), 'a DEGRADED ZFS pool produces a critical finding naming the pool');

// ---------------------------------------------------------------- test 4 ---
// ZFS per-device READ/WRITE/CKSUM errors -> alert naming the device.
$findings = v_checks_run(['time' => time(), 'pool_health' => ['btrfs' => [], 'zfs' => [
  zfsPool('cache', 'ONLINE', [['device' => 'sdb1', 'read' => 0, 'write' => 0, 'cksum' => 2]]),
]]]);
$devFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'pool_health' && $f['subject'] === 'pool_zfs'));
check(count($devFindings) === 1 && str_contains($devFindings[0]['title'], 'sdb1'), 'a ZFS device with nonzero cksum errors produces an alert naming the device');

// ---------------------------------------------------------------- test 5 ---
// ZFS scrub age: Selene's real scrub was "Mon Sep 28 13:31:42 2026" --
// within 30 days of a `time` set to right after that -> no finding.
$recentDate = date('D M j H:i:s Y', strtotime('-2 days'));
$findings = v_checks_run(['time' => time(), 'pool_health' => ['btrfs' => [], 'zfs' => [zfsPool('cache', 'ONLINE', [], $recentDate)]]]);
$scrubFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'pool_health' && $f['subject'] === 'pool_zfs_scrub'));
check(count($scrubFindings) === 0, 'a ZFS scrub 2 days ago produces no scrub-age finding');

$oldDate = date('D M j H:i:s Y', strtotime('-45 days'));
$findings = v_checks_run(['time' => time(), 'pool_health' => ['btrfs' => [], 'zfs' => [zfsPool('cache', 'ONLINE', [], $oldDate)]]]);
$scrubFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'pool_health' && $f['subject'] === 'pool_zfs_scrub'));
check(count($scrubFindings) === 1 && str_contains($scrubFindings[0]['title'], 'cache'), 'a ZFS scrub 45 days ago produces a warning naming the pool');

// No scrub date at all (never scrubbed) -> skipped, not guessed.
$findings = v_checks_run(['time' => time(), 'pool_health' => ['btrfs' => [], 'zfs' => [zfsPool('cache', 'ONLINE', [], null)]]]);
$scrubFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'pool_health' && $f['subject'] === 'pool_zfs_scrub'));
check(count($scrubFindings) === 0, 'no scrub date at all produces no age finding (not guessed)');

// ---------------------------------------------------------------- test 6 ---
// Missing pool_health entirely (e.g. no btrfs/zfs on the box) must not crash.
$findings = v_checks_run(['time' => time()]);
$poolFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'pool_health'));
check(count($poolFindings) === 0, 'a snapshot with no pool_health key at all produces no findings and does not crash');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
