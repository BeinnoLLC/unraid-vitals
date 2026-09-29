<?php
/**
 * unraid-vitals — standalone test for v_syslog_scan() (collect.php) and
 * checks/signature_scan.php (P14-09). Run: php tests/test-signature-scan.php
 */

$tmp = sys_get_temp_dir() . '/vitals-sig-scan-test-' . getmypid();
@mkdir($tmp, 0755, true);
define('VITALS_STATE', $tmp . '/state');
define('VITALS_FLASH', $tmp . '/flash');
@mkdir(VITALS_STATE, 0755, true);
@mkdir(VITALS_FLASH, 0755, true);
@mkdir($tmp . '/data', 0755, true);
file_put_contents(VITALS_FLASH . '/vitals.cfg', 'DATA_DIR="' . $tmp . '/data/unraid-vitals"' . "\n");

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/unraid-vitals/include/checks.php';

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

$log = $tmp . '/syslog';

// ---------------------------------------------------------------- test 1 ---
// Acceptance criterion, verbatim: an injected OOM line in syslog produces
// a finding naming the killed process within one run. Real line format
// captured from Selene's actual syslog.
file_put_contents($log, "Sep 28 20:59:41 Selene kernel: Plex Media Scan invoked oom-killer: gfp_mask=0x140dca\n"
  . "Sep 28 20:59:41 Selene kernel: Out of memory: Killed process 2464115 (Plex Media Scan) total-vm:25374144kB\n");
$matches = v_syslog_scan($log);
check(count($matches) === 1, 'an injected real-format OOM line produces exactly one match in one run, got ' . count($matches));
check(($matches[0]['signature'] ?? null) === 'oom_killer', 'the match is the oom_killer signature');
check(str_contains($matches[0]['title'] ?? '', 'Plex Media Scan'), 'the finding title names the killed process, got: ' . ($matches[0]['title'] ?? ''));
check(($matches[0]['severity'] ?? null) === 'alert', 'OOM finding severity is alert');

// Run the check function too, to prove the checks-engine path also surfaces it.
$findings = v_checks_run(['time' => time(), 'syslog_matches' => $matches]);
$sigFindings = array_values(array_filter($findings, fn($f) => $f['check_id'] === 'signature_scan'));
check(count($sigFindings) === 1 && str_contains($sigFindings[0]['title'], 'Plex Media Scan'), 'checks engine surfaces the OOM finding naming the process');

// ---------------------------------------------------------------- test 2 ---
// Offset tracking: a second scan with NO new lines appended must not
// re-report the same match (that would spam a finding every minute for
// an event that already happened once).
$matches2 = v_syslog_scan($log);
check(count($matches2) === 0, 'a second scan with no new bytes produces no new matches (offset tracked correctly)');

// ---------------------------------------------------------------- test 3 ---
// Appending a NEW OOM line (different process) after the offset must be
// picked up on the next run, and only the new line, not a re-scan of the
// whole file.
file_put_contents($log, "Sep 28 21:36:02 Selene kernel: Out of memory: Killed process 2561692 (php-fpm) total-vm:1024kB\n", FILE_APPEND);
$matches3 = v_syslog_scan($log);
check(count($matches3) === 1 && str_contains($matches3[0]['title'], 'php-fpm'), 'appending a new OOM line is picked up on the next run and only the new line, got: ' . json_encode($matches3));

// ---------------------------------------------------------------- test 4 ---
// Log rotation (new inode, smaller file) must restart from the top of the
// new file, not crash on a negative-length seek or silently skip content.
unlink($log);
file_put_contents($log, "Sep 29 00:00:00 Selene kernel: BTRFS error (device sdb): parent transid verify failed\n");
$matches4 = v_syslog_scan($log);
check(count($matches4) === 1 && ($matches4[0]['signature'] ?? null) === 'btrfs_error', 'a rotated (new-inode) log restarts scanning from the top without crashing');

// ---------------------------------------------------------------- test 5 ---
// A benign boot-time SATA link-up/down line must NOT trigger the
// ata_link_error signature (only "exception Emask" lines should).
file_put_contents($log, "Sep 29 00:00:01 Selene kernel: ata6: SATA link down (SStatus 0 SControl 330)\n"
  . "Sep 29 00:00:02 Selene kernel: ata1: SATA link up 6.0 Gbps (SStatus 133 SControl 300)\n", FILE_APPEND);
$matches5 = v_syslog_scan($log);
$ataFindings = array_values(array_filter($matches5, fn($m) => $m['signature'] === 'ata_link_error'));
check(count($ataFindings) === 0, 'benign boot-time SATA link up/down lines do not trigger the ata_link_error signature');

// A real mid-operation ATA exception DOES trigger it.
file_put_contents($log, "Sep 29 00:00:03 Selene kernel: ata3.00: exception Emask 0x0 SAct 0x0 SErr 0x0 action 0x0\n", FILE_APPEND);
$matches6 = v_syslog_scan($log);
$ataFindings2 = array_values(array_filter($matches6, fn($m) => $m['signature'] === 'ata_link_error'));
check(count($ataFindings2) === 1, 'a real mid-operation ATA exception line triggers ata_link_error');

// ---------------------------------------------------------------- test 6 ---
// A bare "Call Trace:" line immediately following an OOM kill (same
// batch) is suppressed -- it's noise from the same event, not a second
// separate finding.
file_put_contents($log, "Sep 29 00:00:10 Selene kernel: Out of memory: Killed process 999 (stress) total-vm:1kB\n"
  . "Sep 29 00:00:10 Selene kernel: Call Trace:\n", FILE_APPEND);
$matches7 = v_syslog_scan($log);
$traceFindings = array_values(array_filter($matches7, fn($m) => $m['signature'] === 'kernel_call_trace'));
$oomFindings = array_values(array_filter($matches7, fn($m) => $m['signature'] === 'oom_killer'));
check(count($oomFindings) === 1, 'the OOM finding itself still fires in the suppression batch');
check(count($traceFindings) === 0, 'a bare Call Trace right after an OOM kill in the same batch is suppressed as noise');

// A standalone Call Trace with NO OOM in the same batch is NOT suppressed.
file_put_contents($log, "Sep 29 00:00:20 Selene kernel: Call Trace:\n", FILE_APPEND);
$matches8 = v_syslog_scan($log);
$traceFindings2 = array_values(array_filter($matches8, fn($m) => $m['signature'] === 'kernel_call_trace'));
check(count($traceFindings2) === 1, 'a standalone Call Trace with no OOM in the same batch is NOT suppressed');

// ---------------------------------------------------------------- test 7 ---
// An unreadable/missing log path must not crash, just return no matches.
$matches9 = v_syslog_scan($tmp . '/does-not-exist.log');
check($matches9 === [], 'a missing log path returns an empty array without crashing');

// ---------------------------------------------------------------- test 8 ---
// One data file (signatures.json) drives everything -- no code changes
// needed to add a signature. Verify it parses and every entry has the
// required keys the scanner reads.
$sigs = json_decode(file_get_contents(__DIR__ . '/../src/usr/local/emhttp/plugins/unraid-vitals/include/checks/signatures.json'), true);
check(is_array($sigs) && count($sigs) >= 9, 'signatures.json parses and has at least the 9 ticket-listed signature types, got ' . count($sigs ?? []));
$allValid = true;
foreach ($sigs as $key => $sig) {
  if (empty($sig['pattern']) || empty($sig['severity']) || empty($sig['title'])) $allValid = false;
  if (@preg_match($sig['pattern'], '') === false) $allValid = false; // valid regex syntax
}
check($allValid, 'every signature has pattern/severity/title and a syntactically valid regex');

cleanup($tmp);

echo "\n" . ($failures === 0 ? "ALL PASS\n" : "$failures FAILURE(S)\n");
exit($failures === 0 ? 0 : 1);
