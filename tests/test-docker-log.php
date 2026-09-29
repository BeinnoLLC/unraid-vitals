<?php
/**
 * unraid-vitals — standalone test for checks/docker_log_large.php (P14-02).
 * Run: php tests/test-docker-log.php
 *
 * Same isolation pattern as test-docker-image.php. v_docker_logs_cached()
 * reads/writes a plain JSON cache in the state dir, so the test pre-seeds
 * that cache directly instead of needing a real docker daemon or log files.
 */

$tmp = sys_get_temp_dir() . '/vitals-docker-log-test-' . getmypid();
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

function seedLogCache(?int $ts, array $logs, ?int $prevTs = null, array $prevLogs = []): array {
  $cache = ['ts' => $ts, 'logs' => $logs, 'prev_ts' => $prevTs, 'prev_logs' => $prevLogs];
  file_put_contents(VITALS_STATE . '/docker_logs.json', json_encode($cache, JSON_UNESCAPED_SLASHES));
  return ['time' => time(), 'docker_logs' => $cache];
}

// ---------------------------------------------------------------- test 1 ---
// Small, non-growing log — no finding.
$snap = seedLogCache(time() - 100, ['quiet-container' => ['path' => '/x/quiet.log', 'bytes' => 1048576]]);
$findings = v_checks_run($snap);
$logFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'docker_log_large'));
check(count($logFindings) === 0, 'a 1MB log with no history produces no finding');

// ---------------------------------------------------------------- test 2 ---
// Acceptance criterion: a chatty test container is flagged with its log
// size and growth rate — 600MB now, was 50MB a day ago (>500MB size AND
// >100MB/day growth, both conditions true at once).
$now = time();
$dayAgo = $now - 86400;
$snap = seedLogCache($now, ['chatty-test-container' => ['path' => '/var/lib/docker/containers/abc/abc-json.log', 'bytes' => 629145600]],
  $dayAgo, ['chatty-test-container' => ['path' => '/var/lib/docker/containers/abc/abc-json.log', 'bytes' => 52428800]]);
$findings = v_checks_run($snap);
$logFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'docker_log_large'));
check(count($logFindings) === 1, 'the chatty test container produces exactly one finding');
check(str_contains($logFindings[0]['title'] ?? '', 'chatty-test-container'),
  'finding title names the chatty container, got: ' . ($logFindings[0]['title'] ?? ''));
$evidence = array_column($logFindings[0]['evidence'] ?? [], 'label', 'value');
$evidenceLabels = array_column($logFindings[0]['evidence'] ?? [], 'label');
check(in_array('log_bytes', $evidenceLabels, true), 'finding evidence includes log_bytes (size)');
check(in_array('growth_bytes_per_day', $evidenceLabels, true), 'finding evidence includes growth_bytes_per_day (growth rate)');
check(($logFindings[0]['fix_id'] ?? null) === 'docker_log_truncate', 'finding links the docker_log_truncate fix_id (P16-04)');

// ---------------------------------------------------------------- test 3 ---
// Over size threshold but no history (first-ever sample) — still flags on
// size alone, just without a growth-rate reason.
$snap = seedLogCache($now, ['big-static-log' => ['path' => '/x/big.log', 'bytes' => 600000000]]);
$findings = v_checks_run($snap);
$logFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'docker_log_large'));
check(count($logFindings) === 1, 'a 600MB log with no prior sample still flags on size alone');
check(!str_contains($logFindings[0]['title'] ?? '', 'growing'), 'no growth claim is made without a prior sample to compare against');

// ---------------------------------------------------------------- test 4 ---
// Fast growth but under the size threshold — still flags (growth condition
// alone is sufficient, matching the ticket's "or" wording).
$snap = seedLogCache($now, ['fast-grower' => ['path' => '/x/grower.log', 'bytes' => 200000000]],
  $dayAgo, ['fast-grower' => ['path' => '/x/grower.log', 'bytes' => 10000000]]);
$findings = v_checks_run($snap);
$logFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'docker_log_large'));
check(count($logFindings) === 1, 'fast growth under the size threshold still flags');
check(str_contains($logFindings[0]['title'] ?? '', 'growing'), 'growth reason is in the title when only growth is over threshold');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
