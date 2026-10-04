/* unraid-vitals — the install manifest must be internally consistent.
 *
 * `plugins/unraid-vitals.plg` is the address users paste into `plugin install`
 * (&pluginURL points at it). It downloads a VERSIONED payload from
 * `releases/latest`, so a manifest left at an old version does not merely look
 * stale — it installs the older build, or 404s if that tag's asset is gone.
 *
 * That happened: the build wrote only dist/unraid-vitals.plg (gitignored) and
 * never the tracked copy, so the tracked manifest sat at 2026.09.28 through
 * several releases. The build now writes both; this catches a hand-edit or a
 * bad merge, which the build cannot.
 *
 * No dist/ here: it is gitignored and absent in CI. These are checks on the
 * tracked file alone.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const PLG = new URL('../plugins/unraid-vitals.plg', import.meta.url);
const FILE_ROOT = fileURLToPath(new URL('..', import.meta.url));
const xml = readFileSync(PLG, 'utf8');

function entity(name) {
  const m = xml.match(new RegExp(`<!ENTITY\\s+${name}\\s+"([^"]*)"`));
  assert.ok(m, `manifest is missing the &${name}; entity`);
  return m[1];
}

test('the manifest names one consistent version', () => {
  const version = entity('version');
  assert.match(version, /^\d{4}\.\d{2}\.\d{2}$/, `version "${version}" is not YYYY.MM.DD`);
  assert.equal(
    entity('pkgname'),
    `${entity('name')}-${version}-x86_64-1`,
    '&pkgname; must be &name;-&version;-x86_64-1, or it downloads a different build than it declares',
  );
});

test('the manifest carries a real checksum', () => {
  // Not the checksum of anything checkable here (the payload is gitignored),
  // but it must be a filled-in 32-hex md5: an empty or placeholder value here
  // means the build's sed never ran.
  assert.match(entity('md5'), /^[0-9a-f]{32}$/, '&md5; is not a filled-in md5');
});

test('the changelog documents the version being shipped', () => {
  // A release whose own changelog does not mention it is how the stale
  // manifest went unnoticed: the version bumped, the notes did not.
  const version = entity('version');
  assert.ok(
    xml.includes(`###${version}`),
    `CHANGES has no ###${version} entry — add it to build/plugin.plg.template`,
  );
});

test('the manifest points at a versioned payload it can actually find', () => {
  // `<URL>…/releases/latest/download/&pkgname;.txz</URL>` — `latest` resolves to
  // the newest release, so the asset filename must carry the version.
  assert.match(xml, /releases\/latest\/download\/&pkgname;\.txz/, 'the txz URL must use &pkgname;');
  assert.ok(!/download\/unraid-vitals-\d{4}\.\d{2}\.\d{2}/.test(xml),
    'the txz URL hardcodes a version instead of using &pkgname; — it will drift');
});

test('&pluginURL points at this tracked file', () => {
  // If pluginURL points somewhere else, the file users are told to install is
  // not the file we maintain — the stale-manifest bug in another guise.
  assert.equal(entity('pluginURL'), 'https://raw.githubusercontent.com/&github;/main/plugins/&name;.plg');
  assert.equal(entity('name'), 'unraid-vitals');
});

test('the tracked manifest is not older than the newest release tag', () => {
  // The invariant the stale-manifest bug violated. A manifest can be perfectly
  // self-consistent (version, pkgname, md5 all agreeing) and still be three
  // releases behind — that is precisely how it went unnoticed, so consistency
  // alone is not enough. The manifest is what `plugin install` reads, and it
  // must never advertise a version older than one already tagged.
  //
  // The build writes the manifest before the tag for that version exists, so
  // equality here is fine and only "older" is a failure. Skips (rather than
  // fails) when tags are unavailable, e.g. a depth-1 CI checkout.
  let tags = [];
  try {
    tags = execFileSync('git', ['tag', '--list', 'v*'], { cwd: FILE_ROOT, encoding: 'utf8' })
      .split('\n').map((t) => t.trim()).filter(Boolean);
  } catch { /* no git, or not a checkout */ }
  if (tags.length === 0) return;

  // YYYY.MM.DD sorts lexicographically, so a plain max is a correct "newest".
  const newest = tags.map((t) => t.replace(/^v/, '')).sort().pop();
  const version = entity('version');
  assert.ok(
    version >= newest,
    `tracked manifest is ${version} but ${newest} is already tagged — `
    + 'it would install the older build; rebuild so build.sh rewrites plugins/unraid-vitals.plg',
  );
});
