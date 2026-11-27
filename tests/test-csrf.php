<?php
/* Acceptance tests for the strict server-side CSRF gate (UNRAIDPLUG-223
 * hardening, Hazem decision 38). Run: php tests/test-csrf.php
 *
 * Proves the three required cases on the REAL endpoints (each request runs in
 * its own process via tests/helpers/csrf-probe.php, because the endpoints
 * exit after answering):
 *
 *   POST with NO token      -> 403
 *   POST with WRONG token   -> 403
 *   POST with CORRECT token -> accepted (the gate passes; a deliberately
 *       invalid action payload then fails its own validation, so acceptance
 *       is observable WITHOUT any state change)
 *
 * Also proves: the legacy auto_prepend channels (csrf_token field /
 * X-CSRF-Token header) are NOT accepted, a mixed channel must match on every
 * value, a non-POST never passes the gate, and read-only GET endpoints stay
 * unaffected.
 *
 * Uses a stub var.ini with a FAKE token under /tmp — the real token is never
 * read or printed. */
error_reporting(E_ALL & ~E_DEPRECATED);

$fail = 0; $pass = 0;
function ok($label, $cond) { global $fail, $pass; if ($cond) { $pass++; echo "PASS $label\n"; } else { $fail++; echo "FAIL $label\n"; } }

// --- sandbox: stub var.ini (FAKE token) --------------------------------------
$S = '/tmp/vitals-csrf-test';
@mkdir($S, 0755, true);
$TOK = 'uv-test-token-8f3c2b7a-not-real';
file_put_contents("$S/var.ini", "csrf_token=\"$TOK\"\n");
if (!defined('V_VAR_INI')) define('V_VAR_INI', "$S/var.ini");

$INC = __DIR__ . '/../src/usr/local/emhttp/plugins/unraid-vitals/include';
if (!is_file("$INC/csrf.php")) { fwrite(STDERR, "csrf.php not found\n"); exit(2); }
require "$INC/csrf.php";

function set_req(string $method, array $get = [], array $post = [], array $srv = []) {
  $_GET = $get; $_POST = $post;
  $_SERVER['REQUEST_METHOD'] = $method;
  unset($_SERVER['HTTP_X_VITALS_CSRF'], $_SERVER['HTTP_X_CSRF_TOKEN']);
  foreach ($srv as $k => $v) $_SERVER[$k] = $v;
}

// --- 1. gate unit tests ------------------------------------------------------
set_req('POST');
ok('unit: POST + NO token -> reject', !v_csrf_token_ok());

set_req('POST', [], [], ['HTTP_X_VITALS_CSRF' => 'wrong-token']);
ok('unit: POST + WRONG header token -> reject', !v_csrf_token_ok());

set_req('POST', [], ['vitals_csrf' => 'wrong-token']);
ok('unit: POST + WRONG body token -> reject', !v_csrf_token_ok());

set_req('POST', [], [], ['HTTP_X_VITALS_CSRF' => $TOK]);
ok('unit: POST + CORRECT header token -> accept', v_csrf_token_ok());

set_req('POST', [], ['vitals_csrf' => $TOK]);
ok('unit: POST + CORRECT body token -> accept', v_csrf_token_ok());

set_req('POST', [], ['vitals_csrf' => 'wrong-token'], ['HTTP_X_VITALS_CSRF' => $TOK]);
ok('unit: mixed channel (right header + wrong body) -> reject', !v_csrf_token_ok());

set_req('POST', [], ['vitals_csrf' => $TOK], ['HTTP_X_VITALS_CSRF' => 'wrong-token']);
ok('unit: mixed channel (wrong header + right body) -> reject', !v_csrf_token_ok());

set_req('POST', [], ['csrf_token' => $TOK], ['HTTP_X_CSRF_TOKEN' => $TOK]);
ok('unit: legacy auto_prepend channels only -> reject', !v_csrf_token_ok());

set_req('GET', [], [], ['HTTP_X_VITALS_CSRF' => $TOK]);
ok('unit: GET + correct token -> reject (gate is POST-only)', !v_csrf_token_ok());

set_req('POST', [], [], ['HTTP_X_VITALS_CSRF' => $TOK]);
rename(V_VAR_INI, V_VAR_INI . '.away');
ok('unit: POST + correct token but real token unreadable -> reject', !v_csrf_token_ok());
rename(V_VAR_INI . '.away', V_VAR_INI);

// --- 2. endpoint acceptance: the three required cases ------------------------
$candidates = [
  __DIR__ . '/../src/usr/local/emhttp/plugins/unraid-vitals/include',
  '/usr/local/emhttp/plugins/unraid-vitals/include',
];
$inc = null;
foreach ($candidates as $c) { if (is_file("$c/ajax.php")) { $inc = $c; break; } }
if (!$inc) { fwrite(STDERR, "ajax.php not found\n"); exit(2); }
$PROBE = __DIR__ . '/helpers/csrf-probe.php';

function probe(string $endpoint, array $query, string $body, string $method = 'POST', ?string $headerTok = null): array {
  global $PROBE;
  $cmd = 'VITALS_TEST_VAR_INI=' . escapeshellarg(V_VAR_INI)
    . ($headerTok !== null ? ' VITALS_TEST_HEADER_TOKEN=' . escapeshellarg($headerTok) : '')
    . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($PROBE) . ' '
    . escapeshellarg($endpoint) . ' ' . escapeshellarg(json_encode($query)) . ' '
    . escapeshellarg($body) . ' ' . escapeshellarg($method) . ' 2>&1';
  exec($cmd, $out, $rc);
  $text = implode("\n", $out);
  $code = 200;
  if (preg_match('/__HTTP__=(\d+)/', $text, $m)) $code = (int)$m[1];
  return ['code' => $code, 'body' => trim((string)preg_replace('/__HTTP__=\d+/', '', $text))];
}

// job_run is used for the accept case on purpose: with a malformed job id the
// CSRF gate runs first (so 403 vs 400 separates "rejected" from "accepted"),
// and the id regex rejects before ANY state change.
$AJ = "$inc/ajax.php"; $AC = "$inc/actions.php";
$jq = ['action' => 'job_run']; $jb = 'job=bad!!id';

$r = probe($AJ, $jq, $jb);
ok('ajax job_run: POST + NO token -> 403', $r['code'] === 403 && str_contains($r['body'], 'bad csrf token'));

$r = probe($AJ, $jq, $jb, 'POST', 'wrong-token');
ok('ajax job_run: POST + WRONG header token -> 403', $r['code'] === 403 && str_contains($r['body'], 'bad csrf token'));

$r = probe($AJ, $jq, "$jb&vitals_csrf=wrong-token");
ok('ajax job_run: POST + WRONG body token -> 403', $r['code'] === 403 && str_contains($r['body'], 'bad csrf token'));

$r = probe($AJ, $jq, $jb, 'POST', $TOK);
ok('ajax job_run: POST + CORRECT header token -> accepted (400 bad job id, not 403)',
  $r['code'] === 400 && str_contains($r['body'], 'bad job id'));

$r = probe($AJ, $jq, "$jb&vitals_csrf=" . urlencode($TOK));
ok('ajax job_run: POST + CORRECT body token -> accepted (400 bad job id, not 403)',
  $r['code'] === 400 && str_contains($r['body'], 'bad job id'));

$r = probe($AJ, $jq, "$jb&csrf_token=" . urlencode($TOK));
ok('ajax job_run: POST + correct token ONLY on legacy csrf_token field -> 403',
  $r['code'] === 403 && str_contains($r['body'], 'bad csrf token'));

$r = probe($AJ, $jq, "$jb&vitals_csrf=wrong-token", 'POST', $TOK);
ok('ajax job_run: POST + correct header but wrong body channel -> 403', $r['code'] === 403);

$ab = 'op=bogus_op&target=x';
$r = probe($AC, [], $ab);
ok('actions: POST + NO token -> 403', $r['code'] === 403 && str_contains($r['body'], 'bad csrf token'));

$r = probe($AC, [], "$ab&vitals_csrf=wrong-token");
ok('actions: POST + WRONG token -> 403', $r['code'] === 403 && str_contains($r['body'], 'bad csrf token'));

$r = probe($AC, [], $ab, 'POST', $TOK);
ok('actions: POST + CORRECT token -> accepted (400 unknown op, not 403)',
  $r['code'] === 400 && str_contains($r['body'], 'unknown op'));

$r = probe($AC, [], $ab, 'GET', $TOK);
ok('actions: GET + correct token -> 405 (POST required)', $r['code'] === 405);

// --- 3. every state-mutating ajax action rejects a tokenless POST ------------
$MUTATING = [
  'save_settings', 'cleanup_apply', 'kb_seed', 'kb_lesson_pin', 'kb_lesson_edit',
  'kb_lesson_merge', 'kb_lesson_delete', 'kb_lesson_manual', 'kb_solution_feedback',
  'kb_import', 'smart_test_start', 'job_run', 'backup_run', 'backup_restore',
  'backup_bundle', 'diag_bundle', 'mover_start', 'mover_stop',
  'apply_share_comment', 'gen_share_comment', 'research_ask', 'research_retry',
];
foreach ($MUTATING as $a) {
  $r = probe($AJ, ['action' => $a], 'x=1');
  ok("ajax $a: POST + NO token -> 403", $r['code'] === 403 && str_contains($r['body'], 'bad csrf token'));
  $r = probe($AJ, ['action' => $a], 'x=1&vitals_csrf=wrong-token');
  ok("ajax $a: POST + WRONG token -> 403", $r['code'] === 403 && str_contains($r['body'], 'bad csrf token'));
}

// --- 4. read-only GET endpoints stay unaffected ------------------------------
$r = probe($AJ, ['action' => 'playbook', 'check' => 'rootfs_full'], '', 'GET');
ok('ajax playbook: GET read endpoint without token still served (200, not CSRF-rejected)',
  $r['code'] === 200 && str_contains($r['body'], '"ok":') && !str_contains($r['body'], 'bad csrf token'));

echo "RESULT pass=$pass fail=$fail\n";
exit($fail ? 1 : 0);
