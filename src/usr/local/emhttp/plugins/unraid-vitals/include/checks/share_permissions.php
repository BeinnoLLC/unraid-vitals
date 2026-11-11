<?php

declare(strict_types=1);

function v_check_share_permissions(array $snap): array {
  $shareInfo = $snap['shares'] ?? [];
  $shares = $shareInfo['list'] ?? [];
  if (!is_array($shares) || !$shares) return [];
  
  
  $maxEntries = 400;
  $out = [];

  foreach ($shares as $sh) {
    $name = is_array($sh) ? ($sh['name'] ?? null) : null;
    if (!$name) continue;
    $path = '/mnt/user/' . rawurlencode($name);
    if (!is_dir($path)) continue;

    
    $tops = [];
    $h = @opendir($path);
    if (!$h) continue;
    while (($entry = readdir($h)) !== false) {
      if ($entry === '.' || $entry === '..') continue;
      $tops[] = $entry;
      if (count($tops) >= $maxEntries) break;
    }
    closedir($h);
    if (!$tops) continue;

    
    
    $quoted = implode(' ', array_map(function ($t) use ($path) {
      return escapeshellarg($path . '/' . $t);
    }, array_slice($tops, 0, 50)));
    $cmd = 'timeout 20 ionice -c3 find ' . $quoted
      . ' -maxdepth 2 \\( -not -user nobody -o -not -group users \\) -printf \'%U %G %p\n\' 2>/dev/null'
      . ' | head -n ' . $maxEntries;
    $raw = @shell_exec($cmd);
    if (!$raw) continue;

    $badFiles = [];
    $badTops = [];
    foreach (explode("\n", trim($raw)) as $line) {
      if (!preg_match('/^(\d+) (\S+) (.+)$/', $line, $m)) continue;
      $badFiles[] = ['uid' => $m[1], 'group' => $m[2], 'path' => $m[3]];
      
      $rel = ltrim(substr($m[3], strlen($path)), '/');
      $top = strtok($rel, '/');
      if ($top !== false) $badTops[$top] = ($badTops[$top] ?? 0) + 1;
      if (count($badFiles) >= 50) break; 
    }
    if (!$badFiles) continue;

    $total = count($badFiles);
    $sampleTop = array_slice(array_keys($badTops), 0, 5);
    $sampleOwner = $badFiles[0]['uid'] === '0' ? 'root' : ('uid ' . $badFiles[0]['uid']);

    $out[] = [
      'check_id' => 'share_permissions',
      'severity' => 'warning',
      'subject'  => 'share_permissions',
      'title'    => 'Share "' . $name . '" has files not owned by nobody:users'
        . (count($badTops) ? ' (top-level: ' . implode(', ', $sampleTop) . ')' : ''),
      'detail'   => 'Found ' . $total . '+ files in share "' . $name . '" not owned by nobody:users or without group write'
        . ' (sample owner: ' . $sampleOwner . '). Client writes through SMB/NFS use the nobody:users pair,'
        . ' so these files are read-only to them. Fix with Tools → New Permissions on the share,'
        . ' or: find /mnt/user/' . $name . ' ! -user nobody -o ! -group users | newperms-style chown/chmod.'
        . ' (Scan was a bounded sample, not a full walk.)',
      'evidence' => [
        ['label' => 'share', 'value' => $name],
        ['label' => 'affected_top_level', 'value' => $sampleTop],
        ['label' => 'sample_bad_files', 'value' => array_slice(array_column($badFiles, 'path'), 0, 10)],
        ['label' => 'scan_mode', 'value' => 'bounded sample: maxdepth 2, ≤' . $maxEntries . ' entries/share'],
      ],
      'fix_id'   => 'share_permissions_fix',
    ];
  }
  return $out;
}