<?php

declare(strict_types=1);

require_once __DIR__ . '/store.php';

const V_JUNK_FILES = ['.DS_Store', 'Thumbs.db'];

function v_junk_names(): array {
  return [
    'files' => ['.DS_Store', 'Thumbs.db'],
    'prefix_files' => ['._'],
    'dirs' => ['@eaDir'],
  ];
}

function v_junk_scan_share(string $share, int $maxEntries = 400): array {
  $root = '/mnt/user/' . $share;
  if (!is_dir($root)) return [];
  $names = v_junk_names();
  $paths = [];
  $perFolder = [];
  
  $cmd = 'timeout 30 ionice -c3 find ' . escapeshellarg($root)
    . ' \( -name .DS_Store -o -name Thumbs.db -o -name "._*" -o -type d -name @eaDir \)'
    . ' -printf \'%y %p\n\' 2>/dev/null | head -n ' . $maxEntries;
  foreach (explode("\n", (string)@shell_exec($cmd)) as $line) {
    if (!preg_match('/^([fd]) (.+)$/', $line, $m)) continue;
    $type = $m[1]; $path = $m[2];
    $base = basename($path);
    $isJunk = false;
    if (in_array($base, $names['files'], true)) $isJunk = true;
    elseif (in_array($type, ['d']) && in_array($base, $names['dirs'], true)) $isJunk = true;
    elseif (str_starts_with($base, '._')) $isJunk = true;
    if (!$isJunk) continue;
    
    $real = realpath($path);
    if (!$real || strpos($real, $root . '/') !== 0) continue;
    $paths[] = ['path' => $real, 'type' => $type];
    $rel = ltrim(substr($real, strlen($root)), '/');
    $top = strtok($rel, '/');
    if ($top !== false) {
      $key = $top . '/' . ($type === 'd' ? '@eaDir' : 'files');
      $perFolder[$key] = ($perFolder[$key] ?? 0) + 1;
    }
    if (count($paths) >= $maxEntries) break;
  }
  
  $cmd2 = 'timeout 20 ionice -c3 find ' . escapeshellarg($root)
    . ' -maxdepth 4 -type d -empty -not -name "@eaDir" -printf \'%p\n\' 2>/dev/null | head -n ' . $maxEntries;
  foreach (explode("\n", (string)@shell_exec($cmd2)) as $p) {
    $p = trim($p);
    if ($p === '' || $p === $root) continue;
    $real = realpath($p);
    if (!$real || strpos($real, $root . '/') !== 0) continue;
    $paths[] = ['path' => $real, 'type' => 'e'];
    $rel = ltrim(substr($real, strlen($root)), '/');
    $top = strtok($rel, '/');
    if ($top !== false) {
      $key = $top . '/empty_dirs';
      $perFolder[$key] = ($perFolder[$key] ?? 0) + 1;
    }
    if (count($paths) >= $maxEntries) break;
  }
  return ['paths' => $paths, 'perFolder' => $perFolder];
}

function v_cleanup_junk_preview(): array {
  $cfg = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
  $shares = array_filter(array_map('trim', explode(',', (string)($cfg['JUNK_SHARES'] ?? ''))));
  if (!$shares) return ['ok' => false, 'error' => 'no shares opted in (set JUNK_SHARES in vitals.cfg, comma-separated)'];
  $paths = []; $perFolder = [];
  foreach ($shares as $s) {
    $r = v_junk_scan_share($s);
    $paths = array_merge($paths, $r['paths']);
    foreach ($r['perFolder'] as $k => $n) $perFolder[$s . '/' . $k] = $n;
    if (count($paths) >= 1200) break;
  }
  $items = [];
  foreach ($paths as $p) {
    $items[] = ['ref' => basename($p['path']), 'path' => $p['path'], 'type' => $p['type'],
                'bytes' => $p['type'] === 'd' || $p['type'] === 'e' ? null : (@filesize($p['path']) ?: 0)];
  }
  return ['ok' => true, 'items' => $items, 'per_folder' => $perFolder, 'shares' => $shares];
}

function v_cleanup_junk_apply(array $items): array {
  $done = 0; $bytes = 0; $results = [];
  $cfg = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
  $shares = [];
  foreach (array_filter(array_map('trim', explode(',', (string)($cfg['JUNK_SHARES'] ?? '')))) as $s) $shares[$s] = true;
  foreach ($items as $it) {
    $path = (string)($it['path'] ?? '');
    $type = (string)($it['type'] ?? 'f');
    $real = realpath($path);
    
    $m = null;
    if (!$real || !preg_match('#^/mnt/user/([^/]+)/(.+)$#', $real, $m) || !isset($shares[$m[1]])) {
      return ['ok' => false, 'error' => 'path failed re-validation (outside opted-in shares)', 'detail' => $path, 'partial_done' => $done];
    }
    if ($type === 'd') {
      
      $sz = (int)trim((string)(@shell_exec('timeout 15 ionice -c3 du -sb ' . escapeshellarg($real) . ' 2>/dev/null') ?: '0'));
      if (@shell_exec('timeout 20 ionice -c3 rm -rf ' . escapeshellarg($real) . ' 2>&1') === null) {
        $done++; $bytes += $sz; $results[] = ['ref' => $m[1], 'path' => $real, 'removed' => true];
      } else { $results[] = ['ref' => $m[1], 'path' => $real, 'error' => 'delete failed']; }
    } elseif ($type === 'e') {
      
      $before = @scandir($real);
      if (@rmdir($real)) { $done++; $results[] = ['ref' => $m[1], 'path' => $real, 'removed' => true]; }
      else { $results[] = ['ref' => $m[1], 'path' => $real, 'error' => 'not empty anymore (kept)']; }
    } else {
      $sz = @filesize($real) ?: 0;
      if (@unlink($real)) { $done++; $bytes += $sz; $results[] = ['ref' => $m[1], 'path' => $real, 'removed' => true, 'bytes' => $sz]; }
      else { $results[] = ['ref' => $m[1], 'path' => $real, 'error' => 'delete failed']; }
    }
  }
  return ['ok' => true, 'removed' => $done, 'of' => count($items), 'bytes_reclaimed' => $bytes, 'results' => $results];
}