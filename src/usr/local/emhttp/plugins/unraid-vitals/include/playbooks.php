<?php
/* unraid-vitals — include/playbooks.php (P17-02 / #85)
 *
 * Loads docs/playbooks.md shipped inside the plugin, splits it per check
 * topic (## headings), serves per-check-id playbook text to the UI.
 * Also seeds the KB (P20-11 integration point: seed_knowledge_base() picks
 * these up as trusted source docs).
 */

declare(strict_types=1);

/** Parse playbooks.md → [ 'anchor' => ['title' =>, 'body' => ] ]. */
function v_playbooks_all(): array {
  static $cache = null;
  if ($cache !== null) return $cache;
  $path = dirname(__DIR__) . '/docs/playbooks.md';
  if (!is_file($path)) $path = '/usr/local/emhttp/plugins/unraid-vitals/docs/playbooks.md';
  if (!is_file($path)) return [];
  $text = (string)file_get_contents($path);
  $out = [];
  preg_match_all('/^## (.+?)\n(.*?)(?=^## |\z)/msm', $text, $m, PREG_SET_ORDER);
  foreach ($m as $sec) {
    $title = trim($sec[1]);
    $body = trim($sec[2]);
    // stable anchor: lowercase FIRST, then non-alnum → dash (otherwise an
    // uppercase C becomes a dash: "-ontainer-log-sizes" was observed live).
    $anchor = strtolower(preg_replace('/[^a-z0-9]+/', '-', strtolower(preg_replace('/\s*\(.*\)$/', '', $title))));
    $anchor = trim($anchor, '-');
    $out[$anchor] = ['title' => $title, 'body' => $body];
  }
  $cache = $out;
  return $out;
}

/** One playbook, by check_id → anchor map (finding → doc). */
function v_playbook_for(string $checkId): ?array {
  // check_id → playbook anchor mapping (kept explicit; new checks must add a row).
  // Anchors are computed from the heading with the "(P14-xx)" suffix stripped.
  $map = [
    'docker_image_full' => 'docker-img-usage-writable-layers',
    'docker_log_large' => 'container-log-sizes',
    'docker_layer_large' => 'docker-img-usage-writable-layers',
    'rootfs_full' => 'rootfs-var-log-tmp-fill',
    'fs_watch_full' => 'fs-watch',
    'mover_files_stuck' => 'cache-pool-mover-health',
    'share_placement' => 'share-placement-conflicts',
    'unclean_shutdown' => 'unclean-shutdowns-parity-history',
    'parity_health' => 'unclean-shutdowns-parity-history',
    'parity_speed_decline' => 'unclean-shutdowns-parity-history',
    'smart_deep' => 'smart-deep-analysis',
    'smart_reallocated' => 'smart-deep-analysis',
    'smart_crc' => 'smart-deep-analysis',
    'smart_nvme' => 'smart-deep-analysis',
    'smart_wear' => 'smart-deep-analysis',
    'spin_never_down' => 'spin-down-analysis',
    'syslog_oom_kill' => 'syslog-signature-scanner',
    'syslog_hw_error' => 'syslog-signature-scanner',
    'syslog_fs_error' => 'syslog-signature-scanner',
    'pool_health' => 'pool-health-btrfs-zfs',
    'pool_btrfs' => 'pool-health-btrfs-zfs',
    'pool_btrfs_scrub' => 'pool-health-btrfs-zfs',
    'pool_zfs' => 'pool-health-btrfs-zfs',
    'net_health' => 'network-health',
    'net_speed_regression' => 'network-health',
    'flash_health' => 'flash-drive-health',
    'flash_ro' => 'flash-drive-health',
    'docker_hygiene' => 'docker-hygiene',
    'vm_storage' => 'vm-storage',
    'system_maintenance' => 'system-maintenance',
    'share_permissions' => 'share-permissions',
    'log_size' => 'log-sizes-of-the-plugin-itself',
    'anomaly_baseline' => 'anomaly-baseline',
    'capacity_forecast' => 'capacity-forecast',
  ];
  $anchor = $map[$checkId] ?? null;
  if (!$anchor) return null;
  $all = v_playbooks_all();
  return $all[$anchor] ?? null;
}