
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { diskFloorFindings } from '../agents/disks.mjs';
import { thermalFloorFindings } from '../agents/thermal.mjs';
import { groundFindings, sameSubject, applyFloorFindings } from '../agents/contract.mjs';

test('disk floor fires on a >90% full pool regardless of the prompt', () => {
  const disks = [{ name: 'cache', type: 'cache', usedPct: 97.8 }];
  const out = diskFloorFindings(disks, {});
  assert.equal(out.length, 1);
  assert.equal(out[0].subject, 'cache');
  assert.equal(out[0].severity, 'warning');
  assert.match(out[0].title, /97\.8% full/);
});

test('floor rules take no prompt argument — trimming must not gate them', () => {
  
  
  
  
  
  
  const src = (p) => readFileSync(new URL(p, import.meta.url), 'utf8');
  for (const [label, file, fn] of [
    ['diskFloorFindings', '../agents/disks.mjs', 'diskFloorFindings'],
    ['thermalFloorFindings', '../agents/thermal.mjs', 'thermalFloorFindings']
  ]) {
    const text = src(file);
    const start = text.indexOf(`export function ${fn}(`);
    assert.ok(start > -1, `${fn} must be exported`);
    const body = text.slice(start, text.indexOf('\n}', start));
    assert.ok(!/promptText|prompt\b/.test(body),
      `${label} must not read the prompt — a trimmed row is exactly when the floor is the only reporter`);
  }
});

test('disk floor still fires when the prompt could not contain the row', () => {
  
  
  const disks = [{ name: 'cache', type: 'cache', usedPct: 97.8 }];
  const trimmedPrompt = 'array: STARTED\n- disk1 (data): temp=32C used=42.0%';
  assert.ok(!trimmedPrompt.includes('cache'));
  const out = diskFloorFindings(disks, {});
  assert.equal(out.length, 1);
  assert.equal(out[0].subject, 'cache');
});

test('disk floor reports SMART FAILED, reallocated sectors and device errors', () => {
  const disks = [{ name: 'sda', type: 'data', usedPct: 10, numErrors: 3 }];
  const smart = { sdb: { name: 'sdb', health: 'FAILED' }, sdc: { name: 'sdc', reallocated: 4 } };
  const titles = diskFloorFindings(disks, smart).map(f => f.title);
  assert.ok(titles.some(t => /sda: 3 device errors/.test(t)));
  assert.ok(titles.some(t => /sdb: SMART reports FAILED/.test(t)));
  assert.ok(titles.some(t => /sdc: 4 reallocated sectors/.test(t)));
});

test('disk floor is silent on a healthy box', () => {
  const disks = [{ name: 'sda', type: 'data', usedPct: 42, numErrors: 0 }];
  const smart = { sda: { name: 'sda', health: 'PASSED', reallocated: 0 } };
  assert.deepEqual(diskFloorFindings(disks, smart), []);
});

test('thermal floor reports hot disks and NVMe media errors', () => {
  const snap = {
    disks: [{ name: 'sdq', temp: 61 }, { name: 'sdr', temp: 56 }, { name: 'sds', temp: 30 }],
    smart: { nvme0: { name: 'nvme0', nvme_media_errors: 2 } }
  };
  const out = thermalFloorFindings(snap);
  const bySubject = Object.fromEntries(out.map(f => [f.subject, f]));
  assert.equal(out.length, 3);
  assert.equal(bySubject.sdq.severity, 'error');   
  assert.equal(bySubject.sdr.severity, 'warning'); 
  assert.equal(bySubject.nvme0.severity, 'error');
  assert.ok(!bySubject.sds);
});

test('grounding accepts a qualified subject that starts with a known one', () => {
  
  
  const f = [{ title: 'Cache SSD usage over 90%', detail: 'cache is 97.8% full', subject: 'cache (ssd)' }];
  const { kept, dropped } = groundFindings(f, ['cache', 'disk1'], 'cache used=97.8%');
  assert.equal(dropped.length, 0);
  assert.equal(kept.length, 1);
});

test('grounding normalises punctuation and dashes in subjects', () => {
  const f = [{ title: 'hot', detail: 'sdq is 61C', subject: 'sdq — SSD' }];
  const { kept } = groundFindings(f, ['sdq'], 'sdq=61C');
  assert.equal(kept.length, 1);
});

test('grounding still rejects a fabricated subject', () => {
  
  
  const f = [{ title: 'invented', detail: 'disk9 failed', subject: 'disk9' }];
  const { kept, dropped } = groundFindings(f, ['disk1'], 'disk1 ok');
  assert.equal(kept.length, 0);
  assert.equal(dropped.length, 1);
});

test('grounding and the dedupe agree on subject identity', () => {
  
  
  
  
  
  assert.ok(sameSubject('cache (ssd)', 'cache'), 'model-qualified vs floor-bare');
  assert.ok(sameSubject('cache', 'cache (ssd)'), 'floor-bare vs model-qualified');
  assert.ok(sameSubject('sdq — SSD', 'sdq'));
  assert.ok(sameSubject('  Cache ', 'cache'));
  assert.ok(!sameSubject('disk9', 'disk1'), 'a fabricated subject must never collapse onto a real one');
  assert.ok(!sameSubject('sda', 'sdb'));
});

test('applyFloorFindings appends the floor finding the model missed', () => {
  const kept = [];
  applyFloorFindings(kept, () => diskFloorFindings([{ name: 'cache', usedPct: 97.8 }], {}), 'Vitals-Disks');
  assert.equal(kept.length, 1);
  assert.match(kept[0].title, /97\.8% full/);
});

test('applyFloorFindings does not double-report one device', () => {
  
  
  
  const kept = [{ severity: 'warning', title: 'Cache SSD nearly full', detail: 'cache (ssd) at 97.8%', subject: 'cache (ssd)' }];
  applyFloorFindings(kept, () => diskFloorFindings([{ name: 'cache', usedPct: 97.8 }], {}), 'Vitals-Disks');
  assert.equal(kept.length, 1, 'one device, one finding');
});

test('applyFloorFindings raises a severity the model under-called', () => {
  const kept = [{ severity: 'info', title: 'cache note', detail: 'cache is a bit full', subject: 'cache' }];
  applyFloorFindings(kept, () => diskFloorFindings([{ name: 'cache', usedPct: 97.8 }], {}), 'Vitals-Disks');
  assert.equal(kept.length, 1);
  assert.equal(kept[0].severity, 'warning'); 
});

test('applyFloorFindings never downgrades a model finding', () => {
  
  
  const kept = [{ severity: 'critical', title: 'cache failing', detail: 'cache 97.8% full and erroring', subject: 'cache' }];
  applyFloorFindings(kept, () => diskFloorFindings([{ name: 'cache', usedPct: 97.8 }], {}), 'Vitals-Disks');
  assert.equal(kept.length, 1);
  assert.equal(kept[0].severity, 'critical');
});

test('applyFloorFindings tolerates a missing hook and a null finding', () => {
  assert.deepEqual(applyFloorFindings([], null), []);
  assert.deepEqual(applyFloorFindings([], undefined), []);
  assert.deepEqual(applyFloorFindings([], () => [null, undefined, false]), []);
});

test('grounding still drops numbers not present in the input', () => {
  const f = [{ title: 'fabricated', detail: 'usage is 12.3%', subject: 'disk1' }];
  const { kept, dropped } = groundFindings(f, ['disk1'], 'disk1 used=42%');
  assert.equal(kept.length, 0);
  assert.equal(dropped.length, 1);
});
