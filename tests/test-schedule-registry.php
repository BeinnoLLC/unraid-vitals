<?php
/**
 * unraid-vitals — v_sched_apply() (plan 122 P18-01).
 * Run: php tests/test-schedule-registry.php
 *
 * This is the one thing the plugin does that changes how the user's box behaves
 * while nobody is watching, and the only way to observe it is to read the files
 * cron will actually read. So this test writes cron files and reads them back
 * off disk, rather than asserting on strings some function handed it.
 *
 * Two seams make that possible, both the codebase's existing idiom of defining a
 * constant only if it is not already defined (see VITALS_STATE / VITALS_FLASH):
 * V_CROND_DIR (default /etc/cron.d) and VITALS_FLASH. Without V_CROND_DIR there
 * is no way to run this at all — the function's whole effect is the file it
 * writes to that one directory.
 *
 * Background this file is protecting: an empty NODE_BIN means "the agents cannot
 * run", and the registry contract is that a node-needing job must then have no
 * cron file on disk — a job that cannot run must not sit in /etc/cron.d failing
 * every minute, forever, with nobody reading the log.
 */

$tmp = sys_get_temp_dir() . '/vitals-sched-test-' . getmypid();
@mkdir($tmp, 0755, true);

define('VITALS_FLASH', $tmp . '/flash');
define('V_CROND_DIR', $tmp . '/cron.d');
@mkdir(VITALS_FLASH, 0755, true);
@mkdir(V_CROND_DIR, 0755, true);

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/unraid-vitals/include/schedule_registry.php';

const TEST_NODE = '/usr/bin/node24';
const TEST_ENV  = ['NODE_BIN' => TEST_NODE];
const NODE_LESS = ['NODE_BIN' => ''];

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

/** Start each scenario from a box that has never had the plugin installed. */
function resetBox(): void {
  foreach (glob(V_CROND_DIR . '/unraid-vitals*') ?: [] as $f) @unlink($f);
  foreach (glob(VITALS_FLASH . '/*') ?: [] as $f) @unlink($f);
}

function writeCfg(string $body): void {
  file_put_contents(VITALS_FLASH . '/vitals.cfg', $body);
}

/** basename => contents, for every cron file the plugin owns. */
function cronFiles(): array {
  $out = [];
  foreach (glob(V_CROND_DIR . '/unraid-vitals*') ?: [] as $f) $out[basename($f)] = (string)@file_get_contents($f);
  ksort($out);
  return $out;
}

function hasCron(string $name): bool {
  return isset(cronFiles()[$name]);
}

// ===================================================== a healthy box ======
resetBox();
writeCfg('');
$r = v_sched_apply($tmp, VITALS_FLASH, TEST_ENV);
$files = cronFiles();

check(count($files) > 0, 'a healthy box gets cron files written at all');
check(isset($files['unraid-vitals']), 'the collector job owns the bare name unraid-vitals (no -collector suffix)');
check($r['applied'] === count($files), 'the applied count equals the files actually on disk');
check($r['skipped'] === 0, 'no job is skipped when node is present and every schedule is valid');

$collector = $files['unraid-vitals'] ?? '';
check(str_contains($collector, 'vitals-jobwrap.php'), 'cron lines run through vitals-jobwrap.php, which records start/duration/exit into the runbook');
check(str_contains($collector, "'collector'"), 'the jobwrap call names the job it is running');
check(str_contains($collector, "\n"), 'the file is newline-terminated — a cron file without a trailing newline drops its last line');

// P18-03: the umbrella agents job must be gone once per-agent jobs exist, or the
// same agents run twice on overlapping schedules.
check(!hasCron('unraid-vitals-agents'), 'the legacy umbrella agents job is absent while per-agent jobs are enabled');
check(hasCron('unraid-vitals-agents_disks'), 'each real agent gets its own job');
check(hasCron('unraid-vitals-agents_unraid-release'), 'the unraid-release agent is scheduled too');

// ====================================== node disappears (the P13-01 gate) ==
resetBox();
writeCfg('');
v_sched_apply($tmp, VITALS_FLASH, TEST_ENV);
$withNode = array_keys(cronFiles());
$r = v_sched_apply($tmp, VITALS_FLASH, NODE_LESS);
$withoutNode = cronFiles();

$nodeJobsLeft = array_filter(array_keys($withoutNode), fn($n) => str_contains($n, 'agents_'));
check($nodeJobsLeft === [], 'empty NODE_BIN removes every needs_node cron file (a job that cannot run must not sit in cron.d)');
check(isset($withoutNode['unraid-vitals']), 'the collector survives an empty NODE_BIN — it is PHP, not node');
check(count($withoutNode) < count($withNode), 'the node-less apply removed files rather than leaving dead jobs behind');
check($r['skipped'] > 0, 'apply reports those jobs as skipped rather than silently succeeding');
check(!file_exists(V_CROND_DIR . '/unraid-vitals-agents_disks'), 'specifically the disks agent file is gone from disk');

// ==================================== disabling a job from settings =======
resetBox();
writeCfg("SCHED_AGENTS_THERMAL_ENABLED=\"0\"\n");
v_sched_apply($tmp, VITALS_FLASH, TEST_ENV);
check(!hasCron('unraid-vitals-agents_thermal'), 'SCHED_AGENTS_THERMAL_ENABLED=0 removes that job');
check(hasCron('unraid-vitals-agents_disks'), 'disabling one agent leaves its neighbours alone');

// ============================================ orphans from older versions ==
resetBox();
writeCfg('');
file_put_contents(V_CROND_DIR . '/unraid-vitals-ancient', "# left by a previous release\n");
v_sched_apply($tmp, VITALS_FLASH, TEST_ENV);
check(!file_exists(V_CROND_DIR . '/unraid-vitals-ancient'), 'a cron file whose job is no longer in the registry is deleted (orphan cleanup)');
check(!file_exists(V_CROND_DIR . '/unraid-vitals-unrelated'), 'a file the plugin does not own is never created or touched');

// ================================ schedule overrides and bad input ========
resetBox();
writeCfg("SCHED_AGENTS_THERMAL=\"*/7 * * * *\"\nSCHED_AGENTS_DISKS=\"not a cron line\"\n");
$r = v_sched_apply($tmp, VITALS_FLASH, TEST_ENV);
$files = cronFiles();

check(str_contains($files['unraid-vitals-agents_thermal'] ?? '', '*/7 * * * *'), 'SCHED_<ID> in vitals.cfg overrides the default schedule');
check(!hasCron('unraid-vitals-agents_disks'), 'a malformed schedule is skipped, never written into cron.d');
check($r['skipped'] === 1, 'exactly the malformed job is counted as skipped');
check(str_contains($files['unraid-vitals-agents_thermal'] ?? '', '# schedule: */7 * * * *'), 'the file says which schedule it is on, so a reader can tell an override from the default');

// the collector's schedule derives from INTERVAL, which is what the settings UI writes
resetBox();
writeCfg("INTERVAL=\"5\"\n");
v_sched_apply($tmp, VITALS_FLASH, TEST_ENV);
check(str_contains(cronFiles()['unraid-vitals'] ?? '', '*/5 * * * *'), 'INTERVAL drives the collector schedule');
check(!str_contains(cronFiles()['unraid-vitals'] ?? '', '*/1 * * * *'), 'INTERVAL=5 does not leave the every-minute default in place');

// ================================== the command every surface runs =========
// v_sched_build_cmd() is shared by cron install and the run-now path so the two
// cannot drift; it is where the node path and the config values enter the line.
$cmd = v_sched_build_cmd('agents_disks', TEST_ENV);
check(!str_contains($cmd, 'NODE_BIN_PLACEHOLDER'), 'build_cmd substitutes the node placeholder');
check(str_contains($cmd, TEST_NODE), 'build_cmd uses the node binary the installer found');
check(str_contains($cmd, 'agents.lock'), 'agent jobs take the shared lock, so they serialize instead of piling up');
check(str_contains($cmd, 'analyze.mjs'), 'agent jobs invoke analyze.mjs');

$cmdDefault = v_sched_build_cmd('agents_disks', []);
check(!str_contains($cmdDefault, 'NODE_BIN_PLACEHOLDER'), 'with no NODE_BIN, build_cmd still substitutes rather than emitting a placeholder');

// a config value must not be able to break out of its quotes into the cron file
resetBox();
writeCfg("LLM_STUDIO_PRIMARY=\"http://host:11434\$(touch /tmp/pwned)\"\n");
$cmdInj = v_sched_build_cmd('agents_disks', TEST_ENV);
check(str_contains($cmdInj, '\$('), 'a $ in a config value is escaped — it cannot become command substitution');
check(!str_contains($cmdInj, '";'), 'a config value cannot close its own quoting');

// ================================ reboot durability ======================
resetBox();
writeCfg('');
v_sched_apply($tmp, VITALS_FLASH, TEST_ENV);
check(file_exists(VITALS_FLASH . '/collector.cron'), 'each cron line is copied to flash so a reboot reinstates it without a reinstall');
check(file_exists(VITALS_FLASH . '/agents_disks.cron'), 'agent cron lines are copied to flash too');
check(str_contains((string)@file_get_contents(VITALS_FLASH . '/collector.cron'), 'vitals-jobwrap.php'), 'the flash copy is the same line that went into cron.d, not a summary of it');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
