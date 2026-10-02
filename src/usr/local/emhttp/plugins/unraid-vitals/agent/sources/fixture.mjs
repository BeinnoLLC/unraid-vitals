/**
 * A synthetic source — the worked example of "integrate any service".
 *
 * It implements core/ports.mjs against a plain JSON fixture, with no Unraid
 * code at all: no docker, no virsh, no syslog. It exists for two reasons:
 *
 *   1. It is the test harness. Every agent can be exercised offline with no
 *      NAS, no LLM studio and no network — which is why the offline test
 *      suite runs in 100ms instead of 8 minutes.
 *   2. It is the template. Copy this file, change how it fetches data, and
 *      you have a new integration. Nothing else in the codebase changes.
 */
import { readFileSync } from 'node:fs';
import { makeSnapshot, makePoint, makeDisk, makeContainer, makeVm, UnsupportedError } from '../core/ports.mjs';

/**
 * @param {object} opts
 * @param {object} [opts.fixtures] in-memory data, highest precedence
 * @param {string} [opts.dir]      directory of <name>.json fixture files
 * @param {string} [opts.id]
 * @param {string} [opts.label]
 */
export function createFixtureSource({ fixtures = {}, dir = null, id = 'fixture', label = 'Fixture', kind = 'test' } = {}) {
  const load = (name, fallback = null) => {
    if (name in fixtures) return fixtures[name];
    if (dir) {
      try { return JSON.parse(readFileSync(`${dir}/${name}.json`, 'utf8')); } catch { return fallback; }
    }
    return fallback;
  };

  return {
    id, label, kind,

    snapshot() {
      const d = load('latest', {});
      const arr = d.array || {};
      return makeSnapshot({
        time: d.time,
        system: d.system,
        cpu: d.cpu, mem: d.mem, load: d.load,
        tempMax: d.temp_max ?? d.tempMax,
        docker: {
          running: d.docker?.running, count: d.docker?.count,
          containers: (load('containers', null) || d.docker?.containers || []).map((c) => makeContainer(c))
        },
        disks: [
          ...(arr.data || []).map((x) => makeDisk({ ...x, role: 'data' })),
          ...(arr.parity || []).map((x) => makeDisk({ ...x, role: 'parity' }))
        ],
        vms: (load('vms', null) || d.vms || []).map((v) => (typeof v === 'string' ? { name: v, state: 'unknown' } : v))
      });
    },

    history() { const h = load('history', []); return (Array.isArray(h) ? h : []).map((p) => makePoint(p)); },

    historyWindow(hours = 6) {
      const cutoff = Math.floor(Date.now() / 1000) - hours * 3600;
      return this.history().filter((p) => (p.t ?? 0) >= cutoff);
    },

    alerts() { const a = load('alerts', []); return Array.isArray(a) ? a : []; },
    tail(name, lines = 200) {
      const t = load(name, '');
      if (Array.isArray(t)) return t.slice(-lines).join('\n');
      return String(t);
    },
    containers() { return (load('containers', []) || []).map((c) => makeContainer(c)); },
    vms() { return (load('vms', []) || []).map((v) => makeVm(v)); },
    containerImages() { return load('containerImages', []) || []; },
    registryDigest() { return null; },
    syslogWarnings(minutes = 60, maxLines = 400) { return this.tail('syslog', maxLines); },
    capabilities() { return { containers: true, hypervisor: true, sensors: false }; },
    sensors() { throw new UnsupportedError('fixture source has no sensors', { provider: id }); }
  };
}
