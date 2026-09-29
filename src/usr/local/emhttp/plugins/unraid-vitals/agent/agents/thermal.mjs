/** Thermal specialist — CPU / disk / pool temperatures and fan-adjacent risk. */
import { runSpecialist } from './contract.mjs';
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
  const sensors = snap.sensors || {};
  const temps = (sensors.temps || []).slice(0, 40);
  const fans = (sensors.fans || []).slice(0, 20);

  return runSpecialist({
    agentName: 'Vitals-Thermal',
    behavior: 'You are the thermal-management specialist inside Unraid Vitals, a background health-monitoring agent. You are given current temperature and load data and must return structured findings, nothing else.',
    systemRole: 'You are a thermal-management specialist for an Unraid server. You watch CPU load vs. temperature relationships and disk/pool temperature trends to catch cooling problems before they cause throttling or shortened drive life.',
    maxTokens: 700,
    knownSubjects: [...disks.map(d => d.name), ...temps.map(t => t.label), ...temps.map(t => t.id), ...fans.map(f => f.label), ...fans.map(f => f.id)],
    sections: [
      { name: 'summary', priority: 5, text: `Current hottest disk: ${snap.temp_max ?? 'n/a'}C (avg ${snap.temp_avg ?? 'n/a'}C)
CPU load: ${snap.cpu?.total ?? 'n/a'}%
Hottest-disk trend over the last ~2h (recent 10-sample avg minus earlier 10-sample avg, degrees C): ${trend}
Sample count in window: ${tSeries.length}` },
      { name: 'disk_temps', priority: 3, text: `Disk temps now: ${disks.map(d => `${d.name}=${d.temp}C`).join(', ') || '(none)'}` },
      { name: 'sensors', priority: 2, text: `Board/CPU sensors (label: now C, chip max C, chip crit C):
${temps.map(t => `- ${t.label}: ${t.value}C max=${t.max ?? 'n/a'} crit=${t.crit ?? 'n/a'}`).join('\n') || '(no hwmon temps)'}
Fans (label: rpm):
${fans.map(f => `- ${f.label}: ${f.rpm} rpm`).join('\n') || '(no hwmon fans)'}` },
      { name: 'instructions', priority: 9, text: 'Flag: any disk above 50C, any sensor within 10C of its chip max, a fan reading 0 rpm that has a label suggesting it should spin, a rising trend even if still under threshold, and note if the array has spun-down disks masking real temps (spundown disks report null/last-known temp, not current).' }
    ]
  });
}
