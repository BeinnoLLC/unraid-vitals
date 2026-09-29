<?php
/**
 * unraid-vitals — standalone test for the checks engine (plan 106 P13-01).
 * Run: php tests/test-checks.php
 *
 * Isolated from the real box: VITALS_STATE/VITALS_FLASH redefined to a
 * throwaway temp dir before include, same pattern as test-rollup.php.
 */

$tmp = sys_get_temp_dir() . '/vitals-checks-test-' . getmypid();
@mkdir($tmp, 0755, true);
define('VITALS_STATE', $tmp . '/state');
define('VITALS_FLASH', $tmp . '/flash');
@mkdir(VITALS_STATE, 0755, true);
@mkdir(VITALS_FLASH, 0755, true);
// v_data_dir() (and so v_db_path(), and so the whole check_results table)
// needs its DATA_DIR's *parent* to already exist — point it at our own temp
// tree so the test never touches /mnt/user/appdata.
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

function snapWithRootfs(float $pct): array {
  return [
    'time' => time(),
    'rootfs' => ['total' => 1000000000, 'free' => (int)(1000000000 * (1 - $pct / 100)), 'used_pct' => $pct],
  ];
}

// Acceptance line from #39: a sample check (rootfs above 90%) runs, appears
// with its evidence, and can be disabled from settings.

// ---------------------------------------------------------------- test 1 ---
// Below threshold — no finding at all.
$findings = v_checks_run(snapWithRootfs(50.0));
check(count($findings) === 0, 'rootfs at 50% produces no finding');

// ---------------------------------------------------------------- test 2 ---
// Above threshold — a finding with evidence lands, and persists to check_results.
$findings = v_checks_run(snapWithRootfs(95.0));
$rootfsFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'rootfs_full'));
check(count($rootfsFindings) === 1, 'rootfs at 95% produces exactly one rootfs_full finding');
$f = $rootfsFindings[0] ?? null;
check($f !== null && $f['severity'] === 'alert', 'finding severity is alert (below the 98% critical line)');
check($f !== null && !empty($f['evidence']), 'finding carries evidence');
$evidenceLabels = $f !== null ? array_column($f['evidence'], 'label') : [];
check(in_array('used_pct', $evidenceLabels, true), 'evidence includes used_pct');
check(in_array('threshold_pct', $evidenceLabels, true), 'evidence includes threshold_pct');

$latest = v_checks_latest();
check(count($latest['findings']) === 1, 'persisted check_results has exactly one row after the 95% run');
check($latest['findings'][0]['check_id'] === 'rootfs_full', 'persisted row is rootfs_full');

// ---------------------------------------------------------------- test 3 ---
// Critical escalation above 98%.
$findings = v_checks_run(snapWithRootfs(99.0));
$rootfsFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'rootfs_full'));
check(($rootfsFindings[0]['severity'] ?? null) === 'critical', 'rootfs at 99% escalates to critical');

// ---------------------------------------------------------------- test 4 ---
// Resolved condition disappears from persisted results (delete-then-reinsert).
$findings = v_checks_run(snapWithRootfs(10.0));
check(count($findings) === 0, 'rootfs back at 10% produces no finding');
$latest = v_checks_latest();
check(count($latest['findings']) === 0, 'persisted check_results is empty once the condition clears — no stale row');

// ---------------------------------------------------------------- test 5 ---
// "can be disabled from settings" — CHECK_ROOTFS_FULL_ENABLED=0 in vitals.cfg
// suppresses the check entirely, even when the condition is true.
file_put_contents(VITALS_FLASH . '/vitals.cfg', 'DATA_DIR="' . $tmp . '/data/unraid-vitals"' . "\nCHECK_ROOTFS_FULL_ENABLED=\"0\"\n");
$findings = v_checks_run(snapWithRootfs(99.0));
check(count($findings) === 0, 'CHECK_ROOTFS_FULL_ENABLED=0 suppresses the finding even at 99%');
$cfg = v_checks_config();
check($cfg['rootfs_full']['enabled'] === false, 'v_checks_config() reports rootfs_full as disabled');

// re-enable, confirm it comes back
file_put_contents(VITALS_FLASH . '/vitals.cfg', 'DATA_DIR="' . $tmp . '/data/unraid-vitals"' . "\nCHECK_ROOTFS_FULL_ENABLED=\"1\"\n");
$findings = v_checks_run(snapWithRootfs(99.0));
check(count($findings) === 1, 're-enabling brings the finding back');

// ---------------------------------------------------------------- test 6 ---
// Severity override from vitals.cfg is honoured when the check doesn't set
// its own severity — but rootfs_full always sets severity explicitly
// (alert/critical based on how far over), so the override should NOT apply
// here; this documents that the check's own severity wins by design.
file_put_contents(VITALS_FLASH . '/vitals.cfg', 'DATA_DIR="' . $tmp . '/data/unraid-vitals"' . "\nCHECK_ROOTFS_FULL_ENABLED=\"1\"\nCHECK_ROOTFS_FULL_SEVERITY=\"info\"\n");
$findings = v_checks_run(snapWithRootfs(95.0));
check(($findings[0]['severity'] ?? null) === 'alert', "a check's own severity wins over the config override, got " . ($findings[0]['severity'] ?? 'null'));

// ---------------------------------------------------------------- test 7 ---
// A snapshot with no rootfs data at all (e.g. disk_total_space failed) must
// not crash the run.
$findings = v_checks_run(['time' => time()]);
check(is_array($findings), 'a snapshot missing rootfs entirely does not crash v_checks_run');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
