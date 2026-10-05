/* unraid-vitals — the stock-Dashboard tile (Vitals.Dashboard.page).
 *
 * This is the plugin's most-seen surface: the compact tile on the Unraid
 * Dashboard that everyone lands on, versus the full app on the Vitals tab.
 * Nothing covered it. It fails silently by construction — every cell renders
 * the literal "—" when its value is missing, so a renamed snapshot key or a
 * typo'd element id shows a plausible-looking empty tile rather than an error,
 * and nobody files it.
 *
 * These tests pin the three ways it can rot:
 *   - the page stops registering on the Dashboard (or collides with the tab)
 *   - the JS writes into ids that are not in the markup
 *   - the JS reads snapshot keys the collector no longer emits
 * plus the read-only contract its own header comment claims.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const P = (rel) => new URL(`../src/usr/local/emhttp/plugins/unraid-vitals/${rel}`, import.meta.url);
const tile = readFileSync(P('Vitals.Dashboard.page'), 'utf8');
const tab = readFileSync(P('Vitals.page'), 'utf8');
const collect = readFileSync(P('include/collect.php'), 'utf8');

/** Element ids the tile's JS reaches for. */
function referencedIds() {
  const ids = new Set();
  for (const m of tile.matchAll(/\bN\('([^']+)'\)/g)) ids.add(m[1]);
  for (const m of tile.matchAll(/\bset\('([^']+)'/g)) ids.add(m[1]);
  return [...ids].sort();
}

test('the tile registers on the Dashboard, additively', () => {
  // Menu="Dashboard" is what build_pages() reads to render a Dashboard tile;
  // anything else and the tile silently never appears.
  const head = tile.slice(0, tile.indexOf('---'));
  assert.match(head, /^Menu="Dashboard"$/m, 'the tile must register under Menu="Dashboard"');
  assert.match(head, /^Title="Vitals"$/m, 'and carry a title');

  // Dedup in emhttp is by basename, and Vitals.page already owns the Tasks tab.
  // Both files must keep distinct Menu values, or one page disappears.
  const tabHead = tab.slice(0, tab.indexOf('---'));
  assert.match(tabHead, /^Menu="Tasks:(\d+)"$/m, 'the full app must stay on its own nav tab');
  assert.doesNotMatch(tabHead, /^Menu="Dashboard"/m,
    'the full app must NOT also claim a Dashboard slot — that would collide with the tile');
  assert.notEqual('Vitals.Dashboard', 'Vitals',
    'the two page basenames must differ or emhttp dedups one away');
});

test('every id the tile writes to exists in the tile markup', () => {
  // The silent-blank bug. `set('vt-temp', …)` against a missing #vt-temp is a
  // no-op: the cell keeps whatever it had, no error anywhere.
  const declared = new Set([...tile.matchAll(/\bid="([^"]+)"/g)].map((m) => m[1]));
  const used = referencedIds();
  assert.ok(used.length >= 6, `expected the tile to drive several cells, found ${used.length}`);
  for (const id of used) {
    assert.ok(declared.has(id), `the tile's JS writes to #${id}, which is not in its markup`);
  }
});

test('the snapshot keys the tile reads still exist in the collector', () => {
  // The other silent-blank bug: collect.php renames docker.count, the tile
  // keeps reading it, and Containers shows "—" forever on every install.
  //
  // The keys come from different builders, so each is checked where its shape
  // actually lives rather than by slicing out one return statement.
  const snapAt = collect.lastIndexOf("'cpu'     => ['cores' => [], 'total' => null]");
  assert.ok(snapAt > 0, "the snapshot's cpu placeholder shape changed");
  const snap = collect.slice(collect.lastIndexOf('return [', snapAt));

  for (const k of ['cpu', 'mem', 'array', 'docker']) {
    assert.match(snap, new RegExp(`'${k}'\\s*=>`), `collect.php no longer returns a '${k}' key`);
  }

  // cpu.total — assigned from v_cpu_pct(), which fills 'total' from the cores map.
  assert.match(tile, /s\.cpu\s*\?\s*s\.cpu\.total/, 'the tile reads cpu.total');
  assert.match(collect, /'cpu'\s*=>\s*\['cores' => \[\], 'total' => null\]/,
    "cpu.total's placeholder is gone — the tile would show a permanent dash");
  assert.match(collect, /\$snap\['cpu'\] = \$p;/, 'the cpu snapshot assignment is gone');
  assert.match(collect, /if \(isset\(\$out\['cores'\]\['cpu'\]\)\) \$out\['total'\] = \$out\['cores'\]\['cpu'\];/,
    "v_cpu_pct() stopped setting 'total' — the tile's cpu cell dies silently");

  // mem.pct — via v_mem().
  assert.match(tile, /s\.mem\s*\?\s*s\.mem\.pct/);
  assert.match(collect, /'mem'\s*=>\s*v_mem\(\)/, 'the snapshot no longer uses v_mem() for mem');
  assert.match(collect, /'pct' => \$total \? round\(100 \* \$used \/ \$total, 1\) : 0,/,
    "v_mem() stopped returning 'pct'");

  // docker.count / docker.running — via v_docker().
  assert.match(tile, /typeof d\.count === 'number' && typeof d\.running === 'number'/);
  assert.match(collect, /'docker'\s*=>\s*v_docker\(\)/, 'the snapshot no longer uses v_docker() for docker');
  assert.match(collect, /return \['count' => count\(\$rows\), 'running' => \$running,/,
    "v_docker() stopped returning count/running");

  // array.totals.used_pct, and the per-disk temp the tile scans for the worst
  // thermal finding.
  assert.match(tile, /totals\s*\?\s*arr\.totals\.used_pct/);
  assert.match(collect, /'totals'\s*=>/, "the array snapshot lost 'totals'");
  assert.match(collect, /'used_pct'\s*=>/, "the array snapshot lost 'used_pct'");
  assert.match(tile, /if \(d && d\.temp != null\)/);
  assert.match(collect, /'temp'\s*=>\s*isset\(\$d\['temp'\]\)/,
    'disk rows no longer carry temp — the tile loses its thermal line');
});

test('the tile stays read-only, as its header comment claims', () => {
  // It talks to include/ajax.php, whose CSRF gate only covers state-changing
  // POSTs. A tile that starts POSTing would either need a token or lean on a
  // gap. Keep it on GET.
  assert.doesNotMatch(tile, /method\s*:\s*['"]POST['"]/i, 'the tile must not POST');
  assert.doesNotMatch(tile, /fetch\([^)]*\{[^}]*body\s*:/, 'the tile must not send a request body');
  for (const m of tile.matchAll(/fetch\(([^)]*)\)/g)) {
    assert.match(m[1], /action=(data|checks)/, `unexpected endpoint in fetch: ${m[1]}`);
  }
});

test('the tile is self-contained — no external css/js it would have to wait on', () => {
  // Its header comment promises inline styles and no file deps so the tile
  // cannot be broken by, or block on, anything else shipped in the plugin.
  const head = tile.slice(0, tile.indexOf('---'));
  assert.doesNotMatch(head, /<link|<script/i, 'the tile preamble must not pull in external assets');
  assert.match(tile, /<style>/, 'the tile must carry its own styles inline');
  assert.doesNotMatch(tile, /src=["']\/plugins/, 'the tile must not load plugin scripts');
});
