#!/usr/bin/php
<?php

declare(strict_types=1);

const V_LOG_CAP_BYTES = 5 * 1024 * 1024; 
const V_LOG_ROOTS = ['/var/tmp/unraid-vitals', '/tmp/unraid-vitals', '/var/log/unraid-vitals'];

$logRoots = V_LOG_ROOTS;

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
    if ($real === false || !str_starts_with($real, $realRoot . '/')) continue; 
    $size = @filesize($real);
    if ($size === false || $size <= V_LOG_CAP_BYTES) continue;

    $gen1 = $real . '.1';
    
    
    
    if (!@copy($real, $gen1)) continue;
    $fp = @fopen($real, 'w');           
    if ($fp === false) continue;
    fclose($fp);
    $rotated++;
  }
  
  foreach (glob($realRoot . '/*.log.1') ?: [] as $old) {
    $s = @filesize($old);
    if ($s !== false && $s > V_LOG_CAP_BYTES * 2) @unlink($old);
  }
}

fwrite(STDOUT, "vitals-logrotate: checked=$checked rotated=$rotated cap=" . V_LOG_CAP_BYTES . "\n");
exit(0);