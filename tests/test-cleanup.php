<?php
/* Acceptance tests for the cleanup safety framework (P16-01 / #76).
 * Run: VITALS_TEST=1 php tests/test-cleanup.php
 * Uses stub roots under /tmp; never touches real /var/log.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$fail = 0; $pass = 0;
function ok($label, $cond) { global $fail, $pass; if ($cond) { $pass++; echo "PASS $label\n"; } else { $fail++; echo "FAIL $label\n"; } }

// --- sandbox: stub flash dir + DATA_DIR + roots -------------------------------
$S = '/tmp/vitals-cleanup-test';
@mkdir($S, 0755, true);
@mkdir("/tmp/vitals-cleanup-roots/var/log", 0755, true);
@mkdir("/tmp/vitals-cleanup-roots/var/tmp/unraid-vitals", 0755, true);
file_put_contents("$S/vitals.cfg", "DATA_DIR=\"$S/data\"\n");
@mkdir("$S/data", 0755, true);

if (!defined('VITALS_FLASH')) define('VITALS_FLASH', $S);
// Sandbox the fs roots for kind=logs/tmp so the test never touches real /var/log.
if (!defined('V_CLEANUP_ROOTS_OVERRIDE')) {
  define('V_CLEANUP_ROOTS_OVERRIDE', json_encode([
    'logs' => ['/tmp/vitals-cleanup-roots/var/log', '/tmp/vitals-cleanup-roots/var/tmp/unraid-vitals'],
    'tmp'  => ['/tmp/vitals-cleanup-roots/tmp'],
  ]));
}
$candidates = [
  __DIR__ . '/../src/usr/local/emhttp/plugins/unraid-vitals/include/cleanup.php',
  '/usr/local/emhttp/plugins/unraid-vitals/include/cleanup.php',
];
$cleanup = null;
foreach ($candidates as $c) { if (is_file($c)) { $cleanup = $c; break; } }
if (!$cleanup) { fwrite(STDERR, "cleanup.php not found\n"); exit(2); }
require $cleanup;

// Override roots for the test (they're returned by v_cleanup_roots; we can't redefine,
// so test through the same gates with our sandbox by adding a parallel kind is not
// possible — instead directly exercise the gates + apply/audit with crafted rows).

// 1. roots gate: paths under stub roots evaluate against REAL /var/log — so test
//    the gate function directly with our own root list.
$roots = ['/tmp/vitals-cleanup-roots/var/log', '/tmp/vitals-cleanup-roots/var/tmp/unraid-vitals'];
file_put_contents('/tmp/vitals-cleanup-roots/var/log/fake.log', str_repeat('x', 1000));
file_put_contents('/tmp/vitals-cleanup-roots/var/tmp/unraid-vitals/collector.log', str_repeat('x', 2000));
symlink('/etc/passwd', '/tmp/vitals-cleanup-roots/var/log/evil-link');

ok('gate: in-root file passes', v_cleanup_safe_path('/tmp/vitals-cleanup-roots/var/log/fake.log', $roots));
ok('gate: traversal target rejected', !v_cleanup_safe_path('/etc/passwd', $roots));
ok('gate: symlink escaping root rejected (realpath)', !v_cleanup_safe_path('/tmp/vitals-cleanup-roots/var/log/evil-link', $roots));
ok('gate: root itself rejected', !v_cleanup_safe_path('/tmp/vitals-cleanup-roots/var/log', $roots));
ok('gate: nonexistent rejected', !v_cleanup_safe_path('/tmp/vitals-cleanup-roots/var/log/nope.log', $roots));

// 2. preview + apply loop with a REAL sqlite-backed preview (kind=logs but on real roots —
//    on this dev box /var/log exists with real files; apply would delete REAL logs, so we
//    only run preview, then apply against a FORGED preview id — the acceptance).
v_cleanup_db(); // create tables (uses VITALS_FLASH → stub DATA_DIR)

// forge a preview row directly in DB (simulating legit flow) with one in-root item:
$db = v_cleanup_db();
$pid = 'pv_' . bin2hex(random_bytes(8));
$items = json_encode([['path' => '/tmp/vitals-cleanup-roots/var/log/fake.log', 'bytes' => 1000]]);
$db->exec("INSERT INTO cleanup_previews (id, kind, created_at, expires_at, items, total_bytes) VALUES ('$pid', 'logs', strftime('%s','now'), strftime('%s','now')+900, '$items', 1000)");

// acceptance: forged apply — preview id that does not exist
$res = v_cleanup_apply('pv_deadbeefdeadbeef');
ok('apply: unknown preview rejected', !$res['ok']);
$audit = v_cleanup_audit_list(5);
$rej = array_values(array_filter($audit, function ($r) { return $r['result'] === 'rejected'; }));
ok('apply: unknown-preview rejection audited', count($rej) >= 1);

// 3. tampered DB row (attacker edits items in DB to /etc/passwd after preview):
$items2 = json_encode([['path' => '/etc/passwd', 'bytes' => 100]]);
$db->exec("INSERT INTO cleanup_previews (id, kind, created_at, expires_at, items, total_bytes) VALUES ('pv_aaaaaaaabbbbbbbb', 'logs', strftime('%s','now'), strftime('%s','now')+900, '$items2', 100)");
$res2 = v_cleanup_apply('pv_aaaaaaaabbbbbbbb');
ok('apply: DB row pointing outside roots rejected', !$res2['ok']);

// 4. TTL expiry
$db->exec("INSERT INTO cleanup_previews (id, kind, created_at, expires_at, items, total_bytes) VALUES ('pv_ccccccccdddddddd', 'logs', strftime('%s','now')-2000, strftime('%s','now')-1000, '$items', 1000)");
$res3 = v_cleanup_apply('pv_ccccccccdddddddd');
ok('apply: expired preview rejected', !$res3['ok']);

// 5. legit apply removes exactly the previewed file
$res4 = v_cleanup_apply($pid);
ok('apply: legit preview applies ok', $res4['ok'] === true);
ok('apply: file actually gone', !file_exists('/tmp/vitals-cleanup-roots/var/log/fake.log'));
ok('apply: bytes reported', (int)($res4['bytes_reclaimed'] ?? 0) === 1000);
ok('apply: preview consumed (single use)', !v_cleanup_load($pid)['ok']);
$audit2 = v_cleanup_audit_list(5);
$ok_rows = array_values(array_filter($audit2, function ($r) { return $r['result'] === 'applied'; }));
ok('audit: applied row recorded with bytes', count($ok_rows) >= 1 && (int)$ok_rows[0]['bytes'] === 1000);

// 6. malformed preview id shape (regex-gated at ajax layer; function level: load fails)
ok('load: garbage id -> not found', !v_cleanup_load('garbage')['ok']);

echo "RESULT pass=$pass fail=$fail\n";
exit($fail ? 1 : 0);