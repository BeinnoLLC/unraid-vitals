#!/usr/bin/env node

import {
  dueStudyJobs, expiredStudyJobs, appendStudyObservation,
  finishResearchJob, failResearchJob, insertKbDocument, getResearchJob,
} from './lib/db.mjs';
import { makeAnalysisAgent, callAnalyze, extractJson } from './lib/smythos-client.mjs';
import { latestSnapshot } from './lib/sources.mjs';
import { window as timelineWindow, describeWindow } from './lib/timeline.mjs';

const TICK_BEHAVIOR = `You are the Unraid Vitals study assistant, mid-way through a standing
observation task. You are given the user's original study goal, a short history of what you
have already noted, and the CURRENT system snapshot. Write ONE short observation (1-3 sentences)
about what is notable right now relative to the goal — a real change, a new alert, a trend
continuing, or "nothing new since last check" if genuinely nothing changed. Do not repeat an
earlier observation verbatim. Be concrete: name the metric/disk/container, not "the system".`;

const SUMMARY_BEHAVIOR = `You are the Unraid Vitals study assistant. A standing observation
window has closed. You are given the original goal and the full journal of observations
collected during the window. Write a complete written report answering the goal, structured as:
a short executive summary, then a "## Timeline" section covering what happened when, then a
"## Findings" section with the concrete issues/trends found (or "no issues found" stated plainly
if the window was quiet), then a "## Recommendation" section. Ground every claim in the journal
— do not invent readings that were not observed. Markdown output.`;

async function tickOne(job) {
  try {
    const snap = latestSnapshot() || {};
    const recentObs = (job.observations || []).slice(-6)
      .map(o => `- ${new Date(o.at * 1000).toISOString()}: ${o.note}`).join('\n') || '(none yet)';

    const summary = {
      time: snap.time, cpu: snap.cpu, mem: snap.mem, load: snap.load,
      temp_max: snap.temp_max, temp_avg: snap.temp_avg,
      array: snap.array && snap.array.totals, docker: snap.docker && { running: snap.docker.running, count: snap.docker.count },
      flash: snap.flash,
    };

    
    
    
    const sinceTick = Math.max(1, Math.ceil((job.tick_minutes || 5) / 60 * 1.2));
    const tl = timelineWindow(sinceTick);
    const prompt = `Study goal: ${job.prompt}\n\nPrior observations (most recent last):\n${recentObs}\n\n` +
      `Current snapshot: ${JSON.stringify(summary).slice(0, 900)}\n\n` +
      `Since the last check (${tl.coverage.minute_samples} samples):\n${describeWindow(tl).slice(0, 2200)}\n\n` +
      `Respond with strict JSON: {"observation": "<1-3 sentence note>"}`;

    const agent = await makeAnalysisAgent('Vitals-Study-Tick', TICK_BEHAVIOR, { maxTokens: 220, temperature: 0.2 });
    const raw = await callAnalyze(agent, TICK_BEHAVIOR, prompt);
    const parsed = extractJson(raw);
    const note = typeof parsed?.observation === 'string' ? parsed.observation : String(raw).slice(0, 400);
    appendStudyObservation(job.id, note);
    console.log(`[study#${job.id}] tick: ${note.slice(0, 120)}`);
  } catch (e) {
    
    
    appendStudyObservation(job.id, `(tick failed: ${e?.message || e})`);
    console.error(`[study#${job.id}] tick FAILED: ${e?.message || e}`);
  }
}

async function finishOne(job) {
  try {
    const obs = job.observations || [];
    const journal = obs.length
      ? obs.map(o => `- ${new Date(o.at * 1000).toISOString()}: ${o.note}`).join('\n')
      : '(no observations were recorded during the window — the study ran but nothing changed enough to note, or all ticks failed; say so honestly rather than inventing findings)';

    
    
    const hours = Math.max(1, Math.ceil((job.study_until - job.created_at) / 3600));
    const tl = timelineWindow(hours);
    const prompt = `Study goal: ${job.prompt}\n\nStudy window: ${new Date(job.created_at * 1000).toISOString()} ` +
      `to ${new Date(job.study_until * 1000).toISOString()} (sampled every ${job.tick_minutes} min)\n\n` +
      `Aggregated metrics for the whole window:\n${describeWindow(tl).slice(0, 3500)}\n\n` +
      `Full observation journal:\n${journal}\n\n` +
      `Respond with strict JSON: {"report": "<full markdown report per the structure you were told>", ` +
      `"headline": "<one-line summary>", "severity": "<one of: low, medium, high, critical — how urgently should the admin read this?>", "tags": ["<1-4 lowercase categories like: storage, docker, network, security, thermal, memory, updates>"]}`;

    const agent = await makeAnalysisAgent('Vitals-Study-Summary', SUMMARY_BEHAVIOR, { maxTokens: 1400, temperature: 0.3 });
    const raw = await callAnalyze(agent, SUMMARY_BEHAVIOR, prompt);
    const parsed = extractJson(raw);
    const report = typeof parsed?.report === 'string' ? parsed.report : String(raw).slice(0, 6000);
    const headline = typeof parsed?.headline === 'string' ? parsed.headline : job.prompt;
    const SEVERITIES = new Set(['low', 'medium', 'high', 'critical']);
    const severity = SEVERITIES.has(parsed?.severity) ? parsed.severity : 'medium';
    
    
    const llmTags = Array.isArray(parsed?.tags) ? parsed.tags.map(String) : [];

    finishResearchJob(job.id, report, []);
    insertKbDocument({
      source: 'research', sourceRef: job.id, topic: 'study', kind: 'study',
      title: `Study: ${job.prompt}`,
      summary: headline,
      content: report,
      severity,
      tags: llmTags,
    });
    console.log(`[study#${job.id}] finished — ${obs.length} observations over ${job.tick_minutes ? Math.round((job.study_until - job.created_at) / 60) : '?'} min`);
  } catch (e) {
    failResearchJob(job.id, e?.message || String(e));
    console.error(`[study#${job.id}] FINISH FAILED: ${e?.message || e}`);
  }
}

async function main() {
  const forceId = process.argv[2] === '--once' ? Number(process.argv[3]) : null;

  if (forceId) {
    const job = getResearchJob(forceId);
    if (!job) { console.error(`job ${forceId} not found`); process.exit(1); }
    if (job.study_until <= Math.floor(Date.now() / 1000)) await finishOne(job);
    else await tickOne(job);
    return;
  }

  
  
  const expired = expiredStudyJobs();
  for (const job of expired) await finishOne(job);

  const expiredIds = new Set(expired.map(j => j.id));
  const due = dueStudyJobs().filter(j => !expiredIds.has(j.id));
  for (const job of due) await tickOne(job);

  if (!expired.length && !due.length) console.log('[study] nothing due');
}

main().catch(e => { console.error('[study] fatal:', e?.message || e); process.exit(1); });
