<?php
/* unraid-vitals — include/timeline.php (P17-05 / #88)
 *
 * "What changed?" — a daily snapshot of identity data (container list with
 * image tags, plugin versions, Unraid version, share settings, disk
 * assignments) stored each day in SQLite; the timeline shows consecutive-day
 * diffs. Acceptance: updating a container image appears on the timeline with
 * old and new tag.
 */

declare(strict_types=1);

require_once __DIR__ . '/store.php';

/** Collect today's identity snapshot (cheap: config files + docker inspect). */
function v_timeline_collect(): array {
  $docker = [];
  $names = @shell_exec('timeout 15 docker ps -a --format \'{{.Names}}\' 2>/dev/null') ?: '';
  foreach (explode("\n", trim($names)) as $name) {
    if ($name === '') continue;
    $raw = @shell_exec('timeout 15 docker inspect --format \'{{.Config.Image}}|{{.Image}}\' ' . escapeshellarg($name) . ' 2>/dev/null');
    if (!$raw) continue;
    [$image, $id] = array_pad(explode('|', trim($raw), 2), 2, '');
    $docker[$name] = ['image' => $image, 'image_id' => substr($id, 0, 19)];
  }
  $plugins = [];
  foreach (glob('/var/log/plugins/*') ?: [] as $plg) {
    $plugins[basename($plg, '.plg')] = date('Y-m-d', @filemtime($plg) ?: 0);
  }
  $shares = [];
  foreach ((@parse_ini_file('/var/local/emhttp/shares.ini', true) ?: []) as $name => $s) {
    if (!is_array($s)) continue;
    $shares[$name] = [
      'cache' => (string)($s['useCache'] ?? ''),
      'include' => (string)($s['include'] ?? ''),
      'exclude' => (string)($s['exclude'] ?? ''),
    ];
  }
  $disks = [];
  foreach (['parity', 'disk1', 'disk2', 'disk3', 'disk4', 'disk5', 'disk6', 'disk7',
            'disk8', 'disk9', 'disk10', 'disk11', 'disk12', 'cache', 'cache2'] as $slot) {
    $sd = "/boot/config/super.dat"; // binary — use disks.ini instead
  }
  foreach ((@parse_ini_file('/var/local/emhttp/disks.ini', true) ?: []) as $name => $d) {
    if (!is_array($d) || empty($d['device'])) continue;
    $disks[$name] = ['device' => (string)$d['device'], 'serial' => (string)($d['id'] ?? '') ?: ''];
  }
  return [
    'day' => gmdate('Y-m-d'),
    'unraid' => (string)(v_ini('/etc/unraid-version')['version'] ?? '?'),
    'docker' => $docker,
    'plugins' => $plugins,
    'shares' => $shares,
    'disks' => $disks,
  ];
}

function v_timeline_db(): ?SQLite3 {
  $db = v_events_db();
  if (!$db) return null;
  $db->exec("CREATE TABLE IF NOT EXISTS timeline_days (
    day TEXT PRIMARY KEY, json TEXT NOT NULL)");
  return $db;
}

/** Store/refresh today's snapshot. Idempotent per day (latest wins). */
function v_timeline_tick(): array {
  $db = v_timeline_db();
  if (!$db) return ['ok' => false, 'error' => 'no db'];
  $snap = v_timeline_collect();
  $st = $db->prepare('INSERT INTO timeline_days (day, json) VALUES (:day, :json)
                      ON CONFLICT(day) DO UPDATE SET json = :json');
  $st->bindValue(':day', $snap['day'], SQLITE3_TEXT);
  $st->bindValue(':json', json_encode($snap), SQLITE3_TEXT);
  $st->execute();
  return ['ok' => true, 'day' => $snap['day']];
}

/** Timeline: diffs for each stored day vs its previous stored day. */
function v_timeline_diffs(int $limitDays = 14): array {
  $db = v_timeline_db();
  if (!$db) return [];
  $days = [];
  $res = $db->query("SELECT day, json FROM timeline_days ORDER BY day DESC LIMIT " . (int)$limitDays);
  while (($row = $res->fetchArray(SQLITE3_ASSOC))) {
    $days[] = ['day' => $row['day'], 'snap' => json_decode($row['json'], true)];
  }
  $out = [];
  for ($i = 0; $i + 1 < count($days); $i++) {
    $out[] = v_timeline_diff($days[$i + 1]['snap'], $days[$i]['snap'], $days[$i]['day']);
  }
  return $out;
}

/** Diff old → new; returns [section][kind] = [detail,...] + image tag changes. */
function v_timeline_diff(?array $old, ?array $new, string $day): array {
  $changes = ['containers' => [], 'images' => [], 'plugins' => [], 'unraid' => [], 'shares' => [], 'disks' => []];
  if (!$old || !$new) return ['day' => $day, 'changes' => $changes];
  foreach (['docker' => 'containers', 'plugins' => 'plugins', 'shares' => 'shares', 'disks' => 'disks'] as $k => $label) {
    $ol = $old[$k] ?? []; $nl = $new[$k] ?? [];
    foreach ($nl as $name => $nv) {
      if (!array_key_exists($name, $ol)) { $changes[$label][] = ['kind' => 'added', 'name' => $name, 'new' => $nv]; continue; }
      $ov = $ol[$name];
      if ($k === 'docker') {
        if (($ov['image'] ?? '') !== ($nv['image'] ?? '')) {
          $changes['images'][] = ['kind' => 'updated', 'name' => $name,
            'old' => $ov['image'], 'new' => $nv['image'],
            'old_id' => $ov['image_id'] ?? '', 'new_id' => $nv['image_id'] ?? ''];
        }
      } elseif ($ov != $nv) {
        $changes[$label][] = ['kind' => 'changed', 'name' => $name, 'old' => $ov, 'new' => $nv];
      }
    }
    foreach ($ol as $name => $ov) {
      if (!array_key_exists($name, $nl)) $changes[$label][] = ['kind' => 'removed', 'name' => $name, 'old' => $ov];
    }
  }
  if (($old['unraid'] ?? '') !== ($new['unraid'] ?? '')) {
    $changes['unraid'][] = ['kind' => 'changed', 'old' => $old['unraid'] ?? '?', 'new' => $new['unraid'] ?? '?'];
  }
  return ['day' => $day, 'changes' => $changes];
}