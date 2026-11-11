<?php

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
