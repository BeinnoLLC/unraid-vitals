<?php
/* unraid-vitals — include/kb_seed.php (P20-11 / #115 part)
 *
 * Seeds the knowledge base with trusted Unraid troubleshooting documents:
 *   1. The shipped fix playbooks (docs/playbooks.md — every check's
 *      what/confirm/fix/not-to-do, trusted local doc).
 *   2. A curated base of common Unraid failure modes (kept inline here —
 *      small, factual, non-version-specific).
 *
 * Seeding is idempotent: source='seeded', a source_ref anchor per doc; an
 * existing row is updated (not duplicated). Runs once per plugin version
 * (seed marker holds the last seeded version) or on --force.
 */

declare(strict_types=1);

require_once __DIR__ . '/playbooks.php';
require_once __DIR__ . '/store.php';

const V_KB_SEED_VERSION = '1';

function v_kb_seed_marker(): ?int {
  $db = v_events_db();
  if (!$db) return null;
  $db->exec("CREATE TABLE IF NOT EXISTS kb_seed (id INTEGER PRIMARY KEY CHECK (id = 1), version TEXT NOT NULL, at INTEGER NOT NULL)");
  $row = $db->querySingle('SELECT version, at FROM kb_seed WHERE id = 1', true);
  return $row ? (int)$row['at'] : null;
}

/** @return int number of seeded/updated documents */
function v_kb_seed(bool $force = false): int {
  $db = v_events_db();
  if (!$db) return 0;
  $lastAt = v_kb_seed_marker();
  if (!$force && $lastAt !== null && (time() - $lastAt) < 604800) return 0; // weekly refresh cadence

  $now = time();
  $db->exec("CREATE TABLE IF NOT EXISTS kb_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, source_ref TEXT,
    topic TEXT, title TEXT NOT NULL, content TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'note', summary TEXT, images TEXT,
    severity TEXT NOT NULL DEFAULT 'medium', tags TEXT NOT NULL DEFAULT '',
    times_seen INTEGER NOT NULL DEFAULT 1, last_seen_at INTEGER, created_at INTEGER NOT NULL)");
  $db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS kb_fts USING fts5(title, content, topic, content='kb_documents', content_rowid='id')");
  try { $db->exec("CREATE TRIGGER IF NOT EXISTS kb_ai AFTER INSERT ON kb_documents BEGIN INSERT INTO kb_fts(rowid, title, content, topic) VALUES (new.id, new.title, new.content, new.topic); END"); } catch (Throwable $e) {}
  try { $db->exec("CREATE TRIGGER IF NOT EXISTS kb_au AFTER UPDATE ON kb_documents BEGIN INSERT INTO kb_fts(kb_fts, rowid, title, content, topic) VALUES ('delete', old.id, old.title, old.content, old.topic); INSERT INTO kb_fts(rowid, title, content, topic) VALUES (new.id, new.title, new.content, new.topic); END"); } catch (Throwable $e) {}

  $n = 0;

  // 1. fix playbooks (the exact text that ships with the plugin)
  foreach (v_playbooks_all() as $anchor => $pb) {
    $title = 'Playbook: ' . $pb['title'];
    $content = $pb['body'];
    $st = $db->prepare("SELECT id FROM kb_documents WHERE source='seeded' AND source_ref=? LIMIT 1");
    $st->bindValue(1, 'playbook:' . $anchor, SQLITE3_TEXT);
    $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row) {
      $u = $db->prepare("UPDATE kb_documents SET content=?, title=?, last_seen_at=? WHERE id=?");
      $u->bindValue(1, $content, SQLITE3_TEXT);
      $u->bindValue(2, $title, SQLITE3_TEXT);
      $u->bindValue(3, $now, SQLITE3_INTEGER);
      $u->bindValue(4, (int)$row['id'], SQLITE3_INTEGER);
      $u->execute();
      // re-index FTS
      $db->exec("INSERT INTO kb_fts(kb_fts, rowid, title, content, topic) VALUES ('delete', {$row['id']}, '', '', '')");
    } else {
      $i = $db->prepare("INSERT INTO kb_documents (source, source_ref, topic, title, content, kind, summary, severity, tags, created_at) VALUES ('seeded', ?, 'playbooks', ?, ?, 'report', ?, 'medium', 'documentation,unraid', ?)");
      $i->bindValue(1, 'playbook:' . $anchor, SQLITE3_TEXT);
      $i->bindValue(2, $title, SQLITE3_TEXT);
      $i->bindValue(3, $content, SQLITE3_TEXT);
      $i->bindValue(4, substr(strip_tags($content), 0, 160), SQLITE3_TEXT);
      $i->bindValue(5, $now, SQLITE3_INTEGER);
      $i->execute();
      $n++;
    }
  }

  // 2. curated base knowledge (common failure modes, stable facts)
  $base = [
    ['disk_failed', 'What a failed disk looks like', "SMART health FAILED, growing reallocated/pending sectors, or unmountable filesystem all count as a failing disk.\n\nSteps: 1) copy whatever reads off the disk now; 2) do NOT remove the disk from a started array; 3) Tools → New Perms is not a fix; 4) with parity valid, replace at a scheduled window and let rebuild run.\n\nNever pull a parity disk while the array is started."],
    ['docker_img_full', 'docker.img full — symptoms and fix', "Symptoms: containers fail to start or crash with 'no space left on device', Docker tab slow.\n\nFix: delete unused images/dangling layers (Cleanup tab), shrink container logs, fix containers writing inside the image instead of mapped volumes.\n\nIf the img itself is oversized you must recreate it — back the list of images up first (docker save)."],
    ['unraid_flash_ro', 'Flash drive remounted read-only', "Symptoms: plugin/setting changes silently revert after reboot; a 'read-only file system' error in syslog.\n\nCause: the USB stick has write errors. Reboot (safe), check dmesg for usb/sd errors, replace the stick if it repeats. Back up /boot first."],
    ['unclean_shutdown', 'Unclean shutdown consequences', "After an unclean shutdown Unraid schedules a correcting parity check on the next array start. Let it finish; parity is unverified until it does.\n\nInvestigate the cause (power, UPS, kernel panic) or it repeats."],
    ['network_slow_link', 'A port renegotiated slower than its best-seen speed', "A 10G/1G port sitting at 100Mb is almost always cabling/switch-port/cable-length — not the NIC.\n\nFix: reseat or replace the cable first, try a different switch port, then suspect the NIC."],
    ['cache_stuck_files', 'Files stuck on the wrong tier', "Mover moves pool→array for cache=yes/prefer shares, never array→pool. Files on cache for a cache=no share get moved by the next mover run; files on array for a cache=only share need manual copy.\n\nThe Shares page shows the effective Use Cache setting per share."],
    ['parity_history_meaning', 'Reading the parity check history', "Speed trend matters more than any single number: a steady decline across checks suggests a disk or the controller, not always the slowest disk.\n\nErrors>0 needs a corrective action — the next correcting check should return to 0; check SMART of the suspect disks."],
    ['appdata_on_array', 'appdata on the array = slow containers', "Containers with their databases (SQLite!) under /mnt/user/appdata get through the FUSE layer — noticeably slower than a pool path and at risk during unclean shutdowns.\n\nPreferred: /mnt/cache/appdata (a pool), or /mnt/user0 for less critical stuff."],
  ];
  foreach ($base as [$ref, $title, $content]) {
    $st = $db->prepare("SELECT id FROM kb_documents WHERE source='seeded' AND source_ref=? LIMIT 1");
    $st->bindValue(1, 'base:' . $ref, SQLITE3_TEXT);
    $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
      $i = $db->prepare("INSERT INTO kb_documents (source, source_ref, topic, title, content, kind, summary, severity, tags, created_at) VALUES ('seeded', ?, 'foundations', ?, ?, 'report', ?, 'high', 'documentation,unraid,baseline', ?)");
      $i->bindValue(1, 'base:' . $ref, SQLITE3_TEXT);
      $i->bindValue(2, $title, SQLITE3_TEXT);
      $i->bindValue(3, $content, SQLITE3_TEXT);
      $i->bindValue(4, substr(strip_tags($content), 0, 160), SQLITE3_TEXT);
      $i->bindValue(5, $now, SQLITE3_INTEGER);
      $i->execute();
      $n++;
    }
  }

  // stamp the marker
  $db->exec("INSERT INTO kb_seed (id, version, at) VALUES (1, '" . V_KB_SEED_VERSION . "', $now)
             ON CONFLICT(id) DO UPDATE SET version='" . V_KB_SEED_VERSION . "', at=$now");
  return $n;
}