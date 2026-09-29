/** Thermal specialist — CPU / disk / pool temperatures and fan-adjacent risk. */
import { makeAnalysisAgent, callAnalyze, extractJson } from '../lib/smythos-client.mjs';
import { RESPONSE_CONTRACT, safeParseFindings } from './contract.mjs';
import { latestSnapshot, history } from '../lib/sources.mjs';

export const AGENT_ID = 'thermal';

export async function run() {
  const snap = latestSnapshot();
  const ring = history().slice(-120);
  const a = snap.array || {};
  const disks = [...(a.parity || []), ...(a.data || []), ...(a.cache || [])]
    .filter(d => d.temp != null);
  const tSeries = ring.map(p => p.temp_max).filter(v => v != null);
  const trend = tSeries.length >= 10
    ? (tSeries.slice(-10).reduce((s, v) => s + v, 0) / 10 -
       tSeries.slice(0, 10).reduce((s, v) => s + v, 0) / 10).toFixed(1)
    : 'insufficient history';

  const system = `You are a thermal-management specialist for an Unraid server. You watch CPU load vs. temperature relationships and disk/pool temperature trends to catch cooling problems before they cause throttling or shortened drive life. ${RESPONSE_CONTRACT}`;
  const user = `Current hottest disk: ${snap.temp_max ?? 'n/a'}C (avg ${snap.temp_avg ?? 'n/a'}C)
CPU load: ${snap.cpu?.total ?? 'n/a'}%
Disk temps now: ${disks.map(d => `${d.name}=${d.temp}C`).join(', ')}
Hottest-disk trend over the last ~2h (recent 10-sample avg minus earlier 10-sample avg, degrees C): ${trend}
Sample count in window: ${tSeries.length}

Flag: any disk above 50C, a rising trend even if still under threshold, and note if the array has spun-down disks masking real temps (spundown disks report null/last-known temp, not current).`;

  const text = await callAnalyze(
    await makeAnalysisAgent('Vitals-Thermal',
      'You are the thermal-management specialist inside Unraid Vitals, a background health-monitoring agent. You are given current temperature and load data and must return structured findings, nothing else.',
      { maxTokens: 700 }),
    system, user
  );
  return safeParseFindings(extractJson(text));
}
