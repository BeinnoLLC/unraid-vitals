#!/usr/bin/php
<?php

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);

require '/usr/local/emhttp/plugins/unraid-vitals/include/store.php';

$cfg  = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
$db   = v_events_db();
$out  = ['rollups' => 0, 'kb_documents' => 0, 'kb_events' => 0, 'research_jobs' => 0, 'check_results' => 0];
$keepDays = max(1, (int)($cfg['KEEP_DAYS'] ?? 90));
$kbKeep   = max(1, (int)($cfg['KB_KEEP_DAYS'] ?? 180));
$rsKeep   = max(1, (int)($cfg['RESEARCH_KEEP_DAYS'] ?? 90));
$cutoffTs = time() - $keepDays * 86400;
$kbCutoff = time() - $kbKeep * 86400;
$rsCutoff = time() - $rsKeep * 86400;

$dir = VITALS_FLASH . '/history';
foreach (glob($dir . '/*.jsonl') ?: [] as $f) {
  if (preg_match('/(\d{4})-(\d{2})\.jsonl$/', $f, $m)) {
    $monthEnd = strtotime($m[1] . '-' . $m[2] . '-01 +1 month');
    if ($monthEnd !== false && $monthEnd < $cutoffTs) { @unlink($f); $out['rollups']++; }
  }
}

if (!$db) {
  fwrite(STDOUT, 'vitals-prune: no db — ' . json_encode($out) . "\n");
  exit(0);
}

$st = $db->prepare('DELETE FROM kb_lessons WHERE last_seen_at < :cut');
$st->bindValue(':cut', $kbCutoff, SQLITE3_INTEGER);
$st->execute();
$out['kb_documents'] = $db->changes();

$st = $db->prepare("DELETE FROM kb_events WHERE started_at < :cut AND status != 'open'");
$st->bindValue(':cut', $cutoffTs, SQLITE3_INTEGER);
$st->execute();
$out['kb_events'] = $db->changes();

$tbl = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='research_jobs'");
if ($tbl) {
  $cols = [];
  $res = $db->query('PRAGMA table_info(research_jobs)');
  while (($c = $res->fetchArray(SQLITE3_ASSOC))) $cols[] = $c['name'];
  $timeCol = in_array('created_at', $cols, true) ? 'created_at' : (in_array('started_at', $cols, true) ? 'started_at' : null);
  if ($timeCol) {
    $st = $db->prepare("DELETE FROM research_jobs WHERE $timeCol < :cut");
    $st->bindValue(':cut', $rsCutoff, SQLITE3_INTEGER);
    $st->execute();
    $out['research_jobs'] = $db->changes();
  }
}

$tbl2 = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='check_results'");
if ($tbl2) {
  $st = $db->prepare('DELETE FROM check_results WHERE run_at < :cut');
  $st->bindValue(':cut', $cutoffTs, SQLITE3_INTEGER);
  $st->execute();
  $out['check_results'] = $db->changes();
}

require '/usr/local/emhttp/plugins/unraid-vitals/include/kb_seed.php';
try { $out['kb_seeded_new'] = v_kb_seed(false); } catch (Throwable $e) { $out['kb_seeded_new'] = 'err:' . substr($e->getMessage(), 0, 60); }

fwrite(STDOUT, 'vitals-prune: ' . json_encode($out) . "\n");
exit(0);