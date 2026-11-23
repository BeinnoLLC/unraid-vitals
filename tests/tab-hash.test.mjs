/* unraid-vitals — active-tab hash persistence (UNRAIDPLUG-159).
 *
 * vitals.js is a browser IIFE: it reaches for document, preact, preactHooks
 * and uPlot at load time, so it cannot be imported directly in node. Its
 * tabFromHash() helper is pure, though — this suite pulls that exact function
 * (and the TABS ids it validates against) out of the tracked source and runs
 * it against real hash strings, then checks that App() actually wires it up
 * to state, replaceState and a cleaned-up hashchange listener.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const SRC = new URL('../src/usr/local/emhttp/plugins/unraid-vitals/js/vitals.js', import.meta.url);
const src = readFileSync(SRC, 'utf8');

/** The shipped tabFromHash, evaluated against the shipped TABS ids. */
function shippedTabFromHash() {
  const m = src.match(/function tabFromHash\(hash\) \{[\s\S]*?\n\}/);
  assert.ok(m, 'vitals.js no longer defines tabFromHash(hash)');
  const ids = [...src.matchAll(/^\s*\{ id: '([a-z]+)',\s+label:/gm)].map((x) => x[1]);
  assert.ok(ids.length >= 10, `TABS ids changed shape or disappeared (found ${ids.length})`);
  return new Function('TABS', `${m[0]}; return tabFromHash;`)(ids.map((id) => ({ id })));
}

test('a known hash selects that tab', () => {
  const parse = shippedTabFromHash();
  assert.equal(parse('#docker'), 'docker');
  assert.equal(parse('#settings'), 'settings');
  assert.equal(parse('#dash'), 'dash');
  // Also tolerate a bare id — the helper is called with location.hash, which
  // normally includes '#', but keep it total for any other caller.
  assert.equal(parse('docker'), 'docker');
});

test('empty, bare-# and unknown hashes fall back to dash', () => {
  const parse = shippedTabFromHash();
  for (const hash of ['', '#', undefined, null, '#bogus', 'bogus', '#DOCKER', '#docker/foo']) {
    assert.equal(parse(hash), 'dash', `hash ${JSON.stringify(hash)} should land on the Dashboard`);
  }
});

test('App restores from the hash, replaces history on tab change, and follows hashchange', () => {
  assert.match(src, /useState\(tabFromHash\(window\.location\.hash\)\), tab = s3\[0\], setTab = s3\[1\]/,
    'initial tab state must come from the current hash');
  assert.match(src, /window\.history\.replaceState\([^;]*window\.location\.pathname \+ window\.location\.search \+ want\)/,
    'tab changes must rewrite only the fragment, preserving path/query');
  assert.doesNotMatch(src, /location\.hash\s*=/,
    'never assign location.hash directly — that adds a history entry per click');
  assert.match(src, /addEventListener\(['"]hashchange['"]/, 'a hashchange listener must switch tabs');
  assert.match(src, /removeEventListener\(['"]hashchange['"]/, 'the hashchange listener must be cleaned up');
});
