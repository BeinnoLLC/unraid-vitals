<?php
/**
 * unraid-vitals — checks/share_placement.php (P14-05)
 *
 * A pool-only share ("appdata" etc.) with files on an array disk (or an
 * array-only share with files left on cache) is the classic cause of a
 * "why is Docker slow" / "why won't my array disks spin down" support
 * thread — the share's cache setting looks right in the UI, but old files
 * from before the setting was changed (or from a mover run that never
 * happened) are still sitting on the wrong pool.
 *
 * v_share_placement() (collect.php) only checks the share's own top-level
 * folder existence per disk — see that function's docblock for why it is
 * deliberately shallow (no recursive walk).
 */

function v_check_share_placement(array $snap): array {
  $conflicts = $snap['share_placement'] ?? [];
  $out = [];
  foreach ($conflicts as $shareName => $c) {
    $pool = $c['pool'] ?? '?';
    $stray = $c['stray'] ?? [];
    if (!$stray) continue;

    foreach ($stray as $s) {
      $out[] = [
        'severity' => 'warning',
        'subject' => 'share_placement',
        'title' => 'Share "' . $shareName . '" has files on ' . $s['disk'] . ' but is ' . v_share_pool_label($pool),
        'detail' => 'Share "' . $shareName . '" is configured ' . v_share_pool_label($pool)
          . ' but its top-level folder also exists at ' . $s['path'] . '.'
          . ' This usually means the share\'s cache setting changed after files already existed, or the mover'
          . ' has not moved them yet — files split like this keep ' . ($s['expected'] === 'cache' ? 'array disks spinning' : 'the cache doing work it should not')
          . ' for a share meant to live on ' . $s['expected'] . ' only.',
        'evidence' => [
          ['label' => 'share', 'value' => $shareName],
          ['label' => 'configured_pool', 'value' => $pool],
          ['label' => 'stray_path', 'value' => $s['path']],
          ['label' => 'stray_disk', 'value' => $s['disk']],
          ['label' => 'expected_location', 'value' => $s['expected']],
        ],
        'fix_id' => null,
      ];
    }
  }
  return $out;
}

if (!function_exists('v_share_pool_label')) {
  function v_share_pool_label(string $pool): string {
    return $pool === 'only' ? 'cache-only' : ($pool === 'no' ? 'array-only' : $pool);
  }
}
