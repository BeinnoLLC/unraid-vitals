<?php
/**
 * unraid-vitals — standalone test for checks/share_placement.php (P14-05).
 * Run: php tests/test-share-placement.php
 *
 * Same isolation pattern as the other check tests: v_share_placement_cached()
 * reads/writes a plain JSON cache in the state dir, so the test seeds that
 * cache directly instead of needing real disk mount paths.
 */

$tmp = sys_get_temp_dir() . '/vitals-share-placement-test-' . getmypid();
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

function seedPlacement(array $conflicts): array {
  file_put_contents(VITALS_STATE . '/share_placement.json',
    json_encode(['ts' => time(), 'conflicts' => $conflicts], JSON_UNESCAPED_SLASHES));
  return ['time' => time(), 'share_placement' => $conflicts];
}

// ---------------------------------------------------------------- test 1 ---
// No conflicts — no findings.
$snap = seedPlacement([]);
$findings = v_checks_run($snap);
$placementFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'share_placement'));
check(count($placementFindings) === 0, 'no conflicts produces no finding');

// ---------------------------------------------------------------- test 2 ---
// Acceptance criterion: appdata with one folder on disk1 is reported with
// the exact path.
$snap = seedPlacement([
  'appdata' => ['pool' => 'only', 'stray' => [
    ['disk' => 'disk1', 'path' => '/mnt/disk1/appdata', 'expected' => 'cache'],
  ]],
]);
$findings = v_checks_run($snap);
$placementFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'share_placement'));
check(count($placementFindings) === 1, 'appdata with one stray folder on disk1 produces exactly one finding');
check(str_contains($placementFindings[0]['detail'] ?? '', '/mnt/disk1/appdata'),
  'finding detail names the exact stray path, got: ' . ($placementFindings[0]['detail'] ?? ''));
check(str_contains($placementFindings[0]['title'] ?? '', 'appdata'), 'finding title names the share');
$evidence = [];
foreach ($placementFindings[0]['evidence'] ?? [] as $e) $evidence[$e['label']] = $e['value'];
check(($evidence['share'] ?? null) === 'appdata', 'evidence share label is appdata');
check(($evidence['stray_path'] ?? null) === '/mnt/disk1/appdata', 'evidence stray_path is the exact path');
check(($evidence['stray_disk'] ?? null) === 'disk1', 'evidence stray_disk is disk1');

// ---------------------------------------------------------------- test 3 ---
// Multiple stray disks for one share -> one finding per disk (each names
// its own exact path).
$snap = seedPlacement([
  'appdata' => ['pool' => 'only', 'stray' => [
    ['disk' => 'disk1', 'path' => '/mnt/disk1/appdata', 'expected' => 'cache'],
    ['disk' => 'disk3', 'path' => '/mnt/disk3/appdata', 'expected' => 'cache'],
  ]],
]);
$findings = v_checks_run($snap);
$placementFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'share_placement'));
check(count($placementFindings) === 2, 'a share stray on two disks produces two independent findings');

// ---------------------------------------------------------------- test 4 ---
// Array-only share left on cache — same finding shape, opposite direction.
$snap = seedPlacement([
  'Backups' => ['pool' => 'no', 'stray' => [
    ['disk' => 'cache', 'path' => '/mnt/cache/Backups', 'expected' => 'array'],
  ]],
]);
$findings = v_checks_run($snap);
$placementFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'share_placement'));
check(count($placementFindings) === 1, 'an array-only share stray on cache produces one finding');
check(str_contains($placementFindings[0]['title'] ?? '', 'array-only'), 'title labels the share as array-only, got: ' . ($placementFindings[0]['title'] ?? ''));

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
