<?php
/**
 * unraid-vitals — standalone test for checks/net_health.php (P14-11).
 * Run: php tests/test-net-health.php
 *
 * v_check_net_health() only reads $snap['net'] and $snap['net_link'] --
 * synthetic entries matching v_net_delta()/v_net_link_info()'s real
 * shape.
 */

$tmp = sys_get_temp_dir() . '/vitals-net-health-test-' . getmypid();
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

function linkEntry(?int $speed, ?int $best, bool $carrier = true, ?int $mtu = 1500, array $members = []): array {
  return ['speed_mbps' => $speed, 'duplex' => 'full', 'mtu' => $mtu, 'carrier' => $carrier,
    'operstate' => $carrier ? 'up' : 'down', 'best_speed_seen' => $best, 'members' => $members];
}

// ---------------------------------------------------------------- test 1 ---
// Acceptance criterion, verbatim: forcing a port to 100 Mb raises the
// finding. Interface previously linked at 1000 Mbps (best_speed_seen),
// now reports 100 Mbps -- the exact scenario of a port forced down.
$findings = v_checks_run(['time' => time(), 'net' => [], 'net_link' => ['eth0' => linkEntry(100, 1000)]]);
$speedFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'net_health' && $f['subject'] === 'net_speed'));
check(count($speedFindings) === 1, 'a gigabit-capable port forced to 100 Mb raises exactly one finding');
check(str_contains($speedFindings[0]['title'] ?? '', '100') && str_contains($speedFindings[0]['title'] ?? '', '1000'),
  'the finding names both the current (100) and previous best (1000) speed, got: ' . ($speedFindings[0]['title'] ?? ''));

// A port that has ALWAYS been 100 Mb (best_speed_seen also 100, e.g. an
// old 100Mb-only switch/NIC) must NOT be flagged -- there's no regression.
$findings = v_checks_run(['time' => time(), 'net' => [], 'net_link' => ['eth1' => linkEntry(100, 100)]]);
$speedFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'net_health' && $f['subject'] === 'net_speed'));
check(count($speedFindings) === 0, 'a genuinely 100Mb-only port (never seen higher) produces no speed finding');

// A port at full negotiated gigabit (matching its own best) -> no finding.
$findings = v_checks_run(['time' => time(), 'net' => [], 'net_link' => ['eth2' => linkEntry(1000, 1000)]]);
$speedFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'net_health' && $f['subject'] === 'net_speed'));
check(count($speedFindings) === 0, 'a port at its own best-seen speed produces no finding');

// No carrier (cable unplugged) must not be flagged as a speed regression.
$findings = v_checks_run(['time' => time(), 'net' => [], 'net_link' => ['eth3' => linkEntry(null, 1000, false)]]);
$speedFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'net_health' && $f['subject'] === 'net_speed'));
check(count($speedFindings) === 0, 'a port with no carrier (unplugged) produces no speed finding');

// ---------------------------------------------------------------- test 2 ---
// Errors/drops/collisions as deltas.
$findings = v_checks_run(['time' => time(), 'net' => ['eth0' => ['rx_errs_delta' => 5, 'tx_errs_delta' => 0, 'rx_drop_delta' => 0, 'tx_drop_delta' => 0, 'tx_colls_delta' => 0]], 'net_link' => []]);
$errFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'net_health' && $f['subject'] === 'net_errors'));
check(count($errFindings) === 1 && str_contains($errFindings[0]['title'], 'eth0'), 'nonzero rx error delta produces exactly one finding naming the interface');

$findings = v_checks_run(['time' => time(), 'net' => ['eth0' => ['rx_errs_delta' => 0, 'tx_errs_delta' => 0, 'rx_drop_delta' => 0, 'tx_drop_delta' => 12, 'tx_colls_delta' => 0]], 'net_link' => []]);
$dropFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'net_health' && $f['subject'] === 'net_drops'));
check(count($dropFindings) === 1, 'nonzero drop delta produces exactly one finding');

$findings = v_checks_run(['time' => time(), 'net' => ['eth0' => ['rx_errs_delta' => 0, 'tx_errs_delta' => 0, 'rx_drop_delta' => 0, 'tx_drop_delta' => 0, 'tx_colls_delta' => 3]], 'net_link' => []]);
$collFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'net_health' && $f['subject'] === 'net_collisions'));
check(count($collFindings) === 1, 'nonzero collision delta produces exactly one finding');

// All-zero deltas (the normal case) -> no findings at all.
$findings = v_checks_run(['time' => time(), 'net' => ['eth0' => ['rx_errs_delta' => 0, 'tx_errs_delta' => 0, 'rx_drop_delta' => 0, 'tx_drop_delta' => 0, 'tx_colls_delta' => 0]], 'net_link' => []]);
$netFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'net_health'));
check(count($netFindings) === 0, 'all-zero error/drop/collision deltas produce no findings');

// ---------------------------------------------------------------- test 3 ---
// MTU mismatch inside a bond: bond0 (MTU 9000) has member eth0 at MTU 1500.
$findings = v_checks_run(['time' => time(), 'net' => [], 'net_link' => [
  'bond0' => linkEntry(1000, 1000, true, 9000, ['eth0']),
  'eth0' => linkEntry(1000, 1000, true, 1500),
]]);
$mtuFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'net_health' && $f['subject'] === 'net_mtu'));
check(count($mtuFindings) === 1 && str_contains($mtuFindings[0]['title'], 'eth0') && str_contains($mtuFindings[0]['title'], 'bond0'),
  'an MTU mismatch between a bond and its member produces exactly one finding naming both');

// Matching MTUs -> no finding.
$findings = v_checks_run(['time' => time(), 'net' => [], 'net_link' => [
  'bond0' => linkEntry(1000, 1000, true, 1500, ['eth0']),
  'eth0' => linkEntry(1000, 1000, true, 1500),
]]);
$mtuFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'net_health' && $f['subject'] === 'net_mtu'));
check(count($mtuFindings) === 0, 'matching bond/member MTUs produce no finding');

// ---------------------------------------------------------------- test 4 ---
// Missing net/net_link keys entirely must not crash.
$findings = v_checks_run(['time' => time()]);
$netFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'net_health'));
check(count($netFindings) === 0, 'a snapshot missing net/net_link entirely produces no findings and does not crash');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
