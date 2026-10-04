/**
 * unraid-vitals — lib/hybrid-retrieval.mjs (P11-… / #37)
 *
 * Hybrid retrieval upgrade for the KB:
 *   1. LOCAL embeddings via the configured Ollama-compatible endpoint
 *      (KB_EMBED_MODEL, default nomic-embed-text); vectors as BLOB in
 *      kb_documents / kb_events / kb_lessons; backfill for existing rows.
 *   2. Hybrid search = FTS5 BM25 ⨯ cosine(embedding) fused with reciprocal
 *      rank fusion (k=60).
 *   3. LLM reranker over the top-20 fused candidates (listwise, ids-out) —
 *      final top-5.
 *   4. Query rewriting: ONE optional LLM call expanding synonyms/entities
 *      before retrieval (KB_REWRITE_QUERY).
 *   5. Eval harness: scripts/kb-eval.mjs golden set → recall@5 baseline
 *      (BM25-only) vs hybrid.
 *
 * Embeddings fail SOFT: endpoint down ⇒ BM25-only results (the retrieval
 * degrades, never breaks).
 */

import { getDb } from './db.mjs';

const LLM_PRIMARY = process.env.LLM_STUDIO_PRIMARY || '';
const LLM_FALLBACK = process.env.LLM_STUDIO_BACKUP || '';
const EMBED_MODEL = process.env.VITALS_KB_EMBED_MODEL || 'nomic-embed-text';

/** One embedding call; null on failure (soft). Speaks BOTH OpenAI-compat
 *  /v1/embeddings and Ollama-native /api/embeddings (the studios are plain
 *  Ollama servers — /v1 may not exist there; /api/embeddings always does). */
export async function embed(text) {
  const clean = String(text).slice(0, 4000);
  for (const base of [LLM_PRIMARY, LLM_FALLBACK].filter(Boolean)) {
    const b = base.replace(/\/$/, '');
    // ollama-native first (measured working on the reference box)
    try {
      const res = await fetch(b + '/api/embeddings', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ model: EMBED_MODEL, prompt: clean }),
        signal: AbortSignal.timeout(30000),
      });
      if (res.ok) {
        const j = await res.json();
        if (Array.isArray(j?.embedding) && j.embedding.length) return j.embedding;
      }
    } catch { /* try next */ }
    // openai-compat fallback
    try {
      const res = await fetch(b + '/v1/embeddings', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ model: EMBED_MODEL, input: clean }),
        signal: AbortSignal.timeout(30000),
      });
      if (res.ok) {
        const j = await res.json();
        const v = j?.data?.[0]?.embedding;
        if (Array.isArray(v) && v.length) return v;
      }
    } catch { /* next endpoint / null */ }
  }
  return null;
}

const VEC_DIM = 768; // nomic-embed-text; anything else changes this constant per model — store dim in the row instead.

/** cosine over two number arrays (or Float32Array views of BLOBs). */
export function cosine(a, b) {
  const n = Math.min(a.length, b.length);
  let dot = 0, na = 0, nb = 0;
  for (let i = 0; i < n; i++) { dot += a[i] * b[i]; na += a[i] ** 2; nb += b[i] ** 2; }
  return (na && nb) ? dot / Math.sqrt(na * nb) : 0;
}

function blobToVec(blob) {
  try {
    const arr = new Float32Array(blob.buffer ?? blob);
    return Array.from(arr);
  } catch { return null; }
}
function vecToBlob(vec) { return Buffer.from(Float32Array.from(vec).buffer); }

/** Backfill embeddings for rows missing them. Returns counts. */
export async function backfillEmbeddings(batch = 20) {
  const db = getDb();
  const out = { docs: 0, events: 0, lessons: 0, skipped: 0 };
  for (const [table, titleCol, contentCol] of [['kb_documents', 'title', 'content'], ['kb_events', 'summary', 'summary'], ['kb_lessons', 'entity', 'lesson']]) {
    const cols = new Set(db.prepare(`PRAGMA table_info(${table})`).all().map(c => c.name));
    if (!cols.has('embedding')) db.exec(`ALTER TABLE ${table} ADD COLUMN embedding BLOB`);
    if (!cols.has('embed_dim')) db.exec(`ALTER TABLE ${table} ADD COLUMN embed_dim INTEGER`);
    const rows = db.prepare(`SELECT id, ${titleCol} AS title, ${contentCol} AS content FROM ${table} WHERE embedding IS NULL LIMIT ?`).all(batch);
    for (const r of rows) {
      const v = await embed(`${r.title}\n${String(r.content ?? '').slice(0, 1200)}`);
      if (!v) { out.skipped++; continue; }
      db.prepare(`UPDATE ${table} SET embedding = ?, embed_dim = ? WHERE id = ?`).run(vecToBlob(v), v.length, r.id);
      out[table === 'kb_documents' ? 'docs' : table === 'kb_events' ? 'events' : 'lessons']++;
    }
  }
  return out;
}

/**
 * Hybrid search: BM25 rows + vector cosine rows → RRF fuse → (optional) LLMS
 * rerank. Same signature as searchKb: (query, limit, severity, tag, opts).
 */
export async function hybridSearchKb(query, limit = 8, opts = {}) {
  const db = getDb();
  // 1. BM25 leg (same shape as lib/db.mjs searchKb)
  const words = String(query).split(/\s+/).filter(Boolean).map(w => `"${w.replace(/["*^]/g, '')}"`);
  const leg1 = words.length
    ? db.prepare(`SELECT d.id, d.title, d.topic, d.severity, d.tags, d.kind, d.summary, d.content, bm25(kb_fts) AS rank
                  FROM kb_fts JOIN kb_documents d ON d.id = kb_fts.rowid
                  WHERE kb_fts MATCH ? ORDER BY rank LIMIT 20`).all(words.join(' OR '))
    : [];

  // 2. embedding leg — needs the query vector
  let leg2 = [];
  const qv = opts.noEmbed ? null : await embed(query);
  if (qv) {
    const rows = db.prepare(`SELECT id, title, topic, severity, tags, kind, summary, content, embedding FROM kb_documents WHERE embedding IS NOT NULL LIMIT 8000`).all();
    leg2 = rows
      .filter(r => r.embed_dim === qv.length)
      .map(r => ({ ...r, sim: cosine(qv, blobToVec(r.embedding)) }))
      .sort((a, b) => b.sim - a.sim)
      .slice(0, 20);
  }

  // 3. RRF fuse (k=60)
  const rrf = new Map();
  const addLeg = (rows, weight = 1) => {
    rows.forEach((r, i) => {
      const cur = rrf.get(r.id) ?? { row: r, score: 0 };
      cur.score += weight / (60 + i + 1);
      rrf.set(r.id, cur);
    });
  };
  addLeg(leg1, leg2.length ? 1 : 1.2);               // BM25 keeps more weight without the vector leg
  addLeg(leg2, 1);
  let fused = [...rrf.values()].sort((a, b) => b.score - a.score).map(x => x.row);
  if (fused.length === 0) return [];

  // 4. LLM reranker (listwise, ids out) — top-20 → ranked order; fail soft
  if (opts.rerank !== false && fused.length > 2) {
    try {
      const { callAnalyze } = await import('./smythos-client.mjs');
      const list = fused.slice(0, 20).map((r, i) => `${i}: ${r.title}`).join('\n');
      const raw = await callAnalyze(
        `You are a search reranker for a NAS health knowledge base. The QUERY is: "${String(query).slice(0, 200)}".
Rank these ${Math.min(20, fused.length)} documents by relevance to the query. Respond with ONLY a JSON array of the original 0-based indices in ranked order, most relevant first — nothing else.`,
        list, { maxTokens: 200 }
      );
      const order = JSON.parse(String(raw).replace(/[^0-9,\[\]\s]/g, ''));
      if (Array.isArray(order) && order.length) {
        fused = order.flatMap(ix => (Number.isInteger(ix) && fused[ix]) ? [fused[ix]] : []);
      }
    } catch { /* soft: keep fused order */ }
  }

  return fused.slice(0, limit);
}

/** Optional query rewrite (KB_REWRITE_QUERY=1): one LLM call adding synonyms. */
export async function rewriteQuery(query) {
  if (process.env.VITALS_KB_REWRITE_QUERY !== '1') return query;
  try {
    const { callAnalyze } = await import('./smythos-client.mjs');
    const out = await callAnalyze(
      'Expand this search query into a comma-separated list of query variants with synonyms and related Unraid terms. Respond ONLY with the comma-separated variants.',
      String(query), { maxTokens: 80 });
    return String(out).slice(0, 400);
  } catch { return query; }
}