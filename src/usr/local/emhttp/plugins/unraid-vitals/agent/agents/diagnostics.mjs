/**
 * Diagnostics specialist — deep root-cause scan over a rolling window.
 *
 * Different from the per-domain specialists (disks/thermal/pools/general/
 * network), which each judge the LATEST snapshot in isolation: this one
 * reads the stitched timeline for the configured window (default 6h —
 * VITALS_DIAG_WINDOW_HOURS in Settings) AND the window before it, and
 * hands the models pre-computed facts: ranges, per-container peaks, SMART
 * counter deltas, fill rate, events, flapping alerts, and an explicit
 * "this window vs previous window" delta table. Small local models are
 * bad at summing rows and good at reading a table — so timeline.mjs does
 * the arithmetic and the model does the correlation.
 *
 * Runs the SAME prompt across 2-3 local models (VITALS_DIAG_MODELS) and
 * merges via runSpecialistMultiModel: a correlation claimed by one small
 * model alone is marked tentative; 2+ agreeing is corroborated. Cross-
 * model agreement matters most exactly here, where "X caused Y" is the
 * easiest thing to hallucinate.
 */
import { selectModels } from '../lib/models.mjs';
import { runSpecialistMultiModel } from './contract.mjs';
import { activeSource, recentSyslogWarnings } from '../lib/sources.mjs';
import { window as timelineWindow, describeWindow } from '../lib/timeline.mjs';

export const AGENT_ID = 'diagnostics';
export const KB_REPORT = `${Math.max(1, Number(process.env.VITALS_DIAG_WINDOW_HOURS || 6))}-hour diagnostics scan`;

// Used only when the studios cannot be reached for discovery at all.
const DEFAULT_MODELS = ['qwen3:14b', 'ministral-3:latest', 'devstral-small-2:latest'];

function deltaTable(cur, both) {
  const rows = [];
  const pick = (k, unit = '') => {
    const c = cur.scalars[k], b = both.scalars[k];
    if (!c || !b) return;
    rows.push(`${k}: this window avg ${c.avg}${unit} / max ${c.max}${unit} — 2×window avg ${b.avg}${unit} / max ${b.max}${unit} → ${c.avg > b.avg + 2 ? 'RISING' : c.avg < b.avg - 2 ? 'falling' : 'flat'}`);
  };
  pick('cpu', '%'); pick('mem', '%'); pick('load'); pick('temp_max', '°C'); pick('gpu', '%');
  if (cur.fill && both.fill) rows.push(`array fill rate: this window ${(cur.fill.per_day_bytes / 1e9).toFixed(1)} GB/day vs 2×window ${(both.fill.per_day_bytes / 1e9).toFixed(1)} GB/day`);
  rows.push(`events: this window ${cur.events.length}, previous+this ${both.events.length}; flapping keys now ${cur.flapping.length}`);
  const newCtrs = cur.containers.filter(c => c.appeared).map(c => c.name);
  const goneCtrs = cur.containers.filter(c => c.vanished).map(c => c.name);
  if (newCtrs.length) rows.push(`containers appeared this window: ${newCtrs.join(', ')}`);
  if (goneCtrs.length) rows.push(`containers vanished this window: ${goneCtrs.join(', ')}`);
  return rows.join('\n');
}

export async function run() {
  const hours = Math.max(1, Number(process.env.VITALS_DIAG_WINDOW_HOURS || 6));
  const models = await selectModels({
    enabled: process.env.VITALS_DIAG_MODELS || '',
    prefer: ['gemma3:12b', 'qwen2.5:7b', 'deepseek-coder-v2:16b'],
    limit: 3
  });

  const snap = activeSource().snapshot();
  const cur = timelineWindow(hours);
  const both = timelineWindow(hours * 2);
  const syslog = recentSyslogWarnings(hours * 60, 250);

  const known = [snap.system?.name,
    ...(snap.docker?.containers || []).map(c => c.name),
    ...cur.containers.map(c => c.name),
    ...cur.disks.map(d => d.name),
    ...[...(snap.array?.parity || []), ...(snap.array?.data || []), ...(snap.array?.cache || [])].map(d => d.name),
    ...cur.events.map(e => e.entity)].filter(Boolean);

  return runSpecialistMultiModel({
    agentName: 'Vitals-Diagnostics',
    behavior: `You are the root-cause diagnostics specialist inside Unraid Vitals. You receive a pre-aggregated ${hours}-hour timeline (ranges, per-container peaks, SMART deltas, events, flapping alerts) plus a comparison against the preceding window, and must find CORRELATIONS ACROSS DOMAINS and CHANGES VERSUS THE PREVIOUS WINDOW — not restate the latest sample.`,
    systemRole: `You are a root-cause diagnostics specialist for an Unraid NAS reviewing the last ${hours} hours. All arithmetic is already done for you — do not recompute averages. Your job: (1) connect signals across domains that line up in time (a container's CPU peak with a temperature rise; memory pressure preceding a container vanishing; a syslog warning cluster with a SMART counter moving), (2) call out what is different from the previous window (RISING/falling markers, new/vanished containers, new events, alert flapping), (3) rank by consequence. Only claim a correlation the provided numbers actually support; if nothing correlates, the single 'ok' baseline finding is the correct answer.`,
    maxTokens: 1300,
    knownSubjects: known,
    sections: [
      { name: 'timeline', priority: 5, text: describeWindow(cur) },
      { name: 'vs_previous', priority: 4, text: `This window vs the ${hours}h before it:\n${deltaTable(cur, both)}` },
      { name: 'syslog', priority: 1, text: `Syslog warnings/errors in the window (may include noise):\n${syslog.slice(-3500) || '(none captured)'}` },
      { name: 'instructions', priority: 9, text: 'Report findings as: what changed or correlated, the evidence (quote the numbers/timestamps from the timeline), and the concrete next check. Use the entity names exactly as given. Prefer fewer, well-evidenced findings over many weak ones.' }
    ],
    models
  });
}
