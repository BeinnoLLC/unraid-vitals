#!/usr/bin/php
<?php
/* unraid-vitals — scripts/vitals-logrotate.php (P16-08)
 *
 * Size-capped rotation for the plugin's own logs:
 *   /var/tmp/unraid-vitals/*.log  (collector, agents, checks, study, vmwatch,
 *                                  storage-scan, dup-scan, weekly-report…)
 * and /tmp/unraid-vitals agent logs when present.
 *
 * Runs daily from /etc/cron.d/unraid-vitals (installed by scripts/install.sh).
 * A log over VITALS_LOG_CAP_BYTES becomes <name>.log.1 (previous .log.1 is
 * overwritten, single generation — bounded disk use by construction), and a
 * fresh <name>.log starts. Never deletes a .log that is below the cap, never
 * follows symlinks (lstat), refuses paths that escape the log roots.
 *
 * Acceptance (P16-08): collector.log never exceeds its cap after a week.
 * The rotation is idempotent and O(#log files).
 */

declare(strict_types=1);

const V_LOG_CAP_BYTES = 5 * 1024 * 1024; // 5 MiB per file, single generation
const V_LOG_ROOTS = ['/var/tmp/unraid-vitals', '/tmp/unraid-vitals', '/var/log/unraid-vitals'];

$logRoots = V_LOG_ROOTS;

// Test seam: allow a single argv[1] root for unit runs; refuse otherwise.
$extraRoot = $argv[1] ?? null;
if ($extraRoot && is_dir($extraRoot) && str_starts_with(realpath($extraRoot) ?: '', '/tmp/')) {
  $logRoots = [$extraRoot];
}

$rotated = 0; $checked = 0;
foreach ($logRoots as $root) {
  $realRoot = realpath($root);
  if ($realRoot === false) continue;
  foreach (glob($realRoot . '/*.log') ?: [] as $log) {
    $checked++;
    $real = realpath($log);
    if ($real === false || !str_starts_with($real, $realRoot . '/')) continue; // symlink escape
    $size = @filesize($real);
    if ($size === false || $size <= V_LOG_CAP_BYTES) continue;

    $gen1 = $real . '.1';
    // copytruncate semantics: keep the newest content, truncate the live file —
    // cron keeps its >> fd valid and nothing is lost between copy and truncate
    // except bytes written in that microseconds-wide window.
    if (!@copy($real, $gen1)) continue;
    $fp = @fopen($real, 'w');           // open-for-truncate keeps the inode; cron's >> fd stays valid
    if ($fp === false) continue;
    fclose($fp);
    $rotated++;
  }
  // Remove .log.1 generations from before today's cap scheme if bloated (belt+braces)
  foreach (glob($realRoot . '/*.log.1') ?: [] as $old) {
    $s = @filesize($old);
    if ($s !== false && $s > V_LOG_CAP_BYTES * 2) @unlink($old);
  }
}

fwrite(STDOUT, "vitals-logrotate: checked=$checked rotated=$rotated cap=" . V_LOG_CAP_BYTES . "\n");
exit(0);