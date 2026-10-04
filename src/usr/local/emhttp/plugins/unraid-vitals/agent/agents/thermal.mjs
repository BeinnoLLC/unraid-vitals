/**
 * Thermal specialist — disks, sensors and cooling, on the layered stack.
 *
 * Same agent id, same findings schema, same thresholds as before, but its
 * data now comes from a source object rather than Unraid paths. Point
 * VITALS_SOURCE at another source and this agent reports on that box with
 * no change here.
 */
import { runSpecialist } from './contract.mjs';
import { activeSource } from '../lib/sources.mjs';
import { hottestDisks, peaks, changes } from '../viewmodels/health.mjs';
import { UnsupportedError } from '../core/ports.mjs';

export const AGENT_ID = 'thermal';

/** Sensor rows, when the source can supply them. Returns null otherwise. */
function sensorRows(source) {
  let raw;
  try {
    raw = source.sensors();
  } catch (e) {
    if (e instanceof UnsupportedError) return null;   // source has no sensors
    throw e;
  }
  const sensors = raw?.sensors || raw;
  const list = Array.isArray(raw) ? raw : (sensors?.temps || []);
  const temps = list
    .map((s) => ({
      label: s.label || s.id || s.name || 'sensor',
      chip: s.chip || s.device || '',
      value: Number.isFinite(s.value) ? s.value : Number(s.value),
      max: Number.isFinite(s.max) ? s.max : null,
      crit: Number.isFinite(s.crit) ? s.crit : null
    }))
    .filter((s) => Number.isFinite(s.value));
  const fans = (Array.isArray(sensors?.fans) ? sensors.fans : []).map((f) => ({
    label: f.label || f.id || f.name || 'fan',
    rpm: Number.isFinite(f.rpm) ? f.rpm : Number(f.rpm)
  })).filter((f) => Number.isFinite(f.rpm));
  return { temps, fans };
}

export async function run() {
  const source = activeSource();
  const snap = source.snapshot();
  const hot = hottestDisks(source, 8);
  const sensors = sensorRows(source);

  const diskLines = hot.map((d) => `${d.name}=${d.temp ?? 'n/a'}C (${d.role})`).join(', ') || '(no disk temperatures available)';
  const temps = sensors?.temps || [];
  const fans = sensors?.fans || [];

  return runSpecialist({
    agentName: 'Vitals-Thermal',
    behavior: 'You are the thermal-management specialist inside Unraid Vitals, a background health-monitoring agent. You are given current temperature and load data and must return structured findings, nothing else.',
    systemRole: 'You are a thermal-management specialist for an Unraid server. You watch CPU load vs. temperature relationships and disk/pool temperature trends to catch cooling problems before they cause throttling or shortened drive life.',
    maxTokens: 700,
    knownSubjects: [
      ...hot.map((d) => d.name),
      ...temps.map((t) => t.label), ...temps.map((t) => t.chip).filter(Boolean),
      ...fans.map((f) => f.label),
      snap.system?.name
    ],
    sections: [
      { name: 'trends', priority: 4, text: `Thermal trends (last 6h): peaks=${JSON.stringify(peaks(source, 6))} — recent per-container/disk I/O changes: ${JSON.stringify(changes(source, 6))}` },
      { name: 'summary', priority: 5, text: `System: ${snap.system?.name} — hottest disk ${typeof snap.tempMax === 'number' ? snap.tempMax : 'n/a'}C (avg ${typeof snap.temp_avg === 'number' ? snap.temp_avg : 'n/a'}C)\nCPU load: ${snap.cpu?.total ?? 'n/a'}%\nHottest disks: ${diskLines}` },
      { name: 'disk_temps', priority: 3, text: `Disk temps now: ${diskLines}` },
      { name: 'sensors', priority: 2, text: sensors
        ? `Board/CPU sensors (label: now C, chip max C, chip crit C):\n${temps.map((t) => `- ${t.label}: ${t.value}C max=${t.max ?? 'n/a'} crit=${t.crit ?? 'n/a'}`).join('\n') || '(no hwmon temps)'}\nFans (label: rpm):\n${fans.map((f) => `- ${f.label}: ${f.rpm} rpm`).join('\n') || '(no hwmon fans)'}`
        : '(this source reports no hwmon sensors)' },
      { name: 'instructions', priority: 9, text: 'Flag: any disk above 50C, any sensor within 10C of its chip max, a fan reading 0 rpm that has a label suggesting it should spin, a rising trend even if still under threshold, and note if the array has spun-down disks masking real temps (spundown disks report null/last-known temp, not current).' }
    ]
  });
}
