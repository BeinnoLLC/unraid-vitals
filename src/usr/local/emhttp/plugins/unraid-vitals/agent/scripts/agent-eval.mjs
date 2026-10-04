#!/usr/bin/env node
/* unraid-vitals — agent-eval.mjs (P20-12 / #117)
 *
 * Measures the FINDINGS layer (the retrieval eval in #37 measures search).
 *
 * Fixtures with known problems live in eval/fixtures/*.json (fixture-source
 * shaped). Expected findings: subject + minimum severity per fixture; the
 * 'healthy' fixture must produce only ok/info findings.
 *
 * Usage (run before changing a prompt or the model):
 *   node scripts/agent-eval.mjs [--agents disks,thermal] [--fixture hot-disk] [--repeat 3]
 *
 * Requires a reachable LLM endpoint (LLM_STUDIO_PRIMARY/_BACKUP). Without one
 * runs error and the report shows them — exit 1.
 */

import { readdirSync, readFileSync } from 'node:fs';
import { scoreLine } from '../lib/eval-scoring.mjs';

const AGENT_DIR = new URL('../agents/', import.meta.url);
const FIXTURES_DIR = new URL('../eval/fixtures/', import.meta.url);

const SEV_ORDER = { ok: 0, info: 1, low: 1, warning: 2, medium: 2, error: 3, high: 3, critical: 4 };

const EXPECTED = {
  'failing-disk': { agent: 'disks', anyOf: [{ subjectIncludes: 'sdX', minSeverity: 'error' }] },
  'full-pool':    { agent: 'disks', anyOf: [{ subjectIncludes: 'cache', minSeverity: 'warning' }] },
  'hot-disk':     { agent: 'thermal', anyOf: [{ subjectIncludes: 'sdY', minSeverity: 'warning' }] },
  // crashloop's fixture also runs the box hot on memory/load by design — those
  // are true observations, not false positives; listed as accepted findings.
  'crashloop':    { agent: 'general', anyOf: [{ subjectIncludes: 'crashy', minSeverity: 'warning' }],
                    accepted: [{ subjectIncludes: 'mem', minSeverity: 'warning' }, { subjectIncludes: 'load', minSeverity: 'warning' }] },
  'healthy':      { agent: 'general', expectOkOnly: true },
};

const args = process.argv.slice(2);
const onlyAgents = (args.find(a => a.startsWith('--agents')) || '').split('=')[1]?.split(',').filter(Boolean) || null;
const onlyFixture = (args.find(a => a.startsWith('--fixture')) || '').split('=')[1] || null;
const repeat = Math.max(1, Number((args.find(a => a.startsWith('--repeat')) || '').split('=')[1] || 1));

const fixtureFiles = readdirSync(FIXTURES_DIR.pathname).filter(f => f.endsWith('.json') && !f.startsWith('_'));
if (!fixtureFiles.length) { console.error('agent-eval: no fixtures in eval/fixtures/'); process.exit(2); }

const facade = await import(new URL('../lib/sources.mjs', import.meta.url).pathname);
const { createFixtureSource } = await import(new URL('../sources/fixture.mjs', import.meta.url).pathname);

const results = {};
let exitOk = true;

for (const file of fixtureFiles) {
  const name = file.replace(/\.json$/, '');
  if (onlyFixture && name !== onlyFixture) continue;
  const exp = EXPECTED[name];
  if (!exp) { console.error(`agent-eval: no EXPECTED entry for ${name} — add one`); continue; }
  const ag = exp.agent === 'any' ? 'general' : exp.agent;
  if (onlyAgents && !onlyAgents.includes(ag)) continue;

  const fixtureData = JSON.parse(readFileSync(new URL(file, FIXTURES_DIR).pathname, 'utf8'));
  const R = results[name] ??= { agent: ag, detected: 0, missed: 0, false: 0, runs: 0, okOnly: true, examples: [] };

  for (let r = 0; r < repeat; r++) {
    // The fixture source's loader keys on fixed names (latest, containers,
    // history…) — map THIS file's content onto 'latest' (the snapshot doc),
    // the shape every fixture in eval/fixtures/ uses.
    facade.__setSource(createFixtureSource({ fixtures: { latest: fixtureData } }));
    let findings, runError = null;
    try {
      const mod = await import(new URL(`${ag}.mjs`, AGENT_DIR).pathname);
      findings = await mod.run();
    } catch (e) { runError = String(e).slice(0, 220); }
    finally { facade.__resetSource(); }

    R.runs++;
    if (runError) { R.error = runError; exitOk = false; break; }

    const hay = f => (String(f.subject ?? '') + ' ' + String(f.title ?? '')).toLowerCase();

    if (exp.expectOkOnly || !Array.isArray(exp.anyOf)) {
      const bad = findings.filter(f => ((SEV_ORDER[f.severity ?? 'ok']) ?? 0) >= SEV_ORDER.warning);
      R.false += bad.length;
      R.okOnly = R.okOnly && bad.length === 0;
      R.examples.push(...bad.slice(0, 3).map(f => `${f.severity}:${f.title}`));
    } else {
      for (const e of exp.anyOf) {
        const hit = findings.find(f => {
          const sOK = !e.subjectIncludes || hay(f).includes(e.subjectIncludes.toLowerCase());
          const sevOK = ((SEV_ORDER[f.severity ?? 'ok']) ?? 0) >= ((SEV_ORDER[e.minSeverity]) ?? 0);
          return sOK && sevOK;
        });
        if (hit) R.detected++; else R.missed++;
      }
      // A "false finding" = actionable severity (>= warning) on an unrelated
      // subject. info/ok observations ("no swap used", "healthy") are not
      // false positives — counting them would punish thoroughness.
      for (const f of findings) {
        const actionable = ((SEV_ORDER[f.severity ?? 'ok']) ?? 0) >= SEV_ORDER.warning;
        const pool = [...(exp.anyOf || []), ...(exp.accepted || [])];
        const matched = pool.some(e => !e.subjectIncludes || hay(f).includes(e.subjectIncludes.toLowerCase()));
        if (actionable && !matched) { R.false++; R.examples.push(`${f.severity ?? '?'}:${f.title ?? '?'}`); }
      }
    }
  }
  R.examples = R.examples.slice(0, 3);
  if (R.missed || !R.okOnly) exitOk = false;
}

console.log('\n=== agent-eval report ===');
for (const [fixture, R] of Object.entries(results)) {
  // Scoring lives in lib/eval-scoring.mjs so it is unit-tested. An errored run
  // reports recall=n/a: no answer is not a correct answer.
  const { recallStr, falseRate } = scoreLine(R);
  console.log(`${fixture.padEnd(14)} agent=${(R.agent || '').padEnd(8)} detected=${R.detected} missed=${R.missed} false=${R.false} recall=${recallStr} falseRate=${(falseRate * 100).toFixed(0)}%${R.error ? '  ERROR: ' + R.error : ''}${R.examples.length ? '  e.g. ' + R.examples.join(' | ') : ''}`);
}
if (exitOk) console.log('PASS: all fixtures reproduce their expectations, healthy fixture clean, no false findings');
else { console.log('FAIL'); process.exit(1); }