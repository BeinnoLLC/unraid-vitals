/**
 * unraid-vitals agent — SQLite findings store (node:sqlite, no native deps).
 *
 * One table, `findings`, holds every agent's output. The PHP side (ajax.php)
 * only ever reads this table — all LLM calls happen here, off the request
 * path, so the UI never blocks on inference.
 */
import { DatabaseSync } from 'node:sqlite';
import { mkdirSync, existsSync, readFileSync, copyFileSync } from 'node:fs';
import { dirname } from 'node:path';

const STATE_DIR = process.env.VITALS_STATE_DIR || '/var/tmp/unraid-vitals';
const VITALS_CFG = process.env.VITALS_CFG || '/boot/config/plugins/unraid-vitals/vitals.cfg';
const DOCKER_CFG = process.env.VITALS_DOCKER_CFG || '/boot/config/docker.cfg';

/** One KEY="value" line from an Unraid .cfg file; '' when absent or unreadable. */
function cfgValue(file, key) {
  try {
    const m = readFileSync(file, 'utf8').match(new RegExp(`^${key}="?([^"\\r\\n]*)"?\\s*$`, 'm'));
    return m ? m[1].trim() : '';
  } catch {
    return '';
  }
}

/**
 * Where the DB lives. Persistent by default — the knowledge base has to
 * survive a reboot, so it cannot sit in the RAM-backed state dir. Mirrors
 * v_db_path() in include/store.php — keep the two in step:
 *   1. VITALS_DB_PATH env (full file path; for running outside the host layout)
 *   2. DATA_DIR in vitals.cfg, when set
 *   3. <Docker's appdata path>/unraid-vitals, /mnt/user/appdata by default
 *
 * Throws while the parent directory is missing (array stopped). Only our own
 * leaf directory is ever created: creating /mnt/user/... on an unmounted
 * array would silently write into RAM.
 */
export function resolveDbPath() {
  if (process.env.VITALS_DB_PATH) {
    mkdirSync(dirname(process.env.VITALS_DB_PATH), { recursive: true });
    return process.env.VITALS_DB_PATH;
  }
  let dir = cfgValue(VITALS_CFG, 'DATA_DIR');
  if (!dir) {
    const root = cfgValue(DOCKER_CFG, 'DOCKER_APP_CONFIG_PATH') || '/mnt/user/appdata';
    dir = `${root.replace(/\/+$/, '')}/unraid-vitals`;
  }
  dir = dir.replace(/\/+$/, '');
  if (!dir || !existsSync(dirname(dir))) {
    throw new Error(`data directory ${dir || '(unset)'} is not available — is the array started?`);
  }
  if (!existsSync(dir)) mkdirSync(dir, { mode: 0o755 });
  return `${dir}/vitals.db`;
}

let db;
export function getDb() {
  if (db) return db;
  const path = resolveDbPath();
  // One-time move off RAM: versions before this kept the DB in the state dir.
  const legacy = `${STATE_DIR}/vitals.db`;
  if (path !== legacy && !existsSync(path) && existsSync(legacy)) copyFileSync(legacy, path);
  db = new DatabaseSync(path);
  // Two independent cron-driven node processes (analyze.mjs hourly,
  // study.mjs every 5 min) can legitimately open this DB at overlapping
  // times. Default SQLite locking fails immediately with "database is
  // locked" on any overlap — WAL mode lets readers and a single writer
  // coexist, and a busy_timeout makes a genuine write/write collision
  // retry instead of erroring out immediately.
  db.exec(`PRAGMA journal_mode = WAL;`);
  db.exec(`PRAGMA busy_timeout = 5000;`);
  db.exec(`
    CREATE TABLE IF NOT EXISTS findings (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      agent TEXT NOT NULL,
      run_id TEXT NOT NULL,
      severity TEXT NOT NULL CHECK (severity IN ('ok','info','warning','error','critical')),
      title TEXT NOT NULL,
      detail TEXT,
      recommendation TEXT,
      subject TEXT,
      created_at INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_findings_agent_time ON findings(agent, created_at DESC);

    CREATE TABLE IF NOT EXISTS runs (
      run_id TEXT PRIMARY KEY,
      agent TEXT NOT NULL,
      started_at INTEGER NOT NULL,
      finished_at INTEGER,
      status TEXT NOT NULL DEFAULT 'running',
      error TEXT
    );

    CREATE TABLE IF NOT EXISTS share_comments (
      share TEXT PRIMARY KEY,
      comment TEXT NOT NULL,
      generated_at INTEGER NOT NULL,
      status TEXT NOT NULL DEFAULT 'done'
    );

    -- Knowledge base: every finding (and anything else worth remembering)
    -- lands here as a document. kb_fts is a full-text index over it —
    -- SQLite FTS5, no external search engine, no embeddings service
    -- required. "RAG" here means: FTS5 keyword retrieval over kb_documents
    -- to build context for a synthesis prompt, not a vector store — a
    -- deliberate scope choice for a plugin with no GPU and no external
    -- embedding API to call.
    CREATE TABLE IF NOT EXISTS kb_documents (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      source TEXT NOT NULL,        -- 'finding' | 'research' | 'manual'
      source_ref TEXT,             -- e.g. finding id, research job id
      topic TEXT,                  -- coarse grouping: agent name / free text
      title TEXT NOT NULL,
      content TEXT NOT NULL,       -- markdown — may include ![alt](chart.png) image refs
      kind TEXT NOT NULL DEFAULT 'note',  -- 'note' | 'report' | 'study' — richer kinds render
                                           -- as a full analysis/blog-post layout in the UI
      summary TEXT,                -- 1-2 sentence teaser shown in list views
      images TEXT,                 -- JSON array of {path, caption} — path relative to
                                    -- VITALS_STATE_DIR/kb-assets/, served by ajax.php
      created_at INTEGER NOT NULL
    );
    CREATE VIRTUAL TABLE IF NOT EXISTS kb_fts USING fts5(
      title, content, topic, content='kb_documents', content_rowid='id'
    );
    CREATE TRIGGER IF NOT EXISTS kb_ai AFTER INSERT ON kb_documents BEGIN
      INSERT INTO kb_fts(rowid, title, content, topic) VALUES (new.id, new.title, new.content, new.topic);
    END;
    CREATE TRIGGER IF NOT EXISTS kb_ad AFTER DELETE ON kb_documents BEGIN
      INSERT INTO kb_fts(kb_fts, rowid, title, content, topic) VALUES ('delete', old.id, old.title, old.content, old.topic);
    END;
    CREATE TRIGGER IF NOT EXISTS kb_au AFTER UPDATE ON kb_documents BEGIN
      INSERT INTO kb_fts(kb_fts, rowid, title, content, topic) VALUES ('delete', old.id, old.title, old.content, old.topic);
      INSERT INTO kb_fts(rowid, title, content, topic) VALUES (new.id, new.title, new.content, new.topic);
    END;

    -- Background research: a user-submitted prompt is answered
    -- asynchronously by RAG-over-kb_documents + a local model. May take
    -- a while on CPU-only inference — the UI polls, it never blocks on it.
    CREATE TABLE IF NOT EXISTS research_jobs (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      prompt TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'pending', -- pending|running|studying|done|error
      answer TEXT,
      sources TEXT,        -- JSON array of kb_documents ids used as context
      error TEXT,
      created_at INTEGER NOT NULL,
      started_at INTEGER,
      finished_at INTEGER,
      -- Study mode (P20-xx): "study the system for the next 12 hours" is not
      -- a question with one answer, it's a standing observation task. These
      -- columns are NULL/default for an ordinary one-shot research job.
      mode TEXT NOT NULL DEFAULT 'once',      -- 'once' | 'study'
      study_until INTEGER,                    -- unix ts the study ends
      tick_minutes INTEGER,                   -- how often study.mjs samples
      last_tick_at INTEGER,
      observations TEXT                       -- JSON array of {at, note}, the running journal
    );
    -- idx_research_study_due created after the additive migration below
    -- (this table pre-existed without the mode column on upgrades, so
    -- creating an index on it here — inside the same exec() as the
    -- CREATE TABLE — would fail before the ALTER ever runs).

    -- Event store: everything that HAPPENS (alert breach, agent finding,
    -- control action) becomes a typed, timestamped, auto-resolving event
    -- with frozen evidence. This is distinct from the KB: an event is a
    -- fact about what occurred; a lesson (below) is what we learned from
    -- it once it resolved.
    CREATE TABLE IF NOT EXISTS kb_events (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      kind TEXT NOT NULL,          -- 'temp' | 'fan_stall' | 'load' | 'fill' |
                                    -- 'smart' | 'memory' | 'container' | 'finding'
      entity TEXT NOT NULL,        -- the specific thing: disk name, sensor id,
                                    -- container name, agent name...
      alert_key TEXT,              -- matches v_check_alerts' edge-trigger key
                                    -- for temp/fan/load/fill/smart events, so
                                    -- the collector can resolve by key lookup
      severity TEXT NOT NULL CHECK (severity IN ('info','warning','alert','critical')),
      status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','resolved','superseded')),
      summary TEXT NOT NULL,
      evidence TEXT,                -- JSON: {series:[...], logs:[...], value, threshold}
      source TEXT,                  -- 'alert-engine' | 'agent:<name>' | 'manual'
      source_ref TEXT,              -- finding id, etc.
      started_at INTEGER NOT NULL,
      resolved_at INTEGER
    );
    CREATE INDEX IF NOT EXISTS idx_events_status ON kb_events(status, started_at DESC);
    CREATE INDEX IF NOT EXISTS idx_events_key ON kb_events(alert_key);

    -- Durable lessons distilled from resolved events. Recurring events of the
    -- same kind/entity merge into one lesson (times_seen++, confidence up)
    -- instead of duplicating.
    CREATE TABLE IF NOT EXISTS kb_lessons (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      kind TEXT NOT NULL,
      entity TEXT NOT NULL,
      lesson TEXT NOT NULL,
      confidence REAL NOT NULL DEFAULT 0.5,
      times_seen INTEGER NOT NULL DEFAULT 1,
      first_seen_at INTEGER NOT NULL,
      last_seen_at INTEGER NOT NULL,
      merged_from TEXT             -- JSON array of event ids that fed this lesson
    );
    CREATE INDEX IF NOT EXISTS idx_lessons_kind_entity ON kb_lessons(kind, entity);

    -- What was done about a specific resolved event: detection story, the
    -- action taken, and the outcome. One-to-one with an event.
    CREATE TABLE IF NOT EXISTS kb_solutions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      event_id INTEGER NOT NULL,
      lesson_id INTEGER,
      detection TEXT,
      action_taken TEXT,
      outcome TEXT NOT NULL DEFAULT 'unknown' CHECK (outcome IN ('worked','did_not_work','unknown')),
      created_at INTEGER NOT NULL,
      FOREIGN KEY (event_id) REFERENCES kb_events(id)
    );
    CREATE INDEX IF NOT EXISTS idx_solutions_event ON kb_solutions(event_id);
  `);

  // Additive migration for DBs created before study mode existed —
  // CREATE TABLE IF NOT EXISTS does not add columns to an existing table.
  const cols = new Set(db.prepare(`PRAGMA table_info(research_jobs)`).all().map(c => c.name));
  const addCol = (name, decl) => { if (!cols.has(name)) db.exec(`ALTER TABLE research_jobs ADD COLUMN ${name} ${decl}`); };
  addCol('mode', `TEXT NOT NULL DEFAULT 'once'`);
  addCol('study_until', 'INTEGER');
  addCol('tick_minutes', 'INTEGER');
  addCol('last_tick_at', 'INTEGER');
  addCol('observations', 'TEXT');
  db.exec(`CREATE INDEX IF NOT EXISTS idx_research_study_due ON research_jobs(mode, status, study_until);`);

  const kbCols = new Set(db.prepare(`PRAGMA table_info(kb_documents)`).all().map(c => c.name));
  const addKbCol = (name, decl) => { if (!kbCols.has(name)) db.exec(`ALTER TABLE kb_documents ADD COLUMN ${name} ${decl}`); };
  addKbCol('kind', `TEXT NOT NULL DEFAULT 'note'`);
  addKbCol('summary', 'TEXT');
  addKbCol('images', 'TEXT');

  return db;
}

export function startRun(agent, runId) {
  getDb().prepare(
    `INSERT INTO runs (run_id, agent, started_at, status) VALUES (?, ?, ?, 'running')`
  ).run(runId, agent, Math.floor(Date.now() / 1000));
}

export function finishRun(runId, status, error) {
  getDb().prepare(
    `UPDATE runs SET finished_at = ?, status = ?, error = ? WHERE run_id = ?`
  ).run(Math.floor(Date.now() / 1000), status, error || null, runId);
}

/**
 * Self-gating interval check for orchestrator-style crons (analyze.mjs,
 * diagnostics/update/vm agents). The cron itself can run as often as every
 * few minutes — this decides whether it's actually *time* to do the (slow,
 * LLM-driven) work, using the most recent completed run of `agentGroup`
 * rather than depending on cron's own schedule syntax. That means a user
 * changing the interval in Settings takes effect on the very next cron
 * tick, with no cron file to regenerate for the interval itself (only the
 * tick cadence, which install.sh keeps fixed and fine-grained).
 */
export function isDueForRun(agentGroup, intervalMinutes) {
  const row = getDb().prepare(
    `SELECT MAX(finished_at) AS last FROM runs WHERE agent = ? AND status != 'running'`
  ).get(agentGroup);
  if (!row || !row.last) return true;
  const dueAt = row.last + intervalMinutes * 60;
  return Math.floor(Date.now() / 1000) >= dueAt;
}

/** Replace an agent's prior findings with a fresh batch (one run = one snapshot). */
export function replaceFindings(agent, runId, findings) {
  const d = getDb();
  const tx = d.exec.bind(d);
  tx('BEGIN');
  try {
    d.prepare(`DELETE FROM findings WHERE agent = ?`).run(agent);
    const ins = d.prepare(
      `INSERT INTO findings (agent, run_id, severity, title, detail, recommendation, subject, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`
    );
    const now = Math.floor(Date.now() / 1000);
    for (const f of findings) {
      ins.run(agent, runId, f.severity, f.title, f.detail || null,
        f.recommendation || null, f.subject || null, now);
    }
    tx('COMMIT');
  } catch (e) {
    tx('ROLLBACK');
    throw e;
  }
}

export function setShareComment(share, comment, status = 'done') {
  getDb().prepare(
    `INSERT INTO share_comments (share, comment, generated_at, status) VALUES (?, ?, ?, ?)
     ON CONFLICT(share) DO UPDATE SET comment = excluded.comment,
       generated_at = excluded.generated_at, status = excluded.status`
  ).run(share, comment, Math.floor(Date.now() / 1000), status);
}

/** Push a finding into the knowledge base as a searchable document.
 *  Called right after replaceFindings() for anything above 'ok' severity —
 *  routine all-clear findings would just be noise in the KB. */
export function ingestFindingToKb(agent, finding) {
  const d = getDb();
  const title = `[${agent}] ${finding.title}`;
  const content = [finding.detail, finding.recommendation, finding.subject ? `Subject: ${finding.subject}` : '']
    .filter(Boolean).join('\n');
  d.prepare(
    `INSERT INTO kb_documents (source, source_ref, topic, title, content, kind, created_at) VALUES (?, ?, ?, ?, ?, 'note', ?)`
  ).run('finding', String(finding.id ?? ''), agent, title, content || finding.title, Math.floor(Date.now() / 1000));
}

/** Push a full-length report into the KB — a multi-paragraph analysis or
 *  study writeup, optionally with charts. `kind`: 'report' (one-shot
 *  research) or 'study' (a completed study-mode job). `images`: array of
 *  {path, caption}, paths relative to the kb-assets dir (see lib/charts.mjs). */
export function insertKbDocument({ source, sourceRef, topic, title, content, kind = 'report', summary, images }) {
  const d = getDb();
  const res = d.prepare(
    `INSERT INTO kb_documents (source, source_ref, topic, title, content, kind, summary, images, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`
  ).run(source, sourceRef != null ? String(sourceRef) : null, topic || null, title, content, kind,
        summary || null, images && images.length ? JSON.stringify(images) : null, Math.floor(Date.now() / 1000));
  return res.lastInsertRowid;
}

/** FTS5 keyword search over the knowledge base, newest match first within
 *  each relevance tier. bm25() ranking; simple sanitization so a raw user
 *  query (spaces, punctuation) doesn't blow up FTS5's own query syntax. */
export function searchKb(query, limit = 20) {
  const d = getDb();
  const safe = String(query).replace(/["*^]/g, ' ').trim().split(/\s+/).filter(Boolean)
    .map(w => `"${w}"`).join(' OR ');
  if (!safe) return [];
  try {
    return d.prepare(
      `SELECT d.id, d.source, d.source_ref, d.topic, d.title, d.content, d.kind, d.summary, d.images, d.created_at,
              bm25(kb_fts) AS rank
       FROM kb_fts JOIN kb_documents d ON d.id = kb_fts.rowid
       WHERE kb_fts MATCH ?
       ORDER BY rank LIMIT ?`
    ).all(safe, limit).map(parseKbImages);
  } catch {
    return []; // malformed FTS query from unusual input — fail soft, empty results
  }
}

function parseKbImages(row) {
  if (row && row.images) { try { row.images = JSON.parse(row.images); } catch { row.images = []; } }
  else if (row) row.images = [];
  return row;
}

export function recentKb(limit = 50) {
  return getDb().prepare(
    `SELECT id, source, source_ref, topic, title, content, kind, summary, images, created_at FROM kb_documents
     ORDER BY created_at DESC, id DESC LIMIT ?`
  ).all(limit).map(parseKbImages);
}

export function getKbDocument(id) {
  const row = getDb().prepare(
    `SELECT id, source, source_ref, topic, title, content, kind, summary, images, created_at
     FROM kb_documents WHERE id = ?`
  ).get(id);
  return row ? parseKbImages(row) : null;
}

export function kbTopics() {
  return getDb().prepare(
    `SELECT topic, COUNT(*) AS n, MAX(created_at) AS last FROM kb_documents
     WHERE topic IS NOT NULL GROUP BY topic ORDER BY last DESC`
  ).all();
}

export function createResearchJob(prompt) {
  const now = Math.floor(Date.now() / 1000);
  const res = getDb().prepare(
    `INSERT INTO research_jobs (prompt, status, mode, created_at) VALUES (?, 'pending', 'once', ?)`
  ).run(prompt, now);
  return res.lastInsertRowid;
}

/**
 * A standing "watch the system and tell me what's going on" request instead
 * of a one-shot question — e.g. "study the system for the next 12 hours".
 * study.mjs (the cron-driven runner) samples the live snapshot + recent
 * events/findings every `tickMinutes`, appends an observation, and once
 * `studyUntil` passes, synthesizes everything gathered into one findings
 * report (finishResearchJob, same as a one-shot job) so the UI treats a
 * finished study exactly like a finished research answer.
 */
export function createStudyJob(prompt, durationMinutes, tickMinutes = 15) {
  const now = Math.floor(Date.now() / 1000);
  const until = now + Math.max(5, durationMinutes) * 60;
  const res = getDb().prepare(
    `INSERT INTO research_jobs (prompt, status, mode, study_until, tick_minutes, observations, created_at)
     VALUES (?, 'studying', 'study', ?, ?, '[]', ?)`
  ).run(prompt, until, Math.max(1, tickMinutes), now);
  return res.lastInsertRowid;
}

/** Study jobs due for their next sample: still studying, and either never
 *  ticked or the last tick was >= tick_minutes ago. Used by the cron runner
 *  so it only wakes jobs that actually need a sample this pass. */
export function dueStudyJobs() {
  const now = Math.floor(Date.now() / 1000);
  return getDb().prepare(
    `SELECT * FROM research_jobs
     WHERE mode = 'study' AND status = 'studying'
       AND (last_tick_at IS NULL OR ? - last_tick_at >= tick_minutes * 60)`
  ).all(now);
}

/** Study jobs whose window has closed and are ready to be synthesized into
 *  a final answer. */
export function expiredStudyJobs() {
  const now = Math.floor(Date.now() / 1000);
  return getDb().prepare(
    `SELECT * FROM research_jobs WHERE mode = 'study' AND status = 'studying' AND study_until <= ?`
  ).all(now);
}

/** Append one observation to a study job's running journal and bump
 *  last_tick_at. Kept short by the caller — this is a log line, not the
 *  final report. */
export function appendStudyObservation(id, note) {
  const d = getDb();
  const row = d.prepare(`SELECT observations FROM research_jobs WHERE id = ?`).get(id);
  if (!row) return;
  let obs = [];
  try { obs = JSON.parse(row.observations || '[]'); } catch { obs = []; }
  obs.push({ at: Math.floor(Date.now() / 1000), note: String(note).slice(0, 500) });
  if (obs.length > 500) obs = obs.slice(-500); // hard cap — a 12h study at 5min ticks is 144 entries
  d.prepare(`UPDATE research_jobs SET observations = ?, last_tick_at = ? WHERE id = ?`)
    .run(JSON.stringify(obs), Math.floor(Date.now() / 1000), id);
}

export function startResearchJob(id) {
  getDb().prepare(`UPDATE research_jobs SET status = 'running', started_at = ? WHERE id = ?`)
    .run(Math.floor(Date.now() / 1000), id);
}

export function finishResearchJob(id, answer, sources) {
  getDb().prepare(
    `UPDATE research_jobs SET status = 'done', answer = ?, sources = ?, finished_at = ? WHERE id = ?`
  ).run(answer, JSON.stringify(sources || []), Math.floor(Date.now() / 1000), id);
}

export function failResearchJob(id, error) {
  getDb().prepare(`UPDATE research_jobs SET status = 'error', error = ?, finished_at = ? WHERE id = ?`)
    .run(String(error).slice(0, 2000), Math.floor(Date.now() / 1000), id);
}

export function getResearchJob(id) {
  const row = getDb().prepare(`SELECT * FROM research_jobs WHERE id = ?`).get(id);
  if (row && row.observations) { try { row.observations = JSON.parse(row.observations); } catch { row.observations = []; } }
  return row || null;
}

export function listResearchJobs(limit = 30) {
  return getDb().prepare(
    `SELECT id, prompt, status, mode, study_until, tick_minutes, last_tick_at, created_at, finished_at
     FROM research_jobs ORDER BY id DESC LIMIT ?`
  ).all(limit);
}

/* ------------------------------------------------------------------ events */

/** Create (or reuse) an open event for a given kind+entity(+alert_key).
 *  Idempotent per alert_key: a breach that is still firing every collector
 *  tick must not spawn a new event each minute — it updates the existing
 *  open one's evidence instead. Returns the event id. */
export function createOrTouchEvent({ kind, entity, alertKey, severity, summary, evidence, source, sourceRef }) {
  const d = getDb();
  const now = Math.floor(Date.now() / 1000);
  if (alertKey) {
    const open = d.prepare(
      `SELECT id FROM kb_events WHERE alert_key = ? AND status = 'open' LIMIT 1`
    ).get(alertKey);
    if (open) {
      d.prepare(`UPDATE kb_events SET evidence = ?, summary = ? WHERE id = ?`)
        .run(JSON.stringify(evidence || {}), summary, open.id);
      return open.id;
    }
  }
  const res = d.prepare(
    `INSERT INTO kb_events (kind, entity, alert_key, severity, status, summary, evidence, source, source_ref, started_at)
     VALUES (?, ?, ?, ?, 'open', ?, ?, ?, ?, ?)`
  ).run(kind, entity, alertKey || null, severity, summary, JSON.stringify(evidence || {}),
    source || 'alert-engine', sourceRef || null, now);
  return res.lastInsertRowid;
}

/** Resolve every open event for an alert_key (metric normalized). Returns
 *  the resolved event ids, so a caller can kick off distillation. */
export function resolveEventsByKey(alertKey) {
  const d = getDb();
  const now = Math.floor(Date.now() / 1000);
  const rows = d.prepare(`SELECT id FROM kb_events WHERE alert_key = ? AND status = 'open'`).all(alertKey);
  if (!rows.length) return [];
  d.prepare(`UPDATE kb_events SET status = 'resolved', resolved_at = ? WHERE alert_key = ? AND status = 'open'`)
    .run(now, alertKey);
  return rows.map(r => r.id);
}

export function getEvent(id) {
  return getDb().prepare(`SELECT * FROM kb_events WHERE id = ?`).get(id) || null;
}

export function listEvents({ status, kind, entity, sinceHours, limit = 100 } = {}) {
  const d = getDb();
  const clauses = [];
  const args = [];
  if (status) { clauses.push('status = ?'); args.push(status); }
  if (kind) { clauses.push('kind = ?'); args.push(kind); }
  if (entity) { clauses.push('entity = ?'); args.push(entity); }
  if (sinceHours) { clauses.push('started_at >= ?'); args.push(Math.floor(Date.now() / 1000) - sinceHours * 3600); }
  const where = clauses.length ? `WHERE ${clauses.join(' AND ')}` : '';
  args.push(limit);
  return d.prepare(
    `SELECT id, kind, entity, alert_key, severity, status, summary, source, started_at, resolved_at
     FROM kb_events ${where} ORDER BY started_at DESC LIMIT ?`
  ).all(...args);
}

/** Events that resolved but have no lesson/solution yet — the distiller's
 *  work queue. */
export function unlearnedResolvedEvents(limit = 20) {
  return getDb().prepare(
    `SELECT e.* FROM kb_events e
     LEFT JOIN kb_solutions s ON s.event_id = e.id
     WHERE e.status = 'resolved' AND s.id IS NULL
     ORDER BY e.resolved_at ASC LIMIT ?`
  ).all(limit);
}

/* ----------------------------------------------------------- lessons/solutions */

export function upsertLesson({ kind, entity, lesson, eventId }) {
  const d = getDb();
  const now = Math.floor(Date.now() / 1000);
  const existing = d.prepare(`SELECT * FROM kb_lessons WHERE kind = ? AND entity = ?`).get(kind, entity);
  if (existing) {
    const merged = JSON.parse(existing.merged_from || '[]');
    if (eventId && !merged.includes(eventId)) merged.push(eventId);
    d.prepare(
      `UPDATE kb_lessons SET lesson = ?, confidence = MIN(0.98, confidence + 0.08),
       times_seen = times_seen + 1, last_seen_at = ?, merged_from = ? WHERE id = ?`
    ).run(lesson, now, JSON.stringify(merged), existing.id);
    return existing.id;
  }
  const res = d.prepare(
    `INSERT INTO kb_lessons (kind, entity, lesson, confidence, times_seen, first_seen_at, last_seen_at, merged_from)
     VALUES (?, ?, ?, 0.5, 1, ?, ?, ?)`
  ).run(kind, entity, lesson, now, now, JSON.stringify(eventId ? [eventId] : []));
  return res.lastInsertRowid;
}

export function addSolution({ eventId, lessonId, detection, actionTaken, outcome }) {
  const d = getDb();
  return d.prepare(
    `INSERT INTO kb_solutions (event_id, lesson_id, detection, action_taken, outcome, created_at)
     VALUES (?, ?, ?, ?, ?, ?)`
  ).run(eventId, lessonId || null, detection || null, actionTaken || null,
    outcome || 'unknown', Math.floor(Date.now() / 1000)).lastInsertRowid;
}

export function lessonsFor(kind, entity) {
  return getDb().prepare(
    `SELECT * FROM kb_lessons WHERE kind = ? AND entity = ? ORDER BY confidence DESC, last_seen_at DESC`
  ).all(kind, entity);
}

export function listLessons(limit = 100) {
  return getDb().prepare(
    `SELECT * FROM kb_lessons ORDER BY last_seen_at DESC LIMIT ?`
  ).all(limit);
}

export function listSolutions(limit = 100) {
  return getDb().prepare(
    `SELECT s.*, e.summary AS event_summary, e.kind, e.entity FROM kb_solutions s
     JOIN kb_events e ON e.id = s.event_id ORDER BY s.created_at DESC LIMIT ?`
  ).all(limit);
}

export function setSolutionOutcome(id, outcome) {
  getDb().prepare(`UPDATE kb_solutions SET outcome = ? WHERE id = ?`).run(outcome, id);
}
