/**
 * unraid-vitals agent — the timeline: one answer to "what happened between
 * T1 and T2" for every research/diagnostics/study consumer.
 *
 * Why this exists: the plugin keeps history in three places with three
 * shapes and three time spans, and every agent that wanted "the last N
 * hours" was re-deriving (and getting wrong) which one to read:
 *
 *   ring     /var/tmp/unraid-vitals/history.json   ~2h, per-minute, rich
 *            (field 't' epoch; cpu/mem/load/temp_max/net/ctr/smart/...)
 *   rollups  /boot/config/plugins/unraid-vitals/history/YYYY-MM.jsonl
 *            months, per-hour aggregates (field 'h' = epoch/3600;
 *            cpu_avg/max/p95, mem_*, temp_*, net_rx/tx bytes, fill_max, smart)
 *   events   kb_events in vitals.db — alert breaches, agent findings,
 *            VM transitions, control actions (started_at/resolved_at)
 *
 * window(hours) stitches them: minute samples where the ring has them,
 * hourly rollups for everything older, plus every event in range — so a
 * "past 2 days" question and a 6-hour diagnostics scan read the SAME code
 * path and disagree only in how much of it is minute- vs hour-resolution.
 *
 * Everything here is plain arithmetic, no LLM: the point is to hand the
 * models pre-computed facts (ranges, deltas, per-container peaks, what
 * changed) instead of raw rows, which small local models mis-add.
 */
import { readFileSync, readdirSync } from 'node:fs';
import { history, latestSnapshot } from './sources.mjs';
import { listEvents } from './db.mjs';

const FLASH_HISTORY = process.env.VITALS_FLASH_HISTORY || '/boot/config/plugins/unraid-vitals/history';

const now = () => Math.floor(Date.now() / 1000);
const iso = (t) => new Date(t * 1000).toISOString().replace('T', ' ').slice(0, 16);

function stats(vals) {
  const v = vals.filter(x => typeof x === 'number' && Number.isFinite(x));
  if (!v.length) return null;
  const sorted = [...v].sort((a, b) => a - b);
  return {
    min: sorted[0], max: sorted[sorted.length - 1],
    avg: +(v.reduce((a, b) => a + b, 0) / v.length).toFixed(1),
    p95: sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * 0.95))],
    n: v.length
  };
}

/** Hourly rollup rows whose hour falls inside [from, to]. Reads only the
 *  month files that can contain the range. */
export function rollups(from, to = now()) {
  const rows = [];
  let files = [];
  try { files = readdirSync(FLASH_HISTORY).filter(f => /^\d{4}-\d{2}\.jsonl$/.test(f)).sort(); } catch { return rows; }
  const fromMonth = new Date(from * 1000).toISOString().slice(0, 7);
  const toMonth = new Date(to * 1000).toISOString().slice(0, 7);
  for (const f of files) {
    const m = f.slice(0, 7);
    if (m < fromMonth || m > toMonth) continue;
    let text = '';
    try { text = readFileSync(`${FLASH_HISTORY}/${f}`, 'utf8'); } catch { continue; }
    for (const line of text.split('\n')) {
      if (!line) continue;
      try {
        const r = JSON.parse(line);
        const t = (r.h ?? 0) * 3600;
        if (t >= from - 3599 && t <= to) rows.push({ ...r, t });
      } catch { /* one bad line must not lose the month */ }
    }
  }
  return rows.sort((a, b) => a.t - b.t);
}

/** Ring samples inside [from, to]. Ring field is 't' (NOT 'time'). */
export function samples(from, to = now()) {
  return history().filter(p => (p.t ?? 0) >= from && (p.t ?? 0) <= to);
}

/**
 * The stitched window. Returns pre-computed facts, not raw rows:
 *   coverage   — what resolution covers which part of the range
 *   scalars    — {cpu, mem, load, temp_max, gpu} stats across the range
 *   containers — per-container CPU/mem peaks + which ones appeared/vanished
 *   disks      — per-disk temp peak, SMART counter deltas (start vs end)
 *   fill       — array fill % start→end (growth rate per day)
 *   net        — total bytes moved, peak rates
 *   events     — kb_events in range (all kinds), plus flapping detection
 *   changes    — bullet list of notable state changes, for the prompt
 */
export function window(hours = 6, { entity } = {}) {
  const to = now();
  const from = to - hours * 3600;
  const ring = samples(from, to);
  const ringStart = ring.length ? ring[0].t : to;
  const roll = rollups(from, Math.min(to, ringStart));
  const events = listEvents({ sinceHours: hours, entity, limit: 500 });
  const snap = latestSnapshot();

  // --- scalars: ring gives minutes, rollups give hours; merge both ----------
  const cpuVals = [...roll.flatMap(r => [r.cpu_avg, r.cpu_max]), ...ring.map(p => p.cpu)];
  const memVals = [...roll.flatMap(r => [r.mem_avg, r.mem_max]), ...ring.map(p => p.mem)];
  const loadVals = [...roll.flatMap(r => [r.load_avg, r.load_max]), ...ring.map(p => p.load)];
  const tempVals = [...roll.flatMap(r => [r.temp_avg, r.temp_max]), ...ring.map(p => p.temp_max)];
  const gpuVals = [...roll.flatMap(r => [r.gpu_avg, r.gpu_max]), ...ring.map(p => p.gpu)];

  // --- containers (ring only — rollups don't keep per-container) -----------
  const ctr = {};
  for (const p of ring) {
    for (const [name, [cpu, memKib]] of Object.entries(p.ctr || {})) {
      const c = ctr[name] ||= { cpu: [], mem: [], first: p.t, last: p.t };
      c.cpu.push(cpu); c.mem.push(memKib); c.last = p.t;
    }
  }
  const containers = Object.entries(ctr).map(([name, c]) => ({
    name, cpu: stats(c.cpu), mem_mib: stats(c.mem.map(k => k == null ? null : Math.round(k / 1024))),
    seen_from: c.first, seen_to: c.last,
    appeared: c.first > ringStart + 120, vanished: c.last < to - 180
  })).sort((a, b) => (b.cpu?.p95 ?? 0) - (a.cpu?.p95 ?? 0));

  // --- disks: temp peaks + SMART monotonic counters start vs end ------------
  const disk = {};
  const smartRows = [...roll.map(r => ({ t: r.t, smart: r.smart })), ...ring.map(p => ({ t: p.t, smart: p.smart }))]
    .filter(x => x.smart && typeof x.smart === 'object');
  for (const { t, smart } of smartRows) {
    for (const [dev, val] of Object.entries(smart)) {
      // ring shape: [temp, realloc, pending]; rollup shape: full smart map {name, temp, reallocated, pending, ...}
      const rec = Array.isArray(val)
        ? { name: dev, temp: val[0], realloc: val[1], pending: val[2] }
        : { name: val.name || dev, temp: val.temp, realloc: val.reallocated, pending: val.pending, crc: val.crc, uncorr: val.uncorrectable };
      const d = disk[rec.name] ||= { temps: [], first: null, last: null };
      if (rec.temp != null) d.temps.push(rec.temp);
      if (!d.first) d.first = { t, ...rec };
      d.last = { t, ...rec };
    }
  }
  const disks = Object.entries(disk).map(([name, d]) => ({
    name, temp: stats(d.temps),
    realloc_delta: (d.last?.realloc ?? 0) - (d.first?.realloc ?? 0),
    pending_delta: (d.last?.pending ?? 0) - (d.first?.pending ?? 0),
    crc_delta: (d.last?.crc ?? 0) - (d.first?.crc ?? 0),
    uncorr_delta: (d.last?.uncorr ?? 0) - (d.first?.uncorr ?? 0),
  }));

  // --- fill growth ----------------------------------------------------------
  const fillSeries = [...roll.map(r => ({ t: r.t, v: r.fs_used })), ...ring.map(p => ({ t: p.t, v: p.fs_used }))].filter(x => x.v != null);
  let fill = null;
  if (fillSeries.length >= 2) {
    const a = fillSeries[0], b = fillSeries[fillSeries.length - 1];
    const days = Math.max((b.t - a.t) / 86400, 1 / 24);
    fill = { start_bytes: a.v, end_bytes: b.v, delta_bytes: b.v - a.v, per_day_bytes: Math.round((b.v - a.v) / days) };
  }

  // --- network --------------------------------------------------------------
  const rxRates = ring.map(p => p.net_rx), txRates = ring.map(p => p.net_tx);
  const rollBytes = roll.reduce((s, r) => ({ rx: s.rx + (r.net_rx || 0), tx: s.tx + (r.net_tx || 0) }), { rx: 0, tx: 0 });
  const ringBytes = ring.reduce((s, p) => ({ rx: s.rx + (p.net_rx || 0) * 60, tx: s.tx + (p.net_tx || 0) * 60 }), { rx: 0, tx: 0 });
  const net = { rx_bytes: Math.round(rollBytes.rx + ringBytes.rx), tx_bytes: Math.round(rollBytes.tx + ringBytes.tx), rx_rate: stats(rxRates), tx_rate: stats(txRates) };

  // --- events: flapping = same alert_key opened 3+ times in the window -----
  const byKey = {};
  for (const e of events) if (e.alert_key) (byKey[e.alert_key] ||= []).push(e);
  const flapping = Object.entries(byKey).filter(([, l]) => l.length >= 3).map(([k, l]) => ({ key: k, count: l.length, entity: l[0].entity, kind: l[0].kind }));

  // --- changes: prose bullets the model can quote verbatim ------------------
  const changes = [];
  for (const c of containers) {
    if (c.appeared) changes.push(`${iso(c.seen_from)} container ${c.name} first seen (started/created)`);
    if (c.vanished) changes.push(`${iso(c.seen_to)} container ${c.name} stopped reporting (stopped/removed)`);
  }
  for (const d of disks) {
    if (d.realloc_delta > 0) changes.push(`disk ${d.name}: reallocated sectors +${d.realloc_delta} within the window`);
    if (d.pending_delta > 0) changes.push(`disk ${d.name}: pending sectors +${d.pending_delta} within the window`);
    if (d.crc_delta > 0) changes.push(`disk ${d.name}: CRC errors +${d.crc_delta} within the window`);
  }
  for (const f of flapping) changes.push(`${f.kind} ${f.entity || ''} "${f.key}" flapped ${f.count}× (repeated open/resolve)`);
  for (const e of events.filter(e => e.kind === 'vm')) changes.push(`${iso(e.started_at)} ${e.summary}`);
  if (fill && Math.abs(fill.per_day_bytes) > 50e9) changes.push(`array fill changing ${(fill.per_day_bytes / 1e9).toFixed(0)} GB/day`);

  return {
    from, to, hours,
    coverage: {
      minute_samples: ring.length, hourly_rollups: roll.length,
      minute_from: ring.length ? ring[0].t : null,
      note: !ring.length && !roll.length ? 'no history in range' :
        roll.length && ring.length ? `hourly resolution ${iso(from)}–${iso(ringStart)}, per-minute after` :
        ring.length ? 'per-minute for the whole range' : 'hourly resolution only (ring buffer empty)'
    },
    scalars: { cpu: stats(cpuVals), mem: stats(memVals), load: stats(loadVals), temp_max: stats(tempVals), gpu: stats(gpuVals) },
    containers, disks, fill, net, events, flapping, changes,
    current: { cpu: snap.cpu?.total, mem: snap.mem?.pct, load: snap.load?.l1, temp_max: snap.temp_max, docker: snap.docker && `${snap.docker.running}/${snap.docker.count}` }
  };
}

/** Compact prose rendering of window() for a prompt section. Numbers are
 *  already aggregated, so the model reads facts rather than doing sums. */
export function describeWindow(w) {
  const s = (st, unit = '') => st ? `${st.min}${unit}–${st.max}${unit} (avg ${st.avg}${unit}, p95 ${st.p95}${unit})` : 'n/a';
  const lines = [
    `Window ${iso(w.from)} → ${iso(w.to)} (${w.hours}h). Coverage: ${w.coverage.note}; ${w.coverage.minute_samples} minute samples, ${w.coverage.hourly_rollups} hourly rollups.`,
    `CPU ${s(w.scalars.cpu, '%')} | Mem ${s(w.scalars.mem, '%')} | Load1 ${s(w.scalars.load)} | Hottest disk ${s(w.scalars.temp_max, '°C')}${w.scalars.gpu ? ` | GPU ${s(w.scalars.gpu, '%')}` : ''}`,
    `Now: CPU ${w.current.cpu ?? 'n/a'}%, mem ${w.current.mem ?? 'n/a'}%, load ${w.current.load ?? 'n/a'}, hottest ${w.current.temp_max ?? 'n/a'}°C, docker ${w.current.docker ?? 'n/a'}`,
    `Network in window: ${(w.net.rx_bytes / 1e9).toFixed(2)} GB in / ${(w.net.tx_bytes / 1e9).toFixed(2)} GB out; peak ${((w.net.rx_rate?.max || 0) / 1e6).toFixed(1)} MB/s in, ${((w.net.tx_rate?.max || 0) / 1e6).toFixed(1)} MB/s out`,
  ];
  if (w.fill) lines.push(`Array used: ${(w.fill.start_bytes / 1e12).toFixed(3)} → ${(w.fill.end_bytes / 1e12).toFixed(3)} TB (${(w.fill.per_day_bytes / 1e9).toFixed(1)} GB/day)`);
  if (w.containers.length) {
    lines.push('Top containers by p95 CPU (cpu% min–max/avg, mem MiB max):');
    for (const c of w.containers.slice(0, 12)) lines.push(`- ${c.name}: cpu ${c.cpu ? `${c.cpu.min}–${c.cpu.max}/${c.cpu.avg}` : 'n/a'}, mem ${c.mem_mib?.max ?? 'n/a'}${c.appeared ? ' [appeared]' : ''}${c.vanished ? ' [vanished]' : ''}`);
  }
  const hot = w.disks.filter(d => d.temp).sort((a, b) => (b.temp.max) - (a.temp.max)).slice(0, 8);
  if (hot.length) lines.push('Hottest disks (max/avg °C): ' + hot.map(d => `${d.name} ${d.temp.max}/${d.temp.avg}`).join(', '));
  const smartMoved = w.disks.filter(d => d.realloc_delta || d.pending_delta || d.crc_delta || d.uncorr_delta);
  lines.push(smartMoved.length ? 'SMART counters that moved: ' + smartMoved.map(d => `${d.name} realloc+${d.realloc_delta} pending+${d.pending_delta} crc+${d.crc_delta}`).join('; ') : 'SMART counters: no reallocated/pending/CRC growth on any disk in the window.');
  if (w.events.length) {
    lines.push(`Events in window (${w.events.length}):`);
    for (const e of w.events.slice(0, 40)) lines.push(`- ${iso(e.started_at)} [${e.kind}/${e.entity || 'system'}] ${e.severity} ${e.status}: ${e.summary}${e.resolved_at ? ` (resolved ${iso(e.resolved_at)})` : ''}`);
  } else lines.push('Events in window: none recorded.');
  lines.push(w.changes.length ? 'Notable changes:\n' + w.changes.map(c => `- ${c}`).join('\n') : 'Notable changes: none detected.');
  return lines.join('\n');
}
