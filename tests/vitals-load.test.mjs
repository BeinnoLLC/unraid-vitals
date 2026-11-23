/* unraid-vitals — the app's load boundary (vitals.js).
 *
 * vitals.js is one IIFE: `var h = preact.h` lives inside it. A render call
 * after the closing `})();` is still valid JavaScript, so `node --check`
 * stays green while the browser throws `ReferenceError: h is not defined`
 * on every page load, after the rest of the app already mounted. #38 put a
 * stray `h(KbWorkspace, {})` there. KbWorkspace stays defined but unrendered
 * until its curation UI is fixed in a follow-up ticket.
 *
 * These tests pin the file ending and an actual VM load with no mount target.
 * A control case re-adds the stray line and proves the harness really catches
 * the regression instead of passing vacuously.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';

const FILE = new URL('../src/usr/local/emhttp/plugins/unraid-vitals/js/vitals.js', import.meta.url);
const source = readFileSync(FILE, 'utf8');

function lastNonBlankLine(text) {
  return text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean).at(-1);
}

// Minimal globals for a load with no #vitals-root: nothing mounts, so no
// component body (or fetch) runs. The guard below only checks the three
// vendored libraries are present; if they are not, the script bails early.
function newContext() {
  const noop = () => {};
  const element = () => ({
    getAttribute: () => null,
    textContent: '',
    addEventListener: noop,
    appendChild: noop,
    setAttribute: noop,
  });
  const document = {
    getElementById: () => null,
    querySelector: () => null,
    querySelectorAll: () => [],
    createElement: element,
    addEventListener: noop,
    body: element(),
  };
  const window = {
    addEventListener: noop,
    location: { href: 'http://localhost/' },
    confirm: () => false,
    matchMedia: () => ({ matches: false, addEventListener: noop }),
  };
  const preact = { h: () => ({}), render: noop };
  const preactHooks = {
    useState: (v) => [v, noop],
    useEffect: noop,
    useRef: (v) => ({ current: v }),
  };
  function uPlot() {}
  return createContext({
    console, document, window, preact, preactHooks, uPlot,
    setTimeout, clearTimeout, Date, Math, JSON, URLSearchParams, Intl,
    navigator: { userAgent: 'node' },
    fetch: () => Promise.reject(new Error('vitals.js load must not fetch')),
  });
}

// Instrument just before the final IIFE close so reaching the probe proves
// the whole script evaluated, not that it returned at the availability guard.
function withEndProbe(text) {
  const end = text.lastIndexOf('})();');
  assert.ok(end >= 0, 'vitals.js must have an IIFE close to instrument');
  return text.slice(0, end) +
    'globalThis.__vitalsLoaded = { h: typeof h, KbWorkspace: typeof KbWorkspace, KbTab: typeof KbTab };\n' +
    text.slice(end);
}

test('vitals.js ends at the IIFE close, with a trailing newline', () => {
  assert.equal(lastNonBlankLine(source), '})();');
  assert.match(source, /\r?\n\}\)\(\);\r?\n$/, 'nothing may follow the IIFE close');
});

test('vitals.js loads without throwing when nothing mounts', () => {
  const ctx = newContext();
  assert.doesNotThrow(() => runInContext(withEndProbe(source), ctx, { filename: 'vitals.js' }));
  for (const symbol of ['h', 'KbWorkspace', 'KbTab']) {
    assert.equal(runInContext('globalThis.__vitalsLoaded.' + symbol, ctx), 'function',
      `${symbol} must exist inside the IIFE at the end of the script`);
  }
});

test('the load harness catches the out-of-scope KbWorkspace call', () => {
  const broken = source.trimEnd() + '\n    h(KbWorkspace, {})\n';
  assert.notEqual(lastNonBlankLine(broken), '})();');
  assert.throws(
    () => runInContext(withEndProbe(broken), newContext(), { filename: 'vitals.js(stray-render)' }),
    (err) => err && err.name === 'ReferenceError' && /h is not defined/.test(err.message),
    'the control must reproduce the original page-load failure',
  );
});
