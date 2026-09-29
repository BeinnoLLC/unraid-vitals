<?php
/**
 * unraid-vitals — standalone test for checks/docker_hygiene.php (P14-13).
 * Run: php tests/test-docker-hygiene.php
 */

$tmp = sys_get_temp_dir() . '/vitals-docker-hygiene-test-' . getmypid();
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

function ctr(string $name, array $o = []): array {
  return array_merge([
    'name' => $name, 'restart_count' => 0, 'exit_code' => 0, 'state' => 'running',
    'mem_limit_bytes' => 0, 'mem_limit_set' => false, 'ports' => [], 'mounts' => [],
    'has_mnt_user_path' => false, 'sqlite_paths' => [],
  ], $o);
}

function hyg(array $containers, array $conflicts = [], ?float $memPct = 40.0): array {
  return ['time' => time(), 'docker_hygiene' => [
    'containers' => $containers, 'port_conflicts' => $conflicts, 'mem_pct' => $memPct,
  ]];
}

function findingsOf(array $findings, string $subject): array {
  return array_values(array_filter($findings, fn($f) => $f['check_id'] === 'docker_hygiene' && $f['subject'] === $subject));
}

// ---------------------------------------------------------------- test 1 ---
// Acceptance criterion, verbatim: a container in a crash loop is reported
// with its restart count and last exit code.
$findings = v_checks_run(hyg(['app' => ctr('app', ['restart_count' => 12, 'exit_code' => 137, 'state' => 'restarting'])]));
$rs = findingsOf($findings, 'docker_restart');
check(count($rs) === 1, 'a crash-looping container produces exactly one restart finding');
check(str_contains($rs[0]['title'] ?? '', '12'), 'the finding names the restart count (12), got: ' . ($rs[0]['title'] ?? ''));
check(str_contains($rs[0]['title'] ?? '', '137'), 'the finding names the last exit code (137), got: ' . ($rs[0]['title'] ?? ''));
check(($rs[0]['severity'] ?? null) === 'alert', 'a 12-restart loop is alert severity');

// A container with a couple of restarts (below the loop threshold) is not flagged.
$findings = v_checks_run(hyg(['app' => ctr('app', ['restart_count' => 2])]));
check(count(findingsOf($findings, 'docker_restart')) === 0, 'a container with 2 restarts is not flagged as a loop');

// Boundary: exactly 3 restarts IS flagged.
$findings = v_checks_run(hyg(['app' => ctr('app', ['restart_count' => 3, 'exit_code' => 1])]));
check(count(findingsOf($findings, 'docker_restart')) === 1, 'exactly 3 restarts is flagged (threshold boundary)');

// ---------------------------------------------------------------- test 2 ---
// Host port conflict between two containers.
$findings = v_checks_run(hyg(['a' => ctr('a'), 'b' => ctr('b')],
  [['host_port' => '8080', 'containers' => ['a', 'b']]]));
$pc = findingsOf($findings, 'docker_port_conflict');
check(count($pc) === 1, 'a host port claimed by two containers produces exactly one conflict finding');
check(str_contains($pc[0]['title'] ?? '', '8080'), 'the conflict finding names the port, got: ' . ($pc[0]['title'] ?? ''));
check(($pc[0]['severity'] ?? null) === 'alert', 'port conflict severity is alert');

// ---------------------------------------------------------------- test 3 ---
// SQLite behind /mnt/user. v_docker_hygiene() only populates
// sqlite_paths when a real SQLite file was found, so the check keys off
// that -- a container with a /mnt/user mount but NO sqlite file must not
// be flagged.
$findings = v_checks_run(hyg(['plex' => ctr('plex', [
  'has_mnt_user_path' => true,
  'mounts' => [['source' => '/mnt/user', 'destination' => '/data']],
  'sqlite_paths' => [],
])]));
check(count(findingsOf($findings, 'docker_sqlite_user')) === 0,
  'a /mnt/user mount with no SQLite file is NOT flagged (avoids noise for legit shared media paths)');

// Same container, but with a real SQLite file discovered -> flagged.
$findings = v_checks_run(hyg(['app' => ctr('app', [
  'has_mnt_user_path' => true,
  'mounts' => [['source' => '/mnt/user/appdata/app', 'destination' => '/config']],
  'sqlite_paths' => ['/mnt/user/appdata/app/library.db'],
])]));
$sq = findingsOf($findings, 'docker_sqlite_user');
check(count($sq) === 1, 'a /mnt/user path with a real SQLite file produces exactly one finding');
check(str_contains($sq[0]['detail'] ?? '', 'library.db'), 'the finding names the actual database path');
check(str_contains($sq[0]['detail'] ?? '', 'FUSE'), 'the finding explains the FUSE/file-locking reason');

// ---------------------------------------------------------------- test 4 ---
// No memory limit: flagged only while the host is under real pressure.
$findings = v_checks_run(hyg(['app' => ctr('app', ['mem_limit_set' => false])], [], 92.0));
$ml = findingsOf($findings, 'docker_no_mem_limit');
check(count($ml) === 1, 'an unlimited container on a host at 92% RAM produces one finding');

$findings = v_checks_run(hyg(['app' => ctr('app', ['mem_limit_set' => false])], [], 40.0));
check(count(findingsOf($findings, 'docker_no_mem_limit')) === 0,
  'the same unlimited container on a host at 40% RAM produces NO finding (not noise)');

// A container WITH a limit is never flagged, even under pressure.
$findings = v_checks_run(hyg(['app' => ctr('app', ['mem_limit_set' => true, 'mem_limit_bytes' => 2147483648])], [], 92.0));
check(count(findingsOf($findings, 'docker_no_mem_limit')) === 0, 'a container with a memory limit is never flagged');

// A stopped container is not flagged for lacking a limit.
$findings = v_checks_run(hyg(['app' => ctr('app', ['mem_limit_set' => false, 'state' => 'exited'])], [], 92.0));
check(count(findingsOf($findings, 'docker_no_mem_limit')) === 0, 'a stopped container is not flagged for lacking a memory limit');

// ---------------------------------------------------------------- test 5 ---
// The fully clean case (matching Selene's real containers: all 0
// restarts, exit 0, no port conflicts, low RAM) produces no findings.
$findings = v_checks_run(hyg([
  'Plex-Media-Server' => ctr('Plex-Media-Server', ['has_mnt_user_path' => true, 'sqlite_paths' => []]),
  'AdGuard-Prim' => ctr('AdGuard-Prim'),
], [], 40.0));
check(count(findingsOf($findings, 'docker_restart')) === 0
   && count(findingsOf($findings, 'docker_port_conflict')) === 0
   && count(findingsOf($findings, 'docker_sqlite_user')) === 0
   && count(findingsOf($findings, 'docker_no_mem_limit')) === 0,
  'a fully clean docker host (Selene\'s real state) produces no hygiene findings');

// ---------------------------------------------------------------- test 6 ---
// Missing docker_hygiene key must not crash.
$findings = v_checks_run(['time' => time()]);
check(count(findingsOf($findings, 'docker_restart')) === 0, 'a snapshot missing docker_hygiene produces nothing and does not crash');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
