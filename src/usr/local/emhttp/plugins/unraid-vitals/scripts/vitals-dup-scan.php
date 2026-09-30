<?php
/**
 * unraid-vitals — scripts/vitals-dup-scan.php (P15-06)
 *
 * Duplicate file finder: size match -> head+tail hash match -> full hash
 * confirm, only within array/cache shares (not appdata/system/domains,
 * which are full of one-off binaries that coincidentally share sizes).
 * Report-only; no deletion here (per the ticket, deletion is a separate
 * cleanup framework in P16-01).
 *
 * Same low-I/O-priority, spun-down-skip discipline as the storage
 * analyzer — a full-tree walk + hashing pass is easily the heaviest
 * single job this plugin runs, so it shares the storage scan's nightly
 * cron slot logic (own cron entry, but same care).
 */

require_once __DIR__ . '/../include/store.php';

$quiet = in_array('--quiet', $argv, true);
$force = in_array('--force', $argv, true);
$maxFiles = 200000;   // hard cap on files walked, so a runaway share can't hang the box overnight
$minSize = 1048576;   // 1 MiB floor -- small files aren't worth a hashing pass, and dwarf the DB with noise

$log = function (string $m) use ($quiet) { if (!$quiet) echo $m . "\n"; };

$db = v_events_db();
if (!$db) { fwrite(STDERR, "vitals-dup-scan: no DB path available\n"); exit(1); }

$db->exec("CREATE TABLE IF NOT EXISTS dup_groups (
  id INTEGER PRIMARY KEY AUTOINCREMENT, size INTEGER NOT NULL, full_hash TEXT NOT NULL,
  file_count INTEGER NOT NULL, wasted_bytes INTEGER NOT NULL, scanned_at INTEGER NOT NULL,
  UNIQUE(full_hash))");
$db->exec("CREATE TABLE IF NOT EXISTS dup_files (
  id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INTEGER NOT NULL, path TEXT NOT NULL,
  FOREIGN KEY(group_id) REFERENCES dup_groups(id))");
$db->exec("CREATE INDEX IF NOT EXISTS idx_dup_files_group ON dup_files(group_id)");

$disks = v_array_disks();
$allDown = true;
foreach (array_merge($disks['data'] ?? [], $disks['cache'] ?? []) as $d) {
  if (empty($d['spundown'])) { $allDown = false; break; }
}
if ($allDown && !$force) { $log('vitals-dup-scan: all disks spun down, skipping'); exit(0); }

$shares = v_shares();
$skipShares = ['appdata', 'system', 'domains', 'isos'];   // binaries/VM images: coincidental size matches, not real dupes worth reporting

// Phase 1: walk every eligible share, bucket paths by size.
$bySize = [];
$walked = 0;
foreach ($shares['list'] ?? [] as $s) {
  $name = $s['name'] ?? '';
  if ($name === '' || in_array($name, $skipShares, true)) continue;
  $path = '/mnt/user/' . $name;
  if (!is_dir($path)) continue;
  $log("walking $name ...");
  $cmd = 'nice -n 19 ionice -c3 find ' . escapeshellarg($path)
    . ' -xdev -type f -size +' . $minSize . 'c -printf "%s %p\\n" 2>/dev/null';
  $out = @shell_exec($cmd);
  foreach (explode("\n", trim((string)$out)) as $line) {
    if ($line === '' || $walked >= $maxFiles) continue;
    $sp = strpos($line, ' ');
    if ($sp === false) continue;
    $sz = (int)substr($line, 0, $sp);
    $p = substr($line, $sp + 1);
    $bySize[$sz][] = $p;
    $walked++;
  }
  if ($walked >= $maxFiles) { $log('vitals-dup-scan: hit the ' . $maxFiles . '-file cap, stopping walk early'); break; }
}

// Phase 2: only sizes with 2+ files are duplicate candidates at all.
$candidates = array_filter($bySize, fn($paths) => count($paths) >= 2);
$log('candidate size-groups: ' . count($candidates) . ' (from ' . $walked . ' files walked)');

// Phase 3: head+tail hash (cheap -- 64 KiB from each end) narrows
// candidates further before paying for a full read.
$headTailHash = function (string $path): ?string {
  $fh = @fopen($path, 'rb');
  if (!$fh) return null;
  $head = fread($fh, 65536);
  $size = fstat($fh)['size'] ?? 0;
  if ($size > 131072) fseek($fh, -65536, SEEK_END);
  $tail = $size > 65536 ? fread($fh, 65536) : '';
  fclose($fh);
  return md5($head . $tail);
};

$confirmed = 0; $groups = 0;
$now = time();
// Hashing multi-GB files takes hours; the DB writes take milliseconds.
// Never hold a write transaction across the hashing -- every other writer
// on vitals.db (agents, KB, research jobs) blocks on it. Each confirmed
// group is committed in its own short transaction instead.
$commitGroup = function (int $size, string $full, array $same) use ($db, $now) {
  $wasted = $size * (count($same) - 1);
  $db->exec('BEGIN IMMEDIATE');
  $db->exec("DELETE FROM dup_files WHERE group_id IN (SELECT id FROM dup_groups WHERE full_hash = " . $db->escapeString($full) . ")");
  $ins = $db->prepare("INSERT INTO dup_groups (size, full_hash, file_count, wasted_bytes, scanned_at)
                         VALUES (?, ?, ?, ?, ?)
                         ON CONFLICT(full_hash) DO UPDATE SET file_count=excluded.file_count,
                           wasted_bytes=excluded.wasted_bytes, scanned_at=excluded.scanned_at");
  $ins->bindValue(1, $size, SQLITE3_INTEGER);
  $ins->bindValue(2, $full, SQLITE3_TEXT);
  $ins->bindValue(3, count($same), SQLITE3_INTEGER);
  $ins->bindValue(4, $wasted, SQLITE3_INTEGER);
  $ins->bindValue(5, $now, SQLITE3_INTEGER);
  $ins->execute();
  $groupId = $db->querySingle("SELECT id FROM dup_groups WHERE full_hash = " . $db->escapeString($full));
  $fi = $db->prepare("INSERT INTO dup_files (group_id, path) VALUES (?, ?)");
  foreach ($same as $p) {
    $fi->reset();
    $fi->bindValue(1, $groupId, SQLITE3_INTEGER);
    $fi->bindValue(2, $p, SQLITE3_TEXT);
    $fi->execute();
  }
  $db->exec('COMMIT');
};
foreach ($candidates as $size => $paths) {
  $byHeadTail = [];
  foreach ($paths as $p) {
    $ht = $headTailHash($p);
    if ($ht !== null) $byHeadTail[$ht][] = $p;
  }
  foreach ($byHeadTail as $ht => $sameHt) {
    if (count($sameHt) < 2) continue;
    // Phase 4: full hash to confirm real duplicates, not just a
    // head/tail coincidence.
    $byFull = [];
    foreach ($sameHt as $p) {
      $full = @hash_file('md5', $p);
      if ($full !== false) $byFull[$full][] = $p;
    }
    foreach ($byFull as $full => $same) {
      if (count($same) < 2) continue;
      $commitGroup((int)$size, (string)$full, $same);
      $confirmed += count($same);
      $groups++;
    }
  }
}
// Drop groups not refreshed by this run -- they no longer exist or fell
// below the duplicate threshold.
$db->exec('BEGIN IMMEDIATE');
$db->exec("DELETE FROM dup_files WHERE group_id IN (SELECT id FROM dup_groups WHERE scanned_at < " . (int)$now . ")");
$db->exec("DELETE FROM dup_groups WHERE scanned_at < " . (int)$now);
$db->exec('COMMIT');
$db->close();

$log("vitals-dup-scan: $groups duplicate group(s), $confirmed file(s) confirmed");
