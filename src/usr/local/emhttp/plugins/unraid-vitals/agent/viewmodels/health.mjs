/**
 * Viewmodels — pure logic over a source's data.
 *
 * No I/O, no shell, no knowledge of Unraid. A viewmodel takes a source
 * (anything satisfying core/ports.mjs) and answers a question about it.
 * Agents are thin wrappers: parse args, pick a viewmodel, format the result.
 *
 * This is the layer that makes "integrate another source" free — every
 * question below already works against Proxmox, a fixture, or anything else
 * that satisfies the port, because none of them can see a source's guts.
 */
import { UnsupportedError } from '../core/ports.mjs';

/** Every number a health view needs, from one source. */
export function health(source) {
  const snap = source.snapshot();
  const disks = snap.disks || [];
  const data = disks.filter((d) => d.role === 'data');
  const parity = disks.filter((d) => d.role === 'parity');
  const containers = snap.docker?.containers || [];

  const temps = disks.map((d) => d.temp).filter((t) => typeof t === 'number');
  const pending = disks.reduce((s, d) => s + (d.pending || 0), 0);
  const crc = disks.reduce((s, d) => s + (d.crc || 0), 0);

  return {
    system: snap.system,
    cpu: snap.cpu,
    mem: snap.mem,
    load: snap.load,
    tempMax: typeof snap.tempMax === 'number' ? snap.tempMax : (temps.length ? Math.max(...temps) : null),
    disks: {
      total: disks.length, data: data.length, parity: parity.length,
      hottest: disks.filter((d) => typeof d.temp === 'number').sort((a, b) => b.temp - a.temp).slice(0, 3),
      withPending: disks.filter((d) => (d.pending || 0) > 0),
      withCrc: disks.filter((d) => (d.crc || 0) > 0)
    },
    containers: {
      total: containers.length,
      running: containers.filter((c) => c.state === 'running').length,
      stopped: containers.filter((c) => c.state !== 'running').map((c) => c.name),
      restarting: containers.filter((c) => c.restartCount > 0 || c.state === 'restarting')
    },
    vms: { total: (snap.vms || []).length, byState: countBy((snap.vms || []).map((v) => v.state)) },
    pendingTotal: pending,
    crcTotal: crc
  };
}

/** Peak load / memory / temperature over a window, with the time of peak. */
export function peaks(source, hours = 6) {
  const pts = source.historyWindow ? source.historyWindow(hours) : source.history();
  const pick = (fn) => {
    let best = null;
    for (const p of pts) {
      const v = fn(p);
      if (typeof v !== 'number' || Number.isNaN(v)) continue;
      if (!best || v > best.value) best = { value: v, at: p.t };
    }
    return best;
  };
  return {
    windowHours: hours,
    samples: pts.length,
    cpu: pick((p) => p.cpu),
    mem: pick((p) => p.mem),
    load: pick((p) => p.load),
    tempMax: pick((p) => p.tempMax ?? p.temp_max)
  };
}

/** Containers ranked by an arbitrary metric — the generic "biggest N" view. */
export function topContainers(source, metric = 'memBytes', n = 5) {
  const cs = source.containers() || [];
  return [...cs]
    .filter((c) => typeof c[metric] === 'number')
    .sort((a, b) => b[metric] - a[metric])
    .slice(0, n);
}

/** Disks ranked by temperature, data first. */
export function hottestDisks(source, n = 5) {
  const ds = source.snapshot().disks || [];
  return [...ds].filter((d) => typeof d.temp === 'number').sort((a, b) => b.temp - a.temp).slice(0, n);
}

/** What changed since the window opened — the "what happened" view. */
export function changes(source, hours = 6) {
  const pts = source.historyWindow ? source.historyWindow(hours) : source.history();
  const out = [];
  if (pts.length < 2) return out;

  const first = new Map(), last = new Map();
  for (const p of pts) {
    for (const [name, v] of Object.entries(p.ctr || {})) {
      if (Array.isArray(v) && typeof v[1] === 'number') {
        if (!first.has(name)) first.set(name, v[1]);
        last.set(name, v[1]);
      }
    }
  }
  for (const [name, v] of last) {
    const f = first.get(name);
    if (f === undefined) out.push(`container ${name} appeared in this window`);
    else if (v > f) out.push(`container ${name} memory +${fmtBytes(v - f)}`);
  }

  const disks = new Map();
  for (const p of pts) {
    for (const [name, v] of Object.entries(p.smart || {})) {
      if (!Array.isArray(v)) continue;
      const prev = disks.get(name);
      const cur = { temp: v[0], pending: v[2] };
      if (prev && prev.pending !== null && cur.pending !== null && cur.pending > prev.pending) {
        out.push(`disk ${name} pending sectors ${prev.pending} → ${cur.pending}`);
      }
      disks.set(name, cur);
    }
  }
  return out;
}

/** Alerts grouped by severity — what needs attention right now. */
export function alertsBySeverity(source) {
  const counts = {};
  const items = [];
  for (const a of source.alerts() || []) {
    const sev = String(a?.severity ?? 'unknown');
    counts[sev] = (counts[sev] || 0) + 1;
    items.push(a);
  }
  return { counts, items, total: items.length };
}

/**
 * Compose one prompt-ready text block. Agents use this instead of hand-
 * assembling strings, so a new source is described identically everywhere.
 */
export function healthBrief(source, hours = 6) {
  const h = health(source);
  const p = peaks(source, hours);
  const lines = [
    `System: ${h.system.name} — ${h.system.version}, uptime ${Math.floor((h.system.uptime || 0) / 3600)}h`,
    `CPU ${fmtPct(h.cpu.total)} of ${h.cpu.cores ?? '?'} cores | memory ${fmtPct(h.mem.pct)} (swap ${fmtPct(h.mem.swapPct)}) | load ${h.load.l1 ?? '?'}/${h.load.l5 ?? '?'}/${h.load.l15 ?? '?'}`,
    `Disks: ${h.disks.total} (${h.disks.data} data, ${h.disks.parity} parity), hottest ${h.tempMax ?? 'n/a'}°C`,
    h.disks.withPending.length ? `Pending sectors: ${h.disks.withPending.map((d) => `${d.name}=${d.pending}`).join(', ')}` : null,
    h.disks.withCrc.length ? `CRC errors: ${h.disks.withCrc.map((d) => `${d.name}=${d.crc}`).join(', ')}` : null,
    `Containers: ${h.containers.running}/${h.containers.total} running` + (h.containers.stopped.length ? ` (stopped: ${h.containers.stopped.join(', ')})` : ''),
    h.vms.total ? `VMs: ${h.vms.total} (${Object.entries(h.vms.byState).map(([k, v]) => `${k}=${v}`).join(', ')})` : null,
    p.samples ? `Last ${p.windowHours}h: ${p.samples} samples, peak cpu ${p.cpu?.value ?? 'n/a'}%, peak mem ${p.mem?.value ?? 'n/a'}%, peak temp ${p.tempMax?.value ?? 'n/a'}°C` : null
  ].filter(Boolean);
  return lines.join('\n');
}

/* helpers */
function countBy(arr) {
  const out = {};
  for (const v of arr) out[v] = (out[v] || 0) + 1;
  return out;
}
function fmtPct(v) { return typeof v === 'number' ? `${v.toFixed(1)}%` : 'n/a'; }
function fmtBytes(b) {
  if (!Number.isFinite(b)) return 'n/a';
  const u = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
  let i = 0, n = Math.abs(b);
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return `${b < 0 ? '-' : ''}${n.toFixed(1)} ${u[i]}`;
}

export { fmtBytes, fmtPct };
