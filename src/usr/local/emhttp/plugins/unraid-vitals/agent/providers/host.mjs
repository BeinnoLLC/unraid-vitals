
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { TransientError, UnsupportedError, SourceError } from '../core/ports.mjs';

export function run(bin, args, { timeout = 8000, encoding = 'utf8' } = {}) {
  try {
    return execFileSync(bin, args, { encoding, timeout });
  } catch {
    return null;
  }
}

const binCache = new Map();
export function hasBin(bin) {
  if (binCache.has(bin)) return binCache.get(bin);
  const ok = !!run('/usr/bin/which', [bin], { timeout: 3000 });
  binCache.set(bin, ok);
  return ok;
}

export function readJson(path, fallback = null) {
  try {
    return JSON.parse(readFileSync(path, 'utf8'));
  } catch {
    return fallback;
  }
}

export function tailFile(path, lines = 200) {
  const out = run('/usr/bin/tail', ['-n', String(lines), path], { timeout: 5000 });
  return out ?? '';
}

export function dockerPs(all = false) {
  const args = ['ps', '--format', '{{.Names}}|{{.State}}|{{.Image}}'];
  if (all) args.push('--all');
  const out = run('/usr/bin/docker', args);
  if (out === null) {
    throw new UnsupportedError('docker CLI not available on this host', { provider: 'docker' });
  }
  return out.split('\n').map(s => s.trim()).filter(Boolean).map((line) => {
    const [name, state, image] = line.split('|');
    return { name: name || '', state: state || 'unknown', image: image || null };
  });
}

export function dockerInspect(name) {
  const out = run('/usr/bin/docker', ['inspect', name], { timeout: 5000 });
  if (!out) return null;
  try {
    return JSON.parse(out)[0] || null;
  } catch {
    return null;
  }
}

export function dockerLogs(container, minutes = 30, maxLines = 150) {
  const out = run('/usr/bin/docker', ['logs', '--since', `${minutes}m`, '--tail', String(maxLines), container], { timeout: 8000 });
  return (out ?? '').slice(0, 20000);
}

export function containerImages() {
  const containers = dockerPs(true);
  return containers.map(({ name }) => {
    const info = dockerInspect(name);
    if (!info) return { name, image: null, localDigest: null };
    const image = info?.Config?.Image || null;
    const digests = Array.isArray(info?.RepoDigests) ? info.RepoDigests : [];
    const first = digests[0] || '';
    const localDigest = first.includes('@sha256:') ? first.split('@')[1] : null;
    return { name, image, localDigest };
  });
}

export function registryDigest(imageRef) {
  const out = run('/usr/bin/docker', ['manifest', 'inspect', '--verbose', imageRef], { timeout: 15000 });
  if (!out) return null;
  try {
    const parsed = JSON.parse(out);
    const d = Array.isArray(parsed) ? parsed[0]?.Descriptor?.digest : parsed?.Descriptor?.digest;
    return d || null;
  } catch {
    return null;
  }
}

let hypervisor;
export function detectHypervisor() {
  if (hypervisor !== undefined) return hypervisor;
  if (hasBin('virsh')) hypervisor = 'libvirt';
  else if (hasBin('VBoxManage') || hasBin('prlctl')) hypervisor = 'other';
  else hypervisor = 'none';
  return hypervisor;
}

export function vmList() {
  if (detectHypervisor() === 'none') {
    throw new UnsupportedError('no supported hypervisor on this host', { provider: 'vm' });
  }
  if (detectHypervisor() !== 'libvirt') {
    throw new UnsupportedError('hypervisor present but not yet supported — add a provider here', { provider: 'vm' });
  }
  const raw = run('/usr/bin/virsh', ['list', '--all', '--name']);
  if (raw === null) throw new TransientError('virsh failed to list domains', { provider: 'vm' });
  const names = raw.split('\n').map(s => s.trim()).filter(Boolean);
  return names.map((name) => {
    let state = 'unknown';
    const info = run('/usr/bin/virsh', ['dominfo', name], { timeout: 5000 });
    if (info) {
      const m = info.match(/^State:\s*(.+)$/m);
      if (m) state = m[1].trim();
    }
    return { name, state };
  });
}

export function syslogWarnings(minutes = 60, maxLines = 400, logPath = '/var/log/syslog') {
  const byJournal = process.env.VITALS_LOG_SOURCE === 'journald';
  if (byJournal) {
    const out = run('/usr/bin/journalctl',
      ['--since', `-${minutes} min`, '--priority=warning..alert', '--no-pager', '-n', String(maxLines)],
      { timeout: 8000 });
    if (out !== null) return out;
  }
  if (!exists(logPath)) {
    const out = run('/usr/bin/journalctl',
      ['--since', `-${minutes} min`, '--priority=warning..alert', '--no-pager', '-n', String(maxLines)],
      { timeout: 8000 });
    if (out !== null) return out;
  }
  const out = run('/bin/sh', ['-c',
    `awk -v cutoff="$(date -d '-${minutes} minutes' '+%b %e %H:%M:%S' 2>/dev/null)" '1' ${logPath} 2>/dev/null | tail -n ${maxLines}`
  ], { timeout: 8000 });
  return out ?? '';
}

function exists(p) {
  try { readFileSync(p); return true; } catch { return false; }
}

export function readStateFile(stateDir, name, fallback = null) {
  return readJson(`${stateDir}/${name}`, fallback);
}
