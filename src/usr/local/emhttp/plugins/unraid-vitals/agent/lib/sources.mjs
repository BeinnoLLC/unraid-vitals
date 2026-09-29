/**
 * unraid-vitals agent — shared data-gathering helpers.
 *
 * Every specialist agent reads the same collector state the PHP UI reads
 * (no duplicate collection logic, no drift between what the UI shows and
 * what the agent reasons about).
 */
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const STATE_DIR = process.env.VITALS_STATE_DIR || '/var/tmp/unraid-vitals';

export function readJson(path, fallback = null) {
  try {
    return JSON.parse(readFileSync(path, 'utf8'));
  } catch {
    return fallback;
  }
}

export function latestSnapshot() { return readJson(`${STATE_DIR}/latest.json`, {}); }
export function history() { return readJson(`${STATE_DIR}/history.json`, []); }
export function alerts() { return readJson(`${STATE_DIR}/alerts.json`, []); }

/** history() filtered to the last N hours — the shared "look back" window
 *  every research/study/diagnostics agent uses so "what happened in the
 *  last 6 hours" means the same thing everywhere. history.json is a ring
 *  buffer (fixed sample count, not fixed time span), so on a freshly
 *  started collector or after a big gap the window may return fewer
 *  samples than the interval implies — callers must treat a short window
 *  as "limited history", not an error. */
export function historyWindow(hours = 6) {
  const cutoff = Math.floor(Date.now() / 1000) - hours * 3600;
  return history().filter(p => (p.time ?? 0) >= cutoff);
}

/** Tail N lines of a file, safely (missing file -> empty string). */
export function tail(path, lines = 200) {
  try {
    return execFileSync('/usr/bin/tail', ['-n', String(lines), path], { encoding: 'utf8', timeout: 5000 });
  } catch {
    return '';
  }
}

/** journalctl/syslog tail restricted to the last N minutes, priority>=warning. */
export function recentSyslogWarnings(minutes = 60, maxLines = 400) {
  try {
    const out = execFileSync('/bin/sh', ['-c',
      `awk -v cutoff="$(date -d '-${minutes} minutes' '+%b %e %H:%M:%S' 2>/dev/null)" '1' /var/log/syslog 2>/dev/null | tail -n ${maxLines}`
    ], { encoding: 'utf8', timeout: 8000 });
    return out;
  } catch {
    return '';
  }
}

export function dockerLogsTail(container, minutes = 30, maxLines = 150) {
  try {
    return execFileSync('/usr/bin/docker', ['logs', '--since', `${minutes}m`, '--tail', String(maxLines), container],
      { encoding: 'utf8', timeout: 8000 }).slice(0, 20000);
  } catch {
    return '';
  }
}

/** All libvirt VMs with basic state — Node-side mirror of include/collect.php's
 *  v_vms() so agent/*.mjs processes don't need to shell out to PHP. Used by
 *  vmwatch.mjs to detect state transitions and by research.mjs to scope a
 *  question to one VM. */
export function vmList() {
  try {
    const raw = execFileSync('/usr/bin/virsh', ['list', '--all', '--name'], { encoding: 'utf8', timeout: 8000 });
    const names = raw.split('\n').map(s => s.trim()).filter(Boolean);
    return names.map(name => {
      let state = 'unknown';
      try {
        const info = execFileSync('/usr/bin/virsh', ['dominfo', name], { encoding: 'utf8', timeout: 5000 });
        const m = info.match(/^State:\s*(.+)$/m);
        if (m) state = m[1].trim();
      } catch { /* domain vanished between list and dominfo — leave 'unknown' */ }
      return { name, state };
    });
  } catch {
    return [];
  }
}

/** Every currently-running container's repo:tag and local image digest —
 *  the baseline updates.mjs diffs against the registry's current digest.
 *  `docker inspect` (not `images --digests`) because a locally-built or
 *  registry-pulled-then-retagged image may not carry a RepoDigest, and we
 *  need the exact reference the container actually runs, not just what's
 *  cached under that tag. */
export function dockerContainerImages() {
  try {
    const names = execFileSync('/usr/bin/docker', ['ps', '--format', '{{.Names}}'],
      { encoding: 'utf8', timeout: 8000 }).split('\n').map(s => s.trim()).filter(Boolean);
    return names.map(name => {
      try {
        const image = execFileSync('/usr/bin/docker', ['inspect', '--format', '{{.Config.Image}}', name],
          { encoding: 'utf8', timeout: 5000 }).trim();
        const digestOut = execFileSync('/usr/bin/docker', ['inspect', '--format', '{{index .RepoDigests 0}}', name],
          { encoding: 'utf8', timeout: 5000 }).trim();
        const localDigest = digestOut.includes('@sha256:') ? digestOut.split('@')[1] : null;
        return { name, image, localDigest };
      } catch {
        return { name, image: null, localDigest: null };
      }
    });
  } catch {
    return [];
  }
}

/** Registry's current digest for an image reference, via `docker manifest
 *  inspect` (no extra CLI needed beyond a modern docker install, no auth
 *  for public images). Returns null on any failure — no network, private
 *  registry needing auth, rate-limited, image deleted upstream — all of
 *  which must skip that one container, not fail the whole scan. */
export function registryDigest(imageRef) {
  try {
    const out = execFileSync('/usr/bin/docker',
      ['manifest', 'inspect', '--verbose', imageRef],
      { encoding: 'utf8', timeout: 15000 });
    const parsed = JSON.parse(out);
    const d = Array.isArray(parsed) ? parsed[0]?.Descriptor?.digest : parsed?.Descriptor?.digest;
    return d || null;
  } catch {
    return null;
  }
}
