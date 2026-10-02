/** General system-health specialist — cross-cutting synthesis + error-log triage. */
import { runSpecialist } from './contract.mjs';
import { activeSource, tail } from '../lib/sources.mjs';
import { healthBrief } from '../viewmodels/health.mjs';

export const AGENT_ID = 'general';

export async function run() {
  const source = activeSource();
  const snap = source.snapshot();
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
      // healthBrief already carries memory, load, cores, disks and container
      // counts — duplicating them here burned prompt budget and risked the two
      // copies disagreeing.
      { name: 'system', priority: 5, text: `${healthBrief(source, 6)}
Memory: ${snap.mem?.pct ?? 'n/a'}% used, swap ${snap.mem?.swapPct ?? snap.mem?.swap_pct ?? 0}% (${snap.mem?.swapUsed ?? snap.mem?.swap_used ?? 0} bytes)
Stopped containers (name each one): ${stopped.join(', ') || 'none'}` },
      // Lowest priority: the log tail is the first thing trimmed when the
      // prompt is over budget — it is context, the metrics are the facts.
      { name: 'collector_log', priority: 1, text: `Collector log tail (last ~120 lines, may include noise — only flag real errors):
${log.slice(-4000) || '(no log content)'}` },
      { name: 'instructions', priority: 9, text: 'Flag: swap actively in use (indicates memory pressure), unexpectedly high load vs core count, containers that appear stopped unexpectedly (crashed vs. intentionally down — you cannot tell for certain, say so), and any real error/exception lines in the collector log (ignore benign info-level lines).' }
    ]
  });
}
