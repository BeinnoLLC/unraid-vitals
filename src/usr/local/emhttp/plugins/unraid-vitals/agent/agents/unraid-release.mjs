/**
 * Unraid OS release watcher.
 *
 * Polls Limetech's release feed (the same endpoint the stock "Check for
 * Updates" button hits) and compares against /etc/unraid-version. When a
 * newer stable release is out it:
 *   1. files a finding ("Unraid 7.3.2 available, you run 7.2.1"), and
 *   2. fetches the official release notes markdown and stores them in the
 *      KB as a 'release' document so they are searchable and so the
 *      auto-research trigger can ground its "why should I upgrade?"
 *      report in the actual changelog rather than model memory.
 *
 * No LLM call here — version comparison is deterministic. The reasoning
 * ("is this upgrade worth it for THIS box?") is the auto-triggered
 * research job's work (see ../lib/auto-research.mjs).
 *
 * Degrades cleanly: no network → one 'info' finding saying the check
 * could not run, never a false "up to date".
 */
import { readFileSync } from 'node:fs';
import { insertKbDocument, getDb } from '../lib/db.mjs';

export const AGENT_ID = 'unraid-release';
export const KB_REPORT = 'Unraid OS release check';

const FEED = process.env.VITALS_UNRAID_RELEASE_FEED || 'https://releases.unraid.net/os?branch=stable';
const FETCH_TIMEOUT_MS = 15000;

export function installedVersion() {
  try {
    const m = readFileSync('/etc/unraid-version', 'utf8').match(/version="?([\d.]+[\w.-]*)"?/);
    return m ? m[1] : null;
  } catch { return null; }
}

/** Semver-ish compare tolerant of "7.3.2-rc1": returns >0 if a > b. */
export function compareVersions(a, b) {
  const pa = String(a).split(/[.-]/), pb = String(b).split(/[.-]/);
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const x = pa[i] ?? '', y = pb[i] ?? '';
    const nx = Number(x), ny = Number(y);
    if (!Number.isNaN(nx) && !Number.isNaN(ny)) { if (nx !== ny) return nx - ny; continue; }
    if (x === y) continue;
    if (x === '') return 1;   // "7.3.2" > "7.3.2-rc1"
    if (y === '') return -1;
    return x < y ? -1 : 1;
  }
  return 0;
}

async function fetchText(url) {
  const ctl = new AbortController();
  const t = setTimeout(() => ctl.abort(), FETCH_TIMEOUT_MS);
  try {
    const r = await fetch(url, { signal: ctl.signal, headers: { 'user-agent': 'unraid-vitals' } });
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    return await r.text();
  } finally { clearTimeout(t); }
}

/** Latest stable release per Limetech, or null when unreachable. */
export async function latestRelease() {
  const j = JSON.parse(await fetchText(FEED));
  if (!j || !j.version) return null;
  return { version: j.version, name: j.name || `Unraid ${j.version}`, date: j.date || null,
           changelog: j.changelog || null, changelogPretty: j.changelogPretty || null };
}

function kbHasRelease(version) {
  return !!getDb().prepare(`SELECT 1 FROM kb_documents WHERE source = 'unraid-release' AND source_ref = ? LIMIT 1`).get(version);
}

export async function run() {
  const installed = installedVersion();
  let latest;
  try { latest = await latestRelease(); }
  catch (e) {
    return [{ severity: 'info', title: 'Unraid release check could not reach releases.unraid.net',
              detail: `Error: ${e?.message || e}. Installed: ${installed ?? 'unknown'}. This is not an "up to date" result.`,
              recommendation: 'Check outbound network access from the server; the check retries every agent run.', subject: null }];
  }
  if (!latest) {
    return [{ severity: 'info', title: 'Unraid release feed returned no version', detail: `Feed: ${FEED}`, recommendation: null, subject: null }];
  }

  // Always cache the notes of the latest release once — even when already
  // on it, "what changed in the version I run" is a fair KB question.
  if (latest.changelog && !kbHasRelease(latest.version)) {
    try {
      const md = await fetchText(latest.changelog);
      insertKbDocument({
        source: 'unraid-release', sourceRef: latest.version, topic: 'unraid-os', kind: 'report',
        title: `Unraid OS ${latest.version} release notes`,
        summary: `${latest.name}${latest.date ? ` (${latest.date})` : ''} — official release notes`,
        content: md.slice(0, 60000) + (latest.changelogPretty ? `\n\n[Formatted release notes](${latest.changelogPretty})` : ''),
        severity: installed && compareVersions(latest.version, installed) > 0 ? 'high' : 'low',
        tags: ['updates', 'release-notes'],
      });
    } catch (e) { console.warn(`[${AGENT_ID}] release notes fetch failed: ${e?.message || e}`); }
  }

  if (!installed) {
    return [{ severity: 'info', title: `Latest Unraid is ${latest.version}; installed version unknown`,
              detail: '/etc/unraid-version could not be read.', recommendation: null, subject: null }];
  }
  if (compareVersions(latest.version, installed) <= 0) {
    return [{ severity: 'ok', title: `Unraid OS ${installed} is current (latest stable ${latest.version})`,
              detail: `Release feed checked at ${new Date().toISOString()}.`, recommendation: null, subject: null }];
  }
  return [{
    severity: 'warning',
    title: `Unraid OS ${latest.version} available — running ${installed}`,
    detail: `${latest.name} was published ${latest.date || 'recently'}. Release notes are stored in the knowledge base (topic "unraid-os") and an upgrade-advisory research job is filed automatically.`,
    recommendation: latest.changelogPretty ? `Read ${latest.changelogPretty}, then Tools → Update OS.` : 'Tools → Update OS.',
    subject: `unraid-${latest.version}`,
    meta: { installed, latest: latest.version },
  }];
}
