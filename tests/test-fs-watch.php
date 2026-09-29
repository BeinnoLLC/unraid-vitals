<?php
/**
 * unraid-vitals — standalone test for checks/fs_watch_full.php (P14-03).
 * Run: php tests/test-fs-watch.php
 *
 * Same isolation pattern as the other check tests. This one does NOT touch
 * any real filesystem's fill level (unlike the ticket's own live acceptance
 * test, "filling /var/log to 85% on Selene", which is deliberately not
 * automated here — see the shipping comment for why) — the check only ever
 * reads $snap['fs_watch'], so a synthetic snapshot exercises the same code
 * path with none of the risk of actually filling a production mount.
 */

$tmp = sys_get_temp_dir() . '/vitals-fs-watch-test-' . getmypid();
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

function mountAt(float $pct, array $largest = []): array {
  $total = 1000000000;
  return ['path' => '/x', 'total' => $total, 'free' => (int)($total * (1 - $pct / 100)),
          'used_pct' => $pct, 'largest' => $largest];
}

function snapWith(array $overrides): array {
  return array_merge(['time' => time(), 'fs_watch' => [
    'rootfs' => mountAt(20.0), 'var_log' => mountAt(20.0), 'tmp' => mountAt(20.0), 'run' => mountAt(20.0),
  ]], $overrides);
}

// ---------------------------------------------------------------- test 1 ---
// Everything healthy — no findings.
$findings = v_checks_run(snapWith([]));
$fsFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'fs_watch_full'));
check(count($fsFindings) === 0, 'all mounts at 20% produce no finding');

// ---------------------------------------------------------------- test 2 ---
// Acceptance criterion: /var/log at 85% raises a finding and names the file.
$snap = snapWith(['fs_watch' => [
  'rootfs' => mountAt(20.0), 'tmp' => mountAt(20.0), 'run' => mountAt(20.0),
  'var_log' => mountAt(85.0, [['path' => '/var/log/syslog', 'bytes' => 734003200]]),
]]);
$findings = v_checks_run($snap);
$fsFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'fs_watch_full'));
check(count($fsFindings) === 1, '/var/log at 85% produces exactly one finding');
check(($fsFindings[0]['subject'] ?? null) === 'fs_var_log', 'finding subject identifies /var/log specifically');
check(($fsFindings[0]['severity'] ?? null) === 'warning', '85% is below the 90% alert line — severity warning');
check(str_contains($fsFindings[0]['detail'] ?? '', '/var/log/syslog'),
  'finding detail names the largest file, got: ' . ($fsFindings[0]['detail'] ?? ''));
$evidenceLabels = array_column($fsFindings[0]['evidence'] ?? [], 'label');
check(in_array('largest_files', $evidenceLabels, true), 'finding evidence includes the largest_files list');

// ---------------------------------------------------------------- test 3 ---
// /tmp and /run get the same treatment, independently, at once.
$snap = snapWith(['fs_watch' => [
  'rootfs' => mountAt(20.0),
  'var_log' => mountAt(20.0),
  'tmp' => mountAt(92.0),
  'run' => mountAt(97.0),
]]);
$findings = v_checks_run($snap);
$fsFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'fs_watch_full'));
check(count($fsFindings) === 2, '/tmp and /run both over threshold produce two independent findings');
$bySubject = [];
foreach ($fsFindings as $f) $bySubject[$f['subject']] = $f;
check(($bySubject['fs_tmp']['severity'] ?? null) === 'alert', '/tmp at 92% is alert (>=90%)');
check(($bySubject['fs_run']['severity'] ?? null) === 'critical', '/run at 97% is critical (>=95%)');

// ---------------------------------------------------------------- test 4 ---
// rootfs itself is NOT covered by this check (rootfs_full.php from P13-01
// already owns it) — a full rootfs in $snap['fs_watch']['rootfs'] must not
// also produce a fs_watch_full finding, or rootfs would double-alert.
$snap = snapWith(['rootfs' => ['total' => 1000000000, 'free' => 10000000, 'used_pct' => 99.0],
  'fs_watch' => ['rootfs' => mountAt(99.0), 'var_log' => mountAt(20.0), 'tmp' => mountAt(20.0), 'run' => mountAt(20.0)]]);
$findings = v_checks_run($snap);
$fsFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'fs_watch_full'));
check(count($fsFindings) === 0, 'a full rootfs produces no fs_watch_full finding (rootfs_full.php owns rootfs)');
$rootfsFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'rootfs_full'));
check(count($rootfsFindings) === 1, 'rootfs_full.php still fires for the same condition (no gap in coverage)');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
