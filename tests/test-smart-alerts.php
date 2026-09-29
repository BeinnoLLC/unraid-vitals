<?php
/**
 * unraid-vitals — standalone test for v_check_alerts()'s SMART growth logic
 * (P14-07 acceptance criterion, store.php). Run: php tests/test-smart-alerts.php
 *
 * v_check_alerts() needs a real sqlite events DB (v_event_raise/resolve),
 * so this test isolates in a temp state/flash dir like the others, and
 * reads back v_events_list() to see what severity actually landed.
 */

$tmp = sys_get_temp_dir() . '/vitals-smart-alerts-test-' . getmypid();
@mkdir($tmp, 0755, true);
define('VITALS_STATE', $tmp . '/state');
define('VITALS_FLASH', $tmp . '/flash');
@mkdir(VITALS_STATE, 0755, true);
@mkdir(VITALS_FLASH, 0755, true);
@mkdir($tmp . '/data', 0755, true);
file_put_contents(VITALS_FLASH . '/vitals.cfg', 'DATA_DIR="' . $tmp . '/data/unraid-vitals"' . "\n");

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/unraid-vitals/include/store.php';

$failures = 0;
function check(bool $ok, string $label): void {
  global $failures;
  echo ($ok ? 'PASS' : 'FAIL') . ' — ' . $label . "\n";
  if (!$ok) $failures++;
}

function cleanup(string $dir): void {
  foreach (glob($dir . '/*') ?: [] as $f) is_dir($f) ? cleanup($f) : @unlink($f);
  @rmdir($dir);
}

function smartEntry(string $diskName, int $realloc, ?int $grew, ?int $days): array {
  return ['name' => $diskName, 'reallocated' => $realloc, 'pending' => 0,
    'growth_30d' => ['days' => $days, 'reallocated' => $grew, 'pending' => 0, 'crc' => 0, 'uncorrectable' => 0]];
}

function minimalSnap(array $smart): array {
  return ['time' => time(), 'load' => ['l1' => 0], 'array' => [], 'sensors' => ['temps' => []],
    'net' => [], 'mem' => [], 'smart' => $smart];
}

function latestSeverity(string $alertKey): ?string {
  // v_events_list() returns rows with severity; find the one matching our
  // alert_key by re-querying the sqlite db directly (v_events_list doesn't
  // filter by key, so pull the DB and check ourselves).
  $db = v_events_db();
  if (!$db) return null;
  $res = $db->query("SELECT severity FROM kb_events WHERE alert_key = '" . SQLite3::escapeString($alertKey) . "' ORDER BY id DESC LIMIT 1");
  $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
  return $row['severity'] ?? null;
}

// ---------------------------------------------------------------- test 1 ---
// Acceptance criterion, verbatim: a disk with 8 reallocated sectors that
// has NOT changed in 30 days produces an info finding, not an alert.
v_check_alerts(minimalSnap(['sdb' => smartEntry('disk-standing', 8, 0, 30)]));
check(latestSeverity('smart_reallocated_disk-standing') === 'info',
  'a standing 8-reallocated-sector count unchanged in 30 days produces an info event, got: ' . latestSeverity('smart_reallocated_disk-standing'));

// ---------------------------------------------------------------- test 2 ---
// Same count, but genuinely grew -> alert, not info.
v_check_alerts(minimalSnap(['sdb' => smartEntry('disk-growing', 11, 3, 5)]));
check(latestSeverity('smart_reallocated_disk-growing') === 'alert',
  'a reallocated count that grew by 3 in 5 days produces an alert event, got: ' . latestSeverity('smart_reallocated_disk-growing'));

// ---------------------------------------------------------------- test 3 ---
// First day of tracking (no growth history yet) -> info, never a loud alert
// before there's any history to judge growth from.
v_check_alerts(minimalSnap(['sdb' => smartEntry('disk-fresh', 4, null, null)]));
check(latestSeverity('smart_reallocated_disk-fresh') === 'info',
  'a fresh count with no growth history yet produces info (never an unproven alert), got: ' . latestSeverity('smart_reallocated_disk-fresh'));

// ---------------------------------------------------------------- test 4 ---
// Zero reallocated sectors -> no open event at all (resolved).
v_check_alerts(minimalSnap(['sdb' => smartEntry('disk-clean', 0, 0, 30)]));
$open = array_values(array_filter(v_events_list('open'), fn($e) => $e['alert_key'] === 'smart_reallocated_disk-clean'));
check(count($open) === 0, 'zero reallocated sectors leaves no open event');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
