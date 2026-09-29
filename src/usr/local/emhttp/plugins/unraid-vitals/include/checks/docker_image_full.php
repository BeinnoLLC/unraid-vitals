<?php
/**
 * unraid-vitals — checks/docker_image_full.php (P14-01)
 *
 * "Docker image is full" is one of the most common Unraid problems, usually
 * caused by a container writing data inside the image (docker.img / the
 * overlay2 storage dir) instead of a mapped host path.
 *
 * Three finding conditions:
 *   1. Image above 75% used            -> warning
 *   2. Image above 90% used            -> alert
 *   3. Growing faster than N GB/day    -> warning (uses the flash rollup
 *      history so it survives a reboot; needs at least 2 daily buckets)
 *   4. A single container's own writable layer above 1 GB -> names the
 *      container (acceptance criterion: a test container writing 2 GB
 *      inside its own filesystem is named in the finding).
 *
 * Thresholds are overridable via CHECK_DOCKER_IMAGE_FULL_SEVERITY in
 * vitals.cfg (severity only, matching the rootfs_full check's convention).
 */

if (!function_exists('v_docker_image_growth_gb_per_day')) {
  /**
   * GB/day growth of docker_img_pct over the last N days of flash rollup
   * history, converted to bytes via the image's own current total size (pct
   * points -> bytes is only meaningful relative to a fixed total, which is
   * true here since docker.img/overlay2 mount size rarely changes).
   */
  function v_docker_image_growth_gb_per_day(array $snap, int $days = 3): ?float {
    $total = $snap['docker_image']['total'] ?? null;
    if ($total === null || $total <= 0) return null;
    $daily = v_daily($days + 1);
    $recent = array_slice($daily, -($days + 1));
    $samples = [];
    foreach ($recent as $d) {
      if (($d['docker_img_pct'] ?? null) !== null) $samples[] = $d;
    }
    if (count($samples) < 2) return null;
    $first = $samples[0]; $last = $samples[count($samples) - 1];
    $dayGap = (strtotime($last['day']) - strtotime($first['day'])) / 86400;
    if ($dayGap <= 0) return null;
    $pctDelta = $last['docker_img_pct'] - $first['docker_img_pct'];
    $bytesDelta = $pctDelta / 100 * $total;
    return round(($bytesDelta / $dayGap) / 1073741824, 2);
  }
}

function v_check_docker_image_full(array $snap): array {
  $out = [];
  $img = $snap['docker_image'] ?? [];
  $pct = $img['used_pct'] ?? null;

  if ($pct !== null) {
    if ($pct >= 90) {
      $out[] = [
        'severity' => 'alert', 'subject' => 'docker_image',
        'title' => 'docker.img is ' . $pct . '% full',
        'detail' => 'Docker\'s image storage (' . ($img['root'] ?? 'docker root') . ') is at ' . $pct . '% usage.'
          . ' A full docker.img stops containers from starting or writing — usually caused by a'
          . ' container writing inside its own image instead of a mapped path.',
        'evidence' => array_values(array_filter([
          ['label' => 'used_pct', 'value' => $pct],
          isset($img['total']) ? ['label' => 'total_bytes', 'value' => $img['total']] : null,
          isset($img['free']) ? ['label' => 'free_bytes', 'value' => $img['free']] : null,
          ['label' => 'threshold_pct', 'value' => 90],
        ])),
        'fix_id' => 'docker_image_full',
      ];
    } elseif ($pct >= 75) {
      $out[] = [
        'severity' => 'warning', 'subject' => 'docker_image',
        'title' => 'docker.img is ' . $pct . '% full',
        'detail' => 'Docker\'s image storage (' . ($img['root'] ?? 'docker root') . ') is at ' . $pct . '% usage.',
        'evidence' => array_values(array_filter([
          ['label' => 'used_pct', 'value' => $pct],
          isset($img['total']) ? ['label' => 'total_bytes', 'value' => $img['total']] : null,
          ['label' => 'threshold_pct', 'value' => 75],
        ])),
        'fix_id' => 'docker_image_full',
      ];
    }
  }

  $growth = v_docker_image_growth_gb_per_day($snap);
  $growthThreshold = 2.0;
  if ($growth !== null && $growth >= $growthThreshold) {
    $out[] = [
      'severity' => 'warning', 'subject' => 'docker_image',
      'title' => 'docker.img is growing ' . $growth . ' GB/day',
      'detail' => 'Docker\'s image storage has grown roughly ' . $growth . ' GB/day recently'
        . ' — at that rate it will fill even if it is not close to full yet.',
      'evidence' => [
        ['label' => 'growth_gb_per_day', 'value' => $growth],
        ['label' => 'threshold_gb_per_day', 'value' => $growthThreshold],
      ],
      'fix_id' => 'docker_image_full',
    ];
  }

  $layerThresholdBytes = 1073741824; // 1 GiB
  $layers = v_docker_layers_cached()['layers'] ?? [];
  foreach ($layers as $name => $bytes) {
    if ($bytes === null || $bytes < $layerThresholdBytes) continue;
    $out[] = [
      'severity' => $bytes >= 2147483648 ? 'alert' : 'warning',
      'subject' => 'docker_image',
      'title' => 'Container "' . $name . '" has a ' . v_format_bytes_docker((float)$bytes) . ' writable layer',
      'detail' => 'Container "' . $name . '" has written ' . v_format_bytes_docker((float)$bytes)
        . ' into its own writable layer (not a mapped volume). This usually means the container'
        . ' is writing data inside its image instead of a mapped path.',
      'evidence' => [
        ['label' => 'container', 'value' => $name],
        ['label' => 'writable_layer_bytes', 'value' => $bytes],
        ['label' => 'threshold_bytes', 'value' => $layerThresholdBytes],
      ],
      'fix_id' => 'docker_layer_large',
    ];
  }

  return $out;
}

if (!function_exists('v_format_bytes_docker')) {
  function v_format_bytes_docker(float $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, 2) . ' ' . $units[$i];
  }
}
