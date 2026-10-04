/**
 * unraid-vitals — lib/research-tools.mjs (P20-08 / #113)
 *
 * The model can ASK for data (read-only), instead of everything being
 * pre-stuffed. Six tools, every one a pure reader:
 *   metric_history(entity, hours)  — ring/rollup samples for a metric or disk
 *   log_search(query, minutes)     — syslog/system log grep
 *   container_log(container, tail) — tail of one container's docker log
 *   smart_detail(disk)             — SMART rows for one disk
 *   kb_search(query)               — the knowledge base retrieval
 *   check_results(subject?)         — the diagnosis engine's stored findings
 *
 * Control actions (start/stop/restart containers, mover, apply, cleanup) are
 * NOT tools — the model never gets a write path; the step cap keeps a run
 * from wandering (default 3 tool rounds).
 */

import { execFileSync } from 'node:child_process';
import { activeSource } from './sources.mjs';
import { searchKb, getDb } from './db.mjs';

const SAFE_DISK = /^(sd[a-z]+|nvme\d+n\d+|disk\d+|parity\d*|cache\d*)$/i;
const SAFE_CONTAINER = /^[A-Za-z0-9][A-Za-z0-9_.-]{0,60}$/;

export const RESEARCH_TOOLS = {
  metric_history: { desc: 'metric values over the last N hours: cpu, mem, load, temp, net_rx, net_tx, or a disk/container name', args: ['name', 'hours'] },
  log_search:     { desc: 'grep the system syslog for a phrase (last N minutes)', args: ['query', 'minutes'] },
  container_log:  { desc: 'tail one docker container\'s log (name, last N lines)', args: ['container', 'lines'] },
  smart_detail:   { desc: 'SMART attribute rows for one disk (e.g. sdX)', args: ['disk'] },
  kb_search:      { desc: 'search the knowledge base for stored Unraid knowledge', args: ['query'] },
  check_results:  { desc: 'the diagnosis engine\'s findings, optionally filtered by subject', args: ['subject'] },
};

const TOOLS_PROMPT = `
TOOLS (read-only, ask by emitting exactly one JSON line {"tool":"<name>","args":{…}} as the WHOLE response):
${Object.entries(RESEARCH_TOOLS).map(([id, t]) => `- ${id}(${t.args.join(', ')}): ${t.desc}`).join('\n')}
Rules: at most 3 tool calls per answer; tools never change anything; every other response is the final JSON answer per the findings/report contract.`;

/** Run one tool call. Returns a compact JSON-able result or {error}. */
export function runResearchTool(name, args = {}) {
  const source = activeSource();
  switch (name) {
    case 'metric_history': {
      const hours = Math.min(72, Math.max(1, Number(args.hours) || 6));
      const hist = source.historyWindow ? source.historyWindow(hours) : source.history();
      const who = String(args.name || '').toLowerCase();
      const series = hist.map(p => ({ t: p.t, cpu: p.cpu, mem: p.mem, load: p.load, temp: p.tempMax ?? p.temp_max, rx: p.net_rx, tx: p.net_tx,
        disk: who && p.disk ? p.disk[who] : undefined, ctr: who && p.ctr ? p.ctr[who] : undefined }));
      return { hours, samples: series.length, series: series.slice(-120) };
    }
    case 'log_search': {
      const minutes = Math.min(10080, Math.max(5, Number(args.minutes) || 180));
      const q = String(args.query || '').slice(0, 120);
      if (!/^[\w .:\/"@#%\[\]()-]+$/.test(q)) return { error: 'bad query charset' };
      const raw = execFileSync('grep', ['-i', '-h', '-m', '40', q, '/var/log/syslog'], { timeout: 15000, encoding: 'utf8' })
        .split('\n').filter(Boolean).slice(-40);
      return { query: q, window_minutes: minutes, hits: raw };
    }
    case 'container_log': {
      const ctr = String(args.container || '');
      if (!SAFE_CONTAINER.test(ctr)) return { error: 'bad container name' };
      const lines = Math.min(100, Math.max(5, Number(args.lines) || 30));
      const id = String(execFileSync('docker', ['inspect', '--format', '{{.Id}}', ctr], { timeout: 15000, encoding: 'utf8' }).trim());
      if (!/^[a-f0-9]{64}$/.test(id)) return { error: 'no such container' };
      const out = String(execFileSync('docker', ['logs', '--tail', String(lines), ctr], { timeout: 15000, encoding: 'utf8' }).slice(0, 4000));
      return { container: ctr, tail: out };
    }
    case 'smart_detail': {
      const disk = String(args.disk || '');
      if (!SAFE_DISK.test(disk)) return { error: 'bad disk name' };
      const dev = `/dev/${disk.replace(/^disk\d+$|^parity\d*$|^cache\d*$/, m => m)}`;
      // disks.ini device mapping: diskN -> sdX
      const map = diskDeviceMap();
      const realDev = map[disk] ?? (/^(sd|nvme)/.test(disk) ? `/dev/${disk}` : null);
      if (!realDev) return { error: 'disk not found in disks.ini' };
      const raw = String(execFileSync('smartctl', ['-a', realDev], { timeout: 20000, encoding: 'utf8' }).slice(0, 5000));
      return { disk, device: realDev, smart: raw };
    }
    case 'kb_search': {
      const q = String(args.query || '').slice(0, 160);
      const hits = searchKb(q, 8);
      return { query: q, hits };
    }
    case 'check_results': {
      const subject = String(args.subject || '').slice(0, 60);
      const db = getDb();
      const rows = subject
        ? db.prepare(`SELECT agent, severity, title, detail, subject, created_at FROM findings WHERE subject LIKE ? ORDER BY created_at DESC LIMIT 20`).all(`%${subject}%`)
        : db.prepare(`SELECT agent, severity, title, subject, created_at FROM findings ORDER BY created_at DESC LIMIT 20`).all();
      return { subject: subject || '(all)', findings: rows };
    }
    default:
      return { error: `unknown tool ${name}` };
  }
}

function diskDeviceMap() {
  const out = {};
  try {
    const ini = execFileSync('php', ['-r', 'echo file_get_contents("/var/local/emhttp/disks.ini");'], { timeout: 10000, encoding: 'utf8' });
    let cur = null;
    for (const line of ini.split('\n')) {
      const sec = /^\[(.+)\]$/.exec(line.trim());
      if (sec) { cur = sec[1]; continue; }
      const kv = /^(device)="([^"]*)"/.exec(line);
      if (kv && cur) out[cur] = '/dev/' + kv[2];
    }
  } catch { /* no php or no ini */ }
  return out;
}

/** Parse a {"tool":…} reply out of the model response; null when final answer. */
export function parseToolCall(text) {
  const t = String(text ?? '').trim();
  if (!t.startsWith('{')) return null;
  try {
    const j = JSON.parse(t);
    if (j && typeof j.tool === 'string' && RESEARCH_TOOLS[j.tool]) return { name: j.tool, args: j.args ?? {} };
  } catch { /* not a tool call */ }
  return null;
}

export { TOOLS_PROMPT };