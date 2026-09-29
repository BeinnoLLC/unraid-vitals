<?php
/**
 * unraid-vitals — standalone test for checks/smart_deep.php (P14-07).
 * Run: php tests/test-smart-deep.php
 *
 * v_check_smart_deep() only reads $snap['smart'] — synthetic entries
 * matching v_smart_tracked()'s real shape (verified separately against
 * Selene's actual SMART/NVMe output during development).
 */

$tmp = sys_get_temp_dir() . '/vitals-smart-deep-test-' . getmypid();
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

function ataDisk(array $overrides = []): array {
  return array_merge([
    'dev' => 'sdb', 'name' => 'disk1', 'health' => null, 'temp' => 36, 'hours' => 38239,
    'reallocated' => 0, 'pending' => 0, 'uncorrectable' => 0, 'crc' => 0, 'spin_retry' => 0,
    'nvme_pct_used' => null, 'nvme_spare_pct' => null, 'nvme_spare_threshold' => null,
    'nvme_media_errors' => null, 'nvme_critical_warning' => null, 'ssd_wear_pct' => null,
    'growth_30d' => ['days' => 30, 'reallocated' => 0, 'pending' => 0, 'crc' => 0, 'uncorrectable' => 0],
  ], $overrides);
}

function nvmeDisk(array $overrides = []): array {
  return array_merge([
    'dev' => 'nvme0n1', 'name' => 'virtualmachine', 'health' => null, 'temp' => 35, 'hours' => 29435,
    'reallocated' => null, 'pending' => null, 'uncorrectable' => null, 'crc' => null, 'spin_retry' => null,
    'nvme_pct_used' => 31, 'nvme_spare_pct' => 100, 'nvme_spare_threshold' => 32,
    'nvme_media_errors' => 0, 'nvme_critical_warning' => 0, 'ssd_wear_pct' => null,
    'growth_30d' => ['days' => null, 'reallocated' => null, 'pending' => null, 'crc' => null, 'uncorrectable' => null],
  ], $overrides);
}

// ---------------------------------------------------------------- test 1 ---
// A healthy ATA disk and a healthy NVMe disk (matching Selene's actual
// nvme0n1 at 31% used, well under the 80% wear threshold) -> no findings.
$findings = v_checks_run(['time' => time(), 'smart' => ['sdb' => ataDisk(), 'nvme0n1' => nvmeDisk()]]);
$deepFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'smart_deep'));
check(count($deepFindings) === 0, 'a healthy ATA disk and a healthy NVMe disk produce no findings');

// ---------------------------------------------------------------- test 2 ---
// Acceptance criterion (adjacent, this file's own scope): CRC errors that
// have NOT grown in 30 days must not be flagged by this check (store.php's
// separate growth-based reallocated/pending path handles that exact
// acceptance wording; this check only covers CRC/NVMe/wear).
$findings = v_checks_run(['time' => time(), 'smart' => ['sdb' => ataDisk([
  'crc' => 8, 'growth_30d' => ['days' => 30, 'reallocated' => 0, 'pending' => 0, 'crc' => 0, 'uncorrectable' => 0],
])]]);
$crcFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'smart_deep' && $f['subject'] === 'smart_crc'));
check(count($crcFindings) === 0, 'a standing (non-growing) CRC count of 8 over 30 days produces no finding');

// CRC errors that HAVE grown -> warning.
$findings = v_checks_run(['time' => time(), 'smart' => ['sdb' => ataDisk([
  'crc' => 12, 'growth_30d' => ['days' => 5, 'reallocated' => 0, 'pending' => 0, 'crc' => 4, 'uncorrectable' => 0],
])]]);
$crcFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'smart_deep' && $f['subject'] === 'smart_crc'));
check(count($crcFindings) === 1, 'CRC errors growing by 4 in 5 days produces exactly one finding');
check(($crcFindings[0]['severity'] ?? null) === 'warning', 'growing CRC finding severity is warning');
check(str_contains($crcFindings[0]['detail'] ?? '', 'cable'), 'CRC finding attributes it to cabling, not the disk');

// ---------------------------------------------------------------- test 3 ---
// NVMe critical warning bit set -> critical, regardless of growth history.
$findings = v_checks_run(['time' => time(), 'smart' => ['nvme0n1' => nvmeDisk(['nvme_critical_warning' => 4])]]);
$nvmeFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'smart_deep' && $f['subject'] === 'smart_nvme'
  && str_contains($f['title'] ?? '', 'critical warning')));
check(count($nvmeFindings) === 1, 'a non-zero NVMe critical warning produces exactly one finding');
check(($nvmeFindings[0]['severity'] ?? null) === 'critical', 'critical warning finding severity is critical');

// ---------------------------------------------------------------- test 4 ---
// Available spare at/below its own vendor threshold -> alert.
$findings = v_checks_run(['time' => time(), 'smart' => ['nvme0n1' => nvmeDisk(['nvme_spare_pct' => 30, 'nvme_spare_threshold' => 32])]]);
$spareFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'smart_deep' && str_contains($f['title'] ?? '', 'spare')));
check(count($spareFindings) === 1, 'spare below its own threshold produces exactly one finding');
check(($spareFindings[0]['severity'] ?? null) === 'alert', 'spare-below-threshold severity is alert');

// Spare well above threshold must NOT trigger it.
$findings = v_checks_run(['time' => time(), 'smart' => ['nvme0n1' => nvmeDisk(['nvme_spare_pct' => 100, 'nvme_spare_threshold' => 32])]]);
$spareFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'smart_deep' && str_contains($f['title'] ?? '', 'spare')));
check(count($spareFindings) === 0, 'spare well above threshold produces no finding');

// ---------------------------------------------------------------- test 5 ---
// NVMe media/data integrity errors -> alert.
$findings = v_checks_run(['time' => time(), 'smart' => ['nvme0n1' => nvmeDisk(['nvme_media_errors' => 3])]]);
$mediaFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'smart_deep' && str_contains($f['title'] ?? '', 'integrity')));
check(count($mediaFindings) === 1, 'non-zero media/data integrity errors produces exactly one finding');
check(($mediaFindings[0]['severity'] ?? null) === 'alert', 'media integrity errors severity is alert');

// ---------------------------------------------------------------- test 6 ---
// Wear thresholds: 79% none, 80% warning, 90% alert, 95% critical.
// Real Selene example that motivated this: nvme1n1 was observed at 100%
// used (vendor-reported wear endpoint) -- reproduced synthetically here.
foreach ([[79, null], [80, 'warning'], [90, 'alert'], [95, 'critical'], [100, 'critical']] as [$pct, $expected]) {
  $findings = v_checks_run(['time' => time(), 'smart' => ['nvme1n1' => nvmeDisk(['dev' => 'nvme1n1', 'name' => 'appdata', 'nvme_pct_used' => $pct])]]);
  $wearFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'smart_deep' && $f['subject'] === 'smart_wear'));
  if ($expected === null) {
    check(count($wearFindings) === 0, "wear at {$pct}% produces no finding");
  } else {
    check(count($wearFindings) === 1 && $wearFindings[0]['severity'] === $expected, "wear at {$pct}% produces a {$expected} finding, got: " . json_encode($wearFindings));
  }
}

// SATA SSD wear indicator (ssd_wear_pct) is treated the same as NVMe's
// nvme_pct_used -- same threshold logic, different source field.
$findings = v_checks_run(['time' => time(), 'smart' => ['sdc' => ataDisk(['dev' => 'sdc', 'name' => 'cache', 'ssd_wear_pct' => 92])]]);
$wearFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'smart_deep' && $f['subject'] === 'smart_wear'));
check(count($wearFindings) === 1 && $wearFindings[0]['severity'] === 'alert', 'a SATA SSD wear indicator at 92% produces an alert finding');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
