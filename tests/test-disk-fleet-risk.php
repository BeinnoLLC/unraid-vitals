<?php
/**
 * unraid-vitals — standalone test for the disk-fleet risk ranking (P15-09).
 * Exercises the same additive scoring logic as v_disk_fleet_report()
 * (re-implemented inline against controlled disk data, since the real
 * function pulls live smartctl output via v_smart() which needs actual
 * hardware) and checks the ticket's exact acceptance line: "the disk with
 * growing pending sectors ranks first."
 */

$failures = 0;
function check(bool $ok, string $label): void {
  global $failures;
  echo ($ok ? 'PASS' : 'FAIL') . ' — ' . $label . "\n";
  if (!$ok) $failures++;
}

// Re-implementation of v_disk_fleet_report()'s scoring rules (kept in
// lockstep with store.php's v_disk_fleet_report by design/comment) --
// this is the part with real decision logic worth unit-testing directly.
function scoreDisk(array $d): array {
  $reasons = []; $score = 0;
  $growth = $d['growth_30d'] ?? [];
  $pending = $d['pending'] ?? 0;
  $reallocated = $d['reallocated'] ?? 0;
  $uncorrectable = $d['uncorrectable'] ?? 0;
  $crc = $d['crc'] ?? 0;

  if ($pending !== null && $pending > 0) { $score += 100 * $pending; $reasons[] = "$pending pending sector(s)"; }
  if (($growth['pending'] ?? null) !== null && $growth['pending'] > 0) { $score += 500; $reasons[] = 'pending sectors growing'; }
  if ($reallocated !== null && $reallocated > 0) { $score += 20 * $reallocated; $reasons[] = "$reallocated reallocated sector(s)"; }
  if (($growth['reallocated'] ?? null) !== null && $growth['reallocated'] > 0) { $score += 100; $reasons[] = 'reallocated sectors growing'; }
  if ($uncorrectable !== null && $uncorrectable > 0) { $score += 10 * $uncorrectable; $reasons[] = "$uncorrectable uncorrectable error(s)"; }
  if ($crc !== null && $crc > 0) { $score += 10 * $crc; $reasons[] = "$crc CRC error(s)"; }
  if (($d['health'] ?? null) !== null && $d['health'] !== 'PASSED' && $d['health'] !== '') { $score += 1000; $reasons[] = 'health: ' . $d['health']; }
  if (($d['temp'] ?? null) !== null && $d['temp'] > 50) { $score += 5 * ($d['temp'] - 50); $reasons[] = 'running hot'; }

  return ['score' => $score, 'reasons' => $reasons];
}

// A realistic fleet: 8 healthy disks, one with a growing pending-sector
// count (the ticket's exact scenario), one with old reallocated sectors
// that are NOT growing (should rank below the growing-pending disk
// despite having a nonzero absolute reallocated count), one running hot.
$disks = [];
for ($i = 1; $i <= 8; $i++) {
  $disks["disk$i"] = ['pending' => 0, 'reallocated' => 0, 'uncorrectable' => 0, 'crc' => 0, 'health' => 'PASSED', 'temp' => 38, 'growth_30d' => ['days' => 30, 'pending' => 0, 'reallocated' => 0]];
}
$disks['disk_growing_pending'] = ['pending' => 3, 'reallocated' => 0, 'uncorrectable' => 0, 'crc' => 0, 'health' => 'PASSED', 'temp' => 40, 'growth_30d' => ['days' => 30, 'pending' => 3, 'reallocated' => 0]];
$disks['disk_old_reallocated'] = ['pending' => 0, 'reallocated' => 8, 'uncorrectable' => 0, 'crc' => 0, 'health' => 'PASSED', 'temp' => 39, 'growth_30d' => ['days' => 30, 'pending' => 0, 'reallocated' => 0]];
$disks['disk_hot'] = ['pending' => 0, 'reallocated' => 0, 'uncorrectable' => 0, 'crc' => 0, 'health' => 'PASSED', 'temp' => 62, 'growth_30d' => ['days' => 30, 'pending' => 0, 'reallocated' => 0]];

$scored = [];
foreach ($disks as $name => $d) {
  $r = scoreDisk($d);
  $scored[] = ['name' => $name] + $r;
}
usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

check($scored[0]['name'] === 'disk_growing_pending',
  "TICKET ACCEPTANCE CRITERION: the disk with growing pending sectors ranks first, got '{$scored[0]['name']}' (score {$scored[0]['score']})");
check(in_array('pending sectors growing', $scored[0]['reasons'], true), 'the top disk\'s reasons explicitly say pending sectors are growing');

$oldReallocatedRank = array_search('disk_old_reallocated', array_column($scored, 'name'));
$growingPendingRank = array_search('disk_growing_pending', array_column($scored, 'name'));
check($growingPendingRank < $oldReallocatedRank,
  'the growing-pending disk outranks the disk with a larger but STATIC reallocated count (growth matters more than raw count)');

$healthyRanks = [];
foreach (['disk1', 'disk2', 'disk3'] as $n) $healthyRanks[] = array_search($n, array_column($scored, 'name'));
check(min($healthyRanks) > $growingPendingRank, 'all healthy disks rank below the at-risk disk');
check($scored[count($scored)-1]['score'] === 0, 'a fully healthy disk scores exactly 0, not a nonzero baseline');

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
