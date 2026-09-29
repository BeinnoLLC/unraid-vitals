<?php
/**
 * unraid-vitals — checks/signature_scan.php (P14-09)
 *
 * The actual scanning (offset tracking, pattern matching, evidence log)
 * happens once per collection run in collect.php's v_syslog_scan(), which
 * writes its output into $snap['syslog_matches']. This check just turns
 * whatever matched THIS run into findings -- kept separate from the
 * scanner itself so the checks engine's usual enable/disable/severity-
 * override machinery (checks.php) applies to it like any other check,
 * without re-scanning the log a second time.
 */

function v_check_signature_scan(array $snap): array {
  $out = [];
  foreach ($snap['syslog_matches'] ?? [] as $m) {
    $out[] = [
      'severity' => $m['severity'] ?? 'warning',
      'subject' => 'syslog_' . ($m['signature'] ?? 'unknown'),
      'title' => $m['title'] ?? 'Syslog signature match',
      'detail' => $m['detail'] ?? '',
      'evidence' => [
        ['label' => 'signature', 'value' => $m['signature'] ?? null],
        ['label' => 'line', 'value' => $m['line'] ?? null],
      ],
      'fix_id' => null,
    ];
  }
  return $out;
}
