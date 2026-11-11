#!/usr/bin/php
<?php

require_once __DIR__ . '/../include/store.php';

v_send_weekly_health_report();

if (!in_array('--quiet', $argv ?? [], true)) {
  echo "vitals-weekly-report: sent\n";
}
