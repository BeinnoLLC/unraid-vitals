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
      content TEXT NOT NULL,
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
      status TEXT NOT NULL DEFAULT 'pending', -- pending|running|done|error
      answer TEXT,
      sources TEXT,        -- JSON array of kb_documents ids used as context
      error TEXT,
      created_at INTEGER NOT NULL,
      started_at INTEGER,
      finished_at INTEGER
    );
  `);
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
    `INSERT INTO kb_documents (source, source_ref, topic, title, content, created_at) VALUES (?, ?, ?, ?, ?, ?)`
  ).run('finding', String(finding.id ?? ''), agent, title, content || finding.title, Math.floor(Date.now() / 1000));
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
      `SELECT d.id, d.source, d.source_ref, d.topic, d.title, d.content, d.created_at,
              bm25(kb_fts) AS rank
       FROM kb_fts JOIN kb_documents d ON d.id = kb_fts.rowid
       WHERE kb_fts MATCH ?
       ORDER BY rank LIMIT ?`
    ).all(safe, limit);
  } catch {
    return []; // malformed FTS query from unusual input — fail soft, empty results
  }
}

export function recentKb(limit = 50) {
  return getDb().prepare(
    `SELECT id, source, source_ref, topic, title, content, created_at FROM kb_documents
     ORDER BY created_at DESC, id DESC LIMIT ?`
  ).all(limit);
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
    `INSERT INTO research_jobs (prompt, status, created_at) VALUES (?, 'pending', ?)`
  ).run(prompt, now);
  return res.lastInsertRowid;
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
  return getDb().prepare(`SELECT * FROM research_jobs WHERE id = ?`).get(id) || null;
}

export function listResearchJobs(limit = 30) {
  return getDb().prepare(
    `SELECT id, prompt, status, created_at, finished_at FROM research_jobs ORDER BY id DESC LIMIT ?`
  ).all(limit);
}
