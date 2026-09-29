/** Storage-pool specialist — cache/pool fill, balance, and share placement. */
import { makeAnalysisAgent, callAnalyze, extractJson } from '../lib/smythos-client.mjs';
import { RESPONSE_CONTRACT, safeParseFindings } from './contract.mjs';
import { latestSnapshot } from '../lib/sources.mjs';

export const AGENT_ID = 'pools';

export async function run() {
  const snap = latestSnapshot();
  const pools = snap.array?.cache || [];
  const shares = snap.shares?.list || [];
  const poolOnly = shares.filter(s => s.pool === 'only');

  const system = `You are a storage-pool specialist for an Unraid NAS. You watch cache/pool device fill levels and share placement (pool-only vs. pool+array vs. array-only) to catch pools that are about to fill up or shares misconfigured for their workload. ${RESPONSE_CONTRACT}`;
  const user = `Pool devices (name, size, used%, temp):
${pools.map(p => `- ${p.name}: size=${p.size ?? 'n/a'} used=${p.usedPct?.toFixed?.(1) ?? 'n/a'}% temp=${p.temp ?? 'n/a'}C`).join('\n') || '(none reported)'}

Shares using the pool: ${snap.shares?.cache ?? 0} of ${snap.shares?.total ?? 0} total.
Pool-only shares (no array fallback — a full pool blocks writes entirely): ${poolOnly.map(s => s.name).join(', ') || 'none'}

Flag: any pool device above 85% used (rising toward full blocks all writes to pool-only shares), and call out if a pool-only share exists alongside a pool near capacity — that is a specific, actionable risk, not a generic warning.`;

  const text = await callAnalyze(
    await makeAnalysisAgent('Vitals-Pools',
      'You are the storage-pool specialist inside Unraid Vitals, a background health-monitoring agent. You are given cache/pool fill and share-placement data and must return structured findings, nothing else.',
      { maxTokens: 700 }),
    system, user
  );
  return safeParseFindings(extractJson(text));
}
