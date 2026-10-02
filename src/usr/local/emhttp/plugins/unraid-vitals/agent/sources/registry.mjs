/**
 * Source registry — the single place that decides which source the agent
 * talks to.
 *
 * Adding an integration is two steps: implement core/ports.mjs in a new file,
 * register it here. Everything above (viewmodels, agents) is unchanged.
 *
 *   VITALS_SOURCE=unraid            (default)
 *   VITALS_SOURCE=unraid,proxmox     fan-in: merge facts from both
 */
import { assertSource, SourceError } from '../core/ports.mjs';
import { createUnraidSource } from './unraid.mjs';

const REGISTRY = new Map();

/** Register (or replace) a source. Fails loudly on a half-built one. */
export function registerSource(source) {
  assertSource(source);
  REGISTRY.set(source.id, source);
  return source;
}

export function getSource(id) {
  const s = REGISTRY.get(id);
  if (!s) throw new SourceError(`no such source: ${id}`, { code: 'unknown_source' });
  return s;
}

export function listSources() { return [...REGISTRY.values()].map((s) => ({ id: s.id, label: s.label, kind: s.kind })); }

export function registerBuiltins() {
  if (!REGISTRY.has('unraid')) registerSource(createUnraidSource());
  return REGISTRY;
}

/**
 * The active source, by name. A single id returns it directly; a
 * comma-separated list returns a FUSED source that reads from each and
 * merges, so "watch two boxes" needs no change anywhere above this line.
 */
export function getActiveSource(env = process.env) {
  registerBuiltins();
  const spec = (env.VITALS_SOURCE || 'unraid').split(',').map((s) => s.trim()).filter(Boolean);
  if (spec.length === 1) return getSource(spec[0]);
  return registerSource(fuseSources(spec.map(getSource)));
}

/** Combine several sources into one that satisfies the same port. */
export function fuseSources(list) {
  if (!list.length) throw new SourceError('cannot fuse zero sources', { code: 'bad_config' });
  const id = `fused(${list.map((s) => s.id).join('+')})`;
  if (REGISTRY.has(id)) return REGISTRY.get(id);

  // Disks/containers/vms are namespaced per source so a fused view never
  // shows two servers' "disk1" as if they were the same disk.
  const ns = (prefix, name) => `${prefix}/${name}`;

  const fused = {
    id,
    label: list.map((s) => s.label || s.id).join(' + '),
    kind: 'fused',

    snapshot() {
      const snaps = list.map((s) => s.snapshot());
      const base = snaps[0];
      for (let i = 1; i < snaps.length; i++) {
        const s = snaps[i];
        base.disks.push(...s.disks.map((d) => ({ ...d, name: ns(s.disks.length ? s.id : '', d.name) })));
        base.vms.push(...s.vms.map((v) => ({ ...v, name: ns('', v.name) })));
        base.docker.containers.push(...s.docker.containers);
        base.docker.count += s.docker.count;
        base.docker.running += s.docker.running;
      }
      base.disks = base.disks.map((d, idx) => d);
      return base;
    },

    history(hours) { return list.flatMap((s) => s.history(hours)); },
    historyWindow(hours) { return list.flatMap((s) => (s.historyWindow ? s.historyWindow(hours) : [])); },
    alerts() { return list.flatMap((s) => s.alerts()); },
    tail(path, lines) { return list.map((s) => s.tail(path, lines)).filter(Boolean).join('\n'); },
    containers() { return list.flatMap((s) => s.containers()); },
    vms() { return list.flatMap((s) => s.vms()); },
    containerImages() { return list.flatMap((s) => s.containerImages()); },
    registryDigest(ref) {
      for (const s of list) { const d = s.registryDigest(ref); if (d) return d; }
      return null;
    },
    syslogWarnings(minutes, max) { return list.map((s) => s.syslogWarnings(minutes, max)).filter(Boolean).join('\n'); },
    capabilities() { return Object.assign({}, ...list.map((s) => (s.capabilities ? s.capabilities() : {}))); },
    sensors() {
      for (const s of list) { try { return s.sensors(); } catch { /* try next */ } }
      throw new SourceError('no source provides sensors', { code: 'unsupported' });
    },
    sources: list
  };
  return registerSource(fused);
}
