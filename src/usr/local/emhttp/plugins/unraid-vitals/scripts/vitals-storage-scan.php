<?php
/**
 * unraid-vitals — scripts/vitals-storage-scan.php (P15-05)
 *
 * Nightly, low-I/O-priority scan of every share: top-20 folders by size,
 * a per-share total (for the 30-day growth chart, built from repeated
 * scans over time), a rough file-type breakdown, and files not accessed
 * in over a year. Cached entirely in the agents' SQLite DB — the UI never
 * triggers a scan on request, only reads whatever the last scan produced.
 *
 * Run from cron once nightly (see install.sh). Skips a share if EVERY
 * backing disk for it is currently spun down, unless --force is passed —
 * `du` would spin every disk back up just to answer "how big is this
 * folder", defeating the whole point of spin-down.
 */

require_once __DIR__ . '/../include/store.php';

$force = in_array('--force', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$log = function (string $m) use ($quiet) { if (!$quiet) echo $m . "\n"; };

$db = v_events_db();   // ensures kb_events/kb_lessons exist; also creates the DB file
if (!$db) { fwrite(STDERR, "vitals-storage-scan: no DB path available (array not mounted?)\n"); exit(1); }

$db->exec("CREATE TABLE IF NOT EXISTS storage_scans (
  id INTEGER PRIMARY KEY AUTOINCREMENT, share TEXT NOT NULL, scanned_at INTEGER NOT NULL,
  total_bytes INTEGER NOT NULL, top_folders TEXT, file_types TEXT, stale_bytes INTEGER, stale_count INTEGER)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_storage_share_time ON storage_scans(share, scanned_at DESC)");

$shares = v_shares();
$disks = v_array_disks();
$spundownByDisk = [];
foreach (array_merge($disks['data'] ?? [], $disks['cache'] ?? []) as $d) {
  $spundownByDisk[$d['name']] = !empty($d['spundown']);
}

$scanned = 0; $skipped = 0;
foreach ($shares['list'] ?? [] as $s) {
  $name = $s['name'] ?? '';
  if ($name === '') continue;
  $path = '/mnt/user/' . $name;
  if (!is_dir($path)) { $skipped++; continue; }

  // Skip if every disk this share could live on is spun down (unless forced)
  // -- v_shares() doesn't map share->disk list, so as a conservative proxy
  // check whether ALL array disks are spun down; a share almost always
  // spans more than one disk via fuse/shfs, so "any disk is up" is the
  // right bar to avoid a false skip.
  $allDown = count($spundownByDisk) > 0 && !in_array(false, $spundownByDisk, true);
  if ($allDown && !$force) { $skipped++; $log("skip $name (all disks spun down)"); continue; }

  $log("scanning $name ...");
  // -d1: one level of subfolder totals (the "top folders" the ticket asks
  // for); ionice -c3 (idle) + nice -n 19 keep this off the array's back
  // during normal use; -x stays on one filesystem (shfs union mount).
  $cmd = 'nice -n 19 ionice -c3 du -b -d1 -x ' . escapeshellarg($path) . ' 2>/dev/null';
  $out = @shell_exec($cmd);
  if ($out === null) { $skipped++; continue; }

  $folders = [];
  $total = 0;
  foreach (explode("\n", trim($out)) as $line) {
    if ($line === '') continue;
    if (!preg_match('/^(\d+)\s+(.+)$/', $line, $m)) continue;
    $bytes = (int)$m[1];
    $p = $m[2];
    if (rtrim($p, '/') === rtrim($path, '/')) { $total = $bytes; continue; }
    $folders[] = ['name' => basename($p), 'bytes' => $bytes];
  }
  usort($folders, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
  $folders = array_slice($folders, 0, 20);

  // File-type breakdown: sample-based (a full recursive walk over a
  // multi-TB share is the exact I/O cost this ticket asks us to avoid) --
  // `find` capped at a generous depth and count, grouped by extension.
  $typeBytes = [];
  $findCmd = 'nice -n 19 ionice -c3 find ' . escapeshellarg($path)
    . ' -maxdepth 4 -type f -printf "%s %f\\n" 2>/dev/null | head -n 20000';
  $findOut = @shell_exec($findCmd);
  foreach (explode("\n", trim((string)$findOut)) as $line) {
    if ($line === '') continue;
    $sp = strpos($line, ' ');
    if ($sp === false) continue;
    $sz = (int)substr($line, 0, $sp);
    $fn = substr($line, $sp + 1);
    $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION)) ?: '(none)';
    $typeBytes[$ext] = ($typeBytes[$ext] ?? 0) + $sz;
  }
  arsort($typeBytes);
  $typeBytes = array_slice($typeBytes, 0, 12, true);

  // Stale data: not accessed in 365+ days, same sampled walk depth.
  $staleCmd = 'nice -n 19 ionice -c3 find ' . escapeshellarg($path)
    . ' -maxdepth 4 -type f -atime +365 -printf "%s\\n" 2>/dev/null | head -n 20000';
  $staleOut = @shell_exec($staleCmd);
  $staleBytes = 0; $staleCount = 0;
  foreach (explode("\n", trim((string)$staleOut)) as $line) {
    if ($line === '') continue;
    $staleBytes += (int)$line;
    $staleCount++;
  }

  $stmt = $db->prepare("INSERT INTO storage_scans (share, scanned_at, total_bytes, top_folders, file_types, stale_bytes, stale_count)
                          VALUES (?, ?, ?, ?, ?, ?, ?)");
  $stmt->bindValue(1, $name, SQLITE3_TEXT);
  $stmt->bindValue(2, time(), SQLITE3_INTEGER);
  $stmt->bindValue(3, $total, SQLITE3_INTEGER);
  $stmt->bindValue(4, json_encode($folders), SQLITE3_TEXT);
  $stmt->bindValue(5, json_encode($typeBytes), SQLITE3_TEXT);
  $stmt->bindValue(6, $staleBytes, SQLITE3_INTEGER);
  $stmt->bindValue(7, $staleCount, SQLITE3_INTEGER);
  $stmt->execute();
  $scanned++;
}

// Retention: keep 60 days of history per share (enough for a 30-day growth
// chart with margin), prune older rows so the DB doesn't grow unbounded.
$db->exec("DELETE FROM storage_scans WHERE scanned_at < " . (time() - 60 * 86400));

$db->close();
$log("vitals-storage-scan: $scanned share(s) scanned, $skipped skipped");
