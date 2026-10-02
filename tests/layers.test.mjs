// Proves the layering is real, not decorative:
//   core/ has no I/O and no vendor, providers/ owns every shell-out,
//   sources/ is the ONLY place that may mention Unraid, and a second,
//   completely non-Unraid source can drive a real viewmodel with no code
//   change anywhere above it.
//
// Runs offline in milliseconds — no NAS, no LLM studio, no network.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const AGENT = join(dirname(fileURLToPath(import.meta.url)),
  '../src/usr/local/emhttp/plugins/unraid-vitals/agent');

const { createFixtureSource } = await import(`${AGENT}/sources/fixture.mjs`);
const { registerSource, getSource, getActiveSource, listSources, fuseSources } =
  await import(`${AGENT}/sources/registry.mjs`);
const { assertSource, SourceError, UnsupportedError } = await import(`${AGENT}/core/ports.mjs`);
const facade = await import(`${AGENT}/lib/sources.mjs`);

/* ---------------------------------------------------------------- *
 * Layer discipline — the rules the architecture promises
 * ---------------------------------------------------------------- */

function walk(dir) {
  const out = [];
  for (const e of readdirSync(dir, { withFileTypes: true })) {
    const p = join(dir, e.name);
    if (e.isDirectory()) { if (e.name !== 'node_modules') out.push(...walk(p)); }
    else if (e.name.endsWith('.mjs')) out.push(p);
  }
  return out;
}
const all = [...walk(AGENT), ...walk(join(AGENT, 'core')), ...walk(join(AGENT, 'providers')),
  ...walk(join(AGENT, 'sources'))];

test('core/ is pure: no child_process, no fs, no network, no env', () => {
  for (const f of walk(join(AGENT, 'core'))) {
    const src = readFileSync(f, 'utf8');
    for (const banned of ['node:child_process', 'node:fs', 'node:http', 'fetch(', 'process.env']) {
      assert.ok(!src.includes(banned), `${relative(AGENT, f)} must not reference ${banned}`);
    }
  }
});

test('only providers/ shells out to host tools', () => {
  const shellouts = all.filter((f) => readFileSync(f, 'utf8').includes('execFileSync'));
  assert.ok(shellouts.length > 0, 'expected at least one provider to shell out');
  for (const f of shellouts) {
    const rel = relative(AGENT, f);
    assert.ok(rel.startsWith('providers') || rel.startsWith('lib/sources.mjs') === false,
      `${rel} shells out but is not a provider — move it to providers/`);
  }
});

test('Unraid subsystems appear in exactly one layer', () => {
  // Strip comments before checking: documenting "parity" in a comment is
  // fine, DEPENDING on it in code is the leak we care about.
  const code = walk(join(AGENT, 'core'))
    .map((f) => readFileSync(f, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, ''))
    .join('\n');
  for (const banned of ['virsh', '/var/log/syslog', 'parity', 'unraid']) {
    assert.ok(!new RegExp(banned, 'i').test(code), `core/ code must not reference ${banned}`);
  }
});

test('the source port rejects a half-implemented source at load', () => {
  assert.throws(() => assertSource({ id: 'broken', snapshot() {} }), /missing port/);
  assert.throws(() => assertSource({ snapshot() {} }), /must expose a string id/);
  const good = createFixtureSource();
  assert.equal(assertSource(good), good);
});

/* ---------------------------------------------------------------- *
 * A second source — the actual "integrate any service" proof
 * ---------------------------------------------------------------- */

function fixtures() {
  return {
    latest: {
      time: 1700000000,
      system: { name: 'fixturebox', version: 'test', uptime: 3600 },
      cpu: { total: 42, cores: 8 }, mem: { pct: 61, swapPct: 0, swapUsed: 0 },
      load: { l1: 1.4, l5: 1.1, l15: 0.9, cores: 8 },
      temp_max: 38, docker: { running: 2, count: 2 },
      docker_containers: [],
      array: {
        data: [{ name: 'disk1', temp: 34, reallocated: 0, pending: 0, crc: 0, role: 'data' }],
        parity: [{ name: 'disk2', temp: 33, reallocated: 0, pending: 0, crc: 0 }]
      }
    },
    history: [{ t: 1700000000, cpu: 40, mem: 60, load: 1.2, temp_max: 37, fs_used: 1e12 }],
    alerts: [],
    containers: [{ name: 'alpha', state: 'running', image: 'alpha:1' }],
    vms: [{ name: 'vm-a', state: 'running' }],
    syslog: 'kernel: nothing alarming here'
  };
}

test('a non-Unraid source satisfies the same port', () => {
  const s = createFixtureSource({ fixtures: fixtures(), id: 'proxmox', label: 'Proxmox-like' });
  assert.doesNotThrow(() => assertSource(s));
  const snap = s.snapshot();
  assert.equal(snap.system.name, 'fixturebox');
  assert.equal(snap.disks.length, 2, 'data + parity both mapped through makeDisk');
  assert.equal(snap.disks.find((d) => d.name === 'disk1').role, 'data');
  assert.equal(snap.vms[0].name, 'vm-a');
});

test('registry switches source by name with no code change', () => {
  registerSource(createFixtureSource({ fixtures: fixtures(), id: 'proxmox', label: 'Proxmox-like' }));
  assert.equal(getSource('proxmox').id, 'proxmox');
  assert.ok(listSources().some((s) => s.id === 'proxmox'));

  const active = getActiveSource({ VITALS_SOURCE: 'proxmox' });
  assert.equal(active.id, 'proxmox');

  const fused = getActiveSource({ VITALS_SOURCE: 'unraid,proxmox' });
  assert.equal(fused.id, 'fused(unraid+proxmox)');
  assert.equal(fused.sources.length, 2);
});

test('fused sources merge containers and counts', () => {
  registerSource(createFixtureSource({ fixtures: fixtures(), id: 'proxmox', label: 'Proxmox-like' }));
  const fused = fuseSources([getSource('proxmox'), getSource('proxmox')]);
  assert.equal(fused.containers().length, 2, 'both sources contribute containers');
  assert.equal(fused.kind, 'fused');
});

test('an unsupported capability degrades instead of crashing', () => {
  const s = createFixtureSource({ fixtures: fixtures() });
  assert.throws(() => s.sensors(), (e) => e instanceof UnsupportedError);
  // The legacy facade must swallow that into an empty list, not rethrow.
  facade.__setSource(s);
  try {
    assert.deepEqual(facade.vmList().length, 1, 'fixture vms come through the facade');
    assert.equal(facade.latestSnapshot().mem.pct, 61);
    assert.equal(facade.activeSourceId(), 'fixture');
    assert.ok(facade.activeSourceCapabilities().containers);
  } finally {
    facade.__resetSource();
  }
});

test('unknown source id fails with a typed error', () => {
  assert.throws(() => getSource('nope'), (e) => e instanceof SourceError && e.code === 'unknown_source');
});
