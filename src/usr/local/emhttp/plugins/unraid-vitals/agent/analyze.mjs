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
import { getDb, startRun, finishRun, replaceFindings, ingestFindingToKb } from './lib/db.mjs';
import * as disks from './agents/disks.mjs';
import * as thermal from './agents/thermal.mjs';
import * as pools from './agents/pools.mjs';
import * as general from './agents/general.mjs';
import * as network from './agents/network.mjs';

const REGISTRY = [disks, thermal, pools, general, network];

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
  const agents = only.length ? REGISTRY.filter(m => only.includes(m.AGENT_ID)) : REGISTRY;
  if (!agents.length) {
    console.error(`no matching agents for: ${only.join(', ')}`);
    process.exit(1);
  }
  for (const mod of agents) {
    // eslint-disable-next-line no-await-in-loop -- intentional: one LLM call at a time
    await runAgent(mod);
  }
}

main().catch(e => { console.error('orchestrator crashed:', e); process.exit(1); });
