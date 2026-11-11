
import {
  readStateFile, tailFile, dockerPs, dockerInspect, dockerLogs, containerImages,
  registryDigest, vmList as hostVmList, syslogWarnings, hasBin
} from '../providers/host.mjs';
import {
  makeSnapshot, makePoint, makeDisk, makeContainer, makeVm,
  UnsupportedError, TransientError
} from '../core/ports.mjs';

export const STATE_DIR = process.env.VITALS_STATE_DIR || '/var/tmp/unraid-vitals';

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
    system: d.system,          
    
    
    
    cpu: { ...d.cpu },
    mem: { ...d.mem, swapPct: d.mem?.swapPct ?? d.mem?.swap_pct, swapUsed: d.mem?.swapUsed ?? d.mem?.swap_used },
    load: { ...d.load },
    tempMax: d.temp_max ?? d.tempMax,
    temp_avg: d.temp_avg,
    array: arr,
    
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

    
    sensors() {
      const snap = source.snapshot();
      if (!snap.sensors) throw new UnsupportedError('this source reports no hwmon sensors', { provider: 'sensors' });
      return snap.sensors;
    },

    
    capabilities() {
      return {
        containers: hasBin('docker'),
        hypervisor: hasBin('virsh'),
        sensors: !!source.snapshot().sensors
      };
    },

    
    extra() {
      const alerts = readStateFile(stateDir, 'alerts.json', []);
      return Array.isArray(alerts) ? { alerts } : {};
    }
  };
  return source;
}
