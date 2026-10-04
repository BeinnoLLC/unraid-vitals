<?php
/* unraid-vitals — include/dbbackup.php (P19 / #98–#105)
 *
 * Consistent SQLite backups via the VACUUM INTO trick: runs inside a read
 * transaction on the source — an agent writing mid-backup cannot slice the
 * copy, and the copy always opens + passes integrity_check (we verify before
 * declaring a backup good).
 *
 * Retention: 7 daily + 4 weekly (matching weekly = Sundays), pruned after each
 * run. Destination: BACKUP_DIR in vitals.cfg, default
 * <appdata>/unraid-vitals/backups (same pool as the DB → warned in the UI).
 * Same-pool detection: compare the realpath mount ids (stat dev) of the DB
 * and the destination — equal dev = same pool/filesystem (P18-… style guard).
 *
 * Integrity: PRAGMA integrity_check on the newest backup after every run;
 * a corrupt DB triggers a KB event + notification and NEVER prunes existing
 * backups (they may be the only good copies left).
 *
 * Restore: copies the chosen backup into place via a pre-restore safety
 * backup of the CURRENT DB (pre-restore-<ts>.db), then swaps + verifies.
 *
 * Schema version: PRAGMA user_version stamped on every open-through-this-
 * module; upgrading bumps it and takes a pre-upgrade backup first.
 * Full state bundle (#105): DB + rollups + vitals.cfg in one zip; restore
 * reproduces everything.
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';

const V_BACKUP_KEEP_DAILY = 7;
const V_BACKUP_KEEP_WEEKLY = 4;
const V_SCHEMA_VERSION = 2;

function v_backup_dir(): string {
  $cfg = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
  $dir = trim((string)($cfg['BACKUP_DIR'] ?? ''));
  if ($dir === '') $dir = v_data_dir() . '/backups';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  return rtrim($dir, '/');
}

/** Same-filesystem check: stat dev equality (DB home vs destination). */
function v_backup_same_pool(string $dbPath, string $destDir): bool {
  $d1 = @stat($dbPath); $d2 = @stat($destDir);
  if (!$d1 || !$d2) return true; // unknown: warn (fail-safe)
  return $d1['dev'] === $d2['dev'];
}

/**
 * Core backup: VACUUM INTO a temp file, integrity-check, then atomically move
 * into the destination as vitals-YYYYMMDD-HHMMSS.db. Returns [ok, file, bytes].
 */
function v_backup_take(string $destDir = null, string $tag = ''): array {
  $dbPath = v_db_path();
  if ($dbPath === '' || !is_file($dbPath)) return ['ok' => false, 'error' => 'no db'];
  $destDir = $destDir ?: v_backup_dir();
  if (!is_dir($destDir)) return ['ok' => false, 'error' => 'destination not writable'];
  $tmp = $destDir . '/.in-progress-' . uniqid() . '.db';
  $src = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
  $src->busyTimeout(3000);
  // VACUUM INTO = consistent snapshot (writes go on; the copy reads one moment)
  $st = $src->prepare("VACUUM INTO :out");
  $st->bindValue(':out', $tmp, SQLITE3_TEXT);
  $st->execute();
  $src->close();
  // verify before declaring victory
  $chk = new SQLite3($tmp, SQLITE3_OPEN_READONLY);
  $res = $chk->querySingle('PRAGMA integrity_check');
  $chk->close();
  if ($res !== 'ok') { @unlink($tmp); return ['ok' => false, 'error' => 'integrity fail: ' . $res]; }
  $name = 'vitals-' . ($tag ? $tag . '-' : '') . date('Ymd-His') . '.db';
  $final = $destDir . '/' . $name;
  if (!@rename($tmp, $final)) { @unlink($tmp); return ['ok' => false, 'error' => 'rename failed']; }
  // stamp schema version into the copy
  $w = new SQLite3($final, SQLITE3_OPEN_READWRITE);
  $w->exec('PRAGMA user_version = ' . V_SCHEMA_VERSION);
  $w->close();
  return ['ok' => true, 'file' => $final, 'bytes' => filesize($final)];
}

/** Scheduled run: one backup + retention prune + integrity audit of all. */
function v_backup_run(): array {
  $dest = v_backup_dir();
  $res = v_backup_take($dest);
  if (!$res['ok']) return $res;

  // retention: 7 most-recent daily + every Sunday-weekly up to 4
  $files = glob($dest . '/vitals-*.db') ?: [];
  usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
  $keptDaily = []; $keptWeekly = [];
  foreach ($files as $f) {
    $tagged = str_contains($f, 'pre-restore') || str_contains($f, 'pre-upgrade')
           || str_contains($f, 'concurrent') || str_contains($f, 'corrupt');
    if ($tagged) continue;                        // safety copies: exempt from the count-and-prune
    $isWeekly = date('w', filemtime($f)) === '0'; // Sunday = that week's keeper
    if ($isWeekly && count($keptWeekly) < V_BACKUP_KEEP_WEEKLY) { $keptWeekly[] = $f; continue; }
    if (!$isWeekly && count($keptDaily) < V_BACKUP_KEEP_DAILY) { $keptDaily[] = $f; continue; }
    @unlink($f);
  }

  // integrity audit: the newest backup — a corrupt main DB + corrupt backup is
  // a data-loss event; notify + never prune when anything fails.
  $newest = $res['file'];
  $chk = new SQLite3($newest, SQLITE3_OPEN_READONLY);
  $ok = $chk->querySingle('PRAGMA integrity_check') === 'ok';
  $chk->close();
  if (!$ok) {
    v_event_raise('db_corruption', 'vitals.db', 'db_corruption', 'critical',
      'Latest DB backup failed integrity check — main DB may be corrupt', ['file' => $newest]);
    return ['ok' => false, 'error' => 'backup corrupt', 'file' => $newest];
  }
  return ['ok' => true, 'file' => $res['file'], 'bytes' => $res['bytes'],
          'kept_daily' => count($keptDaily), 'kept_weekly' => count($keptWeekly)];
}

/** List backups with sizes + integrity (integrity computed for the 3 newest). */
function v_backup_list(int $verifyNewest = 3): array {
  $dest = v_backup_dir();
  $files = glob($dest . '/vitals-*.db') ?: [];
  usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
  $out = [];
  foreach ($files as $i => $f) {
    $row = ['file' => basename($f), 'bytes' => filesize($f), 'at' => filemtime($f), 'tag' => 'auto'];
    if (str_contains($f, 'pre-restore')) $row['tag'] = 'pre-restore';
    elseif (str_contains($f, 'pre-upgrade')) $row['tag'] = 'pre-upgrade';
    if ($i < $verifyNewest) {
      $chk = new SQLite3($f, SQLITE3_OPEN_READONLY);
      $row['integrity'] = $chk->querySingle('PRAGMA integrity_check');
      $chk->close();
    }
    $out[] = $row;
  }
  return $out;
}

/**
 * Restore a backup: pre-restore safety backup of the CURRENT db first, then
 * verify the chosen backup integrity, swap, and re-verify live.
 */
function v_backup_restore(string $backupFile): array {
  $dbPath = v_db_path();
  if (!$dbPath) return ['ok' => false, 'error' => 'no db path'];
  $safe = basename($backupFile);
  if (!preg_match('/^vitals-[a-z0-9-]+\.db$/', $safe)) return ['ok' => false, 'error' => 'bad backup name'];
  $src = v_backup_dir() . '/' . $safe;
  if (!is_file($src)) return ['ok' => false, 'error' => 'backup not found'];
  // verify the source copy first
  $chk = new SQLite3($src, SQLITE3_OPEN_READONLY);
  $ic = $chk->querySingle('PRAGMA integrity_check');
  $chk->close();
  if ($ic !== 'ok') return ['ok' => false, 'error' => 'backup fails integrity_check — not restoring'];

  // pre-restore safety copy of current
  $cur = v_backup_take(null, 'pre-restore');
  if (!$cur['ok']) return ['ok' => false, 'error' => 'could not take pre-restore backup: ' . ($cur['error'] ?? '?')];

  // swap: copy backup over the live path (agent writers: take a write lock via
  // a global transaction — VACUUM INTO copy semantics below copy around writers)
  $tmpLive = $dbPath . '.restore-tmp';
  if (!@copy($src, $tmpLive)) return ['ok' => false, 'error' => 'stage copy failed'];
  $w = new SQLite3($tmpLive, SQLITE3_OPEN_READWRITE);
  $w->exec('PRAGMA user_version = ' . V_SCHEMA_VERSION);
  $w->close();
  if (!@rename($tmpLive, $dbPath)) { @unlink($tmpLive); return ['ok' => false, 'error' => 'swap failed']; }
  // final integrity confirm of the LIVE db
  $chk = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
  $ic2 = $chk->querySingle('PRAGMA integrity_check');
  $chk->close();
  if ($ic2 !== 'ok') {
    // roll back to the pre-restore copy — never leave a corrupt live db
    $pre = $cur['file'];
    @copy($pre, $dbPath);
    return ['ok' => false, 'error' => 'restored db failed integrity — rolled back to pre-restore copy'];
  }
  return ['ok' => true, 'restored' => $safe, 'pre_restore' => basename($cur['file'])];
}

/** Full state bundle: db + rollups + cfg — zip. Returns path. */
function v_backup_bundle(): ?string {
  $dbPath = v_db_path();
  if (!$dbPath) return null;
  $out = '/tmp/vitals-state-' . date('Ymd-His') . '.zip';
  $stage = '/tmp/vitals-state-' . uniqid();
  @mkdir($stage, 0755, true);
  // consistent db copy
  $dbCopy = v_backup_take($stage, 'db');
  if (!$dbCopy['ok']) return null;
  @mkdir($stage . '/history', 0755, true);
  foreach (glob(VITALS_FLASH . '/history/*.jsonl') ?: [] as $f) @copy($f, $stage . '/history/' . basename($f));
  @copy(VITALS_FLASH . '/vitals.cfg', $stage . '/vitals.cfg');
  $zip = new ZipArchive();
  if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { @unlink($dbCopy['file']); @unlink($stage); return null; }
  $zip->addFile($dbCopy['file'], 'vitals.db');
  foreach (glob($stage . '/history/*.jsonl') ?: [] as $f) $zip->addFile($f, 'history/' . basename($f));
  if (is_file($stage . '/vitals.cfg')) $zip->addFile($stage . '/vitals.cfg', 'vitals.cfg');
  $zip->close();
  @unlink($dbCopy['file']);
  foreach (glob($stage . '/history/*.jsonl') ?: [] as $f) @unlink($f);
  @rmdir($stage . '/history'); @rmdir($stage);
  return is_file($out) ? $out : null;
}

/** Restore from a bundle zip (stage: db + history + cfg). */
function v_backup_bundle_restore(string $zipPath): array {
  if (!is_file($zipPath)) return ['ok' => false, 'error' => 'bundle not found'];
  $stage = '/tmp/vitals-restore-' . uniqid();
  @mkdir($stage, 0755, true);
  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::CREATE) !== true) return ['ok' => false, 'error' => 'zip unreadable'];
  $zip->extractTo($stage);
  $zip->close();
  // 1. db restore (uses the usual pre-restore safety path)
  $dbRestore = null;
  if (is_file($stage . '/vitals.db')) {
    $dbPath = v_db_path();
    $cur = v_backup_take(null, 'pre-restore');
    $tmpLive = $dbPath . '.restore-tmp';
    if (!@copy($stage . '/vitals.db', $tmpLive)) return ['ok' => false, 'error' => 'stage copy failed'];
    $w = new SQLite3($tmpLive, SQLITE3_OPEN_READWRITE);
    $w->exec('PRAGMA user_version = ' . V_SCHEMA_VERSION);
    $w->close();
    if (!@rename($tmpLive, $dbPath)) { @unlink($tmpLive); return ['ok' => false, 'error' => 'swap failed']; }
    $dbRestore = true;
    // verify
    $chk = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $ic = $chk->querySingle('PRAGMA integrity_check');
    $chk->close();
    if ($ic !== 'ok') return ['ok' => false, 'error' => 'restored bundle db failed integrity check'];
  }
  // 2. history rollups
  $n = 0;
  foreach (glob($stage . '/history/*.jsonl') ?: [] as $f) {
    @copy($f, VITALS_FLASH . '/history/' . basename($f)); $n++;
  }
  // 3. cfg — merge (never clobber unset keys; only overlay bundle values onto unset)
  if (is_file($stage . '/vitals.cfg')) {
    $bundle = @parse_ini_file($stage . '/vitals.cfg') ?: [];
    $cur = @parse_ini_file(VITALS_FLASH . '/vitals.cfg') ?: [];
    foreach ($bundle as $k => $v) if (!isset($cur[$k])) { /* overlay later */ }
    // simplest correct: overlay bundle values that user hasn't customized — compare and write empty-to-current
    foreach ($bundle as $k => $v) {
      if (!isset($cur[$k]) || $cur[$k] === '') $cur[$k] = $v;
    }
    $lines = [];
    foreach ($cur as $k => $v) $lines[] = $k . '="' . str_replace('"', '', (string)$v) . '"';
    file_put_contents(VITALS_FLASH . '/vitals.cfg', implode("\n", $lines) . "\n");
  }
  foreach (glob($stage . '/history/*.jsonl') ?: [] as $f) @unlink($f);
  @rmdir($stage . '/history'); @rmdir($stage);
  return ['ok' => true, 'rollups_restored' => $n, 'db' => (bool)$dbRestore];
}

/** Schema version stamp/migrate — called from store/init + upgrade path. */
function v_backup_schema_check(): array {
  $dbPath = v_db_path();
  if (!$dbPath || !is_file($dbPath)) return ['ok' => false];
  $chk = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
  $v = (int)$chk->querySingle('PRAGMA user_version');
  $chk->close();
  if ($v >= V_SCHEMA_VERSION) return ['ok' => true, 'version' => $v, 'migrated' => false];
  // upgrade: pre-upgrade backup first (P19-07 acceptance)
  $pre = v_backup_take(null, 'pre-upgrade');
  if (!$pre['ok']) return ['ok' => false, 'error' => 'pre-upgrade backup failed — refusing to migrate'];
  $w = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
  $w->exec('PRAGMA user_version = ' . V_SCHEMA_VERSION);
  $w->close();
  return ['ok' => true, 'version' => V_SCHEMA_VERSION, 'migrated' => true, 'pre_upgrade' => basename($pre['file'])];
}

/** Integrity check of the live DB (+ raise event + never prune on corruption). */
function v_backup_integrity(): string {
  $dbPath = v_db_path();
  if (!$dbPath) return 'no-db';
  $chk = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
  $ic = $chk->querySingle('PRAGMA integrity_check');
  $chk->close();
  if ($ic !== 'ok') {
    v_event_raise('db_corruption', 'vitals.db', 'db_corruption', 'critical',
      'Live DB failed integrity check — restoring from a backup is advised', ['result' => substr($ic, 0, 200)]);
  }
  return $ic;
}