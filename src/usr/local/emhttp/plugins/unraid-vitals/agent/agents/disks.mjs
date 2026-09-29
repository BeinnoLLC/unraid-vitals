/** Disk & array health specialist — SMART trends, fill rate, spin health. */
import { makeAnalysisAgent, callAnalyze, extractJson } from '../lib/smythos-client.mjs';
import { RESPONSE_CONTRACT, safeParseFindings } from './contract.mjs';
import { latestSnapshot } from '../lib/sources.mjs';

export const AGENT_ID = 'disks';

export async function run() {
  const snap = latestSnapshot();
  const a = snap.array || {};
  const smart = snap.smart || {};
  const disks = [...(a.parity || []), ...(a.data || []), ...(a.cache || [])];

  const system = `You are a storage-reliability specialist for an Unraid NAS. You analyze SMART attributes and array disk state to catch failing drives before they cause data loss. ${RESPONSE_CONTRACT}`;
  const user = `Array state: ${a && snap.system ? snap.system.md_state : 'unknown'}
Disks (name, type, temp C, used%, errors, spundown):
${disks.map(d => `- ${d.name} (${d.type}): temp=${d.temp ?? 'n/a'}C used=${d.usedPct?.toFixed?.(1) ?? 'n/a'}% errors=${d.numErrors ?? 0} spundown=${d.spundown}`).join('\n')}

SMART detail (name, health, power-on hours, reallocated, pending, uncorrectable, CRC errors):
${Object.values(smart).map(s => `- ${s.name}: health=${s.health ?? 'n/a'} hours=${s.hours ?? 'n/a'} realloc=${s.reallocated ?? 'n/a'} pending=${s.pending ?? 'n/a'} uncorr=${s.uncorrectable ?? 'n/a'} crc=${s.crc ?? 'n/a'}`).join('\n')}

Flag: any reallocated/pending/uncorrectable sectors above 0, rising CRC error counts, temps above 50C, disks near capacity (>90%), and drives with unusually high power-on hours relative to the fleet.`;

  const agent = await makeAnalysisAgent(
    'Vitals-Disks',
    'You are the disk-and-array reliability specialist inside Unraid Vitals, a background health-monitoring agent. You are given the current SMART and array state and must return structured findings, nothing else.',
    { maxTokens: 1100 }
  );
  const text = await callAnalyze(agent, system, user);
  return safeParseFindings(extractJson(text));
}

