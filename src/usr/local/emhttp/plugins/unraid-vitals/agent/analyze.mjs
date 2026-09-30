#!/usr/bin/env node
/**
 * unraid-vitals agent — orchestrator.
 *
 * Runs all specialist agents sequentially (avoids hammering the LLM studios
 * concurrently), writes each agent's findings to SQLite, and never lets one
 * agent's failure block the others. Meant to run via cron every N minutes —
 * this process does the LLM work; the PHP UI only ever reads the DB.
 *
 * Usage: node analyze.mjs [agentId ...]   (default: run every registered agent)
 */
import { randomUUID } from 'node:crypto';
import { getDb, startRun, finishRun, replaceFindings, ingestFindingToKb, isDueForRun, insertKbDocument } from './lib/db.mjs';
import * as disks from './agents/disks.mjs';
import * as thermal from './agents/thermal.mjs';
import * as pools from './agents/pools.mjs';
import * as general from './agents/general.mjs';
import * as network from './agents/network.mjs';
import * as updates from './agents/updates.mjs';
import * as diagnostics from './agents/diagnostics.mjs';
import * as unraidRelease from './agents/unraid-release.mjs';
import { fireAutoResearch } from './lib/auto-research.mjs';

// Fast per-tick agents run every cron invocation (hourly, see install.sh).
// Slow/deep agents are gated by their own configurable interval so a user
// can turn the deep diagnostics scan down to "every 6h" (the default,
// VITALS_DIAG_WINDOW_HOURS/VITALS_DIAG_INTERVAL_MINUTES in Settings)
// without changing the hourly cron cadence used for everything else —
// the interval lives in isDueForRun's own bookkeeping (last completed run
// time per agent id), not in the cron file itself.
const FAST_REGISTRY = [disks, thermal, pools, general, network];
const GATED_REGISTRY = [
  { mod: updates, intervalMinutes: () => Number(process.env.VITALS_UPDATE_INTERVAL_MINUTES || 360) },
  { mod: diagnostics, intervalMinutes: () => Number(process.env.VITALS_DIAG_INTERVAL_MINUTES || 360) },
  // Unraid OS release feed — daily is plenty, releases are weeks apart.
  { mod: unraidRelease, intervalMinutes: () => Number(process.env.VITALS_RELEASE_INTERVAL_MINUTES || 1440) },
];

async function runAgent(mod) {
  const runId = randomUUID();
  const t0 = Date.now();
  startRun(mod.AGENT_ID, runId);
  try {
    const findings = await mod.run();
    replaceFindings(mod.AGENT_ID, runId, findings);
    // Feed anything worth remembering into the knowledge base — routine
    // 'ok' findings would just be noise, so only non-baseline severities
    // get indexed for the KB search page / background research.
    for (const f of findings) {
      if (f.severity !== 'ok') ingestFindingToKb(mod.AGENT_ID, f);
    }
    // The most critical findings (disk failing, new Unraid release, any
    // 'critical') become research/study jobs on their own — the admin
    // should open the Research tab and find the investigation already
    // underway, not have to think to ask for it.
    try {
      const filed = fireAutoResearch(mod.AGENT_ID, findings);
      for (const j of filed) console.log(`[${mod.AGENT_ID}] auto-research ${j.mode} job #${j.jobId} (${j.key})`);
    } catch (e) { console.warn(`[${mod.AGENT_ID}] auto-research failed: ${e?.message || e}`); }
    // Gated deep scans also land as ONE dated report document, so "what
    // did the last 6h scan find?" is answerable by KB search even when
    // every individual finding was routine.
    if (mod.KB_REPORT) {
      const when = new Date().toISOString().slice(0, 16).replace('T', ' ');
      const worst = findings.some(f => f.severity === 'critical') ? 'critical'
        : findings.some(f => f.severity === 'error') ? 'error'
        : findings.some(f => f.severity === 'warning') ? 'warning' : 'ok';
      const body = findings.map(f => `### ${f.severity.toUpperCase()} — ${f.title}${f.subject ? ` (${f.subject})` : ''}\n${f.detail || ''}${f.recommendation ? `\n\n**Next:** ${f.recommendation}` : ''}`).join('\n\n');
      insertKbDocument({
        source: 'finding', sourceRef: runId, topic: mod.AGENT_ID, kind: 'report',
        title: `${mod.KB_REPORT} — ${when}`,
        summary: `${findings.length} finding(s), worst: ${worst}`,
        content: `# ${mod.KB_REPORT}\n\n_Run ${when}, ${findings.length} finding(s)._\n\n${body}`,
        severity: worst === 'critical' ? 'critical' : worst === 'error' ? 'high' : worst === 'warning' ? 'medium' : 'low',
      });
    }
    finishRun(runId, 'ok', null);
    console.log(`[${mod.AGENT_ID}] ok — ${findings.length} findings in ${Date.now() - t0}ms`);
  } catch (e) {
    finishRun(runId, 'error', String(e?.message || e));
    console.error(`[${mod.AGENT_ID}] FAILED: ${e?.message || e}${e?.causes ? ' — ' + JSON.stringify(Object.fromEntries(Object.entries(e.causes).map(([k, v]) => [k, v?.message]))) : ''}`);
  }
}

async function main() {
  getDb(); // ensure schema exists before any agent runs
  const only = process.argv.slice(2);
  const allMods = [...FAST_REGISTRY, ...GATED_REGISTRY.map(g => g.mod)];
  const fast = only.length ? FAST_REGISTRY.filter(m => only.includes(m.AGENT_ID)) : FAST_REGISTRY;
  const gated = only.length ? GATED_REGISTRY.filter(g => only.includes(g.mod.AGENT_ID)) : GATED_REGISTRY;
  if (only.length && !fast.length && !gated.length) {
    console.error(`no matching agents for: ${only.join(', ')} (known: ${allMods.map(m => m.AGENT_ID).join(', ')})`);
    process.exit(1);
  }
  for (const mod of fast) {
    // eslint-disable-next-line no-await-in-loop -- intentional: one LLM call at a time
    await runAgent(mod);
  }
  for (const { mod, intervalMinutes } of gated) {
    const interval = intervalMinutes();
    // --once-style explicit request bypasses the gate — a user asking for
    // one agent by name wants it to actually run, not silently skip
    // because it ran 20 minutes ago.
    if (only.length || isDueForRun(mod.AGENT_ID, interval)) {
      // eslint-disable-next-line no-await-in-loop
      await runAgent(mod);
    } else {
      console.log(`[${mod.AGENT_ID}] skipped — not due yet (interval ${interval}m)`);
    }
  }
}

main().catch(e => { console.error('orchestrator crashed:', e); process.exit(1); });
