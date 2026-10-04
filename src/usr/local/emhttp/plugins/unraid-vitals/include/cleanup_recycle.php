<?php
/* unraid-vitals — cleanup kind: recycle (P16-06 / #81)
 *
 * Size of each share's recycle bin folder (/mnt/user/<share>/.recycle/ —
 * written by the dynamix recycle bin plugin). Preview lists every share's
 * .recycle size + entry count; apply empties per share (whole bin) or only
 * entries older than N days. Never touches anything outside .recycle/ dirs.
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';

/** All existing .recycle dirs under /mnt/user/*, with their size + oldest entry ts. */
function v_cleanup_recycle_preview(): array {
  $items = [];
  foreach (scandir('/mnt/user') ?: [] as $share) {
    if ($share === '.' || $share === '..' || str_starts_with($share, '.')) continue;
    $bin = '/mnt/user/' . $share . '/.recycle';
    $real = realpath($bin);
    if (!$real || strpos($real, '/mnt/user/') !== 0 || basename($real) !== '.recycle') continue;
    $bytes = 0; $count = 0; $oldest = null;
    if (PHP_SAPI === 'cli' || function_exists('ionice')) {
      // fast path: du for bytes, find for count/oldest — bounded
      $bytes = (int)trim((string)(@shell_exec('timeout 30 ionice -c3 du -sb ' . escapeshellarg($real) . ' 2>/dev/null') ?: '0'));
      $out = @shell_exec('timeout 20 ionice -c3 find ' . escapeshellarg($real) . ' -type f -printf \'%T@ %s\n\' 2>/dev/null | head -n 200');
      foreach (explode("\n", (string)$out) as $line) {
        if (!preg_match('/^([0-9.]+) ([0-9]+)$/', trim($line), $m)) continue;
        $count++;
        $ts = (int)$m[1];
        if ($oldest === null || $ts < $oldest) $oldest = $ts;
      }
    }
    if ($bytes === 0 && $count === 0) continue; // empty bin: skip
    $items[] = ['ref' => $share, 'path' => $real, 'bytes' => $bytes, 'count' => $count,
                'oldest' => $oldest];
  }
  return ['ok' => true, 'items' => $items];
}

/**
 * Apply: empty the listed shares' recycle bins. Items come from the preview
 * (shares) — 'older_than' seconds filters entries (0 = everything). Each path
 * is re-validated: realpath must end in /.recycle and sit under /mnt/user.
 */
function v_cleanup_recycle_apply(array $items, int $olderThan = 0): array {
  $done = 0; $bytes = 0; $results = [];
  $cutoff = $olderThan > 0 ? time() - $olderThan : null;
  foreach ($items as $it) {
    $real = realpath((string)($it['path'] ?? ''));
    $share = (string)($it['ref'] ?? '');
    // gate 1: a .recycle dir under /mnt/user/<share>/
    if (!$real || !preg_match('#^/mnt/user/[^/]+/.recycle$#', $real)) {
      return ['ok' => false, 'error' => 'path failed validation', 'detail' => (string)($it['path'] ?? '')];
    }
    if (basename($real) !== '.recycle' || basename(dirname($real)) !== $share) {
      return ['ok' => false, 'error' => 'share/bin mismatch', 'detail' => $share . ' vs ' . $real];
    }
    foreach (scandir($real) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') continue;
      $p = $real . '/' . $entry;
      if ($cutoff !== null) {
        $mtime = @filemtime($p) ?: 0;
        if ($mtime > $cutoff) continue; // too recent — keep it
      }
      $sz = 0;
      if (is_dir($p)) {
        $sz = (int)trim((string)(@shell_exec('timeout 20 ionice -c3 du -sb ' . escapeshellarg($p) . ' 2>/dev/null') ?: '0'));
        $okDir = @shell_exec('timeout 20 ionice -c3 rm -rf ' . escapeshellarg($p) . ' 2>&1');
      } else {
        $sz = @filesize($p) ?: 0;
        $okDir = @unlink($p) ? '' : 'failed';
      }
      if ($okDir === null || strpos((string)$okDir, 'fail') !== false) {
        $results[] = ['ref' => $share . '/' . $entry, 'error' => 'delete failed'];
        continue;
      }
      $done++; $bytes += $sz;
      $results[] = ['ref' => $share . '/' . $entry, 'removed' => true, 'bytes' => $sz];
    }
  }
  return ['ok' => true, 'removed' => $done, 'of' => count($items), 'bytes_reclaimed' => $bytes, 'results' => $results];
}