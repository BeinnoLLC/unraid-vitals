<?php
/**
 * unraid-vitals — standalone test for checks/docker_image_full.php (P14-01).
 * Run: php tests/test-docker-image.php
 *
 * Isolated the same way test-checks.php is: VITALS_STATE/VITALS_FLASH
 * redefined to a throwaway temp dir. Docker itself is never shelled out to —
 * v_docker_layers_cached() reads/writes a plain JSON cache file in the state
 * dir, so the test pre-seeds that cache directly instead of needing a real
 * docker daemon.
 */

$tmp = sys_get_temp_dir() . '/vitals-docker-image-test-' . getmypid();
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

function seedLayers(array $layers): void {
  file_put_contents(VITALS_STATE . '/docker_layers.json',
    json_encode(['ts' => time(), 'layers' => $layers], JSON_UNESCAPED_SLASHES));
}

function snapWithImage(float $pct, int $total = 100000000000): array {
  return [
    'time' => time(),
    'docker_image' => ['root' => '/var/lib/docker', 'total' => $total,
      'used' => (int)($total * $pct / 100), 'free' => (int)($total * (1 - $pct / 100)), 'used_pct' => $pct],
  ];
}

// ---------------------------------------------------------------- test 1 ---
// Below 75% and no oversized layer — no finding.
seedLayers([]);
$findings = v_checks_run(snapWithImage(40.0));
$imgFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'docker_image_full'));
check(count($imgFindings) === 0, 'docker.img at 40% with clean layers produces no finding');

// ---------------------------------------------------------------- test 2 ---
// 75-90% -> warning.
$findings = v_checks_run(snapWithImage(80.0));
$imgFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'docker_image_full'
  && ($f['title'] ?? '') === 'docker.img is 80% full'));
check(count($imgFindings) === 1, 'docker.img at 80% produces exactly one warning finding');
check(($imgFindings[0]['severity'] ?? null) === 'warning', '80% finding severity is warning');

// ---------------------------------------------------------------- test 3 ---
// >=90% -> alert.
$findings = v_checks_run(snapWithImage(93.0));
$imgFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'docker_image_full'
  && ($f['title'] ?? '') === 'docker.img is 93% full'));
check(count($imgFindings) === 1, 'docker.img at 93% produces exactly one alert finding');
check(($imgFindings[0]['severity'] ?? null) === 'alert', '93% finding severity is alert');
$evidenceLabels = array_column($imgFindings[0]['evidence'] ?? [], 'label');
check(in_array('used_pct', $evidenceLabels, true), '93% finding evidence includes used_pct');

// ---------------------------------------------------------------- test 4 ---
// Acceptance criterion: a test container writing 2 GB inside its own
// filesystem is named in the finding.
seedLayers(['my-test-writer' => 2147483648, 'well-behaved' => 1048576]);
$findings = v_checks_run(snapWithImage(10.0));
$layerFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'docker_image_full'
  && $f['fix_id'] === 'docker_layer_large'));
check(count($layerFindings) === 1, 'exactly one finding for the one container over the 1GB layer threshold');
check(str_contains($layerFindings[0]['title'] ?? '', 'my-test-writer'),
  'the 2GB test container is named in the finding title, got: ' . ($layerFindings[0]['title'] ?? ''));
check(($layerFindings[0]['severity'] ?? null) === 'alert', '2GB layer escalates to alert (>= 2GiB threshold)');
$layerEvidence = array_column($layerFindings[0]['evidence'] ?? [], 'label');
check(in_array('container', $layerEvidence, true), 'layer finding evidence includes the container label');

// well-behaved (1MB) must not produce its own finding.
check(!array_filter($findings, fn($f) => $f['check_id'] === 'docker_image_full'
  && str_contains($f['title'] ?? '', 'well-behaved')), 'the 1MB container produces no finding');

// ---------------------------------------------------------------- test 5 ---
// Growth-rate finding, using flash rollup history (v_daily()).
seedLayers([]);
$historyDir = VITALS_FLASH . '/history';
@mkdir($historyDir, 0755, true);
$now = time();
$threeDaysAgo = $now - 3 * 86400;
$lines = [
  ['h' => (int)floor($threeDaysAgo / 3600), 'n' => 60, 'docker_img_pct' => 10.0],
  ['h' => (int)floor(($now - 1) / 3600), 'n' => 60, 'docker_img_pct' => 40.0],
];
$month = date('Y-m', $threeDaysAgo);
$fh = fopen($historyDir . '/' . $month . '.jsonl', 'a');
foreach ($lines as $l) fwrite($fh, json_encode($l, JSON_UNESCAPED_SLASHES) . "\n");
// second line may land in a different Y-m bucket if the test runs near a
// month boundary — write it to its own file too so v_daily() finds it either way.
fclose($fh);
$month2 = date('Y-m', $now - 1);
if ($month2 !== $month) {
  $fh2 = fopen($historyDir . '/' . $month2 . '.jsonl', 'a');
  fwrite($fh2, json_encode($lines[1], JSON_UNESCAPED_SLASHES) . "\n");
  fclose($fh2);
}
$findings = v_checks_run(snapWithImage(40.0, 100000000000));
$growthFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'docker_image_full'
  && str_contains($f['title'] ?? '', 'growing')));
check(count($growthFindings) === 1, 'a 30-point pct growth over 3 days at 100GB total produces a growth finding, got '
  . count($growthFindings) . ' (findings: ' . json_encode(array_column($findings, 'title')) . ')');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
