/* PR #161 fix-up (UNRAIDPLUG-226): the KB workspace is reachable through the
 * existing Knowledge tab, and its controls actually talk to ajax.php.
 *
 * These are source assertions because the file is a browser IIFE with no
 * exports: importing it needs a DOM, a fetch stub and a hook shim. Every
 * regression in this ticket was a text-level wiring error (a renamed tab, a
 * duplicate state slot, a no-op merge call, a missing token, a component that
 * was never rendered), so pinning the wiring in source is the level at which
 * the bugs are caught — node --check alone proved none of them.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const FILE = new URL(
  '../src/usr/local/emhttp/plugins/unraid-vitals/js/vitals.js',
  import.meta.url,
);
const src = readFileSync(FILE, 'utf8');

function sliceFrom(startMarker, endMarker, from = 0) {
  const start = src.indexOf(startMarker, from);
  assert.notEqual(start, -1, `missing source marker: ${startMarker}`);
  const end = src.indexOf(endMarker, start + startMarker.length);
  assert.notEqual(end, -1, `missing source marker: ${endMarker}`);
  return src.slice(start, end);
}

const tabs = sliceFrom('var TABS = [', '\n];');
const workspace = sliceFrom('function KbWorkspace() {', 'function KbTab() {');
const kbTab = sliceFrom('function KbTab() {',
  '/* --------------------------------------------------------------- research */');

test('the tab bar still has exactly the original Knowledge tab', () => {
  assert.match(tabs,
    /\{ id: 'kb',\s+label: 'Knowledge',\s+icon: 'fa-book' \},/,
    'TABS must keep the original kb entry verbatim');
  assert.doesNotMatch(tabs, /kbworkspace/, 'no second tab may be added');
  assert.doesNotMatch(src, /\{ id: 'kbworkspace'/,
    'a kbworkspace tab must not be introduced anywhere else either');
});

test('KbWorkspace state slots are unique: mergeInto keeps s6, msg keeps s7', () => {
  const names = [...workspace.matchAll(/\bvar\s+(s\d+)\s*=\s*useState\(/g)].map((m) => m[1]);
  assert.ok(names.length >= 10, `expected the full state block, got ${names.join(', ')}`);
  assert.equal(new Set(names).size, names.length,
    `duplicate useState slots in KbWorkspace: ${names.join(', ')}`);
  assert.match(workspace,
    /var s6 = useState\(null\); var mergeInto = s6\[0\], setMergeInto = s6\[1\];/);
  assert.match(workspace,
    /var s7 = useState\(''\); var msg = s7\[0\], setMsg = s7\[1\];/);
  assert.match(workspace,
    /var sEnt = useState\(''\); var entityInput = sEnt\[0\], setEntityInput = sEnt\[1\];/,
    'the entity field needs its own state slot, not a renumbered s6/s7');
});

test('manual lesson form binds entity and text separately, and resets both', () => {
  const entityAt = workspace.indexOf("'aria-label': 'Entity'");
  const textAt = workspace.indexOf("'aria-label': 'Lesson text'", entityAt);
  const endAt = workspace.indexOf("'Save manual lesson'", entityAt);
  assert.ok(entityAt >= 0 && textAt > entityAt && endAt > textAt,
    'manual lesson form markers moved — inspect the source');
  const form = workspace.slice(entityAt, endAt);
  const entityField = form.slice(0, textAt - entityAt);
  const textField = form.slice(textAt - entityAt);

  assert.match(entityField, /value: entityInput/, 'Entity input must be controlled');
  assert.match(entityField,
    /onInput: function \(e\) \{ setEntityInput\(e\.target\.value\); \}/);
  assert.doesNotMatch(entityField, /setEditText/,
    'typing an entity must not overwrite the lesson text');
  assert.match(textField, /value: editText/, 'lesson textarea must be controlled');
  assert.match(textField,
    /onInput: function \(e\) \{ setEditText\(e\.target\.value\); \}/);

  assert.match(form,
    /act\('kb_lesson_manual', \{ entity: entityInput, text: editText \},/,
    'the POST body must carry both independent fields');
  assert.match(form, /setEntityInput\(''\); setEditText\(''\);/,
    'both fields must be reset after a successful save');
});

test('lesson merge asks for confirmation then posts kb_lesson_merge', () => {
  assert.doesNotMatch(workspace, /merge\(l\.id, e\.target\.value\)/,
    'the generic object-merge helper cannot perform a lesson merge');
  const selectAt = workspace.indexOf("'aria-label': 'Merge target lesson'");
  assert.notEqual(selectAt, -1, 'merge select disappeared');
  const handler = workspace.slice(selectAt,
    workspace.indexOf("h('option', { value: '' }", selectAt));

  const confirmAt = handler.indexOf(
    "window.confirm('Merge lesson #' + l.id + ' into #' + e.target.value + '?')");
  const actAt = handler.indexOf(
    "act('kb_lesson_merge', { id: l.id, into: e.target.value })");
  const resetAt = handler.indexOf('setMergeInto(null);');
  assert.ok(confirmAt >= 0, 'merge must use the same confirm pattern as delete');
  assert.ok(actAt > confirmAt, 'confirm must run before the POST');
  assert.ok(resetAt > actAt, 'the merge selector must always close');
});

test('KbWorkspace act() attaches the CSRF token to every POST', () => {
  const actAt = workspace.indexOf('var act = function (action, body, after) {');
  assert.notEqual(actAt, -1, 'KbWorkspace act() disappeared');
  const act = workspace.slice(actAt, workspace.indexOf('var LESSON_KINDS', actAt));
  assert.match(act,
    /new URLSearchParams\(merge\(body, \{ csrf_token: window\.__V_CSRF__ \|\| '' \}\)\)/,
    'copy the body and add csrf_token, as useAction().run() does');
  assert.doesNotMatch(act, /new URLSearchParams\(body\)/,
    'the unmodified body would drop the token');
});

test('failed KB fetches surface an error or reset the empty state', () => {
  assert.match(workspace,
    /\.catch\(function \(\) \{ setData\(\{ error: 'Failed to load curation data\.' \}\); \}\);/);
  assert.match(workspace,
    /: data\.error \? h\('div', \{ class: 'v-empty' \}, data\.error\)/,
    'the error must be rendered, not silently stored');
  assert.match(kbTab,
    /\.catch\(function \(\) \{ setResults\(\[\]\); setTopics\(\[\]\); setSevCounts\(\{\}\); setTagCounts\(\{\}\); \}\);/);
});

test('KbTab renders KbWorkspace once, as the last child of its root div', () => {
  const inTab = (kbTab.match(/h\(KbWorkspace, \{\}\)/g) || []).length;
  assert.equal(inTab, 1, `expected exactly one render inside KbTab, found ${inTab}`);
  assert.ok(kbTab.trimEnd().endsWith('h(KbWorkspace, {}));\n}'),
    'KbWorkspace must follow the Recent knowledge Panel inside the root div');
  const panelClose = kbTab.lastIndexOf('})),');
  const renderAt = kbTab.lastIndexOf('h(KbWorkspace, {})');
  assert.ok(panelClose >= 0 && renderAt > panelClose,
    'the Panel call must be followed by the workspace child before the root closes');

  // PR #160 removes this stray top-level line separately; this PR must not.
  const lines = src.replace(/\n$/, '').split('\n');
  assert.equal(lines.at(-1), '    h(KbWorkspace, {})',
    'the trailing stray line must stay untouched for PR #160 to remove');
  assert.equal((src.match(/h\(KbWorkspace, \{\}\)/g) || []).length, 2,
    'one render in KbTab plus the trailing stray line');
});
