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
import { getDb, startRun, finishRun, replaceFindings, ingestFindingToKb, isDueForRun } from './lib/db.mjs';
import * as disks from './agents/disks.mjs';
import * as thermal from './agents/thermal.mjs';
import * as pools from './agents/pools.mjs';
import * as general from './agents/general.mjs';
import * as network from './agents/network.mjs';
import * as updates from './agents/updates.mjs';
import * as diagnostics from './agents/diagnostics.mjs';

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
