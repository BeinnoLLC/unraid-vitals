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
