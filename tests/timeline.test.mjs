// Offline test of timeline.mjs against synthetic history in the exact
// shapes store.php writes (ring 't' + rollup 'h'). No DB, no LLM.
import { mkdirSync, writeFileSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';

const dir = process.env.TMPDIR + '/tl-test';
mkdirSync(dir + '/flash', { recursive: true });
process.env.VITALS_STATE_DIR = dir;
process.env.VITALS_FLASH_HISTORY = dir + '/flash';
process.env.VITALS_DB_PATH = dir + '/vitals.db';

const now = Math.floor(Date.now() / 1000);
// ring: last 120 minutes; container "plex" appears at -40min; disk3 pending grows
const ring = [];
for (let i = 120; i >= 0; i--) {
  const t = now - i * 60;
  const p = { t, cpu: 20 + (i < 30 ? 50 : 0), mem: 40, load: 1.2, net_rx: 1e6, net_tx: 2e5, temp_max: 38 + (i < 30 ? 6 : 0), temp_avg: 35, gpu: null, fs_used: 15e12 + (120 - i) * 1e9,
    ctr: { sonarr: [3, 200000] }, smart: { disk3: [39, 0, i < 60 ? 2 : 0], disk1: [36, 0, 0] } };
  if (i <= 40) p.ctr.plex = [80, 900000];
  ring.push(p);
}
writeFileSync(dir + '/history.json', JSON.stringify(ring));
writeFileSync(dir + '/latest.json', JSON.stringify({ time: now, cpu: { total: 70 }, mem: { pct: 40 }, load: { l1: 1.2 }, temp_max: 44, docker: { running: 2, count: 3 } }));
// rollups: 48 hourly rows before the ring
const month = new Date().toISOString().slice(0, 7);
const lines = [];
for (let h = 50; h >= 3; h--) {
  const hour = Math.floor((now - h * 3600) / 3600);
  lines.push(JSON.stringify({ h: hour, cpu_avg: 15, cpu_max: 25, mem_avg: 38, mem_max: 41, load_avg: 0.8, load_max: 1.5, temp_avg: 34, temp_max: 37, net_rx: 3e9, net_tx: 5e8, fs_used: 14.9e12, smart: { disk3: { name: 'disk3', temp: 37, reallocated: 0, pending: 0 } } }));
}
writeFileSync(`${dir}/flash/${month}.jsonl`, lines.join('\n') + '\n');

// events
const { getDb, createOrTouchEvent, resolveEventsByKey } = await import('../src/usr/local/emhttp/plugins/unraid-vitals/agent/lib/db.mjs');
getDb();
for (let k = 0; k < 3; k++) { createOrTouchEvent({ kind: 'vm', entity: 'Win11', alertKey: 'vm-state:Win11', severity: 'warning', summary: `Win11: running → paused #${k}`, evidence: {}, source: 'vmwatch' }); resolveEventsByKey('vm-state:Win11'); }
createOrTouchEvent({ kind: 'alert', entity: 'disk3', alertKey: 'pending:disk3', severity: 'alert', summary: 'disk3 pending sectors 2', evidence: {}, source: 'alert-engine' });

const { window, describeWindow } = await import('../src/usr/local/emhttp/plugins/unraid-vitals/agent/lib/timeline.mjs');
const w6 = window(6), w48 = window(48), wVm = window(48, { entity: 'Win11' });
const assert = (c, m) => { if (!c) { console.error('FAIL:', m); process.exit(1); } console.log('ok  ', m); };
assert(w6.coverage.minute_samples === 121, 'ring samples counted (t field)');
assert(w6.coverage.hourly_rollups >= 3, 'rollups stitched for pre-ring hours');
assert(w6.scalars.cpu.max === 70, 'cpu max from ring');
assert(w6.containers.find(c => c.name === 'plex')?.appeared === true, 'plex flagged as appeared');
assert(w6.disks.find(d => d.name === 'disk3')?.pending_delta === 2, 'disk3 pending delta = 2');
assert(w6.flapping.some(f => f.key === 'vm-state:Win11' && f.count === 3), 'Win11 flapping detected (3 opens)');
assert(w48.coverage.hourly_rollups >= 40, '48h window pulls ~45 rollup rows');
assert(wVm.events.every(e => e.entity === 'Win11') && wVm.events.length === 3, 'entity scoping filters events');
assert(w6.fill && w6.fill.per_day_bytes > 0, 'fill growth computed');
const text = describeWindow(w6);
assert(text.includes('plex') && text.includes('[appeared]') && text.includes('flapped 3×'), 'describeWindow mentions appeared container + flapping');
console.log('\n--- describeWindow(6h) ---\n' + text.slice(0, 1800));
