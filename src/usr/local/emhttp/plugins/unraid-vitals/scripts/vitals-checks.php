#!/usr/bin/php
<?php

require_once __DIR__ . '/../include/checks.php';

$snap = v_latest();
if (!$snap) {
  
  
  
  if (!in_array('--quiet', $argv ?? [], true)) echo "vitals-checks: no snapshot yet, skipped\n";
  exit(0);
}

$findings = v_checks_run($snap);

if (in_array('--quiet', $argv ?? [], true)) exit(0);

$bySeverity = [];
foreach ($findings as $f) $bySeverity[$f['severity']] = ($bySeverity[$f['severity']] ?? 0) + 1;
printf("vitals-checks: %d finding(s) — %s\n", count($findings),
  $bySeverity ? implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($bySeverity), $bySeverity)) : 'none');
