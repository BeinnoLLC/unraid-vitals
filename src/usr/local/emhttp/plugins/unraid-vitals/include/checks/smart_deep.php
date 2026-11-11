<?php

function v_check_smart_deep(array $snap): array {
  $out = [];
  foreach ($snap['smart'] ?? [] as $s) {
    $name = $s['name'] ?? ($s['dev'] ?? '?');

    
    
    
    $grew = $s['growth_30d']['crc'] ?? null;
    $days = $s['growth_30d']['days'] ?? null;
    if (($s['crc'] ?? 0) > 0 && $grew !== null && $grew > 0) {
      $out[] = [
        'severity' => 'warning', 'subject' => 'smart_crc',
        'title' => $name . ': UDMA CRC errors growing',
        'detail' => 'Disk ' . $name . ' has ' . $s['crc'] . ' UDMA CRC errors, up ' . $grew
          . ' in the last ' . $days . ' days. This attribute usually points to a bad or loose SATA'
          . ' cable, a failing backplane, or power issues -- not the disk itself. Worth checking'
          . ' cabling before assuming the drive is failing.',
        'evidence' => [
          ['label' => 'disk', 'value' => $name], ['label' => 'crc_errors', 'value' => $s['crc']],
          ['label' => 'grew', 'value' => $grew], ['label' => 'days', 'value' => $days],
        ],
        'fix_id' => null,
      ];
    }

    
    
    
    
    
    if (($s['nvme_critical_warning'] ?? 0) > 0) {
      $out[] = [
        'severity' => 'critical', 'subject' => 'smart_nvme',
        'title' => $name . ': NVMe critical warning set',
        'detail' => 'Disk ' . $name . ' reports a non-zero NVMe critical warning (0x'
          . dechex($s['nvme_critical_warning']) . '). The drive itself is flagging a problem --'
          . ' spare capacity below threshold, temperature, reliability, read-only mode, or backup'
          . ' device failure, per the NVMe spec\'s critical warning bits.',
        'evidence' => [
          ['label' => 'disk', 'value' => $name],
          ['label' => 'critical_warning_hex', 'value' => dechex($s['nvme_critical_warning'])],
        ],
        'fix_id' => null,
      ];
    }

    
    if (($s['nvme_spare_pct'] ?? null) !== null && ($s['nvme_spare_threshold'] ?? null) !== null
      && $s['nvme_spare_pct'] <= $s['nvme_spare_threshold']) {
      $out[] = [
        'severity' => 'alert', 'subject' => 'smart_nvme',
        'title' => $name . ': NVMe available spare at or below threshold',
        'detail' => 'Disk ' . $name . '\'s available spare capacity is ' . $s['nvme_spare_pct']
          . '%, at or below its own vendor threshold of ' . $s['nvme_spare_threshold'] . '%.'
          . ' This is the drive\'s own end-of-life warning, not an external heuristic.',
        'evidence' => [
          ['label' => 'disk', 'value' => $name], ['label' => 'spare_pct', 'value' => $s['nvme_spare_pct']],
          ['label' => 'spare_threshold_pct', 'value' => $s['nvme_spare_threshold']],
        ],
        'fix_id' => null,
      ];
    }

    
    
    if (($s['nvme_media_errors'] ?? 0) > 0) {
      $out[] = [
        'severity' => 'alert', 'subject' => 'smart_nvme',
        'title' => $name . ': NVMe media/data integrity errors',
        'detail' => 'Disk ' . $name . ' reports ' . $s['nvme_media_errors']
          . ' media/data integrity error(s) -- hardware-detected data corruption events.',
        'evidence' => [
          ['label' => 'disk', 'value' => $name], ['label' => 'media_errors', 'value' => $s['nvme_media_errors']],
        ],
        'fix_id' => null,
      ];
    }

    
    
    
    $wearPct = $s['nvme_pct_used'] ?? ($s['ssd_wear_pct'] ?? null);
    if ($wearPct !== null && $wearPct >= 80) {
      $out[] = [
        'severity' => $wearPct >= 95 ? 'critical' : ($wearPct >= 90 ? 'alert' : 'warning'),
        'subject' => 'smart_wear',
        'title' => $name . ': wear at ' . $wearPct . '%',
        'detail' => 'Disk ' . $name . '\'s vendor-reported wear indicator is at ' . $wearPct
          . '% of its rated endurance.',
        'evidence' => [
          ['label' => 'disk', 'value' => $name], ['label' => 'wear_pct', 'value' => $wearPct],
        ],
        'fix_id' => null,
      ];
    }
  }
  return $out;
}
