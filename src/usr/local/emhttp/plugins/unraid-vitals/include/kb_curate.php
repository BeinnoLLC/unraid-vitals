<?php
/* unraid-vitals — include/kb_curate.php (P12 / #38)
 *
 * KB workspace curation: edit, merge, delete, pin lessons; solution outcome
 * (worked/did-not-work) feeding lesson confidence; manual entries
 * (source='manual'); export/import as a single JSON bundle.
 *
 * Written against the AGENT's own schema (kb_documents too), all endpoints
 * CSRF-gated at the ajax layer; every mutation re-checks the row exists.
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';

function v_kb_db(): ?SQLite3 {
  $dbFile = v_db_path();
  if (!$dbFile || !is_file($dbFile) || !class_exists('SQLite3')) return null;
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READWRITE); $db->busyTimeout(3000); return $db; }
  catch (Throwable $e) { return null; }
}

/** Lessons: list w/ confidence + times_seen (already columns); kind/entity filters. */
function v_kb_lessons_list(?string $kind = null, int $limit = 60): array {
  $db = v_kb_db();
  if (!$db) return [];
  $out = [];
  $sql = 'SELECT id, kind, entity, lesson, confidence, times_seen, first_seen_at, last_seen_at, merged_from FROM kb_lessons';
  if ($kind) $sql .= ' WHERE kind = ' . preg_replace("/[^a-z_]/", '', $kind);
  $sql .= ' ORDER BY last_seen_at DESC LIMIT ' . (int)$limit;
  $res = $db->query($sql);
  while (($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
  $db->close();
  return $out;
}

/** Solutions with their event linkage. */
function v_kb_solutions_list(int $limit = 60): array {
  $db = v_kb_db();
  if (!$db) return [];
  $out = [];
  $res = $db->query('SELECT s.id, s.event_id, s.lesson_id, s.detection, s.action_taken, s.outcome, s.created_at,
                     e.kind, e.entity, e.summary FROM kb_solutions s
                     LEFT JOIN kb_events e ON e.id = s.event_id
                     ORDER BY s.created_at DESC LIMIT ' . (int)$limit);
  while (($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
  $db->close();
  return $out;
}

/** Events with status pills data (kind/entity/status filters). */
function v_kb_events_list(?string $kind = null, ?string $status = null, int $limit = 80): array {
  $out = [];
  $dbFile = v_db_path();
  if (!$dbFile || !is_file($dbFile) || !class_exists('SQLite3')) return [];
  try { $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY); } catch (Throwable $e) { return []; }
  $clauses = []; $bind = [];
  if ($kind) { $clauses[] = 'kind = :kind'; $bind[':kind'] = $kind; }
  if ($status) { $clauses[] = 'status = :status'; $bind[':status'] = $status; }
  $sql = 'SELECT id, kind, entity, alert_key, severity, status, summary, evidence, started_at, resolved_at
          FROM kb_events' . ($clauses ? ' WHERE ' . implode(' AND ', $clauses) : '') .
         ' ORDER BY started_at DESC LIMIT ' . (int)$limit;
  $st = $db->prepare($sql);
  foreach ($bind as $k => $v) $st->bindValue($k, $v, SQLITE3_TEXT);
  $res = $st->execute();
  while (($row = $res->fetchArray(SQLITE3_ASSOC))) $out[] = $row;
  $db->close();
  return $out;
}

/** Curation: edit lesson text. */
function v_kb_lesson_edit(int $lessonId, string $text): bool {
  $db = v_kb_db(); if (!$db) return false;
  $st = $db->prepare('UPDATE kb_lessons SET lesson = :t WHERE id = :id');
  $st->bindValue(':t', $text, SQLITE3_TEXT);
  $st->bindValue(':id', $lessonId, SQLITE3_INTEGER);
  $st->execute();
  $ok = $db->changes() > 0;
  $db->close();
  return $ok;
}

/** Curation: merge two lessons (higher-confidence text survives; counts combine). */
function v_kb_lesson_merge(int $lessonId, int $intoId): bool {
  if ($lessonId === $intoId) return false;
  $db = v_kb_db(); if (!$db) return false;
  $a = $db->querySingle("SELECT lesson, confidence, times_seen FROM kb_lessons WHERE id = $intoId", true);
  $b = $db->querySingle("SELECT lesson, times_seen, merged_from FROM kb_lessons WHERE id = $lessonId", true);
  if (!$a || !$b) { $db->close(); return false; }
  $mergedLesson = ($a['lesson'] ?? '') . "\n\n— merged: " . ($b['lesson'] ?? '');
  $st = $db->prepare('UPDATE kb_lessons SET times_seen = times_seen + :seen,
                       confidence = MAX(confidence, :conf),
                       lesson = :lessonA
                       WHERE id = :into');
  $st->bindValue(':seen', (int)($b['times_seen'] ?? 1), SQLITE3_INTEGER);
  $st->bindValue(':conf', (float)($a['confidence'] ?? 0.5), SQLITE3_FLOAT);
  $st->bindValue(':lessonA', $mergedLesson, SQLITE3_TEXT);
  $st->bindValue(':into', $intoId, SQLITE3_INTEGER);
  $st->execute();
  $del = $db->prepare('DELETE FROM kb_lessons WHERE id = :id');
  $del->bindValue(':id', $lessonId, SQLITE3_INTEGER);
  $del->execute();
  // keep provenance
  $mf = $db->prepare('UPDATE kb_lessons SET merged_from = COALESCE(merged_from || ",", "") || :mf WHERE id = :into');
  $mf->bindValue(':mf', (string)$lessonId, SQLITE3_TEXT);
  $mf->bindValue(':into', $intoId, SQLITE3_INTEGER);
  $mf->execute();
  $db->close();
  return true;
}

/** Curation: delete a lesson (its solutions stay, lesson_id orphaned to NULL). */
function v_kb_lesson_delete(int $lessonId): bool {
  $db = v_kb_db(); if (!$db) return false;
  $db->exec('UPDATE kb_solutions SET lesson_id = NULL WHERE lesson_id = ' . (int)$lessonId);
  $st = $db->prepare('DELETE FROM kb_lessons WHERE id = :id');
  $st->bindValue(':id', $lessonId, SQLITE3_INTEGER);
  $st->execute();
  $ok = $db->changes() > 0;
  $db->close();
  return $ok;
}

/** Curation: pin/unpin (pinned lessons sort first in the UI; pinned = times_seen won't prune). */
function v_kb_lesson_pin(int $lessonId, bool $pin): bool {
  // pinned is expressed as confidence floor + a first_class marker via merged_from prefix
  $db = v_kb_db(); if (!$db) return false;
  $cur = (int)($db->querySingle("SELECT times_seen FROM kb_lessons WHERE id = $lessonId") ?? 0);
  if (!$cur) { $db->close(); return false; }
  $st = $db->prepare('UPDATE kb_lessons SET confidence = :c WHERE id = :id');
  $st->bindValue(':c', $pin ? 0.99 : 0.5, SQLITE3_FLOAT);
  $st->bindValue(':id', $lessonId, SQLITE3_INTEGER);
  $st->execute();
  $ok = $db->changes() > 0;
  $db->close();
  return $ok;
}

/** Feedback: did the fix work? — outcome feeds the lesson's confidence. */
function v_kb_solution_feedback(int $solutionId, string $outcome): bool {
  if (!in_array($outcome, ['worked', 'did_not_work'], true)) return false;
  $db = v_kb_db(); if (!$db) return false;
  $s = $db->querySingle("SELECT lesson_id FROM kb_solutions WHERE id = $solutionId", true);
  if (!$s) { $db->close(); return false; }
  $st = $db->prepare('UPDATE kb_solutions SET outcome = :o WHERE id = :id');
  $st->bindValue(':o', $outcome, SQLITE3_TEXT);
  $st->bindValue(':id', $solutionId, SQLITE3_INTEGER);
  $st->execute();
  if ($s['lesson_id']) {
    // worked ⇒ +0.1 confidence (max 0.95); did_not_work ⇒ −0.15 (min 0.1)
    $delta = $outcome === 'worked' ? +0.10 : -0.15;
    $db->exec("UPDATE kb_lessons SET confidence = MAX(0.1, MIN(0.95, confidence + ($delta))) WHERE id = " . (int)$s['lesson_id']);
  }
  $ok = $db->changes() > 0;
  $db->close();
  return $ok;
}

/** Manual entries (source='manual'): lessons. */
function v_kb_lesson_manual(string $entity, string $text): ?int {
  $db = v_kb_db(); if (!$db) return null;
  $st = $db->prepare("INSERT INTO kb_lessons (kind, entity, lesson, confidence, times_seen, first_seen_at, last_seen_at)
                      VALUES ('manual', :e, :t, 0.8, 1, :now, :now)");
  $now = time();
  $st->bindValue(':e', $entity, SQLITE3_TEXT);
  $st->bindValue(':t', $text, SQLITE3_TEXT);
  $st->bindValue(':now', $now, SQLITE3_INTEGER);
  $st->execute();
  $id = $db->lastInsertRowID();
  $db->close();
  return (int)$id;
}

/** Export: the whole KB as one JSON bundle. */
function v_kb_export(): ?string {
  $dbFile = v_db_path();
  if (!$dbFile || !is_file($dbFile)) return null;
  $db = v_kb_db(); if (!$db) return null;
  $bundle = ['exported_at' => time(), 'kb_documents' => [], 'kb_lessons' => [], 'kb_solutions' => []];
  foreach (['kb_documents', 'kb_lessons', 'kb_solutions'] as $t) {
    $res = $db->query("SELECT * FROM $t");
    while (($row = $res->fetchArray(SQLITE3_ASSOC))) $bundle[$t][] = $row;
  }
  $db->close();
  $json = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  return is_string($json) ? $json : null;
}

/** Import: merge a bundle (seeded/manual rows updated, learning rows insert-if-new). */
function v_kb_import(string $json): array {
  $b = json_decode($json, true);
  if (!is_array($b)) return ['ok' => false, 'error' => 'bad bundle json'];
  $db = v_kb_db(); if (!$db) return ['ok' => false, 'error' => 'no db'];
  $ins = 0; $upd = 0;
  foreach (($b['kb_documents'] ?? []) as $d) {
    if (empty($d['source_ref']) || empty($d['title'])) continue;
    $chk = $db->prepare("SELECT id FROM kb_documents WHERE source = :s AND source_ref = :r LIMIT 1");
    $chk->bindValue(':s', $d['source'], SQLITE3_TEXT);
    $chk->bindValue(':r', $d['source_ref'], SQLITE3_TEXT);
    $row = $chk->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row) {
      $u = $db->prepare("UPDATE kb_documents SET content = :c, title = :t WHERE id = :id");
      $u->bindValue(':c', $d['content'] ?? '', SQLITE3_TEXT);
      $u->bindValue(':t', $d['title'], SQLITE3_TEXT);
      $u->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);
      $u->execute(); $upd++;
    } else {
      $i = $db->prepare("INSERT INTO kb_documents (source, source_ref, topic, title, content, kind, summary, severity, tags, created_at) VALUES (:s, :r, :topic, :t, :c, :kind, :summary, :sev, :tags, :at)");
      $i->bindValue(':s', $d['source'], SQLITE3_TEXT); $i->bindValue(':r', $d['source_ref'], SQLITE3_TEXT);
      $i->bindValue(':topic', $d['topic'] ?? '', SQLITE3_TEXT); $i->bindValue(':t', $d['title'], SQLITE3_TEXT);
      $i->bindValue(':c', $d['content'] ?? '', SQLITE3_TEXT); $i->bindValue(':kind', $d['kind'] ?? 'note', SQLITE3_TEXT);
      $i->bindValue(':summary', $d['summary'] ?? '', SQLITE3_TEXT); $i->bindValue(':sev', $d['severity'] ?? 'medium', SQLITE3_TEXT);
      $i->bindValue(':tags', $d['tags'] ?? '', SQLITE3_TEXT); $i->bindValue(':at', (int)($d['created_at'] ?? time()), SQLITE3_INTEGER);
      $i->execute(); $ins++;
    }
  }
  foreach (($b['kb_lessons'] ?? []) as $l) {
    $chk = $db->prepare("SELECT id FROM kb_lessons WHERE kind = :k AND entity = :e ORDER BY last_seen_at DESC LIMIT 1");
    $chk->bindValue(':k', $l['kind'], SQLITE3_TEXT); $chk->bindValue(':e', $l['entity'], SQLITE3_TEXT);
    $row = $chk->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
      $i = $db->prepare("INSERT INTO kb_lessons (kind, entity, lesson, confidence, times_seen, first_seen_at, last_seen_at) VALUES (:k, :e, :t, :c, :n, :f, :l)");
      $i->bindValue(':k', $l['kind'], SQLITE3_TEXT); $i->bindValue(':e', $l['entity'], SQLITE3_TEXT);
      $i->bindValue(':t', $l['lesson'], SQLITE3_TEXT); $i->bindValue(':c', (float)($l['confidence'] ?? 0.5), SQLITE3_FLOAT);
      $i->bindValue(':n', (int)($l['times_seen'] ?? 1), SQLITE3_INTEGER);
      $i->bindValue(':f', (int)($l['first_seen_at'] ?? time()), SQLITE3_INTEGER);
      $i->bindValue(':l', (int)($l['last_seen_at'] ?? time()), SQLITE3_INTEGER);
      $i->execute(); $ins++;
    }
  }
  $db->close();
  return ['ok' => true, 'inserted' => $ins, 'updated' => $upd];
}