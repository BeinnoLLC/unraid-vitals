/**
 * unraid-vitals agent — SQLite findings store (node:sqlite, no native deps).
 *
 * One table, `findings`, holds every agent's output. The PHP side (ajax.php)
 * only ever reads this table — all LLM calls happen here, off the request
 * path, so the UI never blocks on inference.
 */
import { DatabaseSync } from 'node:sqlite';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const DB_PATH = process.env.VITALS_DB_PATH || '/var/tmp/unraid-vitals/vitals.db';

let db;
export function getDb() {
  if (db) return db;
  mkdirSync(dirname(DB_PATH), { recursive: true });
  db = new DatabaseSync(DB_PATH);
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
