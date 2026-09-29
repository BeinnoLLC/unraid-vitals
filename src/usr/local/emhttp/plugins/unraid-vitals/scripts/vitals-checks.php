#!/usr/bin/php
<?php
/**
 * unraid-vitals — checks engine runner (plan 106 P13-01).
 * Run from cron on its own schedule (every 5 minutes by default — see
 * install.sh; the collector's per-minute cadence would be wasteful for
 * checks that look at slow-moving conditions like disk fill).
 *
 *   vitals-checks.php            run every enabled check, persist results
 *   vitals-checks.php --quiet    suppress the summary line (cron use)
 */

require_once __DIR__ . '/../include/checks.php';

$snap = v_latest();
if (!$snap) {
  // No collector sample yet (fresh install, or collector cron hasn't ticked)
  // — nothing to check against. Not an error: exit clean so cron doesn't log
  // noise on every run until the first collector tick lands.
  if (!in_array('--quiet', $argv ?? [], true)) echo "vitals-checks: no snapshot yet, skipped\n";
  exit(0);
}

$findings = v_checks_run($snap);

if (in_array('--quiet', $argv ?? [], true)) exit(0);

$bySeverity = [];
foreach ($findings as $f) $bySeverity[$f['severity']] = ($bySeverity[$f['severity']] ?? 0) + 1;
printf("vitals-checks: %d finding(s) — %s\n", count($findings),
  $bySeverity ? implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($bySeverity), $bySeverity)) : 'none');
