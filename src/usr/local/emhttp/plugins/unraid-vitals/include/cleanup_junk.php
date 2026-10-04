<?php
/* unraid-vitals — cleanup kind: junk (P16-05 / #80)
 *
 * Junk files under OPTED-IN shares only: empty folders, .DS_Store,
 * Thumbs.db, @eaDir dirs, ._*, ._* AppleDouble files.
 * Preview: per opted-in share, counts per top-level folder.
 * Apply: removes exactly the previewed paths; other shares untouched by
 * construction (share list comes from the preview the user saw and applied).
 */

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

/** Junk scan for ONE share. Returns per-folder counts + full path list. */
function v_junk_scan_share(string $share, int $maxEntries = 400): array {
  $root = '/mnt/user/' . $share;
  if (!is_dir($root)) return [];
  $names = v_junk_names();
  $paths = [];
  $perFolder = [];
  // bounded find: maxdepth cap + head cap, low I/O priority
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
    // gate: must be inside /mnt/user/<share>
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
  // empty directories: any dir under the share with nothing inside (not junk-named ones)
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

/** Preview across the opted-in share list (vitals.cfg JUNK_SHARES=csv). */
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

/** Apply: remove exactly the items (files unlink; dirs rmdir/recursive for @eaDir). */
function v_cleanup_junk_apply(array $items): array {
  $done = 0; $bytes = 0; $results = [];
  $cfg = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
  $shares = [];
  foreach (array_filter(array_map('trim', explode(',', (string)($cfg['JUNK_SHARES'] ?? '')))) as $s) $shares[$s] = true;
  foreach ($items as $it) {
    $path = (string)($it['path'] ?? '');
    $type = (string)($it['type'] ?? 'f');
    $real = realpath($path);
    // gate: /mnt/user/<opted-share>/...
    $m = null;
    if (!$real || !preg_match('#^/mnt/user/([^/]+)/(.+)$#', $real, $m) || !isset($shares[$m[1]])) {
      return ['ok' => false, 'error' => 'path failed re-validation (outside opted-in shares)', 'detail' => $path, 'partial_done' => $done];
    }
    if ($type === 'd') {
      // @eaDir: recursive delete of the dir itself
      $sz = (int)trim((string)(@shell_exec('timeout 15 ionice -c3 du -sb ' . escapeshellarg($real) . ' 2>/dev/null') ?: '0'));
      if (@shell_exec('timeout 20 ionice -c3 rm -rf ' . escapeshellarg($real) . ' 2>&1') === null) {
        $done++; $bytes += $sz; $results[] = ['ref' => $m[1], 'path' => $real, 'removed' => true];
      } else { $results[] = ['ref' => $m[1], 'path' => $real, 'error' => 'delete failed']; }
    } elseif ($type === 'e') {
      // empty dir: rmdir only (safe — if it gained a file, rmdir fails)
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