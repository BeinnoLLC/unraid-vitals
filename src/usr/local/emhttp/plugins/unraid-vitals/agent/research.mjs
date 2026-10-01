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
import { getDb, getResearchJob, startResearchJob, finishResearchJob, failResearchJob, searchKb, ingestFindingToKb } from './lib/db.mjs';
import { makeAnalysisAgent, callAnalyze, extractJson, budgetPrompt } from './lib/smythos-client.mjs';
import { latestSnapshot, vmList } from './lib/sources.mjs';
import { window as timelineWindow, describeWindow } from './lib/timeline.mjs';

const BEHAVIOR = `You are the Unraid Vitals research assistant. You answer questions about
this specific Unraid server using ONLY the context provided (knowledge-base excerpts,
recent event history, and a live system snapshot) — never invent metrics, disk names, or
container/VM names that aren't in the context. If the context doesn't contain enough to
answer, say so plainly and suggest what data would help. Be concise and factual, not hype-y.`;

/** A question naming a live VM gets that VM's own event history attached —
 *  "listen to events from all the VMs and answer questions about a specific
 *  VM" scopes the retrieval, not just the prompt wording. Matched against
 *  vmList() rather than a regex over the question, so it only fires for
 *  VMs that actually exist (no false match on an unrelated word that
 *  happens to look like a name). */
function detectVmScope(prompt, vms) {
  const lower = prompt.toLowerCase();
  return vms.find(vm => lower.includes(vm.name.toLowerCase())) || null;
}

/** Lookback window in hours from natural phrasing. Handles "past/last N
 *  days|hours|weeks", "yesterday" (→48h so the whole day is inside),
 *  "today", "this week", and "since <weekday>". Defaults to 48h — the
 *  user's stated common case ("I may ask about the past 2 days"). Capped
 *  at 30 days: beyond that only hourly rollups exist and the prompt would
 *  be all aggregates. */
function detectWindowHours(prompt) {
  const p = prompt.toLowerCase();
  let m;
  if ((m = p.match(/(?:past|last|previous)\s+(\d+)\s*(day|hour|hr|week|h\b|d\b)/))) {
    const n = Number(m[1]); const u = m[2];
    return Math.min(720, u.startsWith('w') ? n * 168 : u.startsWith('d') ? n * 24 : n);
  }
  if (/\b(a|one)\s+week\b|this week|past week|last week/.test(p)) return 168;
  if (/yesterday/.test(p)) return 48;
  if (/today|since (this )?morning|last night|tonight|overnight/.test(p)) return 24;
  if ((m = p.match(/since\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)/))) {
    const days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    const diff = (new Date().getDay() - days.indexOf(m[1]) + 7) % 7 || 7;
    return diff * 24;
  }
  if (/right now|currently|at the moment/.test(p)) return 2;
  return 48;
}

/** Analytical questions (why/trend/compare/cause/diagnose) get answered by
 *  2-3 models with the answers cross-checked; simple lookups ("is X
 *  running", "how hot is disk3") go to one model — corroboration is worth
 *  the 3× wait only when reasoning, not recall, is the failure mode. */
function isAnalytical(prompt) {
  return /\b(why|cause|caused|reason|trend|compare|diagnos|correlat|investigate|analy[sz]e|root cause|what happened|degrad|slow(er|down)?|spike|unusual|anomal)\b/i.test(prompt);
}

async function main() {
  const jobId = Number(process.argv[2]);
  if (!jobId) { console.error('usage: node research.mjs <jobId>'); process.exit(1); }

  const job = getResearchJob(jobId);
  if (!job) { console.error(`job ${jobId} not found`); process.exit(1); }

  startResearchJob(jobId);
  try {
    let contextPrefix = '';
    let kbHits = searchKb(job.prompt, 12);
    if (/^auto:unraid-release:/.test(job.origin || '')) {
      // Guarantee the official notes are in context, whole, not a 400-char FTS snippet.
      const ver = job.origin.split(':')[2];
      const rel = getDb().prepare(`SELECT * FROM kb_documents WHERE source = 'unraid-release' AND source_ref = ?`).get(ver);
      if (rel) {
        kbHits = kbHits.filter(d => d.id !== rel.id);
        contextPrefix = `Official Unraid ${ver} release notes:\n${rel.content.slice(0, 7000)}\n`;
      }
    }
    const snap = latestSnapshot();
    const vms = vmList();
    const scopedVm = detectVmScope(job.prompt, vms);
    const windowHours = detectWindowHours(job.prompt);
    // The stitched timeline for the asked-about range: minute samples,
    // hourly rollups for the older part, every event, per-container /
    // per-disk facts, "what changed" bullets. Narrowed to one VM's events
    // when the question names it.
    const tl = timelineWindow(windowHours, { entity: scopedVm ? scopedVm.name : undefined });

    // Context is assembled as named sections so it can be budgeted into the
    // model's window by priority. Without this a release-notes advisory
    // (12 KB of notes + timeline + KB + snapshot) overflowed 8192 tokens and
    // every model returned truncated JSON → "all models failed".
    const trigger = [];
    if (contextPrefix) trigger.push(contextPrefix);
    if (job.context) trigger.push(`Why this research was opened (${job.origin || 'auto'}):\n${String(job.context).slice(0, 4000)}\n`);

    const timeline = [];
    timeline.push(`Time range the question is about: last ${windowHours}h (${new Date(tl.from * 1000).toISOString()} → ${new Date(tl.to * 1000).toISOString()}). Answer about THIS range unless the question says otherwise; say explicitly when data for part of the range is hourly-only or missing.`);
    timeline.push('\nTimeline (pre-aggregated — quote these numbers, do not recompute):');
    timeline.push(describeWindow(tl));
    if (scopedVm) {
      timeline.push(`\nThe question is about VM "${scopedVm.name}" — current state: ${scopedVm.state}. Events above are already filtered to this VM.${tl.events.length ? '' : ` No state transitions recorded for it in the last ${windowHours}h (settled the whole time, or vmwatch has not run yet).`}`);
    } else if (vms.length) {
      timeline.push(`\nAll VMs currently: ${vms.map(v => `${v.name}=${v.state}`).join(', ')}`);
    }

    const kb = [];
    if (kbHits.length) {
      kb.push('\nKnowledge base excerpts (agent findings, past research, study reports):');
      for (const doc of kbHits) {
        kb.push(`- [doc#${doc.id}] (${doc.topic || doc.source}, ${new Date(doc.created_at * 1000).toISOString().slice(0, 16)}) ${doc.title}\n  ${doc.content.slice(0, 400)}`);
      }
    } else {
      kb.push('\n(No matching knowledge-base documents.)');
    }

    const snapshot = [];
    if (snap) {
      snapshot.push('\nLive snapshot (abbreviated):');
      snapshot.push(JSON.stringify({
        time: snap.time, system: snap.system, load: snap.load,
        array: snap.array && snap.array.totals, docker: snap.docker && { running: snap.docker.running, count: snap.docker.count },
        vms: snap.vms && { running: snap.vms.running, count: snap.vms.count },
        shares: snap.shares && { total: snap.shares.total },
      }).slice(0, 1500));
    }

    // Priorities: question + instructions never trimmed; trigger context /
    // release notes outrank the generic timeline; KB snippets and the live
    // snapshot are trimmed first.
    const budgeted = budgetPrompt([
      { name: 'question', priority: 100, text: `Question: ${job.prompt}\n\nContext:` },
      { name: 'trigger', priority: 80, text: trigger.join('\n') },
      { name: 'timeline', priority: 50, text: timeline.join('\n') },
      { name: 'knowledge-base', priority: 30, text: kb.join('\n') },
      { name: 'snapshot', priority: 10, text: snapshot.join('\n') },
      { name: 'instructions', priority: 100, text: `Respond with strict JSON: {"answer": "<markdown-formatted answer>", "used_docs": [<doc ids you actually relied on>]}` },
    ], BEHAVIOR, 1400); // 900 for the answer + ~500 slack: markdown/JSON tokenizes denser than 3.6 chars/token
    if (budgeted.trimmed.length) console.warn(`[research#${jobId}] trimmed to fit context: ${budgeted.trimmed.join(', ')} (${budgeted.tokens} tok)`);
    const userPrompt = budgeted.text;

    let answer, usedDocs;
    if (isAnalytical(job.prompt)) {
      // Analytical: 2-3 models answer independently, then one synthesis
      // pass reconciles them — agreements stated as fact, disagreements
      // surfaced as such (with which model said what), never silently
      // picking one. The user asked for exactly this kind of multi-model
      // reading; the synthesis is what stops it being 3× the text.
      const models = (process.env.VITALS_DIAG_MODELS || 'qwen3:14b,llama3.1:8b,gemma2:9b').split(',').map(s => s.trim()).filter(Boolean).slice(0, 3);
      // A transient overload on the shared studio used to kill the whole
      // job ("all models failed") — two auto-jobs launched from one agent
      // run arrive together and overload it. Retry the whole drafting pass
      // on a stagger so a busy studio costs seconds, not the investigation.
      let drafts = [];
      let lastErr;
      for (let attempt = 1; attempt <= 3 && !drafts.length; attempt++) {
        const perModel = [];
        for (const model of models) {
          try {
            const agent = await makeAnalysisAgent(`Vitals-Research-${model}`, BEHAVIOR, { maxTokens: 900, temperature: 0.3, model });
            const parsed = extractJson(await callAnalyze(agent, BEHAVIOR, userPrompt));
            if (typeof parsed?.answer === 'string') perModel.push({ model, answer: parsed.answer, used: Array.isArray(parsed.used_docs) ? parsed.used_docs : [] });
            else lastErr = `${model}: no answer field in JSON`;
          } catch (e) { lastErr = e?.message || String(e); console.warn(`[research#${jobId}] ${model} failed: ${lastErr}`); }
        }
        drafts = perModel;
        if (!drafts.length && attempt < 3) {
          console.warn(`[research#${jobId}] no drafts on attempt ${attempt} (${lastErr}); retrying in ${attempt * 20}s`);
          await new Promise(r => setTimeout(r, attempt * 20000));
        }
      }
      if (!drafts.length) throw new Error(`all models failed after 3 attempts: ${lastErr}`);
      if (drafts.length === 1) { answer = drafts[0].answer; usedDocs = drafts[0].used; }
      else {
        const synthPrompt = `Question: ${job.prompt}\n\n${drafts.map(d => `--- Answer from ${d.model} ---\n${d.answer}`).join('\n\n')}\n\n` +
          `Write ONE final markdown answer. Where the drafts agree, state it plainly. Where they disagree on a fact or a cause, keep BOTH positions and name which model said which — do not pick silently. Cut anything not grounded in the drafts. End with a one-line "Confidence:" note (high = all agreed, medium = mostly, low = conflicting). ` +
          `Respond with strict JSON: {"answer": "<markdown>", "used_docs": [<ids>]}`;
        const synth = await makeAnalysisAgent('Vitals-Research-Synthesis', BEHAVIOR, { maxTokens: 1100, temperature: 0.2 });
        const parsed = extractJson(await callAnalyze(synth, BEHAVIOR, synthPrompt));
        answer = typeof parsed?.answer === 'string' ? parsed.answer : drafts[0].answer;
        answer += `\n\n<sub>Cross-checked across ${drafts.length} local models: ${drafts.map(d => d.model).join(', ')}</sub>`;
        usedDocs = [...new Set(drafts.flatMap(d => d.used))];
      }
    } else {
      const agent = await makeAnalysisAgent('Vitals-Research', BEHAVIOR, { maxTokens: 900, temperature: 0.3 });
      const raw = await callAnalyze(agent, BEHAVIOR, userPrompt);
      const parsed = extractJson(raw);
      answer = typeof parsed?.answer === 'string' ? parsed.answer : String(raw).slice(0, 4000);
      usedDocs = Array.isArray(parsed?.used_docs) ? parsed.used_docs : kbHits.map(d => d.id);
    }

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
