/**
 * The Unraid source — every Unraid-specific assumption in the agent lives in
 * this file and nowhere else.
 *
 * It is assembled from the generic host providers, so adding a second source
 * (Proxmox, TrueNAS, a CI fixture) means writing ONE new file against
 * core/ports.mjs and registering it. No agent, viewmodel, or provider needs
 * to change, because none of them can see anything Unraid-specific.
 */
import {
  readStateFile, tailFile, dockerPs, dockerInspect, dockerLogs, containerImages,
  registryDigest, vmList as hostVmList, syslogWarnings, hasBin
} from '../providers/host.mjs';
import {
  makeSnapshot, makePoint, makeDisk, makeContainer, makeVm,
  UnsupportedError, TransientError
} from '../core/ports.mjs';

export const STATE_DIR = process.env.VITALS_STATE_DIR || '/var/tmp/unraid-vitals';

/** Assemble a snapshot from whatever the collector wrote. */
function snapshotFrom(raw) {
  const d = raw || {};
  const arr = d.array || {};
  const disks = [
    ...(arr.data || []).map((x) => makeDisk({ ...x, role: 'data' })),
    ...(arr.parity || []).map((x) => makeDisk({ ...x, role: 'parity' })),
    ...(arr.cache || []).map((x) => makeDisk({ ...x, role: 'cache' }))
  ];
  return makeSnapshot({
    time: d.time,
    system: d.system,          // pass through: disks.mjs reads system.md_state
    // Spread the native objects rather than rebuilding them: general.mjs
    // reads mem.swap_pct/swap_used and disks.mjs reads system.md_state.
    // Rebuilding from a fixed key list silently drops those.
    cpu: { ...d.cpu },
    mem: { ...d.mem, swapPct: d.mem?.swapPct ?? d.mem?.swap_pct, swapUsed: d.mem?.swapUsed ?? d.mem?.swap_used },
    load: { ...d.load },
    tempMax: d.temp_max ?? d.tempMax,
    temp_avg: d.temp_avg,
    array: arr,
    // Pass-through: the existing specialists read these blocks directly.
    sensors: d.sensors, shares: d.shares, smart: d.smart,
    net: d.net, flash: d.flash,
    docker: {
      running: d.docker?.running,
      count: d.docker?.count,
      containers: (d.docker?.containers || []).map((c) => makeContainer(c))
    },
    disks,
    vms: d.vms || []
  });
}

export function createUnraidSource({ stateDir = STATE_DIR } = {}) {
  const source = {
    id: 'unraid',
    label: 'Unraid (this server)',
    kind: 'nas',

    snapshot() {
      return snapshotFrom(readStateFile(stateDir, 'latest.json', {}));
    },

    history() {
      const h = readStateFile(stateDir, 'history.json', []);
      return (Array.isArray(h) ? h : []).map((p) => makePoint({
        t: p.t ?? p.time,
        cpu: p.cpu, mem: p.mem, load: p.load, tempMax: p.temp_max ?? p.tempMax,
        gpu: p.gpu, netRx: p.net_rx, netTx: p.net_tx, fsUsed: p.fs_used,
        ctr: p.ctr, smart: p.smart
      }));
    },

    alerts() {
      const a = readStateFile(stateDir, 'alerts.json', []);
      return Array.isArray(a) ? a : [];
    },

    /** Ring buffer is fixed-sample, not fixed-time: a short result means
     *  "limited history", never an error. Callers must handle it. */
    historyWindow(hours = 6) {
      const cutoff = Math.floor(Date.now() / 1000) - hours * 3600;
      return this.history().filter((p) => (p.t ?? 0) >= cutoff);
    },

    tail(path, lines = 200) { return tailFile(path, lines); },

    collectors() {
      return source.snapshot().docker.containers;
    },

    containers() {
      try { return dockerPs(true).map((c) => makeContainer(c)); }
      catch (e) { return []; }
    },

    vms() {
      try { return hostVmList().map((v) => makeVm(v)); }
      catch (e) {
        if (e instanceof UnsupportedError) return [];
        throw e;
      }
    },

    containerImages() { return containerImages(); },
    registryDigest(ref) { return registryDigest(ref); },

    dockerLogs(container, minutes = 30, maxLines = 150) {
      return dockerLogs(container, minutes, maxLines);
    },

    syslogWarnings(minutes = 60, maxLines = 400) {
      return syslogWarnings(minutes, maxLines, process.env.VITALS_SYSLOG || '/var/log/syslog');
    },

    /** Fan/sensor data: the collector puts it in latest.json's `sensors`
     *  block (there is no separate sensors.json). */
    sensors() {
      const snap = source.snapshot();
      if (!snap.sensors) throw new UnsupportedError('this source reports no hwmon sensors', { provider: 'sensors' });
      return snap.sensors;
    },

    /** Capabilities this host actually has — lets a viewmodel degrade
     *  gracefully instead of crashing on a source without VMs/containers. */
    capabilities() {
      return {
        containers: hasBin('docker'),
        hypervisor: hasBin('virsh'),
        sensors: !!source.snapshot().sensors
      };
    },

    /** Sources may add facts the core port does not define. */
    extra() {
      const alerts = readStateFile(stateDir, 'alerts.json', []);
      return Array.isArray(alerts) ? { alerts } : {};
    }
  };
  return source;
}
