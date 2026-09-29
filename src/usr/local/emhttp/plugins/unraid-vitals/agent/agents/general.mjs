/** General system-health specialist — cross-cutting synthesis + error-log triage. */
import { runSpecialist } from './contract.mjs';
import { latestSnapshot, tail } from '../lib/sources.mjs';

export const AGENT_ID = 'general';

export async function run() {
  const snap = latestSnapshot();
  const dk = snap.docker || {};
  const containers = dk.containers || [];
  const stopped = containers.filter(c => c.state !== 'running').map(c => c.name);
  const log = tail('/var/tmp/unraid-vitals/collector.log', 120);

  return runSpecialist({
    agentName: 'Vitals-General',
    behavior: 'You are the general system-health specialist inside Unraid Vitals, a background health-monitoring agent. You are given memory/load/container/log data and must return structured findings, nothing else.',
    systemRole: "You are a general-health specialist for an Unraid NAS. You look across memory pressure, container restarts/crashes, swap usage, and the collector's own error log to catch problems that don't belong to a single subsystem (disk/thermal/pool). Categorise into errors, warnings, and informational notes.",
    maxTokens: 1000,
    knownSubjects: [...containers.map(c => c.name), snap.system?.name],
    sections: [
      { name: 'system', priority: 5, text: `System: ${snap.system?.name} — Unraid ${snap.system?.version}, uptime ${Math.floor((snap.system?.uptime || 0) / 3600)}h
Memory: ${snap.mem?.pct ?? 'n/a'}% used, swap ${snap.mem?.swap_pct ?? 0}% (${snap.mem?.swap_used ?? 0} bytes)
Load: 1m=${snap.load?.l1 ?? 'n/a'} 5m=${snap.load?.l5 ?? 'n/a'} 15m=${snap.load?.l15 ?? 'n/a'} over ${snap.load?.cores ?? '?'} cores
Docker: ${dk.running ?? 0} running / ${dk.count ?? 0} total. Stopped: ${stopped.join(', ') || 'none'}` },
      // Lowest priority: the log tail is the first thing trimmed when the
      // prompt is over budget — it is context, the metrics are the facts.
      { name: 'collector_log', priority: 1, text: `Collector log tail (last ~120 lines, may include noise — only flag real errors):
${log.slice(-4000) || '(no log content)'}` },
      { name: 'instructions', priority: 9, text: 'Flag: swap actively in use (indicates memory pressure), unexpectedly high load vs core count, containers that appear stopped unexpectedly (crashed vs. intentionally down — you cannot tell for certain, say so), and any real error/exception lines in the collector log (ignore benign info-level lines).' }
    ]
  });
}
