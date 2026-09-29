<?php
/**
 * unraid-vitals — standalone test for checks/vm_storage.php (P14-14).
 * Run: php tests/test-vm-storage.php
 */

$tmp = sys_get_temp_dir() . '/vitals-vm-storage-test-' . getmypid();
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

function vdisk(string $vm, int $virtual, int $actual, string $pool = '/mnt/virtualmachine'): array {
  return ['file' => $pool . '/domains/' . $vm . '/vdisk1.img', 'vm' => $vm, 'format' => 'raw',
    'virtual_size' => $virtual, 'actual_size' => $actual, 'pool' => $pool];
}

function snap(array $o = []): array {
  return ['time' => time(), 'vm_storage' => array_merge([
    'vdisks' => [], 'total_virtual' => 0, 'total_actual' => 0,
    'pools' => [], 'libvirt_img' => null, 'overcommit' => null,
  ], $o)];
}

function sub(array $findings, string $subject): array {
  return array_values(array_filter($findings, fn($f) => $f['check_id'] === 'vm_storage' && $f['subject'] === $subject));
}

// ---------------------------------------------------------------- test 1 ---
// Acceptance criterion, verbatim: overcommitted vdisks are reported with
// the shortfall. Pool of 100GB total hosting 150GB of allocated vdisks.
$findings = v_checks_run(snap([
  'vdisks' => [vdisk('A', 80e9, 40e9), vdisk('B', 70e9, 35e9)],
  'pools' => ['/mnt/virtualmachine' => ['total' => 100e9, 'free' => 10e9]],
  'overcommit' => ['/mnt/virtualmachine' => ['virtual' => 150e9, 'pool_total' => 100e9, 'shortfall' => 50e9]],
]));
$oc = sub($findings, 'vm_overcommit');
check(count($oc) === 1, 'an overcommitted pool produces exactly one finding');
check(($oc[0]['severity'] ?? null) === 'alert', 'overcommit severity is alert');
check(str_contains($oc[0]['title'] ?? '', 'overcommitted'), 'the title says overcommitted, got: ' . ($oc[0]['title'] ?? ''));
// The shortfall must actually be present and named in the finding.
$shortfall = null;
foreach ($oc[0]['evidence'] as $e) if ($e['label'] === 'shortfall_bytes') $shortfall = $e['value'];
check($shortfall === 50e9, 'the finding carries the exact shortfall (50 GB), got: ' . var_export($shortfall, true));
check(str_contains($oc[0]['detail'] ?? '', 'shortfall'), 'the detail states the shortfall');

// A pool that is NOT overcommitted (allocated <= total) produces nothing.
$findings = v_checks_run(snap([
  'vdisks' => [vdisk('A', 40e9, 20e9)],
  'pools' => ['/mnt/virtualmachine' => ['total' => 100e9, 'free' => 60e9]],
  'overcommit' => null,
]));
check(count(sub($findings, 'vm_overcommit')) === 0, 'a non-overcommitted pool produces no overcommit finding');

// ---------------------------------------------------------------- test 2 ---
// Per-vdisk slack: only reported when the gap is >=25% of the allocation.
$findings = v_checks_run(snap(['vdisks' => [vdisk('Big', 100e9, 20e9)]]));
$sk = sub($findings, 'vm_vdisk_slack');
check(count($sk) === 1, 'a vdisk with an 80% slack produces one informational finding');
check(($sk[0]['severity'] ?? null) === 'info', 'vdisk slack is informational (not a fault), severity info');
check(str_contains($sk[0]['title'] ?? '', 'Big'), 'the slack finding names the VM');
check(str_contains($sk[0]['title'] ?? '', 'on disk of'), 'the slack finding states BOTH the on-disk and allocated sizes');

// A nearly-full vdisk (tiny gap) is not worth reporting.
$findings = v_checks_run(snap(['vdisks' => [vdisk('Tight', 100e9, 98e9)]]));
check(count(sub($findings, 'vm_vdisk_slack')) === 0, 'a vdisk with only 2% slack produces no finding');

// Real Selene numbers: 59055800320 allocated, 54945746944 actual -> 7%
// slack, which must NOT be flagged (the ticket wants signal, not noise).
$findings = v_checks_run(snap(['vdisks' => [vdisk('Hazem', 59055800320, 54945746944)]]));
check(count(sub($findings, 'vm_vdisk_slack')) === 0, 'Selene\'s real 7%-slack vdisk is NOT flagged (no noise)');

// ---------------------------------------------------------------- test 3 ---
// libvirt.img usage: 50% none, 80% warning, 95% alert.
foreach ([[50.0, null], [80.0, 'warning'], [95.0, 'alert']] as [$pct, $expected]) {
  $findings = v_checks_run(snap(['libvirt_img' => [
    'file' => '/mnt/cache/system/libvirt.img', 'image_size' => 1073741824,
    'mount_total' => 1073741824.0, 'mount_free' => 1073741824.0 * (100 - $pct) / 100, 'used_pct' => $pct,
  ]]));
  $li = sub($findings, 'vm_libvirt_img');
  if ($expected === null) check(count($li) === 0, "libvirt.img at {$pct}% produces no finding");
  else check(count($li) === 1 && $li[0]['severity'] === $expected, "libvirt.img at {$pct}% produces a {$expected} finding");
}

// No libvirt.img present at all -> nothing, no crash (Selene has none).
$findings = v_checks_run(snap(['libvirt_img' => null]));
check(count(sub($findings, 'vm_libvirt_img')) === 0, 'a missing libvirt.img produces no finding and does not crash');

// ---------------------------------------------------------------- test 4 ---
// A host with no VM storage data at all (no qemu-img) must not crash.
$findings = v_checks_run(['time' => time()]);
check(count(sub($findings, 'vm_overcommit')) === 0, 'a snapshot missing vm_storage entirely produces nothing and does not crash');

// A completely clean VM host (Selene's real shape: vdisks present, no
// overcommit, no libvirt.img) produces no findings at all.
$findings = v_checks_run(snap([
  'vdisks' => [vdisk('Hazem', 59055800320, 54945746944), vdisk('Ahwa', 21474836480, 20000000000)],
  'pools' => ['/mnt/virtualmachine' => ['total' => 2.86e12, 'free' => 7.88e11]],
  'overcommit' => null, 'libvirt_img' => null,
]));
check(count(sub($findings, 'vm_overcommit')) === 0 && count(sub($findings, 'vm_vdisk_slack')) === 0
  && count(sub($findings, 'vm_libvirt_img')) === 0,
  'a fully clean VM host (Selene\'s real state) produces no vm_storage findings');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
