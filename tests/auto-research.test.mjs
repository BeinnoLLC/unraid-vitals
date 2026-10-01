import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

process.env.VITALS_STATE_DIR = mkdtempSync(join(tmpdir(), 'vitals-auto-'));
process.env.VITALS_DB_PATH = join(process.env.VITALS_STATE_DIR, 'vitals.db');

const { ruleFor, fireAutoResearch } = await import('../src/usr/local/emhttp/plugins/unraid-vitals/agent/lib/auto-research.mjs');
const { compareVersions } = await import('../src/usr/local/emhttp/plugins/unraid-vitals/agent/agents/unraid-release.mjs');
const { getDb, getResearchJob, reapStaleRuns } = await import('../src/usr/local/emhttp/plugins/unraid-vitals/agent/lib/db.mjs');

test('compareVersions orders stable above rc and by numeric parts', () => {
  assert.ok(compareVersions('7.3.2', '7.2.1') > 0);
  assert.ok(compareVersions('7.10.0', '7.9.9') > 0);
  assert.ok(compareVersions('7.3.2', '7.3.2-rc1') > 0);
  assert.equal(compareVersions('7.3.2', '7.3.2'), 0);
  assert.ok(compareVersions('6.12.14', '7.0.0') < 0);
});

test('disk error findings become a study scoped to that disk', () => {
  const r = ruleFor('disks', { severity: 'error', title: 'disk3 reports 12 pending sectors', detail: '', subject: 'disk3' });
  assert.equal(r.mode, 'study');
  assert.equal(r.key, 'disk:disk3');
  assert.match(r.prompt, /Study disk3 specifically/);
});

test('disk warnings and non-disk info do not trigger', () => {
  assert.equal(ruleFor('disks', { severity: 'warning', title: 'disk3 warm', subject: 'disk3' }), null);
  assert.equal(ruleFor('network', { severity: 'info', title: 'eth0 renegotiated' }), null);
});

test('new Unraid release becomes an upgrade advisory keyed by version', () => {
  const r = ruleFor('unraid-release', { severity: 'warning', title: 'x', meta: { installed: '7.2.1', latest: '7.3.2' } });
  assert.equal(r.mode, 'once');
  assert.equal(r.key, 'unraid-release:7.3.2');
  assert.match(r.prompt, /upgrade to 7\.3\.2/);
});

test('abandoned runs left in running are reaped, live ones are not', () => {
  const db = getDb();
  const nowS = Math.floor(Date.now() / 1000);
  db.prepare("INSERT INTO runs (run_id, agent, started_at, status) VALUES ('old-run','disks',?,'running')").run(nowS - 3 * 3600);
  db.prepare("INSERT INTO runs (run_id, agent, started_at, status) VALUES ('live-run','disks',?,'running')").run(nowS - 60);
  assert.equal(reapStaleRuns(90), 1);
  assert.equal(db.prepare("SELECT status FROM runs WHERE run_id='old-run'").get().status, 'error');
  assert.equal(db.prepare("SELECT status FROM runs WHERE run_id='live-run'").get().status, 'running');
});

test('error findings from any agent trigger a 12h-cooldown investigation', () => {
  const r = ruleFor('general', { severity: 'error', title: 'Unusually High System Load', subject: null, detail: 'load 24.7' });
  assert.equal(r.mode, 'once');
  assert.equal(r.key, 'err:general:unusually-high-system-load');
  assert.match(r.prompt, /root cause/);
  assert.ok(r.cooldown === 12 * 3600);
});

test('any critical finding becomes a one-shot root-cause research', () => {
  const r = ruleFor('thermal', { severity: 'critical', title: 'CPU at 98°C', subject: 'cpu' });
  assert.equal(r.mode, 'once');
  assert.equal(r.key, 'thermal:cpu');
});

test('fireAutoResearch files jobs with origin/context and is idempotent within cooldown', () => {
  const findings = [
    { severity: 'error', title: 'disk3 reports pending sectors', subject: 'disk3', detail: 'pending=12' },
    { severity: 'critical', title: 'Pool cache degraded', subject: 'cache' },
    { severity: 'ok', title: 'fine' },
  ];
  const first = fireAutoResearch('disks', findings, { launch: false });
  assert.equal(first.length, 2);
  const study = getResearchJob(first[0].jobId);
  assert.equal(study.mode, 'study');
  assert.equal(study.origin, 'auto:disk:disk3');
  assert.match(study.context, /pending=12/);
  const again = fireAutoResearch('disks', findings, { launch: false });
  assert.equal(again.length, 0, 'same trigger must not re-file inside cooldown');
  const rows = getDb().prepare(`SELECT COUNT(*) AS n FROM auto_triggers`).get();
  assert.equal(rows.n, 2);
});
