<?php

function v_check_pool_health(array $snap): array {
  $out = [];
  $ph = $snap['pool_health'] ?? ['btrfs' => [], 'zfs' => []];

  
  foreach ($ph['btrfs'] ?? [] as $pool) {
    foreach ($pool['devices'] ?? [] as $dev) {
      if (($dev['total_errors'] ?? 0) > 0) {
        $out[] = [
          'severity' => 'alert', 'subject' => 'pool_btrfs',
          'title' => 'btrfs device errors on ' . $dev['device'],
          'detail' => 'btrfs device ' . $dev['device'] . ' (pool mounted at ' . $pool['mount']
            . ') reports ' . $dev['total_errors'] . ' total device error(s): '
            . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($dev['stats']), $dev['stats'])) . '.',
          'evidence' => [
            ['label' => 'device', 'value' => $dev['device']], ['label' => 'mount', 'value' => $pool['mount']],
            ['label' => 'total_errors', 'value' => $dev['total_errors']],
          ],
          'fix_id' => null,
        ];
      }
    }
    if (($pool['scrub_status'] ?? null) === 'never_run') {
      $out[] = [
        'severity' => 'warning', 'subject' => 'pool_btrfs_scrub',
        'title' => 'btrfs pool at ' . $pool['mount'] . ' has never been scrubbed',
        'detail' => 'The btrfs pool mounted at ' . $pool['mount'] . ' has no scrub history. A scrub'
          . ' verifies checksums against every block and is the only way to catch silent corruption'
          . ' before it matters.',
        'evidence' => [['label' => 'mount', 'value' => $pool['mount']]],
        'fix_id' => null,
      ];
    }
  }

  
  foreach ($ph['zfs'] ?? [] as $pool) {
    if (!empty($pool['state']) && $pool['state'] !== 'ONLINE') {
      $out[] = [
        'severity' => 'critical', 'subject' => 'pool_zfs_state',
        'title' => 'ZFS pool "' . $pool['pool'] . '" is ' . $pool['state'],
        'detail' => 'ZFS pool "' . $pool['pool'] . '" is reporting state ' . $pool['state']
          . ' instead of ONLINE -- this typically means a degraded, faulted, or missing device.',
        'evidence' => [['label' => 'pool', 'value' => $pool['pool']], ['label' => 'state', 'value' => $pool['state']]],
        'fix_id' => null,
      ];
    }
    foreach ($pool['device_errors'] ?? [] as $de) {
      $out[] = [
        'severity' => 'alert', 'subject' => 'pool_zfs',
        'title' => 'ZFS device errors on ' . $de['device'] . ' (pool "' . $pool['pool'] . '")',
        'detail' => 'ZFS device ' . $de['device'] . ' in pool "' . $pool['pool'] . '" reports read='
          . $de['read'] . ' write=' . $de['write'] . ' cksum=' . $de['cksum'] . ' errors.',
        'evidence' => [
          ['label' => 'pool', 'value' => $pool['pool']], ['label' => 'device', 'value' => $de['device']],
          ['label' => 'read', 'value' => $de['read']], ['label' => 'write', 'value' => $de['write']],
          ['label' => 'cksum', 'value' => $de['cksum']],
        ],
        'fix_id' => null,
      ];
    }
    
    
    
    
    if (!empty($pool['scrub_date'])) {
      $ts = strtotime($pool['scrub_date']);
      if ($ts !== false) {
        $days = (time() - $ts) / 86400;
        if ($days > 30) {
          $out[] = [
            'severity' => 'warning', 'subject' => 'pool_zfs_scrub',
            'title' => 'ZFS pool "' . $pool['pool'] . '" not scrubbed in ' . round($days) . ' days',
            'detail' => 'ZFS pool "' . $pool['pool'] . '" was last scrubbed ' . round($days)
              . ' days ago (' . $pool['scrub_date'] . '). A scrub verifies every block\'s checksum.',
            'evidence' => [['label' => 'pool', 'value' => $pool['pool']], ['label' => 'days_since_scrub', 'value' => round($days)]],
            'fix_id' => null,
          ];
        }
      }
    }
    
    
    
  }

  return $out;
}
