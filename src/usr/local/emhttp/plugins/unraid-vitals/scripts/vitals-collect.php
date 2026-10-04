#!/usr/bin/php
<?php
/**
 * unraid-vitals — collector entry point.
 * Run from cron once a minute (see install script).
 *
 *   vitals-collect.php            full sample (ring + hourly rollup)
 *   vitals-collect.php --slim     sample without touching history
 */

require_once __DIR__ . '/../include/store.php';

$slim = in_array('--slim', $argv ?? [], true);

// Overlap guard (P18-08): a slow docker stats or SMART read must not stack
// runs. flock -n on the collector lock; second-instance exits 0 silently so
// cron behavior (no error mail) is preserved.
$lockFp = fopen('/var/tmp/unraid-vitals/collector.lock', 'c');
if ($lockFp && !flock($lockFp, LOCK_EX | LOCK_NB)) {
  if (!in_array('--quiet', $argv ?? [], true)) fwrite(STDERR, "vitals: previous run still in progress — skipping\n");
  exit(0);
}

$snap = v_tick(!$slim);

if ($lockFp) { flock($lockFp, LOCK_UN); fclose($lockFp); }

if (in_array('--quiet', $argv ?? [], true)) exit(0);

printf("vitals: cpu=%s%% mem=%s%% load=%s disks=%d/%d docker=%d/%d temp_max=%s\n",
  $snap['cpu']['total'] ?? 'n/a',
  $snap['mem']['pct'] ?? 'n/a',
  $snap['load']['l1'] ?? 'n/a',
  count($snap['array']['data'] ?? []),
  ($snap['array']['totals']['data_disks'] ?? 0),
  $snap['docker']['running'] ?? 0,
  $snap['docker']['count'] ?? 0,
  $snap['array']['data'][0]['temp'] ?? 'n/a'
);
