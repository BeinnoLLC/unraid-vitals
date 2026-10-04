#!/usr/bin/env node
/* unraid-vitals — scripts/kb-eval.mjs (P11 eval, #37)
 *
 * Golden set of query → expected-doc anchors, run against the LIVE KB on the
 * reference DB, reporting recall@5 for BOTH retrieval modes:
 *   - bm25 (searchKb, the baseline)
 *   - hybrid (hybridSearchKb w/ embeddings + rerank)
 * plus the delta. Exit 0 when hybrid ≥ baseline; 1 otherwise (soft-fail:
 * no endpoint → both modes degrade and hybrid==baseline).
 *
 * Usage: node scripts/kb-eval.mjs [--backfill] [--no-rerank]
 */
process.env.VITALS_DB_PATH = process.env.VITALS_DB_PATH || '/mnt/user/appdata/unraid-vitals/vitals.db';

import { getDb } from '../lib/db.mjs';
import { hybridSearchKb, backfillEmbeddings } from '../lib/hybrid-retrieval.mjs';

const GOLDEN = [
  // query → expected title fragments (any match counts as a hit)
  ['RAM spike on the server', ['memory', 'RAM', 'mem']],
  ['memory pressure', ['memory', 'RAM', 'swap']],
  ['why is my cache pool hot', ['temp', 'thermal', 'hot', 'cool']],
  ['disk making errors', ['SMART', 'smart', 'reallocated', 'sector', 'failed']],
  ['container keeps restarting', ['crash', 'restart', 'container']],
  ['parity check slow', ['parity', 'speed', 'check']],
  ['network speed dropped', ['network', 'link', 'speed', 'cable']],
  ['share files in wrong place', ['share', 'tier', 'mover', 'cache']],
  ['flash drive readonly', ['flash', 'read-only', 'boot']],
  ['docker disk full', ['docker.img', 'docker', 'image', 'writable']],
];

const db = getDb();
const total = db.prepare(`SELECT COUNT(*) n FROM kb_documents`).get().n;
console.log(`kb-eval: ${total} documents in the KB`);

if (process.argv.includes('--backfill')) {
  const r = await backfillEmbeddings(60);
  console.log('backfill:', r);
}

const score = async (mode) => {
  let hits = 0;
  for (const [q, frags] of GOLDEN) {
    const rows = mode === 'bm25'
      ? db.prepare(`SELECT d.id, d.title FROM kb_fts JOIN kb_documents d ON d.id = kb_fts.rowid
                    WHERE kb_fts MATCH ? LIMIT 5`).all(q.split(/\s+/).map(w => `"${w}"`).join(' OR '))
      : await hybridSearchKb(q, 5, { rerank: !process.argv.includes('--no-rerank') });
    const blob = rows.map(r => `${r.title} ${r.topic ?? ''}`).join(' ').toLowerCase();
    const hit = frags.some(f => blob.includes(f.toLowerCase()));
    console.log(`  [${mode}] ${q} → ${hit ? 'HIT' : 'miss'}${rows[0] ? ` (top: ${rows[0].title})` : ''}`);
    if (hit) hits++;
  }
  return hits;
};

console.log('--- BM25-only (baseline) ---');
const b = await score('bm25');
console.log('--- hybrid (embeddings + RRF + rerank) ---');
const h = await score('hybrid');
const pct = (n) => `${(n / GOLDEN.length * 100).toFixed(0)}%`;
console.log(`\nrecall@5: bm25=${b}/${GOLDEN.length} (${pct(b)})  hybrid=${h}/${GOLDEN.length} (${pct(h)})  delta=${h - b}`);
process.exit(h >= b ? 0 : 1);