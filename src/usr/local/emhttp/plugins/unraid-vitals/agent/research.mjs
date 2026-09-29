#!/usr/bin/env node
/**
 * unraid-vitals agent — background research job runner.
 *
 * ajax.php's ?action=research_ask creates a pending row in research_jobs
 * and fires this script detached (fire-and-forget, same pattern as
 * share-comment.mjs). This process:
 *   1. Retrieves the most relevant existing knowledge-base documents for
 *      the prompt via SQLite FTS5 (searchKb) — this is the "RAG" part:
 *      keyword retrieval over what the health agents have already learned,
 *      no vector store or embedding API needed for a plugin this size.
 *   2. Also pulls a fresh live snapshot (latest.json) so the model isn't
 *      reasoning purely from stale historical findings.
 *   3. Asks the local model to synthesize an answer grounded in that
 *      context, citing which KB documents it drew on.
 *   4. Writes the answer back to the job row. The UI polls for it.
 *
 * CPU-only inference here is genuinely slow (observed 20s-300s per call on
 * this hardware) — per user direction this is fine, it "may take hours",
 * so there is no aggressive timeout: research jobs get the full
 * VITALS_AGENT_TIMEOUT_MS budget (default 10 minutes, see smythos-client.mjs).
 *
 * Usage: node research.mjs <jobId>
 */
import { getResearchJob, startResearchJob, finishResearchJob, failResearchJob, searchKb, ingestFindingToKb } from './lib/db.mjs';
import { makeAnalysisAgent, callAnalyze, extractJson } from './lib/smythos-client.mjs';
import { latestSnapshot } from './lib/sources.mjs';

const BEHAVIOR = `You are the Unraid Vitals research assistant. You answer questions about
this specific Unraid server using ONLY the context provided (knowledge-base excerpts
and a live system snapshot) — never invent metrics, disk names, or container names that
aren't in the context. If the context doesn't contain enough to answer, say so plainly
and suggest what data would help. Be concise and factual, not hype-y.`;

async function main() {
  const jobId = Number(process.argv[2]);
  if (!jobId) { console.error('usage: node research.mjs <jobId>'); process.exit(1); }

  const job = getResearchJob(jobId);
  if (!job) { console.error(`job ${jobId} not found`); process.exit(1); }

  startResearchJob(jobId);
  try {
    const kbHits = searchKb(job.prompt, 12);
    const snap = latestSnapshot();

    const contextParts = [];
    if (kbHits.length) {
      contextParts.push('Knowledge base excerpts (from background health agents):');
      for (const doc of kbHits) {
        contextParts.push(`- [doc#${doc.id}] (${doc.topic || doc.source}) ${doc.title}\n  ${doc.content.slice(0, 400)}`);
      }
    } else {
      contextParts.push('(No matching knowledge-base documents yet.)');
    }
    if (snap) {
      contextParts.push('\nLive snapshot (abbreviated):');
      contextParts.push(JSON.stringify({
        time: snap.time, system: snap.system, load: snap.load,
        array: snap.array && snap.array.totals, docker: snap.docker,
        vms: snap.vms && { running: snap.vms.running, count: snap.vms.count },
        shares: snap.shares && { total: snap.shares.total },
      }).slice(0, 2000));
    }

    const userPrompt = `Question: ${job.prompt}\n\nContext:\n${contextParts.join('\n')}\n\n` +
      `Respond with strict JSON: {"answer": "<markdown-formatted answer>", "used_docs": [<doc ids you actually relied on>]}`;

    const agent = await makeAnalysisAgent('Vitals-Research', BEHAVIOR, { maxTokens: 900, temperature: 0.3 });
    const raw = await callAnalyze(agent, BEHAVIOR, userPrompt);
    const parsed = extractJson(raw);
    const answer = typeof parsed?.answer === 'string' ? parsed.answer : String(raw).slice(0, 4000);
    const usedDocs = Array.isArray(parsed?.used_docs) ? parsed.used_docs : kbHits.map(d => d.id);

    finishResearchJob(jobId, answer, usedDocs);
    // Keep the Q&A itself searchable for future related questions.
    ingestFindingToKb('research', { id: jobId, title: job.prompt, detail: answer, severity: 'info' });
    console.log(`[research#${jobId}] done`);
  } catch (e) {
    failResearchJob(jobId, e?.message || String(e));
    console.error(`[research#${jobId}] FAILED: ${e?.message || e}`);
    process.exit(1);
  }
}

main();
