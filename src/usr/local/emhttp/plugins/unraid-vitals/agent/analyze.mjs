#!/usr/bin/env node

import { randomUUID } from 'node:crypto';
import { getDb, startRun, finishRun, replaceFindings, ingestFindingToKb, isDueForRun, insertKbDocument, reapStaleRuns } from './lib/db.mjs';
import { lastRunStats } from './agents/contract.mjs';
import { distill } from './lib/distiller.mjs';
import * as disks from './agents/disks.mjs';
import * as thermal from './agents/thermal.mjs';
import * as pools from './agents/pools.mjs';
import * as general from './agents/general.mjs';
import * as network from './agents/network.mjs';
import * as updates from './agents/updates.mjs';
import * as diagnostics from './agents/diagnostics.mjs';
import * as unraidRelease from './agents/unraid-release.mjs';
import { fireAutoResearch } from './lib/auto-research.mjs';

const FAST_REGISTRY = [disks, thermal, pools, general, network];
const GATED_REGISTRY = [
  { mod: updates, intervalMinutes: () => Number(process.env.VITALS_UPDATE_INTERVAL_MINUTES || 360) },
  { mod: diagnostics, intervalMinutes: () => Number(process.env.VITALS_DIAG_INTERVAL_MINUTES || 360) },
  
  { mod: unraidRelease, intervalMinutes: () => Number(process.env.VITALS_RELEASE_INTERVAL_MINUTES || 1440) },
];

async function runAgent(mod) {
  const runId = randomUUID();
  const t0 = Date.now();
  startRun(mod.AGENT_ID, runId);
  try {
    const findings = await mod.run();

    
    
    
    
    
    if (!Array.isArray(findings) || findings.length === 0) {
      console.warn(`[analyze] ${mod.AGENT_ID}: empty result — keeping previous findings (run marked stale-empty)`);
      finishRun(runId, 'stale-empty', 'model returned no findings');
      
      const prev = getDb().prepare(`SELECT COUNT(*) AS n FROM findings WHERE agent = ?`).get(mod.AGENT_ID);
      console.warn(`[analyze] ${mod.AGENT_ID}: ${prev?.n ?? 0} standing findings kept`);
      return [];
    }
    
    const valid = findings.filter(f => f && typeof f === 'object' && f.severity);
    if (valid.length === 0) {
      console.warn(`[analyze] ${mod.AGENT_ID}: all findings invalid — keeping previous findings`);
      finishRun(runId, 'stale-empty', 'all findings invalid');
      return [];
    }

    replaceFindings(mod.AGENT_ID, runId, valid);
    
    
    
    for (const f of findings) {
      if (f.severity !== 'ok') ingestFindingToKb(mod.AGENT_ID, f);
    }
    
    
    
    
    try {
      const filed = fireAutoResearch(mod.AGENT_ID, findings);
      for (const j of filed) console.log(`[${mod.AGENT_ID}] auto-research ${j.mode} job #${j.jobId} (${j.key})`);
    } catch (e) { console.warn(`[${mod.AGENT_ID}] auto-research failed: ${e?.message || e}`); }
    
    
    
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
    
    finishRun(runId, 'ok', null, {
      prompt_tokens: lastRunStats.prompt_tokens_est ?? null,
      output_tokens: lastRunStats.eval_count ?? null,
      model_ms: Date.now() - t0,
      endpoint: lastRunStats.endpoint ?? null,
      retries: lastRunStats.retries ?? 0,
      findings_kept: Array.isArray(findings) ? findings.length : 0,
      findings_dropped: lastRunStats.drop_reasons?.length ?? 0
    });
    console.log(`[${mod.AGENT_ID}] ok — ${findings.length} findings in ${Date.now() - t0}ms`);
  } catch (e) {
    finishRun(runId, 'error', String(e?.message || e));
    console.error(`[${mod.AGENT_ID}] FAILED: ${e?.message || e}${e?.causes ? ' — ' + JSON.stringify(Object.fromEntries(Object.entries(e.causes).map(([k, v]) => [k, v?.message]))) : ''}`);
  }
}

async function seedAgentCfg() {
  try {
    const { readFileSync, writeFile: wf, writeFileSync, mkdirSync } = await import('node:fs');
    const { execSync } = await import('node:child_process');
    let cfg = {};
    try {
      const raw = readFileSync(process.env.VITALS_CFG || '/boot/config/plugins/unraid-vitals/vitals.cfg', 'utf8');
      for (const line of raw.split('\n')) {
        const m = line.match(/^([A-Z0-9_]+)="?(.*?)"?\s*$/);
        if (m) cfg[m[1]] = m[2];
      }
    } catch {  }
    writeFileSync('/tmp/vitals-agent-cfg.json', JSON.stringify(cfg));
  } catch (e) { console.warn('[analyze] seedAgentCfg failed:', e.message); }
}

async function main() {
  
  
  await seedAgentCfg();
  getDb(); 
  
  
  reapStaleRuns();
  
  
  
  
  const totalDeadlineMs = Number(process.env.VITALS_RUN_BUDGET_MINUTES || 55) * 60 * 1000;
  const deadline = Date.now() + totalDeadlineMs;
  const outOfTime = () => Date.now() >= deadline;
  const only = process.argv.slice(2);
  const allMods = [...FAST_REGISTRY, ...GATED_REGISTRY.map(g => g.mod)];
  const fast = only.length ? FAST_REGISTRY.filter(m => only.includes(m.AGENT_ID)) : FAST_REGISTRY;
  const gated = only.length ? GATED_REGISTRY.filter(g => only.includes(g.mod.AGENT_ID)) : GATED_REGISTRY;
  if (only.length && !fast.length && !gated.length) {
    console.error(`no matching agents for: ${only.join(', ')} (known: ${allMods.map(m => m.AGENT_ID).join(', ')})`);
    process.exit(1);
  }
  
  
  try {
    const d = distill(25);
    if (d.lessons || d.solutions) console.log(`[analyze] distilled ${d.lessons} lesson(s) / ${d.solutions} solution(s)`);
  } catch (e) { console.warn('[analyze] distiller failed:', e.message); }

  for (const mod of fast) {
    if (outOfTime()) { console.warn(`[analyze] total run budget exhausted — skipping ${mod.AGENT_ID} (next pass retries it)`); continue; }
    
    await runAgent(mod);
  }
  try { const d2 = distill(25); if (d2.lessons || d2.solutions) console.log(`[analyze] distilled +${d2.lessons}/+${d2.solutions}`); } catch {}
  for (const { mod, intervalMinutes } of gated) {
    const interval = intervalMinutes();
    
    
    
    if (outOfTime() && !only.length) { console.warn(`[analyze] total run budget exhausted — skipping ${mod.AGENT_ID}`); continue; }
    if (only.length || isDueForRun(mod.AGENT_ID, interval)) {
      
      await runAgent(mod);
    } else {
      console.log(`[${mod.AGENT_ID}] skipped — not due yet (interval ${interval}m)`);
    }
  }
}

main().catch(e => { console.error('orchestrator crashed:', e); process.exit(1); });
