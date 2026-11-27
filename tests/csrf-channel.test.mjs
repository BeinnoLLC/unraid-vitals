// Regression guard for the strict CSRF channel (UNRAIDPLUG-223 hardening).
//
// The behavioural proof (POST no/wrong/correct token -> 403/403/accepted) lives
// in tests/test-csrf.php against the real endpoints. This suite guards the
// contract at the source level, dashboard-tile.test.mjs style, so the two
// halves cannot silently drift apart:
//   1. the server gate stays strict (no absent-token acceptance, no CLI
//      bypass, hash_equals against the real var.ini token),
//   2. the UI sends the plugin-controlled channel (X-Vitals-CSRF) on EVERY
//      state-mutating POST, and never sends a POST without it.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const P = join(ROOT, 'src/usr/local/emhttp/plugins/unraid-vitals');
const js = readFileSync(join(P, 'js/vitals.js'), 'utf8');
const ajax = readFileSync(join(P, 'include/ajax.php'), 'utf8');
const actions = readFileSync(join(P, 'include/actions.php'), 'utf8');
const csrf = readFileSync(join(P, 'include/csrf.php'), 'utf8');

// Comments may legitimately MENTION the legacy channels while explaining why
// they are refused — only code counts for the "must not accept" assertions.
const stripPhpComments = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');

test('the server gate is strict: no absent-token acceptance, no CLI bypass', () => {
  // The old contract ("absent token on a POST means auto_prepend already
  // checked it") made every state-mutating endpoint accept tokenless POSTs
  // whenever Unraid's auto_prepend was bypassed or changed. It must not
  // return — in any include.
  for (const [name, raw] of [['csrf.php', csrf], ['ajax.php', ajax], ['actions.php', actions]]) {
    const src = stripPhpComments(raw);
    assert.doesNotMatch(src, /PHP_SAPI\s*===\s*'cli'/,
      `${name} must not treat CLI invocation as a CSRF pass`);
    assert.doesNotMatch(src, /if \(\$sent === null\)\s*\{?\s*return/,
      `${name} must not have an absent-token acceptance path`);
  }
  const code = stripPhpComments(csrf);
  assert.match(code, /hash_equals\(\s*\$real\s*,\s*\$cand\s*\)/,
    'the channel token must be compared against the real token with hash_equals');
  assert.match(code, /\$_SERVER\['HTTP_X_VITALS_CSRF'\]/, 'the header channel must be read');
  assert.match(code, /\$_POST\['vitals_csrf'\]/, 'the body-field channel must be read');
  // The standard channels belong to auto_prepend (it strips them before
  // plugin code runs) — accepting them would silently re-open the trust gap.
  assert.doesNotMatch(code, /\$_POST\['csrf_token'\]/, 'csrf.php must not accept the stripped legacy field');
  assert.doesNotMatch(code, /HTTP_X_CSRF_TOKEN/,
    'csrf.php must not accept the stripped legacy header');
});

test('both endpoints delegate their gate to the shared strict check', () => {
  assert.match(ajax, /function v_csrf_ok\(\):\s*bool\s*\{\s*return v_csrf_token_ok\(\);\s*\}/,
    'ajax.php must gate through v_csrf_token_ok()');
  assert.match(actions, /function v_action_csrf_ok\(\):\s*bool\s*\{\s*return v_csrf_token_ok\(\);\s*\}/,
    'actions.php must gate through v_csrf_token_ok()');
  assert.match(ajax, /require_once __DIR__ \. '\/csrf\.php'/, 'ajax.php must load the shared gate');
  assert.match(actions, /require_once __DIR__ \. '\/csrf\.php'/, 'actions.php must load the shared gate');
});

function fetchCalls(src) {
  const out = [];
  for (let i = src.indexOf('fetch('); i !== -1; i = src.indexOf('fetch(', i + 1)) {
    let depth = 0;
    for (let j = i + 5; j < src.length; j++) {
      const c = src[j];
      if (c === '(') depth++;
      else if (c === ')') {
        depth--;
        if (depth === 0) { out.push(src.slice(i, j + 1)); break; }
      }
    }
  }
  return out;
}

test('every state-mutating POST in the UI sends the channel header', () => {
  const calls = fetchCalls(js);
  const posts = calls.filter((c) => /method:\s*'POST'/.test(c));
  assert.ok(posts.length >= 15, `expected the full set of mutating POSTs, found only ${posts.length}`);
  for (const c of posts) {
    assert.match(c, /vCsrfHeaders\(\)/, `POST without the CSRF channel header: ${c.slice(0, 120)}…`);
  }
  // The helper must speak the exact channel name the server reads
  // (X-Vitals-CSRF -> PHP's $_SERVER['HTTP_X_VITALS_CSRF']).
  assert.match(js, /function vCsrfHeaders\(\)[\s\S]*?'X-Vitals-CSRF'/,
    'vCsrfHeaders() must send X-Vitals-CSRF');
  // And the token it sends is the real one delivered by the server payload.
  assert.match(js, /window\.__V_CSRF__\s*=\s*d\.csrf_token/,
    'the UI must seed window.__V_CSRF__ from the server-delivered token');
});

test('every fetch() in the app is either a read GET or a channel-carrying POST', () => {
  const calls = fetchCalls(js);
  assert.ok(calls.length >= 25, `fetch scanner found only ${calls.length} calls — it is broken`);
  for (const c of calls) {
    const isPost = /method:\s*'POST'/.test(c);
    assert.ok(isPost || !/method:/.test(c),
      `unexpected fetch method (only GET-default and POST are allowed): ${c.slice(0, 120)}…`);
  }
});
