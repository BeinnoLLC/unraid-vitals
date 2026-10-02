/**
 * Providers — the ONLY layer that touches the host OS.
 *
 * Each provider wraps one external tool or file source and returns plain
 * data. They know about `/usr/bin/docker` and `/var/log/syslog`; nothing
 * above this layer does. Swapping the host tool (podman for docker, journald
 * for syslog) means editing one file here, not every agent.
 */
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { TransientError, UnsupportedError, SourceError } from '../core/ports.mjs';

/** Run a binary with a timeout; null on any failure. Never throws. */
export function run(bin, args, { timeout = 8000, encoding = 'utf8' } = {}) {
  try {
    return execFileSync(bin, args, { encoding, timeout });
  } catch {
    return null;
  }
}

/** Is this binary present and runnable? Probes once, then caches. */
const binCache = new Map();
export function hasBin(bin) {
  if (binCache.has(bin)) return binCache.get(bin);
  const ok = !!run('/usr/bin/which', [bin], { timeout: 3000 });
  binCache.set(bin, ok);
  return ok;
}

/** Read + parse JSON from a path; fallback on any problem. */
export function readJson(path, fallback = null) {
  try {
    return JSON.parse(readFileSync(path, 'utf8'));
  } catch {
    return fallback;
  }
}

/** Last N lines of a file. Empty string when absent or unreadable. */
export function tailFile(path, lines = 200) {
  const out = run('/usr/bin/tail', ['-n', String(lines), path], { timeout: 5000 });
  return out ?? '';
}

/* ------------------------------------------------------------------ *
 * Docker — any OCI container runtime that answers to `docker`.
 * ------------------------------------------------------------------ */

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

/**
 * Container details from `docker inspect`. Returns raw JSON per container;
 * the source layer maps it to the core shape.
 */
export function dockerInspect(name) {
  const out = run('/usr/bin/docker', ['inspect', name], { timeout: 5000 });
  if (!out) return null;
  try {
    return JSON.parse(out)[0] || null;
  } catch {
    return null;
  }
}

/** Last N lines of a container's logs, for crash triage. */
export function dockerLogs(container, minutes = 30, maxLines = 150) {
  const out = run('/usr/bin/docker', ['logs', '--since', `${minutes}m`, '--tail', String(maxLines), container], { timeout: 8000 });
  return (out ?? '').slice(0, 20000);
}

/**
 * The repo:tag each running container uses, plus the local image digest.
 *
 * `docker inspect` rather than `docker images --digests`: a locally built or
 * retagged image may carry NO RepoDigests key at all — not even an empty array
 * — and a Go --format template on it errors with "map has no entry for key",
 * writing to stderr and polluting the agent log on every run. Parsing the
 * JSON ourselves sidesteps Go's strict key lookup entirely.
 */
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

/**
 * The registry's current digest for an image reference. Null on any failure —
 * no network, private registry needing auth, rate limit, deleted upstream —
 * all of which must skip that one container, not fail the whole scan.
 */
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

/* ------------------------------------------------------------------ *
 * Virtualisation — libvirt (virsh) or Hyper-V, picked by what exists.
 * ------------------------------------------------------------------ */

/** Detect the available hypervisor once. */
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

/* ------------------------------------------------------------------ *
 * Logs — syslog/journald, whichever this host keeps.
 * ------------------------------------------------------------------ */

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

/* ------------------------------------------------------------------ *
 * Host facts — the things a source may or may not be able to supply.
 * ------------------------------------------------------------------ */

export function readStateFile(stateDir, name, fallback = null) {
  return readJson(`${stateDir}/${name}`, fallback);
}
