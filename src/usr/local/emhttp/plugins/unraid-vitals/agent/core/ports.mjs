

export function makeDisk(o = {}) {
  return {
    name: o.name ?? '',
    temp: num(o.temp),
    
    
    reallocated: num(o.reallocated),
    pending: num(o.pending),
    crc: num(o.crc),
    powerOnHours: num(o.powerOnHours),
    model: o.model ?? '',
    sizeBytes: num(o.sizeBytes),
    fsSizeBytes: num(o.fsSizeBytes),
    fsUsedBytes: num(o.fsUsedBytes),
    
    role: o.role ?? 'data',
    state: o.state ?? 'DISK_OK'
  };
}

export function makeContainer(o = {}) {
  return {
    name: o.name ?? '',
    state: o.state ?? 'unknown',
    image: o.image ?? null,
    localDigest: o.localDigest ?? null,
    cpuPct: num(o.cpuPct),
    memBytes: num(o.memBytes),
    restartCount: num(o.restartCount),
    
    ports: Array.isArray(o.ports) ? o.ports : []
  };
}

export function makeVm(o = {}) {
  return { name: o.name ?? '', state: o.state ?? 'unknown' };
}

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
    
    ctr: normMap(o.ctr),
    
    smart: normMap(o.smart)
  };
  
  p.temp_max = p.tempMax;
  p.net_rx = p.netRx;
  p.net_tx = p.netTx;
  p.fs_used = p.fsUsed;
  return p;
}

export function makeSnapshot(o = {}) {
  
  
  
  
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

export class TransientError extends SourceError {
  constructor(message, opts = {}) {
    super(message, { ...opts, code: opts.code || 'transient', retryable: true });
    this.name = 'TransientError';
  }
}

export class UnsupportedError extends SourceError {
  constructor(message, opts = {}) {
    super(message, { ...opts, code: 'unsupported', retryable: false });
    this.name = 'UnsupportedError';
  }
}

export const SOURCE_PORT = [
  'snapshot', 'history', 'alerts', 'tail',
  'containers', 'vms', 'containerImages', 'registryDigest', 'syslogWarnings'
];

export function assertSource(source) {
  if (!source || typeof source !== 'object') throw new TypeError('source must be an object');
  if (!source.id) throw new TypeError('source must expose a string id');
  const missing = SOURCE_PORT.filter((m) => typeof source[m] !== 'function');
  if (missing.length) {
    throw new TypeError(`source "${source.id}" is missing port(s): ${missing.join(', ')}`);
  }
  return source;
}
