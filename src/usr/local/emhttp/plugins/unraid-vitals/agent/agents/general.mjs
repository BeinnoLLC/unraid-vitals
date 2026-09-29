/** General system-health specialist — cross-cutting synthesis + error-log triage. */
import { makeAnalysisAgent, callAnalyze, extractJson } from '../lib/smythos-client.mjs';
import { RESPONSE_CONTRACT, safeParseFindings } from './contract.mjs';
import { latestSnapshot, tail } from '../lib/sources.mjs';

export const AGENT_ID = 'general';

export async function run() {
  const snap = latestSnapshot();
  const dk = snap.docker || {};
  const stopped = (dk.containers || []).filter(c => c.state !== 'running').map(c => c.name);
  const log = tail('/var/tmp/unraid-vitals/collector.log', 120);

  const system = `You are a general-health specialist for an Unraid NAS. You look across memory pressure, container restarts/crashes, swap usage, and the collector's own error log to catch problems that don't belong to a single subsystem (disk/thermal/pool). Categorise into errors, warnings, and informational notes. ${RESPONSE_CONTRACT}`;
  const user = `System: ${snap.system?.name} — Unraid ${snap.system?.version}, uptime ${Math.floor((snap.system?.uptime || 0) / 3600)}h
Memory: ${snap.mem?.pct ?? 'n/a'}% used, swap ${snap.mem?.swap_pct ?? 0}% (${snap.mem?.swap_used ?? 0} bytes)
Load: 1m=${snap.load?.l1 ?? 'n/a'} 5m=${snap.load?.l5 ?? 'n/a'} 15m=${snap.load?.l15 ?? 'n/a'} over ${snap.load?.cores ?? '?'} cores
Docker: ${dk.running ?? 0} running / ${dk.count ?? 0} total. Stopped: ${stopped.join(', ') || 'none'}

Collector log tail (last ~120 lines, may include noise — only flag real errors):
${log.slice(-4000) || '(no log content)'}

Flag: swap actively in use (indicates memory pressure), unexpectedly high load vs core count, containers that appear stopped unexpectedly (crashed vs. intentionally down — you cannot tell for certain, say so), and any real error/exception lines in the collector log (ignore benign info-level lines).`;

  const text = await callAnalyze(
    await makeAnalysisAgent('Vitals-General',
      'You are the general system-health specialist inside Unraid Vitals, a background health-monitoring agent. You are given memory/load/container/log data and must return structured findings, nothing else.',
      { maxTokens: 1000 }),
    system, user
  );
  return safeParseFindings(extractJson(text));
}
