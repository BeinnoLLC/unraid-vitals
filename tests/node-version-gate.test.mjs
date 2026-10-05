/* unraid-vitals — the install-time Node capability gate.
 *
 * The agent reads and writes its findings store through the `node:sqlite`
 * builtin (lib/db.mjs). That module arrived in 22.5.0 but stayed behind
 * --experimental-sqlite until 23.4.0 / 22.13.0, and nothing here passes that
 * flag — so a 22.5–22.12 box has the module and still cannot load it. Node 20
 * remains common on Unraid.
 *
 * Without the gate the plugin schedules agent crons that die on an unresolved
 * import every run, while the README promises the opposite: that agents
 * "disable themselves with a clear hint if Node isn't available". The gate is
 * shell inside a long installer, so nothing would catch it rotting.
 *
 * These tests run the marked block for real against a stub `node` on PATH,
 * rather than asserting on version arithmetic — the gate deliberately does not
 * do version arithmetic, because a table of ranges is wrong at the next
 * backport.
 *
 * Needs bash + a writable temp dir; skipped otherwise.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync, chmodSync, rmSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const INSTALL_SH = new URL(
  '../src/usr/local/emhttp/plugins/unraid-vitals/scripts/install.sh',
  import.meta.url,
);
const sh = readFileSync(INSTALL_SH, 'utf8');

const START = '# >>> v_node_gate';
const END = '# <<< v_node_gate';

function gate() {
  const start = sh.indexOf(START);
  const end = sh.indexOf(END);
  assert.ok(start !== -1 && end > start,
    'install.sh lost its v_node_gate markers — the Node floor is now untested');
  return sh.slice(start, end);
}

const haveBash = (() => {
  try { execFileSync('bash', ['-c', 'true'], { stdio: 'ignore' }); return true; }
  catch { return false; }
})();

/**
 * Run the gate with a stub `node` first on PATH.
 * sqliteExit: what the capability probe should report (0 = capable).
 * fakeVer: what `node -v` should print.
 * withNode: false puts no node on PATH at all.
 */
function runGate({ sqliteExit = 0, fakeVer = 'v22.5.1', withNode = true } = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'vitals-gate-'));
  const bin = join(dir, 'bin');
  mkdirSync(bin);
  if (withNode) {
    const p = join(bin, 'node');
    // -v answers the version; anything else is the probe.
    writeFileSync(p, `#!/bin/sh\nif [ "$1" = "-v" ]; then echo ${fakeVer}; exit 0; fi\nexit ${sqliteExit}\n`);
    chmodSync(p, 0o755);
  }
  try {
    // With no stub, drop the rest of PATH entirely rather than appending it —
    // otherwise the real node on this machine answers `command -v` and the
    // no-node case silently tests the opposite of what it claims. The gate uses
    // only shell builtins (`command -v`, `echo`), so a bare PATH is enough.
    const path = withNode ? `${JSON.stringify(bin)}:"$PATH"` : JSON.stringify(bin);
    const out = execFileSync('bash', ['-c', [
      `export PATH=${path}`,
      gate(),
      'echo "NODE_BIN=[$NODE_BIN]"',
    ].join('\n')], { encoding: 'utf8' });
    const m = out.match(/NODE_BIN=\[(.*)\]/);
    assert.ok(m, `gate produced no NODE_BIN line:\n${out}`);
    return { nodeBin: m[1], out };
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

test('a box whose node can load node:sqlite keeps the agent enabled', { skip: !haveBash }, () => {
  for (const fakeVer of ['v22.13.0', 'v23.4.0', 'v24.0.0', 'v26.7.0']) {
    const { nodeBin, out } = runGate({ sqliteExit: 0, fakeVer });
    assert.ok(nodeBin.endsWith('/node'), `node ${fakeVer} should be accepted, got "${nodeBin}"`);
    assert.ok(!/too old|can't load/.test(out), `node ${fakeVer} must not warn:\n${out}`);
  }
});

test('the gate asks the binary, so a version that only looks new enough is rejected', { skip: !haveBash }, () => {
  // The trap this guards: 22.5 introduced node:sqlite behind a flag, so a
  // version table would wave 22.5–22.12 through and the crons would still die.
  // 22.5.1 reports capable=false here precisely because the gate must trust the
  // probe, not the number.
  const { nodeBin } = runGate({ sqliteExit: 1, fakeVer: 'v22.5.1' });
  assert.equal(nodeBin, '', 'node 22.5.1 without the flag must be rejected');
  // And a capable binary is accepted regardless of an unexpected version.
  const ok = runGate({ sqliteExit: 0, fakeVer: 'v25.7.0' });
  assert.ok(ok.nodeBin.endsWith('/node'), 'a capable node must be accepted');
});

test('a rejected box gets a hint naming the detected version', { skip: !haveBash }, () => {
  // README: agents "disable themselves with a clear hint". A silent disable is
  // the failure this asserts against.
  const { out } = runGate({ sqliteExit: 1, fakeVer: 'v20.11.1' });
  assert.match(out, /v20\.11\.1/, `the hint must name the version found:\n${out}`);
  assert.match(out, /node:sqlite/, 'the hint must say what is missing, so it is actionable');
  assert.match(out, /dashboard works/i, 'the hint must say the dashboard is unaffected');
});

test('no node at all disables the agents quietly', { skip: !haveBash }, () => {
  // An absent node is not an error: the core dashboard works without it.
  const { nodeBin, out } = runGate({ withNode: false });
  assert.equal(nodeBin, '', 'no node on PATH must leave NODE_BIN empty');
  assert.doesNotMatch(out, /can't load/, 'a missing binary should not claim a load failure');
});

test('the gate clears NODE_BIN so the scheduler drops node-backed jobs', { skip: !haveBash }, () => {
  // Downstream contract, in schedule_registry.php: v_sched_apply treats an
  // empty NODE_BIN as "node missing" and unlinks the cron file of every job
  // flagged needs_node, so a dead job does not sit in /etc/cron.d failing every
  // minute. If the gate only warned and left NODE_BIN set, the agent crons would
  // still be written and the gate would accomplish nothing — so assert both
  // halves: the gate clears it, and the registry honours that.
  assert.match(gate(), /NODE_BIN=""/, 'the reject path must clear NODE_BIN');

  const registry = readFileSync(new URL(
    '../src/usr/local/emhttp/plugins/unraid-vitals/include/schedule_registry.php',
    import.meta.url,
  ), 'utf8');
  assert.match(registry, /\$nodeMissing\s*=\s*\$nodeBin === ''/,
    'the scheduler must derive "node missing" from an empty NODE_BIN');
  assert.match(registry, /needs_node.*\$nodeMissing|if \(\$job\['needs_node'\] && \$nodeMissing\)/,
    'an empty NODE_BIN must skip the node jobs, or the gate disables nothing');
});
