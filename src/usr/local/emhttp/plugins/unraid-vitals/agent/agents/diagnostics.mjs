/**
 * Diagnostics specialist — deep root-cause scan over a rolling window.
 *
 * Different from the per-domain specialists (disks/thermal/pools/general/
 * network), which each look at the LATEST snapshot in isolation: this one
 * looks BACK across the configured window (default 6h, matches
 * DIAG_WINDOW_HOURS in Settings) and correlates across domains — "CPU temp
 * rose right after container X started churning", "disk fill rate
 * accelerated the same hour SMB errors began" — the kind of causal link a
 * single-snapshot agent structurally cannot see.
 *
 * Runs the SAME prompt across 2-3 local models (VITALS_DIAG_MODELS) and
 * merges via runSpecialistMultiModel — cross-model corroboration matters
 * more here than in the fast per-tick agents, because this is exactly the
 * kind of multi-signal correlation task where a single small local model
 * is most likely to hallucinate a causal link that isn't really there.
 */
import { runSpecialistMultiModel } from './contract.mjs';
import { latestSnapshot, historyWindow, alerts, recentSyslogWarnings } from '../lib/sources.mjs';

export const AGENT_ID = 'diagnostics';

const DEFAULT_MODELS = ['qwen3:14b', 'llama3.1:8b', 'gemma2:9b'];

function summarizeWindow(points) {
  if (!points.length) return '(no samples in window — collector may have just started, or window exceeds retained history)';
  const first = points[0], last = points[points.length - 1];
  const cpu = points.map(p => p.cpu_pct).filter(v => v != null);
  const temp = points.map(p => p.temp_max).filter(v => v != null);
  const mem = points.map(p => p.mem_pct).filter(v => v != null);
  const range = (arr) => arr.length ? `${Math.min(...arr).toFixed(1)}–${Math.max(...arr).toFixed(1)} (avg ${(arr.reduce((a, b) => a + b, 0) / arr.length).toFixed(1)})` : 'n/a';
  return `Window: ${new Date(first.time * 1000).toISOString()} to ${new Date(last.time * 1000).toISOString()} (${points.length} samples)
CPU % range: ${range(cpu)}
Hottest-disk temp C range: ${range(temp)}
Memory % range: ${range(mem)}`;
}

export async function run() {
  const hours = Number(process.env.VITALS_DIAG_WINDOW_HOURS || 6);
  const models = (process.env.VITALS_DIAG_MODELS || DEFAULT_MODELS.join(','))
    .split(',').map(s => s.trim()).filter(Boolean).slice(0, 3);

  const snap = latestSnapshot();
  const window = historyWindow(hours);
  const alertMap = alerts();
  const openAlerts = Object.entries(alertMap || {})
    .filter(([, v]) => v && v.status !== 'ok')
    .map(([k, v]) => `${k}: ${v.status || 'unknown'} (last check ${v.last_check || 'n/a'})`);
  const syslog = recentSyslogWarnings(hours * 60, 300);

  return runSpecialistMultiModel({
    agentName: 'Vitals-Diagnostics',
    behavior: `You are the root-cause diagnostics specialist inside Unraid Vitals. You are given a ${hours}-hour window of system metrics, open alerts, and syslog warnings, and must find CORRELATIONS ACROSS DOMAINS that single-snapshot monitoring misses — not repeat what the per-domain agents already report from the latest sample alone.`,
    systemRole: `You are a root-cause diagnostics specialist for an Unraid NAS, looking at a ${hours}-hour rolling window (not just the current instant). Your job is specifically to connect signals across domains: does a CPU/temp spike line up with a container's activity, does memory pressure precede container restarts, does a syslog warning cluster align with a metric trend. Only report a correlation you can actually see in the provided window data — do not speculate about causes with no supporting numbers in the window.`,
    maxTokens: 1200,
    knownSubjects: [snap.system?.name, ...(snap.docker?.containers || []).map(c => c.name),
      ...[...(snap.array?.parity || []), ...(snap.array?.data || []), ...(snap.array?.cache || [])].map(d => d.name)],
    sections: [
      { name: 'window_summary', priority: 5, text: summarizeWindow(window) },
      { name: 'open_alerts', priority: 4, text: `Currently open alerts:\n${openAlerts.join('\n') || '(none open)'}` },
      { name: 'current', priority: 3, text: `Current snapshot: CPU ${snap.cpu?.total ?? 'n/a'}%, mem ${snap.mem?.pct ?? 'n/a'}%, load ${snap.load?.l1 ?? 'n/a'}/${snap.load?.l5 ?? 'n/a'}/${snap.load?.l15 ?? 'n/a'}, docker ${snap.docker?.running ?? 0}/${snap.docker?.count ?? 0} running.` },
      { name: 'syslog', priority: 1, text: `Syslog warnings/errors in the window (may include noise):\n${syslog.slice(-4000) || '(none captured)'}` },
      { name: 'instructions', priority: 9, text: `Look specifically for CROSS-DOMAIN correlations within the ${hours}h window: a metric trend that lines up in time with a syslog warning or a container's known behavior, a slow degradation that would be invisible in a single snapshot, or repeated flapping (alert opening/closing repeatedly) that suggests an unstable root cause rather than a one-off blip. If nothing correlates, say so as the 'ok' baseline finding rather than inventing a link.` }
    ],
    models
  });
}
