/** Disk & array health specialist — SMART trends, fill rate, spin health. */
import { runSpecialist } from './contract.mjs';
import { activeSource } from '../lib/sources.mjs';
import { peaks, changes } from '../viewmodels/health.mjs';

export const AGENT_ID = 'disks';

/**
 * #117 (P20-12) floorFindings for disks: deterministic threshold rules — no
 * model arithmetic.
 *
 * Reads the SNAPSHOT only. It must never be gated on a row surviving
 * budgetPrompt(): a trimmed row is precisely the case where the model can no
 * longer see the breach and the floor is the only reporter left. Gating on the
 * prompt text here silently converted "prompt got big" into "no finding" — the
 * full-pool fixture missed deterministically whenever trimming kicked in and
 * passed whenever it didn't, which is what made it look flaky.
 *
 * Exported (not trapped in run()) so tests can pin the rules without an LLM.
 */
export function diskFloorFindings(disks = [], smart = {}) {
  const out = [];
  for (const d of disks) {
    if (typeof d.usedPct === 'number' && d.usedPct >= 90) {
      out.push({ severity: 'warning', title: `${d.name}: ${d.usedPct.toFixed(1)}% full`, detail: `${d.name} (${d.type}) is ${d.usedPct.toFixed(1)}% full — over the 90% threshold. Free space or grow the pool before writes fail.`, recommendation: 'Clean up (Cleanup tab) or grow the pool.', subject: d.name });
    }
    if ((d.numErrors ?? 0) > 0) {
      out.push({ severity: 'error', title: `${d.name}: ${d.numErrors} device errors`, detail: `${d.name} reports ${d.numErrors} device error(s) — scrub and test.`, recommendation: 'Run a scrub; if errors grow, replace the disk.', subject: d.name });
    }
  }
  for (const s of Object.values(smart)) {
    const health = String(s.health || '').toUpperCase();
    if (health === 'FAILED' || String(s.smart_status || '').toUpperCase() === 'FAILED') {
      out.push({ severity: 'critical', title: `${s.name}: SMART reports FAILED`, detail: `${s.name}'s SMART overall status is FAILED — back up and replace the disk.`, recommendation: 'Back up immediately; replace.', subject: s.name });
    } else if ((s.reallocated ?? 0) > 0) {
      out.push({ severity: 'error', title: `${s.name}: ${s.reallocated} reallocated sectors`, detail: `${s.name} has ${s.reallocated} reallocated sectors (remapped bad sectors) — watch growth.`, recommendation: 'Monitor growth weekly; back up.', subject: s.name });
    }
  }
  return out.filter((m) => m);
}

export async function run() {
  const source = activeSource();
  const snap = source.snapshot();
  const a = snap.array || {};
  const smart = snap.smart || {};
  const disks = [...(a.parity || []), ...(a.data || []), ...(a.cache || [])];

  const mandatoryHook = () => diskFloorFindings(disks, smart);

  return runSpecialist({
    agentName: 'Vitals-Disks',
    mandatoryHook,
    behavior: 'You are the disk-and-array reliability specialist inside Unraid Vitals, a background health-monitoring agent. You are given the current SMART and array state and must return structured findings, nothing else.',
    systemRole: 'You are a storage-reliability specialist for an Unraid NAS. You analyze SMART attributes and array disk state to catch failing drives before they cause data loss.',
    maxTokens: 1100,
    knownSubjects: [...disks.map(d => d.name), ...Object.values(smart).map(s => s.name), ...Object.keys(smart)],
    sections: [
      { name: 'trends', priority: 4, text: `Last-6h trends (per-disk temps and container I/O moved, with peaks — "trends, not one snapshot"):
${JSON.stringify(peaks(source, 6))}
${JSON.stringify(changes(source, 6))}` },
      { name: 'array', priority: 3, text: `Array state: ${snap.system?.md_state || snap.array_state || 'unknown'}
Disks (name, type, temp C, used%, errors, spundown, **fill-flag**):
${disks.map(d => `- ${d.name} (${d.type}): temp=${d.temp ?? 'n/a'}C used=${d.usedPct?.toFixed?.(1) ?? 'n/a'}% errors=${d.numErrors ?? 0} spundown=${d.spundown} ${typeof d.usedPct === 'number' && d.usedPct >= 90 ? '<< FILL-FLAG: OVER 90% — report as "warning"' : ''}`).join('\n')}` },
      { name: 'smart', priority: 2, text: `SMART detail (name, health, power-on hours, reallocated, pending, uncorrectable, CRC errors):
${Object.values(smart).map(s => `- ${s.name}: health=${s.health ?? 'n/a'} hours=${s.hours ?? 'n/a'} realloc=${s.reallocated ?? 'n/a'} pending=${s.pending ?? 'n/a'} uncorr=${s.uncorrectable ?? 'n/a'} crc=${s.crc ?? 'n/a'}`).join('\n')}` },
      { name: 'instructions', priority: 9, text: 'Flag: any reallocated/pending/uncorrectable sectors above 0, rising CRC error counts, temps above 50C, disks near capacity (>90%) — a disk or pool over 90% used is severity "warning", not an info note; healthy/no-issue rows stay "info" or "ok". Drives with unusually high power-on hours relative to the fleet are also worth a note.' }
    ]
  });
}
