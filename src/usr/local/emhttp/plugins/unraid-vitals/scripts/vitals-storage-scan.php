<?php

require_once __DIR__ . '/../include/store.php';

$force = in_array('--force', $argv, true);
$quiet = in_array('--quiet', $argv, true);
$log = function (string $m) use ($quiet) { if (!$quiet) echo $m . "\n"; };

$db = v_events_db();   
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

  
  
  
  
  
  $allDown = count($spundownByDisk) > 0 && !in_array(false, $spundownByDisk, true);
  if ($allDown && !$force) { $skipped++; $log("skip $name (all disks spun down)"); continue; }

  $log("scanning $name ...");
  
  
  
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

$db->exec("DELETE FROM storage_scans WHERE scanned_at < " . (time() - 60 * 86400));

$db->close();
$log("vitals-storage-scan: $scanned share(s) scanned, $skipped skipped");
