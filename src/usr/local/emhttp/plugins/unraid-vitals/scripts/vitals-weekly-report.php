#!/usr/bin/php
<?php
/**
 * unraid-vitals — scripts/vitals-weekly-report.php (P15-10)
 *
 * Sends the weekly health summary through Unraid's own notification
 * system. Run from cron once a week (see install.sh) — the summary
 * covers a rolling 7-day window regardless of exactly when it fires, so
 * there's no "day of week" state to track between runs.
 */

require_once __DIR__ . '/../include/store.php';

v_send_weekly_health_report();

if (!in_array('--quiet', $argv ?? [], true)) {
  echo "vitals-weekly-report: sent\n";
}
