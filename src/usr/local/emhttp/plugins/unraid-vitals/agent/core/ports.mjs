/**
 * Core ports — the contracts every layer above depends on.
 *
 * NOTHING in this file knows what Unraid is. It describes *what the rest of
 * the agent needs from the outside world*, so a second source (Proxmox,
 * TrueNAS, a cloud instance, a synthetic test fixture) can be added by
 * implementing these same shapes — not by editing existing code.
 *
 * Layering rule for this codebase:
 *
 *   core/      pure contracts + errors. No I/O, no vendor, no env vars.
 *   providers/ the only place that shells out or opens a socket. Swappable.
 *   sources/   assemble providers into a named SOURCE (e.g. Unraid).
 *   viewmodels/pure logic over a source's data. No I/O.
 *   agents/    the runnable viewmodels (cron entry points).
 *
 * Dependency rule: arrows point DOWN only. agents -> sources -> providers
 * -> core. Nothing in a lower layer may import a higher one.
 */

/** A single disk/array member as every layer expects to see it. */
export function makeDisk(o = {}) {
  return {
    name: o.name ?? '',
    temp: num(o.temp),
    // SMART counters are deltas-over-window or absolutes depending on the
    // source; the view layer only ever renders them, never assumes.
    reallocated: num(o.reallocated),
    pending: num(o.pending),
    crc: num(o.crc),
    powerOnHours: num(o.powerOnHours),
    model: o.model ?? '',
    sizeBytes: num(o.sizeBytes),
    fsSizeBytes: num(o.fsSizeBytes),
    fsUsedBytes: num(o.fsUsedBytes),
    // role: data | parity | cache | flash
    role: o.role ?? 'data',
    state: o.state ?? 'DISK_OK'
  };
}

/** A running (or not) container as every layer expects to see it. */
export function makeContainer(o = {}) {
  return {
    name: o.name ?? '',
    state: o.state ?? 'unknown',
    image: o.image ?? null,
    localDigest: o.localDigest ?? null,
    cpuPct: num(o.cpuPct),
    memBytes: num(o.memBytes),
    restartCount: num(o.restartCount),
    // Present only for sources that can report it (Docker does).
    ports: Array.isArray(o.ports) ? o.ports : []
  };
}

/** A virtual machine. Optional for sources without a hypervisor. */
export function makeVm(o = {}) {
  return { name: o.name ?? '', state: o.state ?? 'unknown' };
}

/**
 * One minute-resolution sample of whole-system telemetry.
 *
 * Carries BOTH the camelCase port names and the collector's native
 * snake_case keys. The camelCase half is the contract new code uses; the
 * snake_case half exists because timeline.mjs (and the historical JSONL
 * rollups) are already written against the raw on-disk shape, and silently
 * renaming their fields would quietly zero every net/fill number in every
 * research answer. Dropping the native keys is a separate, deliberate change.
 */
export function makePoint(o = {}) {
  const p = {
    t: num(o.t ?? Math.floor(Date.now() / 1000)),
    cpu: num(o.cpu),
    mem: num(o.mem),
    load: num(o.load),
    tempMax: num(o.tempMax ?? o.temp_max),
    gpu: num(o.gpu),
    netRx: num(o.netRx ?? o.net_rx),
    netTx: num(o.netTx ?? o.net_tx),
    fsUsed: num(o.fsUsed ?? o.fs_used),
    // Per-entity sub-series. Keys are entity names; values are numbers.
    ctr: normMap(o.ctr),
    // Per-disk sub-series: name -> [temp, realloc, pending]
    smart: normMap(o.smart)
  };
  // Native on-disk aliases — see the note above.
  p.temp_max = p.tempMax;
  p.net_rx = p.netRx;
  p.net_tx = p.netTx;
  p.fs_used = p.fsUsed;
  return p;
}

/**
 * Whole-system facts as of now.
 *
 * Like makePoint(), this carries BOTH the normalized port fields (disks,
 * tempMax, mem.pct) and the collector's native shape (array, temp_max,
 * sensors, shares, smart, net, flash). The native half is not decoration:
 * the existing specialists read snap.array, snap.temp_max, snap.sensors,
 * snap.shares, snap.smart, snap.net and snap.flash directly. Dropping them
 * does not throw — it silently reports "no disks" and "no sensors", which is
 * worse than a crash. Migrate a module by adding the native read to the
 * viewmodels, then remove the alias.
 */
export function makeSnapshot(o = {}) {
  // system/mem/cpu keep BOTH halves: the normalized keys plus every key the
  // source supplied. Rebuilding these objects from a fixed key list is what
  // silently broke disks.mjs (reads system.md_state) and general.mjs (reads
  // mem.swap_pct) — a dropped key reports "unknown" instead of failing.
  const snap = {
    time: num(o.time ?? Math.floor(Date.now() / 1000)),
    system: { ...o.system, name: o.system?.name ?? '', version: o.system?.version ?? '', uptime: num(o.system?.uptime) },
    cpu: { ...o.cpu, total: num(o.cpu?.total), cores: num(o.cpu?.cores) },
    mem: {
      ...o.mem,
      pct: num(o.mem?.pct),
      swapPct: num(o.mem?.swapPct ?? o.mem?.swap_pct),
      swapUsed: num(o.mem?.swapUsed ?? o.mem?.swap_used)
    },
    load: { l1: num(o.load?.l1), l5: num(o.load?.l5), l15: num(o.load?.l15), cores: num(o.load?.cores) },
    tempMax: num(o.tempMax ?? o.temp_max),
    docker: {
      running: num(o.docker?.running),
      count: num(o.docker?.count),
      containers: Array.isArray(o.docker?.containers) ? o.docker.containers.map(makeContainer) : []
    },
    disks: Array.isArray(o.disks) ? o.disks.map(makeDisk) : [],
    vms: Array.isArray(o.vms) ? o.vms.map(makeVm) : []
  };
  // Native on-disk aliases — see the note above. These are pass-through, not
  // recomputed, so a source with extra subsystem data keeps it for free.
  const arr = o.array || o.disksByRole;
  if (arr) snap.array = arr;
  for (const k of ['sensors', 'shares', 'smart', 'net', 'flash', 'array_state', 'mdState', 'temps']) {
    if (o[k] !== undefined) snap[k] = o[k];
  }
  snap.temp_max = snap.tempMax;
  if (o.temp_avg !== undefined) snap.temp_avg = num(o.temp_avg);
  return snap;
}

function num(v) { return typeof v === 'number' && Number.isFinite(v) ? v : null; }

function normMap(m) {
  if (!m || typeof m !== 'object') return {};
  const out = {};
  for (const [k, v] of Object.entries(m)) out[String(k)] = Array.isArray(v) ? v.map((x) => (Number.isFinite(x) ? x : null)) : num(v);
  return out;
}

/**
 * Thrown by any provider that cannot serve a request. Carries enough
 * context for the viewmodel to decide whether to retry, fail over to
 * another endpoint, or surface the gap — without parsing strings.
 */
export class SourceError extends Error {
  constructor(message, { code = 'source_error', cause = null, retryable = false, provider = null } = {}) {
    super(message);
    this.name = 'SourceError';
    this.code = code;
    this.retryable = retryable;
    this.provider = provider;
    if (cause) this.cause = cause;
  }
}

/** A provider/endpoint that is temporarily unusable — retry or fail over. */
export class TransientError extends SourceError {
  constructor(message, opts = {}) {
    super(message, { ...opts, code: opts.code || 'transient', retryable: true });
    this.name = 'TransientError';
  }
}

/** The data does not exist / this source cannot answer that at all. */
export class UnsupportedError extends SourceError {
  constructor(message, opts = {}) {
    super(message, { ...opts, code: 'unsupported', retryable: false });
    this.name = 'UnsupportedError';
  }
}

/**
 * The interface a named source must satisfy. Not enforced at runtime by
 * anything clever — it is the written contract, and `sources/registry.mjs`
 * does a shallow check so a half-implemented source fails loudly at load
 * rather than silently returning empty data to an agent.
 *
 * Required:
 *   id                     stable name, e.g. 'unraid'
 *   snapshot()             -> makeSnapshot()
 *   history(hours)         -> makePoint[]
 *   alerts()               -> object[]
 *   tail(path, lines)      -> string        (log tail for triage)
 *   containers()           -> makeContainer[]
 *   vms()                  -> makeVm[]
 *   containerImages()      -> {name,image,localDigest}[]
 *   registryDigest(ref)    -> string|null
 *   syslogWarnings(min,max)-> string
 * Optional (may throw UnsupportedError):
 *   events({sinceHours,entity,limit}) -> object[]
 */
export const SOURCE_PORT = [
  'snapshot', 'history', 'alerts', 'tail',
  'containers', 'vms', 'containerImages', 'registryDigest', 'syslogWarnings'
];

/** Shallow conformance check used by the registry on load. */
export function assertSource(source) {
  if (!source || typeof source !== 'object') throw new TypeError('source must be an object');
  if (!source.id) throw new TypeError('source must expose a string id');
  const missing = SOURCE_PORT.filter((m) => typeof source[m] !== 'function');
  if (missing.length) {
    throw new TypeError(`source "${source.id}" is missing port(s): ${missing.join(', ')}`);
  }
  return source;
}
